<?php
/**
 * ใบสั่งซื้อ / ใบปะหน้าสำหรับแพ็กของ
 *  - GET  ?id=12           พิมพ์ทีละรายการ (จากหน้ารายละเอียด)
 *  - POST ids[]=12&ids[]=13 พิมพ์หลายรายการพร้อมกัน (จากหน้ารายการคำสั่งซื้อ) ขึ้นหน้าใหม่ทุกใบ
 */
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        http_response_code(400);
        die("คำขอไม่ถูกต้อง กรุณากลับไปเลือกรายการใหม่");
    }
    $ids = array_slice(array_values(array_unique(array_filter(array_map("intval", (array)($_POST["ids"] ?? [])), fn($id) => $id > 0))), 0, 200);
} else {
    $id  = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
    $ids = $id ? [$id] : [];
}

$orders = [];

if ($ids) {
    $in   = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id IN ($in) ORDER BY created_at ASC, id ASC");
    $stmt->execute($ids);
    $orders = $stmt->fetchAll();
}

if (!$orders) {
    http_response_code(404);
    die("ไม่พบคำสั่งซื้อ");
}

$orderIds = array_column($orders, "id");
$in       = implode(",", array_fill(0, count($orderIds), "?"));

$itemsBy = [];
$stmt = $conn->prepare("SELECT * FROM order_items WHERE order_id IN ($in) ORDER BY id ASC");
$stmt->execute($orderIds);
foreach ($stmt as $item) {
    $itemsBy[$item["order_id"]][] = $item;
}

$paymentBy = [];
$stmt = $conn->prepare("SELECT p.* FROM payments p
                        WHERE p.id IN (SELECT MAX(id) FROM payments WHERE order_id IN ($in) GROUP BY order_id)");
$stmt->execute($orderIds);
foreach ($stmt as $p) {
    $paymentBy[$p["order_id"]] = $p;
}

$single = count($orders) === 1;
if (!$single) {
    admin_log("พิมพ์ใบสั่งซื้อแบบกลุ่ม", count($orders) . " รายการ");
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $single ? "ใบสั่งซื้อ " . e($orders[0]["order_number"]) : "ใบสั่งซื้อ " . count($orders) . " รายการ" ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;600;700&display=swap" rel="stylesheet">
    <style nonce="<?= csp_nonce() ?>">
        body { font-family: "Noto Sans Thai", Tahoma, sans-serif; color: #222; margin: 0; background: #eee; }
        .sheet { width: 148mm; max-width: calc(100% - 32px); margin: 20px auto; background: #fff; padding: 12mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); box-sizing: border-box; }
        h1 { font-size: 20px; margin: 0; }
        .sub { color: #555; font-size: 13px; margin: 2px 0 14px; }
        .meta { font-size: 14px; line-height: 1.7; margin-bottom: 12px; }
        .ship-to { border: 2px solid #222; border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: 15px; line-height: 1.6; }
        .ship-to small { display: block; font-size: 12px; color: #555; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #ccc; text-align: left; }
        th.r, td.r { text-align: right; }
        .line { display: flex; justify-content: space-between; font-size: 14px; margin-top: 6px; }
        .total { text-align: right; font-size: 18px; font-weight: 700; margin-top: 12px; }
        .foot { margin-top: 18px; font-size: 12px; color: #555; text-align: center; }
        .toolbar { text-align: center; margin: 16px; }
        .toolbar button, .toolbar a { padding: 9px 20px; border: 0; border-radius: 999px; background: #2d3a47; color: #fff; font: inherit; cursor: pointer; text-decoration: none; }
        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0 auto; width: auto; max-width: none; break-after: page; page-break-after: always; }
            .sheet:last-of-type { break-after: auto; page-break-after: auto; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button type="button" data-print>พิมพ์<?= $single ? "" : " " . count($orders) . " ใบ" ?></button>
    <?php if ($single): ?>
        <a href="order-view.php?id=<?= (int)$orders[0]["id"] ?>">← กลับ</a>
    <?php else: ?>
        <a href="orders.php">← กลับรายการคำสั่งซื้อ</a>
    <?php endif; ?>
</div>

<?php foreach ($orders as $order): ?>
    <?php
    $items   = $itemsBy[$order["id"]] ?? [];
    $payment = $paymentBy[$order["id"]] ?? null;
    $goods   = array_sum(array_map(fn($i) => (float)$i["subtotal"], $items));
    ?>
    <div class="sheet">
        <h1><?= e(SHOP_NAME) ?></h1>
        <div class="sub">
            ใบสั่งซื้อ / ใบสำคัญรับสินค้า
            <?php if (setting("shop_phone") !== ""): ?> · โทร <?= e(setting("shop_phone")) ?><?php endif; ?>
        </div>

        <?php if (delivery_ships($order["delivery_method"])): ?>
            <div class="ship-to">
                <small>ผู้รับ</small>
                <strong><?= e($order["customer_name"]) ?></strong> · โทร <?= e($order["phone"]) ?><br>
                <?= nl2br(e($order["address"])) ?>
                <?php if (!empty($order["tracking_number"])): ?>
                    <small style="margin-top:4px">เลขพัสดุ <?= e(carrier_label($order["carrier"])) ?> <?= e($order["tracking_number"]) ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="meta">
            <strong>เลขที่:</strong> <?= e($order["order_number"]) ?><br>
            <strong>วันที่:</strong> <?= e($order["created_at"]) ?><br>
            <strong>ลูกค้า:</strong> <?= e($order["customer_name"]) ?> (<?= e($order["phone"]) ?>)<br>
            <strong>รับสินค้า:</strong> <?= e($order["delivery_method"] ?? "-") ?><br>
            <strong>ชำระเงิน:</strong> <?= e($order["payment_method"]) ?>
            <?php if ($payment): ?> (<?= e($payment["payment_status"]) ?>)<?php endif; ?><br>
            <strong>สถานะ:</strong> <?= e($order["order_status"]) ?>
        </div>

        <table>
            <thead><tr><th>สินค้า</th><th class="r">ราคา</th><th class="r">จำนวน</th><th class="r">รวม</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item["product_name"]) ?></td>
                    <td class="r"><?= money($item["price"]) ?></td>
                    <td class="r"><?= (int)$item["quantity"] ?></td>
                    <td class="r"><?= money($item["subtotal"]) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="line"><span>ค่าสินค้า</span><span>฿<?= money($goods) ?></span></div>
        <?php if ((float)$order["discount_amount"] > 0): ?>
            <div class="line"><span>ส่วนลด (<?= e($order["coupon_code"]) ?>)</span><span>−฿<?= money($order["discount_amount"]) ?></span></div>
        <?php endif; ?>
        <?php if ((float)$order["shipping_fee"] > 0): ?>
            <div class="line"><span>ค่าจัดส่ง</span><span>฿<?= money($order["shipping_fee"]) ?></span></div>
        <?php endif; ?>
        <div class="total">รวมทั้งสิ้น ฿<?= money($order["total_amount"]) ?></div>

        <div class="foot">ขอบคุณที่ใช้บริการ <?= e(SHOP_NAME) ?></div>
    </div>
<?php endforeach; ?>

<script nonce="<?= csp_nonce() ?>">
document.addEventListener("click", function (event) {
    if (event.target.closest("[data-print]")) { window.print(); }
});
</script>
</body>
</html>
