<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

$error = "";

$categories = $conn->query("SELECT id, category_name FROM categories ORDER BY id")->fetchAll();

$formProduct = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $productName = trim($_POST["product_name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $price       = $_POST["price"] ?? "";
    $stock       = $_POST["stock"] ?? "";
    $categoryId  = $_POST["category_id"] ?? "";

    $formProduct = [
        "product_name" => $productName,
        "description"  => $description,
        "price"        => $price,
        "stock"        => $stock,
        "category_id"  => $categoryId,
        "sale_price"   => $_POST["sale_price"] ?? "",
        "sale_starts"  => $_POST["sale_starts"] ?? "",
        "sale_ends"    => $_POST["sale_ends"] ?? "",
    ];

    $imageName     = null;
    $categoryValue = null;

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        $error = "คำขอไม่ถูกต้อง กรุณาลองใหม่";
    } elseif ($productName === "") {
        $error = "กรุณากรอกชื่อสินค้า";
    } elseif (!is_numeric($price) || !is_finite((float)$price) || $price < 0) {
        $error = "กรุณากรอกราคาที่ถูกต้อง";
    } elseif (filter_var($stock, FILTER_VALIDATE_INT) === false || $stock < 0) {
        $error = "กรุณากรอกจำนวนสินค้าเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป";
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

        // ตรวจและบันทึกรูปภาพ (วาดใหม่เพื่อล้าง EXIF และโค้ดที่ซ่อนในไฟล์)
        if ($error === "" && isset($_FILES["image"]) && $_FILES["image"]["error"] !== UPLOAD_ERR_NO_FILE) {
            try {
                $imageName = save_uploaded_image($_FILES["image"], __DIR__ . "/../assets/images/products/");
            } catch (RuntimeException $ex) {
                $error = $ex->getMessage();
            }
        }

        // รูปเพิ่มเติม
        $galleryNames = [];

        if ($error === "") {
            $galleryFiles = uploaded_files("gallery");

            if (count($galleryFiles) > GALLERY_MAX) {
                $error = "รูปเพิ่มเติมได้สูงสุด " . GALLERY_MAX . " รูป";
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
                delete_product_image_file($imageName);
                $galleryNames = [];
                $imageName    = null;
            }
        }

        if ($error === "") {
            try {
                $conn->beginTransaction();

                $stmt = $conn->prepare("
                    INSERT INTO products (category_id, product_name, description, price, stock, image, sale_price, sale_starts, sale_ends)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$categoryValue, $productName, $description, $price, (int)$stock, $imageName, $salePrice, $saleStarts, $saleEnds]);
                $newId = (int)$conn->lastInsertId();

                $insertImage = $conn->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)");
                foreach ($galleryNames as $i => $galleryName) {
                    $insertImage->execute([$newId, $galleryName, $i + 1]);
                }

                if ((int)$stock !== 0) {
                    log_stock($newId, (int)$stock, "สินค้าใหม่", null, admin_actor());
                }

                $conn->commit();
                admin_log("เพิ่มสินค้า", $productName . " ราคา ฿" . money($price) . " สต็อก " . (int)$stock);

                redirect("products.php?added=1");

            } catch (PDOException $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                array_map("delete_product_image_file", $galleryNames);
                if ($imageName !== null) {
                    $path = __DIR__ . "/../assets/images/products/" . $imageName;
                    if (is_file($path)) {
                        unlink($path);
                    }
                }

                error_log($e->getMessage());
                $error = "บันทึกสินค้าไม่สำเร็จ กรุณาลองใหม่";
            }
        }
    }
}

$adminTitle  = "เพิ่มสินค้า";
$adminActive = "products";
$isEdit      = false;
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>เพิ่มสินค้าใหม่</h1>
        <p>กรอกข้อมูลสินค้าเพื่อเพิ่มลงในร้านค้า</p>
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
