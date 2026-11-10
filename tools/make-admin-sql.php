<?php
/**
 * สร้างคำสั่ง SQL เพิ่มแอดมินคนแรก (ใช้กับโฮสต์ที่เข้า CLI ไม่ได้)
 *   php tools/make-admin-sql.php <username> <password> "<ชื่อ-นามสกุล>"
 * แล้วนำผลลัพธ์ไปรันใน phpMyAdmin (ฐานของเว็บ) หนึ่งครั้ง
 * รหัสผ่านถูกแฮชด้วย Argon2id แบบเดียวกับระบบ ไม่มีรหัสผ่านจริงอยู่ในผลลัพธ์
 * ใช้ได้จากบรรทัดคำสั่งเท่านั้น และห้ามอัปโหลดโฟลเดอร์ tools ขึ้นโฮสต์
 */
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }

[, $user, $pass, $full] = array_pad($argv, 4, "");
if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $user) || strlen($pass) < 12) {
    fwrite(STDERR, "ใช้งาน: php tools/make-admin-sql.php <username 3-50 ตัวอักษร a-z 0-9 _ . -> <password อย่างน้อย 12 ตัว> \"<ชื่อ>\"\n");
    exit(1);
}
$hash = password_hash($pass, PASSWORD_ARGON2ID, ["memory_cost" => 19456, "time_cost" => 2, "threads" => 1]);
$q = fn(string $s) => "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";

echo "-- เพิ่มแอดมินคนแรก (ถ้ายังไม่มีชื่อนี้)\n";
echo "INSERT INTO admins (username, password, fullname)\n";
echo "SELECT {$q($user)}, {$q($hash)}, {$q($full !== '' ? $full : $user)} FROM DUAL\n";
echo "WHERE NOT EXISTS (SELECT 1 FROM admins WHERE username = {$q($user)});\n";
