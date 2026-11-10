<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

/* ลบสินค้า (POST เท่านั้น) */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_product"])) {

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
        redirect("products.php");
    }

    $productId = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);

    if (!$productId) {
        flash("error", "รหัสสินค้าไม่ถูกต้อง");
        redirect("products.php");
    }

    $stmt = $conn->prepare("SELECT product_name, image FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $target = $stmt->fetch();

    if (!$target) {
        flash("error", "ไม่พบสินค้าที่ต้องการลบ");
        redirect("products.php");
    }

    $galleryOld = product_gallery($productId);

    try {
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$productId]);

        // ลบไฟล์รูปภาพที่ไม่ใช้แล้ว
        if (!empty($target["image"])) {
            $path = __DIR__ . "/../assets/images/products/" . basename($target["image"]);
            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ($galleryOld as $g) {
            delete_product_image_file($g["image"]);
        }

        admin_log("ลบสินค้า", $target["product_name"]);

        // เอาสินค้าที่ถูกลบออกจากตะกร้าของผู้ดูแล (ถ้ามี)
        unset($_SESSION["cart"][$productId]);

        flash("success", "ลบสินค้า “{$target["product_name"]}” เรียบร้อยแล้ว");

    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash("error", "ไม่สามารถลบสินค้า “{$target["product_name"]}” ได้ เพราะมีอยู่ในคำสั่งซื้อแล้ว (แนะนำให้ตั้งจำนวนสต็อกเป็น 0 แทน)");
    }

    redirect("products.php");
}

if (isset($_GET["added"]))   { flash("success", "เพิ่มสินค้าเรียบร้อยแล้ว");  redirect("products.php"); }
if (isset($_GET["updated"])) { flash("success", "แก้ไขสินค้าเรียบร้อยแล้ว");  redirect("products.php"); }
if (isset($_GET["deleted"])) { flash("success", "ลบสินค้าเรียบร้อยแล้ว");     redirect("products.php"); }

$q = trim($_GET["q"] ?? "");

$where  = "";
$params = [];

if ($q !== "") {
    $where  = "WHERE products.product_name LIKE ? OR categories.category_name LIKE ?";
    $like   = like_pattern($q);
    $params = [$like, $like];
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM products LEFT JOIN categories ON products.category_id = categories.id $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$perPage = 20;
[$page, $pages, $offset] = paginate($total, page_param(), $perPage);

$stmt = $conn->prepare("
    SELECT products.*, categories.category_name
    FROM products
    LEFT JOIN categories ON products.category_id = categories.id
    $where
    ORDER BY products.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageUrl = fn(int $p) => "products.php?" . http_build_query(array_filter(["q" => $q, "page" => $p]));

$adminTitle  = "จัดการสินค้า";
$adminActive = "products";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>จัดการสินค้า</h1>
        <p>เพิ่ม แก้ไข และตรวจสอบรายการสินค้าในร้าน (<?= number_format($total) ?> รายการ)</p>
    </div>
    <div class="actions">
        <a href="product-add.php" class="btn btn-sage">+ เพิ่มสินค้าใหม่</a>
    </div>
</div>

<div class="table-box">

    <form method="GET" class="toolbar">
        <input type="search" name="q" value="<?= e($q) ?>" class="grow" placeholder="ค้นหาชื่อสินค้า หรือหมวดหมู่" aria-label="ค้นหาสินค้า">
        <button type="submit" class="btn btn-primary">ค้นหา</button>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>สินค้า</th>
                    <th>หมวดหมู่</th>
                    <th class="num">ราคา</th>
                    <th class="num">สต็อก</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <?php
                $img   = product_image($product["image"] ?? null, "../");
                $stock = (int)$product["stock"];
                ?>
                <tr>
                    <td>
                        <div class="cell-product">
                            <?php if ($img): ?>
                                <img class="thumb" src="<?= e($img) ?>" alt="">
                            <?php else: ?>
                                <span class="thumb"><?= category_icon($product["category_name"]) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($product["product_name"]) ?></strong>
                                <small>รหัส #<?= (int)$product["id"] ?></small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if (!empty($product["category_name"])): ?>
                            <span class="badge badge-cat"><?= e($product["category_name"]) ?></span>
                        <?php else: ?>
                            <span class="muted">ไม่ระบุ</span>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?php if (sale_active($product)): ?>
                            <s class="muted">฿<?= money($product["price"]) ?></s><br><strong>฿<?= money($product["sale_price"]) ?></strong>
                        <?php else: ?>
                            ฿<?= money($product["price"]) ?>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?php if ($stock < 1): ?>
                            <span class="badge badge-out">หมด</span>
                        <?php elseif ($stock <= 5): ?>
                            <span class="badge badge-low"><?= $stock ?></span>
                        <?php else: ?>
                            <span class="badge badge-ok"><?= $stock ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="edit-btn" href="product-edit.php?id=<?= (int)$product["id"] ?>">แก้ไข</a>

                            <form method="POST"
                                  data-confirm="ต้องการลบสินค้า “<?= e($product["product_name"]) ?>” หรือไม่?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="product_id" value="<?= (int)$product["id"] ?>">
                                <button type="submit" name="delete_product" class="delete-btn">ลบ</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!$products): ?>
        <div class="empty"><?= $q !== "" ? "ไม่พบสินค้าที่ค้นหา" : "ยังไม่มีรายการสินค้า" ?><br><br><a href="product-add.php" class="btn btn-sage">+ เพิ่มสินค้าใหม่</a></div>
    <?php endif; ?>
    <?= pagination_html($page, $pages, $pageUrl) ?>

</div>

<?php require __DIR__ . "/_bottom.php"; ?>
