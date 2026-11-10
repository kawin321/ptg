<?php
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";
require_owner();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";
    $id     = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        flash("error", "คำขอไม่ถูกต้อง กรุณาลองใหม่");

    } elseif ($action === "add") {

        $code  = strtoupper(trim($_POST["code"] ?? ""));
        $type  = $_POST["discount_type"] ?? "";
        $value = $_POST["discount_value"] ?? "";
        $min   = $_POST["min_order"] === "" ? "0" : ($_POST["min_order"] ?? "0");
        $max   = trim($_POST["max_uses"] ?? "");
        $start = trim($_POST["starts_at"] ?? "");
        $end   = trim($_POST["expires_at"] ?? "");

        $validDate = fn($d) => $d === "" || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false);

        if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            flash("error", "รหัสคูปองใช้ A-Z, 0-9, - และ _ ได้ ยาว 3-40 ตัว");
        } elseif (!in_array($type, ["percent", "fixed"], true)) {
            flash("error", "กรุณาเลือกประเภทส่วนลด");
        } elseif (!is_numeric($value) || (float)$value <= 0 || ($type === "percent" && (float)$value > 100)) {
            flash("error", "มูลค่าส่วนลดไม่ถูกต้อง (เปอร์เซ็นต์ต้องอยู่ระหว่าง 1-100)");
        } elseif (!is_numeric($min) || (float)$min < 0) {
            flash("error", "ยอดซื้อขั้นต่ำไม่ถูกต้อง");
        } elseif ($max !== "" && (filter_var($max, FILTER_VALIDATE_INT) === false || (int)$max < 1)) {
            flash("error", "จำนวนครั้งที่ใช้ได้ต้องเป็นจำนวนเต็มตั้งแต่ 1 ขึ้นไป");
        } elseif (!$validDate($start) || !$validDate($end) || ($start !== "" && $end !== "" && $start > $end)) {
            flash("error", "วันที่เริ่มต้น/หมดอายุไม่ถูกต้อง");
        } else {
            try {
                $conn->prepare("INSERT INTO coupons (code, discount_type, discount_value, min_order, max_uses, starts_at, expires_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?)")
                     ->execute([$code, $type, $value, $min, $max === "" ? null : (int)$max, $start ?: null, $end ?: null]);
                admin_log("สร้างคูปอง", $code . " " . ($type === "percent" ? $value . "%" : "฿" . $value));
                flash("success", "สร้างคูปอง {$code} แล้ว");
            } catch (PDOException $e) {
                if ($e->getCode() === "23000") {
                    flash("error", "มีรหัสคูปอง {$code} อยู่แล้ว");
                } else {
                    error_log($e->getMessage());
                    flash("error", "ไม่สามารถสร้างคูปองได้");
                }
            }
        }

    } elseif ($action === "toggle" && $id) {
        $conn->prepare("UPDATE coupons SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        admin_log("เปิด/ปิดคูปอง", "#" . $id);
        flash("success", "เปลี่ยนสถานะคูปองแล้ว");

    } elseif ($action === "delete" && $id) {
        // คำสั่งซื้อเก็บรหัสและส่วนลดไว้เอง ลบคูปองไม่กระทบยอดเดิม
        $conn->prepare("DELETE FROM coupons WHERE id = ?")->execute([$id]);
        admin_log("ลบคูปอง", "#" . $id);
        flash("success", "ลบคูปองแล้ว");
    }

    redirect("coupons.php");
}

$coupons = $conn->query("SELECT * FROM coupons ORDER BY id DESC")->fetchAll();
$today   = date("Y-m-d");

$adminTitle  = "คูปองส่วนลด";
$adminActive = "coupons";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>คูปองส่วนลด</h1>
        <p>ลูกค้ากรอกรหัสได้ที่หน้าตะกร้า</p>
    </div>
</div>

<div class="grid-2 rev">
    <section class="card">
        <h2>สร้างคูปองใหม่</h2>
        <form method="POST" data-once>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <div class="form-group">
                <label for="code">รหัสคูปอง</label>
                <input type="text" id="code" name="code" maxlength="40" required placeholder="เช่น BACK2SCHOOL" style="text-transform:uppercase">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="discount_type">ประเภท</label>
                    <select id="discount_type" name="discount_type">
                        <option value="percent">ลดเป็นเปอร์เซ็นต์ (%)</option>
                        <option value="fixed">ลดเป็นจำนวนเงิน (บาท)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="discount_value">มูลค่าส่วนลด</label>
                    <input type="number" id="discount_value" name="discount_value" min="0.01" step="0.01" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="min_order">ซื้อขั้นต่ำ (บาท)</label>
                    <input type="number" id="min_order" name="min_order" min="0" step="0.01" placeholder="0">
                </div>
                <div class="form-group">
                    <label for="max_uses">ใช้ได้กี่ครั้ง</label>
                    <input type="number" id="max_uses" name="max_uses" min="1" step="1" placeholder="ไม่จำกัด">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="starts_at">เริ่มใช้</label>
                    <input class="form-control" type="date" id="starts_at" name="starts_at">
                </div>
                <div class="form-group">
                    <label for="expires_at">หมดอายุ</label>
                    <input class="form-control" type="date" id="expires_at" name="expires_at">
                </div>
            </div>
            <button type="submit" class="btn btn-sage">สร้างคูปอง</button>
        </form>
    </section>

    <section class="card">
        <h2>คูปองทั้งหมด</h2>
        <?php if ($coupons): ?>
            <div class="table-wrap">
                <table style="min-width:560px">
                    <thead><tr><th>รหัส</th><th>ส่วนลด</th><th class="num">ใช้แล้ว</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
                    <tbody>
                    <?php foreach ($coupons as $c): ?>
                        <?php
                        $expired = $c["expires_at"] !== null && $today > $c["expires_at"];
                        $full    = $c["max_uses"] !== null && (int)$c["used_count"] >= (int)$c["max_uses"];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($c["code"]) ?></strong><br>
                                <small class="muted">
                                    <?= (float)$c["min_order"] > 0 ? "ขั้นต่ำ ฿" . money($c["min_order"]) : "ไม่มีขั้นต่ำ" ?>
                                    <?= $c["starts_at"] ? " · เริ่ม " . e($c["starts_at"]) : "" ?>
                                    <?= $c["expires_at"] ? " · ถึง " . e($c["expires_at"]) : "" ?>
                                </small>
                            </td>
                            <td><?= $c["discount_type"] === "percent" ? (float)$c["discount_value"] . "%" : "฿" . money($c["discount_value"]) ?></td>
                            <td class="num"><?= (int)$c["used_count"] ?><?= $c["max_uses"] !== null ? " / " . (int)$c["max_uses"] : "" ?></td>
                            <td>
                                <?php if (!(int)$c["is_active"]): ?><span class="badge badge-out">ปิด</span>
                                <?php elseif ($expired): ?><span class="badge badge-out">หมดอายุ</span>
                                <?php elseif ($full): ?><span class="badge badge-low">ครบจำนวน</span>
                                <?php else: ?><span class="badge badge-ok">ใช้ได้</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <form method="POST">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$c["id"] ?>">
                                        <button type="submit" class="edit-btn"><?= (int)$c["is_active"] ? "ปิด" : "เปิด" ?></button>
                                    </form>
                                    <form method="POST" data-confirm="ลบคูปองนี้หรือไม่?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$c["id"] ?>">
                                        <button type="submit" class="delete-btn">ลบ</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty">ยังไม่มีคูปอง</div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
