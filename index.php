<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/product-card.php";

// หมวดหมู่พร้อมจำนวนสินค้า
$categories = $conn->query("
    SELECT c.id, c.category_name, COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id, c.category_name
    ORDER BY c.id ASC
")->fetchAll();

// สินค้าขายดี 4 อันดับ: นับชิ้นที่ขายได้ (ไม่นับคำสั่งซื้อที่ยกเลิก)
// สินค้าที่มีของมาก่อน ถ้ายังขายได้ไม่ถึง 4 รายการ จะเติมด้วยสินค้าใหม่
$products = $conn->query("
    SELECT products.*, categories.category_name, COALESCE(sold.qty, 0) AS sold_qty
    FROM products
    LEFT JOIN categories ON products.category_id = categories.id
    LEFT JOIN (
        SELECT oi.product_id, SUM(oi.quantity) AS qty
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.order_status <> 'ยกเลิก'
        GROUP BY oi.product_id
    ) sold ON sold.product_id = products.id
    ORDER BY (products.stock > 0) DESC, sold_qty DESC, products.id DESC
    LIMIT 4
")->fetchAll();

$totalProducts = (int)$conn->query("SELECT COUNT(*) FROM products")->fetchColumn();

$pageTitle  = "Everything You Need for Your Study";
$activePage = "home";
require __DIR__ . "/includes/header.php";
?>

<!-- Hero -->
<section class="hero">
    <div class="hero-inner">
        <div>
            <h1>เครื่องเขียนของนักเรียน นักศึกษา สั่งออนไลน์ <mark>รับที่ร้านหรือที่แผนก</mark></h1>
            <p class="lead">
                สมุด ปากกา อุปกรณ์ศิลปะ และของใช้ในการเรียน <?= number_format($totalProducts) ?> รายการ
                จ่ายได้ทั้งโอนเงิน เงินสดที่ร้าน หรือเก็บเงินปลายทาง
            </p>
            <div class="hero-actions">
                <a href="products.php" class="btn btn-primary btn-lg">เลือกซื้อสินค้า</a>
                <a href="track.php" class="btn btn-light btn-lg">ติดตามคำสั่งซื้อ</a>
            </div>
        </div>

        <div class="hero-photo">
            <img src="assets/images/products/92036.jpg" alt="เครื่องเขียน สมุด สีน้ำ และอุปกรณ์การเรียนวางบนโต๊ะไม้">
        </div>
    </div>
</section>

<!-- วิธีที่ร้านให้บริการ -->
<section class="features" aria-label="บริการของร้าน">
    <div class="feature">
        <div class="feature-icon"><?= ui_icon("store") ?></div>
        <div><strong>รับที่ร้าน หรือส่งถึงแผนก</strong><span>เลือกได้ตอนสั่งซื้อ</span></div>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= ui_icon("cash") ?></div>
        <div><strong>โอน เงินสด หรือปลายทาง</strong><span>แนบสลิปได้ทันทีเมื่อโอนเงิน</span></div>
    </div>
    <div class="feature">
        <div class="feature-icon"><?= ui_icon("receipt") ?></div>
        <div><strong>ติดตามสถานะได้เอง</strong><span>ด้วยเลขที่คำสั่งซื้อและเบอร์โทร</span></div>
    </div>
</section>

<!-- หมวดหมู่ -->
<?php if ($categories): ?>
<section class="section">
    <div class="container">
        <div class="section-head">
            <div>
                <h2>เลือกตามหมวดหมู่</h2>
            </div>
        </div>

        <div class="category-grid">
            <?php foreach ($categories as $cat): ?>
                <a class="category-tile" href="products.php?category=<?= (int)$cat["id"] ?>">
                    <span class="icon" aria-hidden="true"><?= category_icon($cat["category_name"]) ?></span>
                    <span><strong><?= e($cat["category_name"]) ?></strong>
                    <small><?= (int)$cat["product_count"] ?> รายการ</small></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- สินค้าขายดี -->
<section class="section" style="padding-top:0">
    <div class="container">
        <div class="section-head">
            <div>
                <h2>สินค้าขายดี</h2>
            </div>
            <a href="products.php?sort=best" class="link-arrow">ดูสินค้าขายดีทั้งหมด</a>
        </div>

        <div class="product-grid">
            <?php if ($products): ?>
                <?php foreach ($products as $product): ?>
                    <?php render_product_card($product, "index.php"); ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="big"><?= ui_icon("box") ?></div>
                    <h3>ยังไม่มีสินค้าในร้าน</h3>
                    <p>โปรดกลับมาใหม่เร็ว ๆ นี้</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require __DIR__ . "/includes/footer.php"; ?>
