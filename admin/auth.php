<?php
require_once __DIR__ . "/../includes/functions.php";

// ตรวจสอบว่าผู้ดูแลเข้าสู่ระบบแล้วหรือยัง
if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

// ตรวจกับฐานข้อมูลทุกคำขอ: ถูกปิดบัญชี เปลี่ยนสิทธิ์ หรือเปลี่ยนรหัสผ่านจะมีผลทันที
$stmt = $conn->prepare("SELECT id, username, fullname, role, is_active, password, totp_enabled FROM admins WHERE id = ?");
$stmt->execute([(int)$_SESSION["admin_id"]]);
$currentAdmin = $stmt->fetch();

$authFailure = null;

if (!$currentAdmin || !(int)$currentAdmin["is_active"]) {
    $authFailure = "บัญชีผู้ดูแลนี้ถูกปิดใช้งาน กรุณาติดต่อเจ้าของร้าน";
} elseif (!hash_equals((string)($_SESSION["admin_pwfp"] ?? ""), password_fingerprint($currentAdmin["password"]))) {
    $authFailure = "รหัสผ่านของบัญชีนี้ถูกเปลี่ยนแล้ว กรุณาเข้าสู่ระบบใหม่";
}

if ($authFailure !== null) {
    admin_session_clear();
    session_regenerate_id(true);
    flash("error", $authFailure);
    header("Location: login.php");
    exit;
}

unset($currentAdmin["password"]);

$_SESSION["admin_username"] = $currentAdmin["username"];
$_SESSION["admin_name"]     = $currentAdmin["fullname"];
$_SESSION["admin_role"]     = isset(ADMIN_ROLES[$currentAdmin["role"]]) ? $currentAdmin["role"] : "staff";
