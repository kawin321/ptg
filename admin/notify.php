<?php
/* ข้อมูลสำหรับแจ้งเตือนในหลังบ้าน (เรียกซ้ำทุก ~20 วินาที) */
require_once __DIR__ . "/../includes/session.php";

// เซสชันหมดอายุ: ตอบ 401 ให้หน้าเว็บแจ้งผู้ใช้ (ไม่ redirect)
if (!isset($_SESSION["admin_id"])) {
    http_response_code(401);
    header("Content-Type: application/json; charset=utf-8");
    echo '{"expired":true}';
    exit;
}

require_once __DIR__ . "/auth.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$row = $conn->query("
    SELECT
        (SELECT COALESCE(MAX(id), 0) FROM orders) AS order_max,
        (SELECT COUNT(*) FROM orders WHERE order_status = 'รอตรวจสอบการชำระเงิน') AS pending,
        (SELECT COALESCE(MAX(id), 0) FROM payments WHERE slip_image IS NOT NULL) AS slip_max,
        (SELECT COUNT(*) FROM reviews WHERE status = 'pending') AS reviews
")->fetch();

echo json_encode([
    "orderMax" => (int)$row["order_max"],
    "pending"  => (int)$row["pending"],
    "slipMax"  => (int)$row["slip_max"],
    "reviews"  => (int)$row["reviews"],
]);
