<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/product-card.php";

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id) {
    redirect("products.php");
}

$stmt = $conn->prepare("
    SELECT products.*, categories.category_name
    FROM products
    LEFT JOIN categories ON products.category_id = categories.id
    WHERE products.id = ?
");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    $pageTitle  = "ไม่พบสินค้า";
    $activePage = "products";
    require __DIR__ . "/includes/header.php";
    ?>
    <div class="container section">
        <div class="empty-state">
            <div class="big"><?= ui_icon("search") ?></div>
            <h3>ไม่พบสินค้าที่ต้องการ</h3>
            <p>สินค้านี้อาจถูกลบหรือไม่มีอยู่ในระบบ</p>
            <a href="products.php" class="btn btn-primary">กลับไปดูสินค้าทั้งหมด</a>
        </div>
    </div>
    <?php
    require __DIR__ . "/includes/footer.php";
    exit;
}

/* ---------- รีวิว ---------- */
$customer = current_customer();
$canReview = $customer && customer_purchased((int)$customer["id"], (int)$id);

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "review") {

    $rating  = filter_input(INPUT_POST, "rating", FILTER_VALIDATE_INT);
    $comment = trim($_POST["comment"] ?? "");

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
    } elseif (!bot_ok()) {
        flash("error", BOT_MESSAGE);
    } elseif (!$canReview) {
        flash("error", "รีวิวได้เฉพาะสินค้าที่สั่งซื้อและได้รับแล้วเท่านั้น");
    } elseif (throttle_blocked("review", (string)$customer["id"], 10, 3600)) {
        flash("error", "ส่งรีวิวบ่อยเกินไป กรุณารอสักครู่");
    } elseif (!$rating || $rating < 1 || $rating > 5) {
        flash("error", "กรุณาให้คะแนน 1-5 ดาว");
    } elseif (mb_strlen($comment) > 1000) {
        flash("error", "ความคิดเห็นยาวเกินไป (ไม่เกิน 1,000 ตัวอักษร)");
    } else {
        // แก้รีวิวเดิมได้ แต่ต้องรออนุมัติใหม่
        $conn->prepare("INSERT INTO reviews (product_id, customer_id, rating, comment, status)
                        VALUES (?, ?, ?, ?, 'pending')
                        ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), status = 'pending'")
             ->execute([$id, $customer["id"], $rating, $comment]);
        throttle_hit("review", (string)$customer["id"]);
        flash("success", "ส่งรีวิวแล้ว จะแสดงหลังร้านอนุมัติ");
    }

    redirect("product.php?id=" . (int)$id . "#reviews");
}

$stmt = $conn->prepare("SELECT r.rating, r.comment, r.created_at, c.fullname
    FROM reviews r JOIN customers c ON c.id = r.customer_id
    WHERE r.product_id = ? AND r.status = 'approved'
    ORDER BY r.created_at DESC LIMIT 20");
$stmt->execute([$id]);
$reviews = $stmt->fetchAll();

$myReview = null;
if ($customer) {
    $stmt = $conn->prepare("SELECT rating, comment, status FROM reviews WHERE product_id = ? AND customer_id = ?");
    $stmt->execute([$id, $customer["id"]]);
    $myReview = $stmt->fetch() ?: null;
}

$ratingInfo = product_ratings()[(int)$id] ?? null;

$stock    = (int)$product["stock"];
$inCart   = (int)($_SESSION["cart"][$id] ?? 0);
$canAdd   = max(0, $stock - $inCart);
$image    = product_image($product["image"] ?? null);

// รูปหลัก + รูปเพิ่มเติม (ข้ามไฟล์ที่หายไป)
$images = array_values(array_filter(array_merge(
    [$image],
    array_map(fn($g) => product_image($g["image"]), product_gallery((int)$id))
)));

// สินค้าที่เกี่ยวข้อง (หมวดเดียวกัน)
$related = [];
if (!empty($product["category_id"])) {
    $stmt = $conn->prepare("
        SELECT products.*, categories.category_name
        FROM products
        LEFT JOIN categories ON products.category_id = categories.id
        WHERE products.category_id = ? AND products.id <> ?
        ORDER BY (products.stock > 0) DESC, products.id DESC
        LIMIT 4
    ");
    $stmt->execute([$product["category_id"], $id]);
    $related = $stmt->fetchAll();
}

$pageTitle       = $product["product_name"];
$pageDescription = mb_substr((string)($product["description"] ?: "สินค้าจาก " . SHOP_NAME), 0, 150);
$activePage      = "products";
require __DIR__ . "/includes/header.php";
?>

<div class="container">

    <nav class="breadcrumb" aria-label="breadcrumb">
        <a href="index.php">หน้าหลัก</a> /
        <a href="products.php">สินค้า</a> /
        <?php if (!empty($product["category_id"])): ?>
            <a href="products.php?category=<?= (int)$product["category_id"] ?>"><?= e($product["category_name"]) ?></a> /
        <?php endif; ?>
        <span><?= e($product["product_name"]) ?></span>
    </nav>

    <section class="detail">
        <div class="gallery">
        <div class="detail-image gallery-main">
            <?php if ($images): ?>
                <img src="<?= e($images[0]) ?>" alt="<?= e($product["product_name"]) ?>">
            <?php else: ?>
                <div class="no-image"><?= category_icon($product["category_name"]) ?></div>
            <?php endif; ?>
        </div>
        <?php if (count($images) > 1): ?>
            <div class="gallery-thumbs" aria-label="รูปสินค้าทั้งหมด">
                <?php foreach ($images as $i => $src): ?>
                    <a href="<?= e($src) ?>" aria-label="ดูรูปที่ <?= $i + 1 ?>" <?= $i === 0 ? 'aria-current="true"' : "" ?>><img src="<?= e($src) ?>" alt="" loading="lazy"></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>

        <div class="detail-info">
            <?php if (!empty($product["category_name"])): ?>
                <span class="badge badge-cat"><?= e($product["category_name"]) ?></span>
            <?php endif; ?>

            <h1><?= e($product["product_name"]) ?></h1>

            <?php if ($ratingInfo): ?>
                <a href="#reviews" class="rating-line" style="text-decoration:none"><?= stars($ratingInfo[0]) ?> <small><?= $ratingInfo[0] ?> จาก <?= $ratingInfo[1] ?> รีวิว</small></a>
            <?php endif; ?>

            <?= price_html($product, "detail-price") ?>
            <?php if (sale_active($product) && !empty($product["sale_ends"])): ?>
                <p class="sale-note">ราคาพิเศษถึง <?= e(date("d/m/Y", strtotime($product["sale_ends"]))) ?></p>
            <?php endif; ?>

            <div class="detail-meta">
                <?php if ($stock < 1): ?>
                    <span class="badge badge-out">สินค้าหมด</span>
                <?php elseif ($stock <= 5): ?>
                    <span class="badge badge-low">เหลือเพียง <?= $stock ?> ชิ้น</span>
                <?php else: ?>
                    <span class="badge badge-ok">มีสินค้า · คงเหลือ <?= $stock ?> ชิ้น</span>
                <?php endif; ?>
                <?php if ($inCart > 0): ?>
                    <span class="muted">อยู่ในตะกร้าแล้ว <?= $inCart ?> ชิ้น</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($product["description"])): ?>
                <h3 style="font-size:16px">รายละเอียดสินค้า</h3>
                <p class="detail-desc"><?= e($product["description"]) ?></p>
            <?php endif; ?>

            <?php if ($canAdd > 0): ?>
                <form action="cart.php" method="POST" class="buy-row" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= $id ?>">
                    <input type="hidden" name="return" value="product.php?id=<?= $id ?>">

                    <div class="stepper">
                        <button type="button" data-step="-1" aria-label="ลดจำนวน">−</button>
                        <input type="number" name="quantity" value="1" min="1" max="<?= $canAdd ?>" aria-label="จำนวน">
                        <button type="button" data-step="1" aria-label="เพิ่มจำนวน">+</button>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg"><?= ui_icon("cart") ?> เพิ่มลงตะกร้า</button>
                </form>
            <?php elseif ($stock > 0): ?>
                <div class="alert alert-info">คุณเลือกสินค้าชิ้นนี้ครบตามจำนวนที่มีอยู่แล้ว</div>
                <a href="cart.php" class="btn btn-primary">ไปที่ตะกร้าสินค้า</a>
            <?php else: ?>
                <a href="products.php" class="btn btn-light">ดูสินค้าอื่น ๆ</a>
            <?php endif; ?>

            <div style="margin-top:14px"><?= wishlist_form_inline((int)$id) ?></div>
        </div>
    </section>

    <section class="panel" id="reviews" style="margin-top:26px">
        <h2>รีวิวจากลูกค้า<?= $ratingInfo ? " (" . $ratingInfo[1] . ")" : "" ?></h2>

        <?php if ($reviews): ?>
            <?php foreach ($reviews as $review): ?>
                <article class="review">
                    <div><?= stars((float)$review["rating"]) ?> <strong><?= e(mb_substr($review["fullname"], 0, 2)) ?>***</strong>
                        <small class="muted"><?= e(substr($review["created_at"], 0, 10)) ?></small></div>
                    <?php if ($review["comment"] !== null && $review["comment"] !== ""): ?>
                        <p style="margin:4px 0 0; white-space:pre-line"><?= e($review["comment"]) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="muted">ยังไม่มีรีวิวสำหรับสินค้านี้</p>
        <?php endif; ?>

        <?php if ($canReview): ?>
            <h3 style="font-size:16px; margin-top:20px">รีวิวของคุณ</h3>
            <?php if ($myReview && $myReview["status"] === "pending"): ?>
                <div class="alert alert-info">รีวิวของคุณกำลังรอร้านอนุมัติ</div>
            <?php elseif ($myReview && $myReview["status"] === "rejected"): ?>
                <div class="alert alert-error">รีวิวก่อนหน้าไม่ได้รับการอนุมัติ แก้ไขแล้วส่งใหม่ได้</div>
            <?php endif; ?>
            <form method="POST" data-once>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="review">
                <?= bot_fields() ?>
                <div class="form-group">
                    <label class="label" for="rating">คะแนน</label>
                    <select class="form-control" id="rating" name="rating" required style="max-width:220px">
                        <?php for ($r = 5; $r >= 1; $r--): ?>
                            <option value="<?= $r ?>" <?= (int)($myReview["rating"] ?? 5) === $r ? "selected" : "" ?>><?= str_repeat("★", $r) ?> (<?= $r ?>)</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="label" for="comment">ความคิดเห็น (ไม่บังคับ)</label>
                    <textarea class="form-control" id="comment" name="comment" maxlength="1000"><?= e($myReview["comment"] ?? "") ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">ส่งรีวิว</button>
            </form>
        <?php elseif (!$customer): ?>
            <p class="muted" style="margin-top:12px">ลูกค้าที่<a href="login.php?return=<?= urlencode("product.php?id=" . (int)$id) ?>">เข้าสู่ระบบ</a>และได้รับสินค้าแล้วสามารถรีวิวได้</p>
        <?php else: ?>
            <p class="muted" style="margin-top:12px">รีวิวได้หลังจากสั่งซื้อสินค้านี้และคำสั่งซื้อสำเร็จแล้ว</p>
        <?php endif; ?>
    </section>

    <?php if ($related): ?>
        <section class="section">
            <div class="section-head">
                <div><h2>สินค้าที่คุณอาจสนใจ</h2><p>จากหมวด <?= e($product["category_name"]) ?></p></div>
            </div>
            <div class="product-grid">
                <?php foreach ($related as $item): ?>
                    <?php render_product_card($item, "product.php?id=" . $id); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
