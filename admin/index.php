<?php
// เปิด /admin/ แล้วไปหน้าภาพรวม (ถ้ายังไม่เข้าสู่ระบบ auth.php จะพาไปหน้าเข้าสู่ระบบ)
require_once __DIR__ . "/auth.php";

header("Location: dashboard.php");
exit;
