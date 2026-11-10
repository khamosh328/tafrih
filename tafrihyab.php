<?php
/**
 * Plugin Name: تفریح‌یاب (Tafrihyab)
 * Description: لیست شخصی تفریحات با فیلتر، هزینه و منو، تفریحات تکمیلی، تکمیل خودکار با Gemini، اکسل و تاریخچه تغییرات. شورت‌کد: [tafrihyab]
 * Version: 4.3.1
 * Author: احمد ادیب (Ahmad Adib)
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Text Domain: tafrihyab
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TFY_VER', '4.3.1');
define('TFY_FILE', __FILE__);
define('TFY_DIR', plugin_dir_path(__FILE__));
define('TFY_URL', plugin_dir_url(__FILE__));

require_once TFY_DIR . 'includes/core.php';
require_once TFY_DIR . 'includes/history.php';
require_once TFY_DIR . 'includes/ai.php';
require_once TFY_DIR . 'includes/media.php';
require_once TFY_DIR . 'includes/geo.php';
require_once TFY_DIR . 'includes/enrich.php';
require_once TFY_DIR . 'includes/excel.php';
require_once TFY_DIR . 'includes/rest.php';
require_once TFY_DIR . 'includes/qa.php';
require_once TFY_DIR . 'includes/extras.php';
require_once TFY_DIR . 'includes/seed.php';
require_once TFY_DIR . 'includes/admin.php';
require_once TFY_DIR . 'includes/shortcode.php';

register_activation_hook(__FILE__, function () {
    tfy_register_cpt();
    tfy_db_install();
    flush_rewrite_rules(false);
    if (function_exists('tfy_seed_run')) {
        tfy_hist_pause(true);
        @set_time_limit(120);
        tfy_seed_run(0, 400);
        tfy_hist_pause(false);
    }
});
