<?php
require_once __DIR__ . "/auth.php";
require_owner();

$q       = trim($_GET["q"] ?? "");
$adminId = filter_input(INPUT_GET, "admin", FILTER_VALIDATE_INT) ?: 0;

$where  = "WHERE 1=1";
$params = [];

if ($q !== "") {
    $where   .= " AND (l.action LIKE ? OR l.detail LIKE ? OR l.admin_username LIKE ?)";
    $like     = like_pattern($q);
    $params   = [$like, $like, $like];
}

if ($adminId) {
    $where   .= " AND l.admin_id = ?";
    $params[] = $adminId;
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM admin_logs l $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$perPage = 30;
[$page, $pages, $offset] = paginate($total, page_param(), $perPage);

$stmt = $conn->prepare("
    SELECT l.*, a.fullname
    FROM admin_logs l
    LEFT JOIN admins a ON a.id = l.admin_id
    $where
    ORDER BY l.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$admins = $conn->query("SELECT id, username, fullname FROM admins ORDER BY username")->fetchAll();

$pageUrl = fn($p) => "activity.php?" . http_build_query(array_filter(["q" => $q, "admin" => $adminId ?: null, "page" => $p > 1 ? $p : null]));

$adminTitle  = "บันทึกกิจกรรม";
$adminActive = "activity";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>บันทึกกิจกรรม</h1>
        <p>ใครทำอะไรในหลังบ้าน เมื่อไร (<?= number_format($total) ?> รายการ)</p>
    </div>
</div>

<div class="card">
    <form method="GET" class="toolbar" role="search">
        <input type="search" name="q" value="<?= e($q) ?>" class="grow" placeholder="ค้นหาการกระทำหรือรายละเอียด" aria-label="ค้นหากิจกรรม">
        <select name="admin" aria-label="ผู้ดูแล" style="width:200px">
            <option value="">ผู้ดูแลทุกคน</option>
            <?php foreach ($admins as $a): ?>
                <option value="<?= (int)$a["id"] ?>" <?= $adminId === (int)$a["id"] ? "selected" : "" ?>><?= e($a["username"]) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">ค้นหา</button>
        <?php if ($q !== "" || $adminId): ?><a href="activity.php" class="btn btn-light">ล้าง</a><?php endif; ?>
    </form>

    <?php if ($logs): ?>
        <div class="table-wrap">
            <table style="min-width:720px">
                <thead>
                    <tr><th>เวลา</th><th>ผู้ดูแล</th><th>การกระทำ</th><th>รายละเอียด</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="nowrap"><small><?= e($log["created_at"]) ?></small></td>
                        <td class="nowrap"><?= e($log["admin_username"]) ?></td>
                        <td class="nowrap"><strong><?= e($log["action"]) ?></strong></td>
                        <td><?= e($log["detail"]) ?></td>
                        <td class="nowrap"><small class="muted"><?= e($log["ip"]) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_html($page, $pages, $pageUrl) ?>
    <?php else: ?>
        <div class="empty"><?= $q !== "" || $adminId ? "ไม่พบกิจกรรมที่ค้นหา" : "ยังไม่มีกิจกรรม" ?></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
