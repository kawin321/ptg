<?php
require_once __DIR__ . "/includes/functions.php";

$customer = require_customer("my-orders.php");

const ORDERS_PER_PAGE = 10;

$stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?");
$stmt->execute([$customer["id"]]);
$total = (int)$stmt->fetchColumn();

[$page, $pages, $offset] = paginate($total, page_param(), ORDERS_PER_PAGE);

$stmt = $conn->prepare("
    SELECT o.*, p.payment_status,
           (SELECT COALESCE(SUM(i.quantity), 0) FROM order_items i WHERE i.order_id = o.id) AS item_count
    FROM orders o
    LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
    WHERE o.customer_id = ?
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT " . ORDERS_PER_PAGE . " OFFSET " . $offset);
$stmt->execute([$customer["id"]]);
$orders = $stmt->fetchAll();

$pageTitle  = "คำสั่งซื้อของฉัน";
$activePage = "account";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">

    <div class="page-head" style="padding-top:0">
        <h1>คำสั่งซื้อของฉัน</h1>
    </div>

    <?php require __DIR__ . "/includes/account-tabs.php"; ?>

    <?php if ($orders): ?>
        <div class="order-list">
            <?php foreach ($orders as $o): ?>
                <a class="panel order-card" href="track.php?order=<?= urlencode($o["order_number"]) ?>">
                    <div>
                        <strong><?= e($o["order_number"]) ?></strong>
                        <?php if (order_is_unread($o)): ?><span class="badge badge-new">มีอัปเดต</span><?php endif; ?>
                        <div class="muted" style="font-size:14px">
                            <?= e($o["created_at"]) ?> · <?= (int)$o["item_count"] ?> ชิ้น · <?= e($o["payment_method"]) ?>
                        </div>
                        <?php if ($o["payment_status"] === "ไม่ผ่าน" && $o["order_status"] !== "ยกเลิก"): ?>
                            <div style="color:var(--danger); font-size:14px; font-weight:600">สลิปไม่ผ่านการตรวจสอบ — แตะเพื่อแนบใหม่</div>
                        <?php endif; ?>
                    </div>
                    <div class="order-card-end">
                        <span class="status-badge <?= status_class($o["order_status"]) ?>"><?= e($o["order_status"]) ?></span>
                        <strong class="price">฿<?= money($o["total_amount"]) ?></strong>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?= pagination_html($page, $pages, fn($p) => "my-orders.php?page=" . $p) ?>
    <?php else: ?>
        <div class="empty-state">
            <div class="big"><?= ui_icon("receipt") ?></div>
            <h3>ยังไม่มีคำสั่งซื้อ</h3>
            <p>คำสั่งซื้อที่สั่งขณะเข้าสู่ระบบจะแสดงที่นี่</p>
            <a href="products.php" class="btn btn-primary">เลือกซื้อสินค้า</a>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
