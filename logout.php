<?php
require_once __DIR__ . "/includes/functions.php";

// ออกจากระบบต้องเป็น POST + CSRF เพื่อกันลิงก์จากเว็บอื่น
if ($_SERVER["REQUEST_METHOD"] === "POST" && csrf_valid($_POST["csrf_token"] ?? "")) {
    unset(
        $_SESSION["customer_id"],
        $_SESSION["track_ok"],
        $_SESSION["last_order_number"],
        $_SESSION["last_order_status"],
        $_SESSION["last_payment_method"],
        $_SESSION["last_delivery_method"],
        $_SESSION["last_order_total"]
    );
    session_regenerate_id(true);
    flash("success", "ออกจากระบบแล้ว");
}

redirect("index.php");
