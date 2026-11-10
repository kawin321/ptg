<?php
require_once __DIR__ . "/../includes/functions.php";

// ออกจากระบบต้องเป็น POST + CSRF เพื่อกันลิงก์จากเว็บอื่นบังคับให้ออกจากระบบ
if ($_SERVER["REQUEST_METHOD"] === "POST" && csrf_valid($_POST["csrf_token"] ?? "") && isset($_SESSION["admin_id"])) {
    admin_log("ออกจากระบบ");

    // ออกจากระบบเฉพาะแอดมิน ไม่กระทบตะกร้าหรือบัญชีลูกค้าใน session เดียวกัน
    admin_session_clear();
    session_regenerate_id(true);
    csrf_rotate();
    flash("success", "ออกจากระบบแล้ว");

    header("Location: login.php");
    exit;
}

header("Location: " . (isset($_SESSION["admin_id"]) ? "dashboard.php" : "login.php"));
exit;
