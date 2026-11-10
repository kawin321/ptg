<?php
require_once __DIR__ . "/includes/functions.php";

$customer = require_customer("account.php");

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");

    } elseif ($action === "profile") {

        $fullname = trim($_POST["fullname"] ?? "");
        $phone    = trim($_POST["phone"] ?? "");
        $address  = trim($_POST["address"] ?? "");

        if ($fullname === "" || mb_strlen($fullname) > 150) {
            flash("error", "กรุณากรอกชื่อ-นามสกุล (ไม่เกิน 150 ตัวอักษร)");
        } elseif (!valid_thai_phone($phone)) {
            flash("error", "กรุณากรอกเบอร์โทรศัพท์ให้ถูกต้อง");
        } elseif (mb_strlen($address) > 500) {
            flash("error", "ที่อยู่ยาวเกินไป");
        } else {
            $conn->prepare("UPDATE customers SET fullname = ?, phone = ?, address = ? WHERE id = ?")
                 ->execute([$fullname, $phone, $address, $customer["id"]]);
            flash("success", "บันทึกข้อมูลเรียบร้อยแล้ว");
        }

    } elseif ($action === "password") {

        $current = $_POST["current_password"] ?? "";
        $new     = $_POST["new_password"] ?? "";
        $confirm = $_POST["confirm_password"] ?? "";

        $stmt = $conn->prepare("SELECT password FROM customers WHERE id = ?");
        $stmt->execute([$customer["id"]]);

        if (throttle_blocked("pwchange", (string)$customer["id"], 5, 900)) {
            flash("error", "ใส่รหัสผ่านปัจจุบันผิดหลายครั้งเกินไป กรุณารอสักครู่");
        } elseif (!verify_password_constant_time($current, (string)$stmt->fetchColumn())) {
            throttle_hit("pwchange", (string)$customer["id"]);
            flash("error", "รหัสผ่านปัจจุบันไม่ถูกต้อง");
        } elseif (($problem = password_problem($new, 8, [strstr($customer["email"], "@", true) ?: $customer["email"]])) !== null) {
            flash("error", $problem);
        } elseif ($new !== $confirm) {
            flash("error", "รหัสผ่านใหม่และการยืนยันไม่ตรงกัน");
        } else {
            $newHash = hash_password($new);
            $conn->prepare("UPDATE customers SET password = ? WHERE id = ?")->execute([$newHash, $customer["id"]]);
            session_regenerate_id(true);
            $_SESSION["customer_pwfp"] = password_fingerprint($newHash);   // เครื่องอื่นที่ล็อกอินค้างอยู่จะหลุด
            flash("success", "เปลี่ยนรหัสผ่านเรียบร้อยแล้ว เครื่องอื่นที่เข้าสู่ระบบค้างไว้จะถูกออกจากระบบ");
        }
    }

    redirect("account.php");
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?");
$stmt->execute([$customer["id"]]);
$orderCount = (int)$stmt->fetchColumn();

$pageTitle  = "บัญชีของฉัน";
$activePage = "account";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">

    <div class="page-head" style="padding-top:0">
        <h1>สวัสดี, <?= e($customer["fullname"]) ?></h1>
        <p><?= e($customer["email"]) ?></p>
    </div>

    <?php require __DIR__ . "/includes/account-tabs.php"; ?>

    <div class="account-grid">
        <section class="panel">
            <h2>ข้อมูลส่วนตัว</h2>
            <form method="POST" data-once>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">

                <div class="form-group">
                    <label class="label" for="fullname">ชื่อ-นามสกุล</label>
                    <input class="form-control" id="fullname" name="fullname" maxlength="150" required value="<?= e($customer["fullname"]) ?>">
                </div>
                <div class="form-group">
                    <label class="label" for="phone">เบอร์โทรศัพท์</label>
                    <input class="form-control" type="tel" id="phone" name="phone" maxlength="20" required value="<?= e($customer["phone"]) ?>">
                </div>
                <div class="form-group">
                    <label class="label" for="address">แผนก / ที่อยู่สำหรับจัดส่ง</label>
                    <textarea class="form-control" id="address" name="address" maxlength="500"><?= e($customer["address"]) ?></textarea>
                    <p class="hint">กรอกไว้แล้วระบบจะเติมให้ตอนสั่งซื้อ</p>
                </div>
                <button type="submit" class="btn btn-primary">บันทึกข้อมูล</button>
            </form>
        </section>

        <div>
            <section class="panel">
                <h2>เปลี่ยนรหัสผ่าน</h2>
                <form method="POST" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">

                    <div class="form-group">
                        <label class="label" for="current_password">รหัสผ่านปัจจุบัน</label>
                        <input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="form-group">
                        <label class="label" for="new_password">รหัสผ่านใหม่</label>
                        <input class="form-control" type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="form-group">
                        <label class="label" for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
                        <input class="form-control" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-light">เปลี่ยนรหัสผ่าน</button>
                </form>
            </section>

            <section class="panel">
                <p style="margin:0 0 12px">คำสั่งซื้อทั้งหมด <strong><?= $orderCount ?></strong> รายการ</p>
                <form action="logout.php" method="POST">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost">ออกจากระบบ</button>
                </form>
            </section>
        </div>
    </div>
</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
