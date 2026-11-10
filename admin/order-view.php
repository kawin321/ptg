<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

$orderId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$orderId) {
    flash("error", "ไม่พบหมายเลขคำสั่งซื้อ");
    redirect("orders.php");
}

$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    flash("error", "ไม่พบข้อมูลคำสั่งซื้อนี้");
    redirect("orders.php");
}

$stmt = $conn->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

$stmt = $conn->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$orderId]);
$payment = $stmt->fetch();

/* บันทึกข้อมูลจัดส่ง (บริษัทขนส่ง + เลขพัสดุ) */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "shipping") {

    $carrier  = $_POST["carrier"] ?? "";
    $rawTrack = trim($_POST["tracking_number"] ?? "");
    $tracking = $rawTrack === "" ? "" : normalize_tracking($rawTrack);
    $markSent = isset($_POST["mark_shipped"]);

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
    } elseif ($order["order_status"] === "ยกเลิก") {
        flash("error", "คำสั่งซื้อนี้ถูกยกเลิกแล้ว");
    } elseif ($carrier !== "" && !isset(CARRIERS[$carrier])) {
        flash("error", "กรุณาเลือกบริษัทขนส่ง");
    } elseif ($tracking === null) {
        flash("error", "เลขพัสดุต้องเป็นตัวอักษรภาษาอังกฤษ ตัวเลข หรือขีด 6-60 ตัว");
    } elseif ($tracking !== "" && $carrier === "") {
        flash("error", "กรุณาเลือกบริษัทขนส่งของเลขพัสดุนี้");
    } else {
        try {
            $conn->prepare("UPDATE orders SET carrier = ?, tracking_number = ?, status_changed_at = NOW() WHERE id = ?")
                 ->execute([$carrier !== "" ? $carrier : null, $tracking !== "" ? $tracking : null, $orderId]);

            admin_log("บันทึกเลขพัสดุ", $order["order_number"] . ($tracking !== "" ? ": " . carrier_label($carrier) . " " . $tracking : ": ลบข้อมูลพัสดุ"));

            $message = $tracking !== "" ? "บันทึกเลขพัสดุ {$tracking} แล้ว ลูกค้าจะเห็นในหน้าติดตามคำสั่งซื้อ" : "ล้างข้อมูลพัสดุแล้ว";

            if ($markSent && $tracking !== "" && in_array($order["order_status"], ["กำลังเตรียมสินค้า", "รอตรวจสอบการชำระเงิน"], true)) {
                order_change_status($orderId, "จัดส่งแล้ว");
                $message .= " และเปลี่ยนสถานะเป็น “จัดส่งแล้ว”";
            }

            flash("success", $message);
        } catch (RuntimeException $e) {
            flash("error", $e->getMessage());
        } catch (PDOException $e) {
            error_log($e->getMessage());
            flash("error", "ไม่สามารถบันทึกข้อมูลจัดส่งได้");
        }
    }

    redirect("order-view.php?id=" . $orderId);
}

/* บันทึกผลตรวจสอบการชำระเงิน */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $status  = $_POST["payment_status"] ?? "";
    $allowed = ["ตรวจสอบแล้ว", "ไม่ผ่าน"];

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
    } elseif (!in_array($status, $allowed, true)) {
        flash("error", "สถานะไม่ถูกต้อง");
    } elseif (!$payment) {
        flash("error", "ไม่พบข้อมูลการชำระเงิน");
    } elseif ($order["order_status"] === "ยกเลิก") {
        flash("error", "คำสั่งซื้อนี้ถูกยกเลิกแล้ว ไม่สามารถตรวจสอบการชำระเงินได้");
    } elseif ($order["order_status"] === "สำเร็จ") {
        flash("error", "คำสั่งซื้อนี้สำเร็จแล้ว ไม่ต้องเปลี่ยนผลตรวจสอบการชำระเงิน");
    } elseif ($payment["payment_status"] === $status) {
        flash("success", "สถานะการชำระเงินเป็น “{$status}” อยู่แล้ว");
    } else {
        try {
            $conn->beginTransaction();

            $paidAt = $status === "ตรวจสอบแล้ว" ? date("Y-m-d H:i:s") : null;

            $conn->prepare("UPDATE payments SET payment_status = ?, paid_at = ? WHERE id = ? AND order_id = ?")
                 ->execute([$status, $paidAt, $payment["id"], $orderId]);

            // อัปเดตสถานะคำสั่งซื้อเฉพาะที่ยังอยู่ในขั้นตรวจสอบ ไม่ย้อนสถานะที่คืบหน้าไปแล้ว
            if ($status === "ตรวจสอบแล้ว") {
                $conn->prepare("UPDATE orders SET order_status = 'กำลังเตรียมสินค้า'
                                WHERE id = ? AND order_status = 'รอตรวจสอบการชำระเงิน'")->execute([$orderId]);
            } else {
                $conn->prepare("UPDATE orders SET order_status = 'รอตรวจสอบการชำระเงิน'
                                WHERE id = ? AND order_status = 'กำลังเตรียมสินค้า'")->execute([$orderId]);
            }

            // ให้ลูกค้าเห็นว่ามีอัปเดต (รวมกรณีสลิปไม่ผ่าน)
            $conn->prepare("UPDATE orders SET status_changed_at = NOW() WHERE id = ?")->execute([$orderId]);

            $conn->commit();
            admin_log($status === "ตรวจสอบแล้ว" ? "ยืนยันการชำระเงิน" : "สลิปไม่ผ่าน", $order["order_number"]);
            flash("success", $status === "ตรวจสอบแล้ว"
                ? "ยืนยันการชำระเงินเรียบร้อยแล้ว คำสั่งซื้อเข้าสู่ขั้นตอนเตรียมสินค้า"
                : "บันทึกผล “ไม่ผ่านการตรวจสอบ” แล้ว");

        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log($e->getMessage());
            flash("error", "เกิดข้อผิดพลาดในการบันทึกข้อมูล");
        }
    }

    redirect("order-view.php?id=" . $orderId);
}

$adminTitle  = "รายละเอียดคำสั่งซื้อ";
$adminActive = "orders";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>คำสั่งซื้อ <?= e($order["order_number"]) ?></h1>
        <p>สั่งซื้อเมื่อ <?= e($order["created_at"]) ?></p>
    </div>
    <div class="actions">
        <span class="status-badge <?= status_class($order["order_status"]) ?>" style="font-size:14px; padding:8px 16px"><?= e($order["order_status"]) ?></span>
        <a href="order-print.php?id=<?= (int)$order["id"] ?>" target="_blank" rel="noopener" class="btn btn-light">พิมพ์ใบสั่งซื้อ</a>
        <a href="orders.php" class="btn btn-light">← กลับรายการคำสั่งซื้อ</a>
    </div>
</div>

<div class="detail-grid">

    <div>
        <section class="card">
            <h2>รายการสินค้า</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>สินค้า</th><th class="num">ราคา</th><th class="num">จำนวน</th><th class="num">รวม</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= e($item["product_name"]) ?></td>
                            <td class="num">฿<?= money($item["price"]) ?></td>
                            <td class="num"><?= (int)$item["quantity"] ?></td>
                            <td class="num">฿<?= money($item["subtotal"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ((float)$order["discount_amount"] > 0): ?>
                <div style="display:flex; justify-content:space-between; padding-top:12px"><span>ส่วนลด (<?= e($order["coupon_code"]) ?>)</span><span>−฿<?= money($order["discount_amount"]) ?></span></div>
            <?php endif; ?>
            <?php if ((float)$order["shipping_fee"] > 0): ?>
                <div style="display:flex; justify-content:space-between; padding-top:8px"><span>ค่าจัดส่ง</span><span>฿<?= money($order["shipping_fee"]) ?></span></div>
            <?php endif; ?>
            <div class="total-line"><span>ยอดรวม</span><span>฿<?= money($order["total_amount"]) ?></span></div>
        </section>

        <section class="card">
            <h2>ข้อมูลลูกค้า</h2>
            <dl class="kv">
                <dt>ชื่อลูกค้า</dt><dd><?= e($order["customer_name"]) ?></dd>
                <dt>เบอร์โทรศัพท์</dt><dd><a href="tel:<?= e($order["phone"]) ?>"><?= e($order["phone"]) ?></a></dd>
                <dt>วิธีรับสินค้า</dt><dd><?= e($order["delivery_method"] ?? "-") ?></dd>
                <dt>ที่อยู่/แผนก</dt><dd><?= nl2br(e($order["address"])) ?></dd>
            </dl>
        </section>

        <?php if (delivery_ships($order["delivery_method"]) || !empty($order["tracking_number"])): ?>
            <section class="card">
                <h2>การจัดส่ง</h2>

                <?php if (!empty($order["tracking_number"])): ?>
                    <?php $trackLink = tracking_url($order["carrier"], $order["tracking_number"]); ?>
                    <dl class="kv" style="margin-bottom:14px">
                        <dt>บริษัทขนส่ง</dt><dd><?= e(carrier_label($order["carrier"])) ?></dd>
                        <dt>เลขพัสดุ</dt>
                        <dd>
                            <strong class="mono"><?= e($order["tracking_number"]) ?></strong>
                            <?php if ($trackLink): ?> · <a href="<?= e($trackLink) ?>" target="_blank" rel="noopener noreferrer">ตรวจสอบกับขนส่ง ↗</a><?php endif; ?>
                        </dd>
                        <?php if (!empty($order["shipped_at"])): ?>
                            <dt>จัดส่งเมื่อ</dt><dd><?= e($order["shipped_at"]) ?></dd>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>

                <?php if ($order["order_status"] !== "ยกเลิก"): ?>
                    <form method="POST" class="shipping-form" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="shipping">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="carrier">บริษัทขนส่ง</label>
                                <select id="carrier" name="carrier">
                                    <option value="">— เลือก —</option>
                                    <?php foreach (CARRIERS as $key => [$label]): ?>
                                        <option value="<?= e($key) ?>" <?= ($order["carrier"] ?? "") === $key ? "selected" : "" ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="tracking_number">เลขพัสดุ</label>
                                <input type="text" id="tracking_number" name="tracking_number" maxlength="60" autocomplete="off" spellcheck="false"
                                       value="<?= e($order["tracking_number"] ?? "") ?>" placeholder="เช่น EF123456789TH">
                            </div>
                        </div>
                        <?php if (in_array($order["order_status"], ["กำลังเตรียมสินค้า", "รอตรวจสอบการชำระเงิน"], true)): ?>
                            <label class="check-line"><input type="checkbox" name="mark_shipped" value="1" checked> เปลี่ยนสถานะเป็น “จัดส่งแล้ว” พร้อมกัน</label>
                        <?php endif; ?>
                        <p class="hint">เว้นเลขพัสดุว่างแล้วบันทึก เพื่อลบข้อมูลพัสดุที่กรอกผิด</p>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><?= ui_icon("truck", "16") ?> บันทึกข้อมูลจัดส่ง</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <section class="card">
        <h2>การชำระเงิน</h2>

        <?php if ($payment): ?>
            <dl class="kv" style="grid-template-columns:110px 1fr">
                <dt>วิธีชำระเงิน</dt><dd><?= e($payment["payment_method"]) ?></dd>
                <dt>สถานะ</dt><dd><?= e($payment["payment_status"]) ?></dd>
                <?php if (!empty($payment["paid_at"])): ?>
                    <dt>ชำระเมื่อ</dt><dd><?= e($payment["paid_at"]) ?></dd>
                <?php endif; ?>
            </dl>

            <?php if (!empty($payment["slip_image"])): ?>
                <p style="margin:16px 0 0"><strong>หลักฐานการโอนเงิน</strong></p>
                <a href="slip.php?id=<?= (int)$payment["id"] ?>" target="_blank" rel="noopener">
                    <img class="slip" src="slip.php?id=<?= (int)$payment["id"] ?>" alt="หลักฐานการโอนเงิน">
                </a>
            <?php else: ?>
                <p class="muted" style="margin-top:14px">ไม่มีหลักฐานการโอนเงิน (ชำระด้วยเงินสด/ปลายทาง)</p>
            <?php endif; ?>

            <?php if (!in_array($order["order_status"], ["ยกเลิก", "สำเร็จ"], true)): ?>
                <div class="form-actions">
                    <?php if ($payment["payment_status"] !== "ตรวจสอบแล้ว"): ?>
                        <form method="POST" data-once>
                            <?= csrf_field() ?>
                            <input type="hidden" name="payment_status" value="ตรวจสอบแล้ว">
                            <button type="submit" class="btn approve">ยืนยันการชำระเงิน</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($payment["payment_status"] !== "ไม่ผ่าน"): ?>
                        <form method="POST" data-once>
                            <?= csrf_field() ?>
                            <input type="hidden" name="payment_status" value="ไม่ผ่าน">
                            <button type="submit" class="btn reject"><?= $payment["payment_status"] === "ตรวจสอบแล้ว" ? "เปลี่ยนเป็นไม่ผ่าน" : "ไม่ผ่านการตรวจสอบ" ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php elseif ($order["order_status"] === "สำเร็จ"): ?>
                <p class="muted" style="margin-top:14px">คำสั่งซื้อสำเร็จแล้ว ไม่ต้องตรวจสอบการชำระเงินเพิ่ม</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">ยังไม่มีข้อมูลการชำระเงิน</p>
        <?php endif; ?>
    </section>

</div>

<?php require __DIR__ . "/_bottom.php"; ?>
