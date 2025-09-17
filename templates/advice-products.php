<?php

/**
 * Template: Advice Products (fallback in plugin)
 * Variables passed in $args:
 * - int   $term_id
 * - array $product_ids (max 3)
 */

defined('ABSPATH') || exit;

$term_id     = isset($args['term_id']) ? (int) $args['term_id'] : 0;
$product_ids = isset($args['product_ids']) && is_array($args['product_ids']) ? $args['product_ids'] : [];
$thumb_size = apply_filters('woo_advice_products_thumbnail_size', 'advice-thumb');
?>
<section class="my-10 pb-10 border-b border-slate-300" aria-label="Advice products">
    <h2>
        <?php printf(esc_html__('%s that we recommend', 'woo-advice-products'), esc_html(get_term($term_id)->name)); ?>
    </h2>

    <?php if (!empty($product_ids)) : ?>
        <div class="grid grid-cols-1 tablet:grid-cols-3 gap-6">
            <?php foreach ($product_ids as $pid) :
                $product = wc_get_product($pid);
                if (!$product) continue;
                $permalink  = get_permalink($pid);
                $title      = get_the_title($pid);
                $excerpt    = get_the_excerpt($pid);
                $thumb_html = get_the_post_thumbnail($pid, $thumb_size, ['class' => 'w-full h-full object-cover', 'loading' => 'lazy']);
            ?>
                <div class="flex flex-col gap-3" data-product-id="<?php echo esc_attr($pid); ?>">
                    <a href="<?php echo esc_url($permalink); ?>" class="justify-center aspect-video block overflow-hidden">
                        <?php echo $thumb_html; ?>
                    </a>

                    <a href="<?php echo esc_url($permalink); ?>">
                        <span class="text-2xl text-gray font-bold"><?php echo esc_html($title); ?></span>
                    </a>

                    <?php if (!empty($excerpt)) : ?>
                        <div class="text-base grow"><?php echo $excerpt; ?></div>
                    <?php endif; ?>

                    <a class="uppercase font-bold text-gray group transition-all duration-300 ease-in-out" href="<?php echo esc_url($permalink); ?>">
                        <span class="text-gray bg-left-bottom bg-gradient-to-r from-gray to-gray pb-1 bg-[length:0%_2px] bg-no-repeat group-hover:bg-[length:100%_2px] transition-all duration-500 ease-out">
                            <?php printf(esc_html__('View %s', 'woo-advice-products'), esc_html($title)); ?>
                        </span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>