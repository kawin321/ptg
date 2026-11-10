<?php
require_once __DIR__ . "/includes/functions.php";

http_response_code(404);
$pageTitle  = "ไม่พบหน้าที่ต้องการ";
$activePage = "";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">
    <div class="empty-state" style="max-width:560px; margin:0 auto">
        <div class="big"><?= ui_icon("compass") ?></div>
        <h1 style="font-size:28px">ไม่พบหน้าที่ต้องการ</h1>
        <p>หน้านี้อาจถูกย้ายหรือพิมพ์ที่อยู่ผิด ลองกลับไปเลือกสินค้าได้เลย</p>
        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap">
            <a href="index.php" class="btn btn-primary">กลับหน้าหลัก</a>
            <a href="products.php" class="btn btn-light">ดูสินค้าทั้งหมด</a>
        </div>
    </div>
</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
