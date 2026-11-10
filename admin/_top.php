<?php
/**
 * ส่วนบนของหน้าหลังบ้าน (sidebar + หัวหน้า)
 * ตั้งค่าก่อน include:  $adminTitle, $adminActive (dashboard|products|categories|orders)
 */
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

$adminTitle  = $adminTitle ?? "หลังบ้าน";
$adminActive = $adminActive ?? "";

// ตัวเลขสำหรับป้ายในเมนูและจุดเริ่มของการแจ้งเตือน
$notifyRow = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM orders WHERE order_status = 'รอตรวจสอบการชำระเงิน') AS pending,
        (SELECT COUNT(*) FROM reviews WHERE status = 'pending') AS reviews,
        (SELECT COALESCE(MAX(id), 0) FROM orders) AS order_max,
        (SELECT COALESCE(MAX(id), 0) FROM payments WHERE slip_image IS NOT NULL) AS slip_max
")->fetch();

$pendingBadge   = (int)$notifyRow["pending"];
$pendingReviews = (int)$notifyRow["reviews"];

// [ลิงก์, ไอคอน, ชื่อ, เฉพาะเจ้าของร้าน] (ตัวแปรขึ้นต้น nav เพื่อไม่ทับตัวแปรของหน้าที่ include ไฟล์นี้)
$menuGroups = [
    "" => [
        "dashboard"  => ["dashboard.php",  "home",     "ภาพรวม",        false],
    ],
    "การขาย" => [
        "orders"     => ["orders.php",     "receipt",  "คำสั่งซื้อ",     false],
        "customers"  => ["customers.php",  "users",    "ลูกค้า",         false],
        "coupons"    => ["coupons.php",    "ticket",   "คูปองส่วนลด",    true],
        "reviews"    => ["reviews.php",    "star",     "รีวิวสินค้า",     false],
        "reports"    => ["reports.php",    "chart",    "รายงานยอดขาย",   true],
    ],
    "สินค้า" => [
        "products"   => ["products.php",   "box",      "สินค้าทั้งหมด",   false],
        "categories" => ["categories.php", "tag",      "หมวดหมู่",       false],
        "stock"      => ["stock.php",      "list",     "ประวัติสต็อก",    false],
    ],
    "ระบบ" => [
        "settings"   => ["settings.php",   "settings", "ตั้งค่าร้าน",      true],
        "admins"     => ["admins.php",     "shield",   "ผู้ดูแลระบบ",     true],
        "activity"   => ["activity.php",   "history",  "บันทึกกิจกรรม",   true],
        "backup"     => ["backup.php",     "download", "สำรองข้อมูล",     true],
        "security"   => ["security.php",   "lock",     "ความปลอดภัยบัญชี", false],
        "account"    => ["account.php",    "user",     "บัญชีของฉัน",    false],
    ],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($adminTitle) ?> | หลังบ้าน <?= e(SHOP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg?v=2">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600;700&family=Prompt:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css?v=7">
    <link rel="stylesheet" href="../assets/css/brand.css?v=1">
</head>
<body data-notify="1" data-order-max="<?= (int)$notifyRow["order_max"] ?>" data-slip-max="<?= (int)$notifyRow["slip_max"] ?>">

<div class="mobile-bar">
    <strong><?= e(SHOP_NAME) ?> · หลังบ้าน</strong>
    <button class="menu-toggle" type="button" aria-label="เปิดเมนู" aria-controls="sidebar" aria-expanded="false">☰</button>
</div>

<aside class="sidebar" id="sidebar">
    <a href="dashboard.php" class="side-brand" aria-label="<?= e(SHOP_NAME) ?> ระบบหลังบ้าน">
        <img class="brand-mascot" src="../assets/brand/mascot.svg" alt="" width="66" height="66">
        <span class="brand-text">
            <span class="brand-eyebrow">3 ZA · STATIONERY</span>
            <strong>ซ่า<span>เครื่องเขียน</span></strong>
            <small>ระบบหลังบ้าน</small>
        </span>
    </a>

    <nav class="menu" aria-label="เมนูหลังบ้าน">
        <?php foreach ($menuGroups as $navGroupLabel => $navGroupItems): ?>
            <?php $navGroupItems = array_filter($navGroupItems, fn($navItem) => !$navItem[3] || is_owner()); ?>
            <?php if (!$navGroupItems) continue; ?>
            <?php if ($navGroupLabel !== ""): ?>
                <div class="menu-label"><?= e($navGroupLabel) ?></div>
            <?php endif; ?>
            <?php foreach ($navGroupItems as $navKey => [$navHref, $navIcon, $navLabel]): ?>
                <a href="<?= $navHref ?>" class="<?= $adminActive === $navKey ? "active" : "" ?>" <?= $adminActive === $navKey ? 'aria-current="page"' : "" ?>>
                    <span class="ico"><?= ui_icon($navIcon, "18") ?></span> <?= e($navLabel) ?>
                    <?php if ($navKey === "orders"): ?>
                        <span class="count" data-count="pending" title="รอตรวจสอบ" <?= $pendingBadge > 0 ? "" : "hidden" ?>><?= $pendingBadge ?></span>
                    <?php endif; ?>
                    <?php if ($navKey === "reviews"): ?>
                        <span class="count" data-count="reviews" title="รออนุมัติ" <?= $pendingReviews > 0 ? "" : "hidden" ?>><?= $pendingReviews ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="side-foot">
        <nav class="menu" aria-label="ทางลัด">
            <button type="button" class="sound-toggle" data-sound-toggle aria-pressed="true"><span class="ico"><?= ui_icon("bell", "18") ?></span> <span>เสียงแจ้งเตือน: เปิด</span></button>
            <a href="../index.php" target="_blank" rel="noopener"><span class="ico"><?= ui_icon("store", "18") ?></span> ดูหน้าร้านค้า</a>
            <form method="POST" action="logout.php" class="menu-form">
                <?= csrf_field() ?>
                <button type="submit" class="sound-toggle"><span class="ico"><?= ui_icon("logout", "18") ?></span> <span>ออกจากระบบ</span></button>
            </form>
        </nav>
        <div class="side-user">
            <strong><?= e($_SESSION["admin_name"] ?? "ผู้ดูแลระบบ") ?></strong>
            · <?= e(ADMIN_ROLES[$_SESSION["admin_role"] ?? "staff"] ?? "") ?>
        </div>
    </div>
</aside>

<div class="admin-toast-stack" aria-live="polite"></div>

<main class="main">
<?php if (maintenance_on()): ?>
    <div class="maint-banner" role="status">
        <?= ui_icon("tool", "18") ?> หน้าร้านกำลังปิดปรับปรุง ลูกค้าจะเห็นหน้าแจ้งปิดปรับปรุง (คุณยังเห็นหน้าร้านได้เพราะเข้าสู่ระบบอยู่)
        <?php if (is_owner()): ?><a href="settings.php#store-status">เปิดร้านอีกครั้ง</a><?php endif; ?>
    </div>
<?php endif; ?>
<?php foreach (flash_pull() as $f): ?>
    <div class="alert alert-<?= e($f["type"]) ?>" role="status"><?= e($f["message"]) ?></div>
<?php endforeach; ?>
