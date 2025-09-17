<?php

/**
 * Plugin Name: Woo Adviced Products (per product category)
 * Description: Select up to 3 products per Woocommerce category to highligh for your customers.
 * Author: Rob Huijben
 * Version: 1.3.1
 * Text Domain: woo-advice-products
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

class Woo_Advice_Products
{
    const FIELD_KEY = 'advice_products';
    const TRANSIENT_PREFIX = 'advice_products_term_';
    const TEMPLATE_REL_PATH = 'woocommerce/advice/advice-products.php'; // theme override path
    const TEMPLATE_PLUGIN_SUBDIR = 'templates/advice-products.php';      // plugin fallback

    public function __construct()
    {
        add_action('after_setup_theme', [$this, 'register_image_size']);
        add_action('acf/init', [$this, 'register_acf']);
        add_filter('acf/fields/relationship/query/name=' . self::FIELD_KEY, [$this, 'limit_relationship_to_current_term'], 10, 3);
        add_filter('acf/validate_value/name=' . self::FIELD_KEY, [$this, 'validate_exact_three'], 10, 4);
        add_action('acf/save_post', [$this, 'bust_cache_on_term_save'], 20);

        add_action('woocommerce_before_shop_loop', [$this, 'render_on_category_archive'], 8);
        add_shortcode('advice_products', [$this, 'shortcode']);
        add_action('plugins_loaded', function () {
            load_plugin_textdomain('woo-advice-products', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        });

        add_action('edited_product_cat', [$this, 'bust_cache_term'], 10, 1);
        add_action('set_object_terms', [$this, 'bust_cache_on_product_terms_change'], 10, 6);
    }

    public function plugin_path()
    {
        return untrailingslashit(plugin_dir_path(__FILE__));
    }

    public function register_image_size()
    {
        /**
         * Register a 4:3 hard-cropped image size for advice products.
         * Developers can change the size via filter:
         * add_filter('woo_advice_products_image_size_args', fn($a) => ['name'=>'advice-thumb','w'=>1000,'h'=>750,'crop'=>true]);
         */
        $args = apply_filters('woo_advice_products_image_size_args', [
            'name' => 'advice-thumb',
            'w'    => 640,
            'h'    => 360,
            'crop' => true,
        ]);

        if (!empty($args['name']) && !empty($args['w']) && !empty($args['h'])) {
            add_image_size($args['name'], (int) $args['w'], (int) $args['h'], (bool) $args['crop']);
        }
    }

    /** ACF field group */
    public function register_acf()
    {
        if (!function_exists('acf_add_local_field_group')) return;

        acf_add_local_field_group([
            'key' => 'group_woo_advice_products',
            'title' => 'Highlighted Products',
            'fields' => [
                [
                    'key'           => 'field_' . self::FIELD_KEY,
                    'label'         => 'Advice products (up to 3)',
                    'name'          => self::FIELD_KEY,
                    'type'          => 'relationship',
                    'instructions'  => 'Select up to 3 products that belong to this category.',
                    'required'      => 0,
                    'return_format' => 'id',
                    'min'           => 0,
                    'max'           => 3,
                    'post_type'     => ['product'],
                    'filters'       => ['search', 'taxonomy'],
                    'elements'      => ['featured_image'],
                    'taxonomy'      => ['product_cat:current'],
                ],
            ],
            'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'product_cat']]],
            'position' => 'normal',
        ]);
    }

    public function limit_relationship_to_current_term($args, $field, $post_id)
    {
        $term_id = null;
        if (is_string($post_id) && preg_match('/(\d+)$/', $post_id, $m)) $term_id = (int) $m[1];
        if (!$term_id && isset($_GET['tag_ID'])) $term_id = (int) $_GET['tag_ID'];

        if ($term_id) {
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $term_id,
            ]];
        }
        $args['posts_per_page'] = 50;
        $args['orderby'] = 'title';
        $args['order'] = 'ASC';
        return $args;
    }

    public function validate_exact_three($valid, $value)
    {
        if ($valid !== true) return $valid;

        $count = is_array($value) ? count($value) : 0;

        if ($count > 3) {
            return 'Please select up to 3 products.';
        }
        return true;
    }

    public function bust_cache_on_term_save($post_id)
    {
        if (is_string($post_id) && str_starts_with($post_id, 'product_cat_')) {
            if (preg_match('/(\d+)$/', $post_id, $m)) delete_transient(self::TRANSIENT_PREFIX . (int) $m[1]);
        }
    }

    public function bust_cache_term($term_id)
    {
        delete_transient(self::TRANSIENT_PREFIX . (int)$term_id);
    }

    public function bust_cache_on_product_terms_change($object_id, $terms, $tt_ids, $taxonomy)
    {
        if ($taxonomy !== 'product_cat') return;
        foreach ((array)$terms as $term_id) {
            delete_transient(self::TRANSIENT_PREFIX . (int)$term_id);
            // ook ouders flushen (veilig):
            foreach (get_ancestors($term_id, 'product_cat') as $ancestor) {
                delete_transient(self::TRANSIENT_PREFIX . (int)$ancestor);
            }
        }
    }

    /** Frontend */
    public function render_on_category_archive()
    {
        if (!function_exists('is_product_category') || !is_product_category()) return;
        $term = get_queried_object();
        if (!$term || empty($term->term_id)) return;
        echo $this->get_template_html((int) $term->term_id);
    }

    public function shortcode()
    {
        if (!is_tax('product_cat')) return '';
        $term = get_queried_object();
        if (!$term || empty($term->term_id)) return '';
        return $this->get_template_html((int) $term->term_id);
    }

    private function get_template_html($term_id)
    {
        // Fetch selected IDs from cache/ACF and normalize to max 3
        $product_ids = $this->get_cached_advice_products($term_id);
        $product_ids = array_slice(array_values(array_unique(array_map('intval', (array) $product_ids))), 0, 3);

        // Allow products that belong to the current term and (optionally) its children
        // Toggle via filter: add_filter('woo_advice_products_include_children', '__return_false');
        $include_children   = apply_filters('woo_advice_products_include_children', true);
        $term_ids_to_match  = [$term_id];

        if ($include_children) {
            $children = get_term_children($term_id, 'product_cat');
            if (!is_wp_error($children) && !empty($children)) {
                $term_ids_to_match = array_merge($term_ids_to_match, array_map('intval', $children));
            }
        }
        $term_ids_to_match = array_map('intval', $term_ids_to_match);

        // Filter out products that don't belong to the current term (or its children if enabled)
        if (!empty($product_ids)) {
            $filtered = [];
            foreach ($product_ids as $pid) {
                $prod_terms = wp_get_post_terms($pid, 'product_cat', ['fields' => 'ids']);
                if (!is_wp_error($prod_terms)) {
                    $prod_terms = array_map('intval', (array) $prod_terms);
                    if (array_intersect($term_ids_to_match, $prod_terms)) {
                        $filtered[] = $pid;
                    }
                }
            }
            $product_ids = $filtered;
        }

        // Render via WooCommerce template loader (theme override or plugin fallback)
        ob_start();

        $args = [
            'term_id'     => (int) $term_id,
            'product_ids' => $product_ids,
        ];

        // Theme override path can be customized via this filter if needed
        $template_rel = apply_filters('woo_advice_products_template_rel_path', self::TEMPLATE_REL_PATH);

        // Try theme override first; fall back to plugin template
        $located = locate_template([$template_rel]);
        if ($located) {
            wc_get_template($template_rel, $args);
        } else {
            wc_get_template(
                basename(self::TEMPLATE_PLUGIN_SUBDIR),
                $args,
                '',
                trailingslashit($this->plugin_path()) . 'templates/'
            );
        }

        return (string) ob_get_clean();
    }

    private function get_cached_advice_products($term_id)
    {
        $key = self::TRANSIENT_PREFIX . $term_id;
        $ids = get_transient($key);
        if ($ids !== false) return $ids;

        $ids = get_field(self::FIELD_KEY, 'product_cat_' . $term_id);
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $ids = array_slice($ids, 0, 3);

        set_transient($key, $ids, 12 * HOUR_IN_SECONDS);
        return $ids;
    }
}

new Woo_Advice_Products();
