<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * تکمیل خودکار اطلاعات با هوش مصنوعی + جستجوی وب.
 * قاعده: مقدار خالی پر می‌شود؛ مقداری که کاربر وارد کرده بازنویسی نمی‌شود. قیمت و منو فقط وقتی پر می‌شوند که
 * جستجوی وب واقعا انجام شده باشد (بدون جستجو قیمت حدسی ذخیره نمی‌شود).
 */

function tfy_max_images()
{
    $n = (int) get_option('tfy_img_target', 2);
    return max(1, min(8, $n ? $n : 2));
}

/** همه‌ی کلیدهای قابل درخواست از AI (برای فرم افزودن در تنظیمات). */
function tfy_enrich_all_keys()
{
    return array('city', 'area', 'address', 'hours', 'phone', 'website', 'costs', 'menu', 'modes', 'tags', 'description_extra', 'extra');
}

/**
 * کلیدهایی که واقعا خالی‌اند و باید از AI پرسیده شوند. هرچه کمتر بپرسیم، توکن کمتر.
 * عکس‌ها بدون AI و با منابع رایگان (سایت رسمی، لینک‌های جستجو، ویکی) جمع می‌شوند.
 */
function tfy_enrich_keys_for($it, $refresh = false)
{
    $k = array();
    foreach (array('city', 'area', 'address', 'hours', 'phone', 'website') as $f) {
        if ($it[$f] === '') {
            $k[] = $f;
        }
    }
    if (count($it['images']) < tfy_target_images() && $it['website'] === '' && !in_array('website', $k, true)) {
        $k[] = 'website'; // سایت رسمی منبع اصلی عکس است
    }
    $can_refresh = $refresh && $it['prices_src'] !== 'user';
    if ((!$it['costs'] && !$it['costs_extra']) || $can_refresh) {
        $k[] = 'costs';
    }
    if (tfy_is_food_item($it) && (!$it['menu'] || $can_refresh)) {
        $k[] = 'menu';
    }
    if ($it['guess_modes'] && !$it['modes_ai']) {
        $k[] = 'modes';
    }
    if (!$it['tags']) {
        $k[] = 'tags';
    }
    if (mb_strlen($it['description']) < 60) {
        $k[] = 'description_extra';
    }
    $ex = (array) $it['extra'];
    foreach (tfy_extra_fields() as $key => $l) {
        if (!isset($ex[$key]) || $ex[$key] === '') {
            $k[] = 'extra';
            break;
        }
    }
    return $k;
}


/** هر کارت باید این تعداد عکس داشته باشد (کمتر باشد، کامل می‌شود؛ بیشتر باشد، دست نمی‌خورد). */
function tfy_target_images()
{
    return tfy_max_images();
}

function tfy_price_min_src()
{
    return max(1, min(3, (int) get_option('tfy_price_min_src', 2)));
}

/** دامنه‌ی اصلی (example.co.ir یا example.com) برای شمارش منبع‌های مستقل. */
function tfy_base_domain($s)
{
    $s = strtolower(trim((string) $s));
    if (strpos($s, '//') !== false) {
        $s = (string) wp_parse_url($s, PHP_URL_HOST);
    }
    $s = preg_replace('#[/:?].*$#', '', $s);
    $s = preg_replace('/^www\./', '', $s);
    $p = explode('.', $s);
    if (count($p) < 2) {
        return '';
    }
    $n = count($p);
    if ($n >= 3 && in_array($p[$n - 2], array('co', 'ac', 'org', 'gov', 'net', 'com'), true) && strlen($p[$n - 1]) === 2) {
        return implode('.', array_slice($p, -3));
    }
    return implode('.', array_slice($p, -2));
}

/** تعداد منبع مستقلی که هم مدل ادعا کرده و هم واقعا در نتایج جستجو بوده. */
function tfy_count_verified_src($claimed, $hosts)
{
    $n = array();
    foreach ((array) $claimed as $c) {
        $d = tfy_base_domain(is_string($c) ? $c : '');
        if ($d !== '' && isset($hosts[$d])) {
            $n[$d] = (int) $hosts[$d]; // سایت رسمی خود مکان وزن ۲ دارد
        }
    }
    return array_sum($n);
}

/** متن صفحه برای تطبیق عدد: ارقام انگلیسی، بدون جداکننده‌ی هزارگان. */
function tfy_norm_price_text($html)
{
    $t = wp_strip_all_tags(preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', (string) $html));
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    $t = tfy_digits_en($t);
    $t = preg_replace('/(?<=\d)[,٬،.](?=\d{3}(?!\d))/u', '', $t);
    return preg_replace('/\s+/u', ' ', $t);
}

/** آیا همین قیمت (تومان؛ یا ریال = ×۱۰) واقعا در متن آمده؟ */
function tfy_price_in_text($price, $text)
{
    $price = (int) $price;
    if ($price <= 0 || $text === '') {
        return false;
    }
    $variants = array($price);
    if (mb_strpos($text, 'ریال') !== false) {
        $variants[] = $price * 10; // فقط اگر صفحه به ریال هم قیمت داده؛ وگرنه ۱۵٬۰۰۰ تومان با ۱۵۰٬۰۰۰ اشتباه می‌شود
    }
    foreach ($variants as $v) {
        if (preg_match('/(?<![\d.])' . $v . '(?!\d)/', $text)) {
            return true;
        }
    }
    if ($price % 1000 === 0 && preg_match('/(?<![\d.])' . ($price / 1000) . '\s*(?:هزار|k\b)/iu', $text)) {
        return true;
    }
    if ($price >= 1000000 && $price % 100000 === 0) {
        $m = rtrim(rtrim(number_format($price / 1000000, 1, '.', ''), '0'), '.');
        if (preg_match('/(?<![\d.])' . preg_quote($m, '/') . '\s*میلیون/u', str_replace('٫', '.', $text))) {
            return true;
        }
    }
    return false;
}

/**
 * صفحه‌های واقعی را می‌خواند تا قیمت‌ها با متن خود صفحه سنجیده شوند (نه با ادعای مدل).
 * @return array دامنه => ['url','w','text'|null,'match'=>bool]  (text=null یعنی صفحه باز نشد)
 */
function tfy_fetch_price_pages($urls, $name, $official = '')
{
    $out = array();
    $t0 = time();
    $off = tfy_base_domain($official);
    $list = array();
    if ($official !== '') {
        $list[] = $official;
    }
    foreach ((array) $urls as $u) {
        $list[] = $u;
    }
    foreach ($list as $u) {
        $d = tfy_base_domain($u);
        if ($d === '' || isset($out[$d]) || count($out) >= 6 || time() - $t0 > 30 || tfy_bad_image_host($u)) {
            continue;
        }
        $w = $d === $off ? 2 : 1;
        $res = wp_safe_remote_get($u, array('timeout' => 8, 'limit_response_size' => 900 * KB_IN_BYTES, 'redirection' => 4, 'user-agent' => tfy_http_ua()));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            $out[$d] = array('url' => $u, 'w' => $w, 'text' => null, 'match' => false);
            continue;
        }
        $html = (string) wp_remote_retrieve_body($res);
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? html_entity_decode(wp_strip_all_tags($m[1])) : '';
        $text = tfy_norm_price_text($html);
        $out[$d] = array('url' => $u, 'w' => $w, 'text' => mb_substr($text, 0, 200000), 'match' => tfy_title_matches($name, $title . ' ' . mb_substr($text, 0, 30000)));
    }
    return $out;
}

/** وزن پشتیبانی یک قیمت: صفحه‌ی بازشده فقط اگر عدد واقعا در آن باشد؛ صفحه‌ی بازنشده فقط به ادعای مدل. */
function tfy_price_support($price, $pages, $claimed)
{
    $sum = 0;
    foreach ($pages as $d => $pg) {
        if ($pg['text'] === null) {
            if (in_array($d, $claimed, true)) {
                $sum += $pg['w'];
            }
        } elseif ($pg['match'] && tfy_price_in_text($price, $pg['text'])) {
            $sum += $pg['w'];
        }
    }
    return $sum;
}

/** فقط ردیف‌های قیمتی که حداقل N منبع مستقل تاییدشده دارند می‌مانند. @return array [rows, dropped] */
function tfy_filter_priced($rows, $hosts, $min, $with_note, $pages = null)
{
    $keep = array();
    $dropped = 0;
    foreach ((array) $rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $has_price = array_key_exists('price', $r) && $r['price'] !== null && $r['price'] !== '';
        if (!$has_price) {
            $keep[] = $r;
            continue;
        }
        $src = isset($r['src']) ? (is_array($r['src']) ? $r['src'] : array($r['src'])) : array();
        $claimed = array();
        foreach ($src as $c) {
            $dd = tfy_base_domain(is_string($c) ? $c : '');
            if ($dd !== '') {
                $claimed[] = $dd;
            }
        }
        $pv = tfy_parse_price($r['price']);
        if ($pages !== null && $pv !== null && $pv > 0) {
            $ok = tfy_price_support($pv, $pages, $claimed) >= $min;
            $ds = array();
            foreach ($pages as $d => $pg) {
                if ($pg['text'] !== null && $pg['match'] && tfy_price_in_text($pv, $pg['text'])) {
                    $ds[$d] = $d;
                }
            }
        } else {
            $ok = tfy_count_verified_src($src, $hosts) >= $min;
            $ds = array();
            foreach ($claimed as $dd) {
                if (isset($hosts[$dd])) {
                    $ds[$dd] = $dd;
                }
            }
        }
        if (!$ok) {
            $dropped++;
            continue;
        }
        if ($with_note) {
            $r['note'] = trim((isset($r['note']) && is_string($r['note']) ? $r['note'] . ' | ' : '') . 'منابع: ' . implode('، ', array_slice($ds, 0, 3)), ' |');
        }
        unset($r['src']);
        $keep[] = $r;
    }
    return array($keep, $dropped);
}

function tfy_enrich_system($search)
{
    return "Fill the profile of ONE place in an Iran leisure list (mostly Tehran). Today " . gmdate('Y-m-d') . ". "
        . ($search ? "Use web search and prefer the place's OWN official website and its Google-listed business details; copy facts exactly as published, in particular phone numbers and opening hours. " : "No web search: answer only if sure, else null. ")
        . "Output ONE compact JSON object with only the requested keys, no prose/markdown. Never invent: unknown = null or []. website = the place's OWN official website only (https); never Instagram/Telegram/social/map/aggregator pages; null if unsure. hours = short, e.g. «همه روزه ۹ تا ۲۱». phone = one main number with area code. Persian text.";
}

function tfy_enrich_price_system()
{
    return "Find the exact CURRENT prices for ONE place in Iran (mostly Tehran). Today " . gmdate('Y-m-d') . ". Prices are Iranian TOMAN integers (rial/10; '150 هزار'=150000). Use web search; open the official site, ticket/booking pages (iranticket, alibaba, snapp, tapsi-style listings) and official menus. "
        . "Only report a price you actually saw written on a page that appeared in your search results (published or updated within the last 12 months); copy the number exactly. If pages disagree, report none for that item. Never estimate, never convert 'cheap/expensive' to a number. "
        . "Every priced item carries src:[domains where you saw it]. For a price range use the lower bound and say so in note. price 0 only if the page says free. Output ONE compact JSON object with only the requested keys, no prose/markdown. Persian labels.";
}

function tfy_enrich_user($ctx, $keys, $extra_labels)
{
    $spec = array(
        'city' => 'city', 'area' => 'area(neighborhood)', 'address' => 'address', 'hours' => 'hours(short)', 'phone' => 'phone', 'website' => 'website(official site only, https)',
        'costs' => 'costs[{label,price,note,src}] main costs: exact entrance/ticket (free: price 0), parking, rides; costs_extra[{label,price,note,src}] optional/suggested (≤6): e.g. car to top vs walking, cable car, sled, avg meal/person; cost_note(one short sentence); prices_year',
        'menu' => 'menu[{cat,name,price}] ≤25 representative real items; [] if no food/drink; menu_src[domains of the sites confirming these prices]; prices_year',
        'modes' => 'modes(subset of solo,partner,friends,family,group)',
        'tags' => 'tags(subset of kids,pets,parking,wheelchair,indoor,outdoor; only what you are sure of, [] if unsure)',
        'description_extra' => 'description_extra(one short practical sentence)',
    );
    $want = array();
    foreach ($keys as $k) {
        if ($k === 'extra') {
            $want[] = 'extra{' . implode(',', array_values($extra_labels)) . '}(label→short value)';
        } elseif (isset($spec[$k])) {
            $want[] = $spec[$k];
        }
    }
    $d = mb_substr((string) $ctx['description'], 0, 160);
    $line = $ctx['name'] . ' | ' . $ctx['category'] . ' | ' . $ctx['city'] . ($ctx['address'] !== '' ? ' | ' . $ctx['address'] : '') . ($ctx['website'] !== '' ? ' | ' . $ctx['website'] : '') . ($d !== '' ? ' | ' . $d : '');
    return "Item: " . $line . "\nKeys: " . implode('; ', $want);
}

/** سقف توکن خروجی متناسب با کلیدهای درخواستی. */
function tfy_enrich_max_tokens($keys)
{
    $n = 350 + 60 * count($keys);
    if (in_array('menu', $keys, true)) {
        $n += 1300;
    }
    if (in_array('costs', $keys, true)) {
        $n += 600;
    }
    return min(4000, $n) + 800;
}

/** پاک‌سازی خروجی مدل. */
function tfy_enrich_clean($data, $search_used, $grounded, $hosts = array(), $pages = null)
{
    $out = array();
    foreach (array('city', 'area', 'address', 'hours', 'phone', 'cost_note') as $k) {
        $out[$k] = isset($data[$k]) && is_string($data[$k]) ? tfy_clean_value('str', $data[$k]) : '';
    }
    $out['website'] = isset($data['website']) && is_string($data['website']) ? tfy_clean_value('url', $data['website']) : '';
    $out['description_extra'] = isset($data['description_extra']) && is_string($data['description_extra'])
        ? mb_substr(sanitize_textarea_field($data['description_extra']), 0, 500) : '';
    $out['modes'] = isset($data['modes']) ? tfy_clean_value('modes', $data['modes']) : array();
    $out['tags'] = isset($data['tags']) ? tfy_clean_value('tags', $data['tags']) : array();
    $out['image_urls'] = array();
    $out['prices_year'] = isset($data['prices_year']) && is_scalar($data['prices_year']) ? mb_substr(sanitize_text_field((string) $data['prices_year']), 0, 30) : '';
    $out['prices_dropped'] = 0;
    if ($search_used) {
        $min = tfy_price_min_src();
        list($c1, $d1) = tfy_filter_priced(isset($data['costs']) ? $data['costs'] : array(), $hosts, $min, true, $pages);
        list($c2, $d2) = tfy_filter_priced(isset($data['costs_extra']) ? $data['costs_extra'] : array(), $hosts, $min, true, $pages);
        $menu = isset($data['menu']) && is_array($data['menu']) ? $data['menu'] : array();
        $d3 = 0;
        if ($menu && $pages !== null) {
            $msrc = isset($data['menu_src']) ? (array) $data['menu_src'] : array();
            foreach ($menu as $k => $it) {
                $pv = is_array($it) && isset($it['price']) ? tfy_parse_price($it['price']) : null;
                $claimed = array_filter(array_map(function ($c) {
                    return tfy_base_domain(is_string($c) ? $c : '');
                }, $msrc));
                if ($pv === null || $pv <= 0 || tfy_price_support($pv, $pages, $claimed) < $min) {
                    unset($menu[$k]);
                    $d3++;
                }
            }
            $menu = array_values($menu);
        } elseif ($menu && tfy_count_verified_src(isset($data['menu_src']) ? (array) $data['menu_src'] : array(), $hosts) < $min) {
            $d3 = count($menu);
            $menu = array();
        }
        $out['costs'] = tfy_clean_costs($c1);
        $out['costs_extra'] = tfy_clean_costs($c2);
        $out['menu'] = tfy_clean_menu($menu);
        $out['prices_dropped'] = $d1 + $d2 + $d3;
    } else {
        $out['costs'] = $out['costs_extra'] = $out['menu'] = array();
    }
    $out['extra'] = array();
    if (isset($data['extra']) && is_array($data['extra'])) {
        $labels = tfy_extra_fields();
        foreach ($data['extra'] as $lbl => $val) {
            if (!is_scalar($val) || trim((string) $val) === '') {
                continue;
            }
            foreach ($labels as $k => $l) {
                if (tfy_key_of($l) === tfy_key_of($lbl)) {
                    $out['extra'][$k] = mb_substr(sanitize_text_field((string) $val), 0, 1000);
                }
            }
        }
    }
    // منابع: اگر جستجوی واقعی انجام شده، فقط لینک‌هایی که گوگل واقعا برگردانده؛ وگرنه آنچه مدل گفته.
    $src = $grounded ? $grounded : (isset($data['sources']) ? $data['sources'] : array());
    $out['sources'] = tfy_clean_value('urls', $src);
    return $out;
}

/**
 * دو درخواست جدا (کوچک‌تر، دقیق‌تر): پروفایل (شهر، آدرس، ساعت، تلفن، سایت، توضیح...) و قیمت‌ها (هزینه و منو).
 * اگر یکی خطا بدهد، دیگری از بین نمی‌رود. قیمت فقط وقتی ثبت می‌شود که عدد واقعا در متن صفحه‌های بازشده باشد.
 * @param array $ctx  name, category, description, location, city, address, website
 * @param array $keys کلیدهای درخواستی؛ اگر خالی باشد AI صدا زده نمی‌شود.
 * @return array|WP_Error پیشنهاد پاک‌سازی‌شده
 */
function tfy_enrich_call($ctx, $keys)
{
    if (!$keys) {
        $s = tfy_enrich_clean(array(), false, array());
        $s['search_used'] = false;
        $s['call_notes'] = array();
        return $s;
    }
    $price_keys = array_values(array_intersect($keys, array('costs', 'menu')));
    $info_keys = array_values(array_diff($keys, array('costs', 'menu')));
    $data = array();
    $notes = array();
    $sources = array();
    $grounded = array();
    $info_search = false;
    $first_err = null;

    if ($info_keys) {
        $r = tfy_ai_call(tfy_enrich_system(tfy_ai_search_on()), tfy_enrich_user($ctx, $info_keys, tfy_extra_fields()), array('max_tokens' => 500 + 90 * count($info_keys)));
        if (is_wp_error($r)) {
            $first_err = $r;
        } else {
            $d = tfy_extract_json($r['text']);
            if ($d) {
                $data = array_merge($data, $d);
            } else {
                $first_err = new WP_Error('tfy_parse', 'پاسخ هوش مصنوعی قابل خواندن نبود. دوباره امتحان کنید.', array('status' => 502));
            }
            $info_search = !empty($r['search_used']);
            if ($info_search) {
                foreach (array_slice($r['sources'], 0, 8) as $i => $u) {
                    $sources[] = tfy_resolve_redirect($u);
                }
            }
        }
    }

    $price_search = false;
    $pages = null;
    $price_err = null;
    if ($price_keys) {
        $pctx = $ctx;
        if ($pctx['website'] === '' && !empty($data['website']) && is_string($data['website'])) {
            $pctx['website'] = $data['website']; // سایتی که همین الان پیدا شد؛ برای جستجوی قیمت کمک می‌کند
        }
        $r = tfy_ai_call(tfy_enrich_price_system(), tfy_enrich_user($pctx, $price_keys, tfy_extra_fields()), array('max_tokens' => tfy_enrich_max_tokens($price_keys)));
        if (is_wp_error($r)) {
            $price_err = $r;
        } else {
            $d = tfy_extract_json($r['text']);
            if ($d) {
                $data = array_merge($data, array_intersect_key($d, array_flip(array('costs', 'costs_extra', 'cost_note', 'menu', 'menu_src', 'prices_year'))));
            } else {
                $price_err = new WP_Error('tfy_parse', 'پاسخ قیمت‌ها قابل خواندن نبود.');
            }
            $price_search = !empty($r['search_used']);
            if ($price_search) {
                $urls = array();
                foreach (array_slice($r['sources'], 0, 8) as $u) {
                    $ru = tfy_resolve_redirect($u);
                    $urls[] = $ru;
                    $sources[] = $ru;
                }
                $official = $pctx['website'] !== '' ? $pctx['website'] : '';
                $pages = tfy_fetch_price_pages($urls, $ctx['name'], $official);
            }
        }
    }
    if ($first_err && !$data && !$price_keys) {
        return $first_err;
    }
    if ($first_err && !$data && $price_err) {
        return $first_err;
    }
    foreach (array_slice(array_values(array_unique($sources)), 0, 5) as $u) {
        $grounded[] = $u;
    }
    $hosts = array();
    foreach ($sources as $u) {
        $dm = tfy_base_domain($u);
        if ($dm !== '') {
            $hosts[$dm] = 1;
        }
    }
    $off = tfy_base_domain(isset($ctx['website']) ? $ctx['website'] : '');
    if ($off !== '' && isset($hosts[$off])) {
        $hosts[$off] = 2;
    }
    $s = tfy_enrich_clean($data, $price_search, $grounded, $hosts, $pages);
    $s['search_used'] = $info_search || $price_search;
    $s['price_search'] = $price_search;
    if ($first_err) {
        $notes[] = 'بخش اطلاعات پایه ناموفق بود: ' . $first_err->get_error_message();
    }
    if ($price_err) {
        $notes[] = 'بخش قیمت‌ها ناموفق بود: ' . $price_err->get_error_message();
    }
    if ($price_keys && !$price_err && !$price_search) {
        $notes[] = 'جستجوی وب برای قیمت انجام نشد؛ قیمت ثبت نمی‌شود.';
    }
    if ($pages !== null) {
        $ok = 0;
        foreach ($pages as $pg) {
            if ($pg['text'] !== null) {
                $ok++;
            }
        }
        $notes[] = 'صفحه‌های بررسی‌شده برای قیمت: ' . count($pages) . ' (باز شد: ' . $ok . ')';
    }
    $s['call_notes'] = $notes;
    return $s;
}

function tfy_ctx_from_item($it)
{
    return array(
        'name' => $it['name'], 'category' => $it['category'], 'description' => $it['description'],
        'location' => $it['location'], 'city' => $it['city'], 'address' => $it['address'], 'website' => $it['website'],
    );
}

/**
 * اعمال پیشنهادها روی کارت ذخیره‌شده (داخل تاریخچه).
 * @param array $o refresh (جایگزینی قیمت/منوی قبلیِ هوش مصنوعی)، create_related، images (پیش‌فرض true)
 * @return array|WP_Error ['item', 'filled' => [...], 'images' => int]
 */
function tfy_enrich_apply($id, $s, $o = array())
{
    $item = tfy_get_item($id, true);
    if (!$item) {
        return new WP_Error('tfy_notfound', 'مورد پیدا نشد.', array('status' => 404));
    }
    $refresh = !empty($o['refresh']);
    $upd = array();
    $filled = array();
    $labels = array('city' => 'شهر', 'area' => 'محدوده', 'address' => 'آدرس', 'hours' => 'ساعت کاری', 'phone' => 'تلفن', 'website' => 'وب‌سایت', 'cost_note' => 'توضیح هزینه');
    $site_notes = array();
    // سایت رسمی: ثبت‌شده‌ی قبلی، یا پیشنهاد جدید که باز شود و نام مکان در آن باشد
    $site = $item['website'];
    if ($site === '' && !empty($s['website'])) {
        $why = '';
        $w = tfy_verify_website($s['website'], $item['name'], $why);
        if ($w === '') {
            $site_notes[] = 'وب‌سایت پیشنهادی (' . tfy_base_domain($s['website']) . ') ثبت نشد: ' . $why;
        } else {
            $site = $w;
            $upd['website'] = $w;
            $filled[] = 'وب‌سایت';
        }
    }
    // اطلاعات ساخت‌یافته‌ی خود سایت رسمی (دقیق‌تر از هوش مصنوعی): تلفن، آدرس، ساعت، شهر
    if ($site !== '' && ($item['phone'] === '' || $item['address'] === '' || $item['hours'] === '' || $item['city'] === '')) {
        $f = tfy_site_facts($site, $item['name']);
        foreach (array('phone' => 'تلفن', 'address' => 'آدرس', 'hours' => 'ساعت کاری', 'city' => 'شهر') as $k => $l) {
            if ($item[$k] === '' && $f[$k] !== '') {
                $upd[$k] = $f[$k];
                $s[$k] = '';
                $filled[] = $l . ' (از سایت رسمی)';
            }
        }
    }
    foreach ($labels as $k => $l) {
        if ($k === 'website') {
            continue;
        }
        if ($item[$k] === '' && !empty($s[$k]) && !isset($upd[$k])) {
            $upd[$k] = $s[$k];
            $filled[] = $l;
        }
    }
    $touch = false;
    foreach (array('costs' => 'هزینه‌های اصلی', 'costs_extra' => 'هزینه‌های فرعی', 'menu' => 'منو') as $k => $l) {
        if (!empty($s[$k]) && (empty($item[$k]) || ($refresh && $item['prices_src'] !== 'user'))) {
            $upd[$k] = $s[$k];
            $filled[] = $l;
            $touch = true;
        }
    }
    if ($touch) {
        $upd['prices_at'] = time();
        $upd['prices_src'] = 'ai';
    }

    if (!empty($s['modes']) && $item['guess_modes'] && !$item['modes_ai']) {
        $upd['modes'] = $s['modes'];
        $upd['modes_ai'] = 1;
        $filled[] = 'همراه';
    }
    if (!empty($s['tags']) && !$item['tags']) {
        $upd['tags'] = $s['tags'];
        $filled[] = 'ویژگی‌ها';
    }
    if (!empty($s['extra'])) {
        $cur = (array) $item['extra'];
        $ex = array();
        foreach ($s['extra'] as $k => $v) {
            if (!isset($cur[$k]) || $cur[$k] === '') {
                $ex[$k] = $v;
            }
        }
        if ($ex) {
            $upd['extra'] = $ex;
            $filled[] = 'ستون‌های دلخواه';
        }
    }
    if (!empty($s['description_extra']) && mb_strlen($item['description']) < 120
        && mb_strpos($item['description'], $s['description_extra']) === false) {
        $upd['description'] = trim($item['description'] . "\n\n" . $s['description_extra']);
        $filled[] = 'توضیح';
    }
    $upd['sources'] = array_slice(array_values(array_unique(array_merge($s['sources'], $item['sources']))), 0, 12);
    $upd['enriched_at'] = time();

    $r = tfy_save_item($id, $upd);
    if (is_wp_error($r)) {
        return $r;
    }

    // لوکیشن دقیق (نشان): بدون هوش مصنوعی و بدون توکن
    $loc = tfy_resolve_location($id);
    if ($loc['changed']) {
        $filled[] = $loc['status'] === 'osm' ? 'لوکیشن (از OpenStreetMap؛ دقت را چک کنید)' : 'لوکیشن (نشان)';
    }

    // تفریحات تکمیلی: فقط از لیست خودتان و هم‌محدوده
    if (!tfy_get_item($id, true)['related'] && (!isset($o['related']) || $o['related']) && (int) get_post_meta($id, '_tfy_rel_tried', true) < time() - 30 * DAY_IN_SECONDS) {
        $rr = tfy_enrich_related($id, !empty($o['create_related']));
        if (is_array($rr) && $rr['n']) {
            $filled[] = 'تفریحات تکمیلی (' . $rr['n'] . ')' . ($rr['new'] ? ' و ' . $rr['new'] . ' کارت پیشنهادی جدید' : '');
        }
    }

    $img_added = 0;
    $img_note = '';
    if (!isset($o['images']) || $o['images']) {
        $need_img = tfy_target_images() - count($item['images']);
        if ($need_img > 0) {
            $fresh = tfy_get_item($id, true);
            $res = tfy_collect_images($id, $fresh['name'], $fresh['city'], $fresh['website'], $s['image_urls'], $s['sources'], $need_img);
            $img_added = $res['added'];
            if ($img_added < $need_img && tfy_ai_enabled() && tfy_ai_search_on()) {
                $f = tfy_find_pages($fresh);
                if ($f['pages']) {
                    $r2 = tfy_collect_images($id, $fresh['name'], $fresh['city'], '', array(), $f['pages'], $need_img - $img_added);
                    $img_added += $r2['added'];
                    foreach (array('pages', 'relevant', 'rejected') as $k) {
                        $res[$k] += $r2[$k];
                    }
                }
            }
            update_post_meta($id, '_tfy_img_tried', time());
            if ($img_added) {
                $filled[] = 'عکس (' . $img_added . ')';
            }
            if ($img_added < $need_img) {
                $img_note = 'عکس مطمئن کامل پیدا نشد (' . $res['pages'] . ' صفحه بررسی، ' . $res['relevant'] . ' مرتبط، ' . $res['rejected'] . ' رد)';
            }
        }
    }
    $notes = array();
    if ($img_note !== '') {
        $notes[] = $img_note;
    }
    if (!empty($s['prices_dropped'])) {
        $notes[] = $s['prices_dropped'] . ' قیمت ثبت نشد: عدد آن در متن صفحه‌های بررسی‌شده با حداقل ' . tfy_price_min_src() . ' منبع (سایت رسمی ۲ حساب می‌شود) دیده نشد';
    }
    foreach ((array) ($s['call_notes'] ?? array()) as $cn) {
        $notes[] = $cn;
    }
    foreach ($site_notes as $sn) {
        $notes[] = $sn;
    }
    return array('item' => tfy_get_item($id, true), 'filled' => $filled, 'images' => $img_added, 'notes' => $notes);
}

/** تکمیل کامل یک کارت (فراخوانی + اعمال + تاریخچه). */
function tfy_enrich_item($id, $o = array())
{
    $it = tfy_get_item($id, true);
    if (!$it) {
        return new WP_Error('tfy_notfound', 'مورد پیدا نشد.', array('status' => 404));
    }
    $s = tfy_enrich_call(tfy_ctx_from_item($it), tfy_enrich_keys_for($it, !empty($o['refresh'])));
    if (is_wp_error($s)) {
        return $s;
    }
    $out = null;
    $res = tfy_track($id, 'تکمیل خودکار با هوش مصنوعی', function () use ($id, $s, $o, &$out) {
        $out = tfy_enrich_apply($id, $s, $o);
        return $out;
    });
    if (is_wp_error($res)) {
        return $res;
    }
    $out['suggestion'] = $s;
    return $out;
}

/* ------------------------------------------------------------------ */
/* تفریحات تکمیلی: فقط از لیست خودتان، هم‌محدوده                        */
/* ------------------------------------------------------------------ */

/**
 * کاندیداها با قاعده‌ی قطعی (نه حدس هوش مصنوعی) انتخاب می‌شوند:
 * اگر هر دو مختصات دارند: فاصله حداکثر ۳ کیلومتر؛ وگرنه شهر و محله‌ی یکسان. اگر محله/مختصات معلوم نباشد، کاندیدایی نیست.
 * @return array [ ['id','name'], ... ] نزدیک‌ترین‌ها اول، حداکثر ۴۰
 */
function tfy_related_candidates($it)
{
    $city = tfy_key_of($it['city']);
    $area = tfy_key_of($it['area']);
    $has_c = isset($it['lat']) && $it['lat'] !== null;
    if ($city === '' || (!$has_c && $area === '')) {
        return array();
    }
    $out = array();
    foreach (tfy_all_items() as $c) {
        if ($c['id'] === $it['id'] || tfy_key_of($c['city']) !== $city) {
            continue;
        }
        if ($has_c && $c['lat'] !== null) {
            $d = tfy_haversine_km($it['lat'], $it['lng'], $c['lat'], $c['lng']);
            if ($d <= 3.0) {
                $out[] = array('id' => $c['id'], 'name' => $c['name'], 'd' => $d);
            }
        } elseif ($area !== '' && tfy_key_of($c['area']) === $area) {
            $out[] = array('id' => $c['id'], 'name' => $c['name'], 'd' => 1.5);
        }
    }
    usort($out, function ($a, $b) {
        return $a['d'] <=> $b['d'];
    });
    return array_slice($out, 0, 40);
}

function tfy_title_exists($name)
{
    global $wpdb;
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','pending')",
        TFY_CPT
    ));
    $k = tfy_key_of($name);
    foreach ((array) $rows as $t) {
        if (tfy_key_of($t) === $k) {
            return true;
        }
    }
    return false;
}

/**
 * یک فراخوانی کوچک: AI فقط از بین «نام‌های کاندیدا» انتخاب می‌کند؛ نامی خارج از لیست دور ریخته می‌شود.
 * پیشنهادِ جدید (اختیاری، با جستجو) به‌صورت کارت «در انتظار تایید» ساخته می‌شود، چون صحت مکانش را فقط خودتان می‌توانید تایید کنید.
 * @return array|WP_Error ['n' => تعداد انتخاب‌شده، 'new' => کارت‌های پیشنهادی ساخته‌شده]
 */
function tfy_enrich_related($id, $find_new = false)
{
    $it = tfy_get_item($id, true);
    if (!$it) {
        return array('n' => 0, 'new' => 0);
    }
    update_post_meta($id, '_tfy_rel_tried', time());
    $cands = tfy_related_candidates($it);
    if (!$cands && !($find_new && $it['city'] !== '' && $it['area'] !== '')) {
        return array('n' => 0, 'new' => 0);
    }
    $names = wp_list_pluck($cands, 'name');
    $sys = "Pick related leisure activities for an Iran leisure list. Output ONE compact JSON object, no prose. Never invent.";
    $user = 'Place: ' . $it['name'] . ' | ' . $it['category'] . ' | ' . $it['city'] . ' | ' . $it['area'] . ($it['address'] !== '' ? ' | ' . $it['address'] : '')
        . "\nCANDIDATES (copy names EXACTLY): " . ($names ? implode(' ; ', $names) : '-')
        . "\nReturn {\"related\":[≤4 names from CANDIDATES that are at the same venue or within ~10 minutes and make a sensible combined outing; [] if none]"
        . ($find_new ? ",\"related_new\":[{\"name\",\"category\"} ≤2 REAL places (verified by search) at the same venue or within ~10 minutes that are NOT in CANDIDATES]" : '') . '}';
    $r = tfy_ai_call($sys, $user, array('search' => $find_new && tfy_ai_search_on(), 'max_tokens' => 700));
    if (is_wp_error($r)) {
        return $r;
    }
    $data = tfy_extract_json($r['text']);
    if (!is_array($data)) {
        return array('n' => 0, 'new' => 0);
    }
    $valid = array();
    foreach ($names as $n) {
        $valid[tfy_key_of($n)] = $n;
    }
    $picked = array();
    foreach ((array) ($data['related'] ?? array()) as $n) {
        $k = tfy_key_of((string) $n);
        if (isset($valid[$k]) && !in_array($valid[$k], $picked, true)) {
            $picked[] = $valid[$k];
        }
    }
    $made = 0;
    if ($find_new && !empty($r['search_used'])) {
        foreach (array_slice((array) ($data['related_new'] ?? array()), 0, 2) as $x) {
            $n = is_array($x) && isset($x['name']) ? trim(sanitize_text_field((string) $x['name'])) : '';
            if ($n === '' || tfy_title_exists($n)) {
                continue;
            }
            $nid = tfy_save_item(0, array(
                '_status' => 'pending', 'name' => $n, 'category' => isset($x['category']) ? sanitize_text_field((string) $x['category']) : $it['category'],
                'city' => $it['city'], 'area' => $it['area'], 'guess_modes' => 1, 'row' => 0,
                'description' => 'پیشنهاد هوش مصنوعی به‌عنوان تفریح تکمیلی «' . $it['name'] . '»؛ قبل از تایید، وجود و محل آن را بررسی کنید.',
            ));
            if (is_int($nid)) {
                tfy_hist_record('create', $nid, null, tfy_raw_snapshot($nid), 'کارت پیشنهادی برای «' . $it['name'] . '»');
                $picked[] = $n;
                $made++;
            }
        }
    }
    if ($picked) {
        tfy_save_item($id, array('related' => $picked));
    }
    return array('n' => count($picked), 'new' => $made);
}


/**
 * پیدا کردن صفحه‌های وب که دقیقا درباره‌ی همین مکان‌اند (سایت رسمی، صفحه‌ی معرفی، رزرو/بلیت، معرفی در سایت‌های معتبر).
 * یک جستجوی سبک؛ فقط لینک‌هایی که جستجو واقعا برگردانده نگه داشته می‌شود.
 * @return array ['website'=>string, 'pages'=>[urls]]
 */
function tfy_find_pages($it)
{
    $out = array('website' => '', 'pages' => array());
    if (!tfy_ai_enabled() || !tfy_ai_search_on()) {
        return $out;
    }
    $sys = 'Find web pages that are specifically about ONE place (Iran). Output ONE compact JSON: {"website":official site https or null,"pages":[up to 6 URLs of pages dedicated to this exact place: its official site/page, a booking/ticket/menu/listing page for it, a reputable article about it]}. '
        . 'No social media (instagram/telegram/facebook), no map pages, no search/list pages of many places. Never invent URLs; only URLs you saw in search results.';
    $line = $it['name'] . ' | ' . $it['category'] . ' | ' . $it['city'] . ($it['address'] !== '' ? ' | ' . $it['address'] : '');
    $r = tfy_ai_call($sys, $line, array('search' => true, 'max_tokens' => 700));
    if (is_wp_error($r) || empty($r['search_used'])) {
        return $out;
    }
    $data = tfy_extract_json($r['text']);
    $urls = array();
    if (is_array($data)) {
        if (!empty($data['website']) && is_string($data['website'])) {
            $out['website'] = $data['website'];
        }
        foreach ((array) ($data['pages'] ?? array()) as $u) {
            if (is_string($u)) {
                $urls[] = $u;
            }
        }
    }
    $hosts = array();
    $resolved = array();
    foreach (array_slice((array) $r['sources'], 0, 8) as $u) {
        $ru = tfy_resolve_redirect($u);
        $resolved[] = $ru;
        $d = tfy_base_domain($ru);
        if ($d !== '') {
            $hosts[$d] = 1;
        }
    }
    foreach ($urls as $u) {
        if (isset($hosts[tfy_base_domain($u)])) {
            $out['pages'][] = $u; // فقط لینکی که دامنه‌اش واقعا در نتایج جستجو بوده
        }
    }
    foreach ($resolved as $u) {
        $out['pages'][] = $u; // ارتباط با مکان در مرحله‌ی بعد (عنوان صفحه) بررسی می‌شود
    }
    $out['pages'] = array_slice(array_values(array_unique($out['pages'])), 0, 10);
    return $out;
}

/**
 * فقط تکمیل عکس تا حد تعیین‌شده؛ بدون دست زدن به بقیه‌ی فیلدها.
 * اول سایت رسمی و ویکی (رایگان)؛ اگر کم بود و اجازه‌ی هوش مصنوعی داده شده، جستجو برای پیدا کردن صفحه‌های مرتبط.
 * هر صفحه فقط وقتی عکس می‌دهد که نام مکان در عنوانش باشد.
 * @return array|WP_Error ['added','total','note']
 */
function tfy_complete_images($id, $use_ai)
{
    $it = tfy_get_item($id, true);
    if (!$it) {
        return new WP_Error('tfy_notfound', 'مورد پیدا نشد.');
    }
    $need = tfy_target_images() - count($it['images']);
    if ($need <= 0) {
        return array('added' => 0, 'total' => count($it['images']), 'note' => '');
    }
    $added = 0;
    $stat = array('pages' => 0, 'relevant' => 0, 'rejected' => 0);
    $res = tfy_track($id, 'تکمیل عکس', function () use ($id, $it, $need, $use_ai, &$added, &$stat) {
        $r = tfy_collect_images($id, $it['name'], $it['city'], $it['website'], array(), array(), $need);
        $added = $r['added'];
        foreach ($stat as $k => $v) {
            $stat[$k] += $r[$k];
        }
        if ($added < $need && $use_ai) {
            $f = tfy_find_pages($it);
            $site = $it['website'];
            if ($site === '' && $f['website'] !== '') {
                $site = tfy_verify_website($f['website'], $it['name']);
                if ($site !== '') {
                    tfy_save_item($id, array('website' => $site));
                }
            }
            $pages = $f['pages'];
            if ($site !== '' && $site !== $it['website']) {
                array_unshift($pages, $site);
            }
            if ($pages) {
                $r2 = tfy_collect_images($id, $it['name'], $it['city'], '', array(), $pages, $need - $added);
                $added += $r2['added'];
                foreach ($stat as $k => $v) {
                    $stat[$k] += $r2[$k];
                }
            }
        }
        return true;
    });
    update_post_meta($id, '_tfy_img_tried', time());
    if (is_wp_error($res)) {
        return $res;
    }
    $fresh = tfy_get_item($id, true);
    $note = $stat['pages'] . ' صفحه بررسی شد، ' . $stat['relevant'] . ' صفحه مرتبط با نام مکان بود، ' . $stat['rejected'] . ' عکس رد شد (کوچک، تکراری یا بازنشدنی)';
    return array('added' => $added, 'total' => count($fresh['images']), 'note' => $note);
}
