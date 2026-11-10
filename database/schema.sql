-- ==========================================================
-- โครงสร้างฐานข้อมูล 3 ซ่าเครื่องเขียน
-- สร้างจากการใช้งานจริงในโค้ด (ใช้สำหรับติดตั้งใหม่ / เทียบกับฐานข้อมูลเดิม)
-- ถ้าฐานข้อมูลเดิมของคุณใช้งานได้อยู่แล้ว ไม่ต้องรันไฟล์นี้
-- ==========================================================

-- ติดตั้งในเครื่อง: สร้างฐาน project_3ptg เองก่อน แล้วเลือกฐานนั้นค่อย Import
-- (บนโฮสต์ฟรีสร้างฐานไม่ได้ ใช้ฐานที่โฮสต์ให้ เช่น if0_xxxx_ptg จึงไม่มีคำสั่ง CREATE DATABASE / USE ในไฟล์นี้)
-- CREATE DATABASE project_3ptg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(50)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    fullname   VARCHAR(150) NOT NULL DEFAULT '',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS products (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    category_id  INT NULL,
    product_name VARCHAR(200) NOT NULL,
    description  TEXT NULL,
    price        DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock        INT NOT NULL DEFAULT 0,
    image        VARCHAR(255) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id)
        REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_number    VARCHAR(40)   NOT NULL UNIQUE,
    customer_name   VARCHAR(150)  NOT NULL,
    phone           VARCHAR(20)   NOT NULL,
    address         TEXT          NULL,
    delivery_method VARCHAR(50)   NOT NULL DEFAULT 'รับที่ร้าน',
    total_amount    DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method  VARCHAR(50)   NOT NULL,
    order_status    VARCHAR(50)   NOT NULL DEFAULT 'รอตรวจสอบการชำระเงิน',
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS order_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT NOT NULL,
    product_id   INT NULL,
    product_name VARCHAR(200) NOT NULL,
    price        DECIMAL(10,2) NOT NULL,
    quantity     INT NOT NULL,
    subtotal     DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    order_id       INT NOT NULL,
    payment_method VARCHAR(50)   NOT NULL,
    payment_amount DECIMAL(10,2) NOT NULL,
    slip_image     VARCHAR(255)  NULL,
    payment_status VARCHAR(50)   NOT NULL DEFAULT 'รอตรวจสอบ',
    paid_at        DATETIME      NULL,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- หมวดหมู่ตัวอย่าง (เฉพาะติดตั้งใหม่)
INSERT INTO categories (category_name)
SELECT * FROM (SELECT 'สมุดและกระดาษ' UNION SELECT 'ปากกาและดินสอ' UNION SELECT 'อุปกรณ์ศิลปะ' UNION SELECT 'อุปกรณ์สำนักงาน') AS t
WHERE NOT EXISTS (SELECT 1 FROM categories);

-- ตั้งค่าร้าน (สร้างอัตโนมัติโดยโค้ดเมื่อเปิดเว็บ ไม่ต้องรันเองก็ได้)
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NULL
);

-- ตารางของระบบบัญชีลูกค้า รายการโปรด รีวิว คูปอง ประวัติสต็อก และการจำกัดล็อกอิน
-- ถูกสร้างอัตโนมัติเมื่อเปิดเว็บ (ดู run_migrations ใน includes/shop.php)
-- รุ่น 3 (แอดมินหลายคน บันทึกกิจกรรม ราคาโปรโมชัน รูปเพิ่มเติม และแจ้งเตือนสถานะลูกค้า)
-- ถูกเพิ่มอัตโนมัติเช่นกันเมื่อเปิดเว็บ ไม่ต้องรันเอง
-- รุ่น 5 (ค่าจัดส่ง บริษัทขนส่ง เลขพัสดุ: orders.shipping_fee, carrier, tracking_number, shipped_at)
-- ถูกเพิ่มอัตโนมัติเมื่อเปิดเว็บครั้งแรกหลังอัปเดตโค้ด
