<?php
// sitemap.xml แบบไดนามิก: หน้าหลักของร้าน + สินค้าทุกชิ้น
require_once __DIR__ . "/includes/functions.php";

$host = preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $_SERVER["HTTP_HOST"] ?? "") ? $_SERVER["HTTP_HOST"] : "localhost";
$https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") || ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https";
$dir  = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "")), "/");
$base = ($https ? "https://" : "http://") . $host . $dir . "/";

header("Content-Type: application/xml; charset=UTF-8");
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach (["", "products.php", "track.php", "contact.php"] as $page) {
    echo "  <url><loc>" . htmlspecialchars($base . $page, ENT_XML1) . "</loc></url>\n";
}
foreach ($conn->query("SELECT id FROM products ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    echo "  <url><loc>" . htmlspecialchars($base . "product.php?id=" . (int)$id, ENT_XML1) . "</loc></url>\n";
}
echo "</urlset>\n";
