<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id     = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
    $action = $_POST["action"] ?? "";

    if (!csrf_valid($_POST["csrf_token"] ?? "") || !$id) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");

    } elseif (!is_owner()) {
        flash("error", "เฉพาะเจ้าของร้านเท่านั้นที่ระงับบัญชีหรือรีเซ็ตรหัสผ่านลูกค้าได้");

    } elseif ($action === "toggle") {
        $conn->prepare("UPDATE customers SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        admin_log("ระงับ/เปิดใช้บัญชีลูกค้า", "#" . $id);
        flash("success", "เปลี่ยนสถานะบัญชีลูกค้าแล้ว");

    } elseif ($action === "reset") {
        // ระบบยังไม่ส่งอีเมล จึงให้แอดมินแจ้งรหัสชั่วคราวแก่ลูกค้าโดยตรง
        $alphabet = "abcdefghjkmnpqrstuvwxyz23456789";
        $temp = "";
        for ($i = 0; $i < 10; $i++) {
            $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $stmt = $conn->prepare("UPDATE customers SET password = ? WHERE id = ?");
        $stmt->execute([hash_password($temp), $id]);

        admin_log("รีเซ็ตรหัสผ่านลูกค้า", "#" . $id);
        flash("success", "รหัสผ่านชั่วคราว: {$temp} (แสดงครั้งเดียว แจ้งลูกค้าให้เปลี่ยนหลังเข้าสู่ระบบ)");
    }

    redirect("customers.php");
}

$q = trim($_GET["q"] ?? "");

$where  = "";
$params = [];

if ($q !== "") {
    $where  = "WHERE c.fullname LIKE ? OR c.email LIKE ? OR c.phone LIKE ?";
    $like   = like_pattern($q);
    $params = [$like, $like, $like];
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM customers c $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$perPage = 20;
[$page, $pages, $offset] = paginate($total, page_param(), $perPage);

$stmt = $conn->prepare("
    SELECT c.id, c.email, c.fullname, c.phone, c.is_active, c.created_at,
           COALESCE(o.order_count, 0) AS order_count,
           COALESCE(o.spent, 0) AS spent
    FROM customers c
    LEFT JOIN (
        -- รวมยอดในซับคิวรีแยก: ไม่ต้อง GROUP BY คอลัมน์ลูกค้า จึงใช้ได้กับ ONLY_FULL_GROUP_BY (MariaDB/โฮสต์จริง)
        SELECT customer_id,
               COUNT(*) AS order_count,
               SUM(CASE WHEN order_status <> 'ยกเลิก' THEN total_amount END) AS spent
        FROM orders
        GROUP BY customer_id
    ) o ON o.customer_id = c.id
    $where
    ORDER BY c.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageUrl = fn(int $p) => "customers.php?" . http_build_query(array_filter(["q" => $q, "page" => $p]));

$adminTitle  = "ลูกค้า";
$adminActive = "customers";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>ลูกค้า</h1>
        <p>บัญชีลูกค้าที่สมัครสมาชิก (<?= number_format($total) ?> บัญชี)</p>
    </div>
</div>

<div class="table-box">
    <form method="GET" class="toolbar">
        <input type="search" name="q" value="<?= e($q) ?>" class="grow" placeholder="ค้นหาชื่อ อีเมล หรือเบอร์โทร" aria-label="ค้นหาลูกค้า">
        <button type="submit" class="btn btn-primary">ค้นหา</button>
    </form>

    <?php if ($customers): ?>
        <div class="table-wrap">
            <table style="min-width:800px">
                <thead>
                    <tr><th>ลูกค้า</th><th>เบอร์โทร</th><th class="num">คำสั่งซื้อ</th><th class="num">ยอดซื้อ</th><th>สถานะ</th><th>จัดการ</th></tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><strong><?= e($c["fullname"]) ?></strong><br><small class="muted"><?= e($c["email"]) ?></small></td>
                        <td><?= e($c["phone"]) ?></td>
                        <td class="num"><?= (int)$c["order_count"] ?></td>
                        <td class="num">฿<?= money($c["spent"]) ?></td>
                        <td>
                            <span class="badge <?= (int)$c["is_active"] ? "badge-ok" : "badge-out" ?>"><?= (int)$c["is_active"] ? "ใช้งาน" : "ระงับ" ?></span>
                        </td>
                        <td>
                            <?php if (is_owner()): ?><div class="row-actions">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$c["id"] ?>">
                                    <button type="submit" class="edit-btn"><?= (int)$c["is_active"] ? "ระงับ" : "เปิดใช้" ?></button>
                                </form>
                                <form method="POST" data-confirm="สร้างรหัสผ่านชั่วคราวให้ลูกค้าคนนี้? รหัสเดิมจะใช้ไม่ได้ทันที">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset">
                                    <input type="hidden" name="id" value="<?= (int)$c["id"] ?>">
                                    <button type="submit" class="delete-btn">รีเซ็ตรหัสผ่าน</button>
                                </form>
                            </div><?php else: ?><span class="muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_html($page, $pages, $pageUrl) ?>
    <?php else: ?>
        <div class="empty"><?= $q !== "" ? "ไม่พบลูกค้าที่ค้นหา" : "ยังไม่มีลูกค้าสมัครสมาชิก" ?></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
