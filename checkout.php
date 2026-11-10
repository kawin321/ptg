<?php
require_once __DIR__ . "/includes/functions.php";

$success = isset($_GET["success"]);

/* =====================================================
   หน้าแจ้งผลสำเร็จ
   ===================================================== */
if ($success) {
    if (empty($_SESSION["last_order_number"])) {
        redirect("index.php");
    }

    $orderNumber     = $_SESSION["last_order_number"];
    $successStatus   = $_SESSION["last_order_status"] ?? "";
    $successPayment  = $_SESSION["last_payment_method"] ?? "";
    $successDelivery = $_SESSION["last_delivery_method"] ?? "";
    $successTotal    = $_SESSION["last_order_total"] ?? null;
}

/* =====================================================
   ตรวจโครงสร้างฐานข้อมูล / ตะกร้า
   ===================================================== */
if (!$success) {
    $hasDeliveryColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_method'")->fetch();

    if (!$hasDeliveryColumn) {
        die("ไม่พบคอลัมน์ delivery_method ในตาราง orders กรุณาเพิ่มคอลัมน์ก่อนใช้งาน (ดูไฟล์ database/schema.sql)");
    }

    if (empty($_SESSION["cart"])) {
        flash("error", "ตะกร้าสินค้าว่างเปล่า กรุณาเลือกสินค้าก่อน");
        redirect("products.php");
    }
}

$error        = "";
$cartProducts = [];
$totalAmount  = 0.0;

/* โหลดสินค้าในตะกร้า */
if (!$success) {
    $ids = array_map("intval", array_keys($_SESSION["cart"]));
    $placeholders = implode(",", array_fill(0, count($ids), "?"));

    $stmt = $conn->prepare("
        SELECT products.*, categories.category_name
        FROM products
        LEFT JOIN categories ON products.category_id = categories.id
        WHERE products.id IN ($placeholders)
    ");
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $product) {
        $id       = (int)$product["id"];
        $quantity = (int)$_SESSION["cart"][$id];

        $product["quantity"] = $quantity;
        $product["subtotal"] = effective_price($product) * $quantity;

        $cartProducts[]  = $product;
        $totalAmount    += $product["subtotal"];
    }
}

$customer = current_customer();

/* คูปองในตะกร้า */
$coupon        = !$success ? cart_coupon($totalAmount) : null;
$discount      = $coupon["discount"] ?? 0.0;
$payableAmount = $totalAmount - $discount;

/* วิธีรับสินค้าที่เปิดให้เลือก => [ชนิดสำหรับหน้าเว็บ, วิธีชำระเงินที่ใช้ได้] */
$deliveryOptions = [
    DELIVERY_PICKUP => ["pickup", [PAY_TRANSFER, PAY_CASH]],
    DELIVERY_DEPT   => ["dept",   [PAY_COD]],
];
if (parcel_enabled()) {
    $deliveryOptions[DELIVERY_PARCEL] = ["parcel", [PAY_TRANSFER]];
}

/* ค่าส่งและยอดชำระของแต่ละวิธี (ใช้แสดงผลก่อนกดยืนยัน) */
$shipQuote = [];
foreach (array_keys($deliveryOptions) as $method) {
    $fee = shipping_fee_for($method, $payableAmount);
    $shipQuote[$method] = ["fee" => $fee, "total" => $payableAmount + $fee];
}

/* =====================================================
   ประมวลผลการสั่งซื้อ
   ===================================================== */
if (!$success && $_SERVER["REQUEST_METHOD"] === "POST") {

    $customerName   = trim($_POST["customer_name"] ?? "");
    $phone          = trim($_POST["phone"] ?? "");
    $address        = trim($_POST["address"] ?? "");
    $deliveryMethod = $_POST["delivery_method"] ?? "";
    $paymentMethod  = $_POST["payment_method"] ?? "";

    $slipPath     = null;
    $uploadedFile = null;

    // วิธีชำระเงินที่ใช้ได้ในแต่ละวิธีรับสินค้า
    $allowedPayments = array_map(fn($o) => $o[1], $deliveryOptions);

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        $error = "คำขอไม่ถูกต้อง กรุณาลองใหม่";

    } elseif (!bot_ok()) {
        $error = BOT_MESSAGE;

    } elseif (throttle_blocked("checkout", "", 30, 1800)) {
        $error = "สั่งซื้อบ่อยเกินไปจากเครือข่ายนี้ กรุณารอสักครู่แล้วลองใหม่";

    } elseif (empty($_SESSION["cart"])) {
        $error = "ไม่มีสินค้าในตะกร้า";

    } elseif ($customerName === "" || $phone === "") {
        $error = "กรุณากรอกชื่อและเบอร์โทรศัพท์";

    } elseif (mb_strlen($customerName) > 150) {
        $error = "ชื่อยาวเกินไป";

    } elseif (!preg_match('/^[0-9+\-\s]{9,20}$/', $phone)) {
        $error = "กรุณากรอกเบอร์โทรศัพท์ให้ถูกต้อง (ตัวเลข 9-20 หลัก)";

    } elseif (!isset($allowedPayments[$deliveryMethod])) {
        $error = "กรุณาเลือกวิธีรับสินค้า";

    } elseif (!in_array($paymentMethod, $allowedPayments[$deliveryMethod], true)) {
        $error = "กรุณาเลือกวิธีชำระเงินให้ตรงกับวิธีรับสินค้า";

    } elseif ($deliveryMethod === DELIVERY_DEPT && $address === "") {
        $error = "กรุณากรอกที่อยู่/แผนกสำหรับจัดส่งสินค้า";

    } elseif ($deliveryMethod === DELIVERY_PARCEL && (mb_strlen($address) < 15 || !preg_match('/(?<!\d)\d{5}(?!\d)/', $address))) {
        $error = "กรุณากรอกที่อยู่จัดส่งพัสดุให้ครบ รวมถึงรหัสไปรษณีย์ 5 หลัก";

    } elseif (mb_strlen($address) > 500) {
        $error = "ที่อยู่ยาวเกินไป (ไม่เกิน 500 ตัวอักษร)";

    } else {

        if ($deliveryMethod === DELIVERY_PICKUP) {
            $address = "รับสินค้าที่ร้าน";
        }

        // ตรวจสลิปเฉพาะกรณีโอนเงิน (วาดรูปใหม่ก่อนเก็บ)
        if ($paymentMethod === PAY_TRANSFER) {

            if (!isset($_FILES["slip"]) || $_FILES["slip"]["error"] === UPLOAD_ERR_NO_FILE) {
                $error = "กรุณาแนบรูปสลิปการโอนเงิน";

            } else {
                try {
                    $fileName     = save_uploaded_image($_FILES["slip"], __DIR__ . "/assets/images/slips/");
                    $slipPath     = "assets/images/slips/" . $fileName;
                    $uploadedFile = __DIR__ . "/assets/images/slips/" . $fileName;
                } catch (RuntimeException $ex) {
                    $error = $ex->getMessage();
                }
            }
        }
    }

    /* ---------- บันทึกคำสั่งซื้อ ---------- */
    if ($error === "") {

        try {
            $conn->beginTransaction();

            $cart = $_SESSION["cart"];
            $ids  = array_map("intval", array_keys($cart));
            $placeholders = implode(",", array_fill(0, count($ids), "?"));

            // ล็อกแถวสินค้าเพื่อตรวจสต็อก
            $stmt = $conn->prepare("SELECT * FROM products WHERE id IN ($placeholders) FOR UPDATE");
            $stmt->execute($ids);
            $lockedProducts = $stmt->fetchAll();

            if (count($lockedProducts) !== count($cart)) {
                throw new Exception("มีสินค้าบางรายการไม่อยู่ในระบบแล้ว กรุณากลับไปตรวจสอบตะกร้า");
            }

            $orderItems = [];
            $orderTotal = 0.0;

            foreach ($lockedProducts as $product) {
                $id       = (int)$product["id"];
                $quantity = (int)($cart[$id] ?? 0);

                if ($quantity < 1) {
                    throw new Exception("จำนวนสินค้าไม่ถูกต้อง");
                }

                if ($quantity > (int)$product["stock"]) {
                    throw new Exception("สินค้า “{$product["product_name"]}” มีไม่เพียงพอ กรุณากลับไปแก้ไขตะกร้า");
                }

                $unitPrice   = effective_price($product);   // ราคาโปรโมชันถ้ามีผลวันนี้
                $subtotal    = $unitPrice * $quantity;
                $orderTotal += $subtotal;

                $orderItems[] = [
                    "id"       => $id,
                    "name"     => $product["product_name"],
                    "price"    => $unitPrice,
                    "quantity" => $quantity,
                    "subtotal" => $subtotal,
                ];
            }

            // ตรวจและนับสิทธิ์คูปองภายในรายการเดียวกัน กันใช้เกินจำนวน
            $couponCode     = null;
            $orderDiscount  = 0.0;

            if (!empty($_SESSION["coupon_code"])) {
                $lockedCoupon = coupon_find($_SESSION["coupon_code"], true);
                $check        = coupon_evaluate($lockedCoupon, $orderTotal);

                if ($check["error"] !== null) {
                    unset($_SESSION["coupon_code"]);
                    throw new Exception("คูปองใช้ไม่ได้แล้ว: " . $check["error"] . " กรุณาตรวจสอบยอดอีกครั้ง");
                }

                $couponCode    = $lockedCoupon["code"];
                $orderDiscount = $check["discount"];

                $conn->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")->execute([$lockedCoupon["id"]]);
            }

            $orderSubtotal = $orderTotal;
            $shippingFee   = shipping_fee_for($deliveryMethod, $orderSubtotal - $orderDiscount);
            $orderTotal    = $orderSubtotal - $orderDiscount + $shippingFee;

            $orderNumber = "ORD" . date("YmdHis") . strtoupper(bin2hex(random_bytes(3)));

            // สถานะตามวิธีชำระเงิน
            if ($paymentMethod === PAY_TRANSFER) {
                $orderStatus   = "รอตรวจสอบการชำระเงิน";
                $paymentStatus = "รอตรวจสอบ";
            } elseif ($paymentMethod === PAY_CASH) {
                $orderStatus   = "รอรับสินค้าที่ร้าน";
                $paymentStatus = "รอชำระเงิน";
            } else {
                $orderStatus   = "กำลังเตรียมสินค้า";
                $paymentStatus = "รอชำระเงิน";
            }

            $stmt = $conn->prepare("
                INSERT INTO orders (
                    order_number, customer_name, phone, address,
                    delivery_method, total_amount, payment_method, order_status,
                    customer_id, coupon_code, discount_amount, shipping_fee
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderNumber, $customerName, $phone, $address,
                $deliveryMethod, $orderTotal, $paymentMethod, $orderStatus,
                $customer["id"] ?? null, $couponCode, $orderDiscount, $shippingFee,
            ]);

            $orderId = (int)$conn->lastInsertId();

            $itemStmt = $conn->prepare("
                INSERT INTO order_items (order_id, product_id, product_name, price, quantity, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stockStmt = $conn->prepare("
                UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?
            ");

            foreach ($orderItems as $item) {
                $itemStmt->execute([
                    $orderId, $item["id"], $item["name"],
                    $item["price"], $item["quantity"], $item["subtotal"],
                ]);

                $stockStmt->execute([$item["quantity"], $item["id"], $item["quantity"]]);

                if ($stockStmt->rowCount() !== 1) {
                    throw new Exception("ไม่สามารถตัดสต็อกสินค้าได้ กรุณาลองใหม่");
                }

                log_stock($item["id"], -$item["quantity"], "ขาย", $orderNumber, $customer ? "ลูกค้า: " . $customer["email"] : "ลูกค้าทั่วไป");
            }

            $stmt = $conn->prepare("
                INSERT INTO payments (order_id, payment_method, payment_amount, slip_image, payment_status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$orderId, $paymentMethod, $orderTotal, $slipPath, $paymentStatus]);

            $conn->commit();
            throttle_hit("checkout");

            $_SESSION["cart"] = [];
            unset($_SESSION["coupon_code"]);
            $_SESSION["last_order_number"]    = $orderNumber;
            $_SESSION["last_order_status"]    = $orderStatus;
            $_SESSION["last_payment_method"]  = $paymentMethod;
            $_SESSION["last_delivery_method"] = $deliveryMethod;
            $_SESSION["last_order_total"]     = $orderTotal;

            redirect("checkout.php?success=1");

        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            // ลบสลิปที่อัปโหลดไว้ หากบันทึกคำสั่งซื้อไม่สำเร็จ
            if ($uploadedFile && is_file($uploadedFile)) {
                unlink($uploadedFile);
            }

            error_log($e->getMessage());

            $error = $e instanceof PDOException
                ? "ไม่สามารถบันทึกคำสั่งซื้อได้ กรุณาลองใหม่อีกครั้ง"
                : $e->getMessage();
        }
    }
}

/* ค่าที่เลือกไว้ในฟอร์ม */
$selDelivery = $_POST["delivery_method"] ?? DELIVERY_PICKUP;
if (!$success && !isset($deliveryOptions[$selDelivery])) {
    $selDelivery = DELIVERY_PICKUP;
}
$selQuote = $shipQuote[$selDelivery] ?? ["fee" => 0.0, "total" => $payableAmount];

/** ข้อความค่าส่งบนการ์ดวิธีรับสินค้า */
$feeNote = function (string $method) use ($shipQuote): string {
    $fee = $shipQuote[$method]["fee"] ?? 0.0;
    if ($fee > 0) {
        return "ค่าส่ง ฿" . money($fee);
    }
    return $method === DELIVERY_PICKUP ? "" : "ส่งฟรี";
};
$freeMin = setting_money("ship_free_min");
$selPayment  = $_POST["payment_method"] ?? PAY_TRANSFER;

$pageTitle  = $success ? "สั่งซื้อสำเร็จ" : "ยืนยันการสั่งซื้อ";
$activePage = "cart";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">

<?php if ($success): ?>

    <div class="steps" aria-label="ขั้นตอนการสั่งซื้อ">
        <div class="step done"><i><?= ui_icon("check") ?></i> ตะกร้าสินค้า</div>
        <span class="step-line"></span>
        <div class="step done"><i><?= ui_icon("check") ?></i> ข้อมูลและชำระเงิน</div>
        <span class="step-line"></span>
        <div class="step current"><i>3</i> เสร็จสิ้น</div>
    </div>

    <div class="panel success-card">
        <div class="success-check" aria-hidden="true"><?= ui_icon("check") ?></div>
        <h1 style="font-size:28px">สั่งซื้อสินค้าสำเร็จแล้ว!</h1>
        <p class="muted">ขอบคุณที่เลือกซื้อสินค้ากับ <?= e(SHOP_NAME) ?></p>

        <div class="order-number"><?= e($orderNumber) ?></div>
        <p class="muted" style="margin-top:-8px; font-size:14px">โปรดจดเลขที่คำสั่งซื้อนี้ไว้ เพื่อใช้ติดตามสถานะ</p>

        <div class="info-list">
            <div><span class="k">วิธีรับสินค้า</span><strong><?= e($successDelivery) ?></strong></div>
            <div><span class="k">วิธีชำระเงิน</span><strong><?= e($successPayment) ?></strong></div>
            <?php if ($successTotal !== null): ?>
                <div><span class="k">ยอดรวม</span><strong>฿<?= money($successTotal) ?></strong></div>
            <?php endif; ?>
            <div><span class="k">สถานะ</span><span class="status-badge <?= status_class($successStatus) ?>"><?= e($successStatus) ?></span></div>
        </div>

        <div class="alert alert-info">
            <?php if ($successPayment === PAY_TRANSFER): ?>
                ร้านค้าจะตรวจสอบหลักฐานการโอนเงินของคุณและเตรียมสินค้าให้
            <?php elseif ($successPayment === PAY_CASH): ?>
                กรุณาชำระเงินสดเมื่อมารับสินค้าที่ร้าน
            <?php else: ?>
                กรุณาชำระเงินเมื่อได้รับสินค้า
            <?php endif; ?>
        </div>

        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap">
            <a href="track.php?order=<?= urlencode($orderNumber) ?>" class="btn btn-primary">ติดตามคำสั่งซื้อ</a>
            <a href="index.php" class="btn btn-light">กลับไปหน้าร้าน</a>
        </div>
    </div>

<?php else: ?>

    <div class="steps" aria-label="ขั้นตอนการสั่งซื้อ">
        <div class="step done"><i><?= ui_icon("check") ?></i> ตะกร้าสินค้า</div>
        <span class="step-line"></span>
        <div class="step current"><i>2</i> ข้อมูลและชำระเงิน</div>
        <span class="step-line"></span>
        <div class="step"><i>3</i> เสร็จสิ้น</div>
    </div>

    <h1 class="page-title">ยืนยันการสั่งซื้อ</h1>

    <?php if ($error !== ""): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="checkout-form" data-once class="cart-layout">

        <?= csrf_field() ?>
        <?= bot_fields() ?>

        <div>
            <section class="panel">
                <h2>1. ข้อมูลผู้สั่งซื้อ</h2>

                <div class="form-group">
                    <label class="label" for="customer_name">ชื่อ-นามสกุล <span class="required">*</span></label>
                    <input class="form-control" type="text" name="customer_name" id="customer_name"
                           placeholder="กรอกชื่อ-นามสกุล" value="<?= e($_POST["customer_name"] ?? $customer["fullname"] ?? "") ?>"
                           maxlength="150" autocomplete="name" required>
                </div>

                <div class="form-group">
                    <label class="label" for="phone">เบอร์โทรศัพท์ <span class="required">*</span></label>
                    <input class="form-control" type="tel" name="phone" id="phone"
                           placeholder="เช่น 0812345678" value="<?= e($_POST["phone"] ?? $customer["phone"] ?? "") ?>"
                           maxlength="20" autocomplete="tel" inputmode="tel" required>
                </div>
            </section>

            <section class="panel">
                <h2>2. วิธีรับสินค้า</h2>

                <?php
                $deliveryCards = [
                    DELIVERY_PICKUP => ["store", "มารับสินค้าที่ร้าน", "ชำระด้วยการโอนเงินหรือเงินสด"],
                    DELIVERY_DEPT   => ["truck", "จัดส่งสินค้าที่แผนก", "ชำระเงินปลายทางเมื่อได้รับสินค้า"],
                    DELIVERY_PARCEL => ["box",   "จัดส่งพัสดุ", "ส่งผ่านบริษัทขนส่ง ชำระด้วยการโอนเงิน มีเลขพัสดุให้ติดตาม"],
                ];
                ?>
                <div class="option-cards <?= count($deliveryOptions) === 3 ? "three" : "" ?>">
                    <?php foreach ($deliveryOptions as $method => [$kind]): ?>
                        <?php [$icon, $title, $desc] = $deliveryCards[$method]; ?>
                        <label class="option-card">
                            <input type="radio" name="delivery_method" value="<?= e($method) ?>" data-kind="<?= e($kind) ?>"
                                   data-fee="<?= money($shipQuote[$method]["fee"]) ?>" data-total="<?= money($shipQuote[$method]["total"]) ?>"
                                   <?= $selDelivery === $method ? "checked" : "" ?>>
                            <span class="box">
                                <span class="ico"><?= ui_icon($icon) ?></span>
                                <strong><?= e($title) ?></strong>
                                <small><?= e($desc) ?></small>
                                <?php if (($note = $feeNote($method)) !== ""): ?>
                                    <small class="fee-note"><?= e($note) ?></small>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php if ($freeMin > 0 && max(array_column($shipQuote, "fee")) > 0): ?>
                    <p class="hint" style="margin-top:10px">ยอดสินค้าครบ ฿<?= money($freeMin) ?> ส่งฟรี<?= $payableAmount < $freeMin ? " · ซื้อเพิ่มอีก ฿" . money($freeMin - $payableAmount) : "" ?></p>
                <?php endif; ?>

                <div class="form-group" id="address-group" style="margin-top:18px" hidden>
                    <label class="label" for="address"><span data-address-label>แผนก / ที่อยู่สำหรับจัดส่ง</span> <span class="required">*</span></label>
                    <textarea class="form-control" name="address" id="address" maxlength="500"
                              data-placeholder-dept="เช่น แผนกเทคโนโลยีสารสนเทศ ห้อง 203"
                              data-placeholder-parcel="บ้านเลขที่ ถนน ตำบล/แขวง อำเภอ/เขต จังหวัด รหัสไปรษณีย์"
                              placeholder="เช่น แผนกเทคโนโลยีสารสนเทศ ห้อง 203"><?= e($_POST["address"] ?? $customer["address"] ?? "") ?></textarea>
                </div>
            </section>

            <section class="panel">
                <h2>3. วิธีชำระเงิน</h2>

                <div class="option-cards three">
                    <label class="option-card" data-for="pickup parcel">
                        <input type="radio" name="payment_method" value="<?= e(PAY_TRANSFER) ?>"
                               <?= $selPayment === PAY_TRANSFER ? "checked" : "" ?>>
                        <span class="box">
                            <span class="ico"><?= ui_icon("bank") ?></span>
                            <strong>โอนเงินผ่านธนาคาร</strong>
                            <small>แนบสลิปให้ร้านตรวจสอบ</small>
                        </span>
                    </label>

                    <label class="option-card" data-for="pickup">
                        <input type="radio" name="payment_method" value="<?= e(PAY_CASH) ?>"
                               <?= $selPayment === PAY_CASH ? "checked" : "" ?>>
                        <span class="box">
                            <span class="ico"><?= ui_icon("cash") ?></span>
                            <strong>เงินสดที่ร้าน</strong>
                            <small>จ่ายเมื่อมารับสินค้า</small>
                        </span>
                    </label>

                    <label class="option-card" data-for="dept">
                        <input type="radio" name="payment_method" value="<?= e(PAY_COD) ?>"
                               <?= $selPayment === PAY_COD ? "checked" : "" ?>>
                        <span class="box">
                            <span class="ico"><?= ui_icon("hand") ?></span>
                            <strong>เก็บเงินปลายทาง</strong>
                            <small>จ่ายเมื่อได้รับสินค้า</small>
                        </span>
                    </label>
                </div>

                <div class="bank-box" id="bank-info" style="margin-top:18px" hidden>
                    <strong>รายละเอียดการโอนเงิน</strong>
                    <?php
                    $qrFile = setting("promptpay_qr");
                    $qrUrl  = $qrFile !== "" && is_file(__DIR__ . "/assets/images/shop/" . basename($qrFile))
                        ? "assets/images/shop/" . rawurlencode(basename($qrFile)) : null;
                    ?>
                    <ul>
                        <li>กรุณาโอนเงินตามยอดรวม <strong>฿<span data-pay-total><?= money($selQuote["total"]) ?></span></strong></li>
                        <?php if (setting("bank_account") !== ""): ?>
                            <li>
                                <?= e(setting("bank_name")) ?> เลขที่ <strong><?= e(setting("bank_account")) ?></strong>
                                <?php if (setting("bank_holder") !== ""): ?>ชื่อบัญชี <?= e(setting("bank_holder")) ?><?php endif; ?>
                            </li>
                        <?php elseif (!$qrUrl && promptpay_normalize(setting("promptpay_id")) === null): ?>
                            <li>ร้านยังไม่ได้ระบุเลขบัญชี กรุณาสอบถามร้านก่อนโอนเงิน (<a href="contact.php" target="_blank">ข้อมูลติดต่อ</a>)</li>
                        <?php endif; ?>
                        <li>จากนั้นแนบสลิปด้านล่างเพื่อให้ร้านตรวจสอบ</li>
                    </ul>
                    <?php
                    // QR ตามยอดของแต่ละวิธีรับสินค้าที่โอนเงินได้ (ยอดต่างกันเมื่อมีค่าส่ง)
                    $qrGroups = [];
                    foreach ($deliveryOptions as $method => [$kind, $pays]) {
                        if (in_array(PAY_TRANSFER, $pays, true)) {
                            $qrGroups[(string)round($shipQuote[$method]["total"], 2)][] = $kind;
                        }
                    }
                    ?>
                    <?php foreach ($qrGroups as $qrAmount => $kinds): ?>
                        <div data-qr-for="<?= e(implode(" ", $kinds)) ?>">
                            <?= promptpay_block((float)$qrAmount) ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($qrUrl && promptpay_normalize(setting("promptpay_id")) === null): ?>
                        <img src="<?= e($qrUrl) ?>" alt="QR Code รับเงิน" style="max-width:200px; margin:12px auto 0; border-radius:10px">
                    <?php endif; ?>
                </div>

                <div class="form-group" id="slip-group" hidden>
                    <label class="label" for="slip">แนบสลิปการโอนเงิน <span class="required">*</span></label>
                    <label class="file-drop" for="slip">
                        <span class="ico"><?= ui_icon("clip") ?></span>
                        <span id="slip-name">คลิกเพื่อเลือกรูปสลิป</span>
                        <input type="file" name="slip" id="slip" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <img id="slip-preview" class="file-preview" alt="ตัวอย่างสลิป" hidden>
                    <p class="hint">รองรับ JPG, PNG และ WEBP ขนาดไม่เกิน 2 MB</p>
                </div>
            </section>
        </div>

        <aside class="panel sticky">
            <h2>สรุปรายการสินค้า</h2>

            <?php foreach ($cartProducts as $product): ?>
                <div class="order-line">
                    <span>
                        <?= e($product["product_name"]) ?>
                        <span class="qty">× <?= (int)$product["quantity"] ?></span>
                    </span>
                    <strong>฿<?= money($product["subtotal"]) ?></strong>
                </div>
            <?php endforeach; ?>

            <?php if ($coupon): ?>
                <div class="summary-row" style="color:var(--success); margin-top:8px">
                    <span>ส่วนลด (<?= e($coupon["code"]) ?>)</span><span>−฿<?= money($discount) ?></span>
                </div>
            <?php endif; ?>

            <div class="summary-row" data-ship-row <?= $selDelivery === DELIVERY_PICKUP ? "hidden" : "" ?>>
                <span>ค่าจัดส่ง</span>
                <span data-ship-fee><?= $selQuote["fee"] > 0 ? "฿" . money($selQuote["fee"]) : "ฟรี" ?></span>
            </div>

            <div class="summary-row total">
                <span>รวมทั้งสิ้น</span>
                <span>฿<span data-pay-total><?= money($selQuote["total"]) ?></span></span>
            </div>

            <?php if (!$customer): ?>
                <p class="muted" style="font-size:13px; margin:10px 0 0">
                    <a href="login.php?return=checkout.php">เข้าสู่ระบบ</a> เพื่อเก็บคำสั่งซื้อนี้ไว้ในประวัติ (ไม่บังคับ)
                </p>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:20px">
                ยืนยันการสั่งซื้อ
            </button>
            <a href="cart.php" class="btn btn-ghost btn-block" style="margin-top:6px">← กลับไปแก้ไขตะกร้า</a>
        </aside>

    </form>

<?php endif; ?>

</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
