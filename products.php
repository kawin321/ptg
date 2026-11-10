<?php
require_once __DIR__ . "/includes/functions.php";
require_once __DIR__ . "/includes/product-card.php";

const PER_PAGE = 12;

$search     = trim($_GET["search"] ?? "");
$categoryId = filter_input(INPUT_GET, "category", FILTER_VALIDATE_INT) ?: 0;
$sort       = $_GET["sort"] ?? "newest";
$page       = max(1, (int)(filter_input(INPUT_GET, "page", FILTER_VALIDATE_INT) ?: 1));
$onlyInStock = isset($_GET["instock"]);
$onlySale    = isset($_GET["sale"]);

$categories = $conn->query("SELECT * FROM categories ORDER BY id ASC")->fetchAll();

$orderBy = [
    "newest"     => "products.id DESC",
    "price_asc"  => effective_price_sql() . " ASC, products.id DESC",
    "price_desc" => effective_price_sql() . " DESC, products.id DESC",
    "name"       => "products.product_name ASC",
    "best"       => "(SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.product_id = products.id AND o.order_status <> 'ยกเลิก') DESC, products.id DESC",
];
if (!isset($orderBy[$sort])) {
    $sort = "newest";
}

$where  = "WHERE 1=1";
$params = [];

if ($search !== "") {
    $where   .= " AND (products.product_name LIKE ? OR products.description LIKE ?)";
    $like     = "%" . addcslashes($search, "%_\\") . "%";
    $params[] = $like;
    $params[] = $like;
}

if ($categoryId) {
    $where   .= " AND products.category_id = ?";
    $params[] = $categoryId;
}

if ($onlyInStock) {
    $where .= " AND products.stock > 0";
}

if ($onlySale) {
    $where .= " AND " . effective_price_sql() . " < products.price";
}

// นับจำนวนทั้งหมด
$stmt = $conn->prepare("SELECT COUNT(*) FROM products $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($total / PER_PAGE));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * PER_PAGE;

$stmt = $conn->prepare("
    SELECT products.*, categories.category_name
    FROM products
    LEFT JOIN categories ON products.category_id = categories.id
    $where
    ORDER BY {$orderBy[$sort]}
    LIMIT " . PER_PAGE . " OFFSET " . $offset
);
$stmt->execute($params);
$products = $stmt->fetchAll();

// ชื่อหมวดหมู่ที่เลือก
$currentName = null;
foreach ($categories as $cat) {
    if ((int)$cat["id"] === $categoryId) {
        $currentName = $cat["category_name"];
    }
}

// สร้าง URL สำหรับแบ่งหน้า / กลับมาหน้าเดิม
$query = array_filter([
    "search"   => $search,
    "category" => $categoryId ?: null,
    "sort"     => $sort !== "newest" ? $sort : null,
    "instock"  => $onlyInStock ? 1 : null,
    "sale"     => $onlySale ? 1 : null,
]);
$pageUrl = function (int $p) use ($query): string {
    return "products.php?" . http_build_query($query + ["page" => $p]);
};
$returnUrl = "products.php" . ($query || $page > 1 ? "?" . http_build_query($query + ($page > 1 ? ["page" => $page] : [])) : "");

$pageTitle       = $currentName ? "หมวด" . $currentName : "สินค้าทั้งหมด";
$activePage      = "products";
$showCategoryNav = true;
$currentCategory = $categoryId;
require __DIR__ . "/includes/header.php";
?>

<div class="container">

    <div class="page-head">
        <h1><?= $currentName ? e($currentName) : "สินค้าทั้งหมด" ?></h1>
        <p>เลือกชมเครื่องเขียนและอุปกรณ์การเรียนของเรา</p>
    </div>

    <form action="products.php" method="GET" class="filter-bar" role="search">
        <div class="search-field">
            <input type="search" name="search" placeholder="ค้นหาสินค้า" value="<?= e($search) ?>" aria-label="ค้นหาสินค้า">
        </div>

        <select name="category" aria-label="หมวดหมู่">
            <option value="">ทุกหมวดหมู่</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= (int)$cat["id"] ?>" <?= $categoryId === (int)$cat["id"] ? "selected" : "" ?>>
                    <?= e($cat["category_name"]) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="sort" aria-label="เรียงลำดับ">
            <option value="newest" <?= $sort === "newest" ? "selected" : "" ?>>ใหม่ล่าสุด</option>
            <option value="best" <?= $sort === "best" ? "selected" : "" ?>>ขายดี</option>
            <option value="price_asc" <?= $sort === "price_asc" ? "selected" : "" ?>>ราคาต่ำ → สูง</option>
            <option value="price_desc" <?= $sort === "price_desc" ? "selected" : "" ?>>ราคาสูง → ต่ำ</option>
            <option value="name" <?= $sort === "name" ? "selected" : "" ?>>ชื่อ ก → ฮ</option>
        </select>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">ค้นหา</button>
            <a href="products.php" class="btn btn-light">ล้าง</a>
        </div>

        <div class="filter-checks">
            <label><input type="checkbox" name="instock" value="1" <?= $onlyInStock ? "checked" : "" ?>> แสดงเฉพาะสินค้าที่มีของ</label>
            <label><input type="checkbox" name="sale" value="1" <?= $onlySale ? "checked" : "" ?>> เฉพาะสินค้าลดราคา</label>
        </div>
    </form>

    <div class="result-info">
        <span>พบสินค้า <strong><?= number_format($total) ?></strong> รายการ
            <?php if ($search !== ""): ?> สำหรับ “<?= e($search) ?>”<?php endif; ?>
        </span>
        <?php if ($totalPages > 1): ?><span>หน้า <?= $page ?> / <?= $totalPages ?></span><?php endif; ?>
    </div>

    <div class="product-grid">
        <?php if ($products): ?>
            <?php foreach ($products as $product): ?>
                <?php render_product_card($product, $returnUrl); ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="big"><?= ui_icon("search") ?></div>
                <h3>ไม่พบสินค้าที่ค้นหา</h3>
                <p>ลองใช้คำค้นอื่น หรือเลือกหมวดหมู่ใหม่</p>
                <a href="products.php" class="btn btn-primary">ดูสินค้าทั้งหมด</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="เปลี่ยนหน้า">
            <?php if ($page > 1): ?>
                <a href="<?= e($pageUrl($page - 1)) ?>" aria-label="หน้าก่อนหน้า">‹</a>
            <?php else: ?>
                <span class="disabled">‹</span>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?>
                    <?php if ($i === $page): ?>
                        <span class="current" aria-current="page"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= e($pageUrl($i)) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php elseif (abs($i - $page) === 2): ?>
                    <span class="disabled">…</span>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= e($pageUrl($page + 1)) ?>" aria-label="หน้าถัดไป">›</a>
            <?php else: ?>
                <span class="disabled">›</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

</div>

<?php require __DIR__ . "/includes/footer.php"; ?>
