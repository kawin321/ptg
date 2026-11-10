<?php
require_once __DIR__ . "/includes/functions.php";

$s  = settings_all();
$qr = $s["promptpay_qr"] !== "" && is_file(__DIR__ . "/assets/images/shop/" . basename($s["promptpay_qr"]))
    ? "assets/images/shop/" . rawurlencode(basename($s["promptpay_qr"])) : null;

$faqs = [
    ["สั่งซื้อแล้วได้รับสินค้าเมื่อไหร่?",
     "ดูสถานะล่าสุดได้ที่หน้า “ติดตามคำสั่งซื้อ” หากต้องการทราบเวลาที่แน่นอน กรุณาสอบถามร้าน"],
    ["ชำระเงินได้ทางไหนบ้าง?",
     "มารับที่ร้านเลือกโอนเงิน (แนบสลิป) หรือจ่ายเงินสดได้ ส่วนจัดส่งที่แผนกชำระเงินปลายทางเมื่อได้รับสินค้า"],
    ["โอนเงินแล้วต้องทำอย่างไร?",
     "แนบรูปสลิปในขั้นตอนยืนยันการสั่งซื้อ เมื่อร้านตรวจสอบแล้ว สถานะจะเปลี่ยนเป็น “กำลังเตรียมสินค้า”"],
    ["ยกเลิกคำสั่งซื้อได้หรือไม่?",
     "ยกเลิกเองได้ที่หน้า “ติดตามคำสั่งซื้อ” ระหว่างที่ร้านยังไม่ยืนยันการชำระเงินหรือเริ่มเตรียมสินค้า หลังจากนั้นกรุณาติดต่อร้าน"],
    ["สลิปไม่ผ่านการตรวจสอบ ทำอย่างไร?",
     "เปิดคำสั่งซื้อที่หน้า “ติดตามคำสั่งซื้อ” แล้วแนบสลิปใหม่ได้เลย"],
    ["ลืมรหัสผ่านบัญชีลูกค้า?",
     "กรุณาติดต่อร้านเพื่อขอรหัสผ่านชั่วคราว แล้วเปลี่ยนรหัสผ่านเองที่หน้าบัญชีของฉัน"],
    ["ลืมเลขที่คำสั่งซื้อ?",
     "เลขที่คำสั่งซื้อแสดงในหน้าสั่งซื้อสำเร็จ หากหาไม่เจอ ติดต่อร้านพร้อมแจ้งชื่อและเบอร์โทรที่ใช้สั่งซื้อ"],
];

$pageTitle  = "ติดต่อร้าน & วิธีสั่งซื้อ";
$activePage = "contact";
require __DIR__ . "/includes/header.php";
?>

<div class="container section">

    <div class="page-head" style="padding-top:0">
        <h1>ติดต่อร้าน & วิธีสั่งซื้อ</h1>
        <p>ทุกอย่างที่ควรรู้ก่อนสั่งซื้อกับ <?= e(SHOP_NAME) ?></p>
    </div>

    <section class="features features-steps">
        <div class="feature"><span class="step-no">1</span><div><strong>เลือกสินค้า</strong><span>ใส่ตะกร้า</span></div></div>
        <div class="feature"><span class="step-no">2</span><div><strong>กรอกข้อมูล</strong><span>เลือกวิธีรับและชำระเงิน</span></div></div>
        <div class="feature"><span class="step-no">3</span><div><strong>ร้านตรวจสอบ</strong><span>ติดตามสถานะได้</span></div></div>
        <div class="feature"><span class="step-no">4</span><div><strong>รับสินค้า</strong><span>ที่ร้านหรือที่แผนก</span></div></div>
    </section>

    <div class="two-col">

        <section class="panel">
            <h2>ข้อมูลติดต่อ</h2>
            <div class="info-list" style="max-width:none">
                <div><span class="k">ร้าน</span><strong><?= e(SHOP_NAME) ?></strong></div>
                <?php if ($s["shop_phone"] !== ""): ?>
                    <div><span class="k">โทรศัพท์</span><strong><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $s["shop_phone"])) ?>"><?= e($s["shop_phone"]) ?></a></strong></div>
                <?php endif; ?>
                <?php if ($s["shop_line"] !== ""): ?>
                    <div><span class="k">LINE / Facebook</span><strong><?= e($s["shop_line"]) ?></strong></div>
                <?php endif; ?>
                <?php if ($s["shop_address"] !== ""): ?>
                    <div><span class="k">ที่ตั้ง</span><strong><?= nl2br(e($s["shop_address"])) ?></strong></div>
                <?php endif; ?>
                <?php if ($s["shop_hours"] !== ""): ?>
                    <div><span class="k">เวลาทำการ</span><strong><?= e($s["shop_hours"]) ?></strong></div>
                <?php endif; ?>
            </div>
            <?php if ($s["shop_phone"] === "" && $s["shop_line"] === "" && $s["shop_address"] === ""): ?>
                <p class="muted">ร้านยังไม่ได้เพิ่มข้อมูลติดต่อ</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>บัญชีรับโอนเงิน</h2>
            <?php if ($s["bank_account"] !== "" || $qr): ?>
                <div class="info-list" style="max-width:none">
                    <?php if ($s["bank_name"] !== ""): ?><div><span class="k">ธนาคาร</span><strong><?= e($s["bank_name"]) ?></strong></div><?php endif; ?>
                    <?php if ($s["bank_account"] !== ""): ?><div><span class="k">เลขที่บัญชี</span><strong><?= e($s["bank_account"]) ?></strong></div><?php endif; ?>
                    <?php if ($s["bank_holder"] !== ""): ?><div><span class="k">ชื่อบัญชี</span><strong><?= e($s["bank_holder"]) ?></strong></div><?php endif; ?>
                </div>
                <?php if ($qr): ?><img src="<?= e($qr) ?>" alt="QR Code รับเงิน" style="max-width:220px; margin:10px auto 0; border-radius:12px"><?php endif; ?>
            <?php else: ?>
                <p class="muted">ร้านยังไม่ได้เพิ่มข้อมูลบัญชี กรุณาสอบถามร้านก่อนโอนเงิน</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel" style="margin-top:20px">
        <h2>คำถามที่พบบ่อย</h2>
        <?php foreach ($faqs as [$q, $a]): ?>
            <details style="border-bottom:1px solid var(--line); padding:12px 0">
                <summary style="cursor:pointer; font-weight:600"><?= e($q) ?></summary>
                <p class="muted" style="margin:8px 0 0"><?= e($a) ?></p>
            </details>
        <?php endforeach; ?>
    </section>

</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
