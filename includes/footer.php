</main>

<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-col">
            <div class="footer-brand">
                <img class="brand-mascot" src="assets/brand/mascot.svg" alt="" width="76" height="76" loading="lazy">
                <span class="brand-text">
                    <span class="brand-eyebrow">3 ZA · STATIONERY</span>
                    <strong>ซ่า<span>เครื่องเขียน</span></strong>
                </span>
            </div>
            <p><?= e(SHOP_TAGLINE) ?></p>
            <p class="muted">ร้านจำหน่ายอุปกรณ์การเรียนและเครื่องเขียน</p>
        </div>

        <div class="footer-col">
            <h4>เมนูลัด</h4>
            <a href="index.php">หน้าหลัก</a>
            <a href="products.php">สินค้าทั้งหมด</a>
            <a href="cart.php">ตะกร้าสินค้า</a>
            <a href="track.php">ติดตามคำสั่งซื้อ</a>
            <a href="contact.php">ติดต่อร้าน</a>
        </div>

        <div class="footer-col">
            <h4>ติดต่อร้าน</h4>
            <?php if (setting("shop_phone") !== ""): ?><p>โทร <?= e(setting("shop_phone")) ?></p><?php endif; ?>
            <?php if (setting("shop_line") !== ""): ?><p>LINE / Facebook <?= e(setting("shop_line")) ?></p><?php endif; ?>
            <?php if (setting("shop_address") !== ""): ?><p><?= e(setting("shop_address")) ?></p><?php endif; ?>
            <?php if (setting("shop_hours") !== ""): ?><p>เปิด <?= e(setting("shop_hours")) ?></p><?php endif; ?>
            <p class="muted">รับที่ร้าน หรือส่งถึงแผนก</p>
            <a href="contact.php">วิธีสั่งซื้อและคำถามที่พบบ่อย</a>
        </div>
    </div>

    <div class="footer-bottom">
        © 3 Za Stationery · เว็บไซต์สำหรับโครงงาน
        · <a href="admin/login.php">สำหรับผู้ดูแลร้าน</a>
    </div>
</footer>

<script src="assets/js/main.js?v=8"></script>
</body>
</html>
