<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * رویدادهای ناشناس (بازدید کارت، کلیک خرید، اشتراک)، گزارش اشتباه، پرسش‌های بی‌پاسخ، هوا، و بازبینی دوره‌ای قیمت‌ها.
 * هیچ اطلاعات شخصی ذخیره نمی‌شود؛ فقط یک هش کوتاه برای جلوگیری از شمارش تکراری و سوءاستفاده.
 */

function tfy_ev_table()
{
    global $wpdb;
    return $wpdb->prefix . 'tfy_events';
}

function tfy_ev_ensure()
{
    if (get_option('tfy_ev_db') === '1') {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = tfy_ev_table();
    dbDelta("CREATE TABLE $t (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        type varchar(16) NOT NULL,
        item_id bigint(20) unsigned NOT NULL DEFAULT 0,
        ref varchar(40) NOT NULL DEFAULT '',
        txt varchar(400) NOT NULL DEFAULT '',
        flag tinyint(1) NOT NULL DEFAULT 0,
        ip char(12) NOT NULL DEFAULT '',
        created int(10) unsigned NOT NULL,
        PRIMARY KEY  (id),
        KEY type_created (type, created),
        KEY item (item_id)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('tfy_ev_db', '1', false);
}

function tfy_ip_hash()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
    return substr(md5($ip . wp_salt()), 0, 12);
}

function tfy_ev_add($type, $item_id, $ref = '', $txt = '', $flag = 0)
{
    tfy_ev_ensure();
    global $wpdb;
    $wpdb->insert(tfy_ev_table(), array(
        'type' => $type, 'item_id' => (int) $item_id, 'ref' => mb_substr((string) $ref, 0, 40), 'txt' => mb_substr((string) $txt, 0, 400),
        'flag' => (int) $flag, 'ip' => tfy_ip_hash(), 'created' => time(),
    ));
    if (wp_rand(1, 200) === 1) { // پاکسازی گاه‌به‌گاه: رویدادهای قدیمی‌تر از ۱۸۰ روز
        $wpdb->query($wpdb->prepare('DELETE FROM ' . tfy_ev_table() . " WHERE type <> 'report' AND created < %d", time() - 180 * DAY_IN_SECONDS));
    }
}

add_action('rest_api_init', function () {
    $ns = 'tafrihyab/v1';
    register_rest_route($ns, '/event', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'tfy_rest_event'));
    register_rest_route($ns, '/items/(?P<id>\d+)/report', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => 'tfy_rest_report'));
    register_rest_route($ns, '/weather', array('methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'tfy_rest_weather'));
});

function tfy_rest_event(WP_REST_Request $req)
{
    if (current_user_can('manage_options')) {
        return array('ok' => 1); // بازدید مدیر شمرده نمی‌شود
    }
    $p = (array) $req->get_json_params();
    $type = isset($p['type']) ? sanitize_key($p['type']) : '';
    if (!in_array($type, array('view', 'share'), true)) {
        return new WP_Error('tfy_ev', 'نامعتبر', array('status' => 400));
    }
    $id = (int) ($p['id'] ?? 0);
    $ref = '';
    if (!tfy_get_item($id)) {
        return new WP_Error('tfy_ev', 'نامعتبر', array('status' => 400));
    }
    $k = 'tfy_ev_' . md5(tfy_ip_hash() . $type . $id . $ref);
    if (get_transient($k)) {
        return array('ok' => 1);
    }
    set_transient($k, 1, HOUR_IN_SECONDS);
    tfy_ev_add($type, $id, $ref);
    return array('ok' => 1);
}

function tfy_report_fields()
{
    return array('price' => 'قیمت', 'hours' => 'ساعت کاری', 'location' => 'لوکیشن', 'phone' => 'تلفن', 'website' => 'وب‌سایت', 'image' => 'عکس', 'other' => 'مورد دیگر');
}

function tfy_rest_report(WP_REST_Request $req)
{
    $p = (array) $req->get_json_params();
    if (!empty($p['hp'])) {
        return new WP_Error('tfy_spam', 'نامعتبر', array('status' => 400));
    }
    $it = tfy_get_item((int) $req['id']);
    if (!$it) {
        return new WP_Error('tfy_notfound', 'مورد پیدا نشد.', array('status' => 404));
    }
    $field = isset($p['field']) ? sanitize_key($p['field']) : 'other';
    if (!isset(tfy_report_fields()[$field])) {
        $field = 'other';
    }
    $note = isset($p['note']) ? mb_substr(trim(sanitize_text_field((string) $p['note'])), 0, 300) : '';
    $k = 'tfy_rp_' . tfy_ip_hash();
    $n = (int) get_transient($k);
    if ($n >= 5) {
        return new WP_Error('tfy_rate', 'تعداد گزارش‌های شما در این ساعت به حد مجاز رسید.', array('status' => 429));
    }
    set_transient($k, $n + 1, HOUR_IN_SECONDS);
    tfy_ev_add('report', $it['id'], $field, $note, 0);
    return array('ok' => 1);
}

/* ---------------------------- هوا ---------------------------- */

function tfy_weather_label($code)
{
    if ($code === 0) {
        return array('آفتابی', false);
    }
    if ($code <= 3) {
        return array('کمی ابری', false);
    }
    if ($code === 45 || $code === 48) {
        return array('مه‌آلود', false);
    }
    if ($code >= 51 && $code <= 67) {
        return array('بارانی', true);
    }
    if ($code >= 71 && $code <= 77) {
        return array('برفی', true);
    }
    if ($code >= 80 && $code <= 82) {
        return array('رگبار', true);
    }
    if ($code >= 85 && $code <= 86) {
        return array('برفی', true);
    }
    if ($code >= 95) {
        return array('طوفانی', true);
    }
    return array('ابری', false);
}

function tfy_rest_weather()
{
    if (get_option('tfy_weather_on', '1') !== '1') {
        return array('ok' => false);
    }
    $c = get_transient('tfy_weather');
    if ($c) {
        return $c;
    }
    $r = wp_safe_remote_get('https://api.open-meteo.com/v1/forecast?latitude=35.69&longitude=51.39&current=temperature_2m,weather_code&daily=precipitation_probability_max&timezone=Asia%2FTehran&forecast_days=1', array('timeout' => 8));
    $out = array('ok' => false);
    if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) {
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (isset($j['current']['temperature_2m'], $j['current']['weather_code'])) {
            $prob = isset($j['daily']['precipitation_probability_max'][0]) ? (int) $j['daily']['precipitation_probability_max'][0] : 0;
            list($label, $wet) = tfy_weather_label((int) $j['current']['weather_code']);
            $out = array('ok' => true, 'temp' => (int) round($j['current']['temperature_2m']), 'label' => $label, 'rain_prob' => $prob, 'rainy' => $wet || $prob >= 60);
        }
    }
    set_transient('tfy_weather', $out, $out['ok'] ? 30 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
    return $out;
}

/* ---------------------------- بازبینی دوره‌ای قیمت‌ها ---------------------------- */

add_filter('cron_schedules', function ($s) {
    return $s;
});

add_action('init', function () {
    $on = get_option('tfy_auto_on', '0') === '1';
    $next = wp_next_scheduled('tfy_cron_reprice');
    if ($on && !$next) {
        wp_schedule_event(time() + 300, 'hourly', 'tfy_cron_reprice');
    } elseif (!$on && $next) {
        wp_clear_scheduled_hook('tfy_cron_reprice');
    }
});

function tfy_auto_log($line)
{
    $l = get_option('tfy_auto_log', array());
    $l = is_array($l) ? $l : array();
    array_unshift($l, wp_date('Y-m-d H:i', time()) . ' — ' . $line);
    update_option('tfy_auto_log', array_slice($l, 0, 40), false);
}

add_action('tfy_cron_reprice', function () {
    if (get_option('tfy_auto_on', '0') !== '1' || !tfy_ai_enabled() || get_transient('tfy_auto_pause')) {
        return;
    }
    @set_time_limit(170);
    $per_day = max(1, min(100, (int) get_option('tfy_auto_per_day', 10)));
    $age = max(7, min(365, (int) get_option('tfy_auto_age', 30)));
    $st = get_option('tfy_auto_state', array());
    $today = gmdate('Y-m-d');
    if (!is_array($st) || ($st['d'] ?? '') !== $today) {
        $st = array('d' => $today, 'n' => 0);
    }
    if ($st['n'] >= $per_day) {
        return;
    }
    global $wpdb;
    $cut = time() - $age * DAY_IN_SECONDS;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_tfy_prices_at'
         JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_tfy_prices_src' AND s.meta_value = 'ai'
         LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_tfy_price_review'
         WHERE p.post_type = %s AND p.post_status = 'publish' AND CAST(a.meta_value AS UNSIGNED) < %d
           AND (r.meta_value IS NULL OR CAST(r.meta_value AS UNSIGNED) < %d)
         ORDER BY CAST(a.meta_value AS UNSIGNED) ASC LIMIT 2",
        TFY_CPT, $cut, $cut
    ));
    tfy_hist_batch(tfy_hist_new_batch());
    foreach ((array) $ids as $id) {
        $r = tfy_enrich_item((int) $id, array('refresh' => true, 'images' => false, 'related' => false));
        $st['n']++;
        update_post_meta((int) $id, '_tfy_price_review', time());
        if (is_wp_error($r)) {
            tfy_auto_log('خطا برای کارت ' . $id . ': ' . mb_substr($r->get_error_message(), 0, 120));
            set_transient('tfy_auto_pause', 1, 3 * HOUR_IN_SECONDS); // سقف رایگان؛ چند ساعت صبر
            break;
        }
        $f = isset($r['filled']) ? implode('، ', $r['filled']) : '';
        tfy_auto_log(get_the_title($id) . ': ' . ($f !== '' ? 'به‌روز شد (' . $f . ')' : 'تغییری لازم نبود یا قیمت جدید تایید نشد'));
        if ($st['n'] >= $per_day) {
            break;
        }
    }
    update_option('tfy_auto_state', $st, false);
});

/* ---------------------------- مدیریت: گزارش‌ها و آمار ---------------------------- */

add_action('admin_post_tfy_report_act', function () {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('tfy_report_act');
    tfy_ev_ensure();
    global $wpdb;
    $id = (int) ($_GET['id'] ?? 0);
    $op = sanitize_key($_GET['op'] ?? '');
    if ($op === 'resolve') {
        $wpdb->update(tfy_ev_table(), array('flag' => 1), array('id' => $id, 'type' => 'report'));
    } elseif ($op === 'delete') {
        $wpdb->delete(tfy_ev_table(), array('id' => $id));
    } elseif ($op === 'clear_ask') {
        $wpdb->delete(tfy_ev_table(), array('type' => 'ask', 'item_id' => (int) ($_GET['item'] ?? 0), 'flag' => 1));
    }
    wp_safe_redirect(tfy_admin_url('stats'));
    exit;
});

function tfy_tab_stats()
{
    tfy_ev_ensure();
    global $wpdb;
    $t = tfy_ev_table();
    $since = time() - 30 * DAY_IN_SECONDS;
    $nonce = wp_create_nonce('tfy_report_act');
    $act = function ($op, $id, $extra = '') use ($nonce) {
        return esc_url(admin_url('admin-post.php?action=tfy_report_act&op=' . $op . '&id=' . (int) $id . $extra . '&_wpnonce=' . $nonce));
    };
    $name = function ($id) {
        $p = get_post((int) $id);
        return $p ? $p->post_title : ('#' . (int) $id);
    };
    $counts = $wpdb->get_results($wpdb->prepare("SELECT type, COUNT(*) c FROM $t WHERE created >= %d AND type <> 'report' GROUP BY type", $since), OBJECT_K);
    $c = function ($k) use ($counts) {
        return isset($counts[$k]) ? (int) $counts[$k]->c : 0;
    };
    ?>
    <div class="box" style="max-width:1100px">
        <h2>آمار ۳۰ روز اخیر</h2>
        <p>بازدید کارت: <strong><?php echo (int) $c('view'); ?></strong> — اشتراک‌گذاری: <strong><?php echo (int) $c('share'); ?></strong> — پرسش از هوش مصنوعی: <strong><?php echo (int) $c('ask'); ?></strong></p>
        <p class="muted">بازدید مدیر و بازدید تکراری یک نفر از یک کارت در یک ساعت شمرده نمی‌شود. اطلاعات شخصی ذخیره نمی‌شود.</p>
        <?php
        $top = $wpdb->get_results($wpdb->prepare("SELECT item_id, COUNT(*) c FROM $t WHERE type='view' AND created >= %d GROUP BY item_id ORDER BY c DESC LIMIT 15", $since));
        if ($top) {
            echo '<h3>پربازدیدترین کارت‌ها</h3><table class="widefat striped" style="max-width:560px"><tbody>';
            foreach ($top as $r) {
                echo '<tr><td>' . esc_html($name($r->item_id)) . '</td><td>' . (int) $r->c . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        ?>
    </div>
    <div class="box" style="max-width:1100px">
        <h2>پرسش‌هایی که کارت جوابشان را نداشت</h2>
        <p class="muted">این‌ها نشان می‌دهند چه اطلاعاتی در کارت‌ها کم است. اطلاعات کارت را در اکسل یا ویرایش کامل کنید.</p>
        <?php
        $na = $wpdb->get_results($wpdb->prepare("SELECT item_id, COUNT(*) c, GROUP_CONCAT(txt SEPARATOR ' | ') qs FROM $t WHERE type='ask' AND flag=1 AND created >= %d GROUP BY item_id ORDER BY c DESC LIMIT 30", $since));
        if (!$na) {
            echo '<p>موردی نیست.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>کارت</th><th>تعداد</th><th>پرسش‌ها</th><th></th></tr></thead><tbody>';
            foreach ($na as $r) {
                echo '<tr><td>' . esc_html($name($r->item_id)) . '</td><td>' . (int) $r->c . '</td><td>' . esc_html(mb_substr($r->qs, 0, 400)) . '</td><td><a class="button button-small" href="' . $act('clear_ask', 0, '&item=' . (int) $r->item_id) . '">پاک شد</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        ?>
    </div>
    <div class="box" style="max-width:1100px">
        <h2>گزارش اشتباه از بازدیدکننده‌ها</h2>
        <?php
        $rp = $wpdb->get_results("SELECT * FROM $t WHERE type='report' ORDER BY flag ASC, id DESC LIMIT 100");
        if (!$rp) {
            echo '<p>گزارشی نیست.</p>';
        } else {
            $fl = tfy_report_fields();
            echo '<table class="widefat striped"><thead><tr><th>کارت</th><th>بخش</th><th>توضیح</th><th>تاریخ</th><th></th></tr></thead><tbody>';
            foreach ($rp as $r) {
                echo '<tr' . ($r->flag ? ' style="opacity:.5"' : '') . '><td>' . esc_html($name($r->item_id)) . '</td><td>' . esc_html(isset($fl[$r->ref]) ? $fl[$r->ref] : $r->ref) . '</td><td>' . esc_html($r->txt) . '</td><td>' . esc_html(wp_date('Y-m-d', (int) $r->created)) . '</td><td>'
                    . ($r->flag ? 'حل شد ' : '<a class="button button-small" href="' . $act('resolve', $r->id) . '">حل شد</a> ') . '<a class="button button-small" href="' . $act('delete', $r->id) . '">حذف</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        ?>
    </div>
    <div class="box" style="max-width:1100px">
        <h2>گزارش بازبینی خودکار قیمت‌ها</h2>
        <?php
        $lg = get_option('tfy_auto_log', array());
        echo $lg ? '<pre style="white-space:pre-wrap">' . esc_html(implode("\n", (array) $lg)) . '</pre>' : '<p>هنوز اجرا نشده یا غیرفعال است (تنظیمات).</p>';
        ?>
    </div>
    <?php
}
