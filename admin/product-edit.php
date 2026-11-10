<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    flash("error", "ไม่พบรหัสสินค้า");
    redirect("products.php");
}

$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    flash("error", "ไม่พบสินค้าที่ต้องการแก้ไข");
    redirect("products.php");
}

$categories = $conn->query("SELECT id, category_name FROM categories ORDER BY id")->fetchAll();

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $productName = trim($_POST["product_name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $price       = $_POST["price"] ?? "";
    $stock       = $_POST["stock"] ?? "";
    $categoryId  = $_POST["category_id"] ?? "";

    $categoryValue = null;
    $imageName     = $product["image"];
    $newImagePath  = null;

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        $error = "คำขอไม่ถูกต้อง กรุณาลองใหม่";
    } elseif ($productName === "") {
        $error = "กรุณากรอกชื่อสินค้า";
    } elseif (!is_numeric($price) || !is_finite((float)$price) || $price < 0) {
        $error = "กรุณากรอกราคาที่ถูกต้อง";
    } elseif (filter_var($stock, FILTER_VALIDATE_INT) === false || $stock < 0) {
        $error = "กรุณากรอกจำนวนสินค้าให้ถูกต้อง";
    } elseif ($categoryId !== "" && filter_var($categoryId, FILTER_VALIDATE_INT) === false) {
        $error = "กรุณาเลือกหมวดหมู่ให้ถูกต้อง";
    } else {

        [$saleError, $salePrice, $saleStarts, $saleEnds] = parse_sale_fields($_POST, $price);
        if ($saleError !== null) {
            $error = $saleError;
        }

        if ($error === "" && $categoryId !== "") {
            $check = $conn->prepare("SELECT id FROM categories WHERE id = ?");
            $check->execute([$categoryId]);

            if (!$check->fetch()) {
                $error = "ไม่พบหมวดหมู่ที่เลือก";
            } else {
                $categoryValue = (int)$categoryId;
            }
        }

        if ($error === "" && isset($_FILES["image"]) && $_FILES["image"]["error"] !== UPLOAD_ERR_NO_FILE) {
            try {
                $newImageName = save_uploaded_image($_FILES["image"], __DIR__ . "/../assets/images/products/");
                $newImagePath = __DIR__ . "/../assets/images/products/" . $newImageName;
                $imageName    = $newImageName;
            } catch (RuntimeException $ex) {
                $error = $ex->getMessage();
            }
        }

        // รูปเพิ่มเติม: ลบที่ติ๊ก + เพิ่มใหม่ รวมแล้วไม่เกินจำนวนสูงสุด
        $existingGallery = product_gallery($id);
        $existingIds     = array_map(fn($g) => (int)$g["id"], $existingGallery);
        $removeIds       = array_values(array_intersect($existingIds, array_map("intval", (array)($_POST["remove_gallery"] ?? []))));
        $galleryNames    = [];

        if ($error === "") {
            $galleryFiles = uploaded_files("gallery");

            if (count($existingIds) - count($removeIds) + count($galleryFiles) > GALLERY_MAX) {
                $error = "รูปเพิ่มเติมได้สูงสุด " . GALLERY_MAX . " รูป (ตอนนี้มี " . (count($existingIds) - count($removeIds)) . " รูป)";
            } else {
                try {
                    foreach ($galleryFiles as $galleryFile) {
                        $galleryNames[] = store_product_image($galleryFile);
                    }
                } catch (RuntimeException $ex) {
                    $error = $ex->getMessage();
                }
            }

            if ($error !== "") {
                array_map("delete_product_image_file", $galleryNames);
                $galleryNames = [];
                if ($newImagePath !== null && is_file($newImagePath)) {
                    unlink($newImagePath);
                }
                $newImagePath = null;
                $imageName    = $product["image"];
            }
        }

        if ($error === "") {
            try {
                // ล็อกแถวเพื่อบันทึกส่วนต่างสต็อกให้ตรง แม้มีลูกค้าสั่งซื้อพร้อมกัน
                $conn->beginTransaction();

                $lock = $conn->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
                $lock->execute([$id]);
                $stockBefore = (int)$lock->fetchColumn();

                $update = $conn->prepare("
                    UPDATE products SET
                        category_id = ?, product_name = ?, description = ?,
                        price = ?, stock = ?, image = ?,
                        sale_price = ?, sale_starts = ?, sale_ends = ?
                    WHERE id = ?
                ");
                $update->execute([$categoryValue, $productName, $description, $price, (int)$stock, $imageName, $salePrice, $saleStarts, $saleEnds, $id]);

                if ($removeIds) {
                    $conn->prepare("DELETE FROM product_images WHERE product_id = ? AND id IN (" . implode(",", array_fill(0, count($removeIds), "?")) . ")")
                         ->execute(array_merge([$id], $removeIds));
                }

                $nextOrder   = (int)$conn->query("SELECT COALESCE(MAX(sort_order), 0) FROM product_images WHERE product_id = " . (int)$id)->fetchColumn();
                $insertImage = $conn->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)");
                foreach ($galleryNames as $i => $galleryName) {
                    $insertImage->execute([$id, $galleryName, $nextOrder + $i + 1]);
                }

                if ((int)$stock !== $stockBefore) {
                    log_stock($id, (int)$stock - $stockBefore, "แก้ไขสินค้า", null, admin_actor());
                }

                $conn->commit();

                // ไฟล์รูปเพิ่มเติมที่ถูกลบ
                foreach ($existingGallery as $g) {
                    if (in_array((int)$g["id"], $removeIds, true)) {
                        delete_product_image_file($g["image"]);
                    }
                }

                $changes = [];
                if ((float)$product["price"] !== (float)$price) {
                    $changes[] = "ราคา ฿" . money($product["price"]) . " → ฿" . money($price);
                }
                if ((int)$product["stock"] !== (int)$stock) {
                    $changes[] = "สต็อก " . (int)$product["stock"] . " → " . (int)$stock;
                }
                if ((string)($product["sale_price"] ?? "") !== (string)($salePrice ?? "")
                    || (string)($product["sale_starts"] ?? "") !== (string)($saleStarts ?? "")
                    || (string)($product["sale_ends"] ?? "") !== (string)($saleEnds ?? "")) {
                    $changes[] = $salePrice === null ? "ยกเลิกราคาโปรโมชัน" : "ราคาโปร ฿" . money($salePrice)
                        . ($saleStarts ? " ตั้งแต่ " . $saleStarts : "") . ($saleEnds ? " ถึง " . $saleEnds : "");
                }
                if ($removeIds || $galleryNames) {
                    $changes[] = "รูปเพิ่มเติม +" . count($galleryNames) . " / -" . count($removeIds);
                }
                admin_log("แก้ไขสินค้า", $productName . ($changes ? " · " . implode(", ", $changes) : ""));

                // ลบรูปเก่าหลังบันทึกสำเร็จ
                if ($newImagePath !== null && !empty($product["image"])) {
                    $oldPath = __DIR__ . "/../assets/images/products/" . basename($product["image"]);
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }

                redirect("products.php?updated=1");

            } catch (PDOException $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }

                if ($newImagePath !== null && is_file($newImagePath)) {
                    unlink($newImagePath);
                }
                array_map("delete_product_image_file", $galleryNames);

                error_log($e->getMessage());
                $error = "แก้ไขสินค้าไม่สำเร็จ กรุณาลองใหม่";
            }
        }
    }

    // เก็บค่าที่กรอกไว้แสดงกลับ
    $product["product_name"] = $productName;
    $product["description"]  = $description;
    $product["price"]        = $price;
    $product["stock"]        = $stock;
    $product["category_id"]  = $categoryId;
    $product["sale_price"]   = $_POST["sale_price"] ?? "";
    $product["sale_starts"]  = $_POST["sale_starts"] ?? "";
    $product["sale_ends"]    = $_POST["sale_ends"] ?? "";
}

$formProduct = $product;
$adminTitle  = "แก้ไขสินค้า";
$adminActive = "products";
$isEdit      = true;
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>แก้ไขสินค้า</h1>
        <p>รหัสสินค้า #<?= (int)$id ?> · แก้ไขข้อมูลสินค้าในร้าน <?= e(SHOP_NAME) ?></p>
    </div>
    <div class="actions"><a href="products.php" class="btn btn-light">← กลับรายการสินค้า</a></div>
</div>

<div class="form-box">
    <?php if ($error !== ""): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php require __DIR__ . "/_product_form.php"; ?>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
