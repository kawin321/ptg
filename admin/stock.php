<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

/* ปรับสต็อกด้วยมือ */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $productId = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);
    $change    = filter_input(INPUT_POST, "change_qty", FILTER_VALIDATE_INT);
    $note      = mb_substr(trim($_POST["note"] ?? ""), 0, 200);

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
    } elseif (!$productId || !$change) {
        flash("error", "กรุณาเลือกสินค้าและกรอกจำนวน (+ เพิ่ม, - ลด ห้ามเป็น 0)");
    } elseif ($note === "") {
        flash("error", "กรุณาระบุเหตุผล เช่น รับของเข้า หรือ ของชำรุด");
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT stock, product_name FROM products WHERE id = ? FOR UPDATE");
            $stmt->execute([$productId]);
            $row = $stmt->fetch();

            if (!$row) {
                throw new RuntimeException("ไม่พบสินค้า");
            }
            if ((int)$row["stock"] + $change < 0) {
                throw new RuntimeException("ปรับไม่ได้: สต็อกของ “{$row["product_name"]}” มีเพียง " . (int)$row["stock"] . " ชิ้น");
            }

            $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([$change, $productId]);
            log_stock($productId, $change, "ปรับสต็อก", $note, admin_actor());

            $conn->commit();
            admin_log("ปรับสต็อก", "{$row["product_name"]} " . ($change > 0 ? "+" : "") . $change . " ({$note})");
            flash("success", "ปรับสต็อก “{$row["product_name"]}” เรียบร้อยแล้ว");

        } catch (RuntimeException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            flash("error", $e->getMessage());
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log($e->getMessage());
            flash("error", "ไม่สามารถปรับสต็อกได้");
        }
    }

    redirect("stock.php");
}

$q = trim($_GET["q"] ?? "");

$where  = "";
$params = [];

if ($q !== "") {
    $where  = "WHERE product_name LIKE ? OR reference LIKE ?";
    $like   = like_pattern($q);
    $params = [$like, $like];
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM stock_movements $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$perPage = 30;
[$page, $pages, $offset] = paginate($total, page_param(), $perPage);

$stmt = $conn->prepare("SELECT * FROM stock_movements $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$moves = $stmt->fetchAll();

$productOptions = $conn->query("SELECT id, product_name, stock FROM products ORDER BY product_name")->fetchAll();

$pageUrl = fn(int $p) => "stock.php?" . http_build_query(array_filter(["q" => $q, "page" => $p]));

$adminTitle  = "ประวัติสต็อก";
$adminActive = "stock";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>ประวัติสต็อก</h1>
        <p>บันทึกทุกการเข้าออกของสินค้า นับตั้งแต่เปิดใช้ระบบนี้</p>
    </div>
</div>

<section class="card" style="margin-bottom:20px">
    <h2>ปรับสต็อก</h2>
    <form method="POST" class="stock-form" data-once>
        <?= csrf_field() ?>
        <div>
            <label class="label" for="product_id">สินค้า</label>
            <select id="product_id" name="product_id" required>
                <option value="">เลือกสินค้า</option>
                <?php foreach ($productOptions as $p): ?>
                    <option value="<?= (int)$p["id"] ?>"><?= e($p["product_name"]) ?> (คงเหลือ <?= (int)$p["stock"] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="label" for="change_qty">จำนวน (+/-)</label>
            <input type="number" id="change_qty" name="change_qty" step="1" required placeholder="เช่น 10 หรือ -2">
        </div>
        <div>
            <label class="label" for="note">เหตุผล</label>
            <input type="text" id="note" name="note" maxlength="200" required placeholder="เช่น รับของเข้า">
        </div>
        <button type="submit" class="btn btn-sage">บันทึก</button>
    </form>
</section>

<div class="table-box">
    <form method="GET" class="toolbar">
        <input type="search" name="q" value="<?= e($q) ?>" class="grow" placeholder="ค้นหาชื่อสินค้า หรือเลขที่คำสั่งซื้อ" aria-label="ค้นหาประวัติ">
        <button type="submit" class="btn btn-primary">ค้นหา</button>
    </form>

    <?php if ($moves): ?>
        <div class="table-wrap">
            <table style="min-width:760px">
                <thead><tr><th>เวลา</th><th>สินค้า</th><th class="num">เปลี่ยนแปลง</th><th class="num">คงเหลือ</th><th>เหตุผล</th><th>โดย</th></tr></thead>
                <tbody>
                <?php foreach ($moves as $m): ?>
                    <tr>
                        <td><small><?= e($m["created_at"]) ?></small></td>
                        <td><?= e($m["product_name"]) ?></td>
                        <td class="num"><span class="badge <?= (int)$m["change_qty"] > 0 ? "badge-ok" : "badge-out" ?>"><?= (int)$m["change_qty"] > 0 ? "+" : "" ?><?= (int)$m["change_qty"] ?></span></td>
                        <td class="num"><?= (int)$m["stock_after"] ?></td>
                        <td><?= e($m["reason"]) ?><?php if ($m["reference"] !== null && $m["reference"] !== ""): ?><br><small class="muted"><?= e($m["reference"]) ?></small><?php endif; ?></td>
                        <td><small class="muted"><?= e($m["actor"]) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_html($page, $pages, $pageUrl) ?>
    <?php else: ?>
        <div class="empty">ยังไม่มีประวัติสต็อก</div>
    <?php endif; ?>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
