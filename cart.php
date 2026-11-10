<?php
require_once __DIR__ . "/includes/functions.php";

if (!isset($_SESSION["cart"]) || !is_array($_SESSION["cart"])) {
    $_SESSION["cart"] = [];
}

/* =====================================================
   จัดการคำขอ (POST → redirect กลับ เพื่อไม่ให้ส่งซ้ำเมื่อรีเฟรช)
   ===================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $redirectTo = "cart.php";

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง");

    } else {
        $action    = $_POST["action"] ?? "";
        $productId = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);
        $quantity  = filter_input(INPUT_POST, "quantity", FILTER_VALIDATE_INT);

        if ($action === "add") {

            // เพิ่มสินค้า: กลับไปหน้าเดิมที่กดมา
            $redirectTo = safe_return_url($_POST["return"] ?? "", "cart.php");

            if (!$productId || !$quantity || $quantity < 1) {
                flash("error", "ข้อมูลสินค้าไม่ถูกต้อง");

            } else {
                $stmt = $conn->prepare("SELECT id, product_name, stock FROM products WHERE id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    flash("error", "ไม่พบสินค้าที่เลือก");

                } elseif ((int)$product["stock"] < 1) {
                    flash("error", "สินค้า “{$product["product_name"]}” หมดแล้ว");

                } else {
                    $newQuantity = (int)($_SESSION["cart"][$productId] ?? 0) + $quantity;

                    if ($newQuantity > (int)$product["stock"]) {
                        flash("error", "สินค้า “{$product["product_name"]}” เหลือเพียง " . (int)$product["stock"] . " ชิ้น");
                    } else {
                        $_SESSION["cart"][$productId] = $newQuantity;
                        flash("success", "เพิ่ม “{$product["product_name"]}” ลงตะกร้าแล้ว");
                    }
                }
            }

        } elseif ($action === "update") {

            if (!$productId || !$quantity || $quantity < 1) {
                flash("error", "จำนวนสินค้าไม่ถูกต้อง");

            } else {
                $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch();

                if (!$product) {
                    flash("error", "ไม่พบสินค้าในระบบ");
                } elseif ($quantity > (int)$product["stock"]) {
                    flash("error", "สินค้าเหลือเพียง " . (int)$product["stock"] . " ชิ้น");
                } else {
                    $_SESSION["cart"][$productId] = $quantity;
                    flash("success", "แก้ไขจำนวนสินค้าเรียบร้อยแล้ว");
                }
            }

        } elseif ($action === "remove") {

            if ($productId && isset($_SESSION["cart"][$productId])) {
                unset($_SESSION["cart"][$productId]);
                flash("success", "ลบสินค้าออกจากตะกร้าแล้ว");
            } else {
                flash("error", "ไม่พบสินค้าที่ต้องการลบ");
            }

        } elseif ($action === "apply_coupon") {

            $code = strtoupper(trim($_POST["coupon_code"] ?? ""));
            $subtotal = 0.0;

            // กันการสุ่มเดารหัสคูปอง
            $couponBlocked = throttle_blocked("coupon", "", 30, 600);
            throttle_hit("coupon");

            if (!empty($_SESSION["cart"])) {
                $ids = array_map("intval", array_keys($_SESSION["cart"]));
                $stmt = $conn->prepare("SELECT * FROM products WHERE id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")");
                $stmt->execute($ids);
                foreach ($stmt->fetchAll() as $row) {
                    $subtotal += effective_price($row) * (int)$_SESSION["cart"][(int)$row["id"]];
                }
            }

            $result = $couponBlocked
                ? ["error" => "ลองใช้คูปองบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่", "discount" => 0.0]
                : coupon_evaluate($code === "" ? null : coupon_find($code), $subtotal);

            if ($result["error"] !== null) {
                flash("error", $result["error"]);
            } else {
                $_SESSION["coupon_code"] = $code;
                flash("success", "ใช้คูปอง {$code} แล้ว ลด ฿" . money($result["discount"]));
            }

        } elseif ($action === "remove_coupon") {

            unset($_SESSION["coupon_code"]);
            flash("success", "นำคูปองออกแล้ว");

        } elseif ($action === "clear") {

            $_SESSION["cart"] = [];
            unset($_SESSION["coupon_code"]);
            flash("success", "ล้างตะกร้าสินค้าเรียบร้อยแล้ว");
        }
    }

    redirect($redirectTo);
}

/* =====================================================
   โหลดสินค้าในตะกร้า
   ===================================================== */
$cartProducts = [];
$totalAmount  = 0.0;
$itemCount    = 0;
$hasProblem   = false;

if (!empty($_SESSION["cart"])) {

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
        $stock    = (int)$product["stock"];

        $product["quantity"] = $quantity;
        $product["subtotal"] = effective_price($product) * $quantity;
        $product["problem"]  = $stock < $quantity;

        if ($product["problem"]) {
            $hasProblem = true;
        }

        $cartProducts[] = $product;
        $totalAmount   += $product["subtotal"];
        $itemCount     += $quantity;
    }

    // ลบสินค้าที่ไม่มีในระบบแล้วออกจากตะกร้า
    $foundIds = array_map(fn($p) => (int)$p["id"], $cartProducts);
    foreach (array_keys($_SESSION["cart"]) as $id) {
        if (!in_array((int)$id, $foundIds, true)) {
            unset($_SESSION["cart"][$id]);
        }
    }
}

$coupon   = $cartProducts ? cart_coupon($totalAmount) : null;
$discount = $coupon["discount"] ?? 0.0;

$pageTitle  = "ตะกร้าสินค้า";
$activePage = "cart";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">

    <div class="steps" aria-label="ขั้นตอนการสั่งซื้อ">
        <div class="step current"><i>1</i> ตะกร้าสินค้า</div>
        <span class="step-line"></span>
        <div class="step"><i>2</i> ข้อมูลและชำระเงิน</div>
        <span class="step-line"></span>
        <div class="step"><i>3</i> เสร็จสิ้น</div>
    </div>

    <h1 class="page-title">ตะกร้าสินค้าของฉัน</h1>

    <?php if ($cartProducts): ?>

        <div class="cart-layout">

            <section class="panel">
                <?php if ($hasProblem): ?>
                    <div class="alert alert-error">
                        สินค้าบางรายการมีจำนวนในสต็อกไม่พอ กรุณาปรับจำนวนก่อนดำเนินการต่อ
                    </div>
                <?php endif; ?>

                <?php foreach ($cartProducts as $product): ?>
                    <?php
                    $pid   = (int)$product["id"];
                    $img   = product_image($product["image"] ?? null);
                    $stock = (int)$product["stock"];
                    ?>
                    <div class="cart-item">
                        <a href="product.php?id=<?= $pid ?>" class="cart-thumb">
                            <?php if ($img): ?>
                                <img src="<?= e($img) ?>" alt="">
                            <?php else: ?>
                                <div class="no-image"><?= category_icon($product["category_name"]) ?></div>
                            <?php endif; ?>
                        </a>

                        <div class="cart-info">
                            <h3><a href="product.php?id=<?= $pid ?>"><?= e($product["product_name"]) ?></a></h3>
                            <div class="unit">฿<?= money(effective_price($product)) ?> / ชิ้น<?php if (sale_active($product)): ?> <s>฿<?= money($product["price"]) ?></s><?php endif; ?></div>

                            <?php if ($product["problem"]): ?>
                                <div class="unit" style="color:var(--danger); font-weight:600">
                                    มีในสต็อกเพียง <?= $stock ?> ชิ้น
                                </div>
                            <?php endif; ?>

                            <div class="cart-controls">
                                <form method="POST" style="display:flex; gap:8px; align-items:center">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="product_id" value="<?= $pid ?>">

                                    <div class="stepper">
                                        <button type="button" data-step="-1" aria-label="ลดจำนวน">−</button>
                                        <input type="number" name="quantity" min="1" max="<?= max(1, $stock) ?>"
                                               value="<?= (int)$product["quantity"] ?>" aria-label="จำนวน" required>
                                        <button type="button" data-step="1" aria-label="เพิ่มจำนวน">+</button>
                                    </div>

                                    <button type="submit" class="btn btn-light btn-sm">อัปเดต</button>
                                </form>

                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="product_id" value="<?= $pid ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm"
                                            data-confirm="ต้องการลบสินค้านี้ออกจากตะกร้าหรือไม่?"><?= ui_icon("trash") ?> ลบ</button>
                                </form>
                            </div>
                        </div>

                        <div class="cart-subtotal">฿<?= money($product["subtotal"]) ?></div>
                    </div>
                <?php endforeach; ?>
            </section>

            <aside class="panel sticky">
                <h2>สรุปคำสั่งซื้อ</h2>

                <div class="summary-row"><span>จำนวนสินค้า</span><span><?= $itemCount ?> ชิ้น</span></div>
                <div class="summary-row"><span>ราคาสินค้า</span><span>฿<?= money($totalAmount) ?></span></div>

                <?php if ($coupon): ?>
                    <div class="summary-row" style="color:var(--success)">
                        <span>ส่วนลด (<?= e($coupon["code"]) ?>)</span><span>−฿<?= money($discount) ?></span>
                    </div>
                    <form method="POST" style="text-align:right">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_coupon">
                        <button type="submit" class="btn btn-ghost btn-sm">นำคูปองออก</button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="coupon-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="apply_coupon">
                        <input class="form-control" name="coupon_code" maxlength="40" placeholder="รหัสคูปอง" aria-label="รหัสคูปอง" required>
                        <button type="submit" class="btn btn-light">ใช้</button>
                    </form>
                <?php endif; ?>

                <div class="summary-row total"><span>ยอดรวม</span><span>฿<?= money($totalAmount - $discount) ?></span></div>
                <p class="muted" style="font-size:13px; margin-top:8px">ค่าจัดส่ง (หากมี) คำนวณในขั้นตอนถัดไปตามวิธีรับสินค้า</p>

                <div style="display:grid; gap:10px; margin-top:20px">
                    <?php if ($hasProblem): ?>
                        <button class="btn btn-primary btn-lg btn-block is-disabled" disabled>ดำเนินการสั่งซื้อ</button>
                    <?php else: ?>
                        <a href="checkout.php" class="btn btn-primary btn-lg btn-block">ดำเนินการสั่งซื้อ →</a>
                    <?php endif; ?>
                    <a href="products.php" class="btn btn-light btn-block">เลือกซื้อสินค้าต่อ</a>
                </div>

                <form method="POST" style="text-align:center; margin-top:12px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-ghost btn-sm"
                            data-confirm="ต้องการล้างตะกร้าทั้งหมดหรือไม่?">ล้างตะกร้า</button>
                </form>
            </aside>

        </div>

    <?php else: ?>

        <div class="empty-state" style="max-width:560px; margin:0 auto">
            <div class="big"><?= ui_icon("cart") ?></div>
            <h3>ยังไม่มีสินค้าในตะกร้า</h3>
            <p>เลือกสินค้าเครื่องเขียนที่คุณชื่นชอบได้เลย</p>
            <a href="products.php" class="btn btn-primary btn-lg">เลือกซื้อสินค้า</a>
        </div>

    <?php endif; ?>

</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
