<?php
require_once __DIR__ . "/includes/functions.php";

$return = safe_return_url($_GET["return"] ?? $_POST["return"] ?? "", "account.php");

if (current_customer()) {
    redirect($return);
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullname = trim($_POST["fullname"] ?? "");
    $email    = mb_strtolower(trim($_POST["email"] ?? ""));
    $phone    = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm  = $_POST["confirm_password"] ?? "";

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        $error = "คำขอไม่ถูกต้อง กรุณาลองใหม่";
    } elseif (!bot_ok()) {
        $error = BOT_MESSAGE;
    } elseif (throttle_blocked("register", "", 20, 3600)) {
        $error = "สมัครสมาชิกบ่อยเกินไปจากเครือข่ายนี้ กรุณารอสักครู่แล้วลองใหม่";
    } elseif ($fullname === "" || mb_strlen($fullname) > 150) {
        $error = "กรุณากรอกชื่อ-นามสกุล (ไม่เกิน 150 ตัวอักษร)";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $error = "กรุณากรอกอีเมลให้ถูกต้อง";
    } elseif (!valid_thai_phone($phone)) {
        $error = "กรุณากรอกเบอร์โทรศัพท์ให้ถูกต้อง";
    } elseif (($problem = password_problem($password, 8, [strstr($email, "@", true) ?: $email])) !== null) {
        $error = $problem;
    } elseif ($password !== $confirm) {
        $error = "รหัสผ่านและการยืนยันไม่ตรงกัน";
    } else {
        try {
            throttle_hit("register");
            $newHash = hash_password($password);

            $conn->prepare("INSERT INTO customers (email, password, fullname, phone) VALUES (?, ?, ?, ?)")
                 ->execute([$email, $newHash, $fullname, $phone]);

            customer_login_finish((int)$conn->lastInsertId(), $newHash);

            flash("success", "สมัครสมาชิกสำเร็จ ยินดีต้อนรับ!");
            redirect($return);

        } catch (PDOException $e) {
            if ($e->getCode() === "23000") {
                $error = "อีเมลนี้ถูกใช้สมัครแล้ว กรุณาเข้าสู่ระบบ";
            } else {
                error_log($e->getMessage());
                $error = "ไม่สามารถสมัครสมาชิกได้ กรุณาลองใหม่";
            }
        }
    }
}

$pageTitle  = "สมัครสมาชิก";
$activePage = "account";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">
    <div class="panel auth-card">
        <h1>สมัครสมาชิก</h1>
        <p class="muted">เก็บประวัติคำสั่งซื้อ รายการโปรด และรีวิวสินค้าได้</p>

        <?php if ($error !== ""): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" data-once>
            <?= csrf_field() ?>
            <?= bot_fields() ?>
            <input type="hidden" name="return" value="<?= e($return) ?>">

            <div class="form-group">
                <label class="label" for="fullname">ชื่อ-นามสกุล</label>
                <input class="form-control" id="fullname" name="fullname" maxlength="150" autocomplete="name" required value="<?= e($_POST["fullname"] ?? "") ?>">
            </div>
            <div class="form-group">
                <label class="label" for="email">อีเมล</label>
                <input class="form-control" type="email" id="email" name="email" maxlength="190" autocomplete="email" required value="<?= e($_POST["email"] ?? "") ?>">
            </div>
            <div class="form-group">
                <label class="label" for="phone">เบอร์โทรศัพท์</label>
                <input class="form-control" type="tel" id="phone" name="phone" maxlength="20" autocomplete="tel" required value="<?= e($_POST["phone"] ?? "") ?>">
            </div>
            <div class="form-group">
                <label class="label" for="password">รหัสผ่าน</label>
                <input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                <p class="hint">อย่างน้อย 8 ตัวอักษร</p>
            </div>
            <div class="form-group">
                <label class="label" for="confirm_password">ยืนยันรหัสผ่าน</label>
                <input class="form-control" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block">สมัครสมาชิก</button>
        </form>

        <p class="auth-switch">มีบัญชีอยู่แล้ว? <a href="login.php?return=<?= urlencode($return) ?>">เข้าสู่ระบบ</a></p>
    </div>
</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
