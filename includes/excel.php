<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ورودی/خروجی اکسل بدون کتابخانه‌ی خارجی (فقط ZipArchive و SimpleXML که روی هاست‌های وردپرس هست).
 * ورودی: xlsx یا csv. خروجی: xlsx (اگر ZipArchive نبود csv).
 */

/* ---------------------------- خواندن ---------------------------- */

function tfy_col_index($ref)
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $n - 1);
}

/** @return array|WP_Error آرایه‌ی ردیف‌ها (هر ردیف آرایه‌ی رشته‌ها) */
function tfy_read_table($path, $name)
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        return tfy_read_csv($path);
    }
    if ($ext !== 'xlsx') {
        return new WP_Error('tfy_fmt', 'فقط فایل xlsx یا csv پشتیبانی می‌شود (اگر فایل xls قدیمی است، در اکسل «Save As > xlsx» کنید).');
    }
    if (!class_exists('ZipArchive')) {
        return new WP_Error('tfy_zip', 'افزونه ZipArchive روی هاست فعال نیست؛ فایل را به csv تبدیل کنید.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return new WP_Error('tfy_zipopen', 'فایل اکسل خراب است یا xlsx معتبر نیست.');
    }
    $shared = array();
    $x = $zip->getFromName('xl/sharedStrings.xml');
    if ($x !== false) {
        $xml = @simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        if ($xml) {
            foreach ($xml->si as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string) $si->t;
                } else {
                    if (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $t .= (string) $r->t;
                        }
                    }
                }
                $shared[] = $t;
            }
        }
    }
    // پیدا کردن اولین شیت
    $sheet = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $wbx = @simplexml_load_string($wb, 'SimpleXMLElement', LIBXML_NONET);
        $rx = @simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
        if ($wbx && $rx && isset($wbx->sheets->sheet[0])) {
            $rid = (string) $wbx->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            foreach ($rx->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $t = ltrim((string) $rel['Target'], '/');
                    $sheet = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
                }
            }
        }
    }
    $sx = $zip->getFromName($sheet);
    $zip->close();
    if ($sx === false) {
        return new WP_Error('tfy_nosheet', 'شیت اول فایل خوانده نشد.');
    }
    $xml = @simplexml_load_string($sx, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    if (!$xml) {
        return new WP_Error('tfy_badsheet', 'محتوای شیت خوانده نشد.');
    }
    $rows = array();
    foreach ($xml->sheetData->row as $row) {
        $r = array();
        foreach ($row->c as $c) {
            $i = tfy_col_index((string) $c['r']);
            $t = (string) $c['t'];
            if ($t === 's') {
                $v = isset($shared[(int) $c->v]) ? $shared[(int) $c->v] : '';
            } elseif ($t === 'inlineStr') {
                $v = '';
                if (isset($c->is->t)) {
                    $v = (string) $c->is->t;
                } else {
                    if (isset($c->is->r)) {
                        foreach ($c->is->r as $rr) {
                            $v .= (string) $rr->t;
                        }
                    }
                }
            } else {
                $v = (string) $c->v;
                if ($t === '' && is_numeric($v) && strpos($v, '.') !== false) {
                    $v = rtrim(rtrim(sprintf('%.10F', (float) $v), '0'), '.');
                }
            }
            $r[$i] = trim($v);
        }
        if ($r) {
            $max = max(array_keys($r));
            $full = array();
            for ($i = 0; $i <= $max; $i++) {
                $full[] = isset($r[$i]) ? $r[$i] : '';
            }
            $rows[] = $full;
        } else {
            $rows[] = array();
        }
    }
    return tfy_trim_rows($rows);
}

function tfy_read_csv($path)
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        return new WP_Error('tfy_csv', 'فایل خوانده نشد.');
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1256');
    }
    $first = strtok($raw, "\n");
    $delim = ',';
    foreach (array(';', "\t", '|') as $d) {
        if (substr_count($first, $d) > substr_count($first, $delim)) {
            $delim = $d;
        }
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = array();
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        $rows[] = array_map('trim', $r);
    }
    fclose($fh);
    return tfy_trim_rows($rows);
}

function tfy_trim_rows($rows)
{
    $out = array();
    foreach ($rows as $r) {
        $has = false;
        foreach ($r as $v) {
            if ($v !== '') {
                $has = true;
                break;
            }
        }
        if ($has) {
            $out[] = $r;
        }
    }
    return $out;
}

/* ---------------------------- نگاشت ستون‌ها ---------------------------- */

/** کلیدهای مقصد ایمپورت و عنوان فارسی آن‌ها (عنوان‌ها همان سرستون‌های خروجی اکسل‌اند، پس رفت‌وبرگشت کامل است). */
function tfy_import_targets()
{
    return array(
        'row' => 'ردیف', 'name' => 'نام', 'category' => 'دسته', 'description' => 'توضیح', 'location' => 'لینک لوکیشن',
        'city' => 'شهر', 'area' => 'محدوده', 'address' => 'آدرس', 'hours' => 'ساعت کاری', 'phone' => 'تلفن', 'website' => 'وب‌سایت',
        'duration_h' => 'مدت (ساعت)', 'times' => 'زمان مناسب', 'modes' => 'مناسب برای', 'tags' => 'ویژگی‌ها', 'cost_level' => 'سطح هزینه', 'cost_note' => 'توضیح هزینه',
        'costs' => 'هزینه‌های اصلی', 'costs_extra' => 'هزینه‌های فرعی و پیشنهادی', 'menu' => 'منو', 'related' => 'تفریحات تکمیلی',
        'favorite' => 'علاقه‌مندی', 'done' => 'انجام شده', 'notes' => 'یادداشت', 'sources' => 'منابع', 'image_url' => 'لینک عکس‌ها',
        'lat' => 'عرض جغرافیایی', 'lng' => 'طول جغرافیایی', 'loc_src' => 'منبع لوکیشن', 'prices_at' => 'تاریخ قیمت‌ها', 'prices_src' => 'منبع قیمت‌ها',
    );
}

function tfy_header_synonyms()
{
    return array(
        'name' => array('نام', 'عنوان', 'نام تفریح', 'تفریح', 'مکان', 'name', 'title'),
        'category' => array('دسته', 'دسته بندی', 'نوع', 'گروه', 'category', 'type'),
        'description' => array('توضیح', 'توضیحات', 'شرح', 'description'),
        'location' => array('لوکیشن', 'لینک', 'لینک لوکیشن', 'نقشه', 'لینک نقشه', 'لینک نشان', 'نشان', 'location', 'map', 'map link'),
        'city' => array('شهر', 'city'),
        'area' => array('محدوده', 'محله', 'منطقه', 'area', 'district'),
        'address' => array('آدرس', 'نشانی', 'address'),
        'hours' => array('ساعت کاری', 'ساعات کاری', 'ساعت کار', 'hours'),
        'phone' => array('تلفن', 'شماره تماس', 'تماس', 'phone', 'tel'),
        'website' => array('وب سایت', 'وبسایت', 'سایت', 'website', 'site', 'اینستاگرام'),
        'duration_h' => array('مدت', 'مدت زمان', 'مدت ساعت', 'زمان لازم', 'duration'),
        'times' => array('زمان', 'زمان مناسب', 'تایم', 'تایم مناسب', 'times'),
        'modes' => array('همراه', 'مناسب برای', 'حالت', 'نوع همراه', 'modes'),
        'tags' => array('ویژگی ها', 'ویژگی', 'برچسب', 'برچسب ها', 'امکانات', 'tags'),
        'cost_level' => array('سطح هزینه', 'هزینه سطح'),
        'cost_note' => array('هزینه', 'قیمت', 'توضیح هزینه', 'هزینه تقریبی', 'cost'),
        'costs' => array('هزینه های اصلی', 'هزینه اصلی', 'costs'),
        'costs_extra' => array('هزینه های فرعی', 'هزینه های فرعی و پیشنهادی', 'هزینه فرعی'),
        'menu' => array('منو', 'menu'),
        'related' => array('تفریحات تکمیلی', 'تکمیلی', 'تفریح تکمیلی', 'related'),
        'favorite' => array('علاقه مندی', 'علاقه‌مندی', 'favorite'),
        'done' => array('انجام شده', 'انجام', 'done'),
        'notes' => array('یادداشت', 'یادداشت من', 'notes'),
        'sources' => array('منابع', 'sources'),
        'image_url' => array('عکس', 'تصویر', 'لینک عکس', 'لینک عکس ها', 'لینک تصویر', 'image'),
        'lat' => array('عرض جغرافیایی', 'عرض', 'lat', 'latitude'),
        'lng' => array('طول جغرافیایی', 'طول', 'lng', 'lon', 'longitude'),
        'loc_src' => array('منبع لوکیشن'),
        'prices_at' => array('تاریخ قیمت ها', 'تاریخ قیمت', 'prices at'),
        'prices_src' => array('منبع قیمت ها', 'منبع قیمت'),
        'row' => array('ردیف', 'شماره', 'شماره ردیف', 'row', '#'),
    );
}

function tfy_guess_mapping($headers)
{
    $syn = tfy_header_synonyms();
    foreach (tfy_import_targets() as $k => $label) {
        $syn[$k][] = $label;
    }
    $lookup = array();
    foreach ($syn as $k => $list) {
        foreach ($list as $s) {
            $lookup[tfy_key_of($s)] = $k;
        }
    }
    $map = array();
    $used = array();
    foreach ($headers as $i => $h) {
        $key = tfy_key_of($h);
        $target = 'x:new';
        if ($key === '') {
            $target = 'skip';
        } elseif (isset($lookup[$key]) && !isset($used[$lookup[$key]])) {
            $target = $lookup[$key];
            $used[$target] = 1;
        } else {
            foreach (tfy_extra_fields() as $xk => $xl) {
                if (tfy_key_of($xl) === $key) {
                    $target = 'x:' . $xk;
                }
            }
        }
        $map[$i] = $target;
    }
    return $map;
}

/* ---------------------------- تبدیل مقدار سلول‌ها ---------------------------- */

function tfy_split_tokens($s)
{
    return array_values(array_filter(array_map('trim', preg_split('/[,،;\/|\n+\-–]+|\sو\s/u', (string) $s))));
}

function tfy_cell_times($s)
{
    $out = array();
    foreach (tfy_split_tokens($s) as $t) {
        $k = tfy_key_of($t);
        foreach (tfy_times_allowed() as $a) {
            if ($k === tfy_key_of($a)) {
                $out[] = $a;
            }
        }
        if (mb_strpos($k, 'آخرهفته') !== false || mb_strpos($k, 'تعطیل') !== false) {
            $out[] = 'آخر هفته';
        }
        if (mb_strpos($k, 'همهزمان') !== false || mb_strpos($k, 'هرزمان') !== false) {
            $out[] = 'هروقت';
        }
    }
    return array_values(array_unique($out));
}

function tfy_cell_tags($s)
{
    $out = array();
    foreach (tfy_split_tokens($s) as $t) {
        $k = tfy_key_of($t);
        foreach (tfy_tag_labels() as $key => $label) {
            if ($k === $key || $k === tfy_key_of($label)) {
                $out[] = $key;
            }
        }
        if (mb_strpos($k, 'کودک') !== false || mb_strpos($k, 'بچه') !== false) {
            $out[] = 'kids';
        } elseif (mb_strpos($k, 'حیوان') !== false || mb_strpos($k, 'سگ') !== false) {
            $out[] = 'pets';
        } elseif (mb_strpos($k, 'پارکینگ') !== false) {
            $out[] = 'parking';
        } elseif (mb_strpos($k, 'ویلچر') !== false || mb_strpos($k, 'معلول') !== false) {
            $out[] = 'wheelchair';
        } elseif (mb_strpos($k, 'سرپوشیده') !== false || mb_strpos($k, 'بسته') !== false || mb_strpos($k, 'داخل') !== false) {
            $out[] = 'indoor';
        } elseif (mb_strpos($k, 'بازفضای') !== false || mb_strpos($k, 'فضایباز') !== false || mb_strpos($k, 'روباز') !== false || mb_strpos($k, 'بیرون') !== false) {
            $out[] = 'outdoor';
        }
    }
    return array_values(array_unique($out));
}

function tfy_cell_modes($s)
{
    $out = array();
    foreach (tfy_split_tokens($s) as $t) {
        $k = tfy_key_of($t);
        if (in_array($k, tfy_modes_allowed(), true)) {
            $out[] = $k;
        } elseif (mb_strpos($k, 'تنها') !== false || mb_strpos($k, 'تک') !== false) {
            $out[] = 'solo';
        } elseif (mb_strpos($k, 'پارتنر') !== false || mb_strpos($k, 'دونفر') !== false || mb_strpos($k, 'زوج') !== false || mb_strpos($k, 'عاشقانه') !== false) {
            $out[] = 'partner';
        } elseif (mb_strpos($k, 'دوست') !== false) {
            $out[] = 'friends';
        } elseif (mb_strpos($k, 'خانواد') !== false || mb_strpos($k, 'کودک') !== false) {
            $out[] = 'family';
        } elseif (mb_strpos($k, 'گروه') !== false || mb_strpos($k, 'جمع') !== false) {
            $out[] = 'group';
        }
    }
    return array_values(array_unique($out));
}

function tfy_cell_duration($s)
{
    $s = tfy_digits_en((string) $s);
    if (preg_match('/نیم\s*روز/u', $s)) {
        return 6;
    }
    if (preg_match('/روز/u', $s)) {
        return 12;
    }
    if (!preg_match('/\d+(?:\.\d+)?/', $s, $m)) {
        return null;
    }
    $n = (float) $m[0];
    foreach (tfy_durations_allowed() as $d) {
        if ($n <= $d) {
            return $d;
        }
    }
    return 12;
}

function tfy_cell_cost_level($s)
{
    $s = trim(tfy_digits_en((string) $s));
    if ($s === '') {
        return '';
    }
    if (is_numeric($s) && (int) $s >= 0 && (int) $s <= 4) {
        return (int) $s;
    }
    foreach (tfy_cost_labels() as $i => $l) {
        if (mb_strpos(tfy_key_of($s), tfy_key_of($l)) !== false) {
            return $i;
        }
    }
    return '';
}

function tfy_cell_bool($s)
{
    return (bool) preg_match('/^(1|true|yes|بله|آره|✓|✔|x|انجام|انجام شد|شده)$/iu', trim(tfy_digits_en((string) $s)));
}

/**
 * یک ردیف خام اکسل + نگاشت → ردیف نرمال شبیه seed.json
 * @return array|null
 */
function tfy_row_to_data($cells, $mapping)
{
    $d = array();
    $extra = array();
    foreach ($mapping as $i => $target) {
        $v = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
        if ($v === '' || $target === 'skip') {
            continue;
        }
        if (strpos($target, 'x:') === 0) {
            $extra[substr($target, 2)] = $v;
            continue;
        }
        switch ($target) {
            case 'name':
            case 'category':
            case 'city':
            case 'area':
            case 'address':
            case 'hours':
            case 'phone':
            case 'cost_note':
                $d[$target] = $v;
                break;
            case 'description':
            case 'notes':
                $d[$target] = $v;
                break;
            case 'location':
            case 'website':
                $d[$target] = preg_match('#^https?://#i', $v) ? $v : (preg_match('#^[\w.-]+\.[a-z]{2,}(/.*)?$#i', $v) ? 'https://' . $v : '');
                break;
            case 'row':
                $d['row'] = (int) tfy_digits_en($v);
                break;
            case 'duration_h':
                $x = tfy_cell_duration($v);
                if ($x) {
                    $d['duration_h'] = $x;
                }
                break;
            case 'times':
                $x = tfy_cell_times($v);
                if ($x) {
                    $d['times'] = $x;
                }
                break;
            case 'tags':
                $x = tfy_cell_tags($v);
                if ($x) {
                    $d['tags'] = $x;
                }
                break;
            case 'modes':
                $x = tfy_cell_modes($v);
                if ($x) {
                    $d['modes'] = $x;
                }
                break;
            case 'cost_level':
                $x = tfy_cell_cost_level($v);
                if ($x !== '') {
                    $d['cost_level'] = $x;
                }
                break;
            case 'costs':
            case 'costs_extra':
                $x = tfy_parse_costlines($v);
                if ($x) {
                    $d[$target] = $x;
                }
                break;
            case 'menu':
                $x = tfy_parse_menulines($v);
                if ($x) {
                    $d['menu'] = $x;
                }
                break;
            case 'related':
                $x = tfy_clean_value('names', tfy_split_tokens($v));
                if ($x) {
                    $d['related'] = $x;
                }
                break;
            case 'favorite':
            case 'done':
                $d[$target] = tfy_cell_bool($v) ? 1 : 0;
                break;
            case 'sources':
                $d['sources'] = preg_split('/\s+/', $v);
                break;
            case 'lat':
            case 'lng':
                $x = (float) str_replace(array(',', '٫'), '.', tfy_digits_en($v));
                if ($x != 0) {
                    $d[$target] = $x;
                }
                break;
            case 'loc_src':
            case 'prices_src':
                $d[$target] = mb_substr(sanitize_text_field($v), 0, 40);
                break;
            case 'prices_at':
                $t = strtotime(tfy_digits_en($v));
                if ($t && $t > 946684800) {
                    $d['prices_at'] = (int) $t;
                }
                break;
            case 'image_url':
                $urls = array_values(array_filter(preg_split('/[\s,;|]+/', $v), function ($u) {
                    return preg_match('#^https?://#i', $u);
                }));
                if ($urls) {
                    $d['image_url'] = implode("\n", array_slice($urls, 0, 8));
                }
                break;
        }
    }
    if ($extra) {
        $d['extra'] = $extra;
    }
    return isset($d['name']) && $d['name'] !== '' ? $d : null;
}

/* ---------------------------- ادغام ---------------------------- */

function tfy_is_empty_val($v)
{
    return $v === '' || $v === null || $v === array() || $v === false;
}

/**
 * ایندکس کارت‌های موجود برای تشخیص تکراری: بر اساس نام نرمال‌شده یا لینک لوکیشن.
 */
/** لینک عکس‌های اکسل بلافاصله و همان‌طور که هست در گالری ثبت و نمایش داده می‌شود (بدون آپلود در هاست). اینستاگرام رد می‌شود. */
function tfy_add_ext_images($id, $urls)
{
    $g = get_post_meta($id, '_tfy_gallery', true);
    $g = is_array($g) ? $g : array();
    foreach (preg_split('/[\s,;|]+/', (string) $urls) as $u) {
        $u = esc_url_raw(trim($u), array('http', 'https'));
        if ($u && !tfy_bad_image_host($u) && !in_array($u, $g, true) && count($g) < 12) {
            $g[] = $u;
        }
    }
    update_post_meta($id, '_tfy_gallery', array_values($g));
}

function tfy_import_index()
{
    global $wpdb;
    $idx = array('name' => array(), 'loc' => array());
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC",
        TFY_CPT
    ));
    foreach ((array) $rows as $r) {
        $idx['name'][tfy_key_of($r->post_title)][] = (int) $r->ID;
        $loc = get_post_meta($r->ID, '_tfy_location', true);
        if ($loc) {
            $idx['loc'][rtrim(strtolower($loc), '/')] = (int) $r->ID;
        }
    }
    return $idx;
}

function tfy_import_find($d, $idx)
{
    if (!empty($d['location'])) {
        $k = rtrim(strtolower($d['location']), '/');
        if (isset($idx['loc'][$k])) {
            return $idx['loc'][$k];
        }
    }
    $k = tfy_key_of($d['name']);
    if (!empty($idx['name'][$k])) {
        $cands = $idx['name'][$k];
        if (!empty($d['city'])) {
            foreach ($cands as $id) {
                $c = get_post_meta($id, '_tfy_city', true);
                if ($c === '' || tfy_key_of($c) === tfy_key_of($d['city'])) {
                    return $id;
                }
            }
            return 0;
        }
        return $cands[0];
    }
    return 0;
}

/**
 * @param string $mode merge (فقط خالی‌ها پر شود) | update (مقدار فایل جدید جایگزین شود)
 * @return string created|merged|same
 */
function tfy_import_apply($d, &$idx, $mode, $label, $keep_row = false)
{
    $img = isset($d['image_url']) ? $d['image_url'] : '';
    unset($d['image_url']);
    $id = tfy_import_find($d, $idx);

    if (!$id) {
        if (!$keep_row) {
            unset($d['row']);
        }
        if (isset($d['modes'])) {
            $d['guess_modes'] = 0;
        } else {
            $d['guess_modes'] = 1;
        }
        $res = tfy_track(0, $label, function () use ($d) {
            return tfy_save_item(0, $d);
        });
        if (!is_int($res)) {
            return 'error';
        }
        if ($img) {
            tfy_add_ext_images($res, $img);
        }
        $idx['name'][tfy_key_of($d['name'])][] = $res;
        if (!empty($d['location'])) {
            $idx['loc'][rtrim(strtolower($d['location']), '/')] = $res;
        }
        return 'created';
    }

    $cur = tfy_get_item($id);
    if (!$cur) {
        return 'error';
    }
    $upd = array();
    unset($d['row'], $d['name']);
    foreach ($d as $k => $v) {
        if ($k === 'extra') {
            $ex = array();
            $ce = (array) $cur['extra'];
            foreach ($v as $xk => $xv) {
                if ($mode === 'update' || !isset($ce[$xk]) || $ce[$xk] === '') {
                    if (!isset($ce[$xk]) || $ce[$xk] !== $xv) {
                        $ex[$xk] = $xv;
                    }
                }
            }
            if ($ex) {
                $upd['extra'] = $ex;
            }
            continue;
        }
        if ($k === 'times') {
            $u = array_values(array_unique(array_merge($cur['times'], $v)));
            if ($u !== $cur['times']) {
                $upd['times'] = $u;
            }
            continue;
        }
        if ($k === 'tags') {
            $u = array_values(array_unique(array_merge($mode === 'update' ? array() : $cur['tags'], $v)));
            if ($u !== $cur['tags']) {
                $upd['tags'] = $u;
            }
            continue;
        }
        if ($k === 'modes') {
            if ($mode === 'update' || $cur['guess_modes']) {
                if ($v !== $cur['modes']) {
                    $upd['modes'] = $v;
                    $upd['guess_modes'] = 0;
                }
            }
            continue;
        }
        if ($k === 'related') {
            $names = wp_list_pluck($cur['related'], 'name');
            $u = array_values(array_unique(array_merge($names, $v)));
            if ($u !== $names) {
                $upd['related'] = $u;
            }
            continue;
        }
        if ($k === 'sources') {
            continue;
        }
        $cv = array_key_exists($k, $cur) ? $cur[$k] : null;
        if ($k === 'cost_level') {
            $empty = ($cv === null);
        } else {
            $empty = tfy_is_empty_val($cv);
        }
        if ($empty || ($mode === 'update' && $cv !== $v)) {
            $upd[$k] = $v;
            if (in_array($k, array('costs', 'costs_extra', 'menu'), true) && !isset($d['prices_at'])) {
                $upd['prices_src'] = 'user';
                $upd['prices_at'] = time();
            }
        }
    }
    if ($img && empty($cur['images'])) {
        tfy_add_ext_images($id, $img);
    }
    if (!$upd) {
        return 'same';
    }
    tfy_track($id, $label, function () use ($id, $upd) {
        return tfy_save_item($id, $upd);
    });
    return 'merged';
}

/* ---------------------------- خروجی ---------------------------- */

function tfy_export_table()
{
    $targets = tfy_import_targets();
    $extras = tfy_extra_fields();
    $head = array_values($targets);
    foreach ($extras as $l) {
        $head[] = $l;
    }
    $mode_labels = tfy_mode_labels();
    $costs = tfy_cost_labels();
    $rows = array($head);
    $items = tfy_all_items();
    usort($items, function ($a, $b) {
        return $a['row'] <=> $b['row'];
    });
    foreach ($items as $it) {
        $r = array();
        foreach ($targets as $k => $l) {
            switch ($k) {
                case 'times':
                    $v = implode('، ', $it['times']);
                    break;
                case 'tags':
                    $v = implode('، ', array_map(function ($t) {
                        $l = tfy_tag_labels();
                        return isset($l[$t]) ? $l[$t] : $t;
                    }, $it['tags']));
                    break;
                case 'modes':
                    $v = implode('، ', array_map(function ($m) use ($mode_labels) {
                        return isset($mode_labels[$m]) ? $mode_labels[$m] : $m;
                    }, $it['modes']));
                    break;
                case 'cost_level':
                    $v = $it['cost_level'] === null ? '' : $costs[$it['cost_level']];
                    break;
                case 'costs':
                case 'costs_extra':
                    $v = tfy_format_costlines($it[$k]);
                    break;
                case 'menu':
                    $v = tfy_format_menulines($it['menu']);
                    break;
                case 'related':
                    $v = implode('، ', wp_list_pluck($it['related'], 'name'));
                    break;
                case 'favorite':
                case 'done':
                    $v = $it[$k] ? 'بله' : '';
                    break;
                case 'sources':
                    $v = implode("\n", $it['sources']);
                    break;
                case 'image_url':
                    $v = implode("\n", wp_list_pluck($it['images'], 'full'));
                    break;
                case 'row':
                case 'duration_h':
                    $v = (int) $it[$k];
                    break;
                case 'prices_at':
                    $v = $it['prices_at'] ? gmdate('Y-m-d', (int) $it['prices_at']) : '';
                    break;
                case 'lat':
                case 'lng':
                    $v = $it[$k] === null ? '' : $it[$k];
                    break;
                default:
                    $v = isset($it[$k]) ? $it[$k] : '';
            }
            $r[] = $v;
        }
        $ex = (array) $it['extra'];
        foreach ($extras as $xk => $xl) {
            $r[] = isset($ex[$xk]) ? $ex[$xk] : '';
        }
        $rows[] = $r;
    }
    return $rows;
}

function tfy_xml_esc($s)
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function tfy_col_letter($i)
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/** @return bool */
function tfy_write_xlsx($rows, $path)
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $ncol = count($rows[0]);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    for ($i = 0; $i < $ncol; $i++) {
        $w = $i === 1 ? 28 : (in_array($i, array(3, 4, 7, 16, 17, 18, 23, 24), true) ? 40 : 18);
        $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $sheet .= '</cols><sheetData>';
    foreach ($rows as $ri => $r) {
        $sheet .= '<row r="' . ($ri + 1) . '">';
        foreach ($r as $ci => $v) {
            $ref = tfy_col_letter($ci) . ($ri + 1);
            $style = $ri === 0 ? ' s="1"' : '';
            if (is_int($v)) {
                $sheet .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            } elseif ($v === '' || $v === null) {
                continue;
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . tfy_xml_esc($v) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="tafrih" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    return $zip->close();
}
