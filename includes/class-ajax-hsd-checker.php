<?php
/**
 * TGS HSD Checker - AJAX Handler.
 *
 * Catalog san pham doc/ghi qua bang global_product_name.
 * Ton/khoi luong kiem ke lay qua stock API/ledger, khong cap nhat cot ton tren bang san pham.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HSD_Checker_Ajax
{
    const NONCE_ACTION = 'tgs_hsd_checker_nonce';

    public static function register()
    {
        add_action('wp_ajax_hsd_checker_lookup_barcode', [__CLASS__, 'lookup_barcode']);
        add_action('wp_ajax_hsd_checker_search_product', [__CLASS__, 'search_product']);
        add_action('wp_ajax_hsd_checker_map_barcode', [__CLASS__, 'map_barcode']);
        add_action('wp_ajax_hsd_checker_get_children', [__CLASS__, 'get_children']);
        add_action('wp_ajax_hsd_checker_create_child', [__CLASS__, 'create_child']);
        add_action('wp_ajax_hsd_checker_update_quantity', [__CLASS__, 'update_quantity']);
        add_action('wp_ajax_hsd_checker_get_stats', [__CLASS__, 'get_stats']);
    }

    private static function check()
    {
        if (
            !current_user_can('manage_options') &&
            !current_user_can('manage_woocommerce') &&
            !current_user_can('edit_posts')
        ) {
            wp_send_json_error(['message' => 'Khong co quyen.'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    private static function ok($data = [], $msg = '')
    {
        if ($msg) {
            $data['message'] = $msg;
        }
        wp_send_json_success($data);
    }

    private static function fail($msg = 'Loi', $code = 400)
    {
        wp_send_json_error(['message' => $msg], $code);
    }

    private static function product_table(): string
    {
        global $wpdb;
        return defined('TGS_TABLE_GLOBAL_PRODUCT_NAME') ? TGS_TABLE_GLOBAL_PRODUCT_NAME : $wpdb->base_prefix . 'global_product_name';
    }

    private static function hsd_identifier_table(): string
    {
        global $wpdb;
        return defined('TGS_TABLE_GLOBAL_PRODUCT_HSD_IDENTIFIERS')
            ? TGS_TABLE_GLOBAL_PRODUCT_HSD_IDENTIFIERS
            : $wpdb->base_prefix . 'global_product_hsd_identifiers';
    }

    private static function ensure_global_source(): bool
    {
        if (!class_exists('TGS_Global_Product_Source')) {
            $source_file = WP_PLUGIN_DIR . '/tgs_shop_management/functions/class-tgs-global-product-source.php';
            if (is_readable($source_file)) {
                require_once $source_file;
            }
        }

        return class_exists('TGS_Global_Product_Source');
    }

    private static function ensure_adjustment_helper(): bool
    {
        if (!class_exists('TGS_Adjustment_Ledger_Helper')) {
            $helper_file = WP_PLUGIN_DIR . '/tgs_shop_management/functions/class-tgs-adjustment-ledger-helper.php';
            if (is_readable($helper_file)) {
                require_once $helper_file;
            }
        }

        return class_exists('TGS_Adjustment_Ledger_Helper');
    }

    private static function ensure_barcode_helper(): bool
    {
        if (!function_exists('tgs_shop_generate_product_barcode_data')) {
            $helper_file = WP_PLUGIN_DIR . '/tgs_shop_management/functions/tgs-barcode-helpers.php';
            if (is_readable($helper_file)) {
                require_once $helper_file;
            }
        }

        return function_exists('tgs_shop_generate_product_barcode_data');
    }

    private static function normalize_skus(array $skus): array
    {
        return array_values(array_unique(array_filter(array_map(static function ($sku) {
            return trim((string) $sku);
        }, $skus), static function ($sku) {
            return $sku !== '';
        })));
    }

    private static function stock_map_for_skus(array $skus): array
    {
        if (!self::ensure_global_source()) {
            return [];
        }

        $skus = self::normalize_skus($skus);
        return $skus ? TGS_Global_Product_Source::get_stock_for_skus($skus, get_current_blog_id()) : [];
    }

    private static function get_product_by_id(int $product_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::product_table() . "
             WHERE global_product_name_id = %d
               AND (is_deleted IS NULL OR is_deleted = 0)",
            $product_id
        ));
    }

    private static function get_product_by_id_array(int $product_id): array
    {
        $row = self::get_product_by_id($product_id);
        return $row ? (array) $row : [];
    }

    private static function to_local_alias(array $row, array $stock_map = []): array
    {
        if (self::ensure_global_source()) {
            $row = TGS_Global_Product_Source::add_local_aliases($row);
        } else {
            $row['local_product_name_id'] = $row['global_product_name_id'] ?? 0;
            $row['local_product_name'] = $row['global_product_name'] ?? '';
            $row['local_product_sku'] = $row['global_product_sku'] ?? '';
            $row['local_product_thumbnail'] = $row['global_product_thumbnail'] ?? '';
            $row['local_product_barcode_main'] = $row['global_product_barcode_main'] ?? '';
            $row['local_product_barcode_url_main'] = $row['global_product_barcode_url_main'] ?? '';
            $row['local_product_unit'] = $row['global_product_unit'] ?? '';
            $row['local_product_price_after_tax'] = $row['global_product_price_after_tax'] ?? 0;
            $row['local_product_parent_sku'] = $row['global_product_parent_sku'] ?? null;
            $row['local_product_hsd'] = $row['global_product_hsd'] ?? null;
            $row['local_product_special_barcode'] = $row['global_product_special_barcode'] ?? null;
            $row['local_product_special_barcode_url'] = $row['global_product_special_barcode_url'] ?? null;
            $row['local_product_is_tracking'] = $row['global_product_is_tracking'] ?? 0;
        }

        $sku = (string) ($row['global_product_sku'] ?? $row['local_product_sku'] ?? '');
        $qty = isset($stock_map[$sku])
            ? (float) ($stock_map[$sku]['projected_stock'] ?? $stock_map[$sku]['actual_stock'] ?? 0)
            : 0.0;
        $row['local_product_quantity_no_tracking'] = $qty;
        $row['projected_stock'] = $qty;

        return $row;
    }

    private static function maybe_record_hsd_create(int $product_id, ?string $hsd, float $quantity, string $source)
    {
        if ($quantity == 0.0 || !self::ensure_adjustment_helper()) {
            return false;
        }

        return TGS_Adjustment_Ledger_Helper::record_hsd_child_create($product_id, $hsd, $quantity, $source);
    }

    public static function lookup_barcode()
    {
        self::check();

        $barcode = sanitize_text_field($_POST['barcode'] ?? '');
        if ($barcode === '') {
            self::fail('Thieu ma barcode.');
        }

        global $wpdb;
        $table = self::product_table();

        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE global_product_barcode_main = %s
               AND (global_product_parent_sku IS NULL OR global_product_parent_sku = '')
               AND (global_product_is_tracking IS NULL OR global_product_is_tracking = 0)
               AND (is_deleted IS NULL OR is_deleted = 0)
             LIMIT 1",
            $barcode
        ), ARRAY_A);

        if (!$parent) {
            self::ok(['found' => false, 'barcode' => $barcode]);
        }

        $child_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE global_product_parent_sku = %s
               AND (is_deleted IS NULL OR is_deleted = 0)",
            (string) ($parent['global_product_sku'] ?? '')
        ));

        $parent = self::to_local_alias($parent, self::stock_map_for_skus([$parent['global_product_sku'] ?? '']));
        $parent['hsd_child_count'] = $child_count;

        self::ok(['found' => true, 'product' => $parent]);
    }

    public static function search_product()
    {
        self::check();

        $keyword = sanitize_text_field($_POST['keyword'] ?? '');
        if (strlen($keyword) < 2) {
            self::fail('Nhap it nhat 2 ky tu.');
        }

        if (!self::ensure_global_source()) {
            self::fail('Nguon san pham global chua san sang.');
        }

        $result = TGS_Global_Product_Source::query_products([
            'search' => $keyword,
            'blog_id' => get_current_blog_id(),
            'with_stock' => true,
            'with_local_aliases' => true,
            'parent_only' => true,
            'require_sku' => true,
            'tracking_filter' => 'no-tracking',
            'status_filter' => 'all',
            'order_by' => 'global_product_name',
            'order_dir' => 'ASC',
            'per_page' => 20,
        ]);

        self::ok(['products' => array_values((array) ($result['items'] ?? []))]);
    }

    public static function map_barcode()
    {
        self::check();

        $product_id = intval($_POST['product_id'] ?? 0);
        $barcode = sanitize_text_field($_POST['barcode'] ?? '');
        if (!$product_id || $barcode === '') {
            self::fail('Thieu thong tin.');
        }

        global $wpdb;
        $table = self::product_table();

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT global_product_name_id, global_product_sku, global_product_name, global_product_barcode_main
             FROM {$table}
             WHERE global_product_name_id = %d
               AND (global_product_parent_sku IS NULL OR global_product_parent_sku = '')
               AND (is_deleted IS NULL OR is_deleted = 0)",
            $product_id
        ));

        if (!$product) {
            self::fail('Khong tim thay san pham cha.');
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT global_product_name_id, global_product_name
             FROM {$table}
             WHERE global_product_barcode_main = %s
               AND global_product_name_id != %d
               AND (global_product_parent_sku IS NULL OR global_product_parent_sku = '')
               AND (is_deleted IS NULL OR is_deleted = 0)
             LIMIT 1",
            $barcode,
            $product_id
        ));

        if ($existing) {
            self::fail('Barcode nay da duoc gan cho: ' . $existing->global_product_name);
        }

        $wpdb->update(
            $table,
            [
                'global_product_barcode_main' => $barcode,
                'updated_at' => current_time('mysql'),
            ],
            ['global_product_name_id' => $product_id]
        );

        if (!empty($product->global_product_sku)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET global_product_barcode_main = %s, updated_at = %s
                 WHERE global_product_parent_sku = %s
                   AND (global_product_barcode_main IS NULL OR global_product_barcode_main = '')
                   AND (is_deleted IS NULL OR is_deleted = 0)",
                $barcode,
                current_time('mysql'),
                $product->global_product_sku
            ));
        }

        self::ok([
            'product_id' => $product_id,
            'product_name' => $product->global_product_name,
            'barcode' => $barcode,
        ], 'Da gan barcode thanh cong!');
    }

    public static function get_children()
    {
        self::check();

        $parent_sku = sanitize_text_field($_POST['parent_sku'] ?? '');
        if ($parent_sku === '') {
            self::fail('Thieu SKU cha.');
        }

        global $wpdb;
        $table = self::product_table();

        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE global_product_parent_sku = %s
               AND (is_deleted IS NULL OR is_deleted = 0)
             ORDER BY global_product_hsd IS NULL ASC, global_product_hsd ASC",
            $parent_sku
        ), ARRAY_A) ?: [];

        $stock_map = self::stock_map_for_skus(array_column($children, 'global_product_sku'));
        $children = array_map(static function ($child) use ($stock_map) {
            return self::to_local_alias((array) $child, $stock_map);
        }, $children);

        $total_hsd_qty = 0.0;
        $total_all_qty = 0.0;
        foreach ($children as $child) {
            $qty = (float) ($child['local_product_quantity_no_tracking'] ?? 0);
            $total_all_qty += $qty;
            if (!empty($child['local_product_hsd'])) {
                $total_hsd_qty += $qty;
            }
        }

        self::ok([
            'children' => $children,
            'total_hsd_qty' => $total_hsd_qty,
            'total_all_qty' => $total_all_qty,
            'count' => count($children),
        ]);
    }

    public static function create_child()
    {
        self::check();

        $parent_id = intval($_POST['parent_product_id'] ?? 0);
        $hsd = sanitize_text_field($_POST['hsd'] ?? '');
        $quantity = floatval($_POST['quantity'] ?? 0);
        $is_no_hsd = ($hsd === '');

        if (!$parent_id) {
            self::fail('Thieu ID san pham cha.');
        }

        $hsd_date = null;
        if (!$is_no_hsd) {
            $hsd_date = date_create_from_format('Y-m-d', $hsd);
            if (!$hsd_date) {
                self::fail('HSD khong dung dinh dang (YYYY-MM-DD).');
            }
        }

        global $wpdb;
        $table = self::product_table();
        $parent = self::get_product_by_id($parent_id);

        if (!$parent) {
            self::fail('Khong tim thay san pham cha.');
        }
        if (!empty($parent->global_product_parent_sku)) {
            self::fail('Day la san pham con, khong the tao con cua con.');
        }

        $parent_sku = (string) ($parent->global_product_sku ?? '');
        if ($parent_sku === '') {
            self::fail('San pham cha chua co SKU.');
        }

        $child_sku = $is_no_hsd ? $parent_sku . '-no-hsd' : $parent_sku . '-' . date_format($hsd_date, 'dmy');
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT global_product_name_id
             FROM {$table}
             WHERE global_product_sku = %s
               AND (is_deleted IS NULL OR is_deleted = 0)
             LIMIT 1",
            $child_sku
        ));

        if ($existing) {
            $existing_child = self::to_local_alias(self::get_product_by_id_array($existing), self::stock_map_for_skus([$child_sku]));
            self::ok([
                'already_exists' => true,
                'child' => $existing_child,
            ], 'HSD nay da ton tai. Ban co muon cong them so luong?');
            return;
        }

        $global_hsd = $is_no_hsd ? '1970-01-01' : $hsd;
        if (!self::ensure_barcode_helper()) {
            self::fail('Barcode helper chua san sang.', 500);
        }

        if ($quantity != 0.0 && !self::ensure_adjustment_helper()) {
            self::fail('Adjustment ledger helper chua san sang.', 500);
        }

        $global_table = self::hsd_identifier_table();
        $global_record = $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$global_table}
             WHERE local_product_parent_sku = %s
               AND local_product_hsd = %s
             LIMIT 1",
            $parent_sku,
            $global_hsd
        ));

        if ($global_record) {
            $barcode_data = [
                'barcode' => $global_record->special_barcode,
                'barcode_url' => $global_record->special_barcode_url,
            ];
            if (!empty($global_record->local_product_child_sku)) {
                $child_sku = $global_record->local_product_child_sku;
            }
        } else {
            $barcode_data = tgs_shop_generate_product_barcode_data();
            $wpdb->insert($global_table, [
                'local_product_parent_sku' => $parent_sku,
                'local_product_child_sku' => $child_sku,
                'local_product_hsd' => $global_hsd,
                'special_barcode' => $barcode_data['barcode'],
                'special_barcode_url' => $barcode_data['barcode_url'],
                'source_blog_id' => get_current_blog_id(),
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }

        $child_data = [
            'global_blog_id' => $parent->global_blog_id,
            'global_product_name' => $parent->global_product_name,
            'global_product_thumbnail' => $parent->global_product_thumbnail ?? '',
            'global_product_list_category_id' => $parent->global_product_list_category_id,
            'global_product_category_path' => $parent->global_product_category_path ?? '',
            'global_product_description' => $parent->global_product_description ?? '',
            'global_product_content' => $parent->global_product_content ?? '',
            'global_product_status' => $parent->global_product_status ?? 1,
            'global_product_price' => $parent->global_product_price ?? 0,
            'global_product_tax' => $parent->global_product_tax ?? 0,
            'global_product_price_after_tax' => $parent->global_product_price_after_tax ?? 0,
            'global_product_meta' => $parent->global_product_meta ?? '{}',
            'global_product_point' => $parent->global_product_point ?? 0,
            'global_product_barcode_main' => $parent->global_product_barcode_main ?? '',
            'global_product_barcode_url_main' => $parent->global_product_barcode_url_main ?? '',
            'global_product_is_tracking' => 0,
            'global_product_sku' => $child_sku,
            'global_product_unit' => $parent->global_product_unit ?? '',
            'global_product_warehouse_htsoft' => $parent->global_product_warehouse_htsoft ?? '',
            'global_product_parent_sku' => $parent_sku,
            'global_product_hsd' => $is_no_hsd ? null : $hsd,
            'global_product_special_barcode' => $barcode_data['barcode'],
            'global_product_special_barcode_url' => $barcode_data['barcode_url'],
            'global_product_tag' => $parent->global_product_tag ?? 0,
            'weighted_avg_cost' => $parent->weighted_avg_cost ?? 0,
            'user_id' => get_current_user_id(),
            'is_deleted' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $new_id = TGS_Shop_Query::insert($table, $child_data);
        if (!$new_id) {
            self::fail('Khong the tao san pham con.');
        }

        $auto_no_hsd = null;
        if (!$is_no_hsd) {
            $auto_no_hsd = self::ensure_no_hsd_child($parent, $child_data, $global_table);
        }

        self::maybe_record_hsd_create((int) $new_id, $is_no_hsd ? null : $hsd, $quantity, 'hsd_checker_create_child');

        $resp = [
            'id' => $new_id,
            'sku' => $child_sku,
            'special_barcode' => $barcode_data['barcode'],
            'special_barcode_url' => $barcode_data['barcode_url'],
            'is_no_hsd' => $is_no_hsd,
            'quantity' => $quantity,
        ];
        if ($auto_no_hsd) {
            $resp['auto_no_hsd'] = $auto_no_hsd;
        }

        self::ok($resp, 'Tao HSD thanh cong!');
    }

    private static function ensure_no_hsd_child(object $parent, array $base_child_data, string $global_table): ?array
    {
        global $wpdb;

        $table = self::product_table();
        $parent_sku = (string) ($parent->global_product_sku ?? '');
        $no_hsd_sku = $parent_sku . '-no-hsd';

        $no_hsd_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE global_product_sku = %s
               AND (is_deleted IS NULL OR is_deleted = 0)",
            $no_hsd_sku
        ));

        if ($no_hsd_exists) {
            return null;
        }

        $global_nohsd = $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$global_table}
             WHERE local_product_parent_sku = %s
               AND local_product_hsd = '1970-01-01'
             LIMIT 1",
            $parent_sku
        ));

        if ($global_nohsd) {
            $barcode = [
                'barcode' => $global_nohsd->special_barcode,
                'barcode_url' => $global_nohsd->special_barcode_url,
            ];
            if (!empty($global_nohsd->local_product_child_sku)) {
                $no_hsd_sku = $global_nohsd->local_product_child_sku;
            }
        } else {
            $barcode = tgs_shop_generate_product_barcode_data();
            $wpdb->insert($global_table, [
                'local_product_parent_sku' => $parent_sku,
                'local_product_child_sku' => $no_hsd_sku,
                'local_product_hsd' => '1970-01-01',
                'special_barcode' => $barcode['barcode'],
                'special_barcode_url' => $barcode['barcode_url'],
                'source_blog_id' => get_current_blog_id(),
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }

        $data = $base_child_data;
        $data['global_product_sku'] = $no_hsd_sku;
        $data['global_product_hsd'] = null;
        $data['global_product_special_barcode'] = $barcode['barcode'];
        $data['global_product_special_barcode_url'] = $barcode['barcode_url'];

        $id = TGS_Shop_Query::insert($table, $data);
        if (!$id) {
            return null;
        }

        return ['id' => $id, 'sku' => $no_hsd_sku];
    }

    public static function update_quantity()
    {
        self::check();

        $child_id = intval($_POST['child_product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $mode = sanitize_text_field($_POST['mode'] ?? 'set');
        if (!$child_id) {
            self::fail('Thieu ID san pham con.');
        }

        $child = self::get_product_by_id($child_id);
        if (!$child) {
            self::fail('Khong tim thay san pham con.');
        }
        if (empty($child->global_product_parent_sku)) {
            self::fail('Day khong phai san pham con.');
        }

        $stock = self::ensure_global_source()
            ? TGS_Global_Product_Source::get_product($child_id, [
                'by' => 'id',
                'blog_id' => get_current_blog_id(),
                'with_stock' => true,
            ])
            : [];
        $old_qty = (float) ($stock['projected_stock'] ?? $stock['actual_stock'] ?? 0);
        $new_qty = ($mode === 'add') ? $old_qty + $quantity : $quantity;
        $delta = $new_qty - $old_qty;

        $ledger_id = false;
        if (abs($delta) >= 0.0001) {
            if (!self::ensure_adjustment_helper()) {
                self::fail('Adjustment ledger helper chua san sang.', 500);
            }

            $ledger_id = TGS_Adjustment_Ledger_Helper::record_qty_change(
                $child_id,
                $old_qty,
                $new_qty,
                'hsd_checker_update_quantity',
                'HSD checker quantity adjustment'
            );
        }

        self::ok([
            'child_id' => $child_id,
            'old_qty' => $old_qty,
            'new_qty' => $new_qty,
            'delta_qty' => $delta,
            'mode' => $mode,
            'ledger_id' => $ledger_id,
            'warning' => '',
        ], 'Da ghi phieu dieu chinh so luong.');
    }

    public static function get_stats()
    {
        self::check();

        global $wpdb;
        $table = self::product_table();

        $total_parents = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE (global_product_parent_sku IS NULL OR global_product_parent_sku = '')
               AND (global_product_is_tracking IS NULL OR global_product_is_tracking = 0)
               AND (is_deleted IS NULL OR is_deleted = 0)"
        );

        $parents_with_hsd = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.global_product_sku)
             FROM {$table} p
             INNER JOIN {$table} c
                ON c.global_product_parent_sku = p.global_product_sku
               AND (c.is_deleted IS NULL OR c.is_deleted = 0)
             WHERE (p.global_product_parent_sku IS NULL OR p.global_product_parent_sku = '')
               AND (p.global_product_is_tracking IS NULL OR p.global_product_is_tracking = 0)
               AND (p.is_deleted IS NULL OR p.is_deleted = 0)"
        );

        $parents_with_barcode = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE (global_product_parent_sku IS NULL OR global_product_parent_sku = '')
               AND (global_product_is_tracking IS NULL OR global_product_is_tracking = 0)
               AND (is_deleted IS NULL OR is_deleted = 0)
               AND global_product_barcode_main IS NOT NULL
               AND global_product_barcode_main != ''"
        );

        self::ok([
            'total_parents' => $total_parents,
            'parents_with_hsd' => $parents_with_hsd,
            'parents_with_barcode' => $parents_with_barcode,
            'percent_hsd' => $total_parents > 0 ? round(($parents_with_hsd / $total_parents) * 100, 1) : 0,
            'percent_barcode' => $total_parents > 0 ? round(($parents_with_barcode / $total_parents) * 100, 1) : 0,
        ]);
    }
}
