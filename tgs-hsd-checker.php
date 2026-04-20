<?php
/**
 * Plugin Name: TGS HSD Checker
 * Description: Kiểm kê HSD sản phẩm sữa nước nhanh bằng scan barcode. Dùng cho team đi kiểm thực tế.
 * Version: 1.0.0
 * Author: TGS Team
 * Requires Plugins: tgs_shop_management
 */

if (!defined('ABSPATH')) exit;

define('TGS_HSD_CHECKER_VERSION', '1.0.0');
define('TGS_HSD_CHECKER_DIR', plugin_dir_path(__FILE__));
define('TGS_HSD_CHECKER_URL', plugin_dir_url(__FILE__));

class TGS_HSD_Checker
{
    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Wait for tgs_shop_management to load constants
        add_action('plugins_loaded', [$this, 'init'], 20);
    }

    public function init()
    {
        // Check dependency
        if (!defined('TGS_SHOP_PLUGIN_DIR')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>TGS HSD Checker</strong> cần plugin <em>TGS Shop Management</em> được kích hoạt.</p></div>';
            });
            return;
        }

        // Load AJAX handler
        require_once TGS_HSD_CHECKER_DIR . 'includes/class-ajax-hsd-checker.php';
        TGS_HSD_Checker_Ajax::register();

        // Hook into mega nav menu (Kho hàng section)
        add_action('tgs_shop_sidebar_menu', [$this, 'add_menu_item'], 10, 1);

        // Register route in TGS dashboard
        add_filter('tgs_shop_dashboard_routes', [$this, 'add_route']);
    }

    public function add_menu_item($current_view)
    {
        $active = ($current_view === 'hsd-checker') ? 'active' : '';
        $url = admin_url('admin.php?page=tgs-shop-management&view=hsd-checker');
        echo '<li><a class="tgs-menu-link ' . $active . '" href="' . esc_url($url) . '">
                <i class="bx bx-scan me-2"></i>Kiểm kê HSD
              </a></li>';
    }

    public function add_route($routes)
    {
        // Use absolute path so render_dashboard_page() finds the file via file_exists()
        $routes['hsd-checker'] = ['Kiểm kê HSD', TGS_HSD_CHECKER_DIR . 'views/page-hsd-checker.php'];
        return $routes;
    }
}

TGS_HSD_Checker::instance();
