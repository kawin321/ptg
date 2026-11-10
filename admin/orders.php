<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

const BULK_MAX = 200;   // จำนวนคำสั่งซื้อสูงสุดต่อการทำรายการแบบกลุ่มหนึ่งครั้ง

/* ---------- ตัวกรองรายการ (ใช้ทั้งหน้ารายการและการส่งออก) ---------- */
$filter = $_GET["status"] ?? "";
if ($filter !== "" && !in_array($filter, ORDER_STATUSES, true)) {
    $filter = "";
}

$q = trim($_GET["q"] ?? "");

$validDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false ? $d : "";
$from = $validDate($_GET["from"] ?? "");
$to   = $validDate($_GET["to"] ?? "");
if ($from !== "" && $to !== "" && $from > $to) {
    [$from, $to] = [$to, $from];
}

$where  = "WHERE 1=1";
$params = [];

if ($filter !== "") {
    $where   .= " AND o.order_status = ?";
    $params[] = $filter;
}
if ($q !== "") {
    $where   .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ? OR o.tracking_number LIKE ?)";
    $like     = like_pattern($q);
    array_push($params, $like, $like, $like, $like);
}
if ($from !== "") {
    $where   .= " AND o.created_at >= ?";
    $params[] = $from . " 00:00:00";
}
if ($to !== "") {
    $where   .= " AND o.created_at <= ?";
    $params[] = $to . " 23:59:59";
}

$filterQuery = array_filter(["status" => $filter, "q" => $q, "from" => $from, "to" => $to], fn($v) => $v !== "");
$back        = "orders.php" . ($filterQuery ? "?" . http_build_query($filterQuery) : "");

/** รหัสคำสั่งซื้อที่เลือกจากช่องติ๊ก */
function selected_order_ids(): array
{
    $ids = array_values(array_unique(array_filter(array_map("intval", (array)($_POST["ids"] ?? [])), fn($id) => $id > 0)));

    return array_slice($ids, 0, BULK_MAX);
}

/* =====================================================
   ส่งออก CSV: ตามตัวกรอง (GET) หรือเฉพาะที่เลือก (POST)
   ===================================================== */
$exportSelected = $_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "export";

if (isset($_GET["export"]) || $exportSelected) {

    if ($exportSelected) {
        $ids = csrf_valid($_POST["csrf_token"] ?? "") ? selected_order_ids() : [];
        if (!$ids) {
            flash("error", "กรุณาเลือกคำสั่งซื้อที่ต้องการส่งออก");
            redirect($back);
        }
        $exportWhere  = "WHERE o.id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")";
        $exportParams = $ids;
    } else {
        $exportWhere  = $where;
        $exportParams = $params;
    }

    $stmt = $conn->prepare("
        SELECT o.*, p.payment_status,
               (SELECT COALESCE(SUM(oi.subtotal), 0) FROM order_items oi WHERE oi.order_id = o.id) AS goods_total,
               (SELECT GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity) ORDER BY oi.id SEPARATOR ' | ')
                  FROM order_items oi WHERE oi.order_id = o.id) AS item_list
        FROM orders o
        LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
        $exportWhere
        ORDER BY o.created_at ASC, o.id ASC
    ");
    $stmt->execute($exportParams);

    admin_log("ส่งออกคำสั่งซื้อ CSV", $exportSelected ? count($exportParams) . " รายการที่เลือก" : ($filterQuery ? http_build_query($filterQuery) : "ทั้งหมด"));

    $out = csv_begin("orders_" . date("Ymd_His") . ".csv");
    fputcsv($out, [
        "เลขที่คำสั่งซื้อ", "วันที่สั่งซื้อ", "ชื่อลูกค้า", "เบอร์โทร", "วิธีรับสินค้า", "ที่อยู่จัดส่ง",
        "รายการสินค้า", "ค่าสินค้า", "ส่วนลด", "คูปอง", "ค่าจัดส่ง", "ยอดสุทธิ",
        "วิธีชำระเงิน", "สถานะการชำระเงิน", "สถานะคำสั่งซื้อ", "บริษัทขนส่ง", "เลขพัสดุ", "วันที่จัดส่ง",
    ], ",", '"', "");

    foreach ($stmt as $row) {
        fputcsv($out, array_map("csv_cell", [
            $row["order_number"],
            $row["created_at"],
            $row["customer_name"],
            $row["phone"],
            $row["delivery_method"],
            delivery_ships($row["delivery_method"]) ? $row["address"] : "",
            (string)$row["item_list"],
            number_format((float)$row["goods_total"], 2, ".", ""),
            number_format((float)$row["discount_amount"], 2, ".", ""),
            (string)$row["coupon_code"],
            number_format((float)$row["shipping_fee"], 2, ".", ""),
            number_format((float)$row["total_amount"], 2, ".", ""),
            $row["payment_method"],
            (string)$row["payment_status"],
            $row["order_status"],
            carrier_label($row["carrier"]),
            (string)$row["tracking_number"],
            (string)$row["shipped_at"],
        ]), ",", '"', "");
    }

    fclose($out);
    exit;
}

/* =====================================================
   เปลี่ยนสถานะ: ทีละรายการ หรือแบบกลุ่ม
   ===================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
        redirect($back);
    }

    $action    = $_POST["action"] ?? "status";
    $newStatus = $_POST["order_status"] ?? "";

    if (!in_array($newStatus, ORDER_STATUSES, true)) {
        flash("error", "กรุณาเลือกสถานะ");
        redirect($back);
    }

    if ($action === "bulk_status") {
        $ids = selected_order_ids();

        if (!$ids) {
            flash("error", "กรุณาเลือกคำสั่งซื้ออย่างน้อย 1 รายการ");
            redirect($back);
        }

        $changed = 0;
        $same    = 0;
        $errors  = [];

        foreach ($ids as $id) {
            try {
                order_change_status($id, $newStatus) === null ? $same++ : $changed++;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = "คำสั่งซื้อรหัส {$id}: บันทึกไม่สำเร็จ";
            }
        }

        if ($changed > 0) {
            flash("success", "เปลี่ยนสถานะเป็น “{$newStatus}” แล้ว {$changed} รายการ" . ($same ? " (อีก {$same} รายการเป็นสถานะนี้อยู่แล้ว)" : ""));
        } elseif ($same > 0 && !$errors) {
            flash("success", "คำสั่งซื้อที่เลือกเป็นสถานะ “{$newStatus}” อยู่แล้ว");
        }

        if ($errors) {
            flash("error", "ทำรายการไม่สำเร็จ " . count($errors) . " รายการ: " . implode(" · ", array_slice($errors, 0, 5)) . (count($errors) > 5 ? " …" : ""));
        }

        redirect($back);
    }

    $orderId = filter_input(INPUT_POST, "order_id", FILTER_VALIDATE_INT);

    if (!$orderId) {
        flash("error", "ข้อมูลไม่ถูกต้อง");
        redirect($back);
    }

    try {
        $number = order_change_status($orderId, $newStatus);

        if ($number !== null) {
            flash("success", $newStatus === "ยกเลิก"
                ? "ยกเลิกคำสั่งซื้อ {$number} แล้ว (คืนสต็อกสินค้าแล้ว)"
                : "อัปเดตสถานะคำสั่งซื้อ {$number} เป็น “{$newStatus}” แล้ว");
        }
    } catch (RuntimeException $e) {
        flash("error", $e->getMessage());
    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash("error", "ไม่สามารถแก้ไขสถานะคำสั่งซื้อได้");
    }

    redirect($back);
}

/* =====================================================
   โหลดรายการ
   ===================================================== */
$counts = array_fill_keys(ORDER_STATUSES, 0);
foreach ($conn->query("SELECT order_status, COUNT(*) c FROM orders GROUP BY order_status") as $row) {
    if (isset($counts[$row["order_status"]])) {
        $counts[$row["order_status"]] = (int)$row["c"];
    }
}

$stmt = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(o.total_amount), 0) FROM orders o $where");
$stmt->execute($params);
[$total, $totalAmount] = $stmt->fetch(PDO::FETCH_NUM);
$total = (int)$total;

$perPage = 20;
[$page, $pages, $offset] = paginate($total, page_param(), $perPage);

$stmt = $conn->prepare("
    SELECT o.*, p.payment_status, p.slip_image, p.id AS payment_id
    FROM orders o
    LEFT JOIN payments p
        ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.order_id = o.id)
    $where
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

$pageUrl   = fn(int $p) => "orders.php?" . http_build_query($filterQuery + ["page" => $p]);
$exportUrl = "orders.php?" . http_build_query($filterQuery + ["export" => 1]);
$hasFilter = $q !== "" || $from !== "" || $to !== "";

$adminTitle  = "คำสั่งซื้อ";
$adminActive = "orders";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>จัดการคำสั่งซื้อ</h1>
        <p>ตรวจสอบการชำระเงิน อัปเดตสถานะ และจัดการหลายรายการพร้อมกัน</p>
    </div>
    <div class="actions">
        <a href="<?= e($exportUrl) ?>" class="btn btn-light"><?= ui_icon("download", "16") ?> ส่งออก CSV<?= $filterQuery ? " (ตามตัวกรอง)" : "" ?></a>
    </div>
</div>

<div class="tabs" role="navigation" aria-label="กรองตามสถานะ">
    <?php $allQuery = array_diff_key($filterQuery, ["status" => 1]); ?>
    <a href="orders.php<?= $allQuery ? "?" . e(http_build_query($allQuery)) : "" ?>" class="<?= $filter === "" ? "active" : "" ?>">ทั้งหมด <small><?= array_sum($counts) ?></small></a>
    <?php foreach (ORDER_STATUSES as $s): ?>
        <a href="orders.php?<?= e(http_build_query(["status" => $s] + $allQuery)) ?>" class="<?= $filter === $s ? "active" : "" ?>">
            <?= e($s) ?> <small><?= $counts[$s] ?></small>
        </a>
    <?php endforeach; ?>
</div>

<div class="table-box">

    <form method="GET" class="toolbar order-filter">
        <?php if ($filter !== ""): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" class="grow" placeholder="ค้นหาเลขที่คำสั่งซื้อ ชื่อ เบอร์โทร หรือเลขพัสดุ" aria-label="ค้นหาคำสั่งซื้อ">
        <label class="date-field"><span>ตั้งแต่</span><input type="date" name="from" value="<?= e($from) ?>"></label>
        <label class="date-field"><span>ถึง</span><input type="date" name="to" value="<?= e($to) ?>"></label>
        <button type="submit" class="btn btn-primary">ค้นหา</button>
        <?php if ($hasFilter): ?>
            <a href="orders.php<?= $filter !== "" ? "?status=" . urlencode($filter) : "" ?>" class="btn btn-light">ล้างตัวกรอง</a>
        <?php endif; ?>
    </form>

    <?php if ($orders): ?>
        <p class="result-summary muted">
            พบ <strong><?= number_format($total) ?></strong> รายการ · ยอดรวม <strong>฿<?= money($totalAmount) ?></strong>
        </p>

        <form method="POST" id="bulk-form" class="bulk-bar" data-bulk>
            <?= csrf_field() ?>
            <span class="bulk-count" data-bulk-count>ยังไม่ได้เลือกรายการ</span>
            <select name="order_status" aria-label="สถานะใหม่สำหรับรายการที่เลือก">
                <option value="">— เปลี่ยนสถานะเป็น —</option>
                <?php foreach (ORDER_STATUSES as $s): ?>
                    <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="action" value="bulk_status" class="btn btn-primary btn-sm" data-bulk-need
                    data-confirm="ยืนยันการยกเลิกคำสั่งซื้อที่เลือกทั้งหมด? สต็อกจะถูกคืนและเปลี่ยนกลับไม่ได้" data-confirm-when="order_status=ยกเลิก">
                ใช้กับที่เลือก
            </button>
            <span class="bulk-sep" aria-hidden="true"></span>
            <button type="submit" name="action" value="print" formaction="order-print.php" formtarget="_blank" class="btn btn-light btn-sm" data-bulk-need>
                <?= ui_icon("receipt", "15") ?> พิมพ์ใบสั่งซื้อ
            </button>
            <button type="submit" name="action" value="export" class="btn btn-light btn-sm" data-bulk-need>
                <?= ui_icon("download", "15") ?> CSV ที่เลือก
            </button>
        </form>

        <div class="table-wrap">
            <table style="min-width:900px">
                <thead>
                    <tr>
                        <th class="check-col"><input type="checkbox" data-check-all aria-label="เลือกทุกรายการในหน้านี้"></th>
                        <th>คำสั่งซื้อ</th>
                        <th>ลูกค้า</th>
                        <th class="num">ยอดรวม</th>
                        <th>การชำระเงิน</th>
                        <th>สถานะ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td class="check-col">
                            <input type="checkbox" name="ids[]" value="<?= (int)$order["id"] ?>" form="bulk-form" data-check
                                   aria-label="เลือก <?= e($order["order_number"]) ?>">
                        </td>
                        <td class="nowrap">
                            <strong style="font-size:13px"><?= e($order["order_number"]) ?></strong><br>
                            <small class="muted"><?= e($order["created_at"]) ?> · <?= e($order["delivery_method"]) ?></small>
                        </td>
                        <td class="nowrap">
                            <?= e($order["customer_name"]) ?><br>
                            <small class="muted"><?= e($order["phone"]) ?></small>
                        </td>
                        <td class="num">
                            <strong>฿<?= money($order["total_amount"]) ?></strong>
                            <?php if ((float)$order["discount_amount"] > 0): ?><br><small class="muted">ลด ฿<?= money($order["discount_amount"]) ?></small><?php endif; ?>
                            <?php if ((float)$order["shipping_fee"] > 0): ?><br><small class="muted">ค่าส่ง ฿<?= money($order["shipping_fee"]) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?= e($order["payment_method"]) ?><br>
                            <small class="muted"><?= e($order["payment_status"] ?? "ยังไม่มีข้อมูล") ?></small>
                            <?php if (!empty($order["slip_image"])): ?>
                                · <a href="slip.php?id=<?= (int)$order["payment_id"] ?>" target="_blank" rel="noopener" style="color:var(--rose-dark)">สลิป</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($order["order_status"] === "ยกเลิก"): ?>
                                <span class="status-badge status-cancelled">ยกเลิก</span>
                            <?php else: ?>
                                <form method="POST" class="status-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="order_id" value="<?= (int)$order["id"] ?>">
                                    <select name="order_status" class="status-select <?= status_class($order["order_status"]) ?>" aria-label="สถานะคำสั่งซื้อ">
                                        <?php foreach (ORDER_STATUSES as $s): ?>
                                            <option value="<?= e($s) ?>" <?= $order["order_status"] === $s ? "selected" : "" ?>><?= e($s) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="edit-btn"
                                            data-confirm="ยืนยันการยกเลิกคำสั่งซื้อ? สต็อกสินค้าจะถูกคืนและเปลี่ยนสถานะกลับไม่ได้" data-confirm-when="order_status=ยกเลิก">
                                        บันทึก
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if (!empty($order["tracking_number"])): ?>
                                <small class="muted tracking-line"><?= ui_icon("truck", "13") ?> <?= e(carrier_label($order["carrier"])) ?> <?= e($order["tracking_number"]) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><a class="detail-button" href="order-view.php?id=<?= (int)$order["id"] ?>">รายละเอียด</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_html($page, $pages, $pageUrl) ?>
    <?php else: ?>
        <div class="empty">
            <h3>ไม่มีคำสั่งซื้อ<?= $filter !== "" ? "ในสถานะ “" . e($filter) . "”" : "" ?><?= $hasFilter ? "ที่ตรงกับตัวกรอง" : "" ?></h3>
            <p>เมื่อมีลูกค้าสั่งซื้อสินค้า รายการจะแสดงที่หน้านี้</p>
        </div>
    <?php endif; ?>

</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var form = document.querySelector("[data-bulk]");
    if (!form) return;

    var all = document.querySelector("[data-check-all]");
    var boxes = Array.prototype.slice.call(document.querySelectorAll("[data-check]"));
    var label = form.querySelector("[data-bulk-count]");
    var status = form.querySelector("select[name=order_status]");
    var need = form.querySelectorAll("[data-bulk-need]");

    function sync() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        label.textContent = n ? "เลือกแล้ว " + n + " รายการ" : "ยังไม่ได้เลือกรายการ";
        form.classList.toggle("has-selection", n > 0);
        need.forEach(function (btn) {
            btn.disabled = n === 0 || (btn.value === "bulk_status" && status.value === "");
        });
        all.checked = n > 0 && n === boxes.length;
        all.indeterminate = n > 0 && n < boxes.length;
    }

    all.addEventListener("change", function () {
        boxes.forEach(function (b) { b.checked = all.checked; });
        sync();
    });
    boxes.forEach(function (b) { b.addEventListener("change", sync); });
    status.addEventListener("change", sync);
    window.addEventListener("pageshow", sync);
    sync();
})();
</script>

<?php require __DIR__ . "/_bottom.php"; ?>
