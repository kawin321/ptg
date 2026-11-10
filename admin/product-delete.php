<?php
// การลบสินค้าทำผ่านปุ่มในหน้า products.php (POST + CSRF) เท่านั้น
header("Location: products.php");
exit;
