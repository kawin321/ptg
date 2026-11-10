<?php
require_once __DIR__ . "/../includes/functions.php";

$pending = $_SESSION["admin_2fa"] ?? null;

if (!$pending || !hash_equals((string)($pending["ua"] ?? ""), user_agent_hash())) {
    unset($_SESSION["admin_2fa"]);
    redirect("login.php");
}

$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ? AND is_active = 1 AND totp_enabled = 1");
$stmt->execute([(int)$pending["id"]]);
$admin = $stmt->fetch();

if (!$admin) {
    unset($_SESSION["admin_2fa"]);
    redirect("login.php");
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $code = trim($_POST["code"] ?? "");

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        $error = "คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง";

    } elseif (isset($_POST["cancel"])) {
        unset($_SESSION["admin_2fa"]);
        redirect("login.php");

    } elseif (throttle_blocked("totp", (string)$admin["id"], 5, 600)) {
        $error = "กรอกรหัสผิดหลายครั้งเกินไป กรุณารอ 10 นาทีแล้วลองใหม่";

    } else {
        $ok = false;

        if (preg_match('/^\d{3}\s?\d{3}$/', $code)) {
            $secret = secret_decrypt($admin["totp_secret"]);
            $step   = null;

            if ($secret !== null && totp_verify(base32_decode_string($secret), $code, (int)$admin["totp_last_step"], $step)) {
                // บันทึกรอบที่ใช้แล้วแบบอะตอมมิก: ส่งรหัสเดิมซ้ำพร้อมกันก็ผ่านได้เพียงครั้งเดียว
                $mark = $conn->prepare("UPDATE admins SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?");
                $mark->execute([$step, $admin["id"], $step]);
                $ok = $mark->rowCount() === 1;
            }
        } elseif ($code !== "") {
            $use = $conn->prepare("UPDATE admin_recovery_codes SET used_at = NOW() WHERE admin_id = ? AND code_hash = ? AND used_at IS NULL");
            $use->execute([$admin["id"], recovery_code_hash($code)]);
            $ok = $use->rowCount() === 1;

            if ($ok) {
                $left = $conn->prepare("SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL");
                $left->execute([$admin["id"]]);
                $recoveryLeft = (int)$left->fetchColumn();
            }
        }

        if ($ok) {
            $conn->prepare("DELETE FROM login_attempts WHERE scope = 'totp' AND identifier = ?")->execute([(string)$admin["id"]]);
            admin_login_finish($admin);

            if (isset($recoveryLeft)) {
                admin_log("ใช้รหัสกู้คืนเข้าสู่ระบบ", "เหลือรหัสกู้คืน {$recoveryLeft} รหัส");
                flash("success", "เข้าสู่ระบบด้วยรหัสกู้คืน เหลืออีก {$recoveryLeft} รหัส ควรสร้างรหัสกู้คืนชุดใหม่ที่เมนู ความปลอดภัยบัญชี");
            }

            redirect("dashboard.php");
        }

        throttle_hit("totp", (string)$admin["id"]);
        $error = "รหัสไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่";

        if (throttle_blocked("totp", (string)$admin["id"], 5, 600)) {
            admin_log("ล็อกการยืนยันสองขั้นตอน", "กรอกรหัสผิดหลายครั้ง", $admin["username"]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>ยืนยันตัวตน | <?= e(SHOP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg?v=2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&family=Prompt:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css?v=6">
</head>
<body class="login-page">

<div class="login-box">
    <div class="mark"><?= ui_icon("lock", "28") ?></div>
    <h1>ยืนยันตัวตน</h1>
    <p class="subtitle">กรอกรหัส 6 หลักจากแอป Authenticator ของคุณ</p>

    <?php if ($error !== ""): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="code">รหัสยืนยัน</label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20"
                   placeholder="000000" required autofocus>
            <p class="hint">ใช้โทรศัพท์ไม่ได้? กรอกรหัสกู้คืน 1 รหัส (รูปแบบ xxxxx-xxxxx) แทนได้</p>
        </div>
        <button type="submit" class="btn btn-primary btn-block" style="padding:13px; font-size:16px">ยืนยัน</button>
        <button type="submit" name="cancel" value="1" class="btn btn-light btn-block" style="margin-top:8px" formnovalidate>ยกเลิก</button>
    </form>
</div>

</body>
</html>
