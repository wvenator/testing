<?php
/**
 * Custom Redir v2 — панель UTM-редиректов через .htaccess (WordPress).
 * Совместимо с PHP 7.4 – 8.4.
 *
 * Что умеет:
 *  • редирект «страница + UTM-метка → другая страница» (можно несколько значений метки);
 *  • боты (Яндекс Директ, Метрика, поисковики, превью соцсетей и др.) редирект НЕ получают;
 *    список ботов редактируется в интерфейсе и одной кнопкой применяется ко всем старым редиректам;
 *  • бэкап .htaccess перед каждой записью, атомарная запись, блокировка от параллельных правок;
 *  • проверка сайта после записи и автоматический откат, если сайт упал;
 *  • кнопка «Проверить» у каждого редиректа (как его видят человек, Директ, Метрика, поисковики);
 *  • старые редиректы, созданные прежней версией, подхватываются автоматически.
 */

// ============================================================================
//  НАСТРОЙКИ — меняйте только этот блок
// ============================================================================
$CONFIG = array(
    // Пароль. Можно указать открытым текстом или хэшем password_hash('...', PASSWORD_DEFAULT).
    'password'        => 'Ux72K2ON5',

    'platform'        => 'wp',                       // auto | static | wp | bitrix
    'htaccess'        => __DIR__ . '/.htaccess',
    'backup_dir'      => '',                         // '' = авто: ../htaccess_backups (вне корня сайта)
    'keep_backups'    => 30,
    'health_check'    => true,
    'health_url'      => '',
    'curl_bin'        => '',
    'http_timeout'    => 8,                          // таймаут одной проверки сайта, сек
    // Блок обязан стоять ВЫШЕ правил WordPress, иначе редиректы с внутренних страниц не срабатывают
    // (WordPress перехватывает запрос в index.php раньше). Старый блок в конце файла будет перенесён.
    'anchors'         => array('# BEGIN WordPress'),
    'anchor_required' => false,

    'brand' => array(
        'title' => '',
        'color' => '#007cba',
        'head'  => '',
        'note'  => '',
    ),
);

// ============================================================================
//  ДАЛЬШЕ — ОБЩЕЕ ЯДРО (одинаковое во всех вариантах, конфиг — только выше)
// ============================================================================

define('CR_VERSION', '2.0');
define('CR_MARKER_START', '# BEGIN Custom Redir');
define('CR_MARKER_END', '# END Custom Redir');

ini_set('display_errors', '0');
error_reporting(E_ALL);
// Фатальная ошибка — вместо белой страницы понятное сообщение (подробности — в error_log сервера)
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) return;
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=UTF-8'); }
    echo '<div style="font:15px system-ui,Arial;max-width:640px;margin:40px auto;padding:16px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:8px">'
        . '<b>Панель редиректов: внутренняя ошибка.</b><br>' . htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8')
        . '<br><small>Строка ' . (int)$e['line'] . '. Файл .htaccess не повреждён: запись атомарная, копии — в каталоге бэкапов.</small></div>';
});

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// ── Мелкие хелперы (без функций PHP 8, чтобы работало на 7.4) ───────────────
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cr_starts($s, $p) { return strncmp((string)$s, (string)$p, strlen($p)) === 0; }
function cr_ends($s, $p) { return $p === '' || substr((string)$s, -strlen($p)) === $p; }
function cr_lower($s) { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }
function cr_cfg($key, $default = null) {
    global $CONFIG;
    return array_key_exists($key, $CONFIG) ? $CONFIG[$key] : $default;
}
function cr_is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}
function cr_base_url() {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
    return (cr_is_https() ? 'https' : 'http') . '://' . $host;
}
function cr_self() { return basename(__FILE__); }
function cr_fn_enabled($fn) {
    if (!function_exists($fn)) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array($fn, $disabled, true);
}

// ── Платформа ───────────────────────────────────────────────────────────────
function cr_htaccess_path() {
    $p = cr_cfg('htaccess', '');
    return $p !== '' ? $p : __DIR__ . '/.htaccess';
}
function cr_platform() {
    $p = cr_cfg('platform', 'auto');
    if ($p !== 'auto') return $p;
    $dir = dirname(cr_htaccess_path());
    if (file_exists($dir . '/wp-config.php') || file_exists($dir . '/wp-load.php')) return 'wp';
    if (is_dir($dir . '/bitrix')) return 'bitrix';
    return 'static';
}
/** Позиция первого найденного якоря (в порядке списка) или null. */
function cr_anchor_pos($content) {
    foreach (cr_anchors() as $a) {
        if (preg_match('/^(?:\xEF\xBB\xBF)?\K[ \t]*' . preg_quote($a, '/') . '/m', $content, $m, PREG_OFFSET_CAPTURE)) return $m[0][1];
    }
    return null;
}
/** Строки, ПЕРЕД которыми ставится блок. Блок обязан быть выше «catch-all» правил CMS. */
function cr_anchors() {
    $a = cr_cfg('anchors', null);
    if (is_array($a) && $a) return $a;
    switch (cr_platform()) {
        case 'wp':     return array('# BEGIN WordPress');
        case 'bitrix': return array('Options -Indexes', '<IfModule mod_rewrite.c>');
        default:       return array();
    }
}

// ============================================================================
//  БОТЫ
//  Токены — подстроки User-Agent без учёта регистра (не регулярки).
//  Ботам редирект не отдаётся: они видят исходную страницу с кодом 200.
// ============================================================================
function cr_bot_groups() {
    return array(
        'yandex' => array(
            'title' => 'Яндекс (поиск, Директ, Метрика, Вебмастер и др.)',
            'tokens' => array(
                'yandex.com/bots', 'yandex.ru/bots',           // подпись ВСЕХ официальных роботов Яндекса, в т.ч. будущих
                'YandexBot', 'YandexMobileBot', 'YandexDirect', 'YaDirectFetcher', 'YandexMetrika',
                'YandexWebmaster', 'YandexAccessibilityBot', 'YandexAdNet', 'YandexBlogs', 'YandexCalendar',
                'YandexFavicons', 'YandexForDomain', 'YandexImages', 'YandexImageResizer', 'YandexMarket',
                'YandexMedia', 'YandexMobileScreenShotBot', 'YandexNews', 'YandexOntoDB', 'YandexPagechecker',
                'YandexPartner', 'YandexRCA', 'YandexRenderResourcesBot', 'YandexScreenshotBot',
                'YandexSearchShop', 'YandexSitelinks', 'YandexSpravBot', 'YandexTracker', 'YandexTurbo',
                'YandexUserproxy', 'YandexVertis', 'YandexVerticals', 'YandexVideo', 'YandexAdditional',
                'YandexComBot',
            ),
        ),
        'google' => array(
            'title' => 'Google',
            'tokens' => array('Googlebot', 'google.com/bot', 'AdsBot-Google', 'Mediapartners-Google',
                'Google-InspectionTool', 'GoogleOther', 'Google-Read-Aloud', 'APIs-Google', 'FeedFetcher-Google',
                'Google-Site-Verification', 'Storebot-Google', 'Google-Extended'),
        ),
        'search' => array(
            'title' => 'Другие поисковики',
            'tokens' => array('bingbot', 'BingPreview', 'msnbot', 'adidxbot', 'Mail.RU_Bot', 'DuckDuckBot',
                'Baiduspider', 'Applebot', 'SeznamBot', 'PetalBot', 'Sogou', 'Exabot', '360Spider', 'Qwantify'),
        ),
        'social' => array(
            'title' => 'Соцсети и мессенджеры (превью ссылок)',
            'tokens' => array('TelegramBot', 'vkShare', 'OdklBot', 'facebookexternalhit', 'Facebot',
                'Twitterbot', 'WhatsApp', 'SkypeUriPreview', 'Slackbot', 'Discordbot', 'LinkedInBot',
                'Pinterestbot'),
        ),
        'seo' => array(
            'title' => 'SEO-сервисы',
            'tokens' => array('AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'BLEXBot', 'DataForSeoBot',
                'serpstatbot', 'MegaIndex', 'Screaming Frog', 'rogerbot', 'SiteAuditBot', 'LinkpadBot'),
        ),
        'ai' => array(
            'title' => 'AI-краулеры',
            'tokens' => array('GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-User',
                'anthropic-ai', 'PerplexityBot', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent'),
        ),
        'tech' => array(
            'title' => 'Технические (headless, PageSpeed, мониторинг, скрипты)',
            'tokens' => array('bot/', 'crawler', 'spider', 'HeadlessChrome', 'Lighthouse', 'PageSpeed',
                'GTmetrix', 'Pingdom', 'UptimeRobot', 'StatusCake', 'curl/', 'Wget/', 'python-requests',
                'python-urllib', 'Go-http-client', 'okhttp', 'Java/', 'libwww-perl', 'HttpClient'),
        ),
    );
}
function cr_default_settings() {
    return array('off' => array(), 'extra' => array(), 'empty_ua' => true);
}
function cr_valid_token($t) {
    return is_string($t) && $t !== '' && strlen($t) <= 100 && preg_match('/^[\x21-\x7E ]+$/', $t);
}
/** Итоговый список токенов с учётом выключенных и добавленных вручную. */
function cr_effective_tokens($settings) {
    $off = array();
    foreach ($settings['off'] as $t) $off[strtolower($t)] = true;
    $out = array();
    foreach (cr_bot_groups() as $g) {
        foreach ($g['tokens'] as $t) if (!isset($off[strtolower($t)])) $out[strtolower($t)] = $t;
    }
    foreach ($settings['extra'] as $t) if (cr_valid_token($t)) $out[strtolower($t)] = $t;
    return array_values($out);
}
/** Экранирование подстроки для регулярки Apache (PCRE) внутри .htaccess. */
function cr_re_literal($s) {
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === ' ')                             $out .= '\x20';
        elseif (ctype_alnum($c) || ord($c) > 127 || strpos('_-/%', $c) !== false) $out .= $c;
        else                                        $out .= '\\' . $c;
    }
    return $out;
}
/** RewriteCond-строки исключения ботов. Условия с «!» идут через И — т.е. «ни один токен не совпал». */
function cr_bot_conditions($settings, $eol) {
    $lines = array();
    $chunk = array(); $len = 0;
    foreach (cr_effective_tokens($settings) as $t) {
        $e = cr_re_literal($t);
        if ($chunk && $len + strlen($e) > 400) {
            $lines[] = 'RewriteCond %{HTTP_USER_AGENT} !(' . implode('|', $chunk) . ') [NC]';
            $chunk = array(); $len = 0;
        }
        $chunk[] = $e; $len += strlen($e) + 1;
    }
    if ($chunk) $lines[] = 'RewriteCond %{HTTP_USER_AGENT} !(' . implode('|', $chunk) . ') [NC]';
    if (!empty($settings['empty_ua'])) $lines[] = 'RewriteCond %{HTTP_USER_AGENT} !^$';
    return $lines;
}
function cr_ua_is_bot($ua, $settings) {
    if (trim($ua) === '') return !empty($settings['empty_ua']) ? '(пустой User-Agent)' : false;
    foreach (cr_effective_tokens($settings) as $t) if (stripos($ua, $t) !== false) return $t;
    return false;
}

// ============================================================================
//  НОРМАЛИЗАЦИЯ И ВАЛИДАЦИЯ ВВОДА
// ============================================================================
function cr_oneline($v, $max = 300) {
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v);
    if ($v === null) $v = '';
    $v = trim(preg_replace('/\s+/u', ' ', $v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}
/** Путь «откуда»: /path (декодирован, без query/fragment). Разрешает вставить полный URL. */
function cr_norm_path($v, &$query = null) {
    $v = cr_oneline($v, 500);
    $query = '';
    if ($v === '') return '';
    if (preg_match('~^(https?:)?//[^/?#]*(.*)$~i', $v, $m)) $v = $m[2];
    $hashPos = strpos($v, '#');
    if ($hashPos !== false) $v = substr($v, 0, $hashPos);
    $qPos = strpos($v, '?');
    if ($qPos !== false) { $query = substr($v, $qPos + 1); $v = substr($v, 0, $qPos); }
    $v = rawurldecode($v);
    $v = str_replace(' ', '', $v);
    if ($v === '' || $v[0] !== '/') $v = '/' . $v;
    $v = preg_replace('~/{2,}~', '/', $v);
    return $v;
}
function cr_path_key($p) { return cr_lower(rtrim($p, '/')); }

/** Адрес назначения: /path?x или https://host/path. Кириллица и пробелы кодируются. */
function cr_norm_target($v) {
    $v = cr_oneline($v, 1000);
    if ($v === '') return '';
    if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $v) && !preg_match('~^https?://~i', $v)) return false; // javascript:, data: и т.п.
    if (!preg_match('~^(https?:)?//~i', $v) && $v[0] !== '/') $v = '/' . $v;
    $v = preg_replace_callback('/[^\x21-\x7E]/', function ($m) { return rawurlencode($m[0]); }, $v);
    $v = str_replace(array('"', '<', '>', '\\', '`', '{', '}', '|', '^'), array('%22', '%3C', '%3E', '%5C', '%60', '%7B', '%7D', '%7C', '%5E'), $v);
    if (preg_match('~^(https?:)?//~i', $v)) {
        $u = parse_url(cr_starts($v, '//') ? 'https:' . $v : $v);
        if (!$u || empty($u['host'])) return false;
    }
    return $v;
}
function cr_split_values($v) {
    $out = array();
    foreach (preg_split('/[,\n\r]+/', (string)$v) as $x) {
        $x = cr_oneline(rawurldecode(str_replace('+', ' ', $x)), 200);
        if ($x !== '' && !in_array($x, $out, true)) $out[] = $x;
    }
    return $out;
}
/** Путь назначения на ЭТОМ же сайте (или null, если внешний домен). */
function cr_target_local_path($to) {
    if (preg_match('~^(https?:)?//([^/?#]+)(.*)$~i', $to, $m)) {
        $self = parse_url(cr_base_url(), PHP_URL_HOST);
        $host = strtolower(preg_replace('/:\d+$/', '', $m[2]));
        if ($host !== strtolower((string)$self) && $host !== 'www.' . strtolower((string)$self) && 'www.' . $host !== strtolower((string)$self)) return null;
        $to = $m[3] === '' ? '/' : $m[3];
    }
    $to = preg_replace('/[?#].*$/', '', $to);
    return rawurldecode($to);
}

/**
 * Собирает и проверяет редирект из формы. Возвращает [item, errors].
 * $all — текущий список (для проверки дублей), $selfId — id редактируемого.
 */
function cr_item_from_input($in, $all, $selfId = null) {
    $errors = array();
    $query = '';
    $from = cr_norm_path(isset($in['from']) ? $in['from'] : '', $query);
    $param = cr_oneline(isset($in['param']) ? $in['param'] : '', 64);
    $valuesRaw = isset($in['values']) ? (string)$in['values'] : '';

    // Вставили полный URL с меткой в поле «откуда» — забираем параметр и значение из него
    if ($query !== '' && ($param === '' || trim($valuesRaw) === '')) {
        parse_str($query, $q);
        foreach ($q as $k => $val) {
            if (!is_string($val) || $val === '') continue;
            if ($param === '' || strcasecmp($param, $k) === 0) {
                if ($param === '') $param = $k;
                if (trim($valuesRaw) === '') $valuesRaw = $val;
                break;
            }
        }
    }
    $values = cr_split_values($valuesRaw);
    $toRaw = isset($in['to']) ? $in['to'] : '';
    $to = cr_norm_target($toRaw);
    $code = (isset($in['code']) && (int)$in['code'] === 301) ? 301 : 302;

    $item = array(
        'type'    => 'rule',
        'on'      => !empty($in['on']),
        'c'       => cr_oneline(isset($in['comment']) ? $in['comment'] : '', 200),
        'from'    => $from,
        'param'   => $param,
        'values'  => $values,
        'to'      => $to === false ? '' : $to,
        'code'    => $code,
        'bots'    => !empty($in['bots']),
    );

    if ($from === '') $errors[] = 'Не указано, с какой страницы редиректить.';
    if ($param === '') $errors[] = 'Не указан параметр (например, utm_campaign).';
    elseif (!preg_match('/^[A-Za-z0-9_.\-\[\]]{1,64}$/', $param)) $errors[] = 'Параметр может содержать только латиницу, цифры и символы _ . - [ ]';
    if (!$values) $errors[] = 'Не указано значение параметра.';
    foreach ($values as $v) {
        if (strpbrk($v, '&#=') !== false) { $errors[] = 'Значение «' . $v . '» не должно содержать символы & # ='; break; }
    }
    if ($to === false) $errors[] = 'Адрес назначения некорректен (нужен путь вида /page или https://…).';
    elseif ($to === '') $errors[] = 'Не указано, куда редиректить.';

    if (!$errors) {
        // Защита от бесконечного цикла: метка передаётся дальше, и страница снова сработает сама на себя
        $local = cr_target_local_path($to);
        if ($local !== null && cr_path_key($local) === cr_path_key($from)) {
            $errors[] = 'Редирект ведёт на ту же страницу — получится бесконечный цикл.';
        }
        foreach ($all as $other) {
            if ($other['type'] !== 'rule' || ($selfId !== null && $other['id'] === $selfId)) continue;
            if (cr_path_key($other['from']) !== cr_path_key($from) || strcasecmp($other['param'], $param) !== 0) continue;
            $ov = array_map('cr_lower', $other['values']);
            foreach ($values as $v) {
                if (in_array(cr_lower($v), $ov, true)) {
                    $errors[] = 'Такой редирект уже есть: ' . $from . '?' . $param . '=' . $v . ' → ' . rawurldecode($other['to'])
                        . ($other['c'] !== '' ? ' («' . $other['c'] . '»)' : '') . '. Отредактируйте существующий.';
                    break 2;
                }
            }
        }
    }
    return array($item, $errors);
}

// ============================================================================
//  ГЕНЕРАЦИЯ БЛОКА .htaccess
// ============================================================================
function cr_query_value_re($v) {
    // В %{QUERY_STRING} значение лежит в закодированном виде (кириллица = %D0%...)
    $enc = rawurlencode($v);
    $re = cr_re_literal($enc);
    return str_replace('%20', '(%20|\+)', $re);
}
function cr_subst_escape($to) {
    // В подстановке RewriteRule «$N» и «%N» — обратные ссылки; экранируем, чтобы %20 не превратился в мусор
    return str_replace(array('\\', '$', '%'), array('\\\\', '\$', '\%'), $to);
}
function cr_item_meta($it) {
    return json_encode(array(
        'v' => 2, 'on' => $it['on'] ? 1 : 0, 'c' => $it['c'], 'from' => $it['from'], 'param' => $it['param'],
        'values' => array_values($it['values']), 'to' => $it['to'], 'code' => (int)$it['code'], 'bots' => $it['bots'] ? 1 : 0,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function cr_generate_item($it, $settings, $eol) {
    if ($it['type'] === 'raw') return rtrim($it['raw']);

    $from = rtrim($it['from'], '/');
    $fromRe = $from === '' ? '^/$' : '^' . cr_re_literal($from) . '/?$';
    $vals = array();
    foreach ($it['values'] as $v) $vals[] = cr_query_value_re($v);
    $valRe = count($vals) === 1 ? $vals[0] : '(' . implode('|', $vals) . ')';
    $flags = 'R=' . (int)$it['code'] . ',L,NE';
    if (strpos($it['to'], '?') !== false) $flags .= ',QSA'; // иначе Apache сам передаст исходные параметры (utm_*)

    $lines = array('<IfModule mod_rewrite.c>', 'RewriteEngine On',
        'RewriteCond %{REQUEST_URI} ' . $fromRe . ' [NC]',
        'RewriteCond %{QUERY_STRING} (^|&)' . cr_re_literal(rawurlencode($it['param'])) . '=' . $valRe . '(&|$) [NC]');
    if ($it['bots']) foreach (cr_bot_conditions($settings, $eol) as $l) $lines[] = $l;
    $lines[] = 'RewriteRule ^ ' . cr_subst_escape($it['to']) . ' [' . $flags . ']';
    $lines[] = '</IfModule>';
    if (!$it['on']) foreach ($lines as $i => $l) $lines[$i] = '# ' . $l;

    $head = array();
    if ($it['c'] !== '') $head[] = '# ' . $it['c'];
    $head[] = '# @cr ' . cr_item_meta($it);
    return implode($eol, array_merge($head, $lines));
}
function cr_generate_block($items, $settings, $eol) {
    $parts = array(
        CR_MARKER_START . $eol
        . '# !!! Блок управляется ' . cr_self() . ' (v' . CR_VERSION . '). Ручные правки внутри маркеров будут перезаписаны.' . $eol
        . '# @cr-settings ' . json_encode(array('off' => array_values($settings['off']), 'extra' => array_values($settings['extra']), 'empty_ua' => $settings['empty_ua'] ? 1 : 0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    foreach ($items as $it) $parts[] = cr_generate_item($it, $settings, $eol);
    return implode($eol . $eol, $parts) . $eol . CR_MARKER_END;
}

// ============================================================================
//  РАЗБОР .htaccess
// ============================================================================
function cr_eol($content) { return strpos($content, "\r\n") !== false ? "\r\n" : "\n"; }

/** Находит блок между маркерами. Возвращает [start, end] (end — после END-маркера) или null. */
function cr_find_block($content, &$err = null) {
    $s = strpos($content, CR_MARKER_START);
    if ($s === false) return null;
    $e = strpos($content, CR_MARKER_END, $s);
    if ($e === false) { $err = 'В .htaccess есть «' . CR_MARKER_START . '», но нет «' . CR_MARKER_END . '» — файл повреждён. Поправьте его вручную или восстановите из резервной копии.'; return false; }
    if (strpos($content, CR_MARKER_START, $s + 1) !== false) { $err = 'В .htaccess несколько блоков «' . CR_MARKER_START . '». Оставьте один вручную.'; return false; }
    return array($s, $e + strlen(CR_MARKER_END));
}

function cr_item_id($it) {
    return substr(md5($it['type'] === 'raw' ? 'raw:' . $it['raw'] : cr_item_meta($it)), 0, 12);
}

/** Разбор старого (v1) блока, созданного прежними версиями панели. */
function cr_parse_legacy($chunk) {
    $lines = preg_split('/\r?\n/', $chunk);
    $allCommented = true; $comment = '';
    $directives = array('<IfModule', '</IfModule', 'RewriteEngine', 'RewriteCond', 'RewriteRule');
    foreach ($lines as $l) {
        $t = trim($l);
        if ($t === '') continue;
        if ($t[0] !== '#') { $allCommented = false; continue; }
        $body = ltrim(substr($t, 1));
        $isDir = false;
        foreach ($directives as $d) if (cr_starts($body, $d)) { $isDir = true; break; }
        if (!$isDir && $comment === '' && $body !== '') $comment = $body;
    }
    $clean = preg_replace('/^[ \t]*#[ \t]?/m', '', $chunk);

    if (!preg_match('/RewriteCond\s+%\{REQUEST_URI\}\s+\^(\S*)/', $clean, $m)) return null;
    $re = $m[1];
    if (cr_ends($re, '$')) $re = substr($re, 0, -1);
    if (cr_ends($re, '/?')) $re = substr($re, 0, -2);
    $re = str_replace('\\', '', $re);
    if (preg_match('/[()*+?\[\]{}|^$]/', $re)) return null;
    $from = '/' . ltrim($re, '/');

    if (!preg_match('/RewriteCond\s+%\{QUERY_STRING\}\s+\(\^\|&\)([^=\s]+)=([^\s(&]+)\(&\|\$\)/', $clean, $m)) return null;
    $param = str_replace('\\', '', $m[1]);
    $value = rawurldecode(str_replace('\\', '', $m[2]));
    if (preg_match('/[()*+?\[\]{}|^$]/', $param . $value)) return null;

    if (!preg_match('/RewriteRule\s+\S+\s+(\S+)(?:\s+\[([^\]]*)\])?/', $clean, $m)) return null;
    $to = preg_replace('/\?%\{QUERY_STRING\}$/', '', $m[1]);
    if (strpos($to, '%{') !== false || strpos($to, '$') !== false) return null;
    $to = str_replace('\\', '', $to);
    $code = (isset($m[2]) && preg_match('/R=(\d{3})/', $m[2], $cm)) ? (int)$cm[1] : 302;

    return array(
        'type' => 'rule', 'on' => !$allCommented, 'c' => $comment, 'from' => $from, 'param' => $param,
        'values' => array($value), 'to' => $to, 'code' => $code === 301 ? 301 : 302,
        'bots' => stripos($clean, '%{HTTP_USER_AGENT}') !== false, 'legacy' => true,
    );
}

function cr_parse_chunk($chunk) {
    if (preg_match('/^[ \t]*# @cr (\{.*\})[ \t]*\r?$/m', $chunk, $m)) {
        $j = json_decode($m[1], true);
        if (is_array($j) && isset($j['from'], $j['param'], $j['values'], $j['to'])) {
            return array(
                'type' => 'rule', 'on' => !empty($j['on']), 'c' => isset($j['c']) ? (string)$j['c'] : '',
                'from' => (string)$j['from'], 'param' => (string)$j['param'], 'values' => array_values((array)$j['values']),
                'to' => (string)$j['to'], 'code' => (isset($j['code']) && (int)$j['code'] === 301) ? 301 : 302,
                'bots' => !empty($j['bots']), 'legacy' => false,
            );
        }
    }
    $legacy = cr_parse_legacy($chunk);
    if ($legacy) return $legacy;
    return array('type' => 'raw', 'raw' => rtrim($chunk), 'on' => true, 'c' => '');
}

/**
 * Читает состояние: список редиректов, настройки ботов, признаки «устаревших» блоков.
 */
function cr_load_state() {
    $st = array('ok' => true, 'error' => '', 'exists' => false, 'content' => '', 'items' => array(),
        'settings' => cr_default_settings(), 'has_block' => false, 'stale' => 0, 'no_bots' => 0,
        'misplaced' => false, 'eol' => "\n", 'hash' => '');
    $file = cr_htaccess_path();
    if (!file_exists($file)) return $st;
    $content = @file_get_contents($file);
    if ($content === false) { $st['ok'] = false; $st['error'] = 'Не удалось прочитать ' . $file . ' (нет прав?).'; return $st; }
    $st['exists'] = true; $st['content'] = $content; $st['eol'] = cr_eol($content); $st['hash'] = md5($content);

    $err = null;
    $pos = cr_find_block($content, $err);
    if ($pos === false) { $st['ok'] = false; $st['error'] = $err; return $st; }
    if ($pos === null) return $st;
    $st['has_block'] = true;

    $inner = substr($content, $pos[0] + strlen(CR_MARKER_START), $pos[1] - $pos[0] - strlen(CR_MARKER_START) - strlen(CR_MARKER_END));
    $lines = preg_split('/\r?\n/', $inner);
    $chunks = array(); $cur = array();
    foreach ($lines as $l) {
        $t = trim($l);
        if (preg_match('/^# @cr-settings (\{.*\})$/', $t, $m)) {
            $j = json_decode($m[1], true);
            if (is_array($j)) {
                $st['settings'] = array(
                    'off' => array_values(array_filter(isset($j['off']) ? (array)$j['off'] : array(), 'cr_valid_token')),
                    'extra' => array_values(array_filter(isset($j['extra']) ? (array)$j['extra'] : array(), 'cr_valid_token')),
                    'empty_ua' => !isset($j['empty_ua']) || !empty($j['empty_ua']),
                );
            }
            continue;
        }
        if (cr_starts($t, '# !!! ')) continue;
        if ($t === '' && !$cur) continue;
        $cur[] = rtrim($l, "\r");
        if (preg_match('~^#?\s*</IfModule>$~i', $t)) { $chunks[] = implode("\n", $cur); $cur = array(); }
    }
    if ($cur && trim(implode('', $cur)) !== '') $chunks[] = implode("\n", $cur);

    foreach ($chunks as $ch) {
        $it = cr_parse_chunk($ch);
        $it['id'] = cr_item_id($it);
        if ($it['type'] === 'rule') {
            $expected = cr_generate_item($it, $st['settings'], "\n");
            $it['stale'] = preg_replace('/\s+/', ' ', trim($expected)) !== preg_replace('/\s+/', ' ', trim($ch));
            if ($it['stale']) $st['stale']++;
            if (!$it['bots']) $st['no_bots']++;
        }
        $st['items'][] = $it;
    }

    // Блок стоит ниже правил CMS — редиректы с внутренних страниц не сработают
    $ap = cr_anchor_pos(substr($content, 0, $pos[0]) . substr($content, $pos[1]));
    $st['misplaced'] = $ap !== null && $ap < $pos[0];
    return $st;
}

// ============================================================================
//  ЗАПИСЬ: блокировка, бэкап, атомарная запись, проверка сайта, откат
// ============================================================================
function cr_backup_dir() {
    static $dir = null;
    if ($dir !== null) return $dir;
    $cands = array();
    if (cr_cfg('backup_dir', '') !== '') $cands[] = cr_cfg('backup_dir');
    $root = dirname(cr_htaccess_path());
    $cands[] = dirname($root) . '/htaccess_backups';        // вне корня сайта — лучший вариант
    $cands[] = $root . '/.cr_backups';                      // запасной: внутри, но закрыт от веба
    foreach ($cands as $c) {
        if (is_dir($c) || @mkdir($c, 0700, true)) {
            if (is_writable($c)) {
                if (strpos(realpath($c), realpath($root)) === 0) {
                    @file_put_contents($c . '/.htaccess', "Require all denied\nDeny from all\n");
                    @file_put_contents($c . '/index.html', '');
                }
                return $dir = $c;
            }
        }
    }
    return $dir = '';
}
function cr_backups() {
    $d = cr_backup_dir();
    if ($d === '') return array();
    $files = glob($d . '/htaccess-*') ?: array();
    rsort($files);
    return $files;
}
function cr_make_backup($content, &$err) {
    $d = cr_backup_dir();
    if ($d === '') { $err = 'Нет каталога для резервных копий (нет прав на запись) — изменение отменено.'; return false; }
    $dst = $d . '/htaccess-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6);
    if (@file_put_contents($dst, $content) === false) { $err = 'Не удалось сделать резервную копию — изменение отменено.'; return false; }
    @chmod($dst, 0600);
    $files = cr_backups();
    $keep = max(5, (int)cr_cfg('keep_backups', 30));
    foreach (array_slice($files, $keep) as $old) @unlink($old);
    return $dst;
}
function cr_lock() {
    $d = cr_backup_dir();
    $f = ($d !== '' ? $d : sys_get_temp_dir()) . '/cr-' . md5(cr_htaccess_path()) . '.lock';
    $h = @fopen($f, 'c');
    if ($h && @flock($h, LOCK_EX)) return $h;
    return null;
}
function cr_unlock($h) { if ($h) { @flock($h, LOCK_UN); @fclose($h); } }

function cr_atomic_write($file, $content) {
    $perms = file_exists($file) ? (fileperms($file) & 0777) : 0644;
    $tmp = dirname($file) . '/.htaccess.cr-tmp-' . substr(md5(uniqid('', true)), 0, 8);
    if (@file_put_contents($tmp, $content) === strlen($content)) {
        @chmod($tmp, $perms);
        if (@rename($tmp, $file)) return true;
        @unlink($tmp);
    }
    // Каталог недоступен для записи, но сам файл — доступен
    return @file_put_contents($file, $content, LOCK_EX) === strlen($content);
}

function cr_http_timeout() { return max(3, min(30, (int)cr_cfg('http_timeout', 8))); }

/** HTTP-запрос без следования редиректам. Возвращает [code, location, error]. */
function cr_http($url, $ua) {
    $bin = cr_cfg('curl_bin', '');
    if ($bin !== '' && cr_fn_enabled('exec')) {
        $out = array(); $rc = null;
        @exec(escapeshellarg($bin) . ' -s -k -o /dev/null -m ' . cr_http_timeout() . ' -A ' . escapeshellarg($ua)
            . ' -w ' . escapeshellarg('%{http_code} %{redirect_url}') . ' ' . escapeshellarg($url), $out, $rc);
        $line = trim(implode('', $out));
        $parts = explode(' ', $line, 2);
        return array((int)$parts[0], isset($parts[1]) ? trim($parts[1]) : '', (int)$parts[0] ? '' : 'curl вернул код ' . $rc);
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $loc = '';
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => cr_http_timeout(), CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => $ua,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADERFUNCTION => function ($c, $hdr) use (&$loc) {
                if (stripos($hdr, 'Location:') === 0) $loc = trim(substr($hdr, 9));
                return strlen($hdr);
            },
        ));
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return array($code, $loc, $code ? '' : $err);
    }
    $ctx = stream_context_create(array(
        'http' => array('method' => 'GET', 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true, 'timeout' => cr_http_timeout(), 'header' => 'User-Agent: ' . $ua),
        'ssl' => array('verify_peer' => false, 'verify_peer_name' => false),
    ));
    $http_response_header = array();
    @file_get_contents($url, false, $ctx, 0, 1);
    $code = 0; $loc = '';
    foreach ((array)$http_response_header as $hdr) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $hdr, $m)) $code = (int)$m[1];
        elseif (stripos($hdr, 'Location:') === 0) $loc = trim(substr($hdr, 9));
    }
    return array($code, $loc, $code ? '' : 'нет ответа');
}
define('CR_UA_HUMAN', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 YaBrowser/24.10.0.0 Safari/537.36 CustomRedirCheck');
function cr_health_url() {
    $u = cr_cfg('health_url', '');
    return $u !== '' ? $u : cr_base_url() . '/';
}

/**
 * Единая точка записи. $mutator($state) возвращает [items, settings] или строку-ошибку.
 * Всё под блокировкой: перечитать → изменить → бэкап → записать → проверить сайт → при поломке откатить.
 */
function cr_commit($mutator, &$msg) {
    $file = cr_htaccess_path();
    $lock = cr_lock();
    try {
        $st = cr_load_state();
        if (!$st['ok']) { $msg = $st['error']; return false; }
        if ($st['exists'] && !is_writable($file)) { $msg = 'Файл ' . $file . ' недоступен для записи (проверьте права).'; return false; }
        if (!$st['exists'] && !is_writable(dirname($file))) { $msg = 'Файла .htaccess нет, и создать его нельзя (нет прав на каталог).'; return false; }

        $res = $mutator($st);
        if (is_string($res)) { $msg = $res; return false; }
        list($items, $settings) = $res;

        $content = $st['content'];
        $eol = $st['eol'];
        $block = cr_generate_block($items, $settings, $eol);

        // Вырезаем старый блок, запоминая где он стоял
        $oldPos = null;
        $err = null;
        $pos = cr_find_block($content, $err);
        if ($pos === false) { $msg = $err; return false; }
        if ($pos) {
            $after = substr($content, $pos[1]);
            $after = preg_replace('/^(\r?\n){1,2}/', '', $after);
            $content = substr($content, 0, $pos[0]) . $after;
            $oldPos = $pos[0];
        }
        // Куда вставлять: перед якорем CMS, если старый блок стоял ниже него; иначе на прежнее место; иначе в начало
        $anchorPos = cr_anchor_pos($content);
        if ($anchorPos !== null && ($oldPos === null || $oldPos > $anchorPos)) $ins = $anchorPos;
        elseif ($oldPos !== null) $ins = $oldPos;
        elseif (cr_cfg('anchor_required', false)) { $msg = 'В .htaccess не найдена строка «' . implode('» / «', cr_anchors()) . '», перед которой должен стоять блок. Запись отменена.'; return false; }
        else $ins = 0;
        if (substr($content, 0, 3) === "\xEF\xBB\xBF" && $ins < 3) $ins = 3;

        $tail = substr($content, $ins);
        $new = substr($content, 0, $ins) . $block . ($tail === '' ? $eol : $eol . $eol . ltrim($tail, "\r\n"));

        if ($st['exists'] && $new === $st['content']) { $msg = 'Изменений нет.'; return true; }

        $check = (bool)cr_cfg('health_check', true);
        $before = $check ? cr_http(cr_health_url(), CR_UA_HUMAN) : array(0, '', '');
        $backup = null;
        if ($st['exists']) {
            $backup = cr_make_backup($st['content'], $msg);
            if ($backup === false) return false;
        }
        if (!cr_atomic_write($file, $new)) { $msg = 'Ошибка записи в .htaccess.'; return false; }
        clearstatcache();

        if ($check) {
            $after = cr_http(cr_health_url(), CR_UA_HUMAN);
            $broke = ($after[0] >= 500 && $before[0] < 500) || ($after[0] === 403 && $before[0] !== 403);
            if ($broke) {
                if ($backup) cr_atomic_write($file, $st['content']); else @unlink($file);
                $msg = 'Сайт ответил HTTP ' . $after[0] . ' после изменения — правка АВТОМАТИЧЕСКИ ОТКАЧЕНА. '
                    . 'Вероятно, хостинг не поддерживает какую-то директиву (mod_rewrite / Options FollowSymLinks).';
                return false;
            }
            if ($after[0] === 0) $msg = 'Сохранено, но проверить сайт не удалось (' . $after[2] . '). Откройте сайт вручную.';
        }
        return true;
    } finally {
        cr_unlock($lock);
    }
}

// ============================================================================
//  ТЕСТ РЕДИРЕКТА
// ============================================================================
function cr_same_target($location, $to) {
    if ($location === '') return false;
    $a = cr_target_local_path($location);
    $b = cr_target_local_path($to);
    if ($a === null || $b === null) return stripos($location, preg_replace('/[?#].*$/', '', $to)) === 0;
    return cr_path_key($a) === cr_path_key($b);
}
function cr_test_item($it, $settings) {
    $url = cr_base_url() . implode('/', array_map('rawurlencode', explode('/', $it['from'])))
        . '?' . rawurlencode($it['param']) . '=' . rawurlencode($it['values'][0]);
    $cases = array(
        array('Обычный посетитель', CR_UA_HUMAN, $it['on']),
        array('Яндекс Директ', 'Mozilla/5.0 (compatible; YandexDirect/3.0; +http://yandex.com/bots)', $it['on'] && !$it['bots']),
        array('Яндекс Метрика', 'Mozilla/5.0 (compatible; YandexMetrika/2.0; +http://yandex.com/bots)', $it['on'] && !$it['bots']),
        array('Поисковый робот Яндекса', 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', $it['on'] && !$it['bots']),
        array('Googlebot', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', $it['on'] && !$it['bots']),
    );
    $rows = array();
    foreach ($cases as $c) {
        list($code, $loc, $err) = cr_http($url, $c[1]);
        $redirected = $code >= 300 && $code < 400 && cr_same_target($loc, $it['to']);
        $ok = $code > 0 && ($redirected === $c[2]);
        $rows[] = array('who' => $c[0], 'code' => $code, 'loc' => $loc, 'err' => $err,
            'expect' => $c[2] ? 'редирект' : 'без редиректа', 'ok' => $ok);
    }
    return array('url' => $url, 'rows' => $rows);
}

// ============================================================================
//  СЕССИЯ, АВТОРИЗАЦИЯ, CSRF
// ============================================================================
session_name('CRSESS');
session_set_cookie_params(array('lifetime' => 0, 'path' => '/', 'secure' => cr_is_https(), 'httponly' => true, 'samesite' => 'Lax'));
if (!@session_start()) {
    http_response_code(500);
    exit('Не удалось запустить сессию PHP (проверьте session.save_path на хостинге).');
}
if (empty($_SESSION['cr_csrf'])) $_SESSION['cr_csrf'] = bin2hex(random_bytes(16));

function cr_flash($type, $text) { $_SESSION['cr_flash'][] = array($type, $text); }
function cr_redirect_self($hash = '') {
    header('Location: ' . cr_self() . ($hash !== '' ? '#' . $hash : ''), true, 303);
    exit;
}
function cr_is_auth() { return !empty($_SESSION['cr_auth']) && isset($_SESSION['cr_auth_key']) && $_SESSION['cr_auth_key'] === md5(__FILE__ . cr_cfg('password')); }
function cr_check_password($p) {
    $real = (string)cr_cfg('password', '');
    if ($real === '') return false;
    if (preg_match('/^\$(2y|argon2)/', $real)) return password_verify($p, $real);
    return hash_equals($real, $p);
}
function cr_throttle_file() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
    return sys_get_temp_dir() . '/cr-login-' . md5($ip . __FILE__) . '.json';
}
function cr_throttle_get() {
    $f = cr_throttle_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($d) ? $d : array('fails' => 0, 'until' => 0);
}

$isPost = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $isPost && isset($_POST['action']) ? (string)$_POST['action'] : '';
$loginError = '';

if ($action === 'login') {
    $t = cr_throttle_get();
    if ($t['until'] > time()) {
        $loginError = 'Слишком много попыток. Подождите ' . ceil(($t['until'] - time()) / 60) . ' мин.';
    } elseif (cr_check_password(isset($_POST['password']) ? (string)$_POST['password'] : '')) {
        @unlink(cr_throttle_file());
        session_regenerate_id(true);
        $_SESSION['cr_auth'] = true;
        $_SESSION['cr_auth_key'] = md5(__FILE__ . cr_cfg('password'));
        cr_redirect_self();
    } else {
        $t['fails']++;
        if ($t['fails'] >= 5) { $t['until'] = time() + 600; $t['fails'] = 0; }
        @file_put_contents(cr_throttle_file(), json_encode($t));
        sleep(1);
        $loginError = 'Неверный пароль';
    }
}

if (!cr_is_auth()) {
    $brand = cr_cfg('brand', array());
    $color = isset($brand['color']) ? $brand['color'] : '#2563eb';
    ?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Вход — редиректы</title>
<?php echo isset($brand['head']) ? $brand['head'] : ''; ?>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:#f3f4f6;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
form{background:#fff;padding:28px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);width:100%;max-width:360px}
h1{font-size:20px;margin:0 0 16px}input{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;margin-bottom:12px}
button{width:100%;padding:11px;border:0;border-radius:8px;background:<?php echo h($color); ?>;color:#fff;font-size:15px;cursor:pointer}
.err{background:#fef2f2;color:#991b1b;padding:9px 11px;border-radius:8px;margin-bottom:12px;font-size:14px}
</style></head><body>
<form method="post" autocomplete="off">
  <h1>Управление редиректами<?php echo !empty($brand['title']) ? ' — ' . h($brand['title']) : ''; ?></h1>
  <?php if ($loginError): ?><div class="err"><?php echo h($loginError); ?></div><?php endif; ?>
  <input type="hidden" name="action" value="login">
  <input type="password" name="password" placeholder="Пароль" required autofocus>
  <button type="submit">Войти</button>
</form></body></html><?php
    exit;
}

// ============================================================================
//  ДЕЙСТВИЯ (все — POST + CSRF, после — редирект, чтобы F5 не повторял действие)
// ============================================================================
if ($isPost && $action !== '' && $action !== 'login') {
    // Проверки сайта делают HTTP-запросы: даём время и не прерываемся, если пользователь закрыл вкладку
    if (cr_fn_enabled('set_time_limit')) @set_time_limit(120);
    ignore_user_abort(true);
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['cr_csrf'], (string)$_POST['csrf'])) {
        cr_flash('error', 'Сессия устарела — обновите страницу и повторите действие.');
        cr_redirect_self();
    }
    $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
    $notFound = 'Этот редирект уже изменён или удалён (в другой вкладке или вручную). Страница обновлена — повторите действие.';
    $msg = '';
    $anchor = '';

    switch ($action) {
    case 'logout':
        $_SESSION = array();
        session_destroy();
        cr_redirect_self();

    case 'add':
        $in = $_POST; $in['on'] = 1;
        $ok = cr_commit(function ($st) use ($in) {
            list($item, $errors) = cr_item_from_input($in, $st['items']);
            if ($errors) return implode(' ', $errors);
            $st['items'][] = $item;
            return array($st['items'], $st['settings']);
        }, $msg);
        if ($ok) { cr_flash('success', 'Редирект добавлен.' . ($msg !== '' ? ' ' . $msg : '')); $anchor = 'list'; }
        else { cr_flash('error', $msg); $_SESSION['cr_old_add'] = $_POST; $anchor = 'add'; }
        break;

    case 'save':
        $ok = cr_commit(function ($st) use ($id, $notFound) {
            foreach ($st['items'] as $i => $it) {
                if ($it['id'] !== $id || $it['type'] !== 'rule') continue;
                list($item, $errors) = cr_item_from_input($_POST, $st['items'], $id);
                if ($errors) return implode(' ', $errors);
                $st['items'][$i] = $item;
                return array($st['items'], $st['settings']);
            }
            return $notFound;
        }, $msg);
        if ($ok) cr_flash('success', 'Изменения сохранены.' . ($msg !== '' ? ' ' . $msg : ''));
        else { cr_flash('error', $msg); $_SESSION['cr_old_edit'] = $_POST; }
        $anchor = 'list';
        break;

    case 'toggle':
    case 'delete':
        $ok = cr_commit(function ($st) use ($id, $action, $notFound) {
            foreach ($st['items'] as $i => $it) {
                if ($it['id'] !== $id) continue;
                if ($action === 'delete') array_splice($st['items'], $i, 1);
                elseif ($it['type'] === 'rule') $st['items'][$i]['on'] = !$it['on'];
                return array($st['items'], $st['settings']);
            }
            return $notFound;
        }, $msg);
        cr_flash($ok ? 'success' : 'error', $ok ? ($action === 'delete' ? 'Редирект удалён.' : 'Статус изменён.') . ($msg !== '' ? ' ' . $msg : '') : $msg);
        $anchor = 'list';
        break;

    case 'rebuild':
        // Применить актуальные правила (исключение ботов и т.п.) ко всем старым редиректам
        $ok = cr_commit(function ($st) {
            foreach ($st['items'] as $i => $it) if ($it['type'] === 'rule') $st['items'][$i]['bots'] = true;
            return array($st['items'], $st['settings']);
        }, $msg);
        cr_flash($ok ? 'success' : 'error', $ok ? 'Все редиректы пересобраны, исключение ботов включено для каждого.' . ($msg !== '' ? ' ' . $msg : '') : $msg);
        break;

    case 'save_bots':
        $ok = cr_commit(function ($st) {
            $enabled = isset($_POST['tok']) ? array_map('strtolower', (array)$_POST['tok']) : array();
            $off = array();
            foreach (cr_bot_groups() as $g) foreach ($g['tokens'] as $t) if (!in_array(strtolower($t), $enabled, true)) $off[] = $t;
            $extra = array();
            foreach (preg_split('/[\r\n,]+/', isset($_POST['extra']) ? (string)$_POST['extra'] : '') as $t) {
                $t = trim($t);
                if ($t === '') continue;
                if (!cr_valid_token($t)) return 'Недопустимый токен «' . $t . '»: только латиница, цифры и знаки, до 100 символов.';
                $extra[] = $t;
            }
            $settings = array('off' => $off, 'extra' => array_values(array_unique($extra)), 'empty_ua' => !empty($_POST['empty_ua']));
            if (!empty($_POST['apply_all'])) foreach ($st['items'] as $i => $it) if ($it['type'] === 'rule') $st['items'][$i]['bots'] = true;
            return array($st['items'], $settings);
        }, $msg);
        cr_flash($ok ? 'success' : 'error', $ok ? 'Список ботов сохранён и применён ко всем редиректам.' . ($msg !== '' ? ' ' . $msg : '') : $msg);
        $anchor = 'bots';
        break;

    case 'test':
        $st = cr_load_state();
        $found = null;
        foreach ($st['items'] as $it) if ($it['id'] === $id && $it['type'] === 'rule') $found = $it;
        if ($found) { $_SESSION['cr_test'] = array('id' => $id) + cr_test_item($found, $st['settings']); $anchor = 'item-' . $id; }
        else cr_flash('error', $notFound);
        break;

    case 'probe':
        $u = trim(isset($_POST['url']) ? (string)$_POST['url'] : '');
        if ($u === '') { cr_flash('error', 'Укажите адрес для проверки.'); break; }
        if (!preg_match('~^https?://~i', $u)) $u = cr_base_url() . '/' . ltrim($u, '/');
        $ua = trim(isset($_POST['ua']) ? (string)$_POST['ua'] : '');
        $rows = array();
        foreach (array('Обычный посетитель' => CR_UA_HUMAN, 'Яндекс Директ' => 'Mozilla/5.0 (compatible; YandexDirect/3.0; +http://yandex.com/bots)') as $who => $agent) {
            list($code, $loc, $err) = cr_http($u, $agent);
            $rows[] = array('who' => $who, 'code' => $code, 'loc' => $loc, 'err' => $err);
        }
        if ($ua !== '') { list($code, $loc, $err) = cr_http($u, $ua); $rows[] = array('who' => 'Свой User-Agent', 'code' => $code, 'loc' => $loc, 'err' => $err); }
        $_SESSION['cr_probe'] = array('url' => $u, 'rows' => $rows);
        $anchor = 'tools';
        break;

    case 'restore':
        $name = basename(isset($_POST['file']) ? (string)$_POST['file'] : '');
        $path = cr_backup_dir() . '/' . $name;
        if ($name === '' || !preg_match('/^htaccess-[\w\-]+$/', $name) || !is_file($path)) { cr_flash('error', 'Резервная копия не найдена.'); break; }
        $file = cr_htaccess_path();
        $lock = cr_lock();
        $cur = @file_get_contents($file);
        $bk = $cur !== false ? cr_make_backup($cur, $msg) : true;
        if ($bk !== false && cr_atomic_write($file, (string)file_get_contents($path))) cr_flash('success', 'Восстановлено из ' . $name . '. Текущая версия сохранена отдельной копией.');
        else cr_flash('error', $msg !== '' ? $msg : 'Не удалось восстановить.');
        cr_unlock($lock);
        $anchor = 'tools';
        break;

    case 'clear_cache':
        if (cr_platform() !== 'wp') break;
        $wpLoad = null;
        foreach (array(dirname(cr_htaccess_path()) . '/wp-load.php', __DIR__ . '/wp-load.php', dirname(__DIR__) . '/wp-load.php') as $p) {
            if (is_file($p)) { $wpLoad = $p; break; }
        }
        if (!$wpLoad) { cr_flash('error', 'Не найден wp-load.php.'); break; }
        // WordPress грузим в ГЛОБАЛЬНОЙ области видимости: внутри функции он ломается (переменные wp-config не станут глобальными)
        ob_start();
        try {
            require_once $wpLoad;
            $done = array();
            if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); $done[] = 'WP Rocket'; if (function_exists('rocket_clean_minify')) rocket_clean_minify(); }
            if (defined('LSCWP_V')) { do_action('litespeed_purge_all'); $done[] = 'LiteSpeed Cache'; }
            if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); $done[] = 'W3 Total Cache'; }
            if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); $done[] = 'WP Super Cache'; }
            if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) { $GLOBALS['wp_fastest_cache']->deleteCache(true); $done[] = 'WP Fastest Cache'; }
            if (function_exists('sg_cachepress_purge_cache')) { sg_cachepress_purge_cache(); $done[] = 'SiteGround Optimizer'; }
            if (class_exists('autoptimizeCache')) { autoptimizeCache::clearall(); $done[] = 'Autoptimize'; }
            if (has_action('breeze_clear_all_cache')) { do_action('breeze_clear_all_cache'); $done[] = 'Breeze'; }
            if (has_action('wphb_clear_page_cache')) { do_action('wphb_clear_page_cache'); $done[] = 'Hummingbird'; }
            if (function_exists('wp_cache_flush')) wp_cache_flush();
            if ($done) cr_flash('success', 'Кэш очищен: ' . implode(', ', $done) . '.');
            else cr_flash('warn', 'Плагин кэширования не найден — очищен только объектный кэш WordPress.');
        } catch (Throwable $e) {
            cr_flash('error', 'Ошибка при очистке кэша: ' . $e->getMessage());
        }
        ob_end_clean();
        break;
    }
    cr_redirect_self($anchor);
}

// ============================================================================
//  ИНТЕРФЕЙС
// ============================================================================
$state = cr_load_state();
$flash = isset($_SESSION['cr_flash']) ? $_SESSION['cr_flash'] : array();
$testRes = isset($_SESSION['cr_test']) ? $_SESSION['cr_test'] : null;
$probeRes = isset($_SESSION['cr_probe']) ? $_SESSION['cr_probe'] : null;
$oldAdd = isset($_SESSION['cr_old_add']) ? $_SESSION['cr_old_add'] : null;
$oldEdit = isset($_SESSION['cr_old_edit']) ? $_SESSION['cr_old_edit'] : null;
unset($_SESSION['cr_flash'], $_SESSION['cr_test'], $_SESSION['cr_probe'], $_SESSION['cr_old_add'], $_SESSION['cr_old_edit']);

$csrf = $_SESSION['cr_csrf'];
$brand = cr_cfg('brand', array());
$color = isset($brand['color']) ? $brand['color'] : '#2563eb';
$platform = cr_platform();
$platformName = array('wp' => 'WordPress', 'bitrix' => '1С-Битрикс', 'static' => 'статичный сайт');
$rules = array_filter($state['items'], function ($i) { return $i['type'] === 'rule'; });
$activeCount = count(array_filter($rules, function ($i) { return $i['on']; }));
$effTokens = cr_effective_tokens($state['settings']);
$offMap = array(); foreach ($state['settings']['off'] as $t) $offMap[strtolower($t)] = true;
$backups = cr_backups();

function cr_csrf_field() { global $csrf; return '<input type="hidden" name="csrf" value="' . h($csrf) . '">'; }
function cr_btn($action, $id, $label, $class = '', $confirm = '') {
    return '<form method="post" class="inline">' . cr_csrf_field()
        . '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="id" value="' . h($id) . '">'
        . '<button type="submit" class="btn ' . h($class) . '"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . $label . '</button></form>';
}
function cr_val($old, $key, $default = '') { return $old !== null && isset($old[$key]) ? (string)$old[$key] : $default; }
function cr_code_label($code) {
    if ($code >= 300 && $code < 400) return 'редирект ' . $code;
    if ($code === 0) return 'нет ответа';
    return (string)$code;
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Редиректы<?php echo !empty($brand['title']) ? ' — ' . h($brand['title']) : ''; ?></title>
<?php echo isset($brand['head']) ? $brand['head'] : ''; ?>
<style>
:root{--c:<?php echo h($color); ?>;--bg:#f3f4f6;--card:#fff;--line:#e5e7eb;--muted:#6b7280;--text:#111827;--ok:#15803d;--okbg:#f0fdf4;--err:#b91c1c;--errbg:#fef2f2;--warn:#92400e;--warnbg:#fffbeb}
*{box-sizing:border-box}
body{margin:0;font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--text)}
.wrap{max-width:1240px;margin:0 auto;padding:16px}
header{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:14px}
header h1{font-size:22px;margin:0}
header .sub{color:var(--muted);font-size:13px}
.hbtns{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:6px;border:1px solid transparent;background:var(--c);color:#fff;border-radius:8px;padding:8px 14px;font:inherit;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn:hover{filter:brightness(.92)}
.btn.sec{background:#fff;color:var(--text);border-color:#d1d5db}
.btn.danger{background:#fff;color:var(--err);border-color:#fecaca}
.btn.danger:hover{background:var(--errbg)}
.btn.sm{padding:5px 10px;font-size:13px}
.btn.green{background:#16a34a}
form.inline{display:inline}
.msg{padding:10px 14px;border-radius:8px;margin-bottom:10px;border:1px solid;display:flex;justify-content:space-between;gap:10px}
.msg.success{background:var(--okbg);color:var(--ok);border-color:#bbf7d0}
.msg.error{background:var(--errbg);color:var(--err);border-color:#fecaca}
.msg.warn{background:var(--warnbg);color:var(--warn);border-color:#fde68a}
.msg .x{cursor:pointer;opacity:.6;background:none;border:0;font-size:18px;line-height:1;color:inherit}
.grid{display:grid;grid-template-columns:minmax(300px,380px) 1fr;gap:16px;align-items:start}
@media (max-width:900px){.grid{grid-template-columns:1fr}}
.card{background:var(--card);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.06);padding:16px;margin-bottom:16px}
.card h2{font-size:17px;margin:0 0 12px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.sticky{position:sticky;top:12px}
@media (max-width:900px){.sticky{position:static}}
label.f{display:block;font-weight:600;margin-bottom:4px;font-size:13px}
.fg{margin-bottom:12px}
input[type=text],input[type=search],textarea,select{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font:inherit;background:#fff}
input:focus,textarea:focus,select:focus{outline:2px solid var(--c);outline-offset:-1px;border-color:transparent}
textarea{resize:vertical}
.hint{color:var(--muted);font-size:12px;margin-top:3px}
.row{display:flex;gap:10px;flex-wrap:wrap}.row>.fg{flex:1 1 130px}
.req{color:var(--err)}
.chk{display:flex;gap:8px;align-items:flex-start;font-size:13px;margin-bottom:8px;cursor:pointer}
.chk input{margin-top:2px}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.toolbar input{flex:1 1 200px}
.item{border:1px solid var(--line);border-left:4px solid var(--c);border-radius:10px;padding:12px;margin-bottom:10px;background:#fff}
.item.off{border-left-color:#9ca3af;background:#fafafa}
.item.off .route{opacity:.6}
.item.raw{border-left-color:#a855f7}
.item-top{display:flex;gap:10px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap}
.cmt{font-weight:600;margin-bottom:4px}
.route{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px;word-break:break-all}
.route .arr{color:var(--muted);padding:0 4px}
.badges{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.b{font-size:11px;padding:2px 7px;border-radius:99px;background:#f3f4f6;color:#374151;white-space:nowrap}
.b.ok{background:var(--okbg);color:var(--ok)}.b.bad{background:var(--errbg);color:var(--err)}.b.warn{background:var(--warnbg);color:var(--warn)}
.acts{display:flex;gap:6px;flex-wrap:wrap}
.edit{display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--line)}
.item.editing .edit{display:block}
pre{background:#f9fafb;border:1px solid var(--line);border-radius:8px;padding:10px;overflow:auto;font-size:12px;margin:8px 0 0;white-space:pre-wrap;word-break:break-all}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
th,td{text-align:left;padding:6px 8px;border-bottom:1px solid var(--line);vertical-align:top}
td.loc{word-break:break-all;font-family:ui-monospace,Consolas,monospace;font-size:12px}
.pass{color:var(--ok);font-weight:600}.fail{color:var(--err);font-weight:600}
details{border:1px solid var(--line);border-radius:8px;margin-bottom:8px;background:#fff}
details>summary{cursor:pointer;padding:8px 12px;font-weight:600;list-style:none;display:flex;justify-content:space-between;gap:8px}
details>summary::-webkit-details-marker{display:none}
details>summary .cnt{font-weight:400;color:var(--muted);font-size:12px}
.toks{display:flex;flex-wrap:wrap;gap:4px 14px;padding:4px 12px 12px}
.toks label{font-size:12px;display:flex;gap:5px;align-items:center;font-family:ui-monospace,Consolas,monospace}
.muted{color:var(--muted)}
.empty{color:var(--muted);text-align:center;padding:30px 10px}
.foot{color:var(--muted);font-size:12px;margin-top:6px;word-break:break-all}
code{background:#f3f4f6;padding:1px 5px;border-radius:4px;font-size:12px}
.uares{margin-top:6px;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
<header>
  <div>
    <h1>Управление редиректами<?php echo !empty($brand['title']) ? ' — ' . h($brand['title']) : ''; ?></h1>
    <div class="sub"><?php echo h(parse_url(cr_base_url(), PHP_URL_HOST)); ?> · <?php echo h($platformName[$platform]); ?> · активных: <?php echo $activeCount; ?> из <?php echo count($rules); ?></div>
  </div>
  <div class="hbtns">
    <?php if ($platform === 'wp'): ?>
      <form method="post" class="inline"><?php echo cr_csrf_field(); ?><input type="hidden" name="action" value="clear_cache"><button class="btn green" data-confirm="Очистить кэш сайта?">Очистить кэш</button></form>
    <?php endif; ?>
    <form method="post" class="inline"><?php echo cr_csrf_field(); ?><input type="hidden" name="action" value="logout"><button class="btn sec">Выйти</button></form>
  </div>
</header>

<?php foreach ($flash as $f): ?>
  <div class="msg <?php echo h($f[0]); ?>"><span><?php echo h($f[1]); ?></span><button class="x" type="button" onclick="this.parentNode.remove()">×</button></div>
<?php endforeach; ?>

<?php if (!$state['ok']): ?>
  <div class="msg error"><span><?php echo h($state['error']); ?></span></div>
<?php endif; ?>
<?php if (!cr_fn_enabled('curl_init') && cr_cfg('curl_bin', '') === '' && !ini_get('allow_url_fopen')): ?>
  <div class="msg warn"><span>На сервере нет ни cURL, ни allow_url_fopen — автопроверка сайта после изменений и тесты редиректов работать не будут.</span></div>
<?php endif; ?>
<?php if (cr_backup_dir() === ''): ?>
  <div class="msg error"><span>Нет каталога для резервных копий — сохранение заблокировано. Создайте рядом с корнем сайта папку <code>htaccess_backups</code> с правами на запись или задайте <code>backup_dir</code> в настройках.</span></div>
<?php endif; ?>
<?php if ($state['no_bots'] > 0 || $state['stale'] > 0 || $state['misplaced']): ?>
  <div class="msg warn"><span>
    <?php if ($state['no_bots'] > 0): ?>Редиректов без исключения ботов: <b><?php echo $state['no_bots']; ?></b> (Яндекс Директ, Метрика и поисковики получают редирект). <?php endif; ?>
    <?php if ($state['stale'] > 0): ?>Блоков, собранных по старым правилам: <b><?php echo $state['stale']; ?></b>. <?php endif; ?>
    <?php if ($state['misplaced']): ?>Блок редиректов стоит <b>ниже правил <?php echo h($platformName[$platform]); ?></b> — редиректы с внутренних страниц не срабатывают, будет перенесён выше. <?php endif; ?>
  </span>
  <form method="post" class="inline"><?php echo cr_csrf_field(); ?><input type="hidden" name="action" value="rebuild"><button class="btn sm" data-confirm="Пересобрать все редиректы по текущим правилам и включить исключение ботов для всех?">Применить ко всем</button></form>
  </div>
<?php endif; ?>

<div class="grid">
  <!-- ── Добавление ── -->
  <div class="sticky">
    <div class="card" id="add">
      <h2>Новый редирект</h2>
      <form method="post" id="addForm">
        <?php echo cr_csrf_field(); ?><input type="hidden" name="action" value="add">
        <div class="fg">
          <label class="f">С какой страницы <span class="req">*</span></label>
          <input type="text" name="from" value="<?php echo h(cr_val($oldAdd, 'from')); ?>" placeholder="/ или /how-help" required>
          <div class="hint">«/» — главная. Можно вставить целиком ссылку из объявления — параметр и значение подставятся сами.</div>
        </div>
        <div class="row">
          <div class="fg">
            <label class="f">Параметр <span class="req">*</span></label>
            <input type="text" name="param" value="<?php echo h(cr_val($oldAdd, 'param')); ?>" placeholder="utm_campaign" list="params">
          </div>
          <div class="fg">
            <label class="f">Значение <span class="req">*</span></label>
            <input type="text" name="values" value="<?php echo h(cr_val($oldAdd, 'values')); ?>" placeholder="liz_rsy">
          </div>
        </div>
        <div class="hint" style="margin:-8px 0 12px">Несколько значений — через запятую: <code>liz_rsy, liz_search</code></div>
        <div class="fg">
          <label class="f">Куда <span class="req">*</span></label>
          <input type="text" name="to" value="<?php echo h(cr_val($oldAdd, 'to')); ?>" placeholder="/help_ab/ivanov-ivan/" required>
          <div class="hint">Путь на сайте или полный адрес. UTM-метки передаются дальше автоматически.</div>
        </div>
        <div class="fg">
          <label class="f">Комментарий</label>
          <input type="text" name="comment" value="<?php echo h(cr_val($oldAdd, 'comment')); ?>" placeholder="Например: Лиза, РСЯ, сентябрь">
        </div>
        <div class="row">
          <div class="fg">
            <label class="f">Тип</label>
            <select name="code">
              <option value="302"<?php echo cr_val($oldAdd, 'code', '302') !== '301' ? ' selected' : ''; ?>>302 — временный (рекомендуется)</option>
              <option value="301"<?php echo cr_val($oldAdd, 'code') === '301' ? ' selected' : ''; ?>>301 — постоянный</option>
            </select>
          </div>
        </div>
        <label class="chk"><input type="checkbox" name="bots" value="1"<?php echo $oldAdd === null || !empty($oldAdd['bots']) ? ' checked' : ''; ?>> <span>Не редиректить ботов (Яндекс Директ, Метрика, поисковики и др.)</span></label>
        <button type="submit" class="btn" style="width:100%;justify-content:center">Добавить редирект</button>
      </form>
      <datalist id="params"><option value="utm_campaign"><option value="utm_source"><option value="utm_medium"><option value="utm_content"><option value="utm_term"><option value="yclid"></datalist>
    </div>
  </div>

  <div>
    <!-- ── Список ── -->
    <div class="card" id="list">
      <h2>Редиректы <span class="muted" style="font-size:13px;font-weight:400"><?php echo count($state['items']); ?></span></h2>
      <?php if ($state['items']): ?>
      <div class="toolbar">
        <input type="search" id="q" placeholder="Поиск по адресу, метке, комментарию…">
        <select id="flt" style="flex:0 0 auto;width:auto">
          <option value="">Все</option><option value="on">Активные</option><option value="off">Выключенные</option><option value="nobots">Без исключения ботов</option>
        </select>
      </div>
      <?php endif; ?>

      <?php if (!$state['items']): ?>
        <div class="empty">Редиректов пока нет — добавьте первый в форме слева.</div>
      <?php endif; ?>

      <?php foreach ($state['items'] as $n => $it): $id = $it['id']; ?>
        <?php if ($it['type'] === 'raw'): ?>
          <div class="item raw" id="item-<?php echo h($id); ?>" data-search="<?php echo h(cr_lower($it['raw'])); ?>" data-state="on">
            <div class="item-top">
              <div><div class="cmt">Правило, добавленное вручную</div><div class="muted" style="font-size:12px">Не распознано панелью — сохраняется как есть.</div></div>
              <div class="acts"><?php echo cr_btn('delete', $id, 'Удалить', 'sm danger', 'Удалить это правило из .htaccess?'); ?></div>
            </div>
            <pre><?php echo h($it['raw']); ?></pre>
          </div>
        <?php continue; endif; ?>
        <?php
          $isEditErr = $oldEdit !== null && isset($oldEdit['id']) && $oldEdit['id'] === $id;
          $e = $isEditErr ? $oldEdit : array('from' => $it['from'], 'param' => $it['param'], 'values' => implode(', ', $it['values']),
              'to' => $it['to'], 'comment' => $it['c'], 'code' => (string)$it['code'], 'on' => $it['on'] ? '1' : '', 'bots' => $it['bots'] ? '1' : '');
          $search = cr_lower($it['c'] . ' ' . $it['from'] . ' ' . $it['param'] . ' ' . implode(' ', $it['values']) . ' ' . $it['to'] . ' ' . rawurldecode($it['to']));
          $flags = ($it['on'] ? 'on' : 'off') . ($it['bots'] ? '' : ' nobots');
        ?>
        <div class="item<?php echo $it['on'] ? '' : ' off'; ?><?php echo $isEditErr ? ' editing' : ''; ?>" id="item-<?php echo h($id); ?>" data-search="<?php echo h($search); ?>" data-state="<?php echo h($flags); ?>"
             data-dup="<?php echo h(json_encode(array('from' => $it['from'], 'param' => $it['param'], 'values' => '', 'to' => $it['to'], 'comment' => $it['c'], 'code' => $it['code'], 'bots' => $it['bots']), JSON_UNESCAPED_UNICODE)); ?>">
          <div class="item-top">
            <div style="flex:1 1 300px;min-width:0">
              <?php if ($it['c'] !== ''): ?><div class="cmt"><?php echo h($it['c']); ?></div><?php endif; ?>
              <div class="route"><?php echo h($it['from']); ?>?<b><?php echo h($it['param']); ?>=<?php echo h(implode(' | ', $it['values'])); ?></b><span class="arr">→</span><?php echo h(rawurldecode($it['to'])); ?></div>
              <div class="badges">
                <?php if (!$it['on']): ?><span class="b">выключен</span><?php endif; ?>
                <span class="b"><?php echo (int)$it['code']; ?></span>
                <?php if ($it['bots']): ?><span class="b ok">боты исключены</span><?php else: ?><span class="b bad">ботам тоже редирект</span><?php endif; ?>
                <?php if (!empty($it['stale'])): ?><span class="b warn">старый формат</span><?php endif; ?>
              </div>
            </div>
            <div class="acts">
              <button type="button" class="btn sm sec js-edit">Изменить</button>
              <?php echo cr_btn('toggle', $id, $it['on'] ? 'Выключить' : 'Включить', 'sm sec'); ?>
              <?php echo cr_btn('test', $id, 'Проверить', 'sm sec'); ?>
              <button type="button" class="btn sm sec js-dup" title="Скопировать в форму добавления — например, чтобы добавить редирект с другой меткой">Копия</button>
              <?php echo cr_btn('delete', $id, 'Удалить', 'sm danger', 'Удалить редирект ' . $it['from'] . '?' . $it['param'] . '=' . implode(',', $it['values']) . ' ?'); ?>
            </div>
          </div>

          <?php if ($testRes && $testRes['id'] === $id): ?>
            <div style="margin-top:10px">
              <div class="muted" style="font-size:12px">Проверка: <a href="<?php echo h($testRes['url']); ?>" target="_blank" rel="noopener"><?php echo h(rawurldecode($testRes['url'])); ?></a></div>
              <table><tr><th>Кто</th><th>Ожидается</th><th>Ответ</th><th>Куда</th><th></th></tr>
              <?php foreach ($testRes['rows'] as $r): ?>
                <tr><td><?php echo h($r['who']); ?></td><td><?php echo h($r['expect']); ?></td><td><?php echo h(cr_code_label($r['code'])); ?><?php echo $r['err'] ? ' <span class="muted">' . h($r['err']) . '</span>' : ''; ?></td>
                <td class="loc"><?php echo h(rawurldecode($r['loc'])); ?></td><td class="<?php echo $r['ok'] ? 'pass' : 'fail'; ?>"><?php echo $r['ok'] ? '✓' : '✗'; ?></td></tr>
              <?php endforeach; ?>
              </table>
              <?php if (array_filter($testRes['rows'], function ($r) { return !$r['ok']; })): ?>
                <div class="hint">Если посетителя не перенаправляет: проверьте кэш сайта/CDN, включён ли mod_rewrite и не стоит ли выше другое правило для этой страницы.</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <form method="post" class="edit">
            <?php echo cr_csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo h($id); ?>">
            <div class="fg"><label class="f">Комментарий</label><input type="text" name="comment" value="<?php echo h($e['comment']); ?>"></div>
            <div class="fg"><label class="f">С какой страницы</label><input type="text" name="from" value="<?php echo h($e['from']); ?>" required></div>
            <div class="row">
              <div class="fg"><label class="f">Параметр</label><input type="text" name="param" value="<?php echo h($e['param']); ?>" list="params" required></div>
              <div class="fg"><label class="f">Значения (через запятую)</label><input type="text" name="values" value="<?php echo h($e['values']); ?>" required></div>
            </div>
            <div class="fg"><label class="f">Куда</label><input type="text" name="to" value="<?php echo h(rawurldecode($e['to'])); ?>" required></div>
            <div class="row" style="align-items:center">
              <div class="fg"><select name="code"><option value="302"<?php echo $e['code'] !== '301' ? ' selected' : ''; ?>>302 — временный</option><option value="301"<?php echo $e['code'] === '301' ? ' selected' : ''; ?>>301 — постоянный</option></select></div>
              <div class="fg">
                <label class="chk" style="margin:0"><input type="checkbox" name="on" value="1"<?php echo !empty($e['on']) ? ' checked' : ''; ?>> Активен</label>
                <label class="chk" style="margin:4px 0 0"><input type="checkbox" name="bots" value="1"<?php echo !empty($e['bots']) ? ' checked' : ''; ?>> Не редиректить ботов</label>
              </div>
            </div>
            <div class="acts"><button type="submit" class="btn sm">Сохранить</button><button type="button" class="btn sm sec js-edit">Отмена</button></div>
          </form>
        </div>
      <?php endforeach; ?>
      <div class="empty" id="noResults" style="display:none">Ничего не найдено.</div>
    </div>

    <!-- ── Боты ── -->
    <div class="card" id="bots">
      <h2>Исключения для ботов <span class="muted" style="font-size:13px;font-weight:400">правил: <?php echo count($effTokens); ?></span></h2>
      <p class="muted" style="margin:0 0 10px">Ботам редирект не отдаётся — они видят исходную страницу. Совпадение ищется как подстрока в User-Agent без учёта регистра. После сохранения правила сразу применяются ко <b>всем</b> редиректам, включая старые.</p>
      <form method="post">
        <?php echo cr_csrf_field(); ?><input type="hidden" name="action" value="save_bots"><input type="hidden" name="apply_all" value="1">
        <?php foreach (cr_bot_groups() as $gk => $g):
            $onCnt = 0; foreach ($g['tokens'] as $t) if (!isset($offMap[strtolower($t)])) $onCnt++; ?>
          <details<?php echo $gk === 'yandex' ? ' open' : ''; ?>>
            <summary><span><?php echo h($g['title']); ?></span><span class="cnt"><?php echo $onCnt; ?> / <?php echo count($g['tokens']); ?> · <a href="#" class="js-all" data-v="1">все</a> · <a href="#" class="js-all" data-v="0">ничего</a></span></summary>
            <div class="toks">
              <?php foreach ($g['tokens'] as $t): ?>
                <label><input type="checkbox" name="tok[]" value="<?php echo h($t); ?>"<?php echo isset($offMap[strtolower($t)]) ? '' : ' checked'; ?>><?php echo h($t); ?></label>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endforeach; ?>
        <div class="fg" style="margin-top:10px">
          <label class="f">Свои правила (по одному в строке)</label>
          <textarea name="extra" rows="3" placeholder="Например: MyMonitoringBot"><?php echo h(implode("\n", $state['settings']['extra'])); ?></textarea>
          <div class="hint">Новый бот? Впишите фрагмент его User-Agent — правило добавится во все существующие редиректы.</div>
        </div>
        <label class="chk"><input type="checkbox" name="empty_ua" value="1"<?php echo $state['settings']['empty_ua'] ? ' checked' : ''; ?>> Не редиректить запросы с пустым User-Agent (скрипты, сканеры)</label>
        <button type="submit" class="btn">Сохранить и применить ко всем</button>
      </form>
      <div class="fg" style="margin:16px 0 0">
        <label class="f">Проверить User-Agent</label>
        <input type="text" id="uaTest" placeholder="Вставьте User-Agent из логов или Метрики">
        <div class="uares" id="uaRes"></div>
      </div>
    </div>

    <!-- ── Инструменты ── -->
    <div class="card" id="tools">
      <h2>Проверка и резервные копии</h2>
      <form method="post" class="row" style="align-items:flex-end">
        <?php echo cr_csrf_field(); ?><input type="hidden" name="action" value="probe">
        <div class="fg" style="flex:3 1 260px"><label class="f">Проверить любой адрес</label><input type="text" name="url" value="<?php echo h($probeRes ? $probeRes['url'] : ''); ?>" placeholder="/?utm_campaign=liz_rsy"></div>
        <div class="fg" style="flex:2 1 200px"><label class="f">Свой User-Agent (необязательно)</label><input type="text" name="ua"></div>
        <div class="fg" style="flex:0 0 auto"><button class="btn">Проверить</button></div>
      </form>
      <?php if ($probeRes): ?>
        <table><tr><th>Кто</th><th>Ответ</th><th>Куда</th></tr>
        <?php foreach ($probeRes['rows'] as $r): ?>
          <tr><td><?php echo h($r['who']); ?></td><td><?php echo h(cr_code_label($r['code'])); ?><?php echo $r['err'] ? ' <span class="muted">' . h($r['err']) . '</span>' : ''; ?></td><td class="loc"><?php echo h(rawurldecode($r['loc'])); ?></td></tr>
        <?php endforeach; ?>
        </table>
      <?php endif; ?>

      <details style="margin-top:14px">
        <summary><span>Резервные копии .htaccess</span><span class="cnt"><?php echo count($backups); ?></span></summary>
        <div style="padding:0 12px 12px">
          <?php if (!$backups): ?><div class="muted">Копий пока нет — они создаются перед каждым изменением.</div><?php endif; ?>
          <table>
          <?php foreach (array_slice($backups, 0, 15) as $b): $bn = basename($b); ?>
            <tr><td><?php echo h(date('d.m.Y H:i:s', filemtime($b))); ?></td><td class="muted"><?php echo round(filesize($b) / 1024, 1); ?> КБ</td>
            <td style="text-align:right"><form method="post" class="inline"><?php echo cr_csrf_field(); ?><input type="hidden" name="action" value="restore"><input type="hidden" name="file" value="<?php echo h($bn); ?>"><button class="btn sm sec" data-confirm="Восстановить .htaccess из копии от <?php echo h(date('d.m.Y H:i:s', filemtime($b))); ?>? Текущая версия тоже будет сохранена.">Восстановить</button></form></td></tr>
          <?php endforeach; ?>
          </table>
        </div>
      </details>
    </div>

    <div class="foot">
      Файл: <code><?php echo h(cr_htaccess_path()); ?></code><?php echo $state['exists'] ? '' : ' (будет создан)'; ?> ·
      копии: <code><?php echo h(cr_backup_dir() !== '' ? cr_backup_dir() : '—'); ?></code> ·
      PHP <?php echo h(PHP_VERSION); ?> · custom_redir v<?php echo CR_VERSION; ?>
      <?php if (!empty($brand['note'])): ?><div style="margin-top:6px"><?php echo $brand['note']; ?></div><?php endif; ?>
    </div>
  </div>
</div>
</div>

<script>
(function () {
  // Подтверждение опасных действий
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm]');
    if (b && !confirm(b.getAttribute('data-confirm'))) e.preventDefault();
  });
  // Защита от двойной отправки
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset.sent) { e.preventDefault(); return; }
    f.dataset.sent = '1';
    setTimeout(function () { f.dataset.sent = ''; }, 8000);
  });
  // Редактирование
  document.querySelectorAll('.js-edit').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('.item').classList.toggle('editing'); });
  });
  // Копия в форму добавления
  var add = document.getElementById('addForm');
  document.querySelectorAll('.js-dup').forEach(function (b) {
    b.addEventListener('click', function () {
      var d = JSON.parse(b.closest('.item').getAttribute('data-dup')), to = d.to;
      try { to = decodeURIComponent(d.to); } catch (x) {}
      add.from.value = d.from; add.param.value = d.param; add.values.value = '';
      add.to.value = to; add.comment.value = d.comment; add.code.value = String(d.code); add.bots.checked = !!d.bots;
      document.getElementById('add').scrollIntoView({behavior: 'smooth'});
      add.values.focus();
    });
  });
  // Вставили ссылку с меткой в «откуда» — разбираем сразу
  add.from.addEventListener('change', function () {
    var v = add.from.value.trim(), i = v.indexOf('?');
    if (i < 0) return;
    var qs = v.slice(i + 1).split('#')[0].split('&');
    for (var k = 0; k < qs.length; k++) {
      var p = qs[k].split('=');
      if (p.length < 2 || !p[1]) continue;
      var name = p[0], val = p[1];
      try { name = decodeURIComponent(p[0]); val = decodeURIComponent(p[1].replace(/\+/g, ' ')); } catch (x) {}
      if (!add.param.value || add.param.value.toLowerCase() === name.toLowerCase()) {
        add.param.value = name;
        if (!add.values.value) add.values.value = val;
        break;
      }
    }
    add.from.value = v.slice(0, i).replace(/^https?:\/\/[^\/]+/i, '') || '/';
  });
  // Поиск и фильтр
  var q = document.getElementById('q'), flt = document.getElementById('flt');
  function filter() {
    var s = (q.value || '').toLowerCase().trim(), f = flt.value, shown = 0;
    document.querySelectorAll('#list .item').forEach(function (it) {
      var ok = (!s || it.getAttribute('data-search').indexOf(s) >= 0) && (!f || (' ' + it.getAttribute('data-state') + ' ').indexOf(' ' + f + ' ') >= 0);
      it.style.display = ok ? '' : 'none'; if (ok) shown++;
    });
    document.getElementById('noResults').style.display = shown ? 'none' : '';
  }
  if (q) { q.addEventListener('input', filter); flt.addEventListener('change', filter); }
  // Выбрать все / ничего в группе ботов
  document.querySelectorAll('.js-all').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var v = a.getAttribute('data-v') === '1';
      a.closest('details').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = v; });
    });
  });
  // Проверка User-Agent по текущим (в т.ч. несохранённым) галочкам
  var ua = document.getElementById('uaTest'), res = document.getElementById('uaRes');
  ua.addEventListener('input', function () {
    var v = ua.value.trim();
    if (!v) { res.textContent = ''; return; }
    var toks = [];
    document.querySelectorAll('#bots input[name="tok[]"]:checked').forEach(function (c) { toks.push(c.value); });
    document.querySelector('#bots textarea[name=extra]').value.split(/[\r\n,]+/).forEach(function (t) { t = t.trim(); if (t) toks.push(t); });
    var low = v.toLowerCase(), hit = null;
    for (var i = 0; i < toks.length; i++) if (low.indexOf(toks[i].toLowerCase()) >= 0) { hit = toks[i]; break; }
    res.innerHTML = hit ? '<span class="pass">Бот — редиректа не будет</span> <span class="muted">(совпало: <code></code>)</span>'
                        : '<span class="fail">Не бот — получит редирект</span>';
    if (hit) res.querySelector('code').textContent = hit;
  });
  // Убираем якорь после перехода, чтобы F5 не прыгал
  if (location.hash) { var el = document.querySelector(location.hash); if (el) el.scrollIntoView(); }
})();
</script>
</body>
</html>
