<?php
/* ส่งรูปสลิปให้เฉพาะผู้ดูแลที่เข้าสู่ระบบ (ไฟล์จริงเปิดผ่าน URL ตรงไม่ได้) */
require_once __DIR__ . "/auth.php";

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(404);
    exit;
}

$stmt = $conn->prepare("SELECT slip_image FROM payments WHERE id = ?");
$stmt->execute([$id]);
$relative = (string)$stmt->fetchColumn();

// รับเฉพาะพาธรูปแบบที่ระบบสร้างเอง ป้องกัน path traversal
if (!preg_match('~^assets/images/slips/[a-f0-9]{32}\.(jpg|png|webp)$~', $relative)) {
    http_response_code(404);
    exit;
}

$path = realpath(__DIR__ . "/../" . $relative);
$base = realpath(__DIR__ . "/../assets/images/slips");

if ($path === false || $base === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

if (!in_array($mime, ["image/jpeg", "image/png", "image/webp"], true)) {
    http_response_code(404);
    exit;
}

header("Content-Type: " . $mime);
header("Content-Length: " . filesize($path));
header("Content-Disposition: inline");
header("Content-Security-Policy: default-src 'none'; sandbox");
header("Cache-Control: private, no-store");
readfile($path);
