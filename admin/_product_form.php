<?php
/**
 * ฟอร์มสินค้า (ใช้ร่วมกันระหว่างเพิ่ม/แก้ไข)
 * ตัวแปร: $formProduct (array), $categories, $isEdit (bool)
 */
$currentImage  = $isEdit ? product_image($formProduct["image"] ?? null, "../") : null;
$galleryItems  = $isEdit ? product_gallery((int)$formProduct["id"]) : [];
?>
<form method="POST" enctype="multipart/form-data" data-once>
    <?= csrf_field() ?>

    <div class="form-group">
        <label for="product_name">ชื่อสินค้า <span style="color:var(--rose)">*</span></label>
        <input type="text" id="product_name" name="product_name" maxlength="200" required
               placeholder="เช่น สมุดโน้ตลายการ์ตูน" value="<?= e($formProduct["product_name"] ?? "") ?>">
    </div>

    <div class="form-group">
        <label for="category_id">หมวดหมู่สินค้า</label>
        <select id="category_id" name="category_id">
            <option value=""><?= $isEdit ? "ไม่ระบุหมวดหมู่" : "เลือกหมวดหมู่" ?></option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int)$category["id"] ?>"
                    <?= (string)($formProduct["category_id"] ?? "") === (string)$category["id"] ? "selected" : "" ?>>
                    <?= e($category["category_name"]) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="hint">ยังไม่มีหมวดหมู่ที่ต้องการ? <a href="categories.php">เพิ่มหมวดหมู่ใหม่</a></p>
    </div>

    <div class="form-group">
        <label for="description">รายละเอียดสินค้า</label>
        <textarea id="description" name="description" placeholder="รายละเอียดสินค้า..."><?= e($formProduct["description"] ?? "") ?></textarea>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="price">ราคา (บาท) <span style="color:var(--rose)">*</span></label>
            <input type="number" id="price" name="price" min="0" step="0.01" required
                   placeholder="เช่น 25.00" value="<?= e($formProduct["price"] ?? "") ?>">
        </div>

        <div class="form-group">
            <label for="stock">จำนวนในสต็อก <span style="color:var(--rose)">*</span></label>
            <input type="number" id="stock" name="stock" min="0" step="1" required
                   placeholder="เช่น 50" value="<?= e($formProduct["stock"] ?? "") ?>">
        </div>
    </div>

    <fieldset class="form-fieldset">
        <legend>ราคาโปรโมชัน (ไม่บังคับ)</legend>
        <div class="form-row three">
            <div class="form-group">
                <label for="sale_price">ราคาลด (บาท)</label>
                <input type="number" id="sale_price" name="sale_price" min="0" step="0.01"
                       placeholder="เว้นว่างถ้าไม่ลด" value="<?= e($formProduct["sale_price"] ?? "") ?>">
            </div>
            <div class="form-group">
                <label for="sale_starts">เริ่ม</label>
                <input type="date" id="sale_starts" name="sale_starts" value="<?= e($formProduct["sale_starts"] ?? "") ?>">
            </div>
            <div class="form-group">
                <label for="sale_ends">สิ้นสุด</label>
                <input type="date" id="sale_ends" name="sale_ends" value="<?= e($formProduct["sale_ends"] ?? "") ?>">
            </div>
        </div>
        <p class="hint">ต้องต่ำกว่าราคาปกติ · เว้นวันเริ่มว่างเพื่อเริ่มทันที เว้นวันสิ้นสุดว่างเพื่อลดไปเรื่อย ๆ</p>
    </fieldset>

    <div class="form-group">
        <label for="image"><?= $isEdit ? "รูปภาพสินค้า" : "รูปภาพสินค้า" ?></label>

        <?php if ($currentImage): ?>
            <img class="current-image" id="current-image" src="<?= e($currentImage) ?>" alt="รูปภาพปัจจุบัน">
        <?php endif; ?>
        <img class="image-preview" id="image-preview" alt="ตัวอย่างรูปใหม่" hidden>

        <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" data-preview>
        <p class="hint">
            <?= $isEdit ? "หากไม่เลือกรูปใหม่ ระบบจะเก็บรูปเดิมไว้ · " : "" ?>
            รองรับ JPG, PNG และ WEBP ขนาดไม่เกิน 2 MB
        </p>
    </div>

    <div class="form-group">
        <label for="gallery">รูปเพิ่มเติม (สูงสุด <?= GALLERY_MAX ?> รูป)</label>
        <?php if ($galleryItems): ?>
            <div class="gallery-manage">
                <?php foreach ($galleryItems as $g): ?>
                    <?php $src = product_image($g["image"], "../"); ?>
                    <label class="gallery-item">
                        <?php if ($src): ?><img src="<?= e($src) ?>" alt=""><?php endif; ?>
                        <span><input type="checkbox" name="remove_gallery[]" value="<?= (int)$g["id"] ?>"> ลบรูปนี้</span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <input type="file" id="gallery" name="gallery[]" accept="image/jpeg,image/png,image/webp" multiple>
        <p class="hint">เลือกได้หลายรูปพร้อมกัน JPG, PNG หรือ WEBP รูปละไม่เกิน 2 MB</p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-sage"><?= $isEdit ? "บันทึกการแก้ไข" : "บันทึกสินค้า" ?></button>
        <a href="products.php" class="btn btn-light">ยกเลิก</a>
    </div>
</form>
