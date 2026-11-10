<?php
if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('tafrihyab', function () {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow');
    }

    $ver = TFY_VER . '.' . (file_exists(TFY_DIR . 'assets/app.js') ? filemtime(TFY_DIR . 'assets/app.js') : '0');
    wp_enqueue_style('tafrihyab', TFY_URL . 'assets/app.css', array(), $ver);
    wp_enqueue_script('tafrihyab', TFY_URL . 'assets/app.js', array(), $ver, true);
    $cfg = array('rest' => esc_url_raw(rest_url('tafrihyab/v1/')), 'assets' => TFY_URL . 'assets/', 'tiles' => (string) get_option('tfy_map_tiles', '') ?: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
    $cfg['qa'] = tfy_qa_on() && tfy_qa_daily_cap() > 0;
    $cfg['weather'] = get_option('tfy_weather_on', '1') === '1';
    if (current_user_can('manage_options')) {
        // فقط وقتی مدیر وردپرس واردشده این صفحه را می‌بیند، دکمه‌های ویرایش و حذف فعال می‌شود؛ سرور هم خودش دسترسی را چک می‌کند.
        $cfg['canEdit'] = true;
        $cfg['nonce'] = wp_create_nonce('wp_rest');
    }
    wp_localize_script('tafrihyab', 'TFY_CFG', $cfg);

    return '<div class="tfy" id="tfy-root" dir="rtl"><noscript>برای استفاده از این صفحه، جاوااسکریپت باید فعال باشد.</noscript></div>';
});
