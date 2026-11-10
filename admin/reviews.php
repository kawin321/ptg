<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

$labels = ["pending" => "รออนุมัติ", "approved" => "อนุมัติแล้ว", "rejected" => "ไม่อนุมัติ"];

$filter = $_GET["status"] ?? "pending";
if ($filter !== "all" && !isset($labels[$filter])) {
    $filter = "pending";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $id     = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
    $action = $_POST["action"] ?? "";

    if (!csrf_valid($_POST["csrf_token"] ?? "") || !$id) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
    } elseif ($action === "approved" || $action === "rejected") {
        $conn->prepare("UPDATE reviews SET status = ? WHERE id = ?")->execute([$action, $id]);
        admin_log($action === "approved" ? "อนุมัติรีวิว" : "ไม่อนุมัติรีวิว", "#" . $id);
        flash("success", "บันทึกเป็น “{$labels[$action]}” แล้ว");
    } elseif ($action === "delete") {
        $conn->prepare("DELETE FROM reviews WHERE id = ?")->execute([$id]);
        admin_log("ลบรีวิว", "#" . $id);
        flash("success", "ลบรีวิวแล้ว");
    }

    redirect("reviews.php?status=" . urlencode($filter));
}

$counts = array_fill_keys(array_keys($labels), 0);
foreach ($conn->query("SELECT status, COUNT(*) c FROM reviews GROUP BY status") as $row) {
    $counts[$row["status"]] = (int)$row["c"];
}

$where  = $filter === "all" ? "" : "WHERE r.status = ?";
$params = $filter === "all" ? [] : [$filter];

$stmt = $conn->prepare("SELECT COUNT(*) FROM reviews r $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$perPage = 20;
[$page, $pages, $offset] = paginate($total, page_param(), $perPage);

$stmt = $conn->prepare("
    SELECT r.*, p.product_name, c.fullname, c.email
    FROM reviews r
    JOIN products p ON p.id = r.product_id
    JOIN customers c ON c.id = r.customer_id
    $where
    ORDER BY r.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$reviews = $stmt->fetchAll();

$adminTitle  = "รีวิวสินค้า";
$adminActive = "reviews";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>รีวิวสินค้า</h1>
        <p>รีวิวจะแสดงที่หน้าสินค้าหลังอนุมัติเท่านั้น</p>
    </div>
</div>

<div class="tabs">
    <?php foreach ($labels as $key => $label): ?>
        <a href="reviews.php?status=<?= $key ?>" class="<?= $filter === $key ? "active" : "" ?>"><?= e($label) ?> <small><?= $counts[$key] ?></small></a>
    <?php endforeach; ?>
    <a href="reviews.php?status=all" class="<?= $filter === "all" ? "active" : "" ?>">ทั้งหมด <small><?= array_sum($counts) ?></small></a>
</div>

<div class="table-box">
    <?php if ($reviews): ?>
        <div class="table-wrap">
            <table style="min-width:760px">
                <thead><tr><th>สินค้า</th><th>คะแนน</th><th>ความคิดเห็น</th><th>ลูกค้า</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($reviews as $r): ?>
                    <tr>
                        <td><a href="../product.php?id=<?= (int)$r["product_id"] ?>" target="_blank" rel="noopener"><?= e($r["product_name"]) ?></a></td>
                        <td style="color:#c98a12; white-space:nowrap"><?= str_repeat("★", (int)$r["rating"]) ?></td>
                        <td style="white-space:pre-line"><?= e($r["comment"]) ?></td>
                        <td><?= e($r["fullname"]) ?><br><small class="muted"><?= e(substr($r["created_at"], 0, 10)) ?></small></td>
                        <td><span class="badge <?= $r["status"] === "approved" ? "badge-ok" : ($r["status"] === "rejected" ? "badge-out" : "badge-low") ?>"><?= e($labels[$r["status"]] ?? $r["status"]) ?></span></td>
                        <td>
                            <form method="POST" class="row-actions" action="reviews.php?status=<?= urlencode($filter) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$r["id"] ?>">
                                <?php if ($r["status"] !== "approved"): ?><button class="edit-btn" name="action" value="approved">อนุมัติ</button><?php endif; ?>
                                <?php if ($r["status"] !== "rejected"): ?><button class="delete-btn" name="action" value="rejected">ไม่อนุมัติ</button><?php endif; ?>
                                <button class="delete-btn" name="action" value="delete" data-confirm="ลบรีวิวนี้หรือไม่?">ลบ</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_html($page, $pages, fn($p) => "reviews.php?status=" . urlencode($filter) . "&page=" . $p) ?>
    <?php else: ?>
        <div class="empty">ไม่มีรีวิวในหมวดนี้</div>
    <?php endif; ?>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
