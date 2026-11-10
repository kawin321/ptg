<?php
require_once __DIR__ . "/includes/functions.php";

$orderNumber = strtoupper(trim($_POST["order"] ?? $_GET["order"] ?? ""));
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "lookup";
    $self   = "track.php?order=" . urlencode($orderNumber);

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
        redirect($orderNumber !== "" ? $self : "track.php");
    }

    if ($action === "lookup") {

        $phone = trim($_POST["phone"] ?? "");

        if (!bot_ok()) {
            $error = BOT_MESSAGE;
        } elseif (login_locked("track", $orderNumber) || throttle_blocked("trackip", "", 40, 900)) {
            $error = "ลองค้นหาผิดหลายครั้งเกินไป กรุณารอสักครู่แล้วลองใหม่";
        } else {
            $found  = find_order($orderNumber);
            $digits = fn($s) => preg_replace('/\D+/', '', (string)$s);

            if ($found && $digits($phone) !== "" && $digits($found["phone"]) === $digits($phone)) {
                $_SESSION["track_ok"][$found["order_number"]] = true;
                redirect("track.php?order=" . urlencode($found["order_number"]));
            }

            login_failed("track", $orderNumber);
            throttle_hit("trackip");
            $error = "ไม่พบคำสั่งซื้อ กรุณาตรวจสอบเลขที่คำสั่งซื้อและเบอร์โทรศัพท์อีกครั้ง";
        }

    } else {

        $target = find_order($orderNumber);

        if (!$target || !can_view_order($target)) {
            flash("error", "ไม่มีสิทธิ์ดำเนินการกับคำสั่งซื้อนี้");
            redirect("track.php");
        }

        if ($action === "cancel") {

            try {
                cancel_order((int)$target["id"], "ลูกค้า", true);
                flash("success", "ยกเลิกคำสั่งซื้อเรียบร้อยแล้ว");
            } catch (RuntimeException $e) {
                flash("error", $e->getMessage());
            } catch (PDOException $e) {
                error_log($e->getMessage());
                flash("error", "ไม่สามารถยกเลิกคำสั่งซื้อได้ กรุณาลองใหม่");
            }

        } elseif ($action === "reupload") {

            $payment = latest_payment((int)$target["id"]);
            $file    = $_FILES["slip"] ?? null;

            if ($target["order_status"] === "ยกเลิก" || !$payment || $payment["payment_status"] !== "ไม่ผ่าน" || $payment["payment_method"] !== PAY_TRANSFER) {
                flash("error", "คำสั่งซื้อนี้ไม่ต้องแนบสลิปใหม่");

            } elseif (!$file || $file["error"] !== UPLOAD_ERR_OK) {
                flash("error", "กรุณาเลือกรูปสลิป");

            } else {
                try {
                    $name = save_uploaded_image($file, __DIR__ . "/assets/images/slips/");

                    // เพิ่มแถวใหม่ เก็บสลิปเดิมไว้เป็นประวัติ
                    $conn->prepare("INSERT INTO payments (order_id, payment_method, payment_amount, slip_image, payment_status)
                                    VALUES (?, ?, ?, ?, 'รอตรวจสอบ')")
                         ->execute([$target["id"], $payment["payment_method"], $payment["payment_amount"], "assets/images/slips/" . $name]);
                    flash("success", "แนบสลิปใหม่แล้ว รอร้านตรวจสอบ");
                } catch (RuntimeException $ex) {
                    flash("error", $ex->getMessage());
                }
            }
        }

        redirect($self);
    }
}

/* แสดงผล */
$order = null;
$items = [];
$payment = null;
$steps = [];

if ($orderNumber !== "" && $error === "") {
    $found = find_order($orderNumber);

    if ($found && can_view_order($found)) {
        $order   = $found;
        $payment = latest_payment((int)$order["id"]);

        // ลูกค้าเจ้าของเปิดดูแล้ว: ล้างการแจ้งเตือนสถานะ
        $viewer = current_customer();
        if ($viewer && (int)($order["customer_id"] ?? 0) === (int)$viewer["id"] && order_is_unread($order)) {
            $conn->prepare("UPDATE orders SET customer_seen_at = NOW() WHERE id = ?")->execute([$order["id"]]);
        }

        $stmt = $conn->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
        $stmt->execute([$order["id"]]);
        $items = $stmt->fetchAll();

        $flow = delivery_ships($order["delivery_method"])
            ? ["สั่งซื้อแล้ว", "กำลังเตรียมสินค้า", "จัดส่งแล้ว", "สำเร็จ"]
            : ["สั่งซื้อแล้ว", "ตรวจสอบ/เตรียมสินค้า", "พร้อมรับที่ร้าน", "สำเร็จ"];

        $current = [
            "รอตรวจสอบการชำระเงิน" => 0,
            "กำลังเตรียมสินค้า"     => 1,
            "รอรับสินค้าที่ร้าน"    => 2,
            "จัดส่งแล้ว"            => 2,
            "สำเร็จ"                => 3,
        ][$order["order_status"]] ?? 0;

        foreach ($flow as $i => $label) {
            $steps[] = ["label" => $label, "done" => $i <= $current, "current" => $i === $current];
        }
    }
}

$canCancel = $order && order_customer_cancellable($order["order_status"], $payment["payment_status"] ?? null);
$needSlip  = $order && $payment && $payment["payment_status"] === "ไม่ผ่าน" && $payment["payment_method"] === PAY_TRANSFER && $order["order_status"] !== "ยกเลิก";

$pageTitle  = "ติดตามคำสั่งซื้อ";
$activePage = "track";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">

    <div class="page-head" style="padding-top:0">
        <h1>ติดตามคำสั่งซื้อ</h1>
        <p>กรอกเลขที่คำสั่งซื้อและเบอร์โทรศัพท์เพื่อตรวจสอบสถานะ</p>
    </div>

    <?php if (!$order): ?>
        <form method="POST" class="panel track-form" style="max-width:860px; margin:0 auto 24px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="lookup">
            <?= bot_fields() ?>
            <div>
                <label class="label" for="order">เลขที่คำสั่งซื้อ</label>
                <input class="form-control" type="text" id="order" name="order" placeholder="เช่น ORD20260101120000ABC123" value="<?= e($orderNumber) ?>" required>
            </div>
            <div>
                <label class="label" for="phone">เบอร์โทรศัพท์</label>
                <input class="form-control" type="tel" id="phone" name="phone" placeholder="เบอร์ที่ใช้สั่งซื้อ" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg">ค้นหา</button>
        </form>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-error" style="max-width:860px; margin:0 auto 20px" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($order): ?>
        <div style="max-width:860px; margin:0 auto">

            <section class="panel">
                <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center">
                    <div>
                        <div class="muted" style="font-size:14px">เลขที่คำสั่งซื้อ</div>
                        <h2 style="margin:0; font-size:22px"><?= e($order["order_number"]) ?></h2>
                    </div>
                    <span class="status-badge <?= status_class($order["order_status"]) ?>" style="font-size:15px"><?= e($order["order_status"]) ?></span>
                </div>

                <?php if ($order["order_status"] === "ยกเลิก"): ?>
                    <div class="alert alert-error" style="margin:18px 0 0">คำสั่งซื้อนี้ถูกยกเลิกแล้ว</div>
                <?php else: ?>
                    <ol class="timeline" aria-label="ความคืบหน้า">
                        <?php foreach ($steps as $i => $s): ?>
                            <li class="<?= $s["done"] ? "done" : "" ?> <?= $s["current"] ? "current" : "" ?>">
                                <i><?= $s["done"] ? ui_icon("check") : $i + 1 ?></i><?= e($s["label"]) ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>

            <?php if (!empty($order["tracking_number"]) && $order["order_status"] !== "ยกเลิก"): ?>
                <?php $trackLink = tracking_url($order["carrier"], $order["tracking_number"]); ?>
                <section class="panel">
                    <h2>ข้อมูลพัสดุ</h2>
                    <div class="parcel-box">
                        <div class="muted"><?= e(carrier_label($order["carrier"])) ?><?= !empty($order["shipped_at"]) ? " · ส่งเมื่อ " . e($order["shipped_at"]) : "" ?></div>
                        <div class="parcel-number">
                            <span>เลขพัสดุ</span>
                            <strong id="parcel-no"><?= e($order["tracking_number"]) ?></strong>
                        </div>
                        <div class="parcel-actions">
                            <button type="button" class="btn btn-light" data-copy="<?= e($order["tracking_number"]) ?>"><?= ui_icon("copy") ?> <span>คัดลอกเลขพัสดุ</span></button>
                            <?php if ($trackLink): ?>
                                <a class="btn btn-primary" href="<?= e($trackLink) ?>" target="_blank" rel="noopener noreferrer"><?= ui_icon("truck") ?> ติดตามกับ <?= e(carrier_label($order["carrier"])) ?> ↗</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($needSlip): ?>
                <section class="panel">
                    <h2>แนบสลิปใหม่</h2>
                    <div class="alert alert-error">สลิปไม่ผ่านการตรวจสอบ กรุณาแนบหลักฐานการโอนเงินอีกครั้ง</div>
                    <?= promptpay_block((float)$order["total_amount"]) ?>
                    <form method="POST" enctype="multipart/form-data" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reupload">
                        <input type="hidden" name="order" value="<?= e($order["order_number"]) ?>">
                        <div class="form-group">
                            <label class="label" for="slip">รูปสลิป (JPG, PNG, WEBP ไม่เกิน 2 MB)</label>
                            <input class="form-control" type="file" id="slip" name="slip" accept="image/jpeg,image/png,image/webp" required>
                        </div>
                        <button type="submit" class="btn btn-primary">ส่งสลิปใหม่</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="panel">
                <h2>ข้อมูลการสั่งซื้อ</h2>
                <div class="info-list" style="max-width:none">
                    <div><span class="k">ชื่อผู้สั่งซื้อ</span><strong><?= e($order["customer_name"]) ?></strong></div>
                    <div><span class="k">วิธีรับสินค้า</span><strong><?= e($order["delivery_method"]) ?></strong></div>
                    <?php if (delivery_ships($order["delivery_method"])): ?>
                        <div><span class="k"><?= $order["delivery_method"] === DELIVERY_PARCEL ? "ที่อยู่จัดส่ง" : "แผนก/ที่อยู่" ?></span><strong><?= e($order["address"]) ?></strong></div>
                    <?php endif; ?>
                    <div><span class="k">วิธีชำระเงิน</span><strong><?= e($order["payment_method"]) ?></strong></div>
                    <?php if ($payment): ?>
                        <div><span class="k">สถานะการชำระเงิน</span><strong><?= e($payment["payment_status"]) ?></strong></div>
                    <?php endif; ?>
                    <div><span class="k">วันที่สั่งซื้อ</span><strong><?= e($order["created_at"]) ?></strong></div>
                </div>
            </section>

            <section class="panel">
                <h2>รายการสินค้า</h2>
                <div style="overflow-x:auto">
                    <table class="table-simple">
                        <thead>
                            <tr><th>สินค้า</th><th class="num">ราคา</th><th class="num">จำนวน</th><th class="num">รวม</th></tr>
                        </thead>
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
                    <div class="summary-row" style="margin-top:10px"><span>ส่วนลด (<?= e($order["coupon_code"]) ?>)</span><span>−฿<?= money($order["discount_amount"]) ?></span></div>
                <?php endif; ?>
                <?php if ((float)$order["shipping_fee"] > 0): ?>
                    <div class="summary-row"><span>ค่าจัดส่ง</span><span>฿<?= money($order["shipping_fee"]) ?></span></div>
                <?php endif; ?>
                <div class="summary-row total"><span>ยอดรวม</span><span>฿<?= money($order["total_amount"]) ?></span></div>
            </section>

            <div class="track-actions">
                <a href="receipt.php?order=<?= urlencode($order["order_number"]) ?>" class="btn btn-light" target="_blank" rel="noopener">
                    <?= ui_icon("receipt") ?> <?= ($payment["payment_status"] ?? "") === "ตรวจสอบแล้ว" && $order["order_status"] !== "ยกเลิก" ? "ใบเสร็จรับเงิน" : "ใบสั่งซื้อ" ?>
                </a>
            </div>

            <?php if ($canCancel): ?>
                <form method="POST" style="text-align:center; margin-top:16px"
                      data-confirm="ต้องการยกเลิกคำสั่งซื้อนี้หรือไม่? ยกเลิกแล้วเปลี่ยนกลับไม่ได้">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="order" value="<?= e($order["order_number"]) ?>">
                    <button type="submit" class="btn btn-ghost">ยกเลิกคำสั่งซื้อนี้</button>
                </form>
            <?php endif; ?>

        </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
