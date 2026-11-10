<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/product-card.php";

/* เพิ่ม/เอาออกจากรายการโปรด */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $return = safe_return_url($_POST["return"] ?? "", "wishlist.php");

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
        redirect($return);
    }

    $customer = current_customer();

    if (!$customer) {
        flash("error", "กรุณาเข้าสู่ระบบเพื่อใช้รายการโปรด");
        redirect("login.php?return=" . urlencode($return));
    }

    $productId = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);

    $stmt = $conn->prepare("SELECT product_name FROM products WHERE id = ?");
    $stmt->execute([$productId ?: 0]);
    $name = $stmt->fetchColumn();

    if ($name === false) {
        flash("error", "ไม่พบสินค้า");
        redirect($return);
    }

    $del = $conn->prepare("DELETE FROM wishlists WHERE customer_id = ? AND product_id = ?");
    $del->execute([$customer["id"], $productId]);

    if ($del->rowCount() > 0) {
        flash("success", "เอา “{$name}” ออกจากรายการโปรดแล้ว");
    } else {
        $conn->prepare("INSERT IGNORE INTO wishlists (customer_id, product_id) VALUES (?, ?)")
             ->execute([$customer["id"], $productId]);
        flash("success", "เพิ่ม “{$name}” ในรายการโปรดแล้ว");
    }

    redirect($return);
}

$customer = require_customer("wishlist.php");

$stmt = $conn->prepare("
    SELECT p.*, c.category_name
    FROM wishlists w
    JOIN products p ON p.id = w.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE w.customer_id = ?
    ORDER BY w.created_at DESC
");
$stmt->execute([$customer["id"]]);
$products = $stmt->fetchAll();

$pageTitle  = "รายการโปรด";
$activePage = "account";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">
    <div class="page-head" style="padding-top:0">
        <h1>รายการโปรด</h1>
    </div>

    <?php require __DIR__ . "/includes/account-tabs.php"; ?>

    <div class="product-grid">
        <?php if ($products): ?>
            <?php foreach ($products as $product): ?>
                <?php render_product_card($product, "wishlist.php"); ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="big"><?= ui_icon("heart") ?></div>
                <h3>ยังไม่มีรายการโปรด</h3>
                <p>กดรูปหัวใจที่สินค้าเพื่อเก็บไว้ดูภายหลัง</p>
                <a href="products.php" class="btn btn-primary">เลือกชมสินค้า</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
