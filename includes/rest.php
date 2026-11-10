<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API.
 * عمومی (برای هر کس که آدرس صفحه را دارد): خواندن کارت‌های تاییدشده، و ثبت پیشنهاد تفریح جدید که تا تایید مدیریت پنهان می‌ماند.
 * مدیریتی (فقط مدیر وردپرس واردشده): ویرایش، حذف، تایید/رد، تکمیل با هوش مصنوعی، افزودن مستقیم.
 */

function tfy_rate_hit($bucket, $limit, $window = HOUR_IN_SECONDS)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'x';
    $k = 'tfy_rl_' . $bucket . '_' . md5($ip);
    $n = (int) get_transient($k);
    if ($n >= $limit) {
        return false;
    }
    set_transient($k, $n + 1, $window);
    return true;
}

function tfy_is_admin_req()
{
    return current_user_can('manage_options');
}

function tfy_perm_public()
{
    return true;
}

function tfy_perm_admin()
{
    if (tfy_is_admin_req()) {
        return true;
    }
    return new WP_Error('tfy_forbidden', 'این عملیات فقط از «تنظیمات افزونه» و با حساب مدیر وردپرس ممکن است.', array('status' => 403));
}

/** توکن امضاشده‌ی یک پیشنهاد؛ فقط ثبت‌کننده می‌تواند برای همان پیشنهاد عکس آپلود کند. */
function tfy_sub_token($id)
{
    return substr(hash_hmac('sha256', 'sub' . (int) $id, wp_salt('auth')), 0, 20);
}

function tfy_perm_media(WP_REST_Request $req)
{
    if (tfy_is_admin_req()) {
        return true;
    }
    $id = (int) $req['id'];
    $post = get_post($id);
    $t = (string) $req->get_param('t');
    if ($post && $post->post_type === TFY_CPT && $post->post_status === 'pending' && $t !== '' && hash_equals(tfy_sub_token($id), $t)) {
        if (!tfy_rate_hit('media', 40)) {
            return new WP_Error('tfy_rate', 'تعداد درخواست زیاد است؛ کمی بعد امتحان کنید.', array('status' => 429));
        }
        return true;
    }
    return new WP_Error('tfy_forbidden', 'اجازه‌ی آپلود ندارید.', array('status' => 403));
}

add_action('rest_api_init', function () {
    $ns = 'tafrihyab/v1';
    register_rest_route($ns, '/items', array(
        array('methods' => 'GET', 'callback' => 'tfy_rest_list', 'permission_callback' => 'tfy_perm_public'),
        array('methods' => 'POST', 'callback' => 'tfy_rest_create', 'permission_callback' => 'tfy_perm_public'),
    ));
    register_rest_route($ns, '/items/(?P<id>\d+)', array(
        array('methods' => 'POST', 'callback' => 'tfy_rest_update', 'permission_callback' => 'tfy_perm_admin'),
        array('methods' => 'DELETE', 'callback' => 'tfy_rest_delete', 'permission_callback' => 'tfy_perm_admin'),
    ));
    register_rest_route($ns, '/items/(?P<id>\d+)/status', array(
        'methods' => 'POST', 'callback' => 'tfy_rest_status', 'permission_callback' => 'tfy_perm_admin',
    ));
    register_rest_route($ns, '/items/(?P<id>\d+)/media', array(
        'methods' => 'POST', 'callback' => 'tfy_rest_media', 'permission_callback' => 'tfy_perm_media',
    ));
    register_rest_route($ns, '/items/(?P<id>\d+)/image-host', array(
        'methods' => 'POST', 'callback' => 'tfy_rest_image_host', 'permission_callback' => 'tfy_perm_admin',
    ));
    register_rest_route($ns, '/items/(?P<id>\d+)/image-url', array(
        'methods' => 'POST', 'callback' => 'tfy_rest_image_url', 'permission_callback' => 'tfy_perm_admin',
    ));
    register_rest_route($ns, '/enrich', array(
        'methods' => 'POST', 'callback' => 'tfy_rest_enrich', 'permission_callback' => 'tfy_perm_admin',
    ));
});

add_filter('rest_post_dispatch', function ($result, $server, $request) {
    if (strpos($request->get_route(), '/tafrihyab/v1') === 0) {
        $result->header('Cache-Control', 'no-store, private');
        $result->header('X-Robots-Tag', 'noindex');
    }
    return $result;
}, 10, 3);

function tfy_check_item($id)
{
    $it = tfy_get_item((int) $id, true);
    return $it ? $it : new WP_Error('tfy_notfound', 'مورد پیدا نشد.', array('status' => 404));
}

function tfy_rest_list(WP_REST_Request $req)
{
    $admin = tfy_is_admin_req();
    $all = $admin && $req->get_param('all');
    $items = tfy_all_items($all ? array('publish', 'pending') : array('publish'));
    if (!$admin) {
        // داده‌های شخصی مدیر در صفحه عمومی نمایش داده نمی‌شود.
        foreach ($items as &$it) {
            $it['notes'] = '';
            $it['favorite'] = false;
            $it['done'] = false;
            $it['guess_modes'] = false;
        }
        unset($it);
    }
    return array(
        'items'        => $items,
        'ai'           => $admin && tfy_ai_enabled(),
        'extra_fields' => tfy_extra_schema(),
    );
}

function tfy_rest_create(WP_REST_Request $req)
{
    $data = (array) $req->get_json_params();
    $admin = tfy_is_admin_req();
    unset($data['gallery'], $data['enriched_at'], $data['sources'], $data['prices_at'], $data['prices_src'], $data['modes_ai'], $data['_status']);

    if (!$admin) {
        // پیشنهاد عمومی: فقط فیلدهای محتوایی، بدون وضعیت و یادداشت شخصی، و تا تایید مدیریت پنهان.
        if (!empty($data['hp'])) {
            return array('pending' => true);
        }
        if (!tfy_rate_hit('sub', 10)) {
            return new WP_Error('tfy_rate', 'تعداد پیشنهادهای این ساعت زیاد بود؛ بعدا امتحان کنید.', array('status' => 429));
        }
        unset($data['row'], $data['favorite'], $data['done'], $data['notes'], $data['guess_modes'], $data['image_url'], $data['hp'], $data['lat'], $data['lng'], $data['loc_src'], $data['cost_level']);
        $data['_status'] = 'pending';
        $data['guess_modes'] = 0;
    }
    if (array_key_exists('lat', $data) || array_key_exists('lng', $data)) {
        if (isset($data['lat'], $data['lng']) && tfy_coords_ok($data['lat'], $data['lng'])) {
            $data['lat'] = round((float) $data['lat'], 7);
            $data['lng'] = round((float) $data['lng'], 7);
            if (empty($data['loc_src']) || $data['loc_src'] !== 'manual') {
                $data['loc_src'] = 'manual';
            }
        } else {
            unset($data['lat'], $data['lng'], $data['loc_src']);
        }
    }
    $nl = tfy_normalize_location($data);
    if (is_wp_error($nl)) {
        return $nl;
    }
    if (!empty($data['costs']) || !empty($data['costs_extra']) || !empty($data['menu'])) {
        $data['prices_src'] = 'user';
        $data['prices_at'] = time();
    }
    $id = tfy_track(0, $admin ? 'افزودن تفریح' : 'پیشنهاد کاربر (در انتظار تایید)', function () use ($data) {
        return tfy_save_item(0, $data);
    });
    if (is_wp_error($id)) {
        return $id;
    }
    if (!$admin) {
        return array('pending' => true, 'id' => $id, 'token' => tfy_sub_token($id));
    }
    if (!empty($data['image_url']) && !tfy_bad_image_host($data['image_url'])) {
        // فقط لینک ذخیره می‌شود، نه آپلود در هاست
        tfy_add_ext_images($id, $data['image_url']);
    }
    return array('item' => tfy_get_item($id, true));
}

function tfy_rest_update(WP_REST_Request $req)
{
    $id = (int) $req['id'];
    $cur = tfy_check_item($id);
    if (is_wp_error($cur)) {
        return $cur;
    }
    $data = (array) $req->get_json_params();
    unset($data['enriched_at'], $data['sources'], $data['prices_at'], $data['prices_src'], $data['modes_ai'], $data['_status']);

    $nl = tfy_normalize_location($data);
    if (is_wp_error($nl)) {
        return $nl;
    }
    foreach (array('costs', 'costs_extra', 'menu') as $k) {
        if (isset($data[$k]) && wp_json_encode(tfy_clean_value($k === 'menu' ? 'menu' : 'costs', $data[$k])) !== wp_json_encode($cur[$k])) {
            $data['prices_src'] = 'user';
            $data['prices_at'] = time();
        }
    }
    if (isset($data['gallery'])) {
        // فقط عکس‌هایی که همین کارت دارد (شناسه‌ی هاست یا لینک خارجی) می‌توانند در گالری بمانند.
        $own = array();
        foreach ($cur['images'] as $im) {
            $own[] = !empty($im['ext']) ? $im['url'] : (int) $im['id'];
        }
        $keep = array();
        foreach ((array) $data['gallery'] as $x) {
            $v = is_string($x) && preg_match('#^https?://#i', $x) ? $x : (int) $x;
            if (in_array($v, $own, true)) {
                $keep[] = $v;
            }
        }
        $data['gallery'] = $keep;
    }
    $r = tfy_track($id, 'ویرایش', function () use ($id, $data) {
        return tfy_save_item($id, $data);
    });
    if (is_wp_error($r)) {
        return $r;
    }
    return array('item' => tfy_get_item($id, true));
}

function tfy_rest_delete(WP_REST_Request $req)
{
    $id = (int) $req['id'];
    $cur = tfy_check_item($id);
    if (is_wp_error($cur)) {
        return $cur;
    }
    tfy_trash_tracked($id, 'حذف');
    return array('deleted' => $id);
}

/** تایید (publish) یا رد (حذف) یک پیشنهاد. */
function tfy_rest_status(WP_REST_Request $req)
{
    $id = (int) $req['id'];
    $cur = tfy_check_item($id);
    if (is_wp_error($cur)) {
        return $cur;
    }
    $act = (string) $req->get_param('action');
    if ($act === 'reject') {
        tfy_trash_tracked($id, 'رد پیشنهاد');
        return array('deleted' => $id);
    }
    $before = tfy_raw_snapshot($id);
    wp_update_post(array('ID' => $id, 'post_status' => 'publish'));
    update_post_meta($id, '_tfy_row', tfy_next_row());
    tfy_name_index(true);
    tfy_hist_record('update', $id, $before, tfy_raw_snapshot($id), 'تایید پیشنهاد');
    return array('item' => tfy_get_item($id, true));
}

function tfy_rest_media(WP_REST_Request $req)
{
    $id = (int) $req['id'];
    $cur = tfy_check_item($id);
    if (is_wp_error($cur)) {
        return $cur;
    }
    $files = $req->get_file_params();
    $r = tfy_track($id, 'آپلود عکس', function () use ($id, $files) {
        return tfy_handle_upload(isset($files['file']) ? $files['file'] : null, $id);
    });
    if (is_wp_error($r)) {
        return $r;
    }
    return array('item' => tfy_is_admin_req() ? tfy_get_item($id, true) : null, 'ok' => true);
}

function tfy_rest_image_url(WP_REST_Request $req)
{
    $id = (int) $req['id'];
    $cur = tfy_check_item($id);
    if (is_wp_error($cur)) {
        return $cur;
    }
    $url = esc_url_raw((string) $req->get_param('url'), array('http', 'https'));
    // لینکی که خود مدیر می‌دهد همان‌طور که هست ذخیره می‌شود (بدون آپلود در هاست و بدون رد کردن به‌خاطر اندازه/نسبت).
    // فقط لینک نامعتبر یا شبکه‌ی اجتماعی (اینستاگرام و ...) رد می‌شود. اثر انگشت برای تشخیص تکراری فقط در صورت امکان ثبت می‌شود.
    if (!$url || tfy_bad_image_host($url)) {
        return new WP_Error('tfy_badurl', 'لینک تصویر نامعتبر است یا از شبکه‌ی اجتماعی است.', array('status' => 400));
    }
    $h = tfy_probe_image($url, array());
    $h = is_wp_error($h) ? '' : $h;
    tfy_track($id, 'افزودن عکس از لینک', function () use ($id, $url, $h) {
        $g = get_post_meta($id, '_tfy_gallery', true);
        $g = is_array($g) ? $g : array();
        $g[] = $url;
        update_post_meta($id, '_tfy_gallery', array_values(array_unique($g)));
        $e = get_post_meta($id, '_tfy_ext_hashes', true);
        $e = is_array($e) ? $e : array();
        if ($h !== '') {
            $e[$url] = $h;
        }
        update_post_meta($id, '_tfy_ext_hashes', $e);
        return $id;
    });
    return array('item' => tfy_get_item($id, true));
}

/** بارگذاری یک عکسِ لینکی در هاست (با دکمه‌ی مدیر). */
function tfy_rest_image_host(WP_REST_Request $req)
{
    $id = (int) $req['id'];
    $cur = tfy_check_item($id);
    if (is_wp_error($cur)) {
        return $cur;
    }
    $url = (string) $req->get_param('url');
    $all = (bool) $req->get_param('all');
    $err = null;
    tfy_track($id, 'بارگذاری عکس لینکی در هاست', function () use ($id, $url, $all, $cur, &$err) {
        $targets = array();
        if ($all) {
            foreach ($cur['images'] as $im) {
                if (!empty($im['ext'])) {
                    $targets[] = $im['url'];
                }
            }
        } else {
            $targets[] = $url;
        }
        foreach ($targets as $u) {
            $r = tfy_host_ext_image($id, $u);
            if (is_wp_error($r)) {
                $err = $r;
            }
        }
        return $id;
    });
    $item = tfy_get_item($id, true);
    if ($err && !$all) {
        return new WP_Error('tfy_host', 'بارگذاری در هاست ناموفق بود: ' . $err->get_error_message(), array('status' => 400));
    }
    return array('item' => $item, 'failed' => $err ? true : false);
}

function tfy_rest_enrich(WP_REST_Request $req)
{
    if (!tfy_ai_enabled()) {
        return new WP_Error('tfy_nokey', 'کلید هوش مصنوعی در تنظیمات افزونه وارد نشده است.', array('status' => 400));
    }
    $p = (array) $req->get_json_params();
    $id = isset($p['id']) ? (int) $p['id'] : 0;
    if ($id) {
        $r = tfy_enrich_item($id, array('refresh' => true, 'create_related' => true));
        if (is_wp_error($r)) {
            return $r;
        }
        return array('item' => $r['item'], 'suggestion' => $r['suggestion'], 'filled' => $r['filled']);
    }
    // فرم افزودن در تنظیمات: فقط پیشنهاد برمی‌گردد و چیزی ذخیره نمی‌شود.
    $ctx = array(
        'name'        => isset($p['name']) ? sanitize_text_field($p['name']) : '',
        'category'    => isset($p['category']) ? sanitize_text_field($p['category']) : '',
        'description' => isset($p['description']) ? sanitize_textarea_field($p['description']) : '',
        'location'    => isset($p['location']) ? esc_url_raw($p['location']) : '',
        'city'        => isset($p['city']) ? sanitize_text_field($p['city']) : '',
        'address'     => isset($p['address']) ? sanitize_text_field($p['address']) : '',
        'website'     => isset($p['website']) ? esc_url_raw($p['website']) : '',
    );
    if ($ctx['name'] === '') {
        return new WP_Error('tfy_name', 'ابتدا نام تفریح را وارد کنید.', array('status' => 400));
    }
    $s = tfy_enrich_call($ctx, tfy_enrich_all_keys());
    if (is_wp_error($s)) {
        return $s;
    }
    $urls = array();
    $site = $s['website'] !== '' ? $s['website'] : $ctx['website'];
    if ($site) {
        $og = tfy_find_og_image($site);
        if ($og) {
            $urls[] = esc_url_raw($og, array('http', 'https'));
        }
    }
    $s['image_urls'] = array_slice(array_values(array_unique(array_filter($urls))), 0, 3);
    return array('suggestion' => $s);
}
