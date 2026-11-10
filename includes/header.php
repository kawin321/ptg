<?php
/**
 * ส่วนหัวของหน้าร้านค้า
 * ตัวแปรที่หน้าเรียกใช้กำหนดได้:
 *   $pageTitle     ชื่อหน้า
 *   $activePage    home | products | cart | track
 *   $showCategoryNav  true เพื่อแสดงแถบหมวดหมู่
 *   $pageDescription  คำอธิบายหน้า (meta)
 */
require_once __DIR__ . "/functions.php";

$pageTitle       = $pageTitle ?? SHOP_NAME;
$activePage      = $activePage ?? "";
$showCategoryNav = $showCategoryNav ?? false;
$pageDescription = $pageDescription ?? "ร้านเครื่องเขียนและอุปกรณ์การเรียน สั่งซื้อผ่านเว็บไซต์ รับที่ร้านหรือส่งถึงแผนก";
$cartCount       = cart_count();
$navCustomer     = current_customer();
$currentCategory = isset($currentCategory) ? (int)$currentCategory : 0;

if ($showCategoryNav) {
    $navCategories = $conn->query("SELECT * FROM categories ORDER BY id ASC")->fetchAll();
}

$navItems = [
    "home"     => ["index.php",    "หน้าหลัก"],
    "products" => ["products.php", "สินค้าทั้งหมด"],
    "track"    => ["track.php",    "ติดตามคำสั่งซื้อ"],
    "contact"  => ["contact.php",  "ติดต่อร้าน"],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($pageDescription) ?>">
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" type="image/svg+xml" href="favicon.svg?v=2">
    <?php
    $ogHost  = preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $_SERVER["HTTP_HOST"] ?? "") ? $_SERVER["HTTP_HOST"] : "";
    $ogHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") || ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https";
    $ogDir   = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");
    $ogBase  = $ogHost !== "" ? ($ogHttps ? "https://" : "http://") . $ogHost . $ogDir . "/" : "";
    ?>
    <meta property="og:type" content="website">
    <meta property="og:locale" content="th_TH">
    <meta property="og:site_name" content="<?= e(SHOP_NAME) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?> | <?= e(SHOP_NAME) ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <?php if ($ogBase !== ""): ?>
    <meta property="og:image" content="<?= e($ogBase) ?>assets/images/products/92036.jpg">
    <?php endif; ?>
    <title><?= e($pageTitle) ?> | <?= e(SHOP_NAME) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&family=Prompt:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=9">
    <link rel="stylesheet" href="assets/css/brand.css?v=1">
</head>
<body>

<a href="#main" class="skip-link">ข้ามไปยังเนื้อหา</a>

<?php if (maintenance_on() && !empty($_SESSION["admin_id"])): ?>
    <div class="maint-strip" role="status">
        โหมดปิดปรับปรุงเปิดอยู่ · ลูกค้ามองไม่เห็นหน้านี้ คุณเห็นเพราะเข้าสู่ระบบผู้ดูแลอยู่
        <a href="admin/settings.php#store-status">จัดการ</a>
    </div>
<?php endif; ?>

<header class="site-header">
    <div class="header-inner">

        <a href="index.php" class="brand" aria-label="<?= e(SHOP_NAME) ?> หน้าหลัก">
            <img class="brand-mascot" src="assets/brand/mascot.svg" alt="" width="80" height="80">
            <span class="brand-text">
                <span class="brand-eyebrow">3 ZA · STATIONERY</span>
                <strong>ซ่า<span>เครื่องเขียน</span></strong>
                <small>Everything You Need for Your Study</small>
            </span>
        </a>

        <button class="nav-toggle" type="button" aria-label="เปิดเมนู" aria-expanded="false" aria-controls="site-nav">
            <span></span><span></span><span></span>
        </button>

        <nav class="site-nav" id="site-nav" aria-label="เมนูหลัก">
            <?php foreach ($navItems as $key => [$href, $label]): ?>
                <a href="<?= $href ?>" class="<?= $activePage === $key ? "active" : "" ?>"><?= e($label) ?></a>
            <?php endforeach; ?>

            <?php if ($navCustomer): ?>
                <a href="wishlist.php" aria-label="รายการโปรด" title="รายการโปรด"><?= ui_icon("heart") ?></a>
                <a href="account.php" class="<?= $activePage === "account" ? "active" : "" ?>"><?= ui_icon("user") ?> บัญชี<?php if (customer_unread_orders() > 0): ?> <span class="nav-count" title="คำสั่งซื้อมีอัปเดต"><?= customer_unread_orders() ?></span><?php endif; ?></a>
            <?php else: ?>
                <a href="login.php" class="<?= $activePage === "account" ? "active" : "" ?>">เข้าสู่ระบบ</a>
            <?php endif; ?>

            <a href="cart.php" class="cart-link <?= $activePage === "cart" ? "active" : "" ?>">
                <?= ui_icon("cart") ?>
                ตะกร้า
                <span class="cart-badge <?= $cartCount === 0 ? "is-empty" : "" ?>" id="cart-badge"><?= $cartCount ?></span>
            </a>
        </nav>

    </div>
</header>

<?php if ($showCategoryNav): ?>
<nav class="category-bar" aria-label="หมวดหมู่สินค้า">
    <div class="category-bar-inner">
        <a href="products.php" class="<?= $currentCategory === 0 && $activePage === "products" ? "active" : "" ?>">ทั้งหมด</a>
        <?php foreach ($navCategories as $cat): ?>
            <a href="products.php?category=<?= (int)$cat["id"] ?>"
               class="<?= $currentCategory === (int)$cat["id"] ? "active" : "" ?>">
                <?= e($cat["category_name"]) ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
<?php endif; ?>

<?php $flashMessages = flash_pull(); ?>
<?php if ($flashMessages): ?>
<div class="toast-stack" role="status" aria-live="polite">
    <?php foreach ($flashMessages as $f): ?>
        <div class="toast toast-<?= e($f["type"]) ?>">
            <span class="toast-icon" aria-hidden="true"><?= $f["type"] === "success" ? ui_icon("check") : "!" ?></span>
            <span><?= e($f["message"]) ?></span>
            <button type="button" class="toast-close" aria-label="ปิด">×</button>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<main id="main">
