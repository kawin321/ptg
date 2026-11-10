<?php $tab = basename($_SERVER["SCRIPT_NAME"] ?? ""); ?>
<nav class="account-tabs" aria-label="เมนูบัญชี">
    <a href="account.php" class="<?= $tab === "account.php" ? "active" : "" ?>">ข้อมูลของฉัน</a>
    <a href="my-orders.php" class="<?= $tab === "my-orders.php" ? "active" : "" ?>">คำสั่งซื้อของฉัน</a>
    <a href="wishlist.php" class="<?= $tab === "wishlist.php" ? "active" : "" ?>">รายการโปรด</a>
</nav>
