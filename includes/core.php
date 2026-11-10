<?php
if (!defined('ABSPATH')) {
    exit;
}

const TFY_CPT = 'tafrih';

function tfy_times_allowed()
{
    return array('صبح', 'ظهر', 'عصر', 'شب', 'آخر هفته', 'هروقت');
}

function tfy_modes_allowed()
{
    return array('solo', 'partner', 'friends', 'family', 'group');
}

function tfy_tag_labels()
{
    return array('kids' => 'کودک‌پسند', 'pets' => 'همراه حیوان خانگی', 'parking' => 'پارکینگ دارد', 'wheelchair' => 'مناسب ویلچر', 'indoor' => 'سرپوشیده', 'outdoor' => 'فضای باز');
}

function tfy_tags_allowed()
{
    return array_keys(tfy_tag_labels());
}

function tfy_mode_labels()
{
    return array('solo' => 'تنها', 'partner' => 'با پارتنر', 'friends' => 'دوستانه', 'family' => 'خانوادگی', 'group' => 'گروهی');
}

function tfy_durations_allowed()
{
    return array(1, 3, 6, 12);
}

function tfy_cost_labels()
{
    return array('رایگان', 'ارزان', 'متوسط', 'گران', 'لوکس');
}

function tfy_register_cpt()
{
    register_post_type(TFY_CPT, array(
        'labels' => array(
            'name' => 'تفریحات',
            'singular_name' => 'تفریح',
        ),
        'public' => false,
        // مدیریت داده فقط از منوی «تفریح‌یاب» انجام می‌شود تا هر تغییر در تاریخچه ثبت شود.
        'show_ui' => false,
        'show_in_menu' => false,
        'supports' => array('title', 'editor'),
        'show_in_rest' => false,
        'has_archive' => false,
        'rewrite' => false,
        'exclude_from_search' => true,
        'publicly_queryable' => false,
    ));
}
add_action('init', 'tfy_register_cpt');

/* ------------------------------------------------------------------ */
/* ابزارهای متنی                                                       */
/* ------------------------------------------------------------------ */

function tfy_digits_en($s)
{
    $s = strtr((string) $s, array(
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ));
    return $s;
}

/** نرمال‌سازی برای مقایسه‌ی نام‌ها (ی/ک عربی، نیم‌فاصله، اعراب، ارقام، علائم). */
function tfy_norm($s)
{
    $s = (string) $s;
    $s = str_replace(array('ي', 'ى', 'ك', 'ۀ', 'ة'), array('ی', 'ی', 'ک', 'ه', 'ه'), $s);
    $s = preg_replace('/[\x{200c}\x{200d}\x{200e}\x{200f}\x{0640}\x{064B}-\x{065F}]/u', '', $s);
    $s = tfy_digits_en($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function tfy_key_of($s)
{
    return str_replace(' ', '', tfy_norm($s));
}

/** قیمت (تومان) از متن آزاد. رایگان = 0، نامشخص = null. */
function tfy_parse_price($s)
{
    if (is_int($s) || is_float($s)) {
        return max(0, (int) round($s));
    }
    $s = trim(tfy_digits_en((string) $s));
    if ($s === '') {
        return null;
    }
    if (preg_match('/رایگان|مجانی|بدون هزینه|free/iu', $s)) {
        return 0;
    }
    if (preg_match('/نامشخص|unknown|^\?+$/iu', $s)) {
        return null;
    }
    $mult = 1;
    if (preg_match('/میلیون/u', $s)) {
        $mult = 1000000;
    } elseif (preg_match('/هزار/u', $s)) {
        $mult = 1000;
    }
    $t = str_replace(array(',', '٬', '،'), '', $s);
    $t = str_replace('٫', '.', $t);
    if (!preg_match('/\d+(?:\.\d+)?/', $t, $m)) {
        return null;
    }
    $v = (float) $m[0] * $mult;
    if (preg_match('/ریال/u', $s)) {
        $v /= 10;
    }
    return (int) round($v);
}

function tfy_format_price($p)
{
    if ($p === null || $p === '') {
        return 'نامشخص';
    }
    if ((int) $p === 0) {
        return 'رایگان';
    }
    return number_format((int) $p) . ' تومان';
}

/* ------------------------------------------------------------------ */
/* ساختارهای هزینه / منو                                               */
/* ------------------------------------------------------------------ */

function tfy_clean_costs($v)
{
    $out = array();
    foreach (array_slice((array) $v, 0, 30) as $r) {
        if (is_string($r)) {
            $r = array('label' => $r);
        }
        if (!is_array($r)) {
            continue;
        }
        $label = isset($r['label']) ? mb_substr(sanitize_text_field((string) $r['label']), 0, 120) : '';
        if ($label === '') {
            continue;
        }
        $price = array_key_exists('price', $r) ? tfy_parse_price($r['price']) : null;
        $note = isset($r['note']) ? mb_substr(sanitize_text_field((string) $r['note']), 0, 200) : '';
        $out[] = array('label' => $label, 'price' => $price, 'note' => $note);
    }
    return $out;
}

function tfy_clean_menu($v)
{
    $out = array();
    foreach (array_slice((array) $v, 0, 400) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $name = isset($r['name']) ? mb_substr(sanitize_text_field((string) $r['name']), 0, 120) : '';
        if ($name === '') {
            continue;
        }
        $cat = isset($r['cat']) ? mb_substr(sanitize_text_field((string) $r['cat']), 0, 80) : '';
        $price = array_key_exists('price', $r) ? tfy_parse_price($r['price']) : null;
        $out[] = array('cat' => $cat, 'name' => $name, 'price' => $price);
    }
    return $out;
}

/** هر خط: عنوان | قیمت | توضیح */
function tfy_parse_costlines($text)
{
    $rows = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $p = array_map('trim', explode('|', $line));
        $rows[] = array('label' => $p[0], 'price' => isset($p[1]) ? $p[1] : null, 'note' => isset($p[2]) ? $p[2] : '');
    }
    return tfy_clean_costs($rows);
}

function tfy_format_costlines($arr)
{
    $lines = array();
    foreach ((array) $arr as $r) {
        $price = ($r['price'] === null) ? '' : ((int) $r['price'] === 0 ? 'رایگان' : (string) (int) $r['price']);
        $lines[] = $r['label'] . ' | ' . $price . ($r['note'] !== '' ? ' | ' . $r['note'] : '');
    }
    return implode("\n", $lines);
}

/** هر خط: دسته | نام | قیمت  (یا فقط نام | قیمت) */
function tfy_parse_menulines($text)
{
    $rows = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $p = array_map('trim', explode('|', $line));
        if (count($p) >= 3) {
            $rows[] = array('cat' => $p[0], 'name' => $p[1], 'price' => $p[2]);
        } elseif (count($p) === 2) {
            $rows[] = array('cat' => '', 'name' => $p[0], 'price' => $p[1]);
        } else {
            $rows[] = array('cat' => '', 'name' => $p[0], 'price' => null);
        }
    }
    return tfy_clean_menu($rows);
}

function tfy_format_menulines($arr)
{
    $lines = array();
    foreach ((array) $arr as $r) {
        $price = ($r['price'] === null) ? '' : ((int) $r['price'] === 0 ? 'رایگان' : (string) (int) $r['price']);
        $lines[] = ($r['cat'] !== '' ? $r['cat'] . ' | ' : '') . $r['name'] . ' | ' . $price;
    }
    return implode("\n", $lines);
}

/* ------------------------------------------------------------------ */
/* ستون‌های دلخواه (از اکسل یا دستی)                                    */
/* ------------------------------------------------------------------ */

function tfy_extra_fields()
{
    $f = get_option('tfy_extra_fields', array());
    return is_array($f) ? $f : array();
}

/** @return string کلید فیلد (در صورت نبودن ساخته می‌شود) */
function tfy_extra_register($label)
{
    $label = mb_substr(sanitize_text_field((string) $label), 0, 60);
    if ($label === '') {
        return '';
    }
    $fields = tfy_extra_fields();
    $n = tfy_key_of($label);
    foreach ($fields as $k => $l) {
        if (tfy_key_of($l) === $n) {
            return $k;
        }
    }
    $key = 'x' . substr(md5($n), 0, 8);
    $fields[$key] = $label;
    update_option('tfy_extra_fields', $fields, false);
    return $key;
}

function tfy_clean_extra($v)
{
    $allowed = tfy_extra_fields();
    $out = array();
    foreach ((array) $v as $k => $val) {
        if (isset($allowed[$k]) && is_scalar($val)) {
            $out[$k] = mb_substr(sanitize_text_field((string) $val), 0, 1000);
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* تعریف فیلدها                                                        */
/* ------------------------------------------------------------------ */

function tfy_fields()
{
    return array(
        'category'     => 'str',
        'row'          => 'int',
        'location'     => 'url',
        'lat'          => 'float',
        'lng'          => 'float',
        'loc_src'      => 'str',
        'duration_h'   => 'dur',
        'times'        => 'times',
        'modes'        => 'modes',
        'tags'         => 'tags',
        'cost_level'   => 'cost',
        'cost_note'    => 'str',
        'costs'        => 'costs',
        'costs_extra'  => 'costs',
        'menu'         => 'menu',
        'prices_at'    => 'int',
        'prices_src'   => 'str',
        'related'      => 'names',
        'city'         => 'str',
        'area'         => 'str',
        'address'      => 'str',
        'hours'        => 'str',
        'phone'        => 'str',
        'website'      => 'url',
        'gallery'      => 'gallery',
        'favorite'     => 'bool',
        'done'         => 'bool',
        'notes'        => 'text',
        'guess_modes'  => 'bool',
        'modes_ai'     => 'bool',
        'enriched_at'  => 'int',
        'sources'      => 'urls',
        'extra'        => 'extra',
    );
}

function tfy_clean_value($type, $v)
{
    switch ($type) {
        case 'str':
            return mb_substr(sanitize_text_field((string) $v), 0, 300);
        case 'text':
            return mb_substr(sanitize_textarea_field((string) $v), 0, 5000);
        case 'int':
            return (int) $v;
        case 'float':
            return is_numeric($v) ? round((float) $v, 7) : '';
        case 'bool':
            return $v ? 1 : 0;
        case 'url':
            $u = esc_url_raw(trim((string) $v), array('http', 'https'));
            return $u ? $u : '';
        case 'dur':
            $v = (int) $v;
            return in_array($v, tfy_durations_allowed(), true) ? $v : 3;
        case 'cost':
            if ($v === '' || $v === null) {
                return '';
            }
            $v = (int) $v;
            return ($v >= 0 && $v <= 4) ? $v : '';
        case 'times':
            return array_values(array_intersect(tfy_times_allowed(), (array) $v));
        case 'modes':
            return array_values(array_intersect(tfy_modes_allowed(), (array) $v));
        case 'tags':
            return array_values(array_intersect(tfy_tags_allowed(), (array) $v));
        case 'costs':
            return tfy_clean_costs($v);
        case 'menu':
            return tfy_clean_menu($v);
        case 'names':
            $out = array();
            foreach (array_slice((array) $v, 0, 30) as $n) {
                $n = mb_substr(sanitize_text_field((string) $n), 0, 120);
                if ($n !== '' && !in_array($n, $out, true)) {
                    $out[] = $n;
                }
            }
            return $out;
        case 'extra':
            return tfy_clean_extra($v);
        case 'gallery':
            $out = array();
            // عضو گالری: شناسه‌ی فایل بارگذاری‌شده در هاست (عدد) یا آدرس عکس خارجی (لینک http/https).
            foreach ((array) $v as $id) {
                if (is_string($id) && preg_match('#^https?://#i', $id)) {
                    $u = esc_url_raw($id, array('http', 'https'));
                    if ($u && !in_array($u, $out, true)) {
                        $out[] = $u;
                    }
                    continue;
                }
                $id = (int) $id;
                if ($id && get_post_type($id) === 'attachment' && !in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
            return $out;
        case 'urls':
            $out = array();
            foreach (array_slice((array) $v, 0, 12) as $u) {
                $u = esc_url_raw((string) $u, array('http', 'https'));
                if ($u) {
                    $out[] = $u;
                }
            }
            return $out;
    }
    return '';
}

function tfy_next_row()
{
    global $wpdb;
    $max = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(CAST(pm.meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = %s AND p.post_type = %s",
        '_tfy_row',
        TFY_CPT
    ));
    return ((int) $max) + 1;
}

/**
 * ساخت یا ویرایش. فقط کلیدهای موجود در $data دست می‌خورند.
 * @return int|WP_Error
 */
function tfy_save_item($id, array $data)
{
    $title = isset($data['name']) ? trim(sanitize_text_field((string) $data['name'])) : null;

    if ($id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== TFY_CPT) {
            return new WP_Error('tfy_notfound', 'مورد پیدا نشد.', array('status' => 404));
        }
        $args = array('ID' => $id);
        if ($title !== null) {
            if ($title === '') {
                return new WP_Error('tfy_name', 'نام تفریح الزامی است.', array('status' => 400));
            }
            $args['post_title'] = $title;
        }
        if (array_key_exists('description', $data)) {
            $args['post_content'] = mb_substr(sanitize_textarea_field((string) $data['description']), 0, 5000);
        }
        if (count($args) > 1) {
            $r = wp_update_post($args, true);
            if (is_wp_error($r)) {
                return $r;
            }
        }
    } else {
        if (!$title) {
            return new WP_Error('tfy_name', 'نام تفریح الزامی است.', array('status' => 400));
        }
        $id = wp_insert_post(array(
            'post_type'    => TFY_CPT,
            'post_status'  => (isset($data['_status']) && $data['_status'] === 'pending') ? 'pending' : 'publish',
            'post_title'   => $title,
            'post_content' => isset($data['description']) ? mb_substr(sanitize_textarea_field((string) $data['description']), 0, 5000) : '',
        ), true);
        if (is_wp_error($id)) {
            return $id;
        }
        if (!isset($data['row']) || (int) $data['row'] <= 0) {
            $data['row'] = tfy_next_row();
        }
        if (!isset($data['duration_h'])) {
            $data['duration_h'] = 3;
        }
        if (empty($data['times'])) {
            $data['times'] = array('هروقت');
        }
        if (empty($data['modes'])) {
            $data['modes'] = tfy_modes_allowed();
        }
    }

    foreach (tfy_fields() as $key => $type) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $val = $data[$key];
        if ($key === 'extra') {
            // ادغام با مقادیر قبلی: کلیدهایی که نیامده‌اند دست‌نخورده می‌مانند.
            $cur = get_post_meta($id, '_tfy_extra', true);
            $val = array_merge(is_array($cur) ? $cur : array(), tfy_clean_extra($val));
        }
        update_post_meta($id, '_tfy_' . $key, tfy_clean_value($type, $val));
    }
    tfy_name_index(true);
    return (int) $id;
}

/* ------------------------------------------------------------------ */
/* خواندن                                                              */
/* ------------------------------------------------------------------ */

function tfy_attachment_urls($att_id)
{
    $thumb = wp_get_attachment_image_url($att_id, 'medium_large');
    $full = wp_get_attachment_image_url($att_id, 'large');
    if (!$full) {
        $full = wp_get_attachment_url($att_id);
    }
    if (!$thumb) {
        $thumb = $full;
    }
    if (!$full) {
        return null;
    }
    return array('id' => (int) $att_id, 'thumb' => $thumb, 'full' => $full);
}

/** نقشه‌ی نام نرمال‌شده → شناسه برای باز شدن «تفریحات تکمیلی». */
function tfy_name_index($reset = false)
{
    static $idx = null;
    if ($reset) {
        $idx = null;
        return array();
    }
    if ($idx === null) {
        global $wpdb;
        $idx = array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC",
            TFY_CPT
        ));
        foreach ((array) $rows as $r) {
            $k = tfy_key_of($r->post_title);
            if ($k !== '' && !isset($idx[$k])) {
                $idx[$k] = (int) $r->ID;
            }
        }
    }
    return $idx;
}

function tfy_item_to_array($post)
{
    $id = $post->ID;
    $g = function ($k) use ($id) {
        return get_post_meta($id, '_tfy_' . $k, true);
    };
    $arr = function ($k) use ($g) {
        $v = $g($k);
        return is_array($v) ? $v : array();
    };

    $images = array();
    foreach ($arr('gallery') as $aid) {
        if (is_string($aid)) {
            if (!tfy_bad_image_host($aid)) {
                $images[] = array('id' => 0, 'ext' => true, 'url' => $aid, 'thumb' => $aid, 'full' => $aid);
            }
            continue;
        }
        $u = tfy_attachment_urls($aid);
        if ($u) {
            $images[] = $u;
        }
    }
    // عکسِ اکسلی که هنوز در صف آپلود قدیمی مانده: همان لینک نمایش داده می‌شود.
    if (!$images) {
        $pend = (string) $g('img_src');
        if ($pend !== '' && preg_match('#^https?://#i', $pend) && !tfy_bad_image_host($pend)) {
            $images[] = array('id' => 0, 'ext' => true, 'url' => $pend, 'thumb' => $pend, 'full' => $pend);
        }
    }
    // لوکیشن: فقط نشان. لینک دستیِ نشان یا لینک ساخته‌شده از مختصات دقیق.
    $raw_loc = (string) $g('location');
    $lat = $g('lat');
    $lng = $g('lng');
    $loc = '';
    if ($raw_loc !== '' && tfy_is_neshan_url($raw_loc)) {
        $loc = $raw_loc;
    } elseif (tfy_coords_ok($lat, $lng)) {
        $loc = tfy_neshan_link((float) $lat, (float) $lng);
    }

    $cost = $g('cost_level');
    $idx = tfy_name_index();
    $related = array();
    foreach ($arr('related') as $n) {
        $k = tfy_key_of($n);
        $rid = isset($idx[$k]) ? $idx[$k] : 0;
        $related[] = array('name' => $n, 'id' => ($rid && $rid !== $id) ? $rid : null);
    }

    return array(
        'id'          => $id,
        'status'      => $post->post_status,
        'name'        => $post->post_title,
        'description' => $post->post_content,
        'category'    => (string) $g('category'),
        'row'         => (int) $g('row'),
        'location'    => $loc,
        'lat'         => tfy_coords_ok($lat, $lng) ? (float) $lat : null,
        'lng'         => tfy_coords_ok($lat, $lng) ? (float) $lng : null,
        'loc_src'     => (string) $g('loc_src'),
        'duration_h'  => (int) ($g('duration_h') ?: 3),
        'times'       => $arr('times'),
        'modes'       => $arr('modes'),
        'tags'        => $arr('tags'),
        'cost_level'  => ($cost === '' || $cost === false || $cost === null) ? null : (int) $cost,
        'cost_note'   => (string) $g('cost_note'),
        'costs'       => $arr('costs'),
        'costs_extra' => $arr('costs_extra'),
        'menu'        => $arr('menu'),
        'prices_at'   => (int) $g('prices_at'),
        'price_review' => (int) $g('price_review'),
        'prices_src'  => (string) $g('prices_src'),
        'related'     => $related,
        'city'        => (string) $g('city'),
        'area'        => (string) $g('area'),
        'address'     => (string) $g('address'),
        'hours'       => (string) $g('hours'),
        'phone'       => (string) $g('phone'),
        'website'     => (string) $g('website'),
        'images'      => $images,
        'favorite'    => (bool) $g('favorite'),
        'done'        => (bool) $g('done'),
        'notes'       => (string) $g('notes'),
        'guess_modes' => (bool) $g('guess_modes'),
        'modes_ai'    => (bool) $g('modes_ai'),
        'enriched_at' => (int) $g('enriched_at'),
        'sources'     => $arr('sources'),
        'extra'       => (object) $arr('extra'),
    );
}

function tfy_get_item($id, $any = false)
{
    $post = get_post($id);
    if (!$post || $post->post_type !== TFY_CPT || !in_array($post->post_status, $any ? array('publish', 'pending') : array('publish'), true)) {
        return null;
    }
    return tfy_item_to_array($post);
}

function tfy_all_items($statuses = array('publish'))
{
    // کوئری مستقیم (نه WP_Query): بعضی قالب‌ها و افزونه‌ها با فیلتر، تعداد نتایج WP_Query را محدود می‌کنند و فقط ۱۰۰ کارت برمی‌گشت.
    global $wpdb;
    $statuses = array_values(array_intersect((array) $statuses, array('publish', 'pending')));
    if (!$statuses) {
        return array();
    }
    $in = implode(',', array_map(function ($s) use ($wpdb) {
        return $wpdb->prepare('%s', $s);
    }, $statuses));
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($in) ORDER BY ID ASC", TFY_CPT));
    $out = array();
    foreach (array_chunk(array_map('intval', (array) $ids), 200) as $chunk) {
        _prime_post_caches($chunk, false, true);
        update_meta_cache('post', $chunk);
        foreach ($chunk as $id) {
            $p = get_post($id);
            if ($p && $p->post_type === TFY_CPT) {
                $out[] = tfy_item_to_array($p);
            }
        }
    }
    return $out;
}

function tfy_extra_schema()
{
    $out = array();
    foreach (tfy_extra_fields() as $k => $l) {
        $out[] = array('key' => $k, 'label' => $l);
    }
    return $out;
}

/** فیلدهای خالی یک کارت که هوش مصنوعی می‌تواند پر کند. */
function tfy_item_needs($it)
{
    $n = array();
    foreach (array('city' => 'شهر', 'area' => 'محدوده', 'address' => 'آدرس', 'hours' => 'ساعت کاری', 'phone' => 'تلفن', 'website' => 'وب‌سایت') as $k => $l) {
        if ($it[$k] === '') {
            $n[$k] = $l;
        }
    }
    if (!$it['costs'] && !$it['costs_extra']) {
        $n['costs'] = 'هزینه‌ها';
    }
    if (count($it['images']) < tfy_target_images() && (int) get_post_meta($it['id'], '_tfy_img_tried', true) < time() - 14 * DAY_IN_SECONDS) {
        $n['images'] = 'عکس (کمتر از ' . tfy_target_images() . ')';
    }
    if ($it['location'] === '' && (int) get_post_meta($it['id'], '_tfy_loc_tried', true) < time() - 30 * DAY_IN_SECONDS) {
        $n['location'] = 'لوکیشن';
    }
    if (!$it['menu'] && tfy_is_food_item($it)) {
        $n['menu'] = 'منو';
    }
    if ($it['guess_modes'] && !$it['modes_ai']) {
        $n['modes'] = 'همراه';
    }
    if (!$it['related'] && (int) get_post_meta($it['id'], '_tfy_rel_tried', true) < time() - 30 * DAY_IN_SECONDS) {
        $n['related'] = 'تفریحات تکمیلی';
    }
    $extra = (array) $it['extra'];
    foreach (tfy_extra_fields() as $k => $l) {
        if (!isset($extra[$k]) || $extra[$k] === '') {
            $n['x:' . $k] = $l;
        }
    }
    return $n;
}

function tfy_is_food_item($it)
{
    return (bool) preg_match('/کافه|کافی|رستوران|فست|غذا|صبحانه|قهوه|بستنی|شیرینی|کباب|چای/u', $it['category'] . ' ' . $it['name']);
}
