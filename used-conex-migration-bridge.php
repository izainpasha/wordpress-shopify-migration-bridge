<?php
/**
 * Plugin Name: Used Conex Migration Bridge
 * Description: Read-only export bridge for migrating usedconex.com content (posts, pages, products, media, menus, SEO meta) to Shopify. Token-protected. Safe to deactivate/delete after migration.
 * Version: 1.2.0
 * Author: Bee Creative
 */

if (!defined('ABSPATH')) exit;

class UC_Migration_Bridge
{
    const OPTION_TOKEN = 'uc_migration_bridge_token';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /* ---------------- Admin UI: show/generate the token ---------------- */

    public function admin_menu()
    {
        add_options_page(
            'Migration Bridge',
            'Migration Bridge',
            'manage_options',
            'uc-migration-bridge',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['uc_regenerate_token']) && check_admin_referer('uc_migration_bridge')) {
            $token = wp_generate_password(40, false, false);
            update_option(self::OPTION_TOKEN, $token, false);
        }

        $token = get_option(self::OPTION_TOKEN);
        if (!$token) {
            $token = wp_generate_password(40, false, false);
            update_option(self::OPTION_TOKEN, $token, false);
        }

        $base = esc_url_raw(rest_url('uc-migration/v1'));
        ?>
        <div class="wrap">
            <h1>Used Conex Migration Bridge</h1>
            <p>This plugin exposes a read-only, token-protected REST API so content can be pulled into the new Shopify store. Nothing here modifies your WordPress data.</p>

            <h2>Access Token</h2>
            <p>Share this token with the migration assistant. Treat it like a password — anyone with it can read your site content (not orders/customers/passwords).</p>
            <p><input type="text" readonly style="width:420px" value="<?php echo esc_attr($token); ?>" onclick="this.select()"></p>

            <form method="post">
                <?php wp_nonce_field('uc_migration_bridge'); ?>
                <button type="submit" name="uc_regenerate_token" value="1" class="button" onclick="return confirm('Regenerate token? The old one will stop working immediately.')">Regenerate token</button>
            </form>

            <h2>Base URL</h2>
            <p><code><?php echo esc_html($base); ?></code></p>

            <h2>Available endpoints</h2>
            <p>Append <code>?token=<?php echo esc_html($token); ?></code> to every request (or send header <code>X-UC-Token</code>).</p>
            <ul>
                <li><code>GET /site</code> — site name, description, timezone, permalink structure</li>
                <li><code>GET /menus</code> — all registered nav menus with items (nested)</li>
                <li><code>GET /posts?per_page=50&page=1</code> — blog posts, full content + Yoast/RankMath SEO meta + featured image URL + categories/tags</li>
                <li><code>GET /pages?per_page=50&page=1</code> — static pages, full content + SEO meta + parent/template</li>
                <li><code>GET /products?per_page=50&page=1</code> — WooCommerce products (if active): full data, variations, images, categories, attributes, SEO meta</li>
                <li><code>GET /product-categories</code> — WooCommerce product category tree (if active)</li>
                <li><code>GET /media?per_page=50&page=1</code> — media library items with source URL, alt text, dimensions</li>
                <li><code>GET /redirects</code> — known redirect rules (Redirection plugin table, if present)</li>
            </ul>

            <p>All list endpoints paginate via <code>per_page</code> (max 100) and <code>page</code>, and return a <code>total</code> + <code>total_pages</code> header/body field.</p>
        </div>
        <?php
    }

    /* ---------------- Auth ---------------- */

    public function check_auth(\WP_REST_Request $request)
    {
        try {
            $token = get_option(self::OPTION_TOKEN);
            $given = $request->get_param('token');
            if (!$given) {
                $given = $request->get_header('x-uc-token');
            }
            if (!$token || !$given || !hash_equals((string) $token, (string) $given)) {
                return new WP_Error('uc_forbidden', 'Invalid or missing token', ['status' => 403]);
            }
            return true;
        } catch (\Throwable $e) {
            return new WP_Error('uc_auth_error', 'Auth check failed: ' . $e->getMessage(), ['status' => 500]);
        }
    }

    /* ---------------- Routes ---------------- */

    public function register_routes()
    {
        $ns = 'uc-migration/v1';

        register_rest_route($ns, '/site', [
            'methods' => 'GET',
            'callback' => $this->safe('get_site'),
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($ns, '/menus', [
            'methods' => 'GET',
            'callback' => $this->safe('get_menus'),
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($ns, '/posts', [
            'methods' => 'GET',
            'callback' => $this->safe('get_posts'),
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($ns, '/pages', [
            'methods' => 'GET',
            'callback' => $this->safe('get_pages'),
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($ns, '/products', [
            'methods' => 'GET',
            'callback' => $this->safe('get_products'),
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($ns, '/product-categories', [
            'methods' => 'GET',
            'callback' => $this->safe('get_product_categories'),
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($ns, '/media', [
            'methods' => 'GET',
            'callback' => $this->safe('get_media'),
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($ns, '/redirects', [
            'methods' => 'GET',
            'callback' => $this->safe('get_redirects'),
            'permission_callback' => [$this, 'check_auth'],
        ]);

        register_rest_route($ns, '/ping', [
            'methods' => 'GET',
            'callback' => function () {
                return ['ok' => true, 'time' => current_time('mysql'), 'php_version' => PHP_VERSION];
            },
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Wraps a route callback so a PHP exception/error becomes a normal
     * WP_REST_Response instead of a fatal wp_die() page — lets us see the
     * real cause from the client instead of a blank "critical error" page.
     */
    private function safe($method)
    {
        $self = $this;
        return function (\WP_REST_Request $request) use ($self, $method) {
            try {
                return call_user_func([$self, $method], $request);
            } catch (\Throwable $e) {
                return new WP_REST_Response([
                    'uc_error' => true,
                    'endpoint' => $method,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], 500);
            }
        };
    }

    /* ---------------- Helpers ---------------- */

    private function pagination_args(\WP_REST_Request $request)
    {
        $per_page = (int) $request->get_param('per_page');
        $page = (int) $request->get_param('page');
        if ($per_page <= 0) $per_page = 50;
        if ($per_page > 100) $per_page = 100;
        if ($page <= 0) $page = 1;
        return [$per_page, $page];
    }

    private function seo_meta($post_id)
    {
        $meta = [];
        // Yoast SEO
        $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
        $yoast_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        if ($yoast_title || $yoast_desc) {
            $meta['source'] = 'yoast';
            $meta['title'] = $yoast_title;
            $meta['description'] = $yoast_desc;
        }
        // RankMath
        $rank_title = get_post_meta($post_id, 'rank_math_title', true);
        $rank_desc = get_post_meta($post_id, 'rank_math_description', true);
        if ($rank_title || $rank_desc) {
            $meta['source'] = 'rankmath';
            $meta['title'] = $rank_title;
            $meta['description'] = $rank_desc;
        }
        // All in One SEO
        $aioseo_title = get_post_meta($post_id, '_aioseo_title', true);
        $aioseo_desc = get_post_meta($post_id, '_aioseo_description', true);
        if ($aioseo_title || $aioseo_desc) {
            $meta['source'] = 'aioseo';
            $meta['title'] = $aioseo_title;
            $meta['description'] = $aioseo_desc;
        }
        return $meta;
    }

    private function featured_image_url($post_id)
    {
        $thumb_id = get_post_thumbnail_id($post_id);
        if (!$thumb_id) return null;
        $src = wp_get_attachment_image_src($thumb_id, 'full');
        return $src ? $src[0] : null;
    }

    /* ---------------- Endpoint: /site ---------------- */

    public function get_site(\WP_REST_Request $request)
    {
        return [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => get_bloginfo('url'),
            'timezone' => get_option('timezone_string'),
            'permalink_structure' => get_option('permalink_structure'),
            'woocommerce_active' => class_exists('WooCommerce'),
            'wp_version' => get_bloginfo('version'),
        ];
    }

    /* ---------------- Endpoint: /menus ---------------- */

    public function get_menus(\WP_REST_Request $request)
    {
        $locations = get_nav_menu_locations();
        $menus = wp_get_nav_menus();
        $out = [];

        foreach ($menus as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            $tree = $this->build_menu_tree($items);
            $location_key = array_search($menu->term_id, $locations);
            $out[] = [
                'id' => $menu->term_id,
                'name' => $menu->name,
                'slug' => $menu->slug,
                'location' => $location_key ?: null,
                'items' => $tree,
            ];
        }

        return $out;
    }

    private function build_menu_tree($items, $parent_id = 0)
    {
        $branch = [];
        if (!$items) return $branch;
        foreach ($items as $item) {
            if ((int) $item->menu_item_parent === (int) $parent_id) {
                $branch[] = [
                    'id' => $item->ID,
                    'title' => $item->title,
                    'url' => $item->url,
                    'target' => $item->target,
                    'children' => $this->build_menu_tree($items, $item->ID),
                ];
            }
        }
        return $branch;
    }

    /* ---------------- Endpoint: /posts ---------------- */

    public function get_posts(\WP_REST_Request $request)
    {
        [$per_page, $page] = $this->pagination_args($request);

        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            $items[] = [
                'id' => $post->ID,
                'title' => get_the_title($post),
                'slug' => $post->post_name,
                'link' => get_permalink($post),
                'date' => $post->post_date,
                'modified' => $post->post_modified,
                'excerpt' => get_the_excerpt($post),
                'content_html' => apply_filters('the_content', $post->post_content),
                'author' => get_the_author_meta('display_name', $post->post_author),
                'categories' => wp_get_post_categories($post->ID, ['fields' => 'names']),
                'tags' => wp_get_post_tags($post->ID, ['fields' => 'names']),
                'featured_image' => $this->featured_image_url($post->ID),
                'seo' => $this->seo_meta($post->ID),
            ];
        }

        return [
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page' => $page,
            'per_page' => $per_page,
            'items' => $items,
        ];
    }

    /* ---------------- Endpoint: /pages ---------------- */

    public function get_pages(\WP_REST_Request $request)
    {
        [$per_page, $page] = $this->pagination_args($request);

        $query = new WP_Query([
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            $items[] = [
                'id' => $post->ID,
                'title' => get_the_title($post),
                'slug' => $post->post_name,
                'link' => get_permalink($post),
                'parent_id' => $post->post_parent,
                'template' => get_page_template_slug($post->ID),
                'date' => $post->post_date,
                'modified' => $post->post_modified,
                'content_html' => apply_filters('the_content', $post->post_content),
                'featured_image' => $this->featured_image_url($post->ID),
                'seo' => $this->seo_meta($post->ID),
            ];
        }

        return [
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page' => $page,
            'per_page' => $per_page,
            'items' => $items,
        ];
    }

    /* ---------------- Endpoint: /products (WooCommerce) ---------------- */

    public function get_products(\WP_REST_Request $request)
    {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('uc_no_woocommerce', 'WooCommerce is not active on this site', ['status' => 404]);
        }

        [$per_page, $page] = $this->pagination_args($request);

        $query = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;

            $gallery_ids = $product->get_gallery_image_ids();
            $gallery_urls = array_map(function ($id) {
                $src = wp_get_attachment_image_src($id, 'full');
                return $src ? $src[0] : null;
            }, $gallery_ids);

            $variations = [];
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if (!$variation) continue;
                    $variations[] = [
                        'id' => $variation_id,
                        'sku' => $variation->get_sku(),
                        'price' => $variation->get_price(),
                        'regular_price' => $variation->get_regular_price(),
                        'sale_price' => $variation->get_sale_price(),
                        'stock_quantity' => $variation->get_stock_quantity(),
                        'stock_status' => $variation->get_stock_status(),
                        'attributes' => $variation->get_variation_attributes(),
                        'image' => $variation->get_image_id() ? wp_get_attachment_image_src($variation->get_image_id(), 'full')[0] : null,
                    ];
                }
            }

            $items[] = [
                'id' => $post->ID,
                'title' => $product->get_name(),
                'slug' => $post->post_name,
                'link' => get_permalink($post),
                'type' => $product->get_type(),
                'status' => $product->get_status(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'description_html' => apply_filters('the_content', $product->get_description()),
                'short_description_html' => $product->get_short_description(),
                'categories' => wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'names']),
                'tags' => wp_get_post_terms($post->ID, 'product_tag', ['fields' => 'names']),
                'attributes' => $product->get_attributes() ? array_map(function ($attr) {
                    return [
                        'name' => $attr->get_name(),
                        'options' => $attr->get_options(),
                        'variation' => $attr->get_variation(),
                    ];
                }, $product->get_attributes()) : [],
                'featured_image' => $this->featured_image_url($post->ID),
                'gallery_images' => array_values(array_filter($gallery_urls)),
                'variations' => $variations,
                'seo' => $this->seo_meta($post->ID),
            ];
        }

        return [
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page' => $page,
            'per_page' => $per_page,
            'items' => $items,
        ];
    }

    /* ---------------- Endpoint: /product-categories ---------------- */

    public function get_product_categories(\WP_REST_Request $request)
    {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('uc_no_woocommerce', 'WooCommerce is not active on this site', ['status' => 404]);
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        $items = [];
        foreach ($terms as $term) {
            $thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
            $image = $thumbnail_id ? wp_get_attachment_image_src($thumbnail_id, 'full')[0] : null;
            $items[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent_id' => $term->parent,
                'description' => $term->description,
                'count' => $term->count,
                'image' => $image,
            ];
        }

        return $items;
    }

    /* ---------------- Endpoint: /media ---------------- */

    public function get_media(\WP_REST_Request $request)
    {
        [$per_page, $page] = $this->pagination_args($request);

        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $items = [];
        foreach ($query->posts as $post) {
            $src = wp_get_attachment_image_src($post->ID, 'full');
            $items[] = [
                'id' => $post->ID,
                'title' => get_the_title($post),
                'alt' => get_post_meta($post->ID, '_wp_attachment_image_alt', true),
                'mime_type' => $post->post_mime_type,
                'url' => $src ? $src[0] : wp_get_attachment_url($post->ID),
                'width' => $src ? $src[1] : null,
                'height' => $src ? $src[2] : null,
            ];
        }

        return [
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page' => $page,
            'per_page' => $per_page,
            'items' => $items,
        ];
    }

    /* ---------------- Endpoint: /redirects ---------------- */

    public function get_redirects(\WP_REST_Request $request)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'redirection_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }
        $rows = $wpdb->get_results("SELECT source, target, regex, status FROM {$table} WHERE status = 'enabled'", ARRAY_A);
        return $rows ?: [];
    }
}

new UC_Migration_Bridge();
