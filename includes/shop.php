<?php
/**
 * ฟังก์ชันระบบร้าน: อัปเกรดฐานข้อมูล, จำกัดการล็อกอิน, บัญชีลูกค้า,
 * รายการโปรด, รีวิว, คูปอง, ประวัติสต็อก, ยกเลิกคำสั่งซื้อ, แบ่งหน้า
 * (ถูกเรียกจาก functions.php)
 */

const SCHEMA_VERSION = 5;

/* ---------- อัปเกรดฐานข้อมูลอัตโนมัติ ---------- */

function run_migrations(): void
{
    global $conn;

    $conn->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key   VARCHAR(50) PRIMARY KEY,
        setting_value TEXT NULL
    ) CHARACTER SET utf8mb4");

    $read = fn() => (int)$conn->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn();

    if ($read() >= SCHEMA_VERSION) {
        return;
    }

    $conn->query("SELECT GET_LOCK('project_3ptg_migrate', 30)")->fetchColumn();

    try {
        if ($read() < 2) {
            $statements = [
                "CREATE TABLE IF NOT EXISTS customers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(190) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    fullname VARCHAR(150) NOT NULL,
                    phone VARCHAR(20) NOT NULL,
                    address TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) CHARACTER SET utf8mb4",

                "CREATE TABLE IF NOT EXISTS wishlists (
                    customer_id INT NOT NULL,
                    product_id INT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (customer_id, product_id),
                    CONSTRAINT fk_wish_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                    CONSTRAINT fk_wish_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                ) CHARACTER SET utf8mb4",

                "CREATE TABLE IF NOT EXISTS reviews (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    customer_id INT NOT NULL,
                    rating TINYINT NOT NULL,
                    comment TEXT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_review (product_id, customer_id),
                    KEY idx_review_status (status),
                    CONSTRAINT fk_review_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                    CONSTRAINT fk_review_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
                ) CHARACTER SET utf8mb4",

                "CREATE TABLE IF NOT EXISTS coupons (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(40) NOT NULL UNIQUE,
                    discount_type VARCHAR(10) NOT NULL,
                    discount_value DECIMAL(10,2) NOT NULL,
                    min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
                    max_uses INT NULL,
                    used_count INT NOT NULL DEFAULT 0,
                    starts_at DATE NULL,
                    expires_at DATE NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) CHARACTER SET utf8mb4",

                "CREATE TABLE IF NOT EXISTS stock_movements (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    product_name VARCHAR(200) NOT NULL,
                    change_qty INT NOT NULL,
                    stock_after INT NOT NULL,
                    reason VARCHAR(50) NOT NULL,
                    reference VARCHAR(200) NULL,
                    actor VARCHAR(150) NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_stock_product (product_id),
                    KEY idx_stock_created (created_at)
                ) CHARACTER SET utf8mb4",

                "CREATE TABLE IF NOT EXISTS login_attempts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    scope VARCHAR(20) NOT NULL,
                    identifier VARCHAR(190) NOT NULL,
                    ip VARCHAR(45) NOT NULL,
                    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_attempt_lookup (scope, identifier, ip, attempted_at),
                    KEY idx_attempt_ip (ip, attempted_at)
                ) CHARACTER SET utf8mb4",

                "ALTER TABLE orders
                    ADD COLUMN IF NOT EXISTS customer_id INT NULL,
                    ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(40) NULL,
                    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                    ADD INDEX IF NOT EXISTS idx_orders_customer (customer_id)",
            ];

            foreach ($statements as $sql) {
                $conn->exec($sql);
            }

            $conn->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '2')
                         ON DUPLICATE KEY UPDATE setting_value = '2'");
        }

        // รุ่น 3: แอดมินหลายคน, บันทึกกิจกรรม, ราคาโปรโมชัน, หลายรูปต่อสินค้า, แจ้งเตือนสถานะให้ลูกค้า
        if ($read() < 3) {
            $statements = [
                "ALTER TABLE admins
                    ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'owner',
                    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1,
                    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL",

                "CREATE TABLE IF NOT EXISTS admin_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NULL,
                    admin_username VARCHAR(50) NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    detail VARCHAR(500) NOT NULL DEFAULT '',
                    ip VARCHAR(45) NOT NULL DEFAULT '',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_log_created (created_at),
                    KEY idx_log_admin (admin_id)
                ) CHARACTER SET utf8mb4",

                "ALTER TABLE products
                    ADD COLUMN IF NOT EXISTS sale_price DECIMAL(10,2) NULL,
                    ADD COLUMN IF NOT EXISTS sale_starts DATE NULL,
                    ADD COLUMN IF NOT EXISTS sale_ends DATE NULL",

                "CREATE TABLE IF NOT EXISTS product_images (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    image VARCHAR(255) NOT NULL,
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_pimg_product (product_id, sort_order),
                    CONSTRAINT fk_pimg_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                ) CHARACTER SET utf8mb4",

                "ALTER TABLE orders
                    ADD COLUMN IF NOT EXISTS status_changed_at DATETIME NULL,
                    ADD COLUMN IF NOT EXISTS customer_seen_at DATETIME NULL",
            ];

            foreach ($statements as $sql) {
                $conn->exec($sql);
            }

            $conn->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '3')
                         ON DUPLICATE KEY UPDATE setting_value = '3'");
        }

        // รุ่น 4: ยืนยันตัวตนสองขั้นตอน (TOTP) สำหรับผู้ดูแล
        if ($read() < 4) {
            $statements = [
                "ALTER TABLE admins
                    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(255) NULL,
                    ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS totp_last_step BIGINT NOT NULL DEFAULT 0",

                "CREATE TABLE IF NOT EXISTS admin_recovery_codes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id INT NOT NULL,
                    code_hash CHAR(64) NOT NULL,
                    used_at DATETIME NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_rc_lookup (admin_id, code_hash),
                    CONSTRAINT fk_rc_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
                ) CHARACTER SET utf8mb4",
            ];

            foreach ($statements as $sql) {
                $conn->exec($sql);
            }

            $conn->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '4')
                         ON DUPLICATE KEY UPDATE setting_value = '4'");
        }

        // รุ่น 5: ค่าจัดส่ง และข้อมูลพัสดุ (บริษัทขนส่ง + เลขพัสดุ)
        if ($read() < 5) {
            $conn->exec("ALTER TABLE orders
                ADD COLUMN IF NOT EXISTS shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
                ADD COLUMN IF NOT EXISTS carrier VARCHAR(50) NULL,
                ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(60) NULL,
                ADD COLUMN IF NOT EXISTS shipped_at DATETIME NULL");

            $conn->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '5')
                         ON DUPLICATE KEY UPDATE setting_value = '5'");
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('project_3ptg_migrate')")->fetchColumn();
    }
}

/* ---------- จำกัดการเดารหัสผ่าน (เก็บในฐานข้อมูล เปิด session ใหม่ก็เลี่ยงไม่ได้) ---------- */

const LOGIN_MAX_PER_ACCOUNT = 5;   // ต่อบัญชี+IP ใน 5 นาที
const LOGIN_MAX_PER_IP      = 30;  // ต่อ IP ใน 15 นาที

function client_ip(): string
{
    return substr((string)($_SERVER["REMOTE_ADDR"] ?? "unknown"), 0, 45);
}

function login_locked(string $scope, string $identifier): bool
{
    global $conn;

    $identifier = mb_substr(mb_strtolower($identifier), 0, 190);

    $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts
        WHERE scope = ? AND identifier = ? AND ip = ? AND attempted_at > NOW() - INTERVAL 5 MINUTE");
    $stmt->execute([$scope, $identifier, client_ip()]);

    if ((int)$stmt->fetchColumn() >= LOGIN_MAX_PER_ACCOUNT) {
        return true;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM login_attempts
        WHERE ip = ? AND attempted_at > NOW() - INTERVAL 15 MINUTE");
    $stmt->execute([client_ip()]);

    return (int)$stmt->fetchColumn() >= LOGIN_MAX_PER_IP;
}

function login_failed(string $scope, string $identifier): void
{
    global $conn;

    $conn->prepare("INSERT INTO login_attempts (scope, identifier, ip) VALUES (?, ?, ?)")
         ->execute([$scope, mb_substr(mb_strtolower($identifier), 0, 190), client_ip()]);

    if (random_int(1, 20) === 1) {
        $conn->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
    }
}

function login_clear(string $scope, string $identifier): void
{
    global $conn;

    $conn->prepare("DELETE FROM login_attempts WHERE scope = ? AND identifier = ? AND ip = ?")
         ->execute([$scope, mb_substr(mb_strtolower($identifier), 0, 190), client_ip()]);
}

/* ---------- บัญชีลูกค้า ---------- */

function current_customer(): ?array
{
    global $conn;
    static $loaded = false, $customer = null;

    if ($loaded) {
        return $customer;
    }
    $loaded = true;

    if (empty($_SESSION["customer_id"])) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id, email, fullname, phone, address, password FROM customers WHERE id = ? AND is_active = 1");
    $stmt->execute([(int)$_SESSION["customer_id"]]);
    $customer = $stmt->fetch() ?: null;

    if ($customer) {
        // รหัสผ่านถูกเปลี่ยน/รีเซ็ตแล้ว: session เดิมทุกเครื่องต้องหลุด
        $fingerprint = password_fingerprint($customer["password"]);

        if (!isset($_SESSION["customer_pwfp"])) {
            $_SESSION["customer_pwfp"] = $fingerprint;
        } elseif (!hash_equals((string)$_SESSION["customer_pwfp"], $fingerprint)) {
            $customer = null;
            customer_session_clear();
            flash("error", "รหัสผ่านของบัญชีนี้ถูกเปลี่ยนแล้ว กรุณาเข้าสู่ระบบใหม่");
        }
    } else {
        customer_session_clear(); // บัญชีถูกระงับหรือไม่มีแล้ว
    }

    if ($customer) {
        unset($customer["password"]);   // ไม่เก็บแฮชไว้ในตัวแปรที่หน้าอื่นใช้
    }

    return $customer;
}

function require_customer(string $return = "account.php"): array
{
    $customer = current_customer();

    if (!$customer) {
        flash("error", "กรุณาเข้าสู่ระบบก่อน");
        redirect("login.php?return=" . urlencode($return));
    }

    return $customer;
}

function valid_thai_phone(string $phone): bool
{
    return (bool)preg_match('/^[0-9+\-\s]{9,20}$/', $phone);
}

/* ---------- รายการโปรด ---------- */

function wishlist_ids(): array
{
    global $conn;
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }

    $ids = [];
    $customer = current_customer();

    if ($customer) {
        $stmt = $conn->prepare("SELECT product_id FROM wishlists WHERE customer_id = ?");
        $stmt->execute([$customer["id"]]);
        $ids = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    return $ids;
}

function wishlist_button(int $productId, string $return): string
{
    $on = in_array($productId, wishlist_ids(), true);

    return '<form action="wishlist.php" method="POST" class="wish-form">' . csrf_field()
        . '<input type="hidden" name="product_id" value="' . $productId . '">'
        . '<input type="hidden" name="return" value="' . e($return) . '">'
        . '<button type="submit" class="wish-btn' . ($on ? " on" : "") . '" aria-pressed="' . ($on ? "true" : "false") . '"'
        . ' aria-label="' . ($on ? "เอาออกจากรายการโปรด" : "เพิ่มในรายการโปรด") . '">' . ui_icon("heart", "1em", $on) . '</button></form>';
}

/** ปุ่มรายการโปรดแบบมีข้อความ สำหรับหน้ารายละเอียดสินค้า */
function wishlist_form_inline(int $productId): string
{
    $on = in_array($productId, wishlist_ids(), true);

    return '<form action="wishlist.php" method="POST">' . csrf_field()
        . '<input type="hidden" name="product_id" value="' . $productId . '">'
        . '<input type="hidden" name="return" value="product.php?id=' . $productId . '">'
        . '<button type="submit" class="btn btn-ghost">' . ui_icon("heart", "1em", $on) . ($on ? " อยู่ในรายการโปรดแล้ว (เอาออก)" : " เพิ่มในรายการโปรด") . '</button></form>';
}

/* ---------- รีวิว ---------- */

/** คะแนนเฉลี่ยของทุกสินค้า: [product_id => [avg, count]] */
function product_ratings(): array
{
    global $conn;
    static $ratings = null;

    if ($ratings === null) {
        $ratings = [];
        $rows = $conn->query("SELECT product_id, AVG(rating) a, COUNT(*) c FROM reviews WHERE status = 'approved' GROUP BY product_id");
        foreach ($rows as $row) {
            $ratings[(int)$row["product_id"]] = [round((float)$row["a"], 1), (int)$row["c"]];
        }
    }

    return $ratings;
}

function stars(float $rating): string
{
    $full = (int)round($rating);

    return '<span class="stars" role="img" aria-label="คะแนน ' . $rating . ' จาก 5">'
        . str_repeat("★", $full) . '<span class="stars-off">' . str_repeat("★", 5 - $full) . '</span></span>';
}

/** ลูกค้าซื้อสินค้านี้และได้รับแล้ว (คำสั่งซื้อสำเร็จ) */
function customer_purchased(int $customerId, int $productId): bool
{
    global $conn;

    $stmt = $conn->prepare("SELECT 1 FROM orders o JOIN order_items i ON i.order_id = o.id
        WHERE o.customer_id = ? AND i.product_id = ? AND o.order_status = 'สำเร็จ' LIMIT 1");
    $stmt->execute([$customerId, $productId]);

    return (bool)$stmt->fetchColumn();
}

/* ---------- คูปอง ---------- */

function coupon_find(string $code, bool $lock = false): ?array
{
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM coupons WHERE code = ?" . ($lock ? " FOR UPDATE" : ""));
    $stmt->execute([strtoupper(trim($code))]);

    return $stmt->fetch() ?: null;
}

/** ตรวจคูปองกับยอดสินค้า: คืน ["error" => ?string, "discount" => float] */
function coupon_evaluate(?array $coupon, float $subtotal): array
{
    $fail = fn(string $m) => ["error" => $m, "discount" => 0.0];
    $today = date("Y-m-d");

    if (!$coupon || !(int)$coupon["is_active"]) {
        return $fail("ไม่พบคูปองนี้ หรือคูปองถูกปิดใช้งานแล้ว");
    }
    if ($coupon["starts_at"] !== null && $today < $coupon["starts_at"]) {
        return $fail("คูปองนี้ยังไม่เริ่มใช้งาน");
    }
    if ($coupon["expires_at"] !== null && $today > $coupon["expires_at"]) {
        return $fail("คูปองนี้หมดอายุแล้ว");
    }
    if ($coupon["max_uses"] !== null && (int)$coupon["used_count"] >= (int)$coupon["max_uses"]) {
        return $fail("คูปองนี้ถูกใช้ครบจำนวนแล้ว");
    }
    if ($subtotal < (float)$coupon["min_order"]) {
        return $fail("คูปองนี้ใช้ได้เมื่อซื้อครบ ฿" . money($coupon["min_order"]));
    }

    $discount = $coupon["discount_type"] === "percent"
        ? $subtotal * (float)$coupon["discount_value"] / 100
        : (float)$coupon["discount_value"];

    return ["error" => null, "discount" => round(min($discount, $subtotal), 2)];
}

/** คูปองในตะกร้าปัจจุบัน: คืน ["code", "discount"] หรือ null (ลบออกถ้าใช้ไม่ได้แล้ว) */
function cart_coupon(float $subtotal): ?array
{
    $code = $_SESSION["coupon_code"] ?? "";

    if ($code === "") {
        return null;
    }

    $result = coupon_evaluate(coupon_find($code), $subtotal);

    if ($result["error"] !== null) {
        unset($_SESSION["coupon_code"]);
        flash("error", "นำคูปอง {$code} ออกแล้ว: " . $result["error"]);
        return null;
    }

    return ["code" => $code, "discount" => $result["discount"]];
}

/* ---------- ประวัติสต็อก (เรียกหลังอัปเดตสต็อกแล้ว) ---------- */

function log_stock(int $productId, int $change, string $reason, ?string $reference = null, ?string $actor = null): void
{
    global $conn;

    $conn->prepare("INSERT INTO stock_movements (product_id, product_name, change_qty, stock_after, reason, reference, actor)
        SELECT id, product_name, ?, stock, ?, ?, ? FROM products WHERE id = ?")
        ->execute([$change, $reason, $reference, $actor, $productId]);
}

function admin_actor(): string
{
    return "แอดมิน: " . ($_SESSION["admin_username"] ?? "-");
}

/* ---------- ยกเลิกคำสั่งซื้อ: คืนสต็อกและสิทธิ์คูปองในรายการเดียว ---------- */

/**
 * @param bool $byCustomer true = ลูกค้ายกเลิกเอง (ได้เฉพาะที่ยังรอดำเนินการและยังไม่ยืนยันการชำระเงิน)
 * @throws RuntimeException เมื่อยกเลิกไม่ได้
 */
function cancel_order(int $orderId, string $actor, bool $byCustomer = false): string
{
    global $conn;

    $conn->beginTransaction();

    try {
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new RuntimeException("ไม่พบคำสั่งซื้อ");
        }
        if ($order["order_status"] === "ยกเลิก") {
            throw new RuntimeException("คำสั่งซื้อนี้ถูกยกเลิกไปแล้ว");
        }

        if ($byCustomer) {
            $stmt = $conn->prepare("SELECT payment_status FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$orderId]);
            if (!order_customer_cancellable($order["order_status"], $stmt->fetchColumn() ?: null)) {
                throw new RuntimeException("คำสั่งซื้อนี้ดำเนินการไปแล้ว ไม่สามารถยกเลิกเองได้ กรุณาติดต่อร้าน");
            }
        }

        // ลูกค้ายกเลิกเอง: ถือว่ารับทราบแล้ว ไม่ต้องแจ้งเตือนกลับ
        $conn->prepare("UPDATE orders SET order_status = 'ยกเลิก', status_changed_at = NOW()"
            . ($byCustomer ? ", customer_seen_at = NOW()" : "") . " WHERE id = ?")->execute([$orderId]);

        $items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $items->execute([$orderId]);
        $restock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");

        foreach ($items->fetchAll() as $item) {
            if ($item["product_id"] === null) {
                continue;
            }
            $restock->execute([(int)$item["quantity"], $item["product_id"]]);
            log_stock((int)$item["product_id"], (int)$item["quantity"], "ยกเลิกคำสั่งซื้อ", $order["order_number"], $actor);
        }

        if (!empty($order["coupon_code"])) {
            $conn->prepare("UPDATE coupons SET used_count = used_count - 1 WHERE code = ? AND used_count > 0")
                 ->execute([$order["coupon_code"]]);
        }

        $conn->commit();

        return $order["order_number"];

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function order_customer_cancellable(string $orderStatus, ?string $paymentStatus): bool
{
    return in_array($orderStatus, ["รอตรวจสอบการชำระเงิน", "รอรับสินค้าที่ร้าน"], true)
        && $paymentStatus !== "ตรวจสอบแล้ว";
}

/* ---------- แบ่งหน้า ---------- */

function paginate(int $total, int $page, int $perPage): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);

    return [$page, $pages, ($page - 1) * $perPage];
}

function pagination_html(int $page, int $pages, callable $url): string
{
    if ($pages < 2) {
        return "";
    }

    $html = '<nav class="pagination" aria-label="เปลี่ยนหน้า">';

    if ($page > 1) {
        $html .= '<a href="' . e($url($page - 1)) . '" aria-label="หน้าก่อนหน้า">‹</a>';
    }

    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= 1) {
            $html .= $i === $page
                ? '<span class="current" aria-current="page">' . $i . '</span>'
                : '<a href="' . e($url($i)) . '">' . $i . '</a>';
        } elseif (abs($i - $page) === 2) {
            $html .= '<span class="disabled">…</span>';
        }
    }

    if ($page < $pages) {
        $html .= '<a href="' . e($url($page + 1)) . '" aria-label="หน้าถัดไป">›</a>';
    }

    return $html . "</nav>";
}

/** เลขหน้าจาก query string */
function page_param(): int
{
    return max(1, (int)(filter_input(INPUT_GET, "page", FILTER_VALIDATE_INT) ?: 1));
}

/** ค้นหาแบบ LIKE ให้ % และ _ ที่ผู้ใช้พิมพ์เป็นตัวอักษรธรรมดา */
function like_pattern(string $term): string
{
    return "%" . addcslashes($term, "%_\\") . "%";
}

/* ---------- สิทธิ์เข้าดูคำสั่งซื้อ (หน้าติดตามและใบเสร็จใช้ร่วมกัน) ---------- */

/** ลูกค้าเจ้าของ, คนที่เพิ่งสั่งใน session นี้ หรือคนที่ยืนยันเบอร์โทรแล้ว */
function can_view_order(array $order): bool
{
    $customer = current_customer();

    return ($customer && (int)($order["customer_id"] ?? 0) === (int)$customer["id"])
        || ($_SESSION["last_order_number"] ?? "") === $order["order_number"]
        || !empty($_SESSION["track_ok"][$order["order_number"]]);
}

function find_order(string $number): ?array
{
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM orders WHERE order_number = ? LIMIT 1");
    $stmt->execute([$number]);

    return $stmt->fetch() ?: null;
}

function latest_payment(int $orderId): ?array
{
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orderId]);

    return $stmt->fetch() ?: null;
}

/* ---------- แจ้งเตือนลูกค้าเมื่อร้านเปลี่ยนสถานะคำสั่งซื้อ ---------- */

function order_is_unread(array $order): bool
{
    return !empty($order["status_changed_at"])
        && (empty($order["customer_seen_at"]) || $order["status_changed_at"] > $order["customer_seen_at"]);
}

/** จำนวนคำสั่งซื้อของลูกค้าที่ร้านอัปเดตแล้วลูกค้ายังไม่ได้เปิดดู */
function customer_unread_orders(): int
{
    global $conn;
    static $count = null;

    if ($count !== null) {
        return $count;
    }

    $customer = current_customer();
    $count = 0;

    if ($customer) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM orders
            WHERE customer_id = ? AND status_changed_at IS NOT NULL
              AND (customer_seen_at IS NULL OR status_changed_at > customer_seen_at)");
        $stmt->execute([$customer["id"]]);
        $count = (int)$stmt->fetchColumn();
    }

    return $count;
}

/* ---------- ราคาโปรโมชัน ---------- */

/** ราคาโปรโมชันใช้ได้วันนี้หรือไม่ (ต้องถูกกว่าราคาปกติและอยู่ในช่วงวันที่) */
function sale_active(array $product): bool
{
    if (!isset($product["sale_price"]) || $product["sale_price"] === null || $product["sale_price"] === "") {
        return false;
    }
    if ((float)$product["sale_price"] >= (float)$product["price"]) {
        return false;
    }

    $today = date("Y-m-d");

    if (!empty($product["sale_starts"]) && $today < $product["sale_starts"]) {
        return false;
    }

    return empty($product["sale_ends"]) || $today <= $product["sale_ends"];
}

/** ราคาที่ลูกค้าจ่ายจริงวันนี้ */
function effective_price(array $product): float
{
    return sale_active($product) ? (float)$product["sale_price"] : (float)$product["price"];
}

/** นิพจน์ SQL ของราคาที่จ่ายจริง สำหรับเรียงลำดับ (วันที่มาจาก date() จึงไม่ใช่ข้อมูลผู้ใช้) */
function effective_price_sql(string $table = "products"): string
{
    $today = date("Y-m-d");

    return "(CASE WHEN {$table}.sale_price IS NOT NULL AND {$table}.sale_price < {$table}.price
                   AND ({$table}.sale_starts IS NULL OR {$table}.sale_starts <= '{$today}')
                   AND ({$table}.sale_ends IS NULL OR {$table}.sale_ends >= '{$today}')
              THEN {$table}.sale_price ELSE {$table}.price END)";
}

/** ราคาแบบมีราคาเดิมขีดฆ่าเมื่อลดราคา */
function price_html(array $product, string $class = "price"): string
{
    if (!sale_active($product)) {
        return '<div class="' . $class . '">฿' . money($product["price"]) . '</div>';
    }

    $percent = (int)round((1 - (float)$product["sale_price"] / (float)$product["price"]) * 100);

    return '<div class="' . $class . ' is-sale">฿' . money($product["sale_price"])
        . ' <s aria-label="ราคาปกติ">฿' . money($product["price"]) . '</s>'
        . ' <span class="sale-pct">-' . $percent . '%</span></div>';
}

/**
 * ตรวจช่องราคาโปรโมชันจากฟอร์มสินค้า
 * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} [ข้อผิดพลาด, ราคาโปร, วันเริ่ม, วันสิ้นสุด]
 */
function parse_sale_fields(array $input, $price): array
{
    $sale  = trim((string)($input["sale_price"] ?? ""));
    $start = trim((string)($input["sale_starts"] ?? ""));
    $end   = trim((string)($input["sale_ends"] ?? ""));

    if ($sale === "") {
        return [null, null, null, null];
    }

    $validDate = fn($d) => $d === "" || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)));

    if (!is_numeric($sale) || !is_finite((float)$sale) || (float)$sale < 0) {
        return ["ราคาโปรโมชันไม่ถูกต้อง", null, null, null];
    }
    if ((float)$sale >= (float)$price) {
        return ["ราคาโปรโมชันต้องต่ำกว่าราคาปกติ", null, null, null];
    }
    if (!$validDate($start) || !$validDate($end)) {
        return ["วันที่เริ่มหรือสิ้นสุดโปรโมชันไม่ถูกต้อง", null, null, null];
    }
    if ($start !== "" && $end !== "" && $start > $end) {
        return ["วันสิ้นสุดโปรโมชันต้องไม่ก่อนวันเริ่ม", null, null, null];
    }

    return [null, number_format((float)$sale, 2, ".", ""), $start ?: null, $end ?: null];
}

/* ---------- รูปสินค้าเพิ่มเติม ---------- */

const GALLERY_MAX = 8;

function product_gallery(int $productId): array
{
    global $conn;

    $stmt = $conn->prepare("SELECT id, image FROM product_images WHERE product_id = ? ORDER BY sort_order, id");
    $stmt->execute([$productId]);

    return $stmt->fetchAll();
}

/** แปลง input แบบ multiple ให้เป็นรายการไฟล์ (ข้ามช่องที่ไม่ได้เลือกไฟล์) */
function uploaded_files(string $field): array
{
    $raw = $_FILES[$field] ?? null;

    if (!$raw || !is_array($raw["name"] ?? null)) {
        return [];
    }

    $files = [];

    foreach (array_keys($raw["name"]) as $i) {
        if (($raw["error"][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            "name"     => $raw["name"][$i],
            "tmp_name" => $raw["tmp_name"][$i],
            "error"    => $raw["error"][$i],
            "size"     => $raw["size"][$i],
        ];
    }

    return $files;
}

/** บันทึกรูปสินค้าที่อัปโหลด (ตรวจและวาดใหม่) คืนชื่อไฟล์ใหม่ หรือโยน RuntimeException */
function store_product_image(array $file): string
{
    return save_uploaded_image($file, __DIR__ . "/../assets/images/products/");
}

function delete_product_image_file(?string $name): void
{
    if ($name === null || $name === "") {
        return;
    }

    $path = __DIR__ . "/../assets/images/products/" . basename($name);

    if (is_file($path)) {
        unlink($path);
    }
}

/* ---------- ผู้ดูแลระบบ: สิทธิ์และบันทึกกิจกรรม ---------- */

const ADMIN_ROLES = [
    "owner" => "เจ้าของร้าน",
    "staff" => "พนักงาน",
];

function is_owner(): bool
{
    return ($_SESSION["admin_role"] ?? "") === "owner";
}

/** หน้าที่เจ้าของร้านเท่านั้นเข้าได้ (รายงาน คูปอง ตั้งค่า ผู้ดูแล บันทึกกิจกรรม) */
function require_owner(): void
{
    if (!is_owner()) {
        flash("error", "หน้านี้สำหรับเจ้าของร้านเท่านั้น");
        redirect("dashboard.php");
    }
}

/**
 * บันทึกว่าใครทำอะไร (ล้มเหลวก็ไม่ขัดงานหลัก)
 * @param ?string $actorName ระบุชื่อผู้กระทำเอง เมื่อยังไม่ได้เข้าสู่ระบบ เช่น พยายามเดารหัสผ่าน
 */
function admin_log(string $action, string $detail = "", ?string $actorName = null): void
{
    global $conn;

    try {
        $conn->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, detail, ip) VALUES (?, ?, ?, ?, ?)")
             ->execute([
                 $actorName === null ? ($_SESSION["admin_id"] ?? null) : null,
                 mb_substr($actorName ?? (string)($_SESSION["admin_username"] ?? "-"), 0, 50),
                 mb_substr($action, 0, 50),
                 mb_substr($detail, 0, 500),
                 client_ip(),
             ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
    }
}

/** เข้าสู่ระบบแอดมินสำเร็จ (หลังผ่านรหัสผ่านและ 2FA แล้ว): สร้าง session ใหม่และผูกกับเบราว์เซอร์ */
function admin_login_finish(array $admin): void
{
    global $conn;

    session_regenerate_id(true);
    csrf_rotate();
    unset($_SESSION["admin_2fa"]);

    $now = time();
    $_SESSION["admin_id"]       = (int)$admin["id"];
    $_SESSION["admin_username"] = $admin["username"];
    $_SESSION["admin_name"]     = $admin["fullname"];
    $_SESSION["admin_role"]     = $admin["role"];
    $_SESSION["admin_start"]    = $now;
    $_SESSION["admin_last"]     = $now;
    $_SESSION["admin_ua"]       = user_agent_hash();
    $_SESSION["admin_pwfp"]     = password_fingerprint($admin["password"]);

    $conn->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = ?")->execute([$admin["id"]]);
    admin_log("เข้าสู่ระบบ");
}

/** เข้าสู่ระบบ/สมัครสมาชิกสำเร็จ */
function customer_login_finish(int $customerId, string $passwordHash): void
{
    session_regenerate_id(true);
    csrf_rotate();

    $_SESSION["customer_id"]   = $customerId;
    $_SESSION["customer_last"] = time();
    $_SESSION["customer_pwfp"] = password_fingerprint($passwordHash);
}

/** รหัสผ่านชั่วคราว อ่านง่าย ไม่มีตัวที่สับสน เช่น 0/o, 1/l */
function temp_password(int $length = 10): string
{
    $alphabet = "abcdefghjkmnpqrstuvwxyz23456789";
    $password = "";

    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $password;
}

/* ---------- พร้อมเพย์: สร้างข้อมูล QR ตามยอด (มาตรฐาน EMVCo ของ ธปท.) ---------- */

/** เบอร์มือถือ 10 หลัก, เลขบัตร/ผู้เสียภาษี 13 หลัก หรือ e-Wallet 15 หลัก */
function promptpay_normalize(string $id): ?string
{
    $digits = preg_replace('/\D+/', '', $id);

    if (strlen($digits) === 10 && $digits[0] === "0") {
        return $digits;
    }

    return in_array(strlen($digits), [13, 15], true) ? $digits : null;
}

function crc16_ccitt(string $data): string
{
    $crc = 0xFFFF;

    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
    }

    return sprintf("%04X", $crc);
}

function promptpay_payload(string $id, ?float $amount = null): ?string
{
    $target = promptpay_normalize($id);

    if ($target === null) {
        return null;
    }

    $tlv = fn(string $tag, string $value) => $tag . sprintf("%02d", strlen($value)) . $value;

    if (strlen($target) === 10) {
        $account = $tlv("01", "0066" . substr($target, 1));
    } elseif (strlen($target) === 13) {
        $account = $tlv("02", $target);
    } else {
        $account = $tlv("03", $target);
    }

    $withAmount = $amount !== null && $amount > 0;

    $payload = $tlv("00", "01")
        . $tlv("01", $withAmount ? "12" : "11")
        . $tlv("29", $tlv("00", "A000000677010111") . $account)
        . $tlv("58", "TH")
        . $tlv("53", "764")
        . ($withAmount ? $tlv("54", number_format($amount, 2, ".", "")) : "")
        . "6304";

    return $payload . crc16_ccitt($payload);
}

/** แสดงเลขพร้อมเพย์แบบซ่อนบางส่วน เช่น xxx-xxx-5678 */
function promptpay_masked(string $id): string
{
    $digits = promptpay_normalize($id) ?? "";

    return $digits === "" ? "" : str_repeat("x", strlen($digits) - 4) . substr($digits, -4);
}

/** กล่อง QR พร้อมเพย์ตามยอด (ถ้าร้านตั้งค่าเลขพร้อมเพย์ไว้) */
function promptpay_block(float $amount): string
{
    $payload = promptpay_payload(setting("promptpay_id"), $amount);

    if ($payload === null || $amount <= 0) {
        return "";
    }

    return '<div class="pp-box">'
        . '<div class="pp-qr" data-payload="' . e($payload) . '" data-lib="' . e(QR_LIBRARY_URL) . '" role="img" aria-label="QR พร้อมเพย์ยอด ' . money($amount) . ' บาท"></div>'
        . '<p class="pp-fallback" hidden>แสดง QR ไม่ได้ กรุณาโอนตามเลขบัญชีด้านบน</p>'
        . '<div class="pp-caption"><strong>สแกนจ่าย ฿' . money($amount) . '</strong>'
        . '<span>พร้อมเพย์ ' . e(promptpay_masked(setting("promptpay_id")))
        . (setting("bank_holder") !== "" ? " · " . e(setting("bank_holder")) : "") . '</span></div>'
        . '</div>';
}

/** สคริปต์สร้างภาพ QR (โหลดเฉพาะหน้าที่มี QR) */
const QR_LIBRARY_URL = "https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js";


/* ======================================================================
   การจัดส่ง: ค่าส่ง บริษัทขนส่ง เลขพัสดุ
   ====================================================================== */

/** บริษัทขนส่ง => [ชื่อ, ลิงก์ติดตาม ({n} = เลขพัสดุ) หรือ null] */
const CARRIERS = [
    "thaipost" => ["ไปรษณีย์ไทย", "https://track.thailandpost.co.th/?trackNumber={n}"],
    "kerry"    => ["Kerry Express (KEX)", "https://th.kex-express.com/th/track/?track={n}"],
    "flash"    => ["Flash Express", "https://www.flashexpress.co.th/fle/tracking?se={n}"],
    "jt"       => ["J&T Express", "https://www.jtexpress.co.th/service/track"],
    "best"     => ["BEST Express", null],
    "spx"      => ["SPX Express", null],
    "dhl"      => ["DHL eCommerce", null],
    "shop"     => ["ร้านจัดส่งเอง", null],
    "other"    => ["อื่น ๆ", null],
];

/** วิธีรับสินค้าที่ต้องจัดส่ง (ต้องมีที่อยู่ และอาจมีค่าส่ง) */
function delivery_ships(?string $method): bool
{
    return in_array($method, [DELIVERY_DEPT, DELIVERY_PARCEL], true);
}

function parcel_enabled(): bool
{
    return setting("parcel_enabled") === "1";
}

/** ค่าเงินจากหน้าตั้งค่า (ไม่ติดลบ ทศนิยม 2 ตำแหน่ง) */
function setting_money(string $key): float
{
    $value = (float)setting($key);

    return $value > 0 ? round($value, 2) : 0.0;
}

/** ค่าจัดส่งตามวิธีรับสินค้า; $goodsAmount = ยอดสินค้าหลังหักส่วนลด */
function shipping_fee_for(string $method, float $goodsAmount): float
{
    $fee = match ($method) {
        DELIVERY_DEPT   => setting_money("ship_fee_dept"),
        DELIVERY_PARCEL => setting_money("ship_fee_parcel"),
        default         => 0.0,
    };

    $freeMin = setting_money("ship_free_min");

    if ($fee > 0 && $freeMin > 0 && $goodsAmount >= $freeMin) {
        return 0.0;
    }

    return $fee;
}

function carrier_label(?string $key): string
{
    return CARRIERS[(string)$key][0] ?? "";
}

/** ลิงก์ไปหน้าติดตามพัสดุของบริษัทขนส่ง (null = ไม่มีหน้าติดตามที่ใส่เลขในลิงก์ได้) */
function tracking_url(?string $carrier, ?string $number): ?string
{
    $template = CARRIERS[(string)$carrier][1] ?? null;

    if ($template === null || (string)$number === "") {
        return null;
    }

    return str_replace("{n}", rawurlencode((string)$number), $template);
}

/** เลขพัสดุ: ตัวอักษรอังกฤษ ตัวเลข และขีด 6-60 ตัว */
function normalize_tracking(string $number): ?string
{
    $number = strtoupper((string)preg_replace('/\s+/', '', $number));

    return preg_match('/^[A-Z0-9\-]{6,60}$/', $number) ? $number : null;
}

/* ======================================================================
   เปลี่ยนสถานะคำสั่งซื้อ (ใช้ทั้งทีละรายการและแบบกลุ่ม)
   ====================================================================== */

/**
 * เปลี่ยนสถานะคำสั่งซื้อพร้อมผลข้างเคียงที่ถูกต้อง (คืนสต็อกเมื่อยกเลิก, ปิดยอดชำระเมื่อสำเร็จ)
 * @return string|null เลขที่คำสั่งซื้อที่เปลี่ยน หรือ null ถ้าเป็นสถานะนั้นอยู่แล้ว
 * @throws RuntimeException ข้อความที่แสดงให้ผู้ดูแลเห็นได้
 */
function order_change_status(int $orderId, string $newStatus): ?string
{
    global $conn;

    if (!in_array($newStatus, ORDER_STATUSES, true)) {
        throw new RuntimeException("สถานะไม่ถูกต้อง");
    }

    // ยกเลิก: คืนสต็อกและสิทธิ์คูปองผ่านฟังก์ชันกลาง
    if ($newStatus === "ยกเลิก") {
        $current = $conn->prepare("SELECT order_status FROM orders WHERE id = ?");
        $current->execute([$orderId]);

        if ($current->fetchColumn() === "ยกเลิก") {
            return null;
        }

        $number = cancel_order($orderId, admin_actor());
        admin_log("ยกเลิกคำสั่งซื้อ", $number);

        return $number;
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare("SELECT id, order_number, order_status FROM orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new RuntimeException("ไม่พบคำสั่งซื้อ");
        }

        $oldStatus = $order["order_status"];

        if ($oldStatus === $newStatus) {
            $conn->rollBack();
            return null;
        }

        if ($oldStatus === "ยกเลิก") {
            throw new RuntimeException("คำสั่งซื้อ {$order["order_number"]} ถูกยกเลิกแล้ว เปลี่ยนสถานะไม่ได้ (สต็อกถูกคืนแล้ว)");
        }

        $conn->prepare("UPDATE orders
                        SET order_status = ?, status_changed_at = NOW(),
                            shipped_at = CASE WHEN ? = 'จัดส่งแล้ว' THEN COALESCE(shipped_at, NOW()) ELSE shipped_at END
                        WHERE id = ?")
             ->execute([$newStatus, $newStatus, $orderId]);

        // สำเร็จ → ถือว่าได้รับชำระเงินแล้ว (เฉพาะแถวล่าสุด ไม่แตะประวัติสลิปที่เคยไม่ผ่าน)
        if ($newStatus === "สำเร็จ") {
            $conn->prepare("
                UPDATE payments
                SET payment_status = 'ตรวจสอบแล้ว', paid_at = COALESCE(paid_at, ?)
                WHERE id = (SELECT id FROM (SELECT MAX(id) id FROM payments WHERE order_id = ?) x)
            ")->execute([date("Y-m-d H:i:s"), $orderId]);
        }

        $conn->commit();

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    admin_log("เปลี่ยนสถานะคำสั่งซื้อ", "{$order["order_number"]}: {$oldStatus} → {$newStatus}");

    return $order["order_number"];
}

/* ======================================================================
   CSV
   ====================================================================== */

/** กัน CSV/Excel formula injection จากข้อมูลที่ผู้ใช้กรอก */
function csv_cell($value)
{
    if (is_string($value) && $value !== "" && strpos("=+-@\t\r", $value[0]) !== false && !is_numeric($value)) {
        return "'" . $value;
    }

    return $value;
}

/** เริ่มส่งไฟล์ CSV ที่เปิดใน Excel ภาษาไทยได้ (มี BOM) */
function csv_begin(string $filename)
{
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^A-Za-z0-9_.\-]/', "_", $filename) . "\"");
    header("Cache-Control: no-store");

    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF");

    return $out;
}

/* ======================================================================
   ปิดปรับปรุงหน้าร้าน
   ====================================================================== */

function maintenance_on(): bool
{
    return setting("maintenance_mode") === "1";
}

/** เรียกทุกคำขอ: ถ้าปิดปรับปรุง ลูกค้าเห็นหน้าแจ้ง 503 (หลังบ้านและผู้ดูแลที่ล็อกอินอยู่ใช้งานได้ตามปกติ) */
function maintenance_gate(): void
{
    if (PHP_SAPI === "cli" || !maintenance_on() || !empty($_SESSION["admin_id"])) {
        return;
    }

    $script = realpath((string)($_SERVER["SCRIPT_FILENAME"] ?? ""));
    $admin  = realpath(__DIR__ . "/../admin");

    if ($script !== false && $admin !== false && str_starts_with($script, $admin . DIRECTORY_SEPARATOR)) {
        return;
    }

    $message = trim(setting("maintenance_message"));
    $phone   = setting("shop_phone");
    $line    = setting("shop_line");

    http_response_code(503);
    header("Retry-After: 3600");
    header("Content-Type: text/html; charset=UTF-8");
    header("Cache-Control: no-store");

    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");
    ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>ปิดปรับปรุงชั่วคราว | <?= $esc(SHOP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg?v=2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;600&family=Prompt:wght@600&display=swap" rel="stylesheet">
    <style nonce="<?= $esc(csp_nonce()) ?>">
        :root { --bg: #f7f4ee; --card: #fff; --text: #2d3a47; --muted: #5f6873; --line: rgba(31,42,55,.12); --accent: #0f766e; }
        @media (prefers-color-scheme: dark) { :root { --bg: #161b21; --card: #1f262e; --text: #e8e4dc; --muted: #a7afb8; --line: rgba(255,255,255,.1); --accent: #5ec4b6; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text); font-family: "Noto Sans Thai", Tahoma, sans-serif; line-height: 1.7; }
        main { width: min(100%, 480px); background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 36px 28px; text-align: center; }
        img { width: 88px; height: 88px; }
        h1 { font-family: Prompt, sans-serif; font-weight: 600; font-size: 24px; margin: 12px 0 6px; }
        p { margin: 0 0 8px; color: var(--muted); }
        .contact { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--line); font-size: 14px; }
        .contact strong { color: var(--text); }
        .shop { margin-top: 14px; font-size: 13px; }
    </style>
</head>
<body>
<main>
    <img src="assets/brand/mascot.svg" alt="" width="88" height="88">
    <h1>ร้านปิดปรับปรุงชั่วคราว</h1>
    <p><?= $message !== "" ? nl2br($esc($message)) : "เรากำลังปรับปรุงระบบให้ดียิ่งขึ้น กรุณากลับมาใหม่อีกครั้งในภายหลัง" ?></p>
    <?php if ($phone !== "" || $line !== ""): ?>
        <div class="contact">
            ติดต่อร้าน
            <?php if ($phone !== ""): ?> · โทร <strong><?= $esc($phone) ?></strong><?php endif; ?>
            <?php if ($line !== ""): ?> · <strong><?= $esc($line) ?></strong><?php endif; ?>
        </div>
    <?php endif; ?>
    <p class="shop"><?= $esc(SHOP_NAME) ?></p>
</main>
</body>
</html>
    <?php
    exit;
}

/* ======================================================================
   สำรองฐานข้อมูลเป็นไฟล์ .sql (ไม่ต้องใช้ mysqldump จึงใช้ได้บนโฮสต์ทั่วไป)
   ====================================================================== */

/** เขียนคำสั่ง SQL ของทุกตารางลง $out ทีละช่วง ไม่โหลดทั้งตารางเข้าหน่วยความจำ */
function database_dump($out): array
{
    global $conn;

    $tables = [];
    foreach ($conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = $row[0];
    }

    $dbName = (string)$conn->query("SELECT DATABASE()")->fetchColumn();

    fwrite($out, "-- " . SHOP_NAME . " : สำรองฐานข้อมูล `{$dbName}`\n");
    fwrite($out, "-- สร้างเมื่อ " . date("Y-m-d H:i:s") . " (เวลาไทย)\n");
    fwrite($out, "-- กู้คืน: นำเข้าไฟล์นี้ใน phpMyAdmin (แท็บ Import) ข้อมูลเดิมในตารางที่ชื่อตรงกันจะถูกแทนที่\n\n");
    fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");

    // ให้ค่า TIMESTAMP ออกมาเป็น UTC ตรงกับ time_zone ที่ตั้งไว้ตอนกู้คืน
    $originalZone = (string)$conn->query("SELECT @@session.time_zone")->fetchColumn();
    $conn->exec("SET time_zone = '+00:00'");

    $rowsTotal = 0;
    $batch     = 500;

    try {
        foreach ($tables as $table) {
            $quoted = "`" . str_replace("`", "``", $table) . "`";
            $create = $conn->query("SHOW CREATE TABLE $quoted")->fetch(PDO::FETCH_NUM)[1];

            fwrite($out, "-- --------------------------------------------------\n-- ตาราง $quoted\n\n");
            fwrite($out, "DROP TABLE IF EXISTS $quoted;\n$create;\n\n");

            for ($offset = 0; ; $offset += $batch) {
                $rows = $conn->query("SELECT * FROM $quoted LIMIT $batch OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

                if (!$rows) {
                    break;
                }

                $columns = "(" . implode(", ", array_map(fn($c) => "`" . str_replace("`", "``", $c) . "`", array_keys($rows[0]))) . ")";
                $values  = [];

                foreach ($rows as $row) {
                    $values[] = "(" . implode(", ", array_map(fn($v) => $v === null ? "NULL" : $conn->quote((string)$v), $row)) . ")";
                }

                fwrite($out, "INSERT INTO $quoted $columns VALUES\n" . implode(",\n", $values) . ";\n");
                $rowsTotal += count($rows);

                if (count($rows) < $batch) {
                    break;
                }
            }

            fwrite($out, "\n");
        }
    } finally {
        $conn->prepare("SET time_zone = ?")->execute([$originalZone]);
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");

    return ["tables" => count($tables), "rows" => $rowsTotal];
}
