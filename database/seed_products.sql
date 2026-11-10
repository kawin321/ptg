-- เพิ่มสินค้าจากรูปใน assets/images/products (รันซ้ำได้ ไม่เกิดรายการซ้ำ)
-- ราคาและจำนวนเป็นค่าทดลอง แก้ได้ที่หลังบ้าน > จัดการสินค้า
SET NAMES utf8mb4;

INSERT INTO categories (category_name)
SELECT 'กระเป๋าและกล่องดินสอ' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category_name = 'กระเป๋าและกล่องดินสอ');

INSERT INTO products (category_id, product_name, description, price, stock, image)
SELECT c.id, s.name, s.descr, s.price, s.stock, s.image
FROM (
    SELECT 'ปากกาและดินสอ' cat, 'ปากกาลูกลื่น สีน้ำเงิน' name, 'ปากกาลูกลื่นด้ามใส หมึกสีน้ำเงิน' descr, 10.00 price, 50 stock, '04a1a2872b164881dda7862195dee045.jpg' image
    UNION ALL SELECT 'ปากกาและดินสอ', 'ปากกาลูกลื่น สีแดง', 'ปากกาลูกลื่นด้ามใส หมึกสีแดง', 10.00, 50, '5fe7bcd3968077f7b7250188b851f139.jpg'
    UNION ALL SELECT 'ปากกาและดินสอ', 'ปากกาลูกลื่น สีดำ', 'ปากกาลูกลื่นด้ามใส หมึกสีดำ', 10.00, 50, '9b39f3c07b6704940c4a1bb809887bb9.jpg'
    UNION ALL SELECT 'ปากกาและดินสอ', 'ปากกาหมึกเจลแบบกด ชุด 5 สี', 'ปากกาแบบกด ชุด 5 สี', 89.00, 20, 'f3b599db03cfd6cff0ae3cc96514428e.jpg'
    UNION ALL SELECT 'ปากกาและดินสอ', 'ดินสอไม้ สีน้ำเงิน พร้อมกล่อง', 'ชุดดินสอไม้ในกล่องสีน้ำเงิน', 59.00, 20, '04a2ed091ee417f090b9195da2d8e653.jpg'
    UNION ALL SELECT 'ปากกาและดินสอ', 'ปากกาเน้นข้อความ STABILO BOSS สีฟ้า', 'ปากกาเน้นข้อความสีฟ้าพาสเทล', 35.00, 30, '777fab149119b1477ef7ac1df4d22856.jpg'
    UNION ALL SELECT 'สมุดและกระดาษ', 'สมุดโน้ตปกหนัง', 'สมุดโน้ตปกหนังมีหลายสี', 129.00, 20, '1fd2678ad5941d2d77bf16c3f5f4ed42.jpg'
    UNION ALL SELECT 'สมุดและกระดาษ', 'กระดาษโน้ตกาวในตัว คละสี', 'กระดาษโน้ตกาวในตัวสีสดใส', 45.00, 40, '94db689c72c53a173b43fb3dd3ce03bb.jpg'
    UNION ALL SELECT 'สมุดและกระดาษ', 'สติกเกอร์ฉลากลายน่ารัก', 'สติกเกอร์ฉลากเขียนชื่อ ลายจุดและลายตาราง', 29.00, 30, 'eb747f6ec6f67a627412eb40d0f91ad1.jpg'
    UNION ALL SELECT 'อุปกรณ์ศิลปะ', 'ชุดสีอะคริลิก 42 ชิ้น', 'ชุดสีอะคริลิก พร้อมพู่กัน เฟรมผ้าใบ และจานสี', 590.00, 10, '060cfec218b75c9b4989795fce4123c3.jpg'
    UNION ALL SELECT 'อุปกรณ์ศิลปะ', 'ชุดพู่กันศิลปะ', 'ชุดพู่กันหลายขนาด สำหรับระบายสี', 120.00, 15, '3605dd5df782d5b4b0505a44c0a35722.jpg'
    UNION ALL SELECT 'อุปกรณ์ศิลปะ', 'สีน้ำแบบหลอด Marie''s 12 สี', 'ชุดสีแบบหลอด 12 สี', 159.00, 15, '3c2d5719dbb9a9e3bc3eb459f9dfcded.jpg'
    UNION ALL SELECT 'อุปกรณ์ศิลปะ', 'ดินสอสีไม้ Faber-Castell', 'ดินสอสีไม้ในกล่อง หลากสี', 99.00, 20, '3da240e03b3e0571504e52a5fb27978d.jpg'
    UNION ALL SELECT 'อุปกรณ์สำนักงาน', 'กรรไกร Westcott ด้ามสีน้ำเงิน', 'กรรไกรด้ามพลาสติกสีน้ำเงิน', 65.00, 25, '55d9b9183d49144fdbfd8e856ea76ad3.jpg'
    UNION ALL SELECT 'อุปกรณ์สำนักงาน', 'ไม้บรรทัดเหล็ก', 'ไม้บรรทัดเหล็กสเตนเลส', 35.00, 30, '7a43546602e8140d337565d73f08516d.jpg'
    UNION ALL SELECT 'อุปกรณ์สำนักงาน', 'เครื่องคิดเลขตั้งโต๊ะ', 'เครื่องคิดเลขขนาดเล็ก', 199.00, 10, '81bb18dad0e1c81663e26495890f2af2.jpg'
    UNION ALL SELECT 'อุปกรณ์สำนักงาน', 'กาวแท่ง Elmer''s', 'กาวแท่ง Washable School Glue', 39.00, 30, '8fd45abbdbf75319e4ee319cc3a2f12c.jpg'
    UNION ALL SELECT 'อุปกรณ์สำนักงาน', 'ยางลบ Faber-Castell', 'ยางลบ PVC-Free', 15.00, 50, '96b0fb3d899d88fcf0f97d88baa80dd6.jpg'
    UNION ALL SELECT 'อุปกรณ์สำนักงาน', 'เทปลบคำผิด ลายพาสเทล', 'เทปลบคำผิดลายพาสเทล', 35.00, 30, 'ddf2a70bbbef7e97fb8d5a295dd41d32.jpg'
    UNION ALL SELECT 'กระเป๋าและกล่องดินสอ', 'กระเป๋าเป้ผ้า สีดำ', 'กระเป๋าเป้ผ้าสีดำ', 390.00, 10, 'd20f18249c23953f48bb9367a1000e68.jpg'
    UNION ALL SELECT 'กระเป๋าและกล่องดินสอ', 'กระเป๋าเป้ผ้า สีครีม', 'กระเป๋าเป้ผ้าสีครีม', 390.00, 10, 'ff3b5e9a5854822142e7269abb905cc9.jpg'
    UNION ALL SELECT 'กระเป๋าและกล่องดินสอ', 'กล่องดินสอผ้า สีน้ำเงิน', 'กระเป๋าดินสอผ้าสีน้ำเงิน มีหลายช่อง', 120.00, 20, 'd3cbf9cf74b1e72bb59591b2fda034e9.jpg'
) s
JOIN categories c ON c.category_name = s.cat COLLATE utf8mb4_unicode_ci
WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.image = s.image COLLATE utf8mb4_unicode_ci);
