<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";
require_owner();

$qrDir = __DIR__ . "/../assets/images/shop/";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
        redirect("settings.php");
    }

    $fields = ["shop_phone", "shop_line", "shop_address", "shop_hours", "bank_name", "bank_account", "bank_holder", "promptpay_id"];
    $values = [];

    foreach ($fields as $key) {
        $values[$key] = trim($_POST[$key] ?? "");
        if (mb_strlen($values[$key]) > 500) {
            flash("error", "ข้อความยาวเกินไป กรุณาย่อให้สั้นลง");
            redirect("settings.php");
        }
    }

    if ($values["shop_phone"] !== "" && !preg_match('/^[0-9+\-\s,()]{5,40}$/', $values["shop_phone"])) {
        flash("error", "เบอร์โทรศัพท์ร้านไม่ถูกต้อง");
        redirect("settings.php");
    }

    // ค่าจัดส่ง: ตัวเลข 0-100,000 ทศนิยมไม่เกิน 2 ตำแหน่ง
    foreach (["ship_fee_dept" => "ค่าส่งที่แผนก", "ship_fee_parcel" => "ค่าส่งพัสดุ", "ship_free_min" => "ยอดส่งฟรี"] as $key => $label) {
        $raw = str_replace([",", " "], "", trim($_POST[$key] ?? "0"));
        $raw = $raw === "" ? "0" : $raw;

        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $raw) || (float)$raw > 100000) {
            flash("error", "{$label}ต้องเป็นตัวเลข 0 ถึง 100,000");
            redirect("settings.php");
        }

        $values[$key] = number_format((float)$raw, 2, ".", "");
    }

    $values["parcel_enabled"]      = isset($_POST["parcel_enabled"]) ? "1" : "0";
    $values["maintenance_mode"]    = isset($_POST["maintenance_mode"]) ? "1" : "0";
    $values["maintenance_message"] = trim($_POST["maintenance_message"] ?? "");

    if (mb_strlen($values["maintenance_message"]) > 500) {
        flash("error", "ข้อความแจ้งปิดปรับปรุงยาวเกินไป (ไม่เกิน 500 ตัวอักษร)");
        redirect("settings.php");
    }

    $maintenanceChanged = $values["maintenance_mode"] !== setting("maintenance_mode");

    if ($values["promptpay_id"] !== "") {
        $normalized = promptpay_normalize($values["promptpay_id"]);

        if ($normalized === null) {
            flash("error", "เลขพร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก เลขบัตร 13 หลัก หรือ e-Wallet 15 หลัก");
            redirect("settings.php");
        }

        $values["promptpay_id"] = $normalized;
    }

    // รูป QR (ถ้าเลือกใหม่ หรือติ๊กลบ)
    $newQr  = null;
    $oldQr  = setting("promptpay_qr");

    if (isset($_FILES["promptpay_qr"]) && $_FILES["promptpay_qr"]["error"] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES["promptpay_qr"];

        if ($file["error"] !== UPLOAD_ERR_OK || $file["size"] > 2 * 1024 * 1024) {
            flash("error", "อัปโหลดรูป QR ไม่สำเร็จ (ขนาดไม่เกิน 2 MB)");
            redirect("settings.php");
        }

        try {
            $name = save_uploaded_image($file, $qrDir, 95, 2000);
        } catch (RuntimeException $ex) {
            flash("error", "รูป QR: " . $ex->getMessage());
            redirect("settings.php");
        }

        $newQr = $name;
    } elseif (isset($_POST["remove_qr"])) {
        $newQr = "";
    }

    try {
        foreach ($values as $key => $value) {
            save_setting($key, $value);
        }

        if ($newQr !== null) {
            save_setting("promptpay_qr", $newQr);

            if ($oldQr !== "" && is_file($qrDir . basename($oldQr))) {
                unlink($qrDir . basename($oldQr));
            }
        }

        admin_log("บันทึกการตั้งค่าร้าน", $values["promptpay_id"] !== "" ? "พร้อมเพย์ " . promptpay_masked($values["promptpay_id"]) : "");

        if ($maintenanceChanged) {
            admin_log($values["maintenance_mode"] === "1" ? "ปิดหน้าร้านชั่วคราว" : "เปิดหน้าร้าน");
        }

        flash("success", "บันทึกการตั้งค่าร้านเรียบร้อยแล้ว" . ($maintenanceChanged
            ? ($values["maintenance_mode"] === "1" ? " · หน้าร้านปิดปรับปรุงแล้ว ลูกค้าจะเห็นหน้าแจ้งปิดปรับปรุง" : " · หน้าร้านเปิดให้ลูกค้าใช้งานแล้ว")
            : ""));
    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash("error", "ไม่สามารถบันทึกการตั้งค่าได้");
    }

    redirect("settings.php");
}

$s = settings_all();
$qr = $s["promptpay_qr"] !== "" && is_file($qrDir . basename($s["promptpay_qr"]))
    ? "../assets/images/shop/" . rawurlencode(basename($s["promptpay_qr"]))
    : null;

$adminTitle  = "ตั้งค่าร้าน";
$adminActive = "settings";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>ตั้งค่าร้าน</h1>
        <p>ข้อมูลติดต่อและบัญชีรับเงิน จะแสดงที่หน้าเว็บ ท้ายเว็บ และหน้าชำระเงิน</p>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" data-once>
    <?= csrf_field() ?>

    <section class="card" id="store-status">
        <h2>สถานะหน้าร้าน</h2>
        <label class="switch-line">
            <input type="checkbox" name="maintenance_mode" value="1" <?= $s["maintenance_mode"] === "1" ? "checked" : "" ?>>
            <span>
                ปิดหน้าร้านชั่วคราว (โหมดปิดปรับปรุง)
                <small>ลูกค้าจะเห็นหน้าแจ้งปิดปรับปรุงและสั่งซื้อไม่ได้ ส่วนหลังบ้านและผู้ดูแลที่เข้าสู่ระบบอยู่ยังใช้งานและดูหน้าร้านได้ตามปกติ</small>
            </span>
        </label>
        <div class="form-group" style="margin-top:14px">
            <label for="maintenance_message">ข้อความที่แสดงให้ลูกค้า</label>
            <textarea id="maintenance_message" name="maintenance_message" maxlength="500"
                      placeholder="เช่น ร้านปิดตรวจนับสต็อกวันที่ 15 – 16 เปิดให้สั่งซื้ออีกครั้งวันที่ 17"><?= e($s["maintenance_message"]) ?></textarea>
            <p class="hint">เว้นว่างเพื่อใช้ข้อความมาตรฐาน ข้อมูลติดต่อร้านจะแสดงต่อท้ายให้อัตโนมัติ</p>
        </div>
    </section>

    <section class="card">
        <h2>การจัดส่งและค่าส่ง</h2>
        <div class="form-row three">
            <div class="form-group">
                <label for="ship_fee_dept">ค่าส่งที่แผนก (บาท)</label>
                <input type="text" inputmode="decimal" id="ship_fee_dept" name="ship_fee_dept" value="<?= e($s["ship_fee_dept"]) ?>">
                <p class="hint">ใส่ 0 = ส่งฟรี</p>
            </div>
            <div class="form-group">
                <label for="ship_fee_parcel">ค่าส่งพัสดุ (บาท)</label>
                <input type="text" inputmode="decimal" id="ship_fee_parcel" name="ship_fee_parcel" value="<?= e($s["ship_fee_parcel"]) ?>">
                <p class="hint">คิดเหมาต่อคำสั่งซื้อ</p>
            </div>
            <div class="form-group">
                <label for="ship_free_min">ส่งฟรีเมื่อยอดถึง (บาท)</label>
                <input type="text" inputmode="decimal" id="ship_free_min" name="ship_free_min" value="<?= e($s["ship_free_min"]) ?>">
                <p class="hint">ยอดสินค้าหลังหักส่วนลด · 0 = ไม่มีส่งฟรี</p>
            </div>
        </div>
        <label class="switch-line">
            <input type="checkbox" name="parcel_enabled" value="1" <?= $s["parcel_enabled"] === "1" ? "checked" : "" ?>>
            <span>
                เปิดตัวเลือก “จัดส่งพัสดุ” ผ่านบริษัทขนส่ง
                <small>ลูกค้ากรอกที่อยู่และชำระด้วยการโอนเงินเท่านั้น ร้านกรอกเลขพัสดุได้ที่หน้ารายละเอียดคำสั่งซื้อ และลูกค้าติดตามพัสดุได้จากหน้าติดตามคำสั่งซื้อ</small>
            </span>
        </label>
    </section>

    <div style="height:18px"></div>

    <div class="grid-2 rev" style="grid-template-columns:1fr 1fr">
        <section class="card">
            <h2>ข้อมูลติดต่อร้าน</h2>

            <div class="form-group">
                <label for="shop_phone">เบอร์โทรศัพท์</label>
                <input type="text" id="shop_phone" name="shop_phone" value="<?= e($s["shop_phone"]) ?>" placeholder="เช่น 081-234-5678">
            </div>
            <div class="form-group">
                <label for="shop_line">LINE ID / Facebook</label>
                <input type="text" id="shop_line" name="shop_line" value="<?= e($s["shop_line"]) ?>" placeholder="เช่น @3zastationery">
            </div>
            <div class="form-group">
                <label for="shop_address">ที่ตั้งร้าน</label>
                <textarea id="shop_address" name="shop_address" placeholder="เช่น อาคาร 2 ชั้น 1 ใกล้โรงอาหาร"><?= e($s["shop_address"]) ?></textarea>
            </div>
            <div class="form-group">
                <label for="shop_hours">เวลาทำการ</label>
                <input type="text" id="shop_hours" name="shop_hours" value="<?= e($s["shop_hours"]) ?>">
            </div>
        </section>

        <section class="card">
            <h2>บัญชีรับโอนเงิน</h2>

            <div class="form-group">
                <label for="bank_name">ธนาคาร</label>
                <input type="text" id="bank_name" name="bank_name" value="<?= e($s["bank_name"]) ?>" placeholder="เช่น กสิกรไทย">
            </div>
            <div class="form-group">
                <label for="bank_account">เลขที่บัญชี / พร้อมเพย์</label>
                <input type="text" id="bank_account" name="bank_account" value="<?= e($s["bank_account"]) ?>" placeholder="เช่น 123-4-56789-0">
            </div>
            <div class="form-group">
                <label for="bank_holder">ชื่อบัญชี</label>
                <input type="text" id="bank_holder" name="bank_holder" value="<?= e($s["bank_holder"]) ?>">
            </div>

            <div class="form-group">
                <label for="promptpay_id">พร้อมเพย์สำหรับ QR ตามยอด</label>
                <input type="text" id="promptpay_id" name="promptpay_id" inputmode="numeric" maxlength="20"
                       value="<?= e($s["promptpay_id"]) ?>" placeholder="เบอร์มือถือ 10 หลัก หรือเลขบัตร 13 หลัก">
                <p class="hint">หน้าชำระเงินจะสร้าง QR พร้อมยอดของแต่ละคำสั่งซื้อให้อัตโนมัติ ก่อนเปิดใช้ ให้ลองสแกนด้วยแอปธนาคารแล้วตรวจชื่อบัญชีปลายทาง</p>
            </div>

            <div class="form-group">
                <label for="promptpay_qr">รูป QR Code รับเงิน (ใช้เมื่อไม่ได้ตั้งพร้อมเพย์)</label>
                <?php if ($qr): ?>
                    <img class="current-image" src="<?= e($qr) ?>" alt="QR Code ปัจจุบัน">
                    <label style="font-weight:400"><input type="checkbox" name="remove_qr" value="1" style="width:auto"> ลบรูป QR ปัจจุบัน</label>
                <?php endif; ?>
                <img class="image-preview" id="image-preview" alt="ตัวอย่าง QR ใหม่" hidden>
                <input type="file" id="promptpay_qr" name="promptpay_qr" accept="image/jpeg,image/png,image/webp" data-preview>
                <p class="hint">JPG, PNG หรือ WEBP ไม่เกิน 2 MB</p>
            </div>
        </section>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-sage">บันทึกการตั้งค่า</button>
    </div>
</form>

<?php require __DIR__ . "/_bottom.php"; ?>
