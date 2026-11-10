<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * اتصال چندسرویسی به هوش مصنوعی. همه‌ی درخواست‌ها از سمت سرور سایت می‌روند و کلید به مرورگر نمی‌رسد.
 * سرویس‌ها: Gemini، OpenAI، Claude (Anthropic) و هر سرویس سازگار با OpenAI
 * (OpenRouter، DeepSeek، Groq، Mistral، xAI یا آدرس دلخواه خودتان).
 * جستجوی وب (برای قیمت و منو) فقط با Gemini و Claude کار می‌کند.
 */

function tfy_ai_providers()
{
    return array(
        'gemini'     => array('label' => 'Google Gemini', 'model' => 'gemini-3.5-flash-lite', 'base' => '', 'search' => true),
        'openai'     => array('label' => 'OpenAI (ChatGPT)', 'model' => 'gpt-4o-mini', 'base' => 'https://api.openai.com/v1', 'search' => false),
        'anthropic'  => array('label' => 'Anthropic (Claude)', 'model' => 'claude-haiku-5-5', 'base' => '', 'search' => true),
        'openrouter' => array('label' => 'OpenRouter (صدها مدل با یک کلید)', 'model' => 'google/gemini-2.5-flash', 'base' => 'https://openrouter.ai/api/v1', 'search' => false),
        'deepseek'   => array('label' => 'DeepSeek', 'model' => 'deepseek-chat', 'base' => 'https://api.deepseek.com/v1', 'search' => false),
        'groq'       => array('label' => 'Groq', 'model' => 'llama-3.3-70b-versatile', 'base' => 'https://api.groq.com/openai/v1', 'search' => false),
        'mistral'    => array('label' => 'Mistral', 'model' => 'mistral-small-latest', 'base' => 'https://api.mistral.ai/v1', 'search' => false),
        'xai'        => array('label' => 'xAI (Grok)', 'model' => 'grok-3-mini', 'base' => 'https://api.x.ai/v1', 'search' => false),
        'custom'     => array('label' => 'سرویس سازگار با OpenAI (آدرس دلخواه)', 'model' => '', 'base' => '', 'search' => false),
    );
}

function tfy_ai_provider()
{
    $p = (string) get_option('tfy_ai_provider', 'gemini');
    $all = tfy_ai_providers();
    return isset($all[$p]) ? $p : 'gemini';
}

function tfy_ai_key()
{
    $k = trim((string) get_option('tfy_ai_key', ''));
    if ($k === '' && tfy_ai_provider() === 'gemini') {
        $k = trim((string) get_option('tfy_gemini_key', '')); // سازگاری با نسخه‌ی قبلی
    }
    return $k;
}

function tfy_ai_enabled()
{
    return tfy_ai_key() !== '';
}

function tfy_ai_model()
{
    $m = trim((string) get_option('tfy_ai_model', ''));
    if ($m === '' && tfy_ai_provider() === 'gemini') {
        $m = trim((string) get_option('tfy_gemini_model', ''));
    }
    if ($m === '') {
        $all = tfy_ai_providers();
        $m = $all[tfy_ai_provider()]['model'];
    }
    return $m;
}

function tfy_ai_base()
{
    $p = tfy_ai_provider();
    $all = tfy_ai_providers();
    if ($p === 'custom') {
        return rtrim(trim((string) get_option('tfy_ai_base', '')), '/');
    }
    return $all[$p]['base'];
}

function tfy_ai_supports_search()
{
    $all = tfy_ai_providers();
    return !empty($all[tfy_ai_provider()]['search']);
}

function tfy_ai_search_on()
{
    return get_option('tfy_gemini_search', '1') === '1' && tfy_ai_supports_search();
}

function tfy_ai_label()
{
    $all = tfy_ai_providers();
    return $all[tfy_ai_provider()]['label'];
}

function tfy_extract_json($text)
{
    $text = trim((string) $text);
    $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
    $a = strpos($text, '{');
    $b = strrpos($text, '}');
    if ($a === false) {
        return null;
    }
    if ($b === false || $b <= $a) {
        return tfy_json_repair(substr($text, $a));
    }
    $raw = substr($text, $a, $b - $a + 1);
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        $d = json_decode(preg_replace('/,\s*([}\]])/', '$1', $raw), true);
    }
    if (!is_array($d)) {
        $d = tfy_json_repair(substr($text, $a));
    }
    return is_array($d) ? $d : null;
}

/** پاسخ نیمه‌کاره‌ی قطع‌شده (سقف توکن) را تا آخرین مقدار کامل نجات می‌دهد؛ مقدار ناقص دور ریخته می‌شود. */
function tfy_json_repair($raw)
{
    $n = strlen($raw);
    $stack = array();
    $in = false;
    $esc = false;
    $good = array(); // نقطه‌هایی که بعد از یک مقدار کامل‌اند: [طول، پشته]
    for ($i = 0; $i < $n; $i++) {
        $c = $raw[$i];
        if ($in) {
            if ($esc) {
                $esc = false;
            } elseif ($c === '\\') {
                $esc = true;
            } elseif ($c === '"') {
                $in = false;
            }
            continue;
        }
        if ($c === '"') {
            $in = true;
        } elseif ($c === '{' || $c === '[') {
            $stack[] = $c;
        } elseif ($c === '}' || $c === ']') {
            array_pop($stack);
            $good[] = array($i + 1, $stack);
        } elseif ($c === ',') {
            $good[] = array($i, $stack);
        }
    }
    for ($k = count($good) - 1; $k >= 0 && $k >= count($good) - 60; $k--) {
        list($len, $st) = $good[$k];
        $cand = rtrim(substr($raw, 0, $len), ", \n\r\t");
        for ($j = count($st) - 1; $j >= 0; $j--) {
            $cand .= $st[$j] === '{' ? '}' : ']';
        }
        $d = json_decode($cand, true);
        if (is_array($d)) {
            return $d;
        }
    }
    return null;
}

/** لینک‌های تغییر مسیر گراندینگ گوگل را به آدرس اصلی تبدیل می‌کند. */
function tfy_resolve_redirect($uri)
{
    if (strpos($uri, 'vertexaisearch.cloud.google.com') === false && strpos($uri, 'google.com/grounding') === false) {
        return $uri;
    }
    $r = wp_safe_remote_head($uri, array('timeout' => 6, 'redirection' => 0));
    if (is_wp_error($r)) {
        return $uri;
    }
    $loc = wp_remote_retrieve_header($r, 'location');
    return $loc ? $loc : $uri;
}

/** شمارنده‌ی مصرف روزانه (توکن و تعداد درخواست) برای مدیریت سهمیه‌ی رایگان. */
function tfy_ai_usage_add($tokens)
{
    $u = get_option('tfy_ai_usage', array());
    $today = gmdate('Y-m-d');
    if (!is_array($u) || !isset($u['d']) || $u['d'] !== $today) {
        $u = array('d' => $today, 'req' => 0, 'tok' => 0);
    }
    $u['req']++;
    $u['tok'] += (int) $tokens;
    update_option('tfy_ai_usage', $u, false);
}

function tfy_ai_usage()
{
    $u = get_option('tfy_ai_usage', array());
    if (!is_array($u) || !isset($u['d']) || $u['d'] !== gmdate('Y-m-d')) {
        return array('req' => 0, 'tok' => 0);
    }
    return array('req' => (int) $u['req'], 'tok' => (int) $u['tok']);
}

function tfy_ai_http_error($code, $msg)
{
    if ($code === 429) {
        $msg = 'سقف درخواست یا سهمیه‌ی این سرویس پر شده است. پیام اصلی سرویس: ' . mb_substr($msg, 0, 300);
    } elseif ($code === 401) {
        $msg = 'کلید نامعتبر است.';
    } elseif ($code === 403) {
        $msg = 'دسترسی رد شد (کلید غلط، نبودن دسترسی به مدل، یا محدودیت کشور سرور). ' . $msg;
    } elseif ($code === 404) {
        $msg = 'مدل یا آدرس پیدا نشد؛ نام مدل و آدرس را در تنظیمات بررسی کنید. ' . $msg;
    } elseif ($msg === '') {
        $msg = 'خطای نامشخص';
    }
    return new WP_Error('tfy_api', tfy_ai_label() . ': ' . $msg . ' (کد ' . $code . ')', array('status' => 502));
}

/** ارسال با چند بار تلاش برای 429/5xx. @return array|WP_Error [code, json] */
function tfy_ai_post($url, $headers, $payload, &$adjust = null)
{
    $code = 0;
    $json = null;
    for ($try = 0; $try < 3; $try++) {
        $res = wp_remote_post($url, array('timeout' => 120, 'headers' => $headers, 'body' => wp_json_encode($payload)));
        if (is_wp_error($res)) {
            return new WP_Error('tfy_net', 'اتصال به سرویس ناموفق بود: ' . $res->get_error_message(), array('status' => 502));
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $json = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 200 && $code < 300) {
            break;
        }
        if ($code === 429 && preg_match('/limit:\s*0\b/i', tfy_ai_err_msg($json))) {
            break; // سهمیه‌ی این قابلیت در پلن فعلی صفر است؛ تلاش مجدد بی‌فایده است.
        }
        if (in_array($code, array(429, 500, 502, 503, 529), true) && $try < 2) {
            sleep($try === 0 ? 5 : 12);
            continue;
        }
        break;
    }
    return array($code, $json);
}

function tfy_ai_err_msg($json)
{
    if (isset($json['error']['message'])) {
        return (string) $json['error']['message'];
    }
    if (isset($json['error']) && is_string($json['error'])) {
        return $json['error'];
    }
    return isset($json['message']) && is_string($json['message']) ? $json['message'] : '';
}

/* ---------------------------- Gemini ---------------------------- */

function tfy_ai_call_gemini($system, $user, $o)
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(tfy_ai_model()) . ':generateContent';
    $payload = array(
        'system_instruction' => array('parts' => array(array('text' => $system))),
        'contents'           => array(array('role' => 'user', 'parts' => array(array('text' => $user)))),
        'generationConfig'   => array('temperature' => $o['temperature'], 'maxOutputTokens' => $o['max_tokens']),
    );
    $search = $o['search'];
    if ($search) {
        $payload['tools'] = array(array('google_search' => new stdClass()));
    }
    // خاموش کردن «تفکر» داخلی مدل (توکن‌خور است). اگر مدل پشتیبانی نکرد یک‌بار یاد گرفته و دیگر نمی‌فرستیم.
    $think_off = get_option('tfy_ai_nothink', '') !== 'bad';
    if ($think_off) {
        // سری Gemini 3 به‌جای thinkingBudget از thinkingLevel استفاده می‌کند.
        $payload['generationConfig']['thinkingConfig'] = preg_match('/gemini-3/i', tfy_ai_model()) ? array('thinkingLevel' => 'minimal') : array('thinkingBudget' => 0);
    }
    $headers = array('Content-Type' => 'application/json', 'x-goog-api-key' => tfy_ai_key());
    $r = tfy_ai_post($url, $headers, $payload);
    if (is_wp_error($r)) {
        return $r;
    }
    list($code, $json) = $r;
    if ($code === 400 && $think_off) {
        // پیام 400 گوگل اغلب کلی است («invalid argument»)؛ پس بدون thinkingConfig دوباره امتحان می‌کنیم و فقط اگر جواب گرفتیم، آن را «نامعتبر» ثبت می‌کنیم.
        $p2 = $payload;
        unset($p2['generationConfig']['thinkingConfig']);
        $r2 = tfy_ai_post($url, $headers, $p2);
        if (is_wp_error($r2)) {
            return $r2;
        }
        if ($r2[0] >= 200 && $r2[0] < 300) {
            update_option('tfy_ai_nothink', 'bad', false);
            $payload = $p2;
        }
        list($code, $json) = $r2;
    }
    if (($code === 400 || $code === 429) && $search) {
        // جستجوی گوگل در برخی پلن‌های رایگان مجاز نیست یا سهمیه‌اش جدا و صفر است؛ بدون جستجو ادامه می‌دهیم (قیمت و منو در این حالت ذخیره نمی‌شود).
        unset($payload['tools']);
        $search = false;
        $r = tfy_ai_post($url, $headers, $payload);
        if (is_wp_error($r)) {
            return $r;
        }
        list($code, $json) = $r;
    }
    if ($code < 200 || $code >= 300) {
        return tfy_ai_http_error($code, tfy_ai_err_msg($json));
    }
    $text = '';
    foreach ((array) ($json['candidates'][0]['content']['parts'] ?? array()) as $p) {
        if (isset($p['text'])) {
            $text .= $p['text'];
        }
    }
    $sources = array();
    foreach ((array) ($json['candidates'][0]['groundingMetadata']['groundingChunks'] ?? array()) as $c) {
        if (!empty($c['web']['uri'])) {
            $sources[] = (string) $c['web']['uri'];
        }
    }
    return array('text' => $text, 'sources' => array_values(array_unique($sources)), 'search_used' => $search, 'tokens' => (int) ($json['usageMetadata']['totalTokenCount'] ?? 0));
}

/* ---------------------------- Claude ---------------------------- */

function tfy_ai_call_anthropic($system, $user, $o)
{
    $payload = array(
        'model'       => tfy_ai_model(),
        'max_tokens'  => $o['max_tokens'],
        'temperature' => $o['temperature'],
        'system'      => $system,
        'messages'    => array(array('role' => 'user', 'content' => $user)),
    );
    $search = $o['search'];
    if ($search) {
        $payload['tools'] = array(array('type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 2));
    }
    $headers = array('Content-Type' => 'application/json', 'x-api-key' => tfy_ai_key(), 'anthropic-version' => '2023-06-01');
    $r = tfy_ai_post('https://api.anthropic.com/v1/messages', $headers, $payload);
    if (is_wp_error($r)) {
        return $r;
    }
    list($code, $json) = $r;
    if ($code === 400 && $search && preg_match('/tool|search/i', tfy_ai_err_msg($json))) {
        unset($payload['tools']);
        $search = false;
        $r = tfy_ai_post('https://api.anthropic.com/v1/messages', $headers, $payload);
        if (is_wp_error($r)) {
            return $r;
        }
        list($code, $json) = $r;
    }
    if ($code === 400 && isset($payload['temperature']) && stripos(tfy_ai_err_msg($json), 'temperature') !== false) {
        unset($payload['temperature']);
        $r = tfy_ai_post('https://api.anthropic.com/v1/messages', $headers, $payload);
        if (is_wp_error($r)) {
            return $r;
        }
        list($code, $json) = $r;
    }
    if ($code < 200 || $code >= 300) {
        return tfy_ai_http_error($code, tfy_ai_err_msg($json));
    }
    $text = '';
    $sources = array();
    foreach ((array) ($json['content'] ?? array()) as $b) {
        if (!isset($b['type'])) {
            continue;
        }
        if ($b['type'] === 'text' && isset($b['text'])) {
            $text .= $b['text'];
        } elseif ($b['type'] === 'web_search_tool_result' && is_array($b['content'])) {
            foreach ($b['content'] as $c) {
                if (!empty($c['url'])) {
                    $sources[] = (string) $c['url'];
                }
            }
        }
    }
    return array('text' => $text, 'sources' => array_values(array_unique($sources)), 'search_used' => $search, 'tokens' => (int) (($json['usage']['input_tokens'] ?? 0) + ($json['usage']['output_tokens'] ?? 0)));
}

/* ---------------------------- سازگار با OpenAI ---------------------------- */

function tfy_ai_call_openai($system, $user, $o)
{
    $base = tfy_ai_base();
    if ($base === '' || !preg_match('#^https?://#', $base)) {
        return new WP_Error('tfy_base', 'آدرس پایه (Base URL) سرویس در تنظیمات وارد نشده است.', array('status' => 400));
    }
    $is_openai = strpos($base, 'api.openai.com') !== false;
    $payload = array(
        'model'    => tfy_ai_model(),
        'messages' => array(array('role' => 'system', 'content' => $system), array('role' => 'user', 'content' => $user)),
    );
    if ($is_openai) {
        $payload['max_completion_tokens'] = $o['max_tokens'];
    } else {
        $payload['max_tokens'] = $o['max_tokens'];
        $payload['temperature'] = $o['temperature'];
    }
    if ($is_openai && preg_match('/^(o\d|gpt-5)/i', tfy_ai_model())) {
        $payload['reasoning_effort'] = 'minimal'; // کمترین تفکر = کمترین توکن
    }
    $headers = array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . tfy_ai_key());
    if (tfy_ai_provider() === 'openrouter') {
        $headers['HTTP-Referer'] = home_url('/');
        $headers['X-Title'] = 'Tafrihyab';
    }
    $url = $base . '/chat/completions';
    for ($i = 0; $i < 3; $i++) {
        $r = tfy_ai_post($url, $headers, $payload);
        if (is_wp_error($r)) {
            return $r;
        }
        list($code, $json) = $r;
        if ($code < 200 || $code >= 300) {
            $m = tfy_ai_err_msg($json);
            // سازگارسازی خودکار با مدل‌هایی که پارامتری را نمی‌پذیرند
            if ($code === 400 && isset($payload['max_tokens']) && preg_match('/max_completion_tokens/i', $m)) {
                $payload['max_completion_tokens'] = $payload['max_tokens'];
                unset($payload['max_tokens']);
                continue;
            }
            if ($code === 400 && isset($payload['reasoning_effort']) && preg_match('/reasoning_effort|minimal/i', $m)) {
                $payload['reasoning_effort'] = preg_match('/minimal/i', $m) ? 'low' : null;
                if ($payload['reasoning_effort'] === null) {
                    unset($payload['reasoning_effort']);
                }
                continue;
            }
            if ($code === 400 && isset($payload['temperature']) && preg_match('/temperature/i', $m)) {
                unset($payload['temperature']);
                continue;
            }
            if ($code === 400 && isset($payload['max_completion_tokens']) && preg_match('/max_completion_tokens/i', $m) && preg_match('/unsupported|not supported|unknown/i', $m)) {
                $payload['max_tokens'] = $payload['max_completion_tokens'];
                unset($payload['max_completion_tokens']);
                continue;
            }
            return tfy_ai_http_error($code, $m);
        }
        $c = $json['choices'][0]['message']['content'] ?? '';
        if (is_array($c)) {
            $t = '';
            foreach ($c as $part) {
                $t .= isset($part['text']) ? $part['text'] : '';
            }
            $c = $t;
        }
        return array('text' => (string) $c, 'sources' => array(), 'search_used' => false, 'tokens' => (int) ($json['usage']['total_tokens'] ?? 0));
    }
    return new WP_Error('tfy_api', tfy_ai_label() . ': تنظیم خودکار پارامترها موفق نبود.', array('status' => 502));
}

/**
 * یک فراخوانی هوش مصنوعی با سرویس انتخاب‌شده.
 * @param array $opts search (bool)، max_tokens، temperature
 * @return array|WP_Error ['text', 'sources' => [url...], 'search_used' => bool]
 */
function tfy_ai_call($system, $user, $opts = array())
{
    if (tfy_ai_key() === '') {
        return new WP_Error('tfy_nokey', 'کلید هوش مصنوعی در تنظیمات افزونه وارد نشده است.', array('status' => 400));
    }
    @set_time_limit(170);
    $o = array(
        'search'      => (array_key_exists('search', $opts) ? (bool) $opts['search'] : tfy_ai_search_on()) && tfy_ai_supports_search(),
        'max_tokens'  => isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : 6000,
        'temperature' => isset($opts['temperature']) ? $opts['temperature'] : 0.2,
    );
    $p = tfy_ai_provider();
    if ($p === 'gemini') {
        $r = tfy_ai_call_gemini($system, $user, $o);
    } elseif ($p === 'anthropic') {
        $r = tfy_ai_call_anthropic($system, $user, $o);
    } else {
        $r = tfy_ai_call_openai($system, $user, $o);
    }
    if (is_wp_error($r)) {
        return $r;
    }
    if (!empty($r['tokens'])) {
        tfy_ai_usage_add($r['tokens']);
    } else {
        tfy_ai_usage_add(0);
    }
    if (trim($r['text']) === '') {
        return new WP_Error('tfy_empty', tfy_ai_label() . ' پاسخی نداد.', array('status' => 502));
    }
    return $r;
}

function tfy_ai_test()
{
    $r = tfy_ai_call('Reply with the single word: OK', 'ping', array('search' => false, 'max_tokens' => 200));
    if (is_wp_error($r)) {
        return $r;
    }
    return array('model' => tfy_ai_label() . ' / ' . tfy_ai_model(), 'reply' => trim($r['text']));
}
