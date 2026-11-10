<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

$adminId = (int)$_SESSION["admin_id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullname = trim($_POST["fullname"] ?? "");
    $current  = $_POST["current_password"] ?? "";
    $new      = $_POST["new_password"] ?? "";
    $confirm  = $_POST["confirm_password"] ?? "";

    $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");

    } elseif ($fullname === "" || mb_strlen($fullname) > 150) {
        flash("error", "กรุณากรอกชื่อที่แสดง (ไม่เกิน 150 ตัวอักษร)");

    } elseif (throttle_blocked("admpw", (string)$adminId, 5, 900)) {
        flash("error", "ใส่รหัสผ่านปัจจุบันผิดหลายครั้งเกินไป กรุณารอสักครู่");

    } elseif (!$row || !verify_password_constant_time($current, $row["password"])) {
        throttle_hit("admpw", (string)$adminId);
        flash("error", "รหัสผ่านปัจจุบันไม่ถูกต้อง");

    } elseif ($new !== "" && ($problem = password_problem($new, 12, [$currentAdmin["username"]])) !== null) {
        flash("error", $problem);

    } elseif ($new !== $confirm) {
        flash("error", "รหัสผ่านใหม่และการยืนยันไม่ตรงกัน");

    } else {
        if ($new !== "") {
            $newHash = hash_password($new);
            $conn->prepare("UPDATE admins SET fullname = ?, password = ? WHERE id = ?")
                 ->execute([$fullname, $newHash, $adminId]);
            session_regenerate_id(true);
            csrf_rotate();
            $_SESSION["admin_pwfp"] = password_fingerprint($newHash);   // เครื่องอื่นที่ล็อกอินค้างอยู่จะหลุด
        } else {
            $conn->prepare("UPDATE admins SET fullname = ? WHERE id = ?")->execute([$fullname, $adminId]);
        }

        $_SESSION["admin_name"] = $fullname;
        admin_log($new !== "" ? "เปลี่ยนรหัสผ่านตัวเอง" : "แก้ไขชื่อที่แสดง");
        flash("success", $new !== "" ? "เปลี่ยนรหัสผ่านและบันทึกข้อมูลเรียบร้อยแล้ว" : "บันทึกข้อมูลเรียบร้อยแล้ว");
    }

    redirect("account.php");
}

$adminTitle  = "บัญชีของฉัน";
$adminActive = "account";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>บัญชีของฉัน</h1>
        <p>ชื่อผู้ใช้: <strong><?= e($_SESSION["admin_username"] ?? "") ?></strong></p>
    </div>
</div>

<div class="form-box" style="max-width:520px">
    <form method="POST" data-once autocomplete="off">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="fullname">ชื่อที่แสดง</label>
            <input type="text" id="fullname" name="fullname" maxlength="150" required value="<?= e($_SESSION["admin_name"] ?? "") ?>">
        </div>

        <hr style="border:0; border-top:1px solid var(--line); margin:22px 0">

        <div class="form-group">
            <label for="current_password">รหัสผ่านปัจจุบัน <span style="color:var(--rose)">*</span></label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            <p class="hint">ต้องกรอกทุกครั้งที่บันทึกการเปลี่ยนแปลง</p>
        </div>
        <div class="form-group">
            <label for="new_password">รหัสผ่านใหม่ (เว้นว่างถ้าไม่เปลี่ยน)</label>
            <input type="password" id="new_password" name="new_password" minlength="12" autocomplete="new-password">
            <p class="hint">อย่างน้อย 12 ตัวอักษร และต้องไม่ใช่รหัสที่เดาง่าย</p>
        </div>
        <div class="form-group">
            <label for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-sage">บันทึก</button>
        </div>
    </form>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
