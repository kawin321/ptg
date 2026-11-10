<?php
/**
 * สำรองฐานข้อมูล (เจ้าของร้านเท่านั้น)
 * ไฟล์มีข้อมูลลูกค้าและรหัสผ่านที่เข้ารหัสแล้ว จึงต้องยืนยันรหัสผ่านอีกครั้งทุกครั้งที่ดาวน์โหลด
 */
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/../includes/functions.php";
require_owner();

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $adminId  = (int)$_SESSION["admin_id"];
    $password = (string)($_POST["password"] ?? "");

    $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
    $stmt->execute([$adminId]);
    $hash = $stmt->fetchColumn();

    if (!csrf_valid($_POST["csrf_token"] ?? "")) {
        $error = "คำขอไม่ถูกต้อง กรุณาลองใหม่";

    } elseif (throttle_blocked("backup", (string)$adminId, 10, 3600)) {
        $error = "ดาวน์โหลดหรือใส่รหัสผ่านผิดบ่อยเกินไป กรุณารอประมาณ 1 ชั่วโมงแล้วลองใหม่";

    } elseif (!verify_password_constant_time($password, $hash !== false ? (string)$hash : null)) {
        throttle_hit("backup", (string)$adminId);
        admin_log("สำรองข้อมูลไม่สำเร็จ", "รหัสผ่านไม่ถูกต้อง");
        $error = "รหัสผ่านไม่ถูกต้อง";

    } else {
        throttle_hit("backup", (string)$adminId);
        @set_time_limit(300);

        // บันทึกเวลาก่อนเริ่มส่งไฟล์ (ส่งข้อมูลออกไปแล้วจะ redirect ไม่ได้)
        save_setting("last_backup_at", date("Y-m-d H:i:s"));
        admin_log("ดาวน์โหลดไฟล์สำรองข้อมูล");

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header("Content-Type: application/sql; charset=UTF-8");
        header("Content-Disposition: attachment; filename=\"backup_3za_" . date("Ymd_His") . ".sql\"");
        header("Cache-Control: no-store");
        header("X-Content-Type-Options: nosniff");

        $out = fopen("php://output", "w");

        try {
            database_dump($out);
        } catch (Throwable $e) {
            error_log("backup failed: " . $e->getMessage());
            fwrite($out, "\n-- !!! การสำรองข้อมูลล้มเหลวกลางทาง ไฟล์นี้ไม่สมบูรณ์ ห้ามใช้กู้คืน !!!\n");
        }

        fclose($out);
        exit;
    }
}

/* ข้อมูลประกอบ */
$tables = 0;
$rows   = 0;
$sizeMb = 0.0;

try {
    $stats = $conn->query("
        SELECT COUNT(*) AS table_count, COALESCE(SUM(table_rows), 0) AS rows_est,
               COALESCE(SUM(data_length + index_length), 0) AS bytes
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
    ")->fetch();
    $tables = (int)$stats["table_count"];
    $rows   = (int)$stats["rows_est"];
    $sizeMb = (float)$stats["bytes"] / 1048576;
} catch (PDOException $e) {
    error_log($e->getMessage());
}

$lastBackup = setting("last_backup_at");
$daysSince  = $lastBackup !== "" ? (int)floor((time() - strtotime($lastBackup)) / 86400) : null;

$adminTitle  = "สำรองข้อมูล";
$adminActive = "backup";
require __DIR__ . "/_top.php";
?>

<div class="topbar">
    <div>
        <h1>สำรองข้อมูล</h1>
        <p>ดาวน์โหลดฐานข้อมูลทั้งหมดเป็นไฟล์ .sql เก็บไว้กู้คืนเมื่อเกิดปัญหา</p>
    </div>
</div>

<?php if ($daysSince === null): ?>
    <div class="alert alert-info">ยังไม่เคยสำรองข้อมูลจากหน้านี้ แนะนำให้ดาวน์โหลดเก็บไว้อย่างน้อยสัปดาห์ละครั้ง</div>
<?php elseif ($daysSince >= 7): ?>
    <div class="maint-banner">สำรองข้อมูลครั้งล่าสุดเมื่อ <?= $daysSince ?> วันก่อน ควรสำรองใหม่</div>
<?php endif; ?>

<div class="two-col">
    <section class="card">
        <h2>ดาวน์โหลดไฟล์สำรอง</h2>

        <dl class="backup-facts">
            <div><dt>จำนวนตาราง</dt><dd><?= number_format($tables) ?></dd></div>
            <div><dt>จำนวนแถว (ประมาณ)</dt><dd><?= number_format($rows) ?></dd></div>
            <div><dt>ขนาดฐานข้อมูล</dt><dd><?= number_format($sizeMb, 2) ?> MB</dd></div>
            <div><dt>สำรองล่าสุด</dt><dd><?= $lastBackup !== "" ? e($lastBackup) : "ยังไม่เคย" ?></dd></div>
        </dl>

        <?php if ($error !== ""): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="password">ยืนยันรหัสผ่านของคุณ</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
                <p class="hint">ไฟล์สำรองมีข้อมูลลูกค้าทั้งหมด จึงต้องยืนยันตัวตนทุกครั้ง</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= ui_icon("download", "16") ?> ดาวน์โหลดไฟล์ .sql</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>ข้อควรรู้</h2>
        <ul class="steps-list">
            <li>ไฟล์มีชื่อ เบอร์โทร ที่อยู่ลูกค้า และรหัสผ่านที่เข้ารหัสแล้ว เก็บไว้ในที่ปลอดภัย <strong>ห้ามส่งต่อหรืออัปโหลดขึ้นที่สาธารณะ</strong></li>
            <li>ไฟล์นี้เก็บเฉพาะฐานข้อมูล รูปสินค้าและสลิปอยู่ในโฟลเดอร์ <code>assets/images</code> ต้องคัดลอกแยกต่างหาก</li>
            <li>กุญแจเข้ารหัสความลับ (เช่น รหัสยืนยันสองขั้นตอน) อยู่ในไฟล์ <code>storage/app.key</code> ถ้ากู้คืนไปเครื่องใหม่ต้องคัดลอกไฟล์นี้ไปด้วย</li>
            <li>กู้คืน: เปิด phpMyAdmin เลือกฐานข้อมูล แล้วนำเข้าไฟล์ที่แท็บ Import ตารางที่ชื่อตรงกันจะถูกแทนที่ด้วยข้อมูลในไฟล์</li>
            <li>ทุกการดาวน์โหลดถูกบันทึกไว้ใน <a href="activity.php">บันทึกกิจกรรม</a></li>
        </ul>
    </section>
</div>

<?php require __DIR__ . "/_bottom.php"; ?>
