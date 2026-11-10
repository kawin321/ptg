<?php
/**
 * ฟังก์ชันกลางที่ใช้ร่วมกันทั้งหน้าร้านและหลังบ้าน
 * - เริ่ม session
 * - เชื่อมต่อฐานข้อมูล
 * - ฟังก์ชันช่วยเหลือ (escape, CSRF, flash message, ตะกร้า)
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/../config/database.php";

/* ---------- ค่าคงที่ของระบบ ---------- */

const SHOP_NAME     = "3 ซ่าเครื่องเขียน";
const SHOP_TAGLINE  = "Everything You Need for Your Study";

const DELIVERY_PICKUP   = "รับที่ร้าน";
const DELIVERY_DEPT     = "จัดส่งสินค้าที่แผนก";
const DELIVERY_PARCEL   = "จัดส่งพัสดุ";

/** สถานะคำสั่งซื้อทั้งหมดตามลำดับขั้นตอน */
const ORDER_STATUSES = [
    "รอตรวจสอบการชำระเงิน",
    "รอรับสินค้าที่ร้าน",
    "กำลังเตรียมสินค้า",
    "จัดส่งแล้ว",
    "สำเร็จ",
    "ยกเลิก",
];

const PAY_TRANSFER = "โอนเงินผ่านธนาคาร";
const PAY_CASH     = "จ่ายเงินสดที่ร้าน";
const PAY_COD      = "เก็บเงินปลายทาง";

/* ---------- ตั้งค่าร้าน (ตาราง settings สร้างอัตโนมัติ) ---------- */

const SETTING_DEFAULTS = [
    "shop_phone"    => "",
    "shop_line"     => "",
    "shop_address"  => "",
    "shop_hours"    => "",
    "bank_name"     => "",
    "bank_account"  => "",
    "bank_holder"   => "",
    "promptpay_qr"  => "",
    "promptpay_id"  => "",

    // ค่าจัดส่ง (ใส่ 0 = ไม่คิดค่าส่ง)
    "ship_fee_dept"   => "0",
    "ship_fee_parcel" => "50",
    "ship_free_min"   => "0",   // ยอดสินค้าหลังหักส่วนลดถึงเท่านี้ส่งฟรี (0 = ไม่มีส่งฟรี)
    "parcel_enabled"  => "0",   // เปิดให้ลูกค้าเลือกส่งพัสดุผ่านบริษัทขนส่ง

    // ปิดปรับปรุงหน้าร้านชั่วคราว (ผู้ดูแลที่เข้าสู่ระบบยังเห็นหน้าร้านตามปกติ)
    "maintenance_mode"    => "0",
    "maintenance_message" => "",

    "last_backup_at" => "",
];

/** อ่านค่าตั้งค่าทั้งหมด (แคชต่อหนึ่งคำขอ) */
function settings_all(): array
{
    global $conn;
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = SETTING_DEFAULTS;

    try {
        foreach ($conn->query("SELECT setting_key, setting_value FROM settings") as $row) {
            if (array_key_exists($row["setting_key"], $cache)) {
                $cache[$row["setting_key"]] = (string)$row["setting_value"];
            }
        }
    } catch (PDOException $e) {
        error_log($e->getMessage()); // ใช้ค่าเริ่มต้นต่อไป
    }

    return $cache;
}

function setting(string $key): string
{
    return settings_all()[$key] ?? "";
}

function save_setting(string $key, string $value): void
{
    global $conn;

    if (!array_key_exists($key, SETTING_DEFAULTS)) {
        return;
    }

    $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
         ->execute([$key, $value]);
}

/* ---------- ฟังก์ชันทั่วไป ---------- */

/** ป้องกัน XSS */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

/** แสดงจำนวนเงิน */
function money($value): string
{
    return number_format((float)$value, 2);
}

/** URL รูปสินค้า (คืนค่า null ถ้าไม่มีไฟล์จริง) */
function product_image($image, string $prefix = ""): ?string
{
    if (empty($image)) {
        return null;
    }

    $file = basename((string)$image);
    $path = __DIR__ . "/../assets/images/products/" . $file;

    return is_file($path) ? $prefix . "assets/images/products/" . rawurlencode($file) : null;
}

/** ไอคอนเส้นชุดเดียวทั้งเว็บ ขนาดตามตัวอักษรรอบข้าง สีตามข้อความ */
function ui_icon(string $name, string $size = "1em", bool $filled = false): string
{
    static $paths = [
        "home"       => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        "box"        => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
        "tag"        => '<path d="M20 13l-7 7-10-10V3h7l10 10z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
        "receipt"    => '<path d="M5 3v18l3-2 2 2 2-2 2 2 2-2 3 2V3z"/><path d="M9 8h6M9 12h6"/>',
        "users"      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.5-4 3-6 6.5-6s6 2 6.5 6"/><path d="M16 4.8a3.5 3.5 0 010 6.4M18 14.5c2 .8 3.2 2.7 3.5 5.5"/>',
        "ticket"     => '<path d="M3 8a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 000-4z"/><path d="M14 6v2M14 11v2M14 16v2"/>',
        "star"       => '<path d="M12 3l2.8 5.8 6.2.9-4.5 4.4 1.1 6.3L12 17.4l-5.6 3 1.1-6.3L3 9.7l6.2-.9z"/>',
        "list"       => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        "chart"      => '<path d="M4 4v16h16"/><path d="M8 15l4-4 3 3 5-6"/>',
        "settings"   => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        "user"       => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.7-4.5 3.8-7 8-7s7.3 2.5 8 7"/>',
        "store"      => '<path d="M4 10v10h16V10"/><path d="M3 10l2-6h14l2 6a3 3 0 01-6 0 3 3 0 01-6 0 3 3 0 01-6 0z"/>',
        "logout"     => '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4"/><path d="M6 12h10"/>',
        "cart"       => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.5 12.5h11L21 7H6"/>',
        "heart"      => '<path d="M12 20s-8-5-8-11a4.5 4.5 0 018-2.8A4.5 4.5 0 0120 9c0 6-8 11-8 11z"/>',
        "search"     => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        "check"      => '<path d="M4 12.5l5 5L20 6.5"/>',
        "trash"      => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        "bank"       => '<path d="M3 9l9-6 9 6"/><path d="M5 9v9M10 9v9M14 9v9M19 9v9M3 21h18"/>',
        "cash"       => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
        "truck"      => '<path d="M2 6h12v11H2zM14 10h4l4 4v3h-8z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        "hand"       => '<path d="M3 11h4l3-3h4l7 6-2 3-3-2-3 3-3-1-3-3H3z"/>',
        "clip"       => '<path d="M20 11l-8 8a5 5 0 01-7-7l8-8a3.3 3.3 0 015 5l-8 8a1.7 1.7 0 01-2.5-2.5l7-7"/>',
        "compass"    => '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5l-2 5-5 2 2-5z"/>',
        "eye"        => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        "notebook"   => '<rect x="5" y="3" width="15" height="18" rx="2"/><path d="M9 3v18M3 8h3M3 12h3M3 16h3"/>',
        "pen"        => '<path d="M4 20l1-5L16 4l4 4L9 19z"/><path d="M13.5 6.5l4 4"/>',
        "pencil"     => '<path d="M4 20l1-4L17 4l3 3L8 19z"/><path d="M15 6l3 3M5 16l3 3"/>',
        "file"       => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4"/>',
        "backpack"   => '<path d="M6 21V10a6 6 0 0112 0v11z"/><path d="M9 4.6V3h6v1.6M9 21v-6h6v6"/>',
        "palette"    => '<path d="M12 3a9 9 0 000 18c1.5 0 2-1 2-2s-1-2 0-3 2-1 3-1h1a3 3 0 003-3c0-5-4-9-9-9z"/><circle cx="7.5" cy="11" r="1"/><circle cx="11" cy="7.5" r="1"/><circle cx="15.5" cy="8.5" r="1"/>',
        "drop"       => '<path d="M12 3s7 7 7 12a7 7 0 01-14 0c0-5 7-12 7-12z"/>',
        "scissors"   => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M8.5 7.8L20 18M8.5 16.2L20 6"/>',
        "calculator" => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h.01M12 12h.01M16 12h.01M8 16h.01M12 16h.01M16 16h.01"/>',
        "folder"     => '<path d="M3 6h6l2 2h10v11H3z"/>',
        "lock"       => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/>',
        "bell"       => '<path d="M6 16V11a6 6 0 0112 0v5l2 2H4z"/><path d="M10 20a2 2 0 004 0"/>',
        "shield"     => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        "history"    => '<path d="M3 12a9 9 0 103-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
        "download"   => '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/>',
        "tool"       => '<path d="M14.5 6.5a4 4 0 005 5L21 13l-8 8-3-3 1.5-1.5a4 4 0 01-5-5L3 9l3-3 3 3 1.5-1.5a4 4 0 014-1z"/>',
        "copy"       => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 00-1-1H5a1 1 0 00-1 1v10a1 1 0 001 1h3"/>',
        "ruler"      => '<path d="M3 16L16 3l5 5L8 21z"/><path d="M7 12l2 2M10 9l2 2M13 6l2 2"/>',
    ];

    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="' . ($filled ? "currentColor" : "none") . '" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? $paths["box"]) . '</svg>';
}

/** ไอคอนประจำหมวดหมู่ (ใช้เป็นภาพแทนเมื่อไม่มีรูปสินค้า) */
function category_icon($name): string
{
    $name = (string)$name;
    $map = [
        "สมุด"   => "notebook",
        "ปากกา"  => "pen",
        "ดินสอ"  => "pencil",
        "กระดาษ" => "file",
        "กระเป๋า" => "backpack",
        "สี"     => "palette",
        "ศิลป"   => "palette",
        "กาว"    => "drop",
        "คัตเตอร์" => "scissors",
        "กรรไกร" => "scissors",
        "เครื่องคิดเลข" => "calculator",
        "แฟ้ม"   => "folder",
        "ไม้บรรทัด" => "ruler",
    ];

    foreach ($map as $keyword => $icon) {
        if (mb_strpos($name, $keyword) !== false) {
            return ui_icon($icon);
        }
    }

    return ui_icon("box");
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

/** เปลี่ยน token ใหม่ (เรียกหลังเข้าสู่ระบบ/ออกจากระบบ) */
function csrf_rotate(): void
{
    unset($_SESSION["csrf_token"]);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid($token): bool
{
    return is_string($token)
        && !empty($_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], $token);
}

/* ---------- Flash message (แสดงครั้งเดียวหลัง redirect) ---------- */

function flash(string $type, string $message): void
{
    $_SESSION["flash"][] = ["type" => $type, "message" => $message];
}

function flash_pull(): array
{
    $messages = $_SESSION["flash"] ?? [];
    unset($_SESSION["flash"]);

    return $messages;
}

function redirect(string $url): void
{
    header("Location: " . $url);
    exit;
}

/* ---------- ตะกร้าสินค้า ---------- */

function cart_count(): int
{
    $count = 0;

    foreach ($_SESSION["cart"] ?? [] as $quantity) {
        $count += (int)$quantity;
    }

    return $count;
}

/** อนุญาต redirect กลับเฉพาะหน้าภายในร้าน */
function safe_return_url($url, string $default = "products.php"): string
{
    $url = (string)$url;

    if (preg_match('/^(index|products|product|wishlist|cart|checkout|account|my-orders|track)\.php(\?[A-Za-z0-9_=&%.+\-]*)?$/', $url)) {
        return $url;
    }

    return $default;
}

/* ---------- สถานะคำสั่งซื้อ ---------- */

/** คลาส CSS ของป้ายสถานะ */
function status_class(string $status): string
{
    $classes = [
        "รอตรวจสอบการชำระเงิน" => "status-pending",
        "รอรับสินค้าที่ร้าน"    => "status-pending",
        "กำลังเตรียมสินค้า"     => "status-preparing",
        "จัดส่งแล้ว"            => "status-shipped",
        "สำเร็จ"                => "status-success",
        "ยกเลิก"                => "status-cancelled",
    ];

    return $classes[$status] ?? "status-pending";
}

/* ---------- ระบบร้าน + อัปเกรดฐานข้อมูลอัตโนมัติ ---------- */

require_once __DIR__ . "/shop.php";

try {
    run_migrations();
} catch (PDOException $e) {
    error_log("migration failed: " . $e->getMessage());
    http_response_code(500);
    die("ไม่สามารถอัปเกรดฐานข้อมูลได้ กรุณาตรวจสอบ error log");
}

maintenance_gate();
