<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");
        redirect("categories.php");
    }

    $action = $_POST["action"] ?? "";
    $name   = trim($_POST["category_name"] ?? "");
    $id     = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

    try {
        if ($action === "add" || $action === "rename") {

            if ($name === "") {
                flash("error", "กรุณากรอกชื่อหมวดหมู่");
                redirect("categories.php");
            }

            if (mb_strlen($name) > 100) {
                flash("error", "ชื่อหมวดหมู่ยาวเกินไป (ไม่เกิน 100 ตัวอักษร)");
                redirect("categories.php");
            }

            // ห้ามชื่อซ้ำ
            $stmt = $conn->prepare("SELECT id FROM categories WHERE category_name = ? AND id <> ?");
            $stmt->execute([$name, $action === "rename" ? (int)$id : 0]);

            if ($stmt->fetch()) {
                flash("error", "มีหมวดหมู่ชื่อ “{$name}” อยู่แล้ว");
                redirect("categories.php");
            }

            if ($action === "add") {
                $conn->prepare("INSERT INTO categories (category_name) VALUES (?)")->execute([$name]);
                admin_log("เพิ่มหมวดหมู่", $name);
                flash("success", "เพิ่มหมวดหมู่ “{$name}” เรียบร้อยแล้ว");
            } else {
                if (!$id) {
                    flash("error", "ข้อมูลไม่ถูกต้อง");
                    redirect("categories.php");
                }
                $conn->prepare("UPDATE categories SET category_name = ? WHERE id = ?")->execute([$name, $id]);
                admin_log("เปลี่ยนชื่อหมวดหมู่", "#{$id} → {$name}");
                flash("success", "เปลี่ยนชื่อหมวดหมู่เรียบร้อยแล้ว");
            }

        } elseif ($action === "delete") {

            if (!$id) {
                flash("error", "ข้อมูลไม่ถูกต้อง");
                redirect("categories.php");
            }

            // สินค้าในหมวดนี้จะกลายเป็น “ไม่ระบุหมวดหมู่” (ไม่ลบสินค้า)
            $conn->beginTransaction();
            $conn->prepare("UPDATE products SET category_id = NULL WHERE category_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            $conn->commit();

            admin_log("ลบหมวดหมู่", "#" . $id);
            flash("success", "ลบหมวดหมู่เรียบร้อยแล้ว (สินค้าในหมวดนี้ถูกย้ายไปเป็น “ไม่ระบุหมวดหมู่”)");
        }

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log($e->getMessage());
        flash("error", "ไม่สามารถดำเนินการได้ กรุณาลองใหม่");
    }

    redirect("categories.php");
}

$categories = $conn->query("
    SELECT c.id, c.category_name, COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id, c.category_name
    ORDER BY c.id ASC
")->fetchAll();

$adminTitle  = "จัดการหมวดหมู่";
$adminActive = "categories";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>จัดการหมวดหมู่</h1>
        <p>จัดกลุ่มสินค้าเพื่อให้ลูกค้าค้นหาง่ายขึ้น (<?= count($categories) ?> หมวดหมู่)</p>
    </div>
</div>

<div class="grid-2 rev">

    <section class="card">
        <h2>เพิ่มหมวดหมู่ใหม่</h2>
        <form method="POST" data-once>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label for="new_name">ชื่อหมวดหมู่</label>
                <input type="text" id="new_name" name="category_name" maxlength="100" required placeholder="เช่น สมุดและกระดาษ">
            </div>
            <button type="submit" class="btn btn-sage">+ เพิ่มหมวดหมู่</button>
        </form>
    </section>

    <section class="card">
        <h2>หมวดหมู่ทั้งหมด</h2>

        <?php if ($categories): ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ชื่อหมวดหมู่</th><th class="num">สินค้า</th><th>จัดการ</th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td>
                                <form method="POST" class="status-form" data-once>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="id" value="<?= (int)$cat["id"] ?>">
                                    <span aria-hidden="true"><?= category_icon($cat["category_name"]) ?></span>
                                    <input type="text" name="category_name" value="<?= e($cat["category_name"]) ?>"
                                           maxlength="100" required aria-label="ชื่อหมวดหมู่" style="min-width:150px">
                                    <button type="submit" class="edit-btn">บันทึก</button>
                                </form>
                            </td>
                            <td class="num"><?= (int)$cat["product_count"] ?></td>
                            <td>
                                <form method="POST"
                                      data-confirm="ลบหมวดหมู่นี้หรือไม่? สินค้า <?= (int)$cat["product_count"] ?> รายการในหมวดนี้จะไม่ถูกลบ แต่จะกลายเป็น ไม่ระบุหมวดหมู่">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$cat["id"] ?>">
                                    <button type="submit" class="delete-btn">ลบ</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">ยังไม่มีหมวดหมู่ — เพิ่มหมวดแรกได้ทางด้านซ้าย</div>
        <?php endif; ?>
    </section>

</div>

<?php require __DIR__ . "/_bottom.php"; ?>
