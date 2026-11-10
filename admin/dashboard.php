<?php
$adminTitle  = "ภาพรวม";
$adminActive = "dashboard";
require __DIR__ . "/_top.php";

$productCount  = (int)$conn->query("SELECT COUNT(*) FROM products")->fetchColumn();
$categoryCount = (int)$conn->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$orderCount    = (int)$conn->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$pendingCount  = (int)$conn->query("SELECT COUNT(*) FROM orders WHERE order_status = 'รอตรวจสอบการชำระเงิน'")->fetchColumn();

// ยอดขาย (ไม่นับคำสั่งซื้อที่ยกเลิก)
$revenue = (float)$conn->query(
    "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE order_status <> 'ยกเลิก'"
)->fetchColumn();

$todayOrders = (int)$conn->query(
    "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()"
)->fetchColumn();

$recentOrders = $conn->query(
    "SELECT id, order_number, customer_name, total_amount, order_status, created_at
     FROM orders ORDER BY created_at DESC, id DESC LIMIT 6"
)->fetchAll();

$lowStock = $conn->query(
    "SELECT id, product_name, stock FROM products WHERE stock <= 5 ORDER BY stock ASC, id DESC LIMIT 5"
)->fetchAll();

$lowStockCount = (int)$conn->query("SELECT COUNT(*) FROM products WHERE stock <= 5")->fetchColumn();

// ยอดขาย 14 วันล่าสุดจากคำสั่งซื้อจริง (ไม่นับที่ยกเลิก)
$dailyRows = $conn->query(
    "SELECT DATE(created_at) AS day, COUNT(*) AS orders, SUM(total_amount) AS revenue
     FROM orders
     WHERE order_status <> 'ยกเลิก' AND created_at >= CURDATE() - INTERVAL 13 DAY
     GROUP BY DATE(created_at)"
)->fetchAll();

$dailyByDay = array_column($dailyRows, null, "day");
$chartDays  = [];

for ($i = 13; $i >= 0; $i--) {
    $day = date("Y-m-d", strtotime("-{$i} day"));
    $chartDays[] = [
        "day"     => $day,
        "label"   => date("d/m", strtotime($day)),
        "orders"  => (int)($dailyByDay[$day]["orders"] ?? 0),
        "revenue" => (float)($dailyByDay[$day]["revenue"] ?? 0),
    ];
}

$chartMax   = max(array_column($chartDays, "revenue"));
$chartTotal = array_sum(array_column($chartDays, "revenue"));
$today      = date("Y-m-d");

$pendingUrl = "orders.php?status=" . urlencode("รอตรวจสอบการชำระเงิน");
?>

<div class="topbar">
    <div>
        <h1>ภาพรวมร้าน</h1>
        <p><?= date("d/m/Y") ?></p>
    </div>
    <div class="actions">
        <a href="orders.php" class="btn btn-light">ดูคำสั่งซื้อ</a>
        <a href="product-add.php" class="btn btn-primary">+ เพิ่มสินค้า</a>
    </div>
</div>

<?php if ($pendingCount > 0): ?>
    <div class="notice-bar" role="status">
        <span>มี <?= number_format($pendingCount) ?> คำสั่งซื้อรอตรวจสอบการชำระเงิน</span>
        <a href="<?= e($pendingUrl) ?>" class="btn btn-primary btn-sm">ตรวจสอบเลย</a>
    </div>
<?php endif; ?>

<?php if (is_owner() && !(int)$currentAdmin["totp_enabled"]): ?>
    <div class="notice-bar" role="status">
        <span>บัญชีเจ้าของร้านยังไม่ได้เปิดการยืนยันตัวตนสองขั้นตอน แนะนำให้เปิดเพื่อกันคนอื่นเข้าบัญชี</span>
        <a href="security.php" class="btn btn-primary btn-sm">ตั้งค่าตอนนี้</a>
    </div>
<?php endif; ?>

<section class="stats" aria-label="สถิติร้านค้า">
    <?php if (is_owner()): ?>
    <a class="stat" href="reports.php">
        <span class="lbl">ยอดขายรวม</span>
        <span class="num">฿<?= money($revenue) ?></span>
        <span class="sub">ไม่รวมที่ยกเลิก</span>
    </a>
    <?php endif; ?>
    <a class="stat" href="orders.php">
        <span class="lbl">คำสั่งซื้อวันนี้</span>
        <span class="num"><?= number_format($todayOrders) ?></span>
        <span class="sub">ทั้งหมด <?= number_format($orderCount) ?> รายการ</span>
    </a>
    <a class="stat <?= $pendingCount > 0 ? "is-warning" : "" ?>" href="<?= e($pendingUrl) ?>">
        <span class="lbl">รอตรวจสอบการชำระเงิน</span>
        <span class="num"><?= number_format($pendingCount) ?></span>
        <span class="sub">คำสั่งซื้อ</span>
    </a>
    <a class="stat <?= $lowStockCount > 0 ? "is-warning" : "" ?>" href="products.php">
        <span class="lbl">สินค้าใกล้หมด</span>
        <span class="num"><?= number_format($lowStockCount) ?></span>
        <span class="sub">จาก <?= number_format($productCount) ?> รายการ</span>
    </a>
</section>

<div class="page-stack">

    <div class="<?= is_owner() ? "grid-2" : "" ?>">
        <?php if (is_owner()): ?>
        <section class="card">
            <div class="card-head">
                <h2>ยอดขาย 14 วันล่าสุด</h2>
                <span class="chart-total">฿<?= money($chartTotal) ?></span>
            </div>

            <?php if ($chartMax > 0): ?>
                <div class="chart-scale"><span>สูงสุด ฿<?= money($chartMax) ?> ต่อวัน</span><a href="reports.php">ดูตัวเลขรายวัน</a></div>
                <div class="bars" role="img" aria-label="กราฟยอดขายรายวัน 14 วันล่าสุด รวม ฿<?= money($chartTotal) ?>">
                    <?php foreach ($chartDays as $d): ?>
                        <div class="bar-col <?= $d["day"] === $today ? "is-today" : "" ?>" tabindex="0">
                            <span class="bar-tip"><?= e($d["label"]) ?> · ฿<?= money($d["revenue"]) ?> · <?= $d["orders"] ?> คำสั่งซื้อ</span>
                            <span class="bar" style="height:<?= round($d["revenue"] / $chartMax * 100, 1) ?>%"></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="bar-labels" aria-hidden="true">
                    <?php foreach ($chartDays as $d): ?><span><?= e($d["label"]) ?></span><?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">ยังไม่มียอดขายใน 14 วันล่าสุด</div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="card">
            <div class="card-head">
                <h2>สินค้าใกล้หมด</h2>
                <a href="products.php" class="btn btn-light btn-sm">จัดการ</a>
            </div>

            <?php if ($lowStock): ?>
                <div class="table-wrap"><table>
                    <tbody>
                    <?php foreach ($lowStock as $p): ?>
                        <tr>
                            <td><?= e($p["product_name"]) ?></td>
                            <td class="num">
                                <?php if ((int)$p["stock"] < 1): ?>
                                    <span class="badge badge-out">หมด</span>
                                <?php else: ?>
                                    <span class="badge badge-low">เหลือ <?= (int)$p["stock"] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><a class="edit-btn" href="product-edit.php?id=<?= (int)$p["id"] ?>">เติมสต็อก</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <div class="empty">สต็อกสินค้าเพียงพอทุกรายการ</div>
            <?php endif; ?>
        </section>
    </div>

    <section class="card">
        <div class="card-head">
            <h2>คำสั่งซื้อล่าสุด</h2>
            <a href="orders.php" class="btn btn-light btn-sm">ดูทั้งหมด</a>
        </div>

        <?php if ($recentOrders): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>เลขที่</th><th>ลูกค้า</th><th class="num">ยอดรวม</th><th>สถานะ</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td class="nowrap"><strong style="font-size:13px"><?= e($o["order_number"]) ?></strong><br>
                                <small class="muted"><?= e($o["created_at"]) ?></small></td>
                            <td class="nowrap"><?= e($o["customer_name"]) ?></td>
                            <td class="num">฿<?= money($o["total_amount"]) ?></td>
                            <td><span class="status-badge <?= status_class($o["order_status"]) ?>"><?= e($o["order_status"]) ?></span></td>
                            <td class="num"><a class="detail-button" href="order-view.php?id=<?= (int)$o["id"] ?>">รายละเอียด</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">ยังไม่มีคำสั่งซื้อ</div>
        <?php endif; ?>
    </section>

</div>

<?php require __DIR__ . "/_bottom.php"; ?>
