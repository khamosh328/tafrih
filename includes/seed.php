<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ایمپورت اولیه از data/seed.json (همان ۱۸۸ ردیف فایل اکسل، نرمال‌شده).
 * هر ردیف با کلید _tfy_seed علامت می‌خورد تا اجرای مجدد، ردیف تکراری نسازد.
 */

function tfy_seed_data()
{
    static $d = null;
    if ($d === null) {
        $raw = @file_get_contents(TFY_DIR . 'data/seed.json');
        $d = $raw ? json_decode($raw, true) : array();
        if (!is_array($d)) {
            $d = array();
        }
    }
    return $d;
}

function tfy_seed_done_keys()
{
    global $wpdb;
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> 'trash'",
        '_tfy_seed',
        TFY_CPT
    ));
    return array_map('intval', (array) $rows);
}

function tfy_seed_status()
{
    $total = count(tfy_seed_data());
    $done = count(tfy_seed_done_keys());
    return array('total' => $total, 'done' => min($done, $total));
}

/**
 * @return int ایندکس بعدی برای ادامه (یا مجموع، یعنی تمام)
 */
function tfy_seed_run($offset, $limit)
{
    $data = tfy_seed_data();
    $done = array_flip(tfy_seed_done_keys());
    $end = min(count($data), $offset + $limit);
    for ($i = $offset; $i < $end; $i++) {
        if (isset($done[$i])) {
            continue;
        }
        $row = $data[$i];
        $img = isset($row['img_src']) ? $row['img_src'] : '';
        unset($row['img_src']);
        $id = tfy_save_item(0, $row);
        if (is_wp_error($id)) {
            continue;
        }
        update_post_meta($id, '_tfy_seed', $i);
        if ($img) {
            update_post_meta($id, '_tfy_img_src', esc_url_raw($img, array('http', 'https')));
        }
    }
    return $end;
}

add_action('wp_ajax_tfy_seed', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('tfy_admin', 'nonce', false)) {
        wp_send_json_error('forbidden', 403);
    }
    @set_time_limit(120);
    $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
    tfy_hist_pause(true);
    $next = tfy_seed_run($offset, 25);
    tfy_hist_pause(false);
    wp_send_json_success(array('next' => $next, 'status' => tfy_seed_status()));
});

/**
 * تصاویر اولیه: لینک تصویر هر مورد در متای _tfy_img_src است و روی سرور خودتان دانلود می‌شود.
 */
function tfy_pending_images_query($n)
{
    return get_posts(array(
        'post_type'      => TFY_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => $n,
        'fields'         => 'ids',
        'meta_query'     => array(array('key' => '_tfy_img_src', 'compare' => 'EXISTS')),
    ));
}

add_action('wp_ajax_tfy_images', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('tfy_admin', 'nonce', false)) {
        wp_send_json_error('forbidden', 403);
    }
    @set_time_limit(120);
    $ok = 0;
    $fail = 0;
    foreach (tfy_pending_images_query(4) as $id) {
        $url = get_post_meta($id, '_tfy_img_src', true);
        delete_post_meta($id, '_tfy_img_src');
        $g = get_post_meta($id, '_tfy_gallery', true);
        if (!empty($g)) {
            continue;
        }
        // عکسِ فایل اکسل فقط به‌صورت لینک نمایش داده می‌شود (آپلود در هاست با دکمه‌ی مدیر)؛ اینستاگرام رد می‌شود.
        $r = (esc_url_raw((string) $url, array('http', 'https')) && !tfy_bad_image_host($url))
            ? (bool) update_post_meta($id, '_tfy_gallery', array(esc_url_raw($url, array('http', 'https'))))
            : new WP_Error('tfy_bad', 'bad');
        if (is_wp_error($r)) {
            $fail++;
        } else {
            $ok++;
        }
    }
    $left = count(tfy_pending_images_query(500));
    wp_send_json_success(array('ok' => $ok, 'fail' => $fail, 'left' => $left));
});
