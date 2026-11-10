<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * تاریخچه تغییرات: قبل و بعد هر تغییر یک نسخه‌ی کامل از کارت ذخیره می‌شود و از تنظیمات افزونه
 * می‌توان هر تغییر (یا کل یک عملیات گروهی مثل ایمپورت) را برگرداند.
 * حذف کارت «انتقال به زباله‌دان» است تا با برگرداندن، عکس‌ها هم برگردند.
 */

const TFY_DB_VER = '2';
const TFY_HIST_MAX = 2000;

function tfy_hist_table()
{
    global $wpdb;
    return $wpdb->prefix . 'tfy_history';
}

function tfy_db_install()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = tfy_hist_table();
    $cs = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch VARCHAR(24) NOT NULL DEFAULT '',
        created DATETIME NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        action VARCHAR(20) NOT NULL DEFAULT '',
        label VARCHAR(200) NOT NULL DEFAULT '',
        item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        item_name VARCHAR(255) NOT NULL DEFAULT '',
        before_json LONGTEXT NULL,
        after_json LONGTEXT NULL,
        reverted TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY batch (batch),
        KEY item_id (item_id)
    ) $cs;");
    update_option('tfy_db_ver', TFY_DB_VER, false);
}

add_action('plugins_loaded', function () {
    if (get_option('tfy_db_ver') !== TFY_DB_VER) {
        tfy_db_install();
        // نسخه ۱ با PIN کار می‌کرد؛ حالا صفحه آزاد است.
        delete_option('tfy_pin_hash');
        delete_option('tfy_api_key');
        delete_option('tfy_model');
        delete_option('tfy_web_search');
    }
});

/** شناسه‌ی عملیات گروهی فعلی (ایمپورت، دستور هوش مصنوعی، ...). */
function tfy_hist_batch($set = null)
{
    static $b = '';
    if ($set !== null) {
        $b = $set;
    }
    return $b;
}

function tfy_hist_new_batch()
{
    return substr(md5(uniqid('', true)), 0, 16);
}

function tfy_hist_pause($on = null)
{
    static $p = false;
    if ($on !== null) {
        $p = (bool) $on;
    }
    return $p;
}

/** نسخه‌ی خام و کامل یک کارت (شامل شناسه‌ی عکس‌ها). */
function tfy_raw_snapshot($id)
{
    $post = get_post($id);
    if (!$post || $post->post_type !== TFY_CPT) {
        return null;
    }
    $meta = array();
    foreach (array_keys(tfy_fields()) as $k) {
        $v = get_post_meta($id, '_tfy_' . $k, true);
        if (metadata_exists('post', $id, '_tfy_' . $k)) {
            $meta[$k] = $v;
        }
    }
    return array('title' => $post->post_title, 'content' => $post->post_content, 'status' => $post->post_status, 'meta' => $meta);
}

function tfy_hist_record($action, $id, $before, $after, $label = '')
{
    global $wpdb;
    if (tfy_hist_pause()) {
        return;
    }
    $name = $after ? $after['title'] : ($before ? $before['title'] : '');
    $wpdb->insert(tfy_hist_table(), array(
        'batch'       => tfy_hist_batch(),
        'created'     => current_time('mysql', true),
        'user_id'     => get_current_user_id(),
        'action'      => $action,
        'label'       => mb_substr($label, 0, 200),
        'item_id'     => (int) $id,
        'item_name'   => mb_substr((string) $name, 0, 255),
        'before_json' => $before === null ? null : wp_json_encode($before),
        'after_json'  => $after === null ? null : wp_json_encode($after),
    ));
    // نگه داشتن آخرین N ردیف
    $cnt = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . tfy_hist_table());
    if ($cnt > TFY_HIST_MAX + 100) {
        $cut = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . tfy_hist_table() . ' ORDER BY id DESC LIMIT 1 OFFSET %d', TFY_HIST_MAX));
        if ($cut) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . tfy_hist_table() . ' WHERE id <= %d', $cut));
        }
    }
}

/**
 * اجرای یک تغییر روی کارت با ثبت در تاریخچه.
 * $fn باید شناسه‌ی کارت را برگرداند (برای ساخت جدید) یا هر مقدار دیگر.
 * @return mixed نتیجه‌ی $fn
 */
function tfy_track($id, $label, $fn)
{
    $before = $id ? tfy_raw_snapshot($id) : null;
    $res = $fn();
    $nid = $id ? $id : (is_int($res) ? $res : 0);
    if ($nid) {
        $after = tfy_raw_snapshot($nid);
        if ($before === null) {
            tfy_hist_record('create', $nid, null, $after, $label);
        } elseif (wp_json_encode($before) !== wp_json_encode($after)) {
            tfy_hist_record('update', $nid, $before, $after, $label);
        }
    }
    return $res;
}

function tfy_trash_tracked($id, $label)
{
    $before = tfy_raw_snapshot($id);
    if (!$before || $before['status'] === 'trash') {
        return false;
    }
    wp_trash_post($id);
    tfy_name_index(true);
    tfy_hist_record('trash', $id, $before, tfy_raw_snapshot($id), $label);
    return true;
}

/** بازگرداندن یک کارت به یک نسخه‌ی خام. */
function tfy_apply_snapshot($id, $snap)
{
    $status = isset($snap['status']) && $snap['status'] === 'trash' ? 'publish' : (isset($snap['status']) ? $snap['status'] : 'publish');
    $r = wp_update_post(array('ID' => $id, 'post_title' => $snap['title'], 'post_content' => $snap['content'], 'post_status' => $status), true);
    if (is_wp_error($r)) {
        return $r;
    }
    foreach (array_keys(tfy_fields()) as $k) {
        if (array_key_exists($k, $snap['meta'])) {
            update_post_meta($id, '_tfy_' . $k, $snap['meta'][$k]);
        } else {
            delete_post_meta($id, '_tfy_' . $k);
        }
    }
    tfy_name_index(true);
    return true;
}

/**
 * برگرداندن یک ردیف تاریخچه به حالت «قبل از آن تغییر».
 * @return true|WP_Error
 */
function tfy_hist_revert($hid)
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . tfy_hist_table() . ' WHERE id = %d', $hid), ARRAY_A);
    if (!$row) {
        return new WP_Error('tfy_h404', 'ردیف تاریخچه پیدا نشد.');
    }
    if ((int) $row['reverted']) {
        return new WP_Error('tfy_hdone', 'این تغییر قبلا برگردانده شده است.');
    }
    $id = (int) $row['item_id'];
    $before = $row['before_json'] ? json_decode($row['before_json'], true) : null;
    $cur = tfy_raw_snapshot($id);

    if ($before === null) {
        // ساخته‌شده بود؛ برگرداندن یعنی انتقال به زباله‌دان
        if ($cur && $cur['status'] !== 'trash') {
            wp_trash_post($id);
            tfy_name_index(true);
            tfy_hist_record('revert', $id, $cur, tfy_raw_snapshot($id), 'برگرداندن ساخت «' . $row['item_name'] . '»');
        }
    } else {
        if (!$cur) {
            return new WP_Error('tfy_hgone', 'این کارت دیگر وجود ندارد (از زباله‌دان هم پاک شده) و برگرداندنش ممکن نیست.');
        }
        $r = tfy_apply_snapshot($id, $before);
        if (is_wp_error($r)) {
            return $r;
        }
        tfy_hist_record('revert', $id, $cur, tfy_raw_snapshot($id), 'برگرداندن «' . $row['item_name'] . '»');
    }
    $wpdb->update(tfy_hist_table(), array('reverted' => 1), array('id' => $hid));
    return true;
}

function tfy_hist_revert_batch($batch)
{
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . tfy_hist_table() . " WHERE batch = %s AND reverted = 0 AND action <> 'revert' ORDER BY id DESC", $batch));
    $ok = 0;
    $fail = 0;
    tfy_hist_batch(tfy_hist_new_batch());
    foreach ($ids as $hid) {
        $r = tfy_hist_revert((int) $hid);
        if (is_wp_error($r)) {
            $fail++;
        } else {
            $ok++;
        }
    }
    return array('ok' => $ok, 'fail' => $fail);
}

function tfy_hist_list($page, $per = 40)
{
    global $wpdb;
    $off = max(0, ($page - 1) * $per);
    $t = tfy_hist_table();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, batch, created, user_id, action, label, item_id, item_name, reverted,
                (SELECT COUNT(*) FROM $t t2 WHERE t2.batch = $t.batch AND $t.batch <> '') AS batch_size
         FROM $t ORDER BY id DESC LIMIT %d OFFSET %d",
        $per,
        $off
    ), ARRAY_A);
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    return array('rows' => (array) $rows, 'total' => $total);
}
