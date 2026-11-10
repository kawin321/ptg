<?php
// ค่าการเชื่อมต่อแยกไว้นอก git:
//   config/database.production.php  — โฮสต์จริง (InfinityFree) อัปโหลดขึ้นโฮสต์ด้วย
//   config/database.local.php       — เครื่องนี้ (XAMPP) ทับค่าข้างบน ไม่ต้องอัปโหลดขึ้นโฮสต์
// ทั้งสองไฟล์คืนค่า array ดูตัวอย่างใน config/database.example.php
$db = [
    "host"     => "127.0.0.1",
    "port"     => 3306,
    "dbname"   => "",
    "username" => "",
    "password" => "",
];
foreach (["database.production.php", "database.local.php"] as $file) {
    if (is_file(__DIR__ . "/" . $file)) {
        $db = array_merge($db, (array)require __DIR__ . "/" . $file);
    }
}

try {
    $conn = new PDO(
        "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4",
        $db["username"],
        $db["password"]
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบการตั้งค่า");
}
