<?php
/**
 * การ์ดสินค้า ใช้ร่วมกันหลายหน้า
 * @param array  $product  แถวจากตาราง products (join category_name)
 * @param string $return   หน้าที่จะกลับไปหลังเพิ่มลงตะกร้า
 */
function render_product_card(array $product, string $return = "products.php"): void
{
    $id    = (int)$product["id"];
    $stock = (int)$product["stock"];
    $img   = product_image($product["image"] ?? null);
    $cat   = $product["category_name"] ?? null;
    $url   = "product.php?id=" . $id;
    ?>
    <article class="product-card">
        <?= wishlist_button($id, $return) ?>
        <a href="<?= $url ?>" class="product-thumb <?= $stock < 1 ? "is-out" : "" ?>" tabindex="-1" aria-hidden="true">
            <?php if ($img): ?>
                <img src="<?= e($img) ?>" alt="" loading="lazy">
            <?php else: ?>
                <div class="no-image"><?= category_icon($cat) ?></div>
            <?php endif; ?>

            <?php if ($stock < 1): ?>
                <span class="badge badge-out">สินค้าหมด</span>
            <?php elseif ($stock <= 5): ?>
                <span class="badge badge-low">เหลือ <?= $stock ?> ชิ้น</span>
            <?php endif; ?>

            <?php if ($stock > 0 && sale_active($product)): ?>
                <span class="badge badge-sale">ลดราคา</span>
            <?php endif; ?>
        </a>

        <div class="product-body">
            <?php if ($cat): ?><div class="cat"><?= e($cat) ?></div><?php endif; ?>

            <h3><a href="<?= $url ?>"><?= e($product["product_name"]) ?></a></h3>

            <?php $rating = product_ratings()[$id] ?? null; ?>
            <?php if ($rating): ?>
                <div class="rating-line"><?= stars($rating[0]) ?> <small><?= $rating[0] ?> (<?= $rating[1] ?>)</small></div>
            <?php endif; ?>

            <?php if (!empty($product["description"])): ?>
                <p class="product-desc"><?= e($product["description"]) ?></p>
            <?php endif; ?>

            <div class="product-foot">
                <?= price_html($product) ?>
                <p class="stock-note <?= $stock > 0 && $stock <= 5 ? "low" : "" ?>">
                    <?= $stock > 0 ? "คงเหลือ {$stock} ชิ้น" : "ขออภัย สินค้าหมดชั่วคราว" ?>
                </p>

                <?php if ($stock > 0): ?>
                    <form action="cart.php" method="POST" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= $id ?>">
                        <input type="hidden" name="quantity" value="1">
                        <input type="hidden" name="return" value="<?= e($return) ?>">
                        <button type="submit" class="btn btn-sage btn-block"><?= ui_icon("cart") ?> เพิ่มลงตะกร้า</button>
                    </form>
                <?php else: ?>
                    <button type="button" class="btn btn-light btn-block is-disabled" disabled>สินค้าหมด</button>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
}
