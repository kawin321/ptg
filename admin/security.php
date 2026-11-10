<?php
require_once __DIR__ . "/auth.php";

$adminId = (int)$_SESSION["admin_id"];

function load_me(int $id): array
{
    global $conn;

    $stmt = $conn->prepare("SELECT id, username, password, totp_secret, totp_enabled, totp_last_step, last_login_at FROM admins WHERE id = ?");
    $stmt->execute([$id]);

    return $stmt->fetch();
}

/** ตรวจรหัส 6 หลักของบัญชีนี้ พร้อมจำกัดจำนวนครั้งที่ลอง */
function check_my_code(array $me, string $code): bool
{
    global $conn;

    if (throttle_blocked("totp", (string)$me["id"], 5, 600)) {
        flash("error", "กรอกรหัสผิดหลายครั้งเกินไป กรุณารอ 10 นาทีแล้วลองใหม่");
        return false;
    }

    $secret = secret_decrypt($me["totp_secret"]);
    $step   = null;

    if ($secret !== null && totp_verify(base32_decode_string($secret), $code, (int)$me["totp_last_step"], $step)) {
        $mark = $conn->prepare("UPDATE admins SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?");
        $mark->execute([$step, $me["id"], $step]);

        if ($mark->rowCount() === 1) {
            return true;
        }
    }

    throttle_hit("totp", (string)$me["id"]);
    flash("error", "รหัสไม่ถูกต้องหรือหมดอายุ");

    return false;
}

function store_recovery_codes(int $adminId): array
{
    global $conn;

    $codes = generate_recovery_codes();

    $conn->prepare("DELETE FROM admin_recovery_codes WHERE admin_id = ?")->execute([$adminId]);
    $insert = $conn->prepare("INSERT INTO admin_recovery_codes (admin_id, code_hash) VALUES (?, ?)");

    foreach ($codes as $code) {
        $insert->execute([$adminId, recovery_code_hash($code)]);
    }

    return $codes;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";
    $me     = load_me($adminId);

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");

    } elseif ($action === "begin" && !(int)$me["totp_enabled"]) {
        $_SESSION["totp_setup"] = base32_encode_bytes(random_bytes(20));

    } elseif ($action === "cancel_setup") {
        unset($_SESSION["totp_setup"]);

    } elseif ($action === "confirm" && !(int)$me["totp_enabled"] && !empty($_SESSION["totp_setup"])) {

        $step = null;

        if (throttle_blocked("totp", (string)$adminId, 5, 600)) {
            flash("error", "กรอกรหัสผิดหลายครั้งเกินไป กรุณารอ 10 นาทีแล้วลองใหม่");
        } elseif (totp_verify(base32_decode_string($_SESSION["totp_setup"]), (string)($_POST["code"] ?? ""), 0, $step)) {

            $conn->prepare("UPDATE admins SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?")
                 ->execute([secret_encrypt($_SESSION["totp_setup"]), $step, $adminId]);

            $_SESSION["new_codes"] = store_recovery_codes($adminId);
            unset($_SESSION["totp_setup"]);
            admin_log("เปิดยืนยันตัวตนสองขั้นตอน");
            flash("success", "เปิดการยืนยันตัวตนสองขั้นตอนแล้ว กรุณาเก็บรหัสกู้คืนด้านล่างไว้");

        } else {
            throttle_hit("totp", (string)$adminId);
            flash("error", "รหัสไม่ถูกต้อง ตรวจสอบเวลาในโทรศัพท์แล้วลองใหม่");
        }

    } elseif ($action === "regenerate" && (int)$me["totp_enabled"]) {

        if (check_my_code($me, (string)($_POST["code"] ?? ""))) {
            $_SESSION["new_codes"] = store_recovery_codes($adminId);
            admin_log("สร้างรหัสกู้คืนชุดใหม่");
            flash("success", "สร้างรหัสกู้คืนชุดใหม่แล้ว ชุดเก่าใช้ไม่ได้อีก");
        }

    } elseif ($action === "disable" && (int)$me["totp_enabled"]) {

        if (!verify_password_constant_time((string)($_POST["password"] ?? ""), $me["password"])) {
            throttle_hit("totp", (string)$adminId);
            flash("error", "รหัสผ่านไม่ถูกต้อง");
        } elseif (check_my_code($me, (string)($_POST["code"] ?? ""))) {
            $conn->prepare("UPDATE admins SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0 WHERE id = ?")->execute([$adminId]);
            $conn->prepare("DELETE FROM admin_recovery_codes WHERE admin_id = ?")->execute([$adminId]);
            admin_log("ปิดยืนยันตัวตนสองขั้นตอน");
            flash("success", "ปิดการยืนยันตัวตนสองขั้นตอนแล้ว");
        }
    }

    redirect("security.php");
}

$me = load_me($adminId);

$newCodes = $_SESSION["new_codes"] ?? null;
unset($_SESSION["new_codes"]);

$setupSecret = (!(int)$me["totp_enabled"] && !empty($_SESSION["totp_setup"])) ? $_SESSION["totp_setup"] : null;
$otpauth     = $setupSecret
    ? "otpauth://totp/" . rawurlencode("3Za Stationery:" . $me["username"]) . "?secret=" . $setupSecret
        . "&issuer=" . rawurlencode("3Za Stationery") . "&algorithm=SHA1&digits=6&period=30"
    : null;

$codesLeft = 0;
if ((int)$me["totp_enabled"]) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL");
    $stmt->execute([$adminId]);
    $codesLeft = (int)$stmt->fetchColumn();
}

$stmt = $conn->prepare("SELECT action, detail, ip, created_at FROM admin_logs WHERE admin_id = ? ORDER BY id DESC LIMIT 8");
$stmt->execute([$adminId]);
$recent = $stmt->fetchAll();

$adminTitle  = "ความปลอดภัยบัญชี";
$adminActive = "security";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>ความปลอดภัยบัญชี</h1>
        <p>บัญชี <?= e($me["username"]) ?> · เข้าสู่ระบบล่าสุด <?= $me["last_login_at"] ? e($me["last_login_at"]) : "-" ?></p>
    </div>
</div>

<div class="page-stack">

    <?php if ($newCodes): ?>
        <section class="card">
            <h2>รหัสกู้คืน (แสดงครั้งเดียว)</h2>
            <p>ใช้แทนรหัสจากแอปได้ รหัสละ 1 ครั้ง กรณีโทรศัพท์หายหรือเสีย <strong>จดหรือพิมพ์เก็บไว้ในที่ปลอดภัย</strong> หน้านี้จะไม่แสดงอีก</p>
            <ul class="recovery-codes">
                <?php foreach ($newCodes as $code): ?><li><?= e($code) ?></li><?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="card">
        <div class="card-head">
            <h2>ยืนยันตัวตนสองขั้นตอน</h2>
            <span class="badge <?= (int)$me["totp_enabled"] ? "badge-ok" : "badge-low" ?>"><?= (int)$me["totp_enabled"] ? "เปิดอยู่" : "ยังไม่ได้เปิด" ?></span>
        </div>

        <?php if (!(int)$me["totp_enabled"] && !$setupSecret): ?>
            <p>แม้มีคนรู้รหัสผ่านของคุณ ก็เข้าระบบไม่ได้ถ้าไม่มีโทรศัพท์ของคุณ ใช้ได้กับแอป Google Authenticator, Microsoft Authenticator หรือ Authy</p>
            <?php if (is_owner()): ?><p class="muted">แนะนำอย่างยิ่งสำหรับเจ้าของร้าน เพราะบัญชีนี้เห็นยอดขายและจัดการผู้ดูแลทุกคน</p><?php endif; ?>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="begin">
                <button type="submit" class="btn btn-primary">เริ่มตั้งค่า</button>
            </form>

        <?php elseif ($setupSecret): ?>
            <ol class="setup-steps">
                <li>เปิดแอป Authenticator แล้วสแกน QR นี้ (หรือกรอกรหัสลับด้วยมือ)</li>
            </ol>
            <div class="pp-box">
                <div class="pp-qr" data-payload="<?= e($otpauth) ?>" data-lib="<?= e(QR_LIBRARY_URL) ?>" role="img" aria-label="QR สำหรับตั้งค่าแอป Authenticator"></div>
                <div class="pp-caption">
                    <span>รหัสลับสำหรับกรอกเอง</span>
                    <strong class="secret-key"><?= e(trim(chunk_split($setupSecret, 4, " "))) ?></strong>
                    <span>ชนิด: ตามเวลา (TOTP) · 6 หลัก · 30 วินาที</span>
                </div>
            </div>
            <form method="POST" data-once style="margin-top:16px; max-width:360px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm">
                <div class="form-group">
                    <label for="code">กรอกรหัส 6 หลักที่แอปแสดง เพื่อยืนยันว่าตั้งค่าถูกต้อง</label>
                    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000" required>
                </div>
                <div class="form-actions" style="margin-top:0">
                    <button type="submit" class="btn btn-primary">ยืนยันและเปิดใช้งาน</button>
                </div>
            </form>
            <form method="POST" style="margin-top:8px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_setup">
                <button type="submit" class="btn btn-light">ยกเลิก</button>
            </form>

        <?php else: ?>
            <p>บัญชีนี้ต้องใช้รหัสจากแอปทุกครั้งที่เข้าสู่ระบบ · รหัสกู้คืนที่ยังไม่ใช้เหลือ <strong><?= $codesLeft ?></strong> รหัส</p>

            <div class="two-col" style="margin-top:16px">
                <form method="POST" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="regenerate">
                    <h3 style="font-size:16px">สร้างรหัสกู้คืนชุดใหม่</h3>
                    <div class="form-group">
                        <label for="code_regen">รหัส 6 หลักจากแอป</label>
                        <input type="text" id="code_regen" name="code" inputmode="numeric" autocomplete="off" maxlength="7" required>
                    </div>
                    <button type="submit" class="btn btn-light">สร้างชุดใหม่</button>
                </form>

                <form method="POST" data-once data-confirm="ปิดการยืนยันตัวตนสองขั้นตอนของบัญชีนี้ใช่ไหม?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="disable">
                    <h3 style="font-size:16px">ปิดการยืนยันสองขั้นตอน</h3>
                    <div class="form-group">
                        <label for="pw_disable">รหัสผ่านปัจจุบัน</label>
                        <input type="password" id="pw_disable" name="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-group">
                        <label for="code_disable">รหัส 6 หลักจากแอป</label>
                        <input type="text" id="code_disable" name="code" inputmode="numeric" autocomplete="off" maxlength="7" required>
                    </div>
                    <button type="submit" class="btn btn-rose">ปิดใช้งาน</button>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>กิจกรรมล่าสุดของบัญชีนี้</h2>
        <p class="muted">ถ้าเห็นรายการที่ไม่ใช่ของคุณ ให้เปลี่ยนรหัสผ่านทันที (ทุกเครื่องที่ล็อกอินค้างจะหลุดออก)</p>
        <?php if ($recent): ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>เวลา</th><th>การกระทำ</th><th>รายละเอียด</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $log): ?>
                        <tr>
                            <td class="nowrap"><small><?= e($log["created_at"]) ?></small></td>
                            <td class="nowrap"><strong><?= e($log["action"]) ?></strong></td>
                            <td><?= e($log["detail"]) ?></td>
                            <td class="nowrap"><small class="muted"><?= e($log["ip"]) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">ยังไม่มีกิจกรรม</div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
