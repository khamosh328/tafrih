<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * لوکیشن، فقط با نشان (neshan.org) و بر پایه‌ی مختصات دقیق؛ هرگز گوگل مپ و هرگز «جستجو در نقشه».
 * مختصات فقط از منابع قابل راستی‌آزمایی گرفته می‌شود: لینک نقشه‌ای که خودتان گذاشته‌اید، یا OpenStreetMap با تطبیق نام.
 * از هوش مصنوعی مختصات نمی‌گیریم (احتمال ساختگی بودن بالاست).
 */

/** قالب لینک نشان؛ در تنظیمات قابل تغییر است. {lat} و {lng} جایگزین می‌شوند. */
function tfy_neshan_tpl()
{
    $t = (string) get_option('tfy_neshan_tpl', '');
    return $t !== '' ? $t : 'https://neshan.org/maps/@{lat},{lng},17.00z';
}

function tfy_neshan_link($lat, $lng)
{
    return str_replace(array('{lat}', '{lng}'), array(rtrim(rtrim(sprintf('%.6f', $lat), '0'), '.'), rtrim(rtrim(sprintf('%.6f', $lng), '0'), '.')), tfy_neshan_tpl());
}

function tfy_is_neshan_url($u)
{
    $h = strtolower((string) wp_parse_url((string) $u, PHP_URL_HOST));
    return $h !== '' && (preg_match('/(^|\.)neshan\.org$/', $h) || preg_match('/(^|\.)nshn\.ir$/', $h));
}

/** مختصات داخل محدوده‌ی ایران؟ (برای ردّ داده‌ی بی‌معنی) */
function tfy_coords_ok($lat, $lng)
{
    return is_numeric($lat) && is_numeric($lng) && $lat >= 24.0 && $lat <= 40.5 && $lng >= 43.5 && $lng <= 64.0;
}

function tfy_haversine_km($a_lat, $a_lng, $b_lat, $b_lng)
{
    $r = 6371.0;
    $dl = deg2rad($b_lat - $a_lat);
    $dg = deg2rad($b_lng - $a_lng);
    $x = sin($dl / 2) ** 2 + cos(deg2rad($a_lat)) * cos(deg2rad($b_lat)) * sin($dg / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($x)));
}

/** دنبال کردن ریدایرکت لینک‌های کوتاه (فقط برای خواندن مختصات). */
function tfy_final_url($u)
{
    $res = wp_safe_remote_get($u, array('timeout' => 10, 'redirection' => 6, 'limit_response_size' => 20 * KB_IN_BYTES, 'user-agent' => tfy_http_ua()));
    if (is_wp_error($res)) {
        return $u;
    }
    $obj = isset($res['http_response']) && is_object($res['http_response']) ? $res['http_response']->get_response_object() : null;
    return ($obj && !empty($obj->url)) ? (string) $obj->url : $u;
}

/** @return array|null [lat, lng, src] */
function tfy_parse_coords($url)
{
    $url = (string) $url;
    if ($url === '') {
        return null;
    }
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    if (preg_match('/(^|\.)(goo\.gl|maps\.app\.goo\.gl|g\.co)$/', $host) || preg_match('/^nshn\.ir$/', $host)) {
        $url = tfy_final_url($url);
    }
    $u = rawurldecode($url);
    $m = array();
    if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $u, $m)) {
        $c = array((float) $m[1], (float) $m[2], 'link');
    } elseif (preg_match('/[?&](?:q|ll|query|destination|center)=(-?\d+\.\d+)[, ]\s*(-?\d+\.\d+)/', $u, $m)) {
        $c = array((float) $m[1], (float) $m[2], 'link');
    } elseif (preg_match('/[?&]lat=(-?\d+\.\d+)&(?:amp;)?(?:lng|lon)=(-?\d+\.\d+)/', $u, $m)) {
        $c = array((float) $m[1], (float) $m[2], 'link');
    } elseif (preg_match('#/(?:place|maps)/[^@]*@(-?\d+\.\d+),(-?\d+\.\d+)#', $u, $m) || (tfy_is_neshan_url($url) && preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $u, $m))) {
        $c = array((float) $m[1], (float) $m[2], 'link');
    } else {
        return null;
    }
    return tfy_coords_ok($c[0], $c[1]) ? $c : null;
}

/** OpenStreetMap؛ فقط اگر نام نتیجه با نام مکان بخواند. @return array|null [lat, lng, src] */
function tfy_geocode_osm($name, $area, $city)
{
    $q = trim($name . ' ' . $area . ' ' . $city);
    $r = wp_safe_remote_get('https://nominatim.openstreetmap.org/search?' . http_build_query(array(
        'q' => $q, 'format' => 'jsonv2', 'limit' => 5, 'namedetails' => 1, 'accept-language' => 'fa', 'countrycodes' => 'ir',
    )), array('timeout' => 12, 'user-agent' => 'TafrihyabWPPlugin/2.0 (' . home_url() . ')'));
    if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
        return null;
    }
    $j = json_decode(wp_remote_retrieve_body($r), true);
    foreach ((array) $j as $row) {
        if (empty($row['lat']) || empty($row['lon'])) {
            continue;
        }
        if (in_array($row['category'] ?? '', array('boundary', 'place', 'highway', 'landuse', 'waterway'), true)) {
            continue;
        }
        $nm = '';
        foreach ((array) ($row['namedetails'] ?? array()) as $v) {
            $nm .= ' ' . $v;
        }
        $nm .= ' ' . ($row['name'] ?? '');
        if (!tfy_title_matches($name, $nm)) {
            continue;
        }
        $lat = (float) $row['lat'];
        $lng = (float) $row['lon'];
        if (tfy_coords_ok($lat, $lng)) {
            return array($lat, $lng, 'osm');
        }
    }
    return null;
}

/**
 * تعیین لوکیشن یک کارت، بدون هوش مصنوعی و بدون توکن.
 * @return array ['status' => ok|converted|osm|none, 'changed' => bool]
 */
function tfy_resolve_location($id, $force = false)
{
    $lat = get_post_meta($id, '_tfy_lat', true);
    $lng = get_post_meta($id, '_tfy_lng', true);
    if (tfy_coords_ok($lat, $lng)) {
        return array('status' => 'ok', 'changed' => false);
    }
    $raw = (string) get_post_meta($id, '_tfy_location', true);
    $post = get_post($id);
    $c = null;
    $status = 'none';
    if ($raw !== '') {
        $c = tfy_parse_coords($raw);
        if ($c) {
            $status = 'converted';
        } elseif (tfy_is_neshan_url($raw)) {
            return array('status' => 'ok', 'changed' => false); // لینک نشانِ دستی (مثلا کوتاه) که مختصاتش خوانده نشد؛ دست نمی‌خورد.
        }
    }
    if (!$c && $post && !$force && (int) get_post_meta($id, '_tfy_loc_tried', true) > time() - 30 * DAY_IN_SECONDS) {
        return array('status' => 'none', 'changed' => false);
    }
    if (!$c && $post) {
        update_post_meta($id, '_tfy_loc_tried', time());
        $c = tfy_geocode_osm($post->post_title, (string) get_post_meta($id, '_tfy_area', true), (string) get_post_meta($id, '_tfy_city', true));
        if ($c) {
            $status = 'osm';
        }
    }
    if (!$c) {
        return array('status' => 'none', 'changed' => false);
    }
    $r = tfy_track($id, 'لوکیشن دقیق (نشان)', function () use ($id, $c) {
        return tfy_save_item($id, array('lat' => $c[0], 'lng' => $c[1], 'loc_src' => $c[2] === 'osm' ? 'osm' : 'link', 'location' => ''));
    });
    return array('status' => is_wp_error($r) ? 'none' : $status, 'changed' => !is_wp_error($r));
}

/** وب‌سایت فقط وقتی پذیرفته می‌شود که واقعا باز شود و نام مکان در صفحه باشد. @return string url معتبر یا '' */
function tfy_verify_website($url, $name, &$why = null)
{
    $url = esc_url_raw(trim((string) $url), array('http', 'https'));
    if (!$url) {
        $why = 'آدرس نامعتبر';
        return '';
    }
    $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url($url, PHP_URL_HOST)));
    if (preg_match('/(instagram|facebook|fb\.com|telegram|t\.me|twitter|x\.com|linkedin|youtube|aparat|google|goo\.gl|neshan|balad|tripadvisor|wikipedia|divar|digikala|snapp|pinterest|whatsapp|eitaa|rubika)/', $host)) {
        $why = 'شبکه اجتماعی/نقشه/تجمیع‌کننده است';
        return '';
    }
    $res = wp_safe_remote_get($url, array('timeout' => 10, 'redirection' => 4, 'limit_response_size' => 400 * KB_IN_BYTES, 'user-agent' => tfy_http_ua()));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        $why = 'صفحه باز نشد (' . (is_wp_error($res) ? 'خطای اتصال' : 'کد ' . (int) wp_remote_retrieve_response_code($res)) . ')';
        return '';
    }
    $html = (string) wp_remote_retrieve_body($res);
    $text = wp_strip_all_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html));
    $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? $m[1] : '';
    $meta = '';
    if (preg_match_all('/<meta[^>]+(?:property|name)=["\'](?:og:title|og:site_name|og:description|description|twitter:title)["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $mm)) {
        $meta = implode(' ', $mm[1]);
    }
    $ld = '';
    if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $mm)) {
        foreach ($mm[1] as $j) {
            if (preg_match_all('/"name"\s*:\s*"([^"]{2,120})"/u', $j, $nn)) {
                $ld .= ' ' . implode(' ', $nn[1]);
            }
        }
    }
    $hay = html_entity_decode($title . ' ' . $meta . ' ' . $ld . ' ' . mb_substr($text, 0, 20000));
    if (!tfy_title_matches($name, $hay)) {
        $why = 'نام مکان در صفحه پیدا نشد';
        return '';
    }
    return $url;
}

/**
 * اطلاعات ساخت‌یافته‌ی خود سایت رسمی (JSON-LD و لینک tel:)، بدون هوش مصنوعی و بدون توکن.
 * فقط اگر نام مکان در صفحه باشد برمی‌گردد. @return array phone, address, city, hours (هرکدام ممکن است خالی باشد)
 */
function tfy_site_facts($url, $name)
{
    $out = array('phone' => '', 'address' => '', 'city' => '', 'hours' => '');
    $url = esc_url_raw((string) $url, array('http', 'https'));
    if (!$url) {
        return $out;
    }
    $pages = array($url);
    for ($i = 0; $i < count($pages) && $i < 3; $i++) {
        $res = wp_safe_remote_get($pages[$i], array('timeout' => 9, 'redirection' => 4, 'limit_response_size' => 600 * KB_IN_BYTES, 'user-agent' => tfy_http_ua()));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            continue;
        }
        $html = (string) wp_remote_retrieve_body($res);
        $plain = html_entity_decode(wp_strip_all_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html)));
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? html_entity_decode($m[1]) : '';
        if (!tfy_title_matches($name, $title . ' ' . mb_substr($plain, 0, 20000))) {
            continue;
        }
        if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $mm)) {
            foreach ($mm[1] as $j) {
                $d = json_decode(trim($j), true);
                $stack = is_array($d) ? array($d) : array();
                $n = 0;
                while ($stack && $n++ < 60) {
                    $x = array_shift($stack);
                    if (!is_array($x)) {
                        continue;
                    }
                    foreach ($x as $k => $v) {
                        if ($k === 'telephone' && $out['phone'] === '') {
                            $out['phone'] = tfy_clean_phone(is_array($v) ? reset($v) : $v);
                        } elseif ($k === 'address') {
                            if (is_string($v) && $out['address'] === '') {
                                $out['address'] = mb_substr(sanitize_text_field($v), 0, 250);
                            } elseif (is_array($v)) {
                                if ($out['address'] === '' && !empty($v['streetAddress']) && is_string($v['streetAddress'])) {
                                    $out['address'] = mb_substr(sanitize_text_field($v['streetAddress']), 0, 250);
                                }
                                if ($out['city'] === '' && !empty($v['addressLocality']) && is_string($v['addressLocality'])) {
                                    $out['city'] = mb_substr(sanitize_text_field($v['addressLocality']), 0, 60);
                                }
                            }
                        } elseif ($k === 'openingHours' && $out['hours'] === '') {
                            $out['hours'] = mb_substr(sanitize_text_field(is_array($v) ? implode('، ', $v) : (string) $v), 0, 200);
                        } elseif ($k === 'openingHoursSpecification' && $out['hours'] === '' && is_array($v)) {
                            $spec = isset($v['opens']) ? array($v) : $v;
                            $sets = array();
                            foreach ($spec as $sp) {
                                if (is_array($sp) && !empty($sp['opens']) && !empty($sp['closes'])) {
                                    $sets[substr((string) $sp['opens'], 0, 5) . ' تا ' . substr((string) $sp['closes'], 0, 5)] = 1;
                                }
                            }
                            if (count($sets) === 1) {
                                $out['hours'] = (count($spec) >= 7 ? 'همه روزه ' : '') . key($sets);
                            }
                        } elseif (is_array($v)) {
                            $stack[] = $v;
                        }
                    }
                }
            }
        }
        if ($out['phone'] === '' && preg_match_all('/href=["\']tel:([^"\']+)["\']/i', $html, $mm)) {
            foreach ($mm[1] as $t) {
                $ph = tfy_clean_phone(rawurldecode($t));
                if ($ph !== '') {
                    $out['phone'] = $ph;
                    break;
                }
            }
        }
        if (($out['phone'] === '' || $out['address'] === '') && $i === 0 && preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $a) {
                if (preg_match('/تماس|contact|درباره|about/iu', strip_tags($a[2]) . ' ' . $a[1])) {
                    $u = tfy_abs_url($a[1], $pages[0]);
                    if ($u && tfy_base_domain($u) === tfy_base_domain($pages[0]) && !in_array($u, $pages, true)) {
                        $pages[] = $u;
                        break;
                    }
                }
            }
        }
        if ($out['phone'] !== '' && $out['address'] !== '') {
            break;
        }
    }
    return $out;
}

function tfy_clean_phone($v)
{
    $d = preg_replace('/[^\d+]/', '', tfy_digits_en((string) $v));
    $d = preg_replace('/^\+98|^0098/', '0', $d);
    if (preg_match('/^0\d{10}$/', $d) || preg_match('/^9\d{9}$/', $d) && ($d = '0' . $d)) {
        return $d;
    }
    return '';
}

/** لینک وارد‌شده در فرم: نشان می‌ماند؛ هر لینک دیگر فقط اگر مختصات دقیق داشته باشد به مختصات تبدیل می‌شود، وگرنه خطا. */
function tfy_normalize_location(array &$data)
{
    if (!isset($data['location'])) {
        return true;
    }
    $loc = trim((string) $data['location']);
    if ($loc === '') {
        unset($data['location']);
        return true;
    }
    $c = tfy_parse_coords($loc);
    if ($c) {
        $data['lat'] = $c[0];
        $data['lng'] = $c[1];
        $data['loc_src'] = 'link';
        $data['location'] = '';
        return true;
    }
    if (tfy_is_neshan_url($loc)) {
        return true;
    }
    return new WP_Error('tfy_loc', 'لینک نقشه باید از نشان باشد، یا لینکی که مختصات دقیق مکان را دارد. لینک جستجوی نقشه پذیرفته نمی‌شود.', array('status' => 400));
}
