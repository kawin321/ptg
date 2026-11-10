<?php
require_once __DIR__ . "/includes/functions.php";

$return = safe_return_url($_GET["return"] ?? $_POST["return"] ?? "", "account.php");

if (current_customer()) {
    redirect($return);
}

$error = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email    = mb_strtolower(trim($_POST["email"] ?? ""));
    $password = $_POST["password"] ?? "";

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        $error = "คำขอไม่ถูกต้อง กรุณาลองใหม่";

    } elseif (!bot_ok()) {
        $error = BOT_MESSAGE;

    } elseif (login_locked("customer", $email)) {
        $error = "ลองเข้าสู่ระบบผิดหลายครั้งเกินไป กรุณารอสักครู่แล้วลองใหม่";

    } else {
        $stmt = $conn->prepare("SELECT id, password, is_active FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        // ตรวจรหัสผ่านทุกครั้งแม้ไม่พบบัญชี ให้ใช้เวลาใกล้เคียงกัน ไม่เผยว่าอีเมลนี้มีอยู่หรือไม่
        if (verify_password_constant_time($password, $row["password"] ?? null)) {

            if (!(int)$row["is_active"]) {
                $error = "บัญชีนี้ถูกระงับ กรุณาติดต่อร้าน";
            } else {
                login_clear("customer", $email);

                if (password_needs_upgrade($row["password"])) {
                    $row["password"] = hash_password($password);
                    $conn->prepare("UPDATE customers SET password = ? WHERE id = ?")->execute([$row["password"], $row["id"]]);
                }

                customer_login_finish((int)$row["id"], $row["password"]);

                flash("success", "เข้าสู่ระบบเรียบร้อยแล้ว");
                redirect($return);
            }

        } else {
            login_failed("customer", $email);
            $error = "อีเมลหรือรหัสผ่านไม่ถูกต้อง";
        }
    }
}

$pageTitle  = "เข้าสู่ระบบ";
$activePage = "account";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">
    <div class="panel auth-card">
        <h1>เข้าสู่ระบบ</h1>
        <p class="muted">สำหรับลูกค้าของ <?= e(SHOP_NAME) ?></p>

        <?php if ($error !== ""): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" data-once>
            <?= csrf_field() ?>
            <?= bot_fields() ?>
            <input type="hidden" name="return" value="<?= e($return) ?>">

            <div class="form-group">
                <label class="label" for="email">อีเมล</label>
                <input class="form-control" type="email" id="email" name="email" autocomplete="email" required value="<?= e($email) ?>">
            </div>
            <div class="form-group">
                <label class="label" for="password">รหัสผ่าน</label>
                <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
                <p class="hint">ลืมรหัสผ่าน? กรุณา<a href="contact.php">ติดต่อร้าน</a>เพื่อขอรหัสผ่านใหม่</p>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block">เข้าสู่ระบบ</button>
        </form>

        <p class="auth-switch">ยังไม่มีบัญชี? <a href="register.php?return=<?= urlencode($return) ?>">สมัครสมาชิก</a></p>
        <p class="auth-switch muted">สั่งซื้อโดยไม่สมัครสมาชิกก็ได้ เพียงเลือกสินค้าแล้วไปที่ตะกร้า</p>
    </div>
</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
