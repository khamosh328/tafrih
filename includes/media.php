<?php
if (!defined('ABSPATH')) {
    exit;
}

function tfy_require_media()
{
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
}

function tfy_is_real_image($path)
{
    $info = @getimagesize($path);
    return $info && in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF), true);
}

/** عکس اینستاگرام/فیسبوک (CDNهای شبکه‌های اجتماعی) هرگز استفاده نمی‌شود. */
function tfy_bad_image_host($url)
{
    $h = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
    return $h !== '' && (bool) preg_match('/(instagram|cdninstagram|fbcdn|facebook|fb\.com|threads\.net)/', $h);
}

function tfy_gallery_append($post_id, $att_id, $as_cover = false)
{
    $g = get_post_meta($post_id, '_tfy_gallery', true);
    $g = is_array($g) ? $g : array();
    if ($as_cover) {
        array_unshift($g, (int) $att_id);
    } else {
        $g[] = (int) $att_id;
    }
    update_post_meta($post_id, '_tfy_gallery', array_values(array_unique($g)));
}

/* ------------------------------------------------------------------ */
/* بهینه‌سازی و تشخیص تکراری                                            */
/* ------------------------------------------------------------------ */

/**
 * کوچک کردن (حداکثر ۱۶۰۰ پیکسل)، فشرده‌سازی و تبدیل به WebP (اگر سرور پشتیبانی کند) یا JPEG.
 * GIF دست نمی‌خورد (ممکن است متحرک باشد).
 * @return array [path, ext]
 */
function tfy_optimize_image($tmp)
{
    $info = @getimagesize($tmp);
    $ext = $info ? image_type_to_extension($info[2], false) : 'jpg';
    $ext = $ext === 'jpeg' ? 'jpg' : $ext;
    if (!$info || $info[2] === IMAGETYPE_GIF) {
        return array($tmp, $ext);
    }
    $editor = wp_get_image_editor($tmp);
    if (is_wp_error($editor)) {
        return array($tmp, $ext);
    }
    $size = $editor->get_size();
    if (max($size['width'], $size['height']) > 1600) {
        $editor->resize(1600, 1600, false);
    }
    $editor->set_quality(80);
    $mime = wp_image_editor_supports(array('mime_type' => 'image/webp')) ? 'image/webp' : 'image/jpeg';
    $new_ext = $mime === 'image/webp' ? 'webp' : 'jpg';
    $out = wp_tempnam('tfy-opt') . '.' . $new_ext;
    $saved = $editor->save($out, $mime);
    if (is_wp_error($saved) || !file_exists($out) || filesize($out) < 1000) {
        @unlink($out);
        return array($tmp, $ext);
    }
    // اگر نسخه‌ی بهینه‌شده بزرگ‌تر شد و ابعاد هم تغییر نکرده بود، همان اصلی بماند.
    if (filesize($out) >= filesize($tmp) && max($size['width'], $size['height']) <= 1600) {
        @unlink($out);
        return array($tmp, $ext);
    }
    @unlink($tmp);
    return array($out, $new_ext);
}

/** هش میانگین ۶۴ بیتی (aHash) برای پیدا کردن عکس‌های تقریبا یکسان. */
function tfy_ahash($path)
{
    if (!function_exists('imagecreatefromstring')) {
        return '';
    }
    $data = @file_get_contents($path, false, null, 0, 6 * MB_IN_BYTES);
    $im = $data ? @imagecreatefromstring($data) : false;
    if (!$im) {
        return '';
    }
    $s = imagecreatetruecolor(8, 8);
    imagecopyresampled($s, $im, 0, 0, 0, 0, 8, 8, imagesx($im), imagesy($im));
    $g = array();
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $c = imagecolorat($s, $x, $y);
            $g[] = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
        }
    }
    $avg = array_sum($g) / 64;
    $bits = '';
    foreach ($g as $v) {
        $bits .= $v >= $avg ? '1' : '0';
    }
    imagedestroy($im);
    imagedestroy($s);
    $hex = '';
    foreach (str_split($bits, 4) as $n) {
        $hex .= dechex(bindec($n));
    }
    return $hex;
}

function tfy_hamming($a, $b)
{
    if (strlen($a) !== 16 || strlen($b) !== 16) {
        return 64;
    }
    $d = 0;
    for ($i = 0; $i < 16; $i++) {
        $x = hexdec($a[$i]) ^ hexdec($b[$i]);
        while ($x) {
            $d += $x & 1;
            $x >>= 1;
        }
    }
    return $d;
}

function tfy_gallery_hashes($post_id)
{
    $g = get_post_meta($post_id, '_tfy_gallery', true);
    $out = array();
    $ext = get_post_meta($post_id, '_tfy_ext_hashes', true);
    foreach ((array) $g as $aid) {
        if (is_string($aid)) {
            if (is_array($ext) && !empty($ext[$aid])) {
                $out[] = $ext[$aid];
            }
            continue;
        }
        $h = get_post_meta((int) $aid, '_tfy_ahash', true);
        if (!$h) {
            $f = get_attached_file((int) $aid);
            $h = ($f && file_exists($f)) ? tfy_ahash($f) : '';
            if ($h) {
                update_post_meta((int) $aid, '_tfy_ahash', $h);
            }
        }
        if ($h) {
            $out[] = $h;
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* دانلود و بارگذاری                                                    */
/* ------------------------------------------------------------------ */

function tfy_http_ua()
{
    return 'Mozilla/5.0 (compatible; TafrihyabBot/2.0; +' . home_url('/') . ')';
}

/**
 * دانلود امن تصویر (فقط http/https، آدرس‌های داخلی مسدود، حداکثر ۸ مگابایت)، بهینه‌سازی، بارگذاری در کتابخانه رسانه.
 * @param array $o min_side (حداقل ضلع کوچک‌تر)، skip_hashes (آرایه‌ی هش‌های موجود برای رد تکراری)
 * @return int|WP_Error شناسه پیوست
 */
function tfy_sideload_url($url, $post_id, $as_cover = false, $o = array())
{
    $url = esc_url_raw(trim((string) $url), array('http', 'https'));
    if (!$url) {
        return new WP_Error('tfy_badurl', 'لینک تصویر نامعتبر است.');
    }
    if (tfy_bad_image_host($url)) {
        return new WP_Error('tfy_insta', 'عکس اینستاگرام و شبکه‌های اجتماعی پذیرفته نمی‌شود.');
    }
    tfy_require_media();
    $tmp = wp_tempnam($url);
    if (!$tmp) {
        return new WP_Error('tfy_tmp', 'ساخت فایل موقت ناموفق بود.');
    }
    $res = wp_safe_remote_get($url, array(
        'timeout'             => 12,
        'stream'              => true,
        'filename'            => $tmp,
        'limit_response_size' => 8 * MB_IN_BYTES,
        'redirection'         => 3,
        'user-agent'          => tfy_http_ua(),
    ));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        @unlink($tmp);
        return new WP_Error('tfy_dl', 'دانلود تصویر ناموفق بود.');
    }
    if (!tfy_is_real_image($tmp)) {
        @unlink($tmp);
        return new WP_Error('tfy_notimg', 'فایل دانلودشده تصویر معتبر نیست.');
    }
    $info = getimagesize($tmp);
    if (!empty($o['min_side']) && min($info[0], $info[1]) < $o['min_side']) {
        @unlink($tmp);
        return new WP_Error('tfy_small', 'تصویر خیلی کوچک است.');
    }
    if (!empty($o['min_side']) && $info[1] > 0 && ($info[0] / $info[1] > 3 || $info[0] / $info[1] < 0.4)) {
        @unlink($tmp);
        return new WP_Error('tfy_ratio', 'نسبت تصویر مناسب نیست (بنر/لوگو).');
    }
    list($path, $ext) = tfy_optimize_image($tmp);
    $hash = tfy_ahash($path);
    if ($hash && !empty($o['skip_hashes'])) {
        foreach ($o['skip_hashes'] as $h) {
            if (tfy_hamming($hash, $h) <= 5) {
                @unlink($path);
                return new WP_Error('tfy_dup', 'تصویر تکراری است.');
            }
        }
    }
    $file = array('name' => 'tafrih-' . $post_id . '-' . wp_generate_password(6, false) . '.' . $ext, 'tmp_name' => $path);
    $att = media_handle_sideload($file, $post_id);
    if (is_wp_error($att)) {
        @unlink($path);
        return $att;
    }
    if ($hash) {
        update_post_meta($att, '_tfy_ahash', $hash);
    }
    tfy_gallery_append($post_id, $att, $as_cover);
    return (int) $att;
}

/**
 * آپلود فایل کاربر (multipart).
 * @return int|WP_Error
 */
function tfy_handle_upload($file, $post_id)
{
    if (empty($file) || !isset($file['tmp_name']) || (int) $file['error'] !== 0) {
        return new WP_Error('tfy_upload', 'آپلود ناموفق بود.', array('status' => 400));
    }
    if ($file['size'] > 12 * MB_IN_BYTES) {
        return new WP_Error('tfy_big', 'حجم تصویر بیش از ۱۲ مگابایت است.', array('status' => 400));
    }
    if (!is_uploaded_file($file['tmp_name']) || !tfy_is_real_image($file['tmp_name'])) {
        return new WP_Error('tfy_notimg', 'فقط تصویر JPG، PNG، WebP یا GIF مجاز است.', array('status' => 400));
    }
    tfy_require_media();
    list($path, $ext) = tfy_optimize_image($file['tmp_name']);
    $file['tmp_name'] = $path;
    $file['name'] = 'tafrih-' . $post_id . '-' . wp_generate_password(6, false) . '.' . $ext;
    $hash = tfy_ahash($path);
    $att = media_handle_sideload($file, $post_id);
    if (is_wp_error($att)) {
        return $att;
    }
    if ($hash) {
        update_post_meta($att, '_tfy_ahash', $hash);
    }
    tfy_gallery_append($post_id, $att, false);
    return (int) $att;
}

/* ------------------------------------------------------------------ */
/* پیدا کردن عکس از چند منبع                                            */
/* ------------------------------------------------------------------ */

function tfy_find_og_image($page_url)
{
    $page_url = esc_url_raw((string) $page_url, array('http', 'https'));
    if (!$page_url || tfy_bad_image_host($page_url)) {
        return '';
    }
    $res = wp_safe_remote_get($page_url, array('timeout' => 8, 'limit_response_size' => 400 * KB_IN_BYTES, 'redirection' => 3, 'user-agent' => tfy_http_ua()));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        return '';
    }
    $html = wp_remote_retrieve_body($res);
    if (preg_match('/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)
        || preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $img = html_entity_decode($m[1]);
        if (strpos($img, '//') === 0) {
            $img = 'https:' . $img;
        } elseif (strpos($img, '/') === 0) {
            $p = wp_parse_url($page_url);
            $img = $p['scheme'] . '://' . $p['host'] . $img;
        }
        return tfy_bad_image_host($img) ? '' : $img;
    }
    return '';
}

/** آیا عنوان نتیجه‌ی ویکی با نام مکان می‌خواند؟ (برای جلوگیری از عکس نامرتبط) */
function tfy_title_matches($name, $title)
{
    $toks = array_filter(explode(' ', tfy_norm($name)), function ($t) {
        return mb_strlen($t) >= 3;
    });
    if (!$toks) {
        return false;
    }
    $t = tfy_norm($title);
    $hit = 0;
    foreach ($toks as $tok) {
        if (mb_strpos($t, $tok) !== false) {
            $hit++;
        }
    }
    return $hit / count($toks) >= 0.6;
}

function tfy_wiki_images($name, $city)
{
    $out = array();
    $ua = array('timeout' => 8, 'user-agent' => tfy_http_ua(), 'redirection' => 2);
    // ویکی‌مدیا کامنز
    $q = trim($name . ' ' . $city);
    $r = wp_safe_remote_get('https://commons.wikimedia.org/w/api.php?' . http_build_query(array(
        'action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrsearch' => $q, 'gsrnamespace' => 6, 'gsrlimit' => 8,
        'prop' => 'imageinfo', 'iiprop' => 'url|mime', 'iiurlwidth' => 1600,
    )), $ua);
    if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) {
        $j = json_decode(wp_remote_retrieve_body($r), true);
        foreach ((array) ($j['query']['pages'] ?? array()) as $p) {
            $ii = isset($p['imageinfo'][0]) ? $p['imageinfo'][0] : null;
            if ($ii && in_array($ii['mime'], array('image/jpeg', 'image/png', 'image/webp'), true) && tfy_title_matches($name, $p['title'])) {
                $out[] = !empty($ii['thumburl']) ? $ii['thumburl'] : $ii['url'];
            }
        }
    }
    // تصویر شاخص مقاله‌ی ویکی‌پدیا فارسی
    $r = wp_safe_remote_get('https://fa.wikipedia.org/w/api.php?' . http_build_query(array(
        'action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrsearch' => $name, 'gsrlimit' => 2,
        'prop' => 'pageimages', 'piprop' => 'thumbnail', 'pithumbsize' => 1600,
    )), $ua);
    if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) {
        $j = json_decode(wp_remote_retrieve_body($r), true);
        foreach ((array) ($j['query']['pages'] ?? array()) as $p) {
            if (!empty($p['thumbnail']['source']) && tfy_title_matches($name, $p['title'])) {
                $out[] = $p['thumbnail']['source'];
            }
        }
    }
    return $out;
}

/**
 * جمع‌آوری عکس از چند منبع مختلف، حذف تکراری و کوچک، بهینه‌سازی و بارگذاری.
 * ترتیب: سایت رسمی ← لینک‌های پیشنهادی هوش مصنوعی ← تصویر صفحه‌های منبع ← ویکی‌مدیا/ویکی‌پدیا.
 * حداکثر ۲ عکس از یک دامنه تا همه از یک منبع نباشند.
 * @return array ['added' => int, 'hosts' => [..]]
 */
/**
 * بررسی یک عکس بدون ذخیره‌ی دائمی: دانلود موقت، اعتبار فایل، اندازه، نسبت و اثر انگشت برای تشخیص تکراری.
 * @return string|WP_Error اثر انگشت (aHash)
 */
function tfy_probe_image($url, $skip_hashes = array())
{
    if (tfy_bad_image_host($url)) {
        return new WP_Error('tfy_insta', 'عکس شبکه‌ی اجتماعی');
    }
    tfy_require_media();
    $tmp = wp_tempnam($url);
    if (!$tmp) {
        return new WP_Error('tfy_tmp', 'tmp');
    }
    $res = wp_safe_remote_get($url, array('timeout' => 12, 'stream' => true, 'filename' => $tmp, 'limit_response_size' => 8 * MB_IN_BYTES, 'redirection' => 3, 'user-agent' => tfy_http_ua()));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200 || !tfy_is_real_image($tmp)) {
        @unlink($tmp);
        return new WP_Error('tfy_dl', 'دانلود یا اعتبار تصویر ناموفق');
    }
    $info = getimagesize($tmp);
    if (min($info[0], $info[1]) < 400 || ($info[1] > 0 && ($info[0] / $info[1] > 3 || $info[0] / $info[1] < 0.4))) {
        @unlink($tmp);
        return new WP_Error('tfy_small', 'کوچک یا نامناسب');
    }
    $hash = tfy_ahash($tmp);
    @unlink($tmp);
    if ($hash) {
        foreach ($skip_hashes as $h) {
            if (tfy_hamming($hash, $h) <= 5) {
                return new WP_Error('tfy_dup', 'تکراری');
            }
        }
    }
    return (string) $hash;
}

/** آدرس نسبی را مطلق می‌کند. */
function tfy_abs_url($u, $page)
{
    $u = html_entity_decode(trim((string) $u));
    if ($u === '' || strpos($u, 'data:') === 0) {
        return '';
    }
    if (strpos($u, '//') === 0) {
        return 'https:' . $u;
    }
    if (preg_match('#^https?://#i', $u)) {
        return $u;
    }
    $p = wp_parse_url($page);
    if (empty($p['host'])) {
        return '';
    }
    $base = $p['scheme'] . '://' . $p['host'];
    if (strpos($u, '/') === 0) {
        return $base . $u;
    }
    $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $base . $dir . $u;
}

/**
 * عکس‌های یک صفحه، فقط اگر صفحه واقعا درباره‌ی همین مکان باشد (نام مکان در عنوان/تیتر صفحه بیاید).
 * عکس غلط بدتر از بی‌عکسی است؛ پس صفحه‌ی لیستی یا نامرتبط هیچ عکسی نمی‌دهد.
 * @return array ['relevant'=>bool, 'images'=>[url...]]
 */
function tfy_page_images($page_url, $name, $trusted = false)
{
    $page_url = esc_url_raw((string) $page_url, array('http', 'https'));
    $out = array('relevant' => false, 'images' => array());
    if (!$page_url || tfy_bad_image_host($page_url)) {
        return $out;
    }
    $res = wp_safe_remote_get($page_url, array('timeout' => 9, 'limit_response_size' => 700 * KB_IN_BYTES, 'redirection' => 4, 'user-agent' => tfy_http_ua()));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        return $out;
    }
    $html = wp_remote_retrieve_body($res);
    if (strpos($html, '<') === false) {
        return $out;
    }
    $heads = array();
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $heads[] = $m[1];
    }
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $heads[] = $m[1];
    }
    if (preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $html, $mm)) {
        foreach (array_slice($mm[1], 0, 2) as $x) {
            $heads[] = $x;
        }
    }
    $head_txt = html_entity_decode(wp_strip_all_tags(implode(' ', $heads)));
    if (!$trusted && !tfy_title_matches($name, $head_txt)) {
        return $out;
    }
    $out['relevant'] = true;
    $c = array();
    foreach (array('/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i', '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i') as $re) {
        if (preg_match($re, $html, $m)) {
            $c[] = $m[1];
        }
    }
    if (preg_match_all('/<script[^>]+application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $mm)) {
        foreach ($mm[1] as $j) {
            $d = json_decode(trim($j), true);
            $stack = is_array($d) ? array($d) : array();
            $n = 0;
            while ($stack && $n++ < 40) {
                $x = array_shift($stack);
                if (!is_array($x)) {
                    continue;
                }
                foreach ($x as $k => $v) {
                    if ($k === 'image' || $k === 'photo' || $k === 'thumbnailUrl') {
                        $list = is_array($v) ? (isset($v['url']) ? array($v) : $v) : array($v);
                        foreach ($list as $iv) {
                            if (is_string($iv)) {
                                $c[] = $iv;
                            } elseif (is_array($iv) && !empty($iv['url']) && is_string($iv['url'])) {
                                $c[] = $iv['url'];
                            }
                        }
                    } elseif (is_array($v)) {
                        $stack[] = $v;
                    }
                }
            }
        }
    }
    if (preg_match('/<link[^>]+rel=["\']image_src["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m)) {
        $c[] = $m[1];
    }
    $more = array(); // عکس‌های بدون alt: فقط از صفحه‌ی مطمئن (سایت رسمی) و با اولویت کمتر
    if (preg_match_all('/<img\b[^>]*>|<source\b[^>]*>/i', $html, $mm)) {
        foreach (array_slice($mm[0], 0, 120) as $tag) {
            $src = '';
            if (preg_match('/\ssrcset=["\']([^"\']+)["\']/i', $tag, $m) || preg_match('/\sdata-srcset=["\']([^"\']+)["\']/i', $tag, $m)) {
                $best = 0;
                foreach (explode(',', $m[1]) as $part) {
                    $bits = preg_split('/\s+/', trim($part));
                    $w = isset($bits[1]) ? (int) $bits[1] : 0;
                    if ($bits[0] !== '' && $w >= $best && strpos($bits[0], 'data:') !== 0) {
                        $best = $w;
                        $src = $bits[0];
                    }
                }
            }
            if ($src === '') {
                foreach (array('data-src', 'data-lazy-src', 'data-original', 'src') as $at) {
                    if (preg_match('/\s' . $at . '=["\']([^"\']+)["\']/i', $tag, $m) && strpos($m[1], 'data:') !== 0) {
                        $src = $m[1];
                        break;
                    }
                }
            }
            if ($src === '') {
                continue;
            }
            // کوچک‌ترهای وردپرس (-300x200.jpg) را به اصل عکس برگردان؛ اگر اصل نبود، خود پروب رد می‌کند
            $full = preg_replace('/-\d{2,4}x\d{2,4}(\.(?:jpe?g|png|webp))/i', '$1', $src);
            $alt = preg_match('/\salt=["\']([^"\']*)["\']/i', $tag, $m) ? html_entity_decode($m[1]) : '';
            if ($alt !== '' && tfy_title_matches($name, $alt)) {
                $c[] = $full;
            } elseif ($trusted && preg_match('/\.(jpe?g|png|webp)(\?|$)/i', $full)) {
                $more[] = $full;
            }
        }
    }
    foreach (array_slice($more, 0, 14) as $u) {
        $c[] = $u;
    }
    $seen = array();
    foreach ($c as $u) {
        $u = tfy_abs_url($u, $page_url);
        if ($u === '' || isset($seen[$u]) || tfy_bad_image_host($u)) {
            continue;
        }
        if (preg_match('/logo|icon|sprite|avatar|favicon|placeholder|blank|pixel|loading|\.svg|\.gif|ads?[-_\/]|banner/i', $u)) {
            continue;
        }
        $seen[$u] = 1;
        $out['images'][] = $u;
    }
    $out['images'] = array_slice($out['images'], 0, $trusted ? 16 : 8);
    return $out;
}

/** لینک صفحه‌های گالری/عکس همان سایت رسمی (حداکثر ۲). */
function tfy_gallery_links($site)
{
    $res = wp_safe_remote_get($site, array('timeout' => 8, 'limit_response_size' => 500 * KB_IN_BYTES, 'redirection' => 4, 'user-agent' => tfy_http_ua()));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        return array();
    }
    $html = (string) wp_remote_retrieve_body($res);
    $out = array();
    $base = tfy_base_domain($site);
    if (preg_match_all('/<a[^>]+href=["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/is', $html, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $a) {
            if (preg_match('/گالری|تصاویر|عکس|gallery|photos?|images/iu', wp_strip_all_tags($a[2]) . ' ' . rawurldecode($a[1]))) {
                $u = tfy_abs_url($a[1], $site);
                if ($u && tfy_base_domain($u) === $base && $u !== $site && !in_array($u, $out, true) && !preg_match('/\.(jpe?g|png|webp|pdf)$/i', $u)) {
                    $out[] = $u;
                    if (count($out) >= 2) {
                        break;
                    }
                }
            }
        }
    }
    return $out;
}

/**
 * جمع‌آوری عکس از چند منبع، بدون آپلود در هاست: فقط آدرس عکس در گالری ذخیره می‌شود.
 * منبع‌ها: سایت رسمی، صفحه‌های منبع (فقط اگر درباره‌ی همین مکان باشند)، لینک‌های پیشنهادی، ویکی‌مدیا.
 * هر عکس قبل از ثبت واقعا دانلود و بررسی می‌شود (باز شود، بزرگ باشد، تکراری نباشد، اینستاگرام نباشد).
 * @return array ['added','hosts','pages','relevant','rejected']
 */
function tfy_collect_images($post_id, $name, $city, $website, $ai_urls, $source_urls, $want)
{
    $t0 = time();
    $cands = array();
    $pages = 0;
    $relevant = 0;
    $page_list = array();
    if ($website) {
        $page_list[] = array($website, true);
    }
    foreach (array_slice((array) $source_urls, 0, 8) as $u) {
        $page_list[] = array($u, false);
    }
    foreach ($page_list as $pl) {
        if (time() - $t0 > 45) {
            break;
        }
        $pages++;
        $r = tfy_page_images($pl[0], $name, $pl[1]);
        if ($r['relevant']) {
            $relevant++;
            foreach ($r['images'] as $u) {
                $cands[] = $u;
            }
        }
    }
    if ($website && count($cands) < $want + 3) {
        foreach (tfy_gallery_links($website) as $gl) {
            if (time() - $t0 > 60) {
                break;
            }
            $pages++;
            $r = tfy_page_images($gl, $name, true);
            if ($r['relevant']) {
                $relevant++;
                foreach ($r['images'] as $u) {
                    $cands[] = $u;
                }
            }
        }
    }
    foreach ((array) $ai_urls as $u) {
        $cands[] = $u;
    }
    foreach (tfy_wiki_images($name, $city) as $u) {
        $cands[] = $u;
    }

    $hashes = tfy_gallery_hashes($post_id);
    $g = get_post_meta($post_id, '_tfy_gallery', true);
    $g = is_array($g) ? $g : array();
    $ext = get_post_meta($post_id, '_tfy_ext_hashes', true);
    $ext = is_array($ext) ? $ext : array();
    $hosts = array();
    $seen = array();
    $added = 0;
    $rejected = 0;
    $tries = 0;
    foreach ($cands as $u) {
        if ($added >= $want || $tries >= 16 || time() - $t0 > 90) {
            break;
        }
        $u = esc_url_raw((string) $u, array('http', 'https'));
        if (!$u || isset($seen[$u]) || tfy_bad_image_host($u) || in_array($u, $g, true)) {
            continue;
        }
        $seen[$u] = 1;
        $host = preg_replace('/^www\./', '', (string) wp_parse_url($u, PHP_URL_HOST));
        if (($hosts[$host] ?? 0) >= 2) {
            continue;
        }
        $tries++;
        $h = tfy_probe_image($u, $hashes);
        if (!is_wp_error($h)) {
            $g[] = $u;
            if ($h !== '') {
                $ext[$u] = $h;
                $hashes[] = $h;
            }
            $added++;
            $hosts[$host] = ($hosts[$host] ?? 0) + 1;
        } else {
            $rejected++;
        }
    }
    if ($added) {
        update_post_meta($post_id, '_tfy_gallery', array_values($g));
        update_post_meta($post_id, '_tfy_ext_hashes', $ext);
    }
    return array('added' => $added, 'hosts' => array_keys($hosts), 'pages' => $pages, 'relevant' => $relevant, 'rejected' => $rejected);
}

/** بارگذاری یک عکس لینکی در هاست خودتان و جایگزینی همان جایگاه در گالری. */
function tfy_host_ext_image($post_id, $url)
{
    $g = get_post_meta($post_id, '_tfy_gallery', true);
    $g = is_array($g) ? $g : array();
    $pos = array_search($url, $g, true);
    if ($pos === false) {
        return new WP_Error('tfy_nf', 'این عکس در گالری نیست.');
    }
    $att = tfy_sideload_url($url, $post_id, false);
    if (is_wp_error($att)) {
        return $att;
    }
    $g = get_post_meta($post_id, '_tfy_gallery', true);
    $g = array_values(array_filter($g, function ($x) use ($att) {
        return $x !== $att;
    }));
    $pos = array_search($url, $g, true);
    if ($pos !== false) {
        $g[$pos] = (int) $att;
    } else {
        $g[] = (int) $att;
    }
    update_post_meta($post_id, '_tfy_gallery', array_values($g));
    return (int) $att;
}
