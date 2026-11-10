<?php
// เวลาไทยเสมอ (PHP ของ XAMPP ตั้งเขตเวลาไม่ตรงกับฐานข้อมูล)
date_default_timezone_set("Asia/Bangkok");

// ความปลอดภัยกลาง: header, CSP, session ที่แข็งแรง (ดู security.php)
require_once __DIR__ . "/security.php";
