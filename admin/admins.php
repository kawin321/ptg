<?php
require_once __DIR__ . "/auth.php";
require_owner();

$selfId = (int)$_SESSION["admin_id"];

/** จำนวนเจ้าของร้านที่ยังใช้งานได้ (ต้องเหลืออย่างน้อย 1 คนเสมอ) */
function active_owner_count(): int
{
    global $conn;

    return (int)$conn->query("SELECT COUNT(*) FROM admins WHERE role = 'owner' AND is_active = 1")->fetchColumn();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";
    $id     = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
        redirect("admins.php");
    }

    if ($action === "add") {

        $username = strtolower(trim($_POST["username"] ?? ""));
        $fullname = trim($_POST["fullname"] ?? "");
        $role     = $_POST["role"] ?? "";
        $password = $_POST["password"] ?? "";
        $confirm  = $_POST["confirm_password"] ?? "";

        if (!preg_match('/^[a-z0-9_.-]{3,30}$/', $username)) {
            flash("error", "ชื่อผู้ใช้ใช้ a-z, 0-9, จุด ขีด และขีดล่าง ยาว 3-30 ตัว");
        } elseif ($fullname === "" || mb_strlen($fullname) > 150) {
            flash("error", "กรุณากรอกชื่อที่แสดง (ไม่เกิน 150 ตัวอักษร)");
        } elseif (!isset(ADMIN_ROLES[$role])) {
            flash("error", "กรุณาเลือกสิทธิ์");
        } elseif (($problem = password_problem($password, 12, [$username, $fullname])) !== null) {
            flash("error", $problem);
        } elseif ($password !== $confirm) {
            flash("error", "รหัสผ่านและการยืนยันไม่ตรงกัน");
        } else {
            try {
                $conn->prepare("INSERT INTO admins (username, password, fullname, role, is_active) VALUES (?, ?, ?, ?, 1)")
                     ->execute([$username, hash_password($password), $fullname, $role]);
                admin_log("เพิ่มผู้ดูแล", "{$username} ({$fullname}) สิทธิ์ " . ADMIN_ROLES[$role]);
                flash("success", "เพิ่มผู้ดูแล {$username} แล้ว");
            } catch (PDOException $e) {
                if ($e->getCode() === "23000") {
                    flash("error", "มีชื่อผู้ใช้ {$username} อยู่แล้ว");
                } else {
                    error_log($e->getMessage());
                    flash("error", "ไม่สามารถเพิ่มผู้ดูแลได้");
                }
            }
        }

        redirect("admins.php");
    }

    // การกระทำกับบัญชีที่มีอยู่
    $stmt = $conn->prepare("SELECT id, username, role, is_active FROM admins WHERE id = ?");
    $stmt->execute([$id ?: 0]);
    $target = $stmt->fetch();

    if (!$target) {
        flash("error", "ไม่พบบัญชีผู้ดูแล");
        redirect("admins.php");
    }

    $isSelf    = (int)$target["id"] === $selfId;
    $lastOwner = $target["role"] === "owner" && (int)$target["is_active"] === 1 && active_owner_count() <= 1;

    if ($action === "toggle") {

        if ($isSelf) {
            flash("error", "ปิดบัญชีของตัวเองไม่ได้");
        } elseif ($lastOwner) {
            flash("error", "ต้องมีเจ้าของร้านที่ใช้งานได้อย่างน้อย 1 คน");
        } else {
            $conn->prepare("UPDATE admins SET is_active = 1 - is_active WHERE id = ?")->execute([$target["id"]]);
            $now = (int)$target["is_active"] ? "ปิดใช้งาน" : "เปิดใช้งาน";
            admin_log("{$now}ผู้ดูแล", $target["username"]);
            flash("success", "{$now}บัญชี {$target["username"]} แล้ว");
        }

    } elseif ($action === "role") {

        $role = $_POST["role"] ?? "";

        if (!isset(ADMIN_ROLES[$role])) {
            flash("error", "สิทธิ์ไม่ถูกต้อง");
        } elseif ($role === $target["role"]) {
            // ไม่มีอะไรเปลี่ยน
        } elseif ($isSelf) {
            flash("error", "เปลี่ยนสิทธิ์ของตัวเองไม่ได้");
        } elseif ($lastOwner) {
            flash("error", "ต้องมีเจ้าของร้านที่ใช้งานได้อย่างน้อย 1 คน");
        } else {
            $conn->prepare("UPDATE admins SET role = ? WHERE id = ?")->execute([$role, $target["id"]]);
            admin_log("เปลี่ยนสิทธิ์ผู้ดูแล", "{$target["username"]}: " . ADMIN_ROLES[$target["role"]] . " → " . ADMIN_ROLES[$role]);
            flash("success", "เปลี่ยนสิทธิ์ {$target["username"]} เป็น" . ADMIN_ROLES[$role] . "แล้ว");
        }

    } elseif ($action === "reset") {

        if ($isSelf) {
            flash("error", "เปลี่ยนรหัสผ่านของตัวเองที่เมนูบัญชีของฉัน");
        } else {
            $temp = temp_password(14);
            $conn->prepare("UPDATE admins SET password = ? WHERE id = ?")->execute([hash_password($temp), $target["id"]]);
            admin_log("รีเซ็ตรหัสผ่านผู้ดูแล", $target["username"]);
            flash("success", "รหัสผ่านชั่วคราวของ {$target["username"]}: {$temp} (แสดงครั้งเดียว ให้เปลี่ยนหลังเข้าสู่ระบบ)");
        }

    } elseif ($action === "reset2fa") {

        // กรณีผู้ดูแลทำโทรศัพท์หายและไม่มีรหัสกู้คืน เจ้าของร้านล้างการยืนยันสองขั้นตอนให้ได้
        $conn->prepare("UPDATE admins SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0 WHERE id = ?")->execute([$target["id"]]);
        $conn->prepare("DELETE FROM admin_recovery_codes WHERE admin_id = ?")->execute([$target["id"]]);
        admin_log("ล้างยืนยันสองขั้นตอนของผู้ดูแล", $target["username"]);
        flash("success", "ล้างการยืนยันสองขั้นตอนของ {$target["username"]} แล้ว ให้ผู้ใช้ตั้งค่าใหม่");
    }

    redirect("admins.php");
}

$admins = $conn->query("SELECT id, username, fullname, role, is_active, totp_enabled, last_login_at, created_at FROM admins ORDER BY role = 'owner' DESC, id ASC")->fetchAll();

$adminTitle  = "ผู้ดูแลระบบ";
$adminActive = "admins";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>ผู้ดูแลระบบ</h1>
        <p>เจ้าของร้านเข้าได้ทุกเมนู พนักงานจัดการคำสั่งซื้อ สินค้า รีวิว และดูข้อมูลลูกค้าได้ แต่ไม่เห็นยอดขาย คูปอง และการตั้งค่า</p>
    </div>
</div>

<div class="page-stack">
    <section class="card">
        <h2>บัญชีทั้งหมด</h2>
        <div class="table-wrap">
            <table style="min-width:640px">
                <thead>
                    <tr><th>ผู้ดูแล</th><th>สิทธิ์</th><th>สถานะ</th><th>ยืนยัน 2 ขั้น</th><th>เข้าใช้ล่าสุด</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($admins as $a): ?>
                    <?php $self = (int)$a["id"] === $selfId; ?>
                    <tr>
                        <td class="nowrap">
                            <strong><?= e($a["fullname"] !== "" ? $a["fullname"] : $a["username"]) ?></strong><?= $self ? ' <span class="muted">(คุณ)</span>' : "" ?><br>
                            <small class="muted"><?= e($a["username"]) ?></small>
                        </td>
                        <td>
                            <?php if ($self): ?>
                                <?= e(ADMIN_ROLES[$a["role"]] ?? $a["role"]) ?>
                            <?php else: ?>
                                <form method="POST" class="status-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="id" value="<?= (int)$a["id"] ?>">
                                    <select name="role" aria-label="สิทธิ์ของ <?= e($a["username"]) ?>" style="width:140px">
                                        <?php foreach (ADMIN_ROLES as $key => $label): ?>
                                            <option value="<?= $key ?>" <?= $a["role"] === $key ? "selected" : "" ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="edit-btn">บันทึก</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= (int)$a["is_active"] ? "badge-ok" : "badge-out" ?>"><?= (int)$a["is_active"] ? "ใช้งาน" : "ปิดใช้งาน" ?></span></td>
                        <td><?= (int)$a["totp_enabled"] ? '<span class="badge badge-ok">เปิด</span>' : '<span class="muted">ไม่ได้เปิด</span>' ?></td>
                        <td class="nowrap"><small class="muted"><?= $a["last_login_at"] ? e($a["last_login_at"]) : "ยังไม่เคย" ?></small></td>
                        <td>
                            <?php if (!$self): ?>
                                <div class="row-actions">
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$a["id"] ?>">
                                        <button type="submit" class="edit-btn"><?= (int)$a["is_active"] ? "ปิดใช้งาน" : "เปิดใช้งาน" ?></button>
                                    </form>
                                    <form method="POST" data-confirm="สร้างรหัสผ่านชั่วคราวใหม่ให้ <?= e($a["username"]) ?> ใช่ไหม? รหัสเดิมจะใช้ไม่ได้ทันที">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reset">
                                        <input type="hidden" name="id" value="<?= (int)$a["id"] ?>">
                                        <button type="submit" class="delete-btn">รีเซ็ตรหัสผ่าน</button>
                                    </form>
                                    <?php if ((int)$a["totp_enabled"]): ?>
                                        <form method="POST" data-confirm="ล้างการยืนยันสองขั้นตอนของ <?= e($a["username"]) ?> ใช่ไหม? ผู้ใช้ต้องตั้งค่าใหม่">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reset2fa">
                                            <input type="hidden" name="id" value="<?= (int)$a["id"] ?>">
                                            <button type="submit" class="delete-btn">ล้าง 2FA</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" style="max-width:640px">
        <h2>เพิ่มผู้ดูแล</h2>
        <form method="POST" data-once>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label for="username">ชื่อผู้ใช้</label>
                <input type="text" id="username" name="username" maxlength="30" pattern="[A-Za-z0-9_.\-]{3,30}" autocomplete="off" required placeholder="เช่น staff01">
            </div>
            <div class="form-group">
                <label for="fullname">ชื่อที่แสดง</label>
                <input type="text" id="fullname" name="fullname" maxlength="150" required>
            </div>
            <div class="form-group">
                <label for="role">สิทธิ์</label>
                <select id="role" name="role" required>
                    <option value="staff">พนักงาน</option>
                    <option value="owner">เจ้าของร้าน</option>
                </select>
            </div>
            <div class="form-group">
                <label for="password">รหัสผ่าน</label>
                <input type="password" id="password" name="password" minlength="12" autocomplete="new-password" required>
                <p class="hint">อย่างน้อย 12 ตัวอักษร และต้องไม่ใช่รหัสที่เดาง่าย แจ้งให้ผู้ใช้เปลี่ยนหลังเข้าสู่ระบบครั้งแรก</p>
            </div>
            <div class="form-group">
                <label for="confirm_password">ยืนยันรหัสผ่าน</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="12" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary">เพิ่มผู้ดูแล</button>
        </form>
    </section>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
