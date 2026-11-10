<?php
require_once __DIR__ . "/includes/functions.php";

$orderNumber = strtoupper(trim($_GET["order"] ?? ""));
$order = $orderNumber !== "" ? find_order($orderNumber) : null;

// สิทธิ์เดียวกับหน้าติดตาม: เจ้าของ, คนที่เพิ่งสั่ง หรือคนที่ยืนยันเบอร์โทรแล้ว
if (!$order || !can_view_order($order)) {
    flash("error", "กรุณายืนยันเลขที่คำสั่งซื้อและเบอร์โทรก่อนเปิดใบเสร็จ");
    redirect("track.php" . ($orderNumber !== "" ? "?order=" . urlencode($orderNumber) : ""));
}

$stmt = $conn->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
$stmt->execute([$order["id"]]);
$items = $stmt->fetchAll();

$payment   = latest_payment((int)$order["id"]);
$cancelled = $order["order_status"] === "ยกเลิก";
$paid      = !$cancelled && $payment && $payment["payment_status"] === "ตรวจสอบแล้ว";
$docTitle  = $paid ? "ใบเสร็จรับเงิน" : "ใบสั่งซื้อ";
$subtotal  = array_sum(array_map(fn($i) => (float)$i["subtotal"], $items));

$s = settings_all();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($docTitle) ?> <?= e($order["order_number"]) ?> | <?= e(SHOP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;500;600&family=Prompt:wght@500;600&display=swap" rel="stylesheet">
    <style nonce="<?= csp_nonce() ?>">
        :root { --text: #27344a; --muted: #535b7d; --line: rgba(39, 52, 74, .14); --pink: #ead0cf; --primary: #626b91; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Noto Sans Thai", Tahoma, sans-serif; color: var(--text); background: #f8f3ec; font-variant-numeric: tabular-nums; line-height: 1.6; }
        .toolbar { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; padding: 16px; }
        .toolbar a, .toolbar button { min-height: 44px; display: inline-flex; align-items: center; padding: 8px 20px; border-radius: 10px; border: 1px solid #d6ccc0; background: #fff; color: var(--text); font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .toolbar button { background: var(--primary); border-color: var(--primary); color: #fff; }
        .sheet { width: 160mm; max-width: calc(100% - 32px); margin: 0 auto 32px; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 32px; position: relative; }
        .head { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; border-bottom: 1px solid var(--line); padding-bottom: 16px; margin-bottom: 16px; }
        .brand { display: flex; gap: 12px; align-items: center; }
        .mark { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 10px; background: var(--pink); font-family: Prompt, sans-serif; font-weight: 600; font-size: 22px; transform: rotate(-6deg); }
        .brand strong { font-family: Prompt, sans-serif; font-weight: 600; font-size: 18px; display: block; }
        .brand small, .contact { color: var(--muted); font-size: 13px; }
        h1 { font-family: Prompt, sans-serif; font-weight: 500; font-size: 22px; margin: 0; text-align: right; }
        h1 + div { text-align: right; font-size: 14px; color: var(--muted); }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; font-size: 14px; margin-bottom: 16px; }
        .meta span { color: var(--muted); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 8px 4px; border-bottom: 1px solid var(--line); text-align: left; }
        th { color: var(--muted); font-weight: 500; font-size: 13px; }
        .r { text-align: right; white-space: nowrap; }
        .sum { margin-left: auto; width: min(100%, 280px); font-size: 14px; margin-top: 8px; }
        .sum div { display: flex; justify-content: space-between; padding: 4px 0; }
        .sum .total { border-top: 1px solid var(--text); margin-top: 4px; padding-top: 8px; font-family: Prompt, sans-serif; font-size: 18px; font-weight: 500; }
        .status { margin-top: 20px; padding: 12px 16px; border-radius: 10px; font-size: 14px; }
        .status.paid { background: #e2f1e0; color: #2c6b34; }
        .status.unpaid { background: #fff0c2; color: #7d5b00; }
        .status.void { background: #fbe3e5; color: #a12b36; }
        .stamp { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-12deg); border: 3px solid #a12b36; color: #a12b36; padding: 8px 24px; border-radius: 10px; font-family: Prompt, sans-serif; font-size: 32px; opacity: .35; pointer-events: none; }
        .foot { margin-top: 20px; text-align: center; color: var(--muted); font-size: 12px; }
        @media (max-width: 560px) { .sheet { padding: 20px 16px; } .meta { grid-template-columns: 1fr; } h1, h1 + div { text-align: left; } }
        @media print { body { background: #fff; } .toolbar { display: none; } .sheet { border: 0; margin: 0; width: auto; max-width: none; padding: 0; } }
    </style>
</head>
<body>

<div class="toolbar">
    <button type="button" data-print>พิมพ์ / บันทึกเป็น PDF</button>
    <a href="track.php?order=<?= urlencode($order["order_number"]) ?>">กลับไปหน้าติดตาม</a>
</div>

<main class="sheet">
    <?php if ($cancelled): ?><div class="stamp" aria-hidden="true">ยกเลิก</div><?php endif; ?>

    <div class="head">
        <div class="brand">
            <span class="mark">3</span>
            <div>
                <strong><?= e(SHOP_NAME) ?></strong>
                <div class="contact">
                    <?= e($s["shop_address"]) ?>
                    <?php if ($s["shop_phone"] !== ""): ?><?= $s["shop_address"] !== "" ? " · " : "" ?>โทร <?= e($s["shop_phone"]) ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div>
            <h1><?= e($docTitle) ?></h1>
            <div>เลขที่ <?= e($order["order_number"]) ?></div>
        </div>
    </div>

    <div class="meta">
        <div><span>ลูกค้า</span> <?= e($order["customer_name"]) ?></div>
        <div><span>วันที่สั่งซื้อ</span> <?= e($order["created_at"]) ?></div>
        <div><span>โทร</span> <?= e($order["phone"]) ?></div>
        <div><span>วิธีรับสินค้า</span> <?= e($order["delivery_method"]) ?></div>
        <?php if (delivery_ships($order["delivery_method"])): ?>
            <div><span>จัดส่งที่</span> <?= e($order["address"]) ?></div>
        <?php endif; ?>
        <?php if (!empty($order["tracking_number"])): ?>
            <div><span>เลขพัสดุ</span> <?= e(carrier_label($order["carrier"])) ?> <?= e($order["tracking_number"]) ?></div>
        <?php endif; ?>
        <div><span>วิธีชำระเงิน</span> <?= e($order["payment_method"]) ?></div>
    </div>

    <table>
        <thead>
            <tr><th>#</th><th>รายการ</th><th class="r">ราคา</th><th class="r">จำนวน</th><th class="r">รวม</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $item): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e($item["product_name"]) ?></td>
                <td class="r">฿<?= money($item["price"]) ?></td>
                <td class="r"><?= (int)$item["quantity"] ?></td>
                <td class="r">฿<?= money($item["subtotal"]) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="sum">
        <div><span>รวมค่าสินค้า</span><span>฿<?= money($subtotal) ?></span></div>
        <?php if ((float)$order["discount_amount"] > 0): ?>
            <div><span>ส่วนลด (<?= e($order["coupon_code"]) ?>)</span><span>−฿<?= money($order["discount_amount"]) ?></span></div>
        <?php endif; ?>
        <?php if ((float)$order["shipping_fee"] > 0): ?>
            <div><span>ค่าจัดส่ง</span><span>฿<?= money($order["shipping_fee"]) ?></span></div>
        <?php endif; ?>
        <div class="total"><span>ยอดสุทธิ</span><span>฿<?= money($order["total_amount"]) ?></span></div>
    </div>

    <?php if ($cancelled): ?>
        <div class="status void">คำสั่งซื้อนี้ถูกยกเลิกแล้ว ใช้เป็นหลักฐานการชำระเงินไม่ได้</div>
    <?php elseif ($paid): ?>
        <div class="status paid">ได้รับชำระเงินแล้ว<?= !empty($payment["paid_at"]) ? " เมื่อ " . e($payment["paid_at"]) : "" ?></div>
    <?php else: ?>
        <div class="status unpaid">ยังไม่ได้รับชำระเงิน · สถานะ: <?= e($payment["payment_status"] ?? $order["order_status"]) ?></div>
    <?php endif; ?>

    <div class="foot">เอกสารนี้ออกจากระบบของ <?= e(SHOP_NAME) ?> · พิมพ์เมื่อ <?= date("Y-m-d H:i") ?></div>
</main>

<script nonce="<?= csp_nonce() ?>">
document.addEventListener("click", function (event) {
    if (event.target.closest("[data-print]")) { window.print(); }
});
</script>
</body>
</html>
