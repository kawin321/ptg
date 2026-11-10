<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";
require_owner();

$validDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

$to   = $validDate($_GET["to"] ?? null)   ? $_GET["to"]   : date("Y-m-d");
$from = $validDate($_GET["from"] ?? null) ? $_GET["from"] : date("Y-m-d", strtotime("-29 days"));

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$params = [$from . " 00:00:00", $to . " 23:59:59"];

/* ส่งออก CSV (เปิดใน Excel ภาษาไทยได้ด้วย BOM) */
if (isset($_GET["export"])) {
    $stmt = $conn->prepare("
        SELECT order_number, created_at, customer_name, phone, delivery_method,
               payment_method, order_status, total_amount
        FROM orders
        WHERE created_at BETWEEN ? AND ?
        ORDER BY created_at ASC
    ");
    $stmt->execute($params);

    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"orders_{$from}_{$to}.csv\"");

    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ["เลขที่คำสั่งซื้อ", "วันที่", "ชื่อลูกค้า", "เบอร์โทร", "วิธีรับสินค้า", "วิธีชำระเงิน", "สถานะ", "ยอดรวม"], ",", '"', "");

    foreach ($stmt as $row) {
        // กัน CSV/Excel formula injection จากข้อมูลที่ลูกค้ากรอก
        foreach ($row as $k => $v) {
            if (is_string($v) && $v !== "" && strpos("=+-@\t\r", $v[0]) !== false && $k !== "total_amount") {
                $row[$k] = "'" . $v;
            }
        }
        fputcsv($out, array_values($row), ",", '"', "");
    }
    fclose($out);
    exit;
}

$stmt = $conn->prepare("
    SELECT COUNT(*) AS orders,
           COALESCE(SUM(total_amount), 0) AS revenue
    FROM orders
    WHERE order_status <> 'ยกเลิก' AND created_at BETWEEN ? AND ?
");
$stmt->execute($params);
$summary = $stmt->fetch();

$stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE order_status = 'ยกเลิก' AND created_at BETWEEN ? AND ?");
$stmt->execute($params);
$cancelled = (int)$stmt->fetchColumn();

$stmt = $conn->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS orders, SUM(total_amount) AS revenue
    FROM orders
    WHERE order_status <> 'ยกเลิก' AND created_at BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY day DESC
");
$stmt->execute($params);
$daily = $stmt->fetchAll();

$stmt = $conn->prepare("
    SELECT oi.product_name, SUM(oi.quantity) AS qty, SUM(oi.subtotal) AS total
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.order_status <> 'ยกเลิก' AND o.created_at BETWEEN ? AND ?
    GROUP BY oi.product_name
    ORDER BY qty DESC, total DESC
    LIMIT 10
");
$stmt->execute($params);
$top = $stmt->fetchAll();

$stmt = $conn->prepare("
    SELECT payment_method, COUNT(*) AS orders, SUM(total_amount) AS revenue
    FROM orders
    WHERE order_status <> 'ยกเลิก' AND created_at BETWEEN ? AND ?
    GROUP BY payment_method
");
$stmt->execute($params);
$byPayment = $stmt->fetchAll();

$orderCount = (int)$summary["orders"];
$revenue    = (float)$summary["revenue"];
$avg        = $orderCount ? $revenue / $orderCount : 0;
$maxDay     = $daily ? max(array_map(fn($d) => (float)$d["revenue"], $daily)) : 0;

$adminTitle  = "รายงานยอดขาย";
$adminActive = "reports";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>รายงานยอดขาย</h1>
        <p>ไม่นับคำสั่งซื้อที่ยกเลิก · <?= e($from) ?> ถึง <?= e($to) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-light" href="reports.php?from=<?= e($from) ?>&to=<?= e($to) ?>&export=1">ส่งออก CSV</a>
    </div>
</div>

<form method="GET" class="card" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:20px">
    <div><label class="label" for="from" style="font-weight:600">ตั้งแต่</label>
        <input type="date" id="from" name="from" value="<?= e($from) ?>" style="width:auto; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px"></div>
    <div><label class="label" for="to" style="font-weight:600">ถึง</label>
        <input type="date" id="to" name="to" value="<?= e($to) ?>" style="width:auto; padding:10px 12px; border:1.5px solid var(--line); border-radius:10px"></div>
    <button type="submit" class="btn btn-primary">ดูรายงาน</button>
    <a href="reports.php?from=<?= date("Y-m-d") ?>&to=<?= date("Y-m-d") ?>" class="btn btn-light">วันนี้</a>
    <a href="reports.php?from=<?= date("Y-m-01") ?>&to=<?= date("Y-m-d") ?>" class="btn btn-light">เดือนนี้</a>
</form>

<section class="stats">
    <div class="stat"><span class="lbl">ยอดขายรวม</span><span class="num">฿<?= money($revenue) ?></span></div>
    <div class="stat"><span class="lbl">คำสั่งซื้อ</span><span class="num"><?= $orderCount ?></span></div>
    <div class="stat"><span class="lbl">เฉลี่ยต่อคำสั่งซื้อ</span><span class="num">฿<?= money($avg) ?></span></div>
    <div class="stat"><span class="lbl">ยกเลิก</span><span class="num"><?= $cancelled ?></span></div>
</section>

<div class="grid-2">
    <section class="card">
        <h2>ยอดขายรายวัน</h2>
        <?php if ($daily): ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>วันที่</th><th class="num">คำสั่งซื้อ</th><th style="width:40%">ยอดขาย</th><th class="num">บาท</th></tr></thead>
                    <tbody>
                    <?php foreach ($daily as $d): ?>
                        <tr>
                            <td><?= e($d["day"]) ?></td>
                            <td class="num"><?= (int)$d["orders"] ?></td>
                            <td><div style="height:8px; border-radius:0 4px 4px 0; background:var(--primary); width:<?= $maxDay > 0 ? max(2, round($d["revenue"] / $maxDay * 100)) : 0 ?>%"></div></td>
                            <td class="num">฿<?= money($d["revenue"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">ไม่มีคำสั่งซื้อในช่วงเวลานี้</div>
        <?php endif; ?>
    </section>

    <div>
        <section class="card">
            <h2>สินค้าขายดี</h2>
            <?php if ($top): ?>
                <table><tbody>
                <?php foreach ($top as $i => $t): ?>
                    <tr>
                        <td><?= $i + 1 ?>.</td>
                        <td><?= e($t["product_name"]) ?></td>
                        <td class="num"><?= (int)$t["qty"] ?> ชิ้น</td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else: ?>
                <div class="empty">ยังไม่มีข้อมูล</div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>แยกตามวิธีชำระเงิน</h2>
            <?php if ($byPayment): ?>
                <table><tbody>
                <?php foreach ($byPayment as $p): ?>
                    <tr>
                        <td><?= e($p["payment_method"]) ?></td>
                        <td class="num"><?= (int)$p["orders"] ?> รายการ</td>
                        <td class="num">฿<?= money($p["revenue"]) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php else: ?>
                <div class="empty">ยังไม่มีข้อมูล</div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
