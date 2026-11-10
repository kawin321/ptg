<?php
require_once __DIR__ . "/../includes/functions.php";

// ถ้าเข้าสู่ระบบอยู่แล้ว ให้ไปหน้า Dashboard
if (isset($_SESSION["admin_id"])) {
    redirect("dashboard.php");
}

$error    = "";
$username = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        $error = "คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง";

    } elseif (!bot_ok()) {
        $error = BOT_MESSAGE;

    } elseif (login_locked("admin", $username)) {
        // จำกัดในฐานข้อมูล เปิด session ใหม่ก็เลี่ยงไม่ได้
        $error = "ใส่รหัสผ่านผิดหลายครั้งเกินไป กรุณารอ 5 นาที แล้วลองใหม่";

    } else {
        $stmt = $conn->prepare("SELECT id, username, password, fullname, role, is_active, totp_enabled FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        // ตรวจรหัสผ่านทุกครั้งแม้ไม่พบบัญชี ให้ใช้เวลาใกล้เคียงกัน ไม่เผยว่าชื่อผู้ใช้นี้มีอยู่หรือไม่
        if (verify_password_constant_time($password, $admin["password"] ?? null)) {

            if (!(int)$admin["is_active"]) {
                $error = "บัญชีนี้ถูกปิดใช้งาน กรุณาติดต่อเจ้าของร้าน";
            } else {
                login_clear("admin", $username);

                if (password_needs_upgrade($admin["password"])) {
                    $admin["password"] = hash_password($password);
                    $conn->prepare("UPDATE admins SET password = ? WHERE id = ?")->execute([$admin["password"], $admin["id"]]);
                }

                if ((int)$admin["totp_enabled"]) {
                    // รหัสผ่านถูกแล้ว แต่ยังไม่ถือว่าเข้าสู่ระบบ จนกว่าจะผ่านรหัสยืนยันสองขั้นตอน
                    session_regenerate_id(true);
                    $_SESSION["admin_2fa"] = ["id" => (int)$admin["id"], "at" => time(), "ua" => user_agent_hash()];
                    redirect("verify.php");
                }

                admin_login_finish($admin);
                redirect("dashboard.php");
            }

        } else {
            login_failed("admin", $username);
            $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";

            if (login_locked("admin", $username)) {
                admin_log("ล็อกการเข้าสู่ระบบ", "ใส่รหัสผ่านผิดหลายครั้ง: " . mb_substr($username, 0, 50), $username);
            }
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
    <title>เข้าสู่ระบบผู้ดูแล | <?= e(SHOP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg?v=2">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&family=Prompt:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css?v=6">
    <link rel="stylesheet" href="../assets/css/brand.css?v=1">
</head>
<body class="login-page">

<div class="login-box">

    <img class="brand-mascot" src="../assets/brand/mascot.svg" alt="" width="120" height="120">
    <h1><?= e(SHOP_NAME) ?></h1>
    <p class="subtitle">ระบบเข้าสู่ระบบผู้ดูแลร้าน</p>

    <?php foreach (flash_pull() as $f): ?>
        <div class="alert alert-<?= e($f["type"]) ?>" role="alert"><?= e($f["message"]) ?></div>
    <?php endforeach; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" data-once>
        <?= csrf_field() ?>
        <?= bot_fields() ?>

        <div class="form-group">
            <label for="username">ชื่อผู้ใช้</label>
            <input type="text" id="username" name="username" placeholder="กรอกชื่อผู้ใช้"
                   value="<?= e($username) ?>" required autofocus autocomplete="username">
        </div>

        <div class="form-group">
            <label for="password">รหัสผ่าน</label>
            <div class="pw-wrap">
                <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน"
                       required autocomplete="current-password">
                <button type="button" id="pw-toggle" aria-label="แสดงรหัสผ่าน"><?= ui_icon("eye") ?></button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block" style="padding:13px; font-size:16px">
            เข้าสู่ระบบ
        </button>
    </form>

    <a href="../index.php" class="back">← กลับไปหน้าร้านค้า</a>
</div>

<script nonce="<?= csp_nonce() ?>">
document.getElementById("pw-toggle").addEventListener("click", function () {
    var input = document.getElementById("password");
    var show = input.type === "password";
    input.type = show ? "text" : "password";
    this.setAttribute("aria-label", show ? "ซ่อนรหัสผ่าน" : "แสดงรหัสผ่าน");
});
document.querySelector("form[data-once]").addEventListener("submit", function () {
    var b = this.querySelector("button[type=submit]");
    setTimeout(function () { b.disabled = true; b.textContent = "กำลังเข้าสู่ระบบ..."; }, 0);
});
</script>
</body>
</html>
