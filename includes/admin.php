<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * همه‌ی تنظیمات و ابزارهای مدیریتی در منوی مستقل «تفریح‌یاب» (نه تنظیمات کلی وردپرس).
 */

add_action('admin_menu', function () {
    add_menu_page('تفریح‌یاب', 'تفریح‌یاب', 'manage_options', 'tafrihyab', 'tfy_admin_page', 'dashicons-palmtree', 58);
});

function tfy_admin_tabs()
{
    return array(
        'settings' => 'تنظیمات و اتصال هوش مصنوعی',
        'cards'    => 'کارت‌ها و تایید پیشنهادها',
        'command'  => 'دستور هوش مصنوعی',
        'excel'    => 'اکسل (ورود و خروج)',
        'bulk'     => 'تکمیل کلی کارت‌ها',
        'add'      => 'افزودن تفریح',
        'stats'    => 'آمار، گزارش‌ها و پرسش‌ها',
        'history'  => 'تاریخچه تغییرات',
    );
}

function tfy_admin_url($tab = 'settings', $args = array())
{
    return add_query_arg(array_merge(array('page' => 'tafrihyab', 'tab' => $tab), $args), admin_url('admin.php'));
}

/* ---------------------------- ذخیره تنظیمات ---------------------------- */

add_action('admin_post_tfy_save_settings', function () {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('tfy_settings');
    $msg = array();
    $prov = isset($_POST['tfy_provider']) ? sanitize_key($_POST['tfy_provider']) : 'gemini';
    if (!isset(tfy_ai_providers()[$prov])) {
        $prov = 'gemini';
    }
    update_option('tfy_ai_provider', $prov, false);
    if (!empty($_POST['tfy_key_delete'])) {
        delete_option('tfy_ai_key');
        delete_option('tfy_gemini_key');
        $msg[] = 'keydel';
    } else {
        $key = isset($_POST['tfy_key']) ? trim((string) wp_unslash($_POST['tfy_key'])) : '';
        if ($key !== '') {
            update_option('tfy_ai_key', sanitize_text_field($key), false);
            $msg[] = 'keyok';
        }
    }
    update_option('tfy_ai_model', isset($_POST['tfy_model']) ? sanitize_text_field(wp_unslash($_POST['tfy_model'])) : '', false);
    update_option('tfy_ai_base', isset($_POST['tfy_base']) ? esc_url_raw(trim((string) wp_unslash($_POST['tfy_base']))) : '', false);
    if (tfy_ai_providers()[$prov]['search']) {
        update_option('tfy_gemini_search', !empty($_POST['tfy_search']) ? '1' : '0', false);
    }
    $tpl = isset($_POST['tfy_neshan_tpl']) ? trim((string) wp_unslash($_POST['tfy_neshan_tpl'])) : '';
    update_option('tfy_neshan_tpl', (strpos($tpl, '{lat}') !== false && strpos($tpl, '{lng}') !== false && tfy_is_neshan_url($tpl)) ? esc_url_raw($tpl) : '', false);
    update_option('tfy_img_target', max(1, min(8, (int) ($_POST['tfy_img_target'] ?? 2))), false);
    update_option('tfy_price_min_src', max(1, min(3, (int) ($_POST['tfy_price_min_src'] ?? 2))), false);
    update_option('tfy_weather_on', !empty($_POST['tfy_weather_on']) ? '1' : '0', false);
    update_option('tfy_auto_on', !empty($_POST['tfy_auto_on']) ? '1' : '0', false);
    update_option('tfy_auto_per_day', max(1, min(100, (int) ($_POST['tfy_auto_per_day'] ?? 10))), false);
    update_option('tfy_auto_age', max(7, min(365, (int) ($_POST['tfy_auto_age'] ?? 30))), false);
    update_option('tfy_qa_on', !empty($_POST['tfy_qa_on']) ? '1' : '0', false);
    update_option('tfy_qa_daily', max(0, min(5000, (int) ($_POST['tfy_qa_daily'] ?? 200))), false);
    $tiles = isset($_POST['tfy_map_tiles']) ? trim((string) wp_unslash($_POST['tfy_map_tiles'])) : '';
    update_option('tfy_map_tiles', (strpos($tiles, '{z}') !== false && strpos($tiles, '{x}') !== false && strpos($tiles, '{y}') !== false && preg_match('#^https://#', $tiles)) ? esc_url_raw($tiles) : '', false);
    $msg[] = 'saved';
    wp_safe_redirect(tfy_admin_url('settings', array('tfy_msg' => implode(',', $msg))));
    exit;
});

add_action('admin_post_tfy_export', function () {
    if (!current_user_can('manage_options')) {
        wp_die('forbidden');
    }
    check_admin_referer('tfy_export');
    @set_time_limit(120);
    $rows = tfy_export_table();
    $name = 'tafrihyab-' . gmdate('Y-m-d');
    $tmp = wp_tempnam('tfy-x') . '.xlsx';
    if (tfy_write_xlsx($rows, $tmp)) {
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
});

/* ---------------------------- AJAX ---------------------------- */

function tfy_ajax_guard()
{
    if (!current_user_can('manage_options') || !check_ajax_referer('tfy_admin', 'nonce', false)) {
        wp_send_json_error(array('message' => 'forbidden'), 403);
    }
    @set_time_limit(170);
}

function tfy_ajax_err($e)
{
    wp_send_json_error(array('message' => is_wp_error($e) ? $e->get_error_message() : (string) $e));
}

add_action('wp_ajax_tfy_test_ai', function () {
    tfy_ajax_guard();
    $r = tfy_ai_test();
    if (is_wp_error($r)) {
        tfy_ajax_err($r);
    }
    wp_send_json_success($r);
});

/* ----- دستور آزاد هوش مصنوعی ----- */

/** فقط فیلدهای مرتبط با دستور و فقط مقدارهای پر؛ هرچه کمتر بفرستیم توکن کمتر مصرف می‌شود. */
function tfy_cmd_topics()
{
    return array(
        'name' => 'نام', 'category' => 'دسته', 'city' => 'شهر', 'area' => 'محدوده', 'address' => 'آدرس', 'hours' => 'ساعت کاری', 'phone' => 'تلفن',
        'website' => 'وب‌سایت', 'description' => 'توضیح', 'costs' => 'هزینه‌های اصلی', 'costs_extra' => 'هزینه‌های فرعی', 'cost_note' => 'توضیح هزینه',
        'menu' => 'منو', 'times' => 'زمان مناسب', 'duration_h' => 'مدت', 'modes' => 'همراه', 'tags' => 'ویژگی‌ها', 'related' => 'تفریحات تکمیلی', 'notes' => 'یادداشت', 'extra' => 'ستون‌های دلخواه',
    );
}

function tfy_cmd_fields_for($instruction)
{
    $map = array(
        '/لوکیشن|نقشه|مپ|map|location|لینک/iu' => array('address', 'area'),
        '/آدرس|نشانی/u' => array('address', 'area'),
        '/ساعت/u' => array('hours'), '/تلفن|تماس|شماره/u' => array('phone'), '/سایت|وب|اینستاگرام/u' => array('website'),
        '/توضیح|متن/u' => array('description'), '/دسته/u' => array('category'), '/شهر/u' => array('city'), '/محدوده|محله|منطقه/u' => array('area'),
        '/هزینه|قیمت|بلیط|ورودی|رایگان|گران|ارزان/u' => array('cost_note', 'costs', 'costs_extra'),
        '/منو|menu/iu' => array('menu'), '/همراه|تنها|پارتنر|خانواده|دوست|گروه/u' => array('modes'), '/ویژگی|برچسب|کودک|پارکینگ|ویلچر|سرپوشیده|فضای باز|حیوان/u' => array('tags'),
        '/زمان|صبح|عصر|شب|مدت/u' => array('times', 'duration_h'), '/تکمیلی/u' => array('related'), '/یادداشت/u' => array('notes'),
        '/نام|اسم/u' => array('name'),
    );
    $f = array();
    foreach ($map as $re => $fields) {
        if (preg_match($re, $instruction)) {
            $f = array_merge($f, $fields);
        }
    }
    if (!$f) {
        $f = array('address', 'area', 'hours', 'phone', 'website', 'description', 'cost_note', 'times', 'modes', 'tags', 'duration_h', 'notes', 'costs', 'costs_extra', 'related');
    }
    return array_values(array_unique(array_merge(array('name', 'category', 'city'), $f)));
}

function tfy_cmd_item_for_ai($it, $fields)
{
    $d = array('id' => $it['id']);
    foreach ($fields as $f) {
        if ($f === 'extra') {
            continue;
        }
        $v = $it[$f];
        if ($f === 'related') {
            $v = wp_list_pluck($it['related'], 'name');
        }
        if ($f === 'description') {
            $v = mb_substr((string) $v, 0, 220);
        }
        if ($v === '' || $v === null || $v === array()) {
            continue;
        }
        $d[$f] = $v;
    }
    $labels = tfy_extra_fields();
    $ex = array();
    foreach ((array) $it['extra'] as $k => $v) {
        if (isset($labels[$k]) && $v !== '') {
            $ex[$labels[$k]] = $v;
        }
    }
    if ($ex) {
        $d['extra'] = $ex;
    }
    return $d;
}

function tfy_cmd_system($search)
{
    return "Edit a personal leisure-list (Iran) per the admin instruction. Input: JSON items. Output ONLY JSON: {\"updates\":[{\"id\":N,\"fields\":{changed fields only}}],\"notes\":\"one short Persian sentence\"}. "
        . "List only items that need a change; never repeat unchanged fields; never invent" . ($search ? " (verify via web search)" : " (no web search)") . "; unverifiable = leave. No delete/create. "
        . "Field types: strings (name,category,city,area,address,hours,phone,description,cost_note,notes); urls (website); duration_h 1|3|6|12; times " . wp_json_encode(tfy_times_allowed(), JSON_UNESCAPED_UNICODE)
        . "; modes " . wp_json_encode(tfy_modes_allowed()) . "; tags subset of " . wp_json_encode(tfy_tags_allowed()) . "; favorite,done bool; costs,costs_extra [{label,price(toman int,0 free,null unknown),note}]; menu [{cat,name,price}]; related [names]; extra {label:value}. Persian. "
        . "Never output location/map links or coordinates (the plugin handles them). website must be the place's own official site only (never Instagram/social).";
}

add_action('wp_ajax_tfy_cmd_run', function () {
    tfy_ajax_guard();
    if (!tfy_ai_enabled()) {
        tfy_ajax_err('کلید هوش مصنوعی وارد نشده است.');
    }
    $instruction = isset($_POST['instruction']) ? sanitize_textarea_field(wp_unslash($_POST['instruction'])) : '';
    $ids = array_filter(array_map('intval', (array) (isset($_POST['ids']) ? $_POST['ids'] : array())));
    $search = !empty($_POST['search']);
    $batch = isset($_POST['batch']) ? sanitize_key($_POST['batch']) : tfy_hist_new_batch();
    $topics = array_values(array_intersect(array_map('sanitize_key', (array) (isset($_POST['topics']) ? $_POST['topics'] : array())), array_keys(tfy_cmd_topics())));
    if ($instruction === '' || !$ids) {
        tfy_ajax_err('دستور یا کارت‌ها خالی است.');
    }
    $items = array();
    foreach ($ids as $id) {
        $it = tfy_get_item($id);
        if ($it) {
            $items[$id] = $it;
        }
    }
    $fields = $topics ? array_values(array_unique(array_merge(array('name', 'category', 'city'), $topics))) : tfy_cmd_fields_for($instruction);
    $payload = array();
    foreach ($items as $it) {
        $payload[] = tfy_cmd_item_for_ai($it, $fields);
    }
    $sys = tfy_cmd_system($search) . ($topics ? ' Change ONLY these fields: ' . implode(', ', $topics) . '; ignore any other field.' : '');
    $r = tfy_ai_call($sys, "Instruction:\n" . $instruction . "\n\nItems:\n" . wp_json_encode($payload, JSON_UNESCAPED_UNICODE), array('search' => $search, 'max_tokens' => 8000));
    if (is_wp_error($r)) {
        tfy_ajax_err($r);
    }
    $data = tfy_extract_json($r['text']);
    if (!$data || !isset($data['updates']) || !is_array($data['updates'])) {
        tfy_ajax_err('پاسخ هوش مصنوعی قابل خواندن نبود.');
    }
    tfy_hist_batch($batch);
    $labels = array(
        'name' => 'نام', 'category' => 'دسته', 'city' => 'شهر', 'area' => 'محدوده', 'address' => 'آدرس', 'hours' => 'ساعت کاری', 'phone' => 'تلفن',
        'description' => 'توضیح', 'cost_note' => 'توضیح هزینه', 'notes' => 'یادداشت', 'website' => 'وب‌سایت', 'duration_h' => 'مدت',
        'times' => 'زمان', 'modes' => 'همراه', 'tags' => 'ویژگی‌ها', 'favorite' => 'علاقه‌مندی', 'done' => 'انجام شده', 'costs' => 'هزینه‌های اصلی',
        'costs_extra' => 'هزینه‌های فرعی', 'menu' => 'منو', 'related' => 'تفریحات تکمیلی', 'extra' => 'ستون‌های دلخواه',
    );
    $types = array(
        'name' => 'str', 'category' => 'str', 'city' => 'str', 'area' => 'str', 'address' => 'str', 'hours' => 'str', 'phone' => 'str',
        'description' => 'text', 'cost_note' => 'str', 'notes' => 'text', 'website' => 'url', 'duration_h' => 'dur',
        'times' => 'times', 'modes' => 'modes', 'tags' => 'tags', 'favorite' => 'bool', 'done' => 'bool', 'costs' => 'costs',
        'costs_extra' => 'costs', 'menu' => 'menu', 'related' => 'names',
    );
    $changed = array();
    $extra_by_label = array();
    foreach (tfy_extra_fields() as $k => $l) {
        $extra_by_label[tfy_key_of($l)] = $k;
    }
    foreach ($data['updates'] as $u) {
        $id = isset($u['id']) ? (int) $u['id'] : 0;
        if (!isset($items[$id]) || empty($u['fields']) || !is_array($u['fields'])) {
            continue;
        }
        $cur = $items[$id];
        $upd = array();
        $names = array();
        foreach ($u['fields'] as $f => $v) {
            if ($f === 'extra' && is_array($v) && (!$topics || in_array('extra', $topics, true))) {
                $ex = array();
                foreach ($v as $lbl => $val) {
                    $k = isset($extra_by_label[tfy_key_of($lbl)]) ? $extra_by_label[tfy_key_of($lbl)] : '';
                    $old = (array) $cur['extra'];
                    if ($k && is_scalar($val) && (!isset($old[$k]) || $old[$k] !== (string) $val)) {
                        $ex[$k] = (string) $val;
                    }
                }
                if ($ex) {
                    $upd['extra'] = $ex;
                    $names[] = $labels['extra'];
                }
                continue;
            }
            if (!isset($types[$f]) || ($topics && !in_array($f, $topics, true))) {
                continue;
            }
            if ($f === 'name' && trim((string) $v) === '') {
                continue;
            }
            $clean = $f === 'name' ? sanitize_text_field((string) $v) : tfy_clean_value($types[$f], $v);
            $old = $f === 'name' ? $cur['name'] : (array_key_exists($f, $cur) ? $cur[$f] : null);
            if ($f === 'related') {
                $old = wp_list_pluck($cur['related'], 'name');
            }
            if ($f === 'cost_level' && $clean === '') {
                $clean = null;
            }
            if (wp_json_encode($clean) === wp_json_encode($old)) {
                continue;
            }
            $upd[$f] = $clean === null ? '' : $clean;
            $names[] = $labels[$f];
            if ($f === 'modes') {
                $upd['guess_modes'] = 0;
            }
            if (in_array($f, array('costs', 'costs_extra', 'menu'), true)) {
                $upd['prices_src'] = 'user';
                $upd['prices_at'] = time();
            }
        }
        if (!$upd) {
            continue;
        }
        tfy_track($id, 'دستور هوش مصنوعی: ' . mb_substr($instruction, 0, 80), function () use ($id, $upd) {
            return tfy_save_item($id, $upd);
        });
        $changed[] = array('id' => $id, 'name' => $cur['name'], 'fields' => $names);
    }
    wp_send_json_success(array('changed' => $changed, 'notes' => isset($data['notes']) && is_string($data['notes']) ? $data['notes'] : '', 'batch' => $batch));
});

/* ----- تکمیل کلی ----- */

add_action('wp_ajax_tfy_bulk_list', function () {
    tfy_ajax_guard();
    $retry = !empty($_POST['retry']);
    $refresh = !empty($_POST['refresh']);
    $out = array();
    foreach (tfy_all_items() as $it) {
        $needs = tfy_item_needs($it);
        if ($refresh && $it['prices_src'] !== 'user' && $it['prices_at'] < time() - 14 * DAY_IN_SECONDS && ($it['costs'] || $it['costs_extra'] || $it['menu'] || !$it['prices_at'])) {
            $needs['prices'] = 'به‌روزرسانی قیمت';
        }
        if ($retry && count($it['images']) < tfy_target_images()) {
            $needs['images'] = 'عکس (کمتر از ' . tfy_target_images() . ')';
        }
        if (!$needs) {
            continue;
        }
        if (!$retry && !isset($needs['prices']) && !isset($needs['images']) && $it['enriched_at'] && $it['enriched_at'] > time() - 14 * DAY_IN_SECONDS) {
            continue;
        }
        $out[] = array('id' => $it['id'], 'name' => $it['name'], 'needs' => array_values($needs));
    }
    wp_send_json_success(array('list' => $out, 'total' => (int) wp_count_posts(TFY_CPT)->publish));
});

add_action('wp_ajax_tfy_loc_list', function () {
    tfy_ajax_guard();
    $out = array();
    foreach (tfy_all_items(array('publish', 'pending')) as $it) {
        $c = get_post_meta($it['id'], '_tfy_lat', true);
        if (!tfy_coords_ok($c, get_post_meta($it['id'], '_tfy_lng', true))) {
            $out[] = array('id' => $it['id'], 'name' => $it['name']);
        }
    }
    wp_send_json_success(array('list' => $out));
});
add_action('wp_ajax_tfy_loc_item', function () {
    tfy_ajax_guard();
    tfy_hist_batch(isset($_POST['batch']) ? sanitize_key($_POST['batch']) : tfy_hist_new_batch());
    $r = tfy_resolve_location((int) $_POST['id'], true);
    wp_send_json_success($r);
});
add_action('wp_ajax_tfy_img_list', function () {
    tfy_ajax_guard();
    $retry = !empty($_POST['retry']);
    $out = array();
    foreach (tfy_all_items(array('publish', 'pending')) as $it) {
        $n = count($it['images']);
        if ($n >= tfy_target_images()) {
            continue;
        }
        if (!$retry && (int) get_post_meta($it['id'], '_tfy_img_tried', true) > time() - 14 * DAY_IN_SECONDS) {
            continue;
        }
        $out[] = array('id' => $it['id'], 'name' => $it['name'], 'have' => $n);
    }
    wp_send_json_success(array('list' => $out, 'target' => tfy_target_images()));
});

add_action('wp_ajax_tfy_img_item', function () {
    tfy_ajax_guard();
    tfy_hist_batch(isset($_POST['batch']) ? sanitize_key($_POST['batch']) : tfy_hist_new_batch());
    $id = (int) ($_POST['id'] ?? 0);
    $use_ai = !empty($_POST['ai']) && tfy_ai_enabled();
    $r = tfy_complete_images($id, $use_ai);
    if (is_wp_error($r)) {
        tfy_ajax_err($r);
    }
    wp_send_json_success($r);
});

add_action('wp_ajax_tfy_prices_ai_list', function () {
    tfy_ajax_guard();
    $out = array();
    foreach (tfy_all_items(array('publish', 'pending')) as $it) {
        if ($it['prices_src'] === 'ai' && ($it['costs'] || $it['costs_extra'] || $it['menu'])) {
            $out[] = $it['id'];
        }
    }
    wp_send_json_success(array('ids' => $out));
});

add_action('wp_ajax_tfy_prices_ai_clear', function () {
    tfy_ajax_guard();
    tfy_hist_batch(isset($_POST['batch']) ? sanitize_key($_POST['batch']) : tfy_hist_new_batch());
    $n = 0;
    foreach (array_slice(array_filter(array_map('intval', (array) ($_POST['ids'] ?? array()))), 0, 60) as $id) {
        $it = tfy_get_item($id, true);
        if (!$it || $it['prices_src'] !== 'ai') {
            continue; // قیمت دستی/اکسل هرگز پاک نمی‌شود
        }
        tfy_track($id, 'پاک کردن قیمت‌های هوش مصنوعی (تاییدنشده)', function () use ($id) {
            return tfy_save_item($id, array('costs' => array(), 'costs_extra' => array(), 'menu' => array(), 'prices_at' => 0, 'prices_src' => ''));
        });
        $n++;
    }
    wp_send_json_success(array('cleared' => $n));
});

add_action('wp_ajax_tfy_bulk_item', function () {
    tfy_ajax_guard();
    if (!tfy_ai_enabled()) {
        tfy_ajax_err('کلید هوش مصنوعی وارد نشده است.');
    }
    tfy_hist_batch(isset($_POST['batch']) ? sanitize_key($_POST['batch']) : tfy_hist_new_batch());
    $r = tfy_enrich_item((int) $_POST['id'], array(
        'refresh' => !empty($_POST['refresh']),
        'create_related' => !empty($_POST['create_related']),
    ));
    if (is_wp_error($r)) {
        tfy_ajax_err($r);
    }
    wp_send_json_success(array('filled' => $r['filled'], 'images' => $r['images'], 'notes' => isset($r['notes']) ? $r['notes'] : array(), 'search_used' => !empty($r['suggestion']['search_used'])));
});

/* ----- اکسل ----- */

add_action('wp_ajax_tfy_import_parse', function () {
    tfy_ajax_guard();
    if (empty($_FILES['file']) || (int) $_FILES['file']['error'] !== 0) {
        tfy_ajax_err('فایلی دریافت نشد.');
    }
    $rows = tfy_read_table($_FILES['file']['tmp_name'], $_FILES['file']['name']);
    if (is_wp_error($rows)) {
        tfy_ajax_err($rows);
    }
    if (count($rows) < 2) {
        tfy_ajax_err('فایل باید حداقل یک ردیف سرستون و یک ردیف داده داشته باشد.');
    }
    $headers = array_map('strval', $rows[0]);
    $id = substr(md5(uniqid('', true)), 0, 12);
    set_transient('tfy_imp_' . $id, array('headers' => $headers, 'rows' => array_slice($rows, 1)), 2 * HOUR_IN_SECONDS);
    $targets = tfy_import_targets();
    $targets['skip'] = 'نادیده بگیر';
    wp_send_json_success(array(
        'id'      => $id,
        'headers' => $headers,
        'mapping' => tfy_guess_mapping($headers),
        'sample'  => array_slice($rows, 1, 3),
        'total'   => count($rows) - 1,
        'targets' => $targets,
        'extras'  => tfy_extra_schema(),
        'batch'   => tfy_hist_new_batch(),
    ));
});

add_action('wp_ajax_tfy_import_run', function () {
    tfy_ajax_guard();
    $id = isset($_POST['id']) ? sanitize_key($_POST['id']) : '';
    $data = get_transient('tfy_imp_' . $id);
    if (!$data) {
        tfy_ajax_err('اطلاعات فایل منقضی شده؛ دوباره آپلود کنید.');
    }
    $mapping = json_decode(wp_unslash(isset($_POST['mapping']) ? $_POST['mapping'] : '[]'), true);
    $mode = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'merge';
    if (!in_array($mode, array('merge', 'update', 'replace'), true)) {
        $mode = 'merge';
    }
    $offset = (int) ($_POST['offset'] ?? 0);
    tfy_hist_batch(sanitize_key($_POST['batch'] ?? tfy_hist_new_batch()));
    if (!is_array($mapping)) {
        tfy_ajax_err('نگاشت ستون‌ها نامعتبر است.');
    }
    // ستون دلخواه جدید → ثبت با عنوان سرستون
    foreach ($mapping as $i => $t) {
        if ($t === 'x:new') {
            $k = tfy_extra_register(isset($data['headers'][$i]) ? $data['headers'][$i] : '');
            $mapping[$i] = $k ? 'x:' . $k : 'skip';
        }
    }
    if ($offset === 0 && $mode === 'replace') {
        foreach (tfy_all_items() as $it) {
            tfy_trash_tracked($it['id'], 'جایگزینی لیست با اکسل');
        }
    }
    $idx = tfy_import_index();
    $counts = array('created' => 0, 'merged' => 0, 'same' => 0, 'error' => 0, 'skipped' => 0);
    $slice = array_slice($data['rows'], $offset, 25);
    foreach ($slice as $cells) {
        $d = tfy_row_to_data($cells, $mapping);
        if (!$d) {
            $counts['skipped']++;
            continue;
        }
        $r = tfy_import_apply($d, $idx, $mode === 'replace' ? 'merge' : $mode, 'ایمپورت اکسل', $mode === 'replace');
        $counts[$r]++;
    }
    $next = $offset + count($slice);
    wp_send_json_success(array('counts' => $counts, 'next' => $next, 'total' => count($data['rows']), 'done' => $next >= count($data['rows'])));
});

/* ----- تاریخچه ----- */

add_action('wp_ajax_tfy_hist_revert', function () {
    tfy_ajax_guard();
    if (!empty($_POST['batch'])) {
        $r = tfy_hist_revert_batch(sanitize_key($_POST['batch']));
        wp_send_json_success($r);
    }
    tfy_hist_batch(tfy_hist_new_batch());
    $r = tfy_hist_revert((int) $_POST['hid']);
    if (is_wp_error($r)) {
        tfy_ajax_err($r);
    }
    wp_send_json_success(array('ok' => 1));
});

/* ---------------------------- صفحه ---------------------------- */

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_tafrihyab') {
        return;
    }
    $ver = TFY_VER . '.' . filemtime(TFY_DIR . 'assets/app.js');
    wp_enqueue_style('tafrihyab', TFY_URL . 'assets/app.css', array(), $ver);
    if (!in_array(isset($_GET['tab']) ? $_GET['tab'] : '', array('add', 'cards'), true)) {
        return;
    }
    wp_enqueue_script('tafrihyab', TFY_URL . 'assets/app.js', array(), $ver, true);
    wp_localize_script('tafrihyab', 'TFY_CFG', array(
        'rest'     => esc_url_raw(rest_url('tafrihyab/v1/')),
        'adminForm' => (isset($_GET['tab']) ? $_GET['tab'] : '') === 'add',
        'manage'   => (isset($_GET['tab']) ? $_GET['tab'] : '') === 'cards',
        'canEdit'  => true,
        'nonce'    => wp_create_nonce('wp_rest'),
    ));
});

function tfy_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $tabs = tfy_admin_tabs();
    $tab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'settings';
    ?>
    <div class="wrap tfy-admin" dir="rtl">
        <h1>تفریح‌یاب</h1>
        <h2 class="nav-tab-wrapper">
            <?php $pend = (int) wp_count_posts(TFY_CPT)->pending; foreach ($tabs as $k => $l) : if ($k === 'cards' && $pend) { $l .= ' (' . $pend . ' در انتظار)'; } ?>
                <a class="nav-tab <?php echo $k === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(tfy_admin_url($k)); ?>"><?php echo esc_html($l); ?></a>
            <?php endforeach; ?>
        </h2>
        <style>
            .tfy-admin .box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:16px 0;max-width:980px}
            .tfy-admin .log{max-height:260px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:8px 10px;font-size:12.5px;white-space:pre-wrap;line-height:1.8}
            .tfy-admin .bar{height:10px;background:#e5e5e5;border-radius:99px;overflow:hidden;margin:10px 0}.tfy-admin .bar i{display:block;height:100%;width:0;background:#2271b1;transition:width .2s}
            .tfy-admin table.map td,.tfy-admin table.map th{padding:6px 8px;text-align:right;vertical-align:top}
            .tfy-admin .pick{max-height:220px;overflow:auto;border:1px solid #dcdcde;border-radius:6px;padding:6px 10px;background:#fff}
            .tfy-admin .pick label{display:block;padding:2px 0}
            .tfy-admin .muted{color:#646970}
        </style>
        <?php
        $fn = 'tfy_tab_' . $tab;
        $fn();
        tfy_admin_js();
        ?>
    </div>
    <?php
}

function tfy_tab_settings()
{
    $msgs = isset($_GET['tfy_msg']) ? explode(',', sanitize_text_field(wp_unslash($_GET['tfy_msg']))) : array();
    $labels = array('keyok' => 'کلید ذخیره شد.', 'keydel' => 'کلید حذف شد.', 'saved' => 'تنظیمات ذخیره شد.');
    foreach ($msgs as $m) {
        if (isset($labels[$m])) {
            echo '<div class="notice notice-success"><p>' . esc_html($labels[$m]) . '</p></div>';
        }
    }
    $seed = tfy_seed_status();
    $pending = count(tfy_pending_images_query(500));
    $count = (int) wp_count_posts(TFY_CPT)->publish;
    ?>
    <div class="box">
        <h2>نمایش در سایت</h2>
        <p>شورت‌کد <code>[tafrihyab]</code> را در یک برگه (ویجت «شورت‌کد» المنتور) بگذارید. صفحه برای هر کس که آدرسش را داشته باشد باز است و PIN ندارد؛ برگه را noindex کنید و در منو نیاورید.</p>
        <p>تعداد تفریحات فعلی: <strong><?php echo esc_html($count); ?></strong></p>
        <?php if ($seed['done'] < $seed['total']) : ?>
            <p id="tfy-seed-line">ایمپورت اولیه: <?php echo esc_html($seed['done'] . ' از ' . $seed['total']); ?> <button class="button button-primary" id="tfy-seed-btn" type="button">ادامه ایمپورت</button></p>
        <?php endif; ?>
        <?php if ($pending > 0) : ?>
            <p id="tfy-img-line">تصاویر در انتظار دانلود: <strong><?php echo esc_html($pending); ?></strong> <button class="button" id="tfy-img-btn" type="button">دانلود تصاویر روی سرور</button></p>
        <?php endif; ?>
    </div>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="box">
        <input type="hidden" name="action" value="tfy_save_settings">
        <?php wp_nonce_field('tfy_settings'); ?>
        <h2>اتصال به هوش مصنوعی</h2>
        <p>وضعیت کلید: <strong><?php echo tfy_ai_enabled() ? 'وارد شده (' . esc_html(tfy_ai_label()) . ')' : 'وارد نشده'; ?></strong>
            &nbsp;|&nbsp; مصرف امروز (به وقت UTC): <strong><?php $u = tfy_ai_usage(); echo esc_html(number_format_i18n($u['req'])); ?></strong> درخواست، <strong><?php echo esc_html(number_format_i18n($u['tok'])); ?></strong> توکن</p>
        <table class="form-table" role="presentation">
            <tr><th>سرویس</th><td><select name="tfy_provider" id="tfy-provider">
                <?php foreach (tfy_ai_providers() as $k => $p) : ?>
                    <option value="<?php echo esc_attr($k); ?>" data-model="<?php echo esc_attr($p['model']); ?>" data-base="<?php echo esc_attr($p['base']); ?>" data-search="<?php echo $p['search'] ? '1' : '0'; ?>" <?php selected(tfy_ai_provider(), $k); ?>><?php echo esc_html($p['label']); ?></option>
                <?php endforeach; ?>
                </select>
                <p class="description">هر سرویسی که API سازگار با OpenAI دارد (مثل مدل‌های لوکال، Together، Perplexity و ...) با گزینه‌ی آخر کار می‌کند.</p></td></tr>
            <tr><th>API Key</th><td><input class="regular-text" type="password" name="tfy_key" dir="ltr" autocomplete="new-password" placeholder="خالی = بدون تغییر">
                <p class="description">کلید فقط روی سرور سایت می‌ماند. با عوض کردن سرویس، کلید همان سرویس را وارد کنید.</p>
                <label><input type="checkbox" name="tfy_key_delete" value="1"> حذف کلید ذخیره‌شده</label></td></tr>
            <tr><th>مدل</th><td><input class="regular-text" type="text" name="tfy_model" id="tfy-model" dir="ltr" value="<?php echo esc_attr(tfy_ai_model()); ?>">
                <p class="description">نام دقیق مدل همان سرویس. اگر خطای «مدل پیدا نشد» دیدید، نام را از پنل خود سرویس بردارید.</p></td></tr>
            <tr id="tfy-base-row"><th>Base URL</th><td><input class="regular-text" type="url" name="tfy_base" id="tfy-base" dir="ltr" value="<?php echo esc_attr(get_option('tfy_ai_base', '')); ?>" placeholder="https://example.com/v1">
                <p class="description">فقط برای «سرویس سازگار با OpenAI»؛ آدرسی که بعد از آن /chat/completions می‌آید.</p></td></tr>
            <tr><th>جستجوی وب</th><td><label><input type="checkbox" name="tfy_search" id="tfy-search" value="1" <?php checked(get_option('tfy_gemini_search', '1'), '1'); ?>> برای اطلاعات واقعی (قیمت، منو، آدرس) جستجوی وب انجام شود</label>
                <p class="description" id="tfy-search-note">فقط با Gemini و Claude در دسترس است. در سرویس‌های دیگر قیمت و منو پر نمی‌شود (حدس ذخیره نمی‌کنیم) و بقیه‌ی فیلدها از دانش خود مدل می‌آید که ممکن است قدیمی باشد. نکته: در OpenRouter می‌توانید به انتهای نام مدل «:online» اضافه کنید تا جستجو انجام شود.</p></td></tr>
            <tr><th>قالب لینک نشان</th><td><input type="text" dir="ltr" name="tfy_neshan_tpl" value="<?php echo esc_attr(get_option('tfy_neshan_tpl', '')); ?>" class="large-text" placeholder="https://neshan.org/maps/@{lat},{lng},17.00z">
                <p class="description">لوکیشن هر کارت به‌صورت مختصات دقیق (lat و lng) ذخیره می‌شود و لینکش از این قالب ساخته می‌شود. فقط آدرس‌های neshan.org یا nshn.ir پذیرفته می‌شود. قبل از اجرای گروهی، یک لینک را در موبایل باز کنید و مطمئن شوید نشان روی همان نقطه باز می‌شود؛ اگر نشد قالب درست را اینجا بگذارید و همه‌ی لینک‌ها یک‌جا اصلاح می‌شوند. خالی = پیش‌فرض.</p></td></tr>
            <tr><th>تعداد عکس هر کارت</th><td><input type="number" min="1" max="8" name="tfy_img_target" value="<?php echo esc_attr(tfy_max_images()); ?>" class="small-text">
                <p class="description">پیش‌فرض ۲. کارتی که کمتر از این تعداد عکس دارد در «تکمیل کلی» تکمیل می‌شود؛ کارتی که به این تعداد برسد دیگر جستجو نمی‌شود. عکس‌ها فقط به‌صورت لینک ذخیره می‌شوند و هر عکس قبل از ثبت دانلود و بررسی می‌شود.</p></td></tr>
            <tr><th>حداقل منبع برای قیمت</th><td><input type="number" min="1" max="3" name="tfy_price_min_src" value="<?php echo esc_attr(tfy_price_min_src()); ?>" class="small-text">
                <p class="description">پیش‌فرض ۲: هر قیمت (هزینه یا منو) فقط وقتی ثبت می‌شود که افزونه صفحه‌ها را واقعا باز کند و همان عدد در متن حداقل این تعداد صفحه‌ی مستقل که درباره‌ی همین مکان‌اند دیده شود (سایت رسمی ۲ حساب می‌شود، پس یک قیمت روی سایت رسمی کافی است). قیمت تاییدنشده ثبت نمی‌شود و خالی می‌ماند (بهتر از قیمت غلط). این کار دقت را بالا می‌برد ولی تضمین ۱۰۰٪ نیست؛ هوش مصنوعی همچنان ممکن است صفحه‌ی قدیمی را بخواند.</p></td></tr>
            <tr><th>پرسش و پاسخ روی کارت‌ها</th><td><label><input type="checkbox" name="tfy_qa_on" value="1" <?php checked(get_option('tfy_qa_on', '1'), '1'); ?>> بازدیدکننده‌ها از هوش مصنوعی درباره‌ی هر کارت بپرسند</label><br>
                سقف کل پرسش در روز: <input type="number" min="0" max="5000" name="tfy_qa_daily" value="<?php echo esc_attr(tfy_qa_daily_cap()); ?>" class="small-text">
                <p class="description">پاسخ فقط از اطلاعات ثبت‌شده‌ی همان کارت ساخته می‌شود (بدون جستجوی وب) و اگر پاسخ در کارت نباشد همین را می‌گوید. هر بازدیدکننده در ساعت حداکثر ۸ پرسش دارد و پاسخ‌های تکراری کش می‌شوند. این پرسش‌ها از سهمیه‌ی کلید شما مصرف می‌کنند؛ روی پلن رایگان سقف را پایین نگه دارید. ۰ = غیرفعال.</p></td></tr>
            <tr><th>هوا</th><td><label><input type="checkbox" name="tfy_weather_on" value="1" <?php checked(get_option('tfy_weather_on', '1'), '1'); ?>> وضعیت هوای تهران بالای صفحه نمایش داده شود و سرپوشیده‌ها پیشنهاد شوند</label>
                <p class="description">از سرویس رایگان Open-Meteo از طرف سرور شما خوانده می‌شود و ۳۰ دقیقه کش می‌شود.</p></td></tr>
            <tr><th>بازبینی دوره‌ای قیمت‌ها</th><td><label><input type="checkbox" name="tfy_auto_on" value="1" <?php checked(get_option('tfy_auto_on', '0'), '1'); ?>> قیمت‌های قدیمی (ثبت‌شده با هوش مصنوعی) خودکار دوباره بررسی شوند</label><br>
                روزانه حداکثر <input type="number" min="1" max="100" name="tfy_auto_per_day" value="<?php echo esc_attr((int) get_option('tfy_auto_per_day', 10)); ?>" class="small-text"> کارت، با قدمت بیشتر از <input type="number" min="7" max="365" name="tfy_auto_age" value="<?php echo esc_attr((int) get_option('tfy_auto_age', 30)); ?>" class="small-text"> روز
                <p class="description">با وردپرس‌کرون و هر بار فقط ۲ کارت اجرا می‌شود. قیمتی که با صفحه واقعی تایید نشود جایگزین نمی‌شود. قیمت‌هایی که خودتان وارد کرده‌اید (اکسل یا دستی) هرگز بازبینی نمی‌شوند. با رسیدن به سقف رایگان ۳ ساعت مکث می‌کند. نتیجه در تب «آمار» ثبت می‌شود.</p></td></tr>
            <tr><th>تایل نقشه</th><td><input type="text" dir="ltr" class="large-text" name="tfy_map_tiles" value="<?php echo esc_attr(get_option('tfy_map_tiles', '')); ?>" placeholder="https://tile.openstreetmap.org/{z}/{x}/{y}.png">
                <p class="description">آدرس تایل برای تب «نقشه» (با {z} و {x} و {y} و فقط https). خالی = OpenStreetMap. اگر در ایران برای بازدیدکنندگان باز نشد، سرویس تایل دیگری بگذارید.</p></td></tr>
        </table>
        <?php submit_button('ذخیره تنظیمات', 'primary', 'submit', false); ?>
        <button type="button" class="button" id="tfy-test-btn">تست اتصال</button> <span id="tfy-test-out"></span>
        <p class="description">با «تکمیل خودکار» نام، دسته، توضیح و لینک هر تفریح به سرویس انتخاب‌شده ارسال می‌شود. در رده‌های رایگان، برخی سرویس‌ها (مثل گوگل) ممکن است از محتوا برای بهبود مدل‌هایشان استفاده کنند.</p>
    </form>
    <?php
}

function tfy_tab_command()
{
    $items = array();
    foreach (tfy_all_items() as $it) {
        $items[] = array('id' => $it['id'], 'name' => $it['name'], 'cat' => $it['category']);
    }
    ?>
    <div class="box">
        <h2>دستور هوش مصنوعی روی اطلاعات</h2>
        <p>یک دستور آزاد بنویسید؛ هوش مصنوعی آن را روی کارت‌های انتخاب‌شده اعمال می‌کند. نمونه‌ها: «وب‌سایت کارت‌هایی که سایتشان خالی است را پیدا کن»، «برای کارت دربند ساعت کاری و توضیح را کامل کن»، «دسته‌ی همه‌ی کافه‌ها را یکسان کن». حذف یا ساخت کارت ممکن نیست و هر تغییر در «تاریخچه» قابل برگشت است.</p>
        <fieldset style="border:1px solid #dcdcde;border-radius:6px;padding:8px 12px;margin:10px 0">
            <legend><strong>موضوع تغییر (اختیاری)</strong></legend>
            <p class="muted" style="margin-top:0">اگر یک یا چند موضوع انتخاب کنید، فقط همان فیلدها (روی همه‌ی کارت‌های انتخابی) تغییر می‌کنند و مصرف توکن هم کمتر می‌شود. اگر هیچ‌کدام انتخاب نشود، هوش مصنوعی هر چیزی را که دستور لازم دارد تغییر می‌دهد. عکس‌ها و لوکیشن از این مسیر تغییر نمی‌کنند (لوکیشن با «تکمیل کلی» و عکس‌ها با دکمه‌های خود کارت، چون هوش مصنوعی نمی‌تواند لینک عکس را راستی‌آزمایی کند).</p>
            <?php foreach (tfy_cmd_topics() as $k => $l) : ?>
                <label style="display:inline-block;margin-left:14px"><input type="checkbox" class="cmd-topic" value="<?php echo esc_attr($k); ?>"> <?php echo esc_html($l); ?></label>
            <?php endforeach; ?>
        </fieldset>
        <p><textarea id="cmd-text" rows="5" class="large-text" placeholder="دستور شما…"></textarea></p>
        <p><label><input type="radio" name="cmd-scope" value="all" checked> همه‌ی کارت‌ها (<?php echo esc_html(count($items)); ?>)</label>
            &nbsp; <label><input type="radio" name="cmd-scope" value="sel"> فقط کارت‌های انتخاب‌شده</label></p>
        <div id="cmd-pick" style="display:none">
            <p><input type="search" id="cmd-filter" class="regular-text" placeholder="جستجو در نام…"></p>
            <div class="pick" id="cmd-list"></div>
        </div>
        <p><label><input type="checkbox" id="cmd-search"> از جستجوی گوگل برای راستی‌آزمایی استفاده شود (کندتر و مصرف سهمیه‌ی بیشتر)</label></p>
        <p><button class="button button-primary" id="cmd-run" type="button">اجرا</button> <button class="button" id="cmd-stop" type="button">توقف</button> <span id="cmd-status" class="muted"></span></p>
        <div class="bar"><i id="cmd-bar"></i></div>
        <div class="log" id="cmd-log" hidden></div>
    </div>
    <script>window.TFY_CMD_ITEMS = <?php echo wp_json_encode($items, JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php
}

function tfy_tab_excel()
{
    ?>
    <div class="box">
        <h2>خروجی اکسل</h2>
        <p>همه‌ی اطلاعات کامل فعلی (شامل هزینه‌ها، منو، تفریحات تکمیلی، ستون‌های دلخواه و لینک عکس‌ها) در یک فایل xlsx.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="tfy_export">
            <?php wp_nonce_field('tfy_export'); ?>
            <?php submit_button('دانلود اکسل', 'primary', 'submit', false); ?>
        </form>
    </div>
    <div class="box" id="imp-box">
        <h2>افزودن فایل اکسل / csv</h2>
        <p>فایل را انتخاب کنید؛ ستون‌ها خودکار با فیلدها تطبیق داده می‌شوند و قبل از ورود می‌توانید اصلاح کنید. ستونی که نگاشت نشود به‌صورت «ستون دلخواه» به همه‌ی کارت‌ها، فرم ویرایش و فیلترها اضافه می‌شود. فرمت ستون هزینه و منو: هر خط «عنوان | قیمت | توضیح» و برای منو «دسته | نام | قیمت».</p>
        <p><input type="file" id="imp-file" accept=".xlsx,.csv"> <button class="button" id="imp-parse" type="button">بارگذاری و تطبیق ستون‌ها</button> <span id="imp-status" class="muted"></span></p>
        <div id="imp-step2" hidden>
            <table class="widefat map" id="imp-map"></table>
            <h3>روش ورود اطلاعات</h3>
            <p><label><input type="radio" name="imp-mode" value="merge" checked> <strong>افزودن و ادغام:</strong> ردیف‌های جدید اضافه می‌شوند؛ ردیف تکراری (هم‌نام یا هم‌لینک‌لوکیشن) با کارت موجود ادغام شده و فقط فیلدهای خالیِ آن پر می‌شود.</label></p>
            <p><label><input type="radio" name="imp-mode" value="update"> <strong>افزودن و به‌روزرسانی:</strong> مثل بالا، ولی مقدارهای فایل جدید روی مقدارهای کارت‌های تکراری می‌نویسد.</label></p>
            <p><label><input type="radio" name="imp-mode" value="replace"> <strong>جایگزینی کامل لیست:</strong> همه‌ی کارت‌های فعلی به زباله‌دان می‌روند و لیست دقیقا از روی این فایل ساخته می‌شود.</label></p>
            <p class="muted">هر سه حالت در «تاریخچه تغییرات» با یک دکمه قابل برگشت‌اند.</p>
            <p><button class="button button-primary" id="imp-run" type="button">شروع ورود اطلاعات</button></p>
            <div class="bar"><i id="imp-bar"></i></div>
        </div>
        <div class="log" id="imp-log" hidden></div>
    </div>
    <?php
}

function tfy_tab_bulk()
{
    ?>
    <div class="box">
        <h2>تکمیل کلی همه‌ی کارت‌ها</h2>
        <p><strong>روش ساده:</strong> فقط دو دکمه: «محاسبه‌ی کارت‌های ناقص» و بعد «شروع تکمیل». برای هر کارتِ ناقص، اطلاعات خالی (آدرس، ساعت، تلفن، سایت، هزینه، منو)، لوکیشن و عکس‌ها (تا ۲ عکس) خودکار پر می‌شود. فقط خالی‌ها پر می‌شوند و داده‌ی خودتان بازنویسی نمی‌شود. اگر چیزی مطمئن پیدا نشد، خالی می‌ماند؛ نداشتن اطلاعات یا عکس بهتر از اطلاعات یا عکس غلط است و همین در گزارش با دلیل نوشته می‌شود.</p>
        <p><label><input type="checkbox" id="bulk-retry"> کارت‌هایی که در ۱۴ روز اخیر تکمیل شده‌اند هم دوباره بررسی شوند</label><br>
            <label><input type="checkbox" id="bulk-refresh" checked> قیمت‌ها و منوها دوباره از وب خوانده شوند و با تاریخ امروز ثبت شوند (قیمت‌هایی که خودتان دستی وارد کرده‌اید محفوظ می‌ماند). فقط کارت‌هایی که قیمتشان بیش از ۱۴ روز قدیمی است</label><br>
            <label><input type="checkbox" id="bulk-related"> اگر تفریح تکمیلیِ نزدیک پیدا شد که در لیست شما نیست، به‌صورت کارت «در انتظار تایید» اضافه شود (نیاز به جستجو؛ مصرف توکن بیشتر)</label></p>
        <details style="margin:0 0 14px"><summary style="cursor:pointer;font-weight:600">ابزارهای جداگانه (فقط لوکیشن، فقط عکس، پاک کردن قیمت‌های غلط)</summary>
        <div style="margin:0 0 14px;padding:12px;border:1px solid #dcdcde;border-radius:6px">
            <strong>لوکیشن دقیق با نشان (بدون مصرف هوش مصنوعی)</strong>
            <p class="description">لینک‌های گوگل‌مپ فعلی (کوتاه یا بلند) اگر مختصات دقیق داشته باشند به مختصات تبدیل می‌شوند؛ برای بقیه، از OpenStreetMap فقط وقتی نتیجه‌ای با نام همان مکان پیدا شود مختصات ثبت می‌شود. چیزی که پیدا نشود خالی می‌ماند و در گزارش می‌آید تا لینک نشان را دستی بگذارید؛ هیچ لوکیشنی حدسی ثبت نمی‌شود.</p>
            <button class="button" id="loc-run" type="button">شروع تعیین لوکیشن</button> <button class="button" id="loc-stop" type="button">توقف</button> <span id="loc-status" class="muted"></span>
            <div class="log" id="loc-log" hidden></div>
        </div>
        <div style="margin:0 0 14px;padding:12px;border:1px solid #dcdcde;border-radius:6px">
            <strong>تکمیل عکس (تا ۲ عکس برای هر کارت)</strong>
            <p class="description">کارتی که ۲ عکس (یا بیشتر) دارد بررسی نمی‌شود؛ کارتی که ۱ عکس دارد فقط یک عکس دیگر می‌گیرد. عکس‌ها از سایت رسمی، صفحه‌های منبع و ویکی‌مدیا پیدا و فقط به‌صورت لینک ثبت می‌شوند. هر عکس قبل از ثبت دانلود و بررسی می‌شود (بازشدن، اندازه‌ی کافی، غیرتکراری، غیراینستاگرامی). کارتی که چیزی برایش پیدا نشد ۱۴ روز دوباره بررسی نمی‌شود، مگر گزینه‌ی تکرار را بزنید.</p>
            <label><input type="checkbox" id="img-ai" checked> اگر کارت سایت رسمی ندارد، با جستجوی هوش مصنوعی صفحه‌ی منبع پیدا شود (مصرف سهمیه‌ی کم)</label><br>
            <label><input type="checkbox" id="img-retry"> کارت‌هایی که اخیرا بدون نتیجه بررسی شده‌اند هم دوباره بررسی شوند</label><br>
            <button class="button" id="img-run" type="button">شروع تکمیل عکس</button> <button class="button" id="img-stop" type="button">توقف</button> <span id="img-status" class="muted"></span>
            <div class="log" id="img-log" hidden></div>
        </div>
        <div style="margin:0 0 14px;padding:12px;border:1px solid #dcdcde;border-radius:6px">
            <strong>پاک کردن قیمت‌های تاییدنشده‌ی هوش مصنوعی</strong>
            <p class="description">اگر قیمت‌های قبلی غلط بوده‌اند: همه‌ی هزینه‌ها و منوهایی که هوش مصنوعی پر کرده است پاک می‌شود تا با قاعده‌ی جدید (حداقل چند منبع مستقل) دوباره خوانده شود. قیمت‌هایی که خودتان یا اکسل وارد کرده‌اید دست نمی‌خورد. با «تاریخچه تغییرات» برگشت‌پذیر است.</p>
            <button class="button" id="pc-run" type="button">پاک کردن قیمت‌های هوش مصنوعی</button> <span id="pc-status" class="muted"></span>
        </div>
        </details>
        <p><button class="button" id="bulk-load" type="button">محاسبه‌ی کارت‌های ناقص</button> <span id="bulk-count" class="muted"></span></p>
        <p><button class="button button-primary" id="bulk-run" type="button" disabled>شروع تکمیل</button> <button class="button" id="bulk-stop" type="button">توقف</button> <span id="bulk-status" class="muted"></span></p>
        <div class="bar"><i id="bulk-bar"></i></div>
        <div class="log" id="bulk-log" hidden></div>
    </div>
    <?php
}

function tfy_tab_cards()
{
    ?>
    <div class="box" style="max-width:1100px">
        <p>ویرایش و حذف کارت‌ها فقط از همین‌جا ممکن است. پیشنهادهای ثبت‌شده از صفحه‌ی عمومی تا وقتی تایید نکنید در صفحه دیده نمی‌شوند.</p>
        <div class="tfy" id="tfy-admin-manage" dir="rtl"></div>
    </div>
    <?php
}

function tfy_tab_add()
{
    ?>
    <div class="box" style="max-width:820px">
        <p>این فرم همان فرم ویرایش کارت در صفحه‌ی نمایش است و همه‌ی فیلدها را دارد.</p>
        <div class="tfy" id="tfy-admin-form" dir="rtl"></div>
    </div>
    <?php
}

function tfy_tab_history()
{
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $h = tfy_hist_list($page);
    $actions = array('create' => 'ساخت', 'update' => 'ویرایش', 'trash' => 'حذف', 'revert' => 'برگردانده شد');
    ?>
    <div class="box" style="max-width:1100px">
        <h2>تاریخچه تغییرات</h2>
        <p>هر ردیف یعنی یک تغییر. «برگردان» کارت را به حالت <strong>قبل از همان تغییر</strong> برمی‌گرداند. برای عملیات گروهی (ایمپورت، دستور هوش مصنوعی، تکمیل کلی) دکمه‌ی «برگرداندن کل عملیات» همه‌ی ردیف‌های آن را یک‌جا برمی‌گرداند. کارت‌های حذف‌شده ۳۰ روز در زباله‌دان می‌مانند و بعد از آن غیرقابل برگشت‌اند.</p>
        <table class="widefat striped">
            <thead><tr><th>زمان</th><th>عملیات</th><th>کارت</th><th>توضیح</th><th></th></tr></thead>
            <tbody>
            <?php if (!$h['rows']) : ?><tr><td colspan="5">هنوز تغییری ثبت نشده است.</td></tr><?php endif; ?>
            <?php foreach ($h['rows'] as $r) : ?>
                <tr>
                    <td><?php echo esc_html(get_date_from_gmt($r['created'], 'Y/m/d H:i')); ?></td>
                    <td><?php echo esc_html(isset($actions[$r['action']]) ? $actions[$r['action']] : $r['action']); ?></td>
                    <td><?php echo esc_html($r['item_name']); ?></td>
                    <td><?php echo esc_html($r['label']); ?></td>
                    <td>
                        <?php if ($r['reverted']) : ?>
                            <span class="muted">برگردانده شد</span>
                        <?php else : ?>
                            <button class="button button-small tfy-rev" data-hid="<?php echo (int) $r['id']; ?>">برگردان</button>
                            <?php if ((int) $r['batch_size'] > 1) : ?>
                                <button class="button button-small tfy-rev-batch" data-batch="<?php echo esc_attr($r['batch']); ?>">برگرداندن کل عملیات (<?php echo (int) $r['batch_size']; ?> مورد)</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $pages = (int) ceil($h['total'] / 40);
        if ($pages > 1) {
            echo '<p>';
            for ($i = 1; $i <= $pages; $i++) {
                echo $i === $page ? '<strong>' . $i . '</strong> ' : '<a href="' . esc_url(tfy_admin_url('history', array('paged' => $i))) . '">' . $i . '</a> ';
            }
            echo '</p>';
        }
        ?>
    </div>
    <?php
}

function tfy_admin_js()
{
    ?>
    <script>
    (function () {
        var nonce = <?php echo wp_json_encode(wp_create_nonce('tfy_admin')); ?>;
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var histUrl = <?php echo wp_json_encode(tfy_admin_url('history')); ?>;
        function $(id) { return document.getElementById(id); }
        function post(action, extra, isForm) {
            var fd = isForm ? extra : new FormData();
            fd.append('action', action); fd.append('nonce', nonce);
            if (!isForm) for (var k in extra) {
                if (Array.isArray(extra[k])) extra[k].forEach(function (v) { fd.append(k + '[]', v); }); else fd.append(k, extra[k]);
            }
            return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
                if (!r.success) throw new Error((r.data && r.data.message) || 'خطا');
                return r.data;
            });
        }
        function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
        function logTo(el, t) { el.hidden = false; el.textContent += t + '\n'; el.scrollTop = el.scrollHeight; }
        function bid() { return Math.random().toString(16).slice(2, 10) + Date.now().toString(16).slice(-8); }

        /* انتخاب سرویس */
        if ($('tfy-provider')) {
            var pv = $('tfy-provider'), lastP = pv.value;
            function syncProv() {
                var o = pv.options[pv.selectedIndex];
                $('tfy-base-row').style.display = pv.value === 'custom' ? '' : 'none';
                $('tfy-search').disabled = o.dataset.search !== '1';
                if (pv.value !== lastP) { $('tfy-model').value = o.dataset.model; lastP = pv.value; }
            }
            pv.onchange = syncProv; syncProv();
        }

        /* تست اتصال */
        if ($('tfy-test-btn')) $('tfy-test-btn').onclick = function () {
            var o = $('tfy-test-out'); o.textContent = 'در حال تست (ابتدا تنظیمات را ذخیره کنید)…';
            post('tfy_test_ai', {}).then(function (d) { o.textContent = 'اتصال برقرار است (' + d.model + '): ' + d.reply; o.style.color = '#007017'; })
                .catch(function (e) { o.textContent = e.message; o.style.color = '#b32d2e'; });
        };

        /* ایمپورت اولیه و تصاویر (قدیمی) */
        if ($('tfy-seed-btn')) $('tfy-seed-btn').onclick = function () {
            var b = this; b.disabled = true;
            (function step(o) { post('tfy_seed', { offset: o }).then(function (r) {
                $('tfy-seed-line').textContent = 'ایمپورت اولیه: ' + r.status.done + ' از ' + r.status.total;
                if (r.next < r.status.total) step(r.next); else location.reload();
            }).catch(function (e) { $('tfy-seed-line').textContent = e.message; b.disabled = false; }); })(0);
        };
        if ($('tfy-img-btn')) $('tfy-img-btn').onclick = function () {
            var b = this; b.disabled = true; var ok = 0, fail = 0;
            (function step() { post('tfy_images', {}).then(function (r) {
                ok += r.ok; fail += r.fail; $('tfy-img-line').textContent = 'دانلود شد: ' + ok + ' | ناموفق: ' + fail + ' | باقی‌مانده: ' + r.left;
                if (r.left > 0) step(); else b.disabled = false;
            }).catch(function (e) { $('tfy-img-line').textContent = e.message; b.disabled = false; }); })();
        };

        /* دستور هوش مصنوعی */
        if ($('cmd-run')) {
            var stopC = false, items = window.TFY_CMD_ITEMS || [];
            var radios = document.getElementsByName('cmd-scope');
            function drawList() {
                var f = ($('cmd-filter').value || '').trim(), box = $('cmd-list'); box.textContent = '';
                items.forEach(function (it) {
                    if (f && (it.name + ' ' + it.cat).indexOf(f) === -1) return;
                    var l = document.createElement('label'), c = document.createElement('input');
                    c.type = 'checkbox'; c.value = it.id; c.className = 'cmd-sel';
                    l.appendChild(c); l.appendChild(document.createTextNode(' ' + it.name + (it.cat ? ' — ' + it.cat : ''))); box.appendChild(l);
                });
            }
            Array.prototype.forEach.call(radios, function (r) { r.onchange = function () { $('cmd-pick').style.display = r.value === 'sel' && r.checked ? 'block' : 'none'; if (r.value === 'sel') drawList(); }; });
            $('cmd-filter').oninput = drawList;
            $('cmd-stop').onclick = function () { stopC = true; };
            $('cmd-run').onclick = function () {
                var text = $('cmd-text').value.trim(); if (!text) { alert('دستور را بنویسید.'); return; }
                var scope = Array.prototype.filter.call(radios, function (r) { return r.checked; })[0].value, ids;
                if (scope === 'all') ids = items.map(function (i) { return i.id; });
                else { ids = Array.prototype.map.call(document.querySelectorAll('.cmd-sel:checked'), function (c) { return +c.value; }); }
                if (!ids.length) { alert('کارتی انتخاب نشده.'); return; }
                if (!confirm('دستور روی ' + ids.length + ' کارت اجرا می‌شود. ادامه می‌دهید؟ (همه‌ی تغییرات در تاریخچه قابل برگشت‌اند)')) return;
                var topics = Array.prototype.map.call(document.querySelectorAll('.cmd-topic:checked'), function (c) { return c.value; });
                var search = $('cmd-search').checked, size = search ? 4 : 8, batch = bid(), done = 0, total = 0, fails = 0;
                var chunks = []; for (var i = 0; i < ids.length; i += size) chunks.push(ids.slice(i, i + size));
                stopC = false; $('cmd-run').disabled = true; $('cmd-log').textContent = '';
                (function next(ci) {
                    if (stopC || ci >= chunks.length) {
                        $('cmd-run').disabled = false; $('cmd-status').textContent = 'پایان: ' + total + ' کارت تغییر کرد.';
                        logTo($('cmd-log'), '— پایان. برای برگرداندن به «تاریخچه تغییرات» بروید.'); return;
                    }
                    $('cmd-status').textContent = 'بخش ' + (ci + 1) + ' از ' + chunks.length + '…';
                    post('tfy_cmd_run', { instruction: text, ids: chunks[ci], search: search ? 1 : '', batch: batch, topics: topics }).then(function (r) {
                        r.changed.forEach(function (c) { total++; logTo($('cmd-log'), '✓ ' + c.name + ': ' + c.fields.join('، ')); });
                        if (r.notes) logTo($('cmd-log'), '  ' + r.notes);
                        fails = 0;
                    }).catch(function (e) { fails++; logTo($('cmd-log'), '✗ بخش ' + (ci + 1) + ': ' + e.message); if (fails >= 3) stopC = true; })
                      .then(function () { done++; $('cmd-bar').style.width = Math.round(done / chunks.length * 100) + '%'; return sleep(4000); })
                      .then(function () { next(ci + 1); });
                })(0);
            };
        }

        /* اکسل */
        if ($('imp-parse')) {
            var imp = null;
            $('imp-parse').onclick = function () {
                var f = $('imp-file').files[0]; if (!f) { alert('فایل را انتخاب کنید.'); return; }
                var fd = new FormData(); fd.append('file', f); $('imp-status').textContent = 'در حال خواندن…';
                post('tfy_import_parse', fd, true).then(function (d) {
                    imp = d; $('imp-status').textContent = d.total + ' ردیف داده پیدا شد.';
                    var t = $('imp-map'); t.textContent = '';
                    var hr = t.insertRow(); ['ستون فایل', 'نمونه', 'ذخیره در'].forEach(function (x) { var th = document.createElement('th'); th.textContent = x; hr.appendChild(th); });
                    d.headers.forEach(function (h, i) {
                        var r = t.insertRow(), c1 = r.insertCell(), c2 = r.insertCell(), c3 = r.insertCell(), s = document.createElement('select');
                        c1.textContent = h || '(بدون عنوان)';
                        c2.textContent = d.sample.map(function (x) { return (x[i] || '').slice(0, 40); }).filter(Boolean).join(' ‖ ');
                        c2.className = 'muted';
                        function opt(v, l) { var o = document.createElement('option'); o.value = v; o.textContent = l; s.appendChild(o); }
                        Object.keys(d.targets).forEach(function (k) { opt(k, d.targets[k]); });
                        d.extras.forEach(function (x) { opt('x:' + x.key, 'ستون دلخواه: ' + x.label); });
                        opt('x:new', 'ستون دلخواه جدید: «' + (h || '؟') + '»');
                        s.value = d.mapping[i]; s.dataset.i = i; s.className = 'imp-sel'; c3.appendChild(s);
                    });
                    $('imp-step2').hidden = false;
                }).catch(function (e) { $('imp-status').textContent = e.message; });
            };
            $('imp-run').onclick = function () {
                if (!imp) return;
                var mode = document.querySelector('input[name=imp-mode]:checked').value;
                if (mode === 'replace' && !confirm('همه‌ی کارت‌های فعلی به زباله‌دان می‌روند و لیست از روی فایل ساخته می‌شود. ادامه؟')) return;
                var map = {}; Array.prototype.forEach.call(document.querySelectorAll('.imp-sel'), function (s) { map[s.dataset.i] = s.value; });
                if (Object.keys(map).every(function (k) { return map[k] !== 'name'; })) { alert('ستونی برای «نام» انتخاب نشده است.'); return; }
                var sum = { created: 0, merged: 0, same: 0, skipped: 0, error: 0 }; $('imp-run').disabled = true; $('imp-log').textContent = '';
                (function step(off) {
                    post('tfy_import_run', { id: imp.id, mapping: JSON.stringify(map), mode: mode, offset: off, batch: imp.batch }).then(function (r) {
                        for (var k in sum) sum[k] += r.counts[k] || 0;
                        $('imp-bar').style.width = Math.round(Math.min(r.next, r.total) / r.total * 100) + '%';
                        $('imp-status').textContent = Math.min(r.next, r.total) + ' از ' + r.total;
                        if (!r.done) return step(r.next);
                        $('imp-run').disabled = false;
                        logTo($('imp-log'), 'تمام شد: ' + sum.created + ' کارت جدید، ' + sum.merged + ' ادغام/به‌روزرسانی، ' + sum.same + ' بدون تغییر، ' + sum.skipped + ' ردیف بدون نام، ' + sum.error + ' خطا.');
                        logTo($('imp-log'), 'ستون‌ها و گزینه‌های جدید خودکار به کارت‌ها، فرم و فیلترها اضافه شدند. برگرداندن: تاریخچه تغییرات.');
                    }).catch(function (e) { $('imp-run').disabled = false; logTo($('imp-log'), 'خطا: ' + e.message); });
                })(0);
            };
        }

        /* تکمیل کلی */
        if ($('loc-run')) {
            var locStop = false;
            $('loc-stop').onclick = function () { locStop = true; };
            $('loc-run').onclick = function () {
                locStop = false; $('loc-run').disabled = true; $('loc-log').textContent = ''; $('loc-status').textContent = 'در حال محاسبه…';
                post('tfy_loc_list', {}).then(function (d) {
                    var q = d.list, i = 0, ok = 0, none = [], batch = bid();
                    function nx() {
                        if (i >= q.length || locStop) {
                            $('loc-run').disabled = false; $('loc-status').textContent = 'پایان: ' + ok + ' لوکیشن ثبت شد، ' + none.length + ' مورد پیدا نشد.';
                            if (none.length) logTo($('loc-log'), '— لینک نشان این موارد را دستی بگذارید: ' + none.join('، '));
                            return;
                        }
                        var it = q[i]; $('loc-status').textContent = it.name + ' (' + (i + 1) + ' از ' + q.length + ')';
                        post('tfy_loc_item', { id: it.id, batch: batch }).then(function (r) {
                            if (r.changed) { ok++; logTo($('loc-log'), '✓ ' + it.name + (r.status === 'osm' ? ' (OpenStreetMap؛ دقت را چک کنید)' : ' (از لینک)')); } else none.push(it.name);
                        }).catch(function (e) { none.push(it.name); logTo($('loc-log'), '✗ ' + it.name + ': ' + e.message); })
                          .then(function () { i++; return sleep(1200); }).then(nx);
                    }
                    nx();
                }).catch(function (e) { $('loc-status').textContent = e.message; $('loc-run').disabled = false; });
            };
        }
        if ($('img-run')) {
            var imgStop = false;
            $('img-stop').onclick = function () { imgStop = true; };
            $('img-run').onclick = function () {
                imgStop = false; $('img-run').disabled = true; $('img-log').textContent = ''; $('img-status').textContent = 'در حال محاسبه…';
                post('tfy_img_list', { retry: $('img-retry').checked ? 1 : '' }).then(function (d) {
                    var q = d.list, i = 0, ok = 0, full = 0, none = [], batch = bid(), ai = $('img-ai').checked ? 1 : '';
                    if (!q.length) { $('img-status').textContent = 'همه‌ی کارت‌ها ' + d.target + ' عکس دارند (یا اخیرا بررسی شده‌اند).'; $('img-run').disabled = false; return; }
                    function nx() {
                        if (i >= q.length || imgStop) {
                            $('img-run').disabled = false; $('img-status').textContent = 'پایان: ' + full + ' کارت کامل شد، ' + ok + ' کارت عکس گرفت (جمعا ناقص‌شده)، ' + none.length + ' کارت چیزی پیدا نشد.';
                            if (none.length) logTo($('img-log'), '— عکس این موارد پیدا نشد (دستی بگذارید): ' + none.join('، '));
                            return;
                        }
                        var it = q[i]; $('img-status').textContent = it.name + ' (' + (i + 1) + ' از ' + q.length + ')';
                        post('tfy_img_item', { id: it.id, batch: batch, ai: ai }).then(function (r) {
                            if (r.added) { ok++; if (r.total >= d.target) full++; logTo($('img-log'), '✓ ' + it.name + ': ' + r.added + ' عکس اضافه شد (اکنون ' + r.total + ')'); }
                            else { none.push(it.name); logTo($('img-log'), '✗ ' + it.name + ': عکس مطمئن پیدا نشد (' + r.total + ' عکس دارد) — ' + (r.note || '')); }
                        }).catch(function (e) { none.push(it.name); logTo($('img-log'), '✗ ' + it.name + ': ' + e.message); })
                          .then(function () { i++; return sleep(ai ? 3500 : 800); }).then(nx);
                    }
                    nx();
                }).catch(function (e) { $('img-status').textContent = e.message; $('img-run').disabled = false; });
            };
        }
        if ($('pc-run')) {
            $('pc-run').onclick = function () {
                if (!confirm('همه‌ی قیمت‌ها و منوهایی که هوش مصنوعی پر کرده پاک می‌شود (قیمت دستی و اکسل دست نمی‌خورد). ادامه؟')) return;
                var b = this; b.disabled = true; $('pc-status').textContent = 'در حال محاسبه…';
                post('tfy_prices_ai_list', {}).then(function (d) {
                    var ids = d.ids, i = 0, done = 0, batch = bid();
                    (function nx() {
                        if (i >= ids.length) { b.disabled = false; $('pc-status').textContent = 'پاک شد: ' + done + ' کارت. حالا «محاسبه‌ی کارت‌های ناقص» و تکمیل را بزنید.'; return; }
                        post('tfy_prices_ai_clear', { ids: ids.slice(i, i + 40), batch: batch }).then(function (r) { done += r.cleared; i += 40; $('pc-status').textContent = done + ' از ' + ids.length; nx(); })
                            .catch(function (e) { $('pc-status').textContent = e.message; b.disabled = false; });
                    })();
                }).catch(function (e) { $('pc-status').textContent = e.message; b.disabled = false; });
            };
        }
        if ($('bulk-load')) {
            var queue = [], stopB = false;
            $('bulk-load').onclick = function () {
                $('bulk-count').textContent = 'در حال محاسبه…';
                post('tfy_bulk_list', { retry: $('bulk-retry').checked ? 1 : '', refresh: $('bulk-refresh').checked ? 1 : '' }).then(function (d) {
                    queue = d.list; $('bulk-count').textContent = queue.length + ' کارت از ' + d.total + ' کارت فیلد خالی دارد.';
                    $('bulk-run').disabled = !queue.length;
                }).catch(function (e) { $('bulk-count').textContent = e.message; });
            };
            $('bulk-stop').onclick = function () { stopB = true; };
            $('bulk-run').onclick = function () {
                if (!queue.length) return;
                if (!confirm('تکمیل ' + queue.length + ' کارت شروع می‌شود و از سهمیه‌ی سرویس هوش مصنوعی مصرف می‌کند. ادامه؟')) return;
                var batch = bid(), i = 0, ok = 0, bad = 0, consec = 0, n = queue.length; stopB = false; $('bulk-run').disabled = true; $('bulk-log').textContent = '';
                (function next() {
                    if (stopB || i >= n || consec >= 3) {
                        $('bulk-run').disabled = false; $('bulk-status').textContent = 'پایان: ' + ok + ' موفق، ' + bad + ' ناموفق' + (consec >= 3 ? ' (به‌دلیل خطای پشت‌سرهم متوقف شد)' : '');
                        logTo($('bulk-log'), '— پایان. برای برگرداندن کل عملیات: تاریخچه تغییرات.'); return;
                    }
                    var it = queue[i]; $('bulk-status').textContent = it.name + ' (' + (i + 1) + ' از ' + n + ')';
                    post('tfy_bulk_item', { id: it.id, batch: batch, refresh: $('bulk-refresh').checked ? 1 : '', create_related: $('bulk-related').checked ? 1 : '' }).then(function (r) {
                        ok++; consec = 0; logTo($('bulk-log'), '✓ ' + it.name + ': ' + (r.filled.length ? r.filled.join('، ') : 'چیز تازه‌ای پیدا نشد') + (r.search_used ? '' : ' (بدون جستجوی وب)'));
                        (r.notes || []).forEach(function (n) { logTo($('bulk-log'), '   ! ' + n); });
                    }).catch(function (e) { bad++; consec++; logTo($('bulk-log'), '✗ ' + it.name + ': ' + e.message); })
                      .then(function () { i++; $('bulk-bar').style.width = Math.round(i / n * 100) + '%'; return sleep(4000); }).then(next);
                })();
            };
        }

        /* تاریخچه */
        Array.prototype.forEach.call(document.querySelectorAll('.tfy-rev'), function (b) {
            b.onclick = function () { b.disabled = true; post('tfy_hist_revert', { hid: b.dataset.hid }).then(function () { location.href = histUrl; }).catch(function (e) { alert(e.message); b.disabled = false; }); };
        });
        Array.prototype.forEach.call(document.querySelectorAll('.tfy-rev-batch'), function (b) {
            b.onclick = function () {
                if (!confirm('کل این عملیات برگردانده شود؟')) return; b.disabled = true;
                post('tfy_hist_revert', { batch: b.dataset.batch }).then(function (r) { alert(r.ok + ' مورد برگردانده شد' + (r.fail ? '، ' + r.fail + ' مورد ناموفق' : '')); location.href = histUrl; })
                    .catch(function (e) { alert(e.message); b.disabled = false; });
            };
        });
    })();
    </script>
    <?php
}
