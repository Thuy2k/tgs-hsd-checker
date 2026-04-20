<?php
/**
 * TGS HSD Checker - AJAX Handler
 * 
 * Xử lý tất cả AJAX calls cho trang kiểm kê HSD.
 * Reuse logic từ TGS_Shop_Ajax_Product nhưng thêm các endpoint riêng.
 */

if (!defined('ABSPATH')) exit;

class TGS_HSD_Checker_Ajax
{
    const NONCE_ACTION = 'tgs_hsd_checker_nonce';

    public static function register()
    {
        // Tìm sản phẩm cha bằng barcode NSX
        add_action('wp_ajax_hsd_checker_lookup_barcode', [__CLASS__, 'lookup_barcode']);
        // Tìm sản phẩm cha bằng tên
        add_action('wp_ajax_hsd_checker_search_product', [__CLASS__, 'search_product']);
        // Map barcode NSX vào sản phẩm cha
        add_action('wp_ajax_hsd_checker_map_barcode', [__CLASS__, 'map_barcode']);
        // Lấy danh sách HSD children
        add_action('wp_ajax_hsd_checker_get_children', [__CLASS__, 'get_children']);
        // Tạo HSD child mới (reuse logic hsd_create_child)
        add_action('wp_ajax_hsd_checker_create_child', [__CLASS__, 'create_child']);
        // Cập nhật số lượng HSD child
        add_action('wp_ajax_hsd_checker_update_quantity', [__CLASS__, 'update_quantity']);
        // Thống kê tiến độ kiểm kê
        add_action('wp_ajax_hsd_checker_get_stats', [__CLASS__, 'get_stats']);
    }

    // ─── HELPERS ──────────────────────────────────────────

    private static function check()
    {
        if (
            !current_user_can('manage_options') &&
            !current_user_can('manage_woocommerce') &&
            !current_user_can('edit_posts')
        ) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    private static function ok($data = [], $msg = '')
    {
        if ($msg) $data['message'] = $msg;
        wp_send_json_success($data);
    }

    private static function fail($msg = 'Lỗi', $code = 400)
    {
        wp_send_json_error(['message' => $msg], $code);
    }

    // ─── 1. LOOKUP BARCODE NSX → TÌM SẢN PHẨM CHA ──────

    public static function lookup_barcode()
    {
        self::check();
        $barcode = sanitize_text_field($_POST['barcode'] ?? '');
        if (empty($barcode)) self::fail('Thiếu mã barcode.');

        global $wpdb;
        $table = TGS_TABLE_LOCAL_PRODUCT_NAME;

        // Tìm sản phẩm cha (parent_sku IS NULL) + không theo dõi + barcode khớp
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT local_product_name_id, local_product_name, local_product_sku,
                    local_product_barcode_main, local_product_barcode_url_main,
                    local_product_quantity_no_tracking, local_product_thumbnail,
                    local_product_unit, local_product_cat_id
             FROM {$table}
             WHERE local_product_barcode_main = %s
               AND (local_product_parent_sku IS NULL OR local_product_parent_sku = '')
               AND (local_product_is_tracking IS NULL OR local_product_is_tracking = 0)
               AND (is_deleted IS NULL OR is_deleted = 0)
             LIMIT 1",
            $barcode
        ), ARRAY_A);

        if ($parent) {
            // Đếm số con HSD đã có
            $child_count = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE local_product_parent_sku = %s
                   AND (is_deleted IS NULL OR is_deleted = 0)",
                $parent['local_product_sku']
            ));
            $parent['hsd_child_count'] = $child_count;
            self::ok(['found' => true, 'product' => $parent]);
        } else {
            self::ok(['found' => false, 'barcode' => $barcode]);
        }
    }

    // ─── 2. TÌM SẢN PHẨM CHA BẰNG TÊN (autocomplete) ──

    public static function search_product()
    {
        self::check();
        $keyword = sanitize_text_field($_POST['keyword'] ?? '');
        if (strlen($keyword) < 2) self::fail('Nhập ít nhất 2 ký tự.');

        global $wpdb;
        $table = TGS_TABLE_LOCAL_PRODUCT_NAME;
        $like = '%' . $wpdb->esc_like($keyword) . '%';

        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT local_product_name_id, local_product_name, local_product_sku,
                    local_product_barcode_main, local_product_quantity_no_tracking,
                    local_product_thumbnail, local_product_unit
             FROM {$table}
             WHERE (local_product_parent_sku IS NULL OR local_product_parent_sku = '')
               AND (local_product_is_tracking IS NULL OR local_product_is_tracking = 0)
               AND (is_deleted IS NULL OR is_deleted = 0)
               AND (local_product_name LIKE %s OR local_product_sku LIKE %s)
             ORDER BY local_product_name ASC
             LIMIT 20",
            $like, $like
        ), ARRAY_A);

        self::ok(['products' => $products]);
    }

    // ─── 3. MAP BARCODE NSX VÀO SẢN PHẨM CHA ───────────

    public static function map_barcode()
    {
        self::check();
        $product_id = intval($_POST['product_id'] ?? 0);
        $barcode = sanitize_text_field($_POST['barcode'] ?? '');
        if (!$product_id || empty($barcode)) self::fail('Thiếu thông tin.');

        global $wpdb;
        $table = TGS_TABLE_LOCAL_PRODUCT_NAME;

        // Verify: phải là sản phẩm cha, không theo dõi
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT local_product_name_id, local_product_sku, local_product_name,
                    local_product_barcode_main
             FROM {$table}
             WHERE local_product_name_id = %d
               AND (local_product_parent_sku IS NULL OR local_product_parent_sku = '')
               AND (is_deleted IS NULL OR is_deleted = 0)",
            $product_id
        ));

        if (!$product) self::fail('Không tìm thấy sản phẩm cha.');

        // Check barcode đã được map cho SP khác chưa (trong cùng shop)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT local_product_name_id, local_product_name FROM {$table}
             WHERE local_product_barcode_main = %s
               AND local_product_name_id != %d
               AND (local_product_parent_sku IS NULL OR local_product_parent_sku = '')
               AND (is_deleted IS NULL OR is_deleted = 0)
             LIMIT 1",
            $barcode, $product_id
        ));

        if ($existing) {
            self::fail('Barcode này đã được gắn cho: ' . $existing->local_product_name);
        }

        // Cập nhật barcode_main cho sản phẩm cha
        $wpdb->update(
            $table,
            [
                'local_product_barcode_main' => $barcode,
                'updated_at' => current_time('mysql'),
            ],
            ['local_product_name_id' => $product_id]
        );

        // Cập nhật cho tất cả SP con (children) nếu chưa có barcode
        if (!empty($product->local_product_sku)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET local_product_barcode_main = %s, updated_at = %s
                 WHERE local_product_parent_sku = %s
                   AND (local_product_barcode_main IS NULL OR local_product_barcode_main = '')
                   AND (is_deleted IS NULL OR is_deleted = 0)",
                $barcode, current_time('mysql'), $product->local_product_sku
            ));
        }

        self::ok([
            'product_id' => $product_id,
            'product_name' => $product->local_product_name,
            'barcode' => $barcode,
        ], 'Đã gắn barcode thành công!');
    }

    // ─── 4. LẤY DANH SÁCH HSD CHILDREN ──────────────────

    public static function get_children()
    {
        self::check();
        $parent_sku = sanitize_text_field($_POST['parent_sku'] ?? '');
        if (empty($parent_sku)) self::fail('Thiếu SKU cha.');

        global $wpdb;
        $table = TGS_TABLE_LOCAL_PRODUCT_NAME;

        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT local_product_name_id, local_product_name, local_product_sku,
                    local_product_hsd, local_product_special_barcode,
                    local_product_quantity_no_tracking, local_product_parent_sku,
                    created_at, updated_at
             FROM {$table}
             WHERE local_product_parent_sku = %s
               AND (is_deleted IS NULL OR is_deleted = 0)
             ORDER BY local_product_hsd IS NULL ASC, local_product_hsd ASC",
            $parent_sku
        ), ARRAY_A);

        $total_hsd_qty = 0;
        $total_all_qty = 0;
        foreach ($children as &$c) {
            $c['local_product_quantity_no_tracking'] = floatval($c['local_product_quantity_no_tracking']);
            $total_all_qty += $c['local_product_quantity_no_tracking'];
            if (!empty($c['local_product_hsd'])) {
                $total_hsd_qty += $c['local_product_quantity_no_tracking'];
            }
        }
        unset($c);

        self::ok([
            'children' => $children,
            'total_hsd_qty' => $total_hsd_qty,
            'total_all_qty' => $total_all_qty,
            'count' => count($children),
        ]);
    }

    // ─── 5. TẠO HSD CHILD (reuse logic) ─────────────────

    public static function create_child()
    {
        self::check();
        $parent_id = intval($_POST['parent_product_id'] ?? 0);
        $hsd = sanitize_text_field($_POST['hsd'] ?? '');
        $quantity = floatval($_POST['quantity'] ?? 0);
        $is_no_hsd = empty($hsd);

        if (!$parent_id) self::fail('Thiếu ID sản phẩm cha.');

        global $wpdb;
        $table = TGS_TABLE_LOCAL_PRODUCT_NAME;

        // Load parent
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE local_product_name_id = %d AND (is_deleted IS NULL OR is_deleted = 0)",
            $parent_id
        ));
        if (!$parent) self::fail('Không tìm thấy sản phẩm cha.');
        if (!empty($parent->local_product_parent_sku)) self::fail('Đây là sản phẩm con, không thể tạo con của con.');

        $parent_sku = $parent->local_product_sku;
        if (empty($parent_sku)) self::fail('Sản phẩm cha chưa có SKU.');

        // Validate HSD
        $hsd_date = null;
        if (!$is_no_hsd) {
            $hsd_date = date_create_from_format('Y-m-d', $hsd);
            if (!$hsd_date) self::fail('HSD không đúng định dạng (YYYY-MM-DD).');
        }

        // Build child SKU
        $child_sku = $is_no_hsd
            ? $parent_sku . '-no-hsd'
            : $parent_sku . '-' . date_format($hsd_date, 'dmy');

        // Check trùng SKU
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT local_product_name_id FROM {$table}
             WHERE local_product_sku = %s AND (is_deleted IS NULL OR is_deleted = 0)",
            $child_sku
        ));

        if ($existing) {
            // Trả về SP đã tồn tại thay vì lỗi → để UI cho phép cộng SL
            $existing_child = $wpdb->get_row($wpdb->prepare(
                "SELECT local_product_name_id, local_product_sku, local_product_hsd,
                        local_product_quantity_no_tracking
                 FROM {$table} WHERE local_product_name_id = %d",
                $existing
            ), ARRAY_A);
            self::ok([
                'already_exists' => true,
                'child' => $existing_child,
            ], 'HSD này đã tồn tại. Bạn có muốn cộng thêm số lượng?');
            return;
        }

        // ── Check global HSD identifiers ──
        $global_hsd = $is_no_hsd ? '1970-01-01' : $hsd;
        $global_table = TGS_TABLE_GLOBAL_PRODUCT_HSD_IDENTIFIERS;

        $global_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$global_table}
             WHERE local_product_parent_sku = %s AND local_product_hsd = %s LIMIT 1",
            $parent_sku, $global_hsd
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

        // Clone parent → child
        $child_data = [
            'global_product_name_id' => $parent->global_product_name_id,
            'global_blog_id' => $parent->global_blog_id,
            'global_product_name' => $parent->global_product_name,
            'source_blog_id' => $parent->source_blog_id ?? get_current_blog_id(),
            'local_product_name' => $parent->local_product_name,
            'local_product_thumbnail' => $parent->local_product_thumbnail ?? '',
            'local_product_cat_id' => $parent->local_product_cat_id,
            'local_product_list_category_id' => $parent->local_product_list_category_id,
            'local_product_category_path' => $parent->local_product_category_path ?? '',
            'local_product_description' => $parent->local_product_description ?? '',
            'local_product_content' => $parent->local_product_content ?? '',
            'local_product_status' => $parent->local_product_status ?? 1,
            'local_product_price' => $parent->local_product_price ?? 0,
            'local_product_tax' => $parent->local_product_tax ?? 0,
            'local_product_price_after_tax' => $parent->local_product_price_after_tax ?? 0,
            'local_product_meta' => $parent->local_product_meta ?? '{}',
            'local_product_point' => $parent->local_product_point ?? 0,
            'local_product_barcode_main' => $parent->local_product_barcode_main ?? '',
            'local_product_barcode_url_main' => $parent->local_product_barcode_url_main ?? '',
            'local_product_is_tracking' => 0,
            'local_product_sku' => $child_sku,
            'local_product_unit' => $parent->local_product_unit ?? '',
            'local_product_warehouse_htsoft' => $parent->local_product_warehouse_htsoft ?? '',
            'local_product_parent_sku' => $parent_sku,
            'local_product_hsd' => $is_no_hsd ? null : $hsd,
            'local_product_special_barcode' => $barcode_data['barcode'],
            'local_product_special_barcode_url' => $barcode_data['barcode_url'],
            'local_product_quantity_no_tracking' => $quantity,
            'local_product_tag' => $parent->local_product_tag ?? 0,
            'weighted_avg_cost' => $parent->weighted_avg_cost ?? 0,
            'user_id' => get_current_user_id(),
            'is_deleted' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $new_id = TGS_Shop_Query::insert(TGS_TABLE_LOCAL_PRODUCT_NAME, $child_data);
        if (!$new_id) self::fail('Không thể tạo sản phẩm con.');

        // ── Auto-create no-hsd child if creating HSD child ──
        $auto_no_hsd = null;
        if (!$is_no_hsd) {
            $no_hsd_sku = $parent_sku . '-no-hsd';
            $no_hsd_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE local_product_sku = %s AND (is_deleted IS NULL OR is_deleted = 0)",
                $no_hsd_sku
            ));

            if (!$no_hsd_exists) {
                // Check global
                $global_nohsd = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$global_table}
                     WHERE local_product_parent_sku = %s AND local_product_hsd = '1970-01-01' LIMIT 1",
                    $parent_sku
                ));

                if ($global_nohsd) {
                    $nohsd_barcode = [
                        'barcode' => $global_nohsd->special_barcode,
                        'barcode_url' => $global_nohsd->special_barcode_url,
                    ];
                } else {
                    $nohsd_barcode = tgs_shop_generate_product_barcode_data();
                    $wpdb->insert($global_table, [
                        'local_product_parent_sku' => $parent_sku,
                        'local_product_child_sku' => $no_hsd_sku,
                        'local_product_hsd' => '1970-01-01',
                        'special_barcode' => $nohsd_barcode['barcode'],
                        'special_barcode_url' => $nohsd_barcode['barcode_url'],
                        'source_blog_id' => get_current_blog_id(),
                        'user_id' => get_current_user_id(),
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ]);
                }

                $nohsd_data = $child_data;
                $nohsd_data['local_product_sku'] = $no_hsd_sku;
                $nohsd_data['local_product_hsd'] = null;
                $nohsd_data['local_product_special_barcode'] = $nohsd_barcode['barcode'];
                $nohsd_data['local_product_special_barcode_url'] = $nohsd_barcode['barcode_url'];
                $nohsd_data['local_product_quantity_no_tracking'] = 0;

                $auto_no_hsd_id = TGS_Shop_Query::insert(TGS_TABLE_LOCAL_PRODUCT_NAME, $nohsd_data);
                if ($auto_no_hsd_id) {
                    $auto_no_hsd = ['id' => $auto_no_hsd_id, 'sku' => $no_hsd_sku];
                }
            }
        }

        $resp = [
            'id' => $new_id,
            'sku' => $child_sku,
            'special_barcode' => $barcode_data['barcode'],
            'is_no_hsd' => $is_no_hsd,
            'quantity' => $quantity,
        ];
        if ($auto_no_hsd) {
            $resp['auto_no_hsd'] = $auto_no_hsd;
        }

        self::ok($resp, 'Tạo HSD thành công!');
    }

    // ─── 6. CẬP NHẬT SỐ LƯỢNG ───────────────────────────

    public static function update_quantity()
    {
        self::check();
        $child_id = intval($_POST['child_product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $mode = sanitize_text_field($_POST['mode'] ?? 'set'); // 'set' or 'add'

        if (!$child_id) self::fail('Thiếu ID sản phẩm con.');

        global $wpdb;
        $table = TGS_TABLE_LOCAL_PRODUCT_NAME;

        $child = $wpdb->get_row($wpdb->prepare(
            "SELECT local_product_name_id, local_product_parent_sku,
                    local_product_quantity_no_tracking, local_product_hsd, local_product_sku
             FROM {$table}
             WHERE local_product_name_id = %d AND (is_deleted IS NULL OR is_deleted = 0)",
            $child_id
        ));

        if (!$child) self::fail('Không tìm thấy sản phẩm con.');
        if (empty($child->local_product_parent_sku)) self::fail('Đây không phải sản phẩm con.');

        $old_qty = floatval($child->local_product_quantity_no_tracking);
        $new_qty = ($mode === 'add') ? $old_qty + $quantity : $quantity;

        // Cảnh báo nếu số lượng bất hợp lý (>500 hoặc <0) - vẫn cho thao tác
        $warning = '';
        if ($new_qty < 0) {
            $warning = 'Số lượng âm (' . $new_qty . '). Vẫn được lưu nhưng hãy kiểm tra lại.';
        } elseif ($new_qty > 500) {
            $warning = 'Số lượng rất lớn (' . $new_qty . '). Vẫn được lưu nhưng hãy kiểm tra lại.';
        }

        $wpdb->update(
            $table,
            [
                'local_product_quantity_no_tracking' => $new_qty,
                'updated_at' => current_time('mysql'),
            ],
            ['local_product_name_id' => $child_id]
        );

        self::ok([
            'child_id' => $child_id,
            'old_qty' => $old_qty,
            'new_qty' => $new_qty,
            'mode' => $mode,
            'warning' => $warning,
        ], $warning ?: 'Đã cập nhật số lượng!');
    }

    // ─── 7. THỐNG KÊ TIẾN ĐỘ ────────────────────────────

    public static function get_stats()
    {
        self::check();
        global $wpdb;
        $table = TGS_TABLE_LOCAL_PRODUCT_NAME;

        // Tổng SP cha (không tracking)
        $total_parents = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE (local_product_parent_sku IS NULL OR local_product_parent_sku = '')
               AND (local_product_is_tracking IS NULL OR local_product_is_tracking = 0)
               AND (is_deleted IS NULL OR is_deleted = 0)"
        );

        // SP cha đã có ít nhất 1 HSD child
        $parents_with_hsd = (int)$wpdb->get_var(
            "SELECT COUNT(DISTINCT p.local_product_sku) FROM {$table} p
             INNER JOIN {$table} c ON c.local_product_parent_sku = p.local_product_sku
               AND (c.is_deleted IS NULL OR c.is_deleted = 0)
             WHERE (p.local_product_parent_sku IS NULL OR p.local_product_parent_sku = '')
               AND (p.local_product_is_tracking IS NULL OR p.local_product_is_tracking = 0)
               AND (p.is_deleted IS NULL OR p.is_deleted = 0)"
        );

        // SP cha đã có barcode NSX
        $parents_with_barcode = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE (local_product_parent_sku IS NULL OR local_product_parent_sku = '')
               AND (local_product_is_tracking IS NULL OR local_product_is_tracking = 0)
               AND (is_deleted IS NULL OR is_deleted = 0)
               AND local_product_barcode_main IS NOT NULL
               AND local_product_barcode_main != ''"
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
