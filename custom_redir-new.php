<?php
session_start();

// Конфигурация
$PASSWORD = 'Ux72K2ON5';
$HTACCESS_FILE = '.htaccess';
$MARKER_START = '# BEGIN Custom Redir';
$MARKER_END = '# END Custom Redir';
$SETTINGS_PREFIX = '# CR_CFG ';

/**
 * Компактный паттерн User-Agent без пробелов.
 * Пробел в RewriteCond ломает .htaccess (500): Apache считает его разделителем аргументов.
 * Yandex/YaDirect покрывают Директ, Метрику, Fetcher и всю линейку Яндекса.
 * bot|crawler|spider|slurp|fetcher ловят остальных роботов.
 */
function getBotUaPattern() {
    $parts = [
        'Yandex',
        'YaDirect',
        'Mediapartners-Google',
        'facebookexternalhit',
        'vkShare',
        'WhatsApp',
        'ia_archiver',
        'ChatGPT',
        'anthropic',
        'bot',
        'crawler',
        'spider',
        'slurp',
        'fetcher',
    ];
    return implode('|', $parts);
}

function getBotExcludeCondLine() {
    $pattern = getBotUaPattern();
    // Кавычки обязательны: пробел внутри паттерна иначе даёт 500 в Apache.
    return 'RewriteCond %{HTTP_USER_AGENT} "!' . '(' . $pattern . ')" [NC]';
}

function getEmptyUaCondLine() {
    return 'RewriteCond %{HTTP_USER_AGENT} !^$';
}

function getEnvBotCondLine() {
    return 'RewriteCond %{ENV:is_redir_bot} !=1';
}

// Проверка авторизации
function isAuthenticated() {
    global $PASSWORD;
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

// Аутентификация
if (isset($_POST['password'])) {
    if ($_POST['password'] === $PASSWORD) {
        $_SESSION['authenticated'] = true;
    } else {
        $error = "Неверный пароль";
    }
}

// Выход
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Если не авторизован, показать форму входа
if (!isAuthenticated()) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Авторизация</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 400px; margin: 100px auto; padding: 20px; }
            .login-form { background: #f9f9f9; padding: 30px; border-radius: 8px; }
            input[type="password"] { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
            button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
            .error { color: red; margin-bottom: 15px; }
        </style>
    </head>
    <body>
        <div class="login-form">
            <h2>Авторизация</h2>
            <?php if (isset($error)) echo "<div class='error'>$error</div>"; ?>
            <form method="post">
                <input type="password" name="password" placeholder="Введите пароль" required>
                <button type="submit">Войти</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function extractMarkedBlock($content) {
    global $MARKER_START, $MARKER_END;
    $pattern = '/' . preg_quote($MARKER_START, '/') . '(.*?)' . preg_quote($MARKER_END, '/') . '/s';
    if (preg_match($pattern, $content, $matches)) {
        return $matches[1];
    }
    return '';
}

function getSettingsFromBlock($blockContent) {
    global $SETTINGS_PREFIX;
    $settings = [
        'exclude_bots' => true, // по умолчанию включаем исключение ботов
    ];
    foreach (explode("\n", $blockContent) as $line) {
        $line = trim($line);
        if (strpos($line, $SETTINGS_PREFIX) === 0) {
            $raw = trim(substr($line, strlen($SETTINGS_PREFIX)));
            parse_str(str_replace(' ', '&', $raw), $parsed);
            if (isset($parsed['exclude_bots'])) {
                $settings['exclude_bots'] = ($parsed['exclude_bots'] === '1' || $parsed['exclude_bots'] === 'true');
            }
        }
    }
    return $settings;
}

function blockHasBotRules($block) {
    $clean = preg_replace('/^\s*#\s?/', '', $block);
    return (
        strpos($clean, 'is_redir_bot') !== false
        || strpos($clean, '%{HTTP_USER_AGENT}') !== false
    );
}

function generateBotEnvBlock() {
    $pattern = getBotUaPattern();
    $lines = [
        '# Исключение ботов из редиректов (SetEnvIf + UA). Не удаляйте этот блок.',
        '<IfModule mod_setenvif.c>',
        'SetEnvIfNoCase User-Agent "' . $pattern . '" is_redir_bot=1',
        'SetEnvIf User-Agent "^$" is_redir_bot=1',
        '</IfModule>',
    ];
    return implode("\n", $lines);
}

// Чтение существующих редиректов
function getCurrentRedirects() {
    global $HTACCESS_FILE, $MARKER_START, $MARKER_END;

    $redirects = [];
    if (!file_exists($HTACCESS_FILE)) {
        return $redirects;
    }

    $content = file_get_contents($HTACCESS_FILE);
    $pattern = '/' . preg_quote($MARKER_START, '/') . '(.*?)' . preg_quote($MARKER_END, '/') . '/s';

    if (preg_match($pattern, $content, $matches)) {
        $blockContent = $matches[1];
        // Разбиваем на отдельные редиректы по закрытию IfModule rewrite
        $redirectBlocks = explode('</IfModule>', $blockContent);

        foreach ($redirectBlocks as $block) {
            if (strpos($block, 'RewriteRule') === false && strpos($block, '# RewriteRule') === false) {
                continue;
            }
            $redirect = parseRedirectBlock($block . '</IfModule>');
            if ($redirect && ($redirect['from_page'] || $redirect['to_page'] || $redirect['utm_param'])) {
                $redirects[] = $redirect;
            }
        }
    }

    return $redirects;
}

function getCurrentSettings() {
    global $HTACCESS_FILE;
    $defaults = ['exclude_bots' => true];
    if (!file_exists($HTACCESS_FILE)) {
        return $defaults;
    }
    $content = file_get_contents($HTACCESS_FILE);
    $block = extractMarkedBlock($content);
    if ($block === '') {
        return $defaults;
    }
    return getSettingsFromBlock($block);
}

// Парсинг блока редиректа
function parseRedirectBlock($block) {
    $redirect = [
        'enabled' => true,
        'comment' => '',
        'from_page' => '',
        'utm_param' => '',
        'utm_value' => '',
        'to_page' => '',
        'bots_excluded' => false,
    ];

    $lines = explode("\n", $block);
    $all_commented = true;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '' && strpos($trimmed, '#') !== 0) {
            $all_commented = false;
            break;
        }
    }
    $redirect['enabled'] = !$all_commented;
    $redirect['bots_excluded'] = blockHasBotRules($block);

    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') && !str_starts_with($line, '# <IfModule') && !str_starts_with($line, '# Rewrite') && !str_starts_with($line, '# SetEnv') && !str_starts_with($line, '# Exclude') && !str_starts_with($line, '# Исключ') && !str_starts_with($line, '# CR_') && !str_starts_with($line, '# BEGIN') && !str_starts_with($line, '# END') && !str_starts_with($line, '# BOT')) {
            $comment = trim(substr($line, 1));
            if ($comment !== '' && stripos($comment, 'бот') === false && stripos($comment, 'exclude') === false) {
                $redirect['comment'] = $comment;
                break;
            }
        }
    }

    $block_clean = preg_replace('/^\s*#\s?/', '', $block);

    if (preg_match('/RewriteCond\s+%\{REQUEST_URI\}\s+\^\/\?\$/', $block_clean)) {
        $redirect['from_page'] = '/';
    } elseif (preg_match('/RewriteCond\s+%\{REQUEST_URI\}\s+\^\/([^\s]*)\/?\$/', $block_clean, $matches)) {
        $redirect['from_page'] = '/' . rtrim($matches[1], '?');
    }

    if (preg_match('/RewriteCond\s+%\{QUERY_STRING\}\s+\(\^\|&\)([^=]+)=([^\(&]+)/', $block_clean, $matches)) {
        $redirect['utm_param'] = trim($matches[1]);
        $redirect['utm_value'] = trim($matches[2]);
    }

    if (preg_match('/RewriteRule\s+[^\s]+\s+([^\s\?]+)/', $block_clean, $matches)) {
        $redirect['to_page'] = $matches[1];
    }

    return $redirect;
}

// Генерация блока редиректа
function generateRedirectBlock($redirect, $excludeBots = true) {
    $fromPage = trim($redirect['from_page'], '/');
    if ($fromPage === '' || $fromPage === '/') {
        $fromPageRegex = '^/?$';
        $rewriteRuleFrom = '^$';
    } else {
        $fromPageRegex = '^/' . preg_quote($fromPage, '/') . '/?$';
        $rewriteRuleFrom = '^' . preg_quote($fromPage, '/') . '/?$';
    }

    $utmParam = preg_quote($redirect['utm_param'], '/');
    $utmValue = preg_quote($redirect['utm_value'], '/');
    $toPage = $redirect['to_page'];

    $block = "";
    if (!empty($redirect['comment'])) {
        $block .= "# {$redirect['comment']}\n";
    }

    $lines = [
        "<IfModule mod_rewrite.c>",
        "RewriteEngine On",
    ];

    if ($excludeBots) {
        $lines[] = getEnvBotCondLine();
        $lines[] = getEmptyUaCondLine();
        $lines[] = getBotExcludeCondLine();
    }

    $lines[] = "RewriteCond %{REQUEST_URI} {$fromPageRegex} [NC]";
    $lines[] = "RewriteCond %{QUERY_STRING} (^|&){$utmParam}={$utmValue}(&|$) [NC]";
    $lines[] = "RewriteRule {$rewriteRuleFrom} {$toPage}?%{QUERY_STRING} [R=301,L]";
    $lines[] = "</IfModule>";

    if (empty($redirect['enabled'])) {
        $lines = array_map(function ($line) {
            return "# " . $line;
        }, $lines);
    }

    $block .= implode("\n", $lines);
    return rtrim($block);
}

function generateSettingsLine($settings) {
    global $SETTINGS_PREFIX;
    $exclude = !empty($settings['exclude_bots']) ? '1' : '0';
    return $SETTINGS_PREFIX . "exclude_bots={$exclude}";
}

// Сохранение редиректов
function saveRedirects($redirects, $settings = null) {
    global $HTACCESS_FILE, $MARKER_START, $MARKER_END;

    if ($settings === null) {
        $settings = getCurrentSettings();
    }

    $excludeBots = !empty($settings['exclude_bots']);
    $blocks = [];
    if ($excludeBots) {
        $blocks[] = generateBotEnvBlock();
    }

    foreach ($redirects as $redirect) {
        $blocks[] = generateRedirectBlock($redirect, $excludeBots);
    }

    $newBlock =
        $MARKER_START . "\n" .
        generateSettingsLine($settings) . "\n" .
        implode("\n\n", $blocks) . "\n" .
        $MARKER_END;

    if (!file_exists($HTACCESS_FILE)) {
        return file_put_contents($HTACCESS_FILE, $newBlock) !== false;
    }

    $content = file_get_contents($HTACCESS_FILE);
    $pattern = '/' . preg_quote($MARKER_START, '/') . '.*?' . preg_quote($MARKER_END, '/') . '/s';

    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, $newBlock, $content);
    } else {
        $content = rtrim($content) . "\n\n" . $newBlock;
    }

    return file_put_contents($HTACCESS_FILE, $content) !== false;
}

function analyzeBotStatus($redirects, $settings) {
    $total = count($redirects);
    $withRules = 0;
    foreach ($redirects as $redirect) {
        if (!empty($redirect['bots_excluded'])) {
            $withRules++;
        }
    }
    $enabled = !empty($settings['exclude_bots']);
    $allHaveRules = ($total === 0) || ($withRules === $total);
    $envPresent = false;
    global $HTACCESS_FILE, $MARKER_START, $MARKER_END;
    if (file_exists($HTACCESS_FILE)) {
        $content = file_get_contents($HTACCESS_FILE);
        $block = extractMarkedBlock($content);
        $envPresent = (strpos($block, 'is_redir_bot') !== false && strpos($block, 'SetEnvIf') !== false);
    }

    if ($total === 0 && $enabled) {
        $state = 'ready';
    } elseif ($enabled && $allHaveRules && $envPresent) {
        $state = 'ok';
    } elseif ($enabled && !$allHaveRules) {
        $state = 'outdated';
    } elseif (!$enabled) {
        $state = 'off';
    } else {
        $state = 'partial';
    }

    return [
        'enabled' => $enabled,
        'total' => $total,
        'with_rules' => $withRules,
        'all_have_rules' => $allHaveRules,
        'env_present' => $envPresent,
        'state' => $state,
    ];
}

// Обработка форм
$message = '';
$messageType = 'success';
$currentRedirects = getCurrentRedirects();
$currentSettings = getCurrentSettings();

if (isset($_POST['save_bot_settings']) || isset($_POST['apply_bot_rules'])) {
    $currentSettings['exclude_bots'] = isset($_POST['exclude_bots']);
    if (isset($_POST['apply_bot_rules'])) {
        $currentSettings['exclude_bots'] = true;
    }
    if (saveRedirects($currentRedirects, $currentSettings)) {
        $currentRedirects = getCurrentRedirects();
        $currentSettings = getCurrentSettings();
        $message = $currentSettings['exclude_bots']
            ? "Правила исключения ботов применены ко всем редиректам."
            : "Исключение ботов выключено. Редиректы перезаписаны без UA-условий.";
    } else {
        $message = "Ошибка при сохранении правил для ботов.";
        $messageType = 'error';
    }
}

// Добавление нового редиректа
if (isset($_POST['add_redirect'])) {
    $newRedirect = [
        'enabled' => true,
        'comment' => trim($_POST['comment']),
        'from_page' => trim($_POST['from_page']),
        'utm_param' => trim($_POST['utm_param']),
        'utm_value' => trim($_POST['utm_value']),
        'to_page' => trim($_POST['to_page']),
        'bots_excluded' => !empty($currentSettings['exclude_bots']),
    ];

    if ($newRedirect['from_page'] && $newRedirect['utm_param'] && $newRedirect['utm_value'] && $newRedirect['to_page']) {
        $currentRedirects[] = $newRedirect;
        if (saveRedirects($currentRedirects, $currentSettings)) {
            $message = "Редирект успешно добавлен!";
            $currentRedirects = getCurrentRedirects();
        } else {
            $message = "Ошибка при сохранении редиректа!";
            $messageType = 'error';
        }
    } else {
        $message = "Заполните все обязательные поля!";
        $messageType = 'error';
    }
}

// Обновление редиректов
if (isset($_POST['update_redirects'])) {
    $updatedRedirects = [];

    foreach ($_POST['redirects'] as $index => $redirectData) {
        $updatedRedirects[] = [
            'enabled' => isset($redirectData['enabled']),
            'comment' => trim($redirectData['comment']),
            'from_page' => trim($redirectData['from_page']),
            'utm_param' => trim($redirectData['utm_param']),
            'utm_value' => trim($redirectData['utm_value']),
            'to_page' => trim($redirectData['to_page']),
        ];
    }

    if (saveRedirects($updatedRedirects, $currentSettings)) {
        $message = "Редиректы успешно обновлены!";
        $currentRedirects = getCurrentRedirects();
    } else {
        $message = "Ошибка при обновлении редиректов!";
        $messageType = 'error';
    }
}

// Удаление редиректа
if (isset($_GET['delete'])) {
    $index = (int)$_GET['delete'];
    if (isset($currentRedirects[$index])) {
        array_splice($currentRedirects, $index, 1);
        saveRedirects($currentRedirects, $currentSettings);
    }

    header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
    exit;
}
if (isset($_GET['deleted'])) {
    $message = "Редирект успешно удалён!";
}

$botStatus = analyzeBotStatus($currentRedirects, $currentSettings);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление редиректами</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        .container { display: flex; gap: 20px; margin-top: 20px; }
        .sidebar { flex: 1; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .main { flex: 2; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        input[type="text"], textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .required { color: red; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        button:hover { background: #005a87; }
        .btn { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #545b62; }
        .btn-warning { background: #e0a800; color: #111; }
        .btn-warning:hover { background: #c69500; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .redirect-item { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 4px; border-left: 4px solid #007cba; }
        .redirect-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; gap: 10px; }
        .redirect-actions { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        .disabled { opacity: 0.6; border-left-color: #6c757d; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-actions { display: flex; gap: 10px; }
        .utm-fields { display: flex; gap: 10px; }
        .utm-fields .form-group { flex: 1; }
        small { color: #666; font-size: 12px; }
        .bot-panel { background: white; padding: 16px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 8px; }
        .status-ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-outdated { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .status-off { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .status-ready { background: #e7f3ff; color: #004085; border: 1px solid #b8daff; }
        .status-box { padding: 12px 14px; border-radius: 6px; margin-bottom: 12px; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .badge-ok { background: #28a745; color: #fff; }
        .badge-warn { background: #ffc107; color: #111; }
        .badge-off { background: #dc3545; color: #fff; }
        details { margin-top: 8px; }
        details summary { cursor: pointer; color: #005a87; font-size: 13px; }
        .bot-list { font-size: 12px; color: #444; line-height: 1.5; margin-top: 6px; }
        code { background: #eee; padding: 1px 4px; border-radius: 3px; font-size: 12px; }
        .hint { font-size: 13px; color: #555; margin-top: 8px; line-height: 1.45; }
        .inline-check { display: flex; align-items: center; gap: 8px; font-weight: bold; }
        .inline-check input { width: auto; }
        .actions-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
    </style>
    <!-- favicon -->
    <link rel="icon" type="image/png" href="/assets/favicon/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/assets/favicon/favicon.svg" />
    <link rel="shortcut icon" href="/assets/favicon/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="БФ «МДС»" />
    <link rel="manifest" href="/assets/favicon/site.webmanifest" />
    <!-- End favicon -->
</head>
<body>
    <div class="header">
        <h1>Управление редиректами</h1>
        <div class="header-actions">
            <a href="?logout" class="btn btn-secondary">Выйти</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="bot-panel">
        <h2>Исключение ботов</h2>
        <?php
            $stateClass = 'status-ready';
            if ($botStatus['state'] === 'ok') $stateClass = 'status-ok';
            if ($botStatus['state'] === 'outdated' || $botStatus['state'] === 'partial') $stateClass = 'status-outdated';
            if ($botStatus['state'] === 'off') $stateClass = 'status-off';
        ?>
        <div class="status-box <?php echo $stateClass; ?>">
            <?php if ($botStatus['state'] === 'ok'): ?>
                <strong>Статус: правила для ботов активны</strong>
                — исключение включено, условия стоят во всех редиректах
                (<?php echo (int)$botStatus['with_rules']; ?>/<?php echo (int)$botStatus['total']; ?>).
            <?php elseif ($botStatus['state'] === 'ready'): ?>
                <strong>Статус: исключение ботов включено</strong>
                — редиректов пока нет, правила применятся автоматически при добавлении.
            <?php elseif ($botStatus['state'] === 'outdated' || $botStatus['state'] === 'partial'): ?>
                <strong>Статус: правила устарели или стоят не везде</strong>
                — условия найдены в <?php echo (int)$botStatus['with_rules']; ?> из <?php echo (int)$botStatus['total']; ?> редиректов.
                Нажмите «Применить ко всем», чтобы перезаписать .htaccess актуальным списком ботов.
            <?php else: ?>
                <strong>Статус: исключение ботов выключено</strong>
                — роботы Яндекса, Google и остальные боты будут следовать 301-редиректам.
            <?php endif; ?>
        </div>

        <form method="post">
            <label class="inline-check">
                <input type="checkbox" name="exclude_bots" value="1" <?php echo $botStatus['enabled'] ? 'checked' : ''; ?>>
                Исключать ботов из всех редиректов
            </label>
            <p class="hint">
                Редирект срабатывает только для живых пользователей. Боты (особенно Яндекс Директ, YaDirectFetcher,
                Яндекс Метрика, YandexBot и остальные) остаются на исходной странице — так модерация объявлений
                и проверка посадочных не упираются в 301.
            </p>
            <div class="actions-row">
                <button type="submit" name="save_bot_settings">Сохранить настройку</button>
                <button type="submit" name="apply_bot_rules" class="btn-success">Применить правила для ботов ко всем редиректам</button>
            </div>
        </form>

        <details>
            <summary>Какие боты исключаются и как устроена подстраховка</summary>
            <div class="bot-list">
                <p><strong>Яндекс (все варианты UA):</strong> YandexBot, YandexDirect, YaDirectFetcher, YandexMetrika, YandexImages, YandexMedia, YandexNews, YandexMobileBot, YandexAdNet, YandexFavicons и любой другой UA со словом <code>Yandex</code> или <code>YaDirect</code>.</p>
                <p><strong>Также:</strong> Googlebot / AdsBot, bingbot, соцсети (Facebook, Twitter, VK, Telegram…), SEO-краулеры, AI-боты, плюс общие маркеры <code>bot</code>, <code>crawler</code>, <code>spider</code>, <code>slurp</code>, <code>fetcher</code>.</p>
                <p><strong>Три слоя в .htaccess:</strong></p>
                <ol>
                    <li><code>SetEnvIf</code> помечает бота флагом <code>is_redir_bot</code> (включая пустой User-Agent).</li>
                    <li>В каждом редиректе: <code>RewriteCond %{ENV:is_redir_bot} !=1</code>.</li>
                    <li>Дубль по User-Agent прямо в правиле + отсев пустого UA — если вдруг нет <code>mod_setenvif</code>.</li>
                </ol>
                <p>После обновления скрипта на проекте нажмите «Применить ко всем», иначе старые блоки в .htaccess останутся без новых условий.</p>
            </div>
        </details>
    </div>

    <div class="container">
        <div class="sidebar">
            <h2>Добавить новый редирект</h2>
            <form method="post">
                <div class="form-group">
                    <label>Комментарий (необязательно)</label>
                    <textarea name="comment" rows="2" placeholder="Например: Редирект с главной для Яндекс Директ"></textarea>
                </div>

                <div class="form-group">
                    <label>С какой страницы <span class="required">*</span></label>
                    <input type="text" name="from_page" placeholder="/ или /old-page" required>
                    <small>Укажите "/" для главной страницы или путь типа "/old-page"</small>
                </div>

                <div class="utm-fields">
                    <div class="form-group">
                        <label>UTM-параметр <span class="required">*</span></label>
                        <input type="text" name="utm_param" placeholder="utm_source или code" required>
                        <small>Параметр, например: utm_source</small>
                    </div>

                    <div class="form-group">
                        <label>Значение параметра <span class="required">*</span></label>
                        <input type="text" name="utm_value" placeholder="direct_yandex или redir_1" required>
                        <small>Значение, например: direct_yandex</small>
                    </div>
                </div>

                <div class="form-group">
                    <label>На какую страницу <span class="required">*</span></label>
                    <input type="text" name="to_page" placeholder="/new-page" required>
                    <small>Путь назначения, например: /profile/user-name</small>
                </div>

                <button type="submit" name="add_redirect">Добавить редирект</button>
            </form>
        </div>

        <div class="main">
            <h2>Текущие редиректы</h2>

            <?php if (empty($currentRedirects)): ?>
                <p>Редиректов пока нет. Добавьте первый редирект используя форму слева.</p>
            <?php else: ?>
                <form method="post">
                    <?php foreach ($currentRedirects as $index => $redirect): ?>
                        <div class="redirect-item <?php echo !$redirect['enabled'] ? 'disabled' : ''; ?>">
                            <div class="redirect-header">
                                <div class="form-group" style="margin-bottom:0;">
                                    <label>
                                        <input type="checkbox" name="redirects[<?php echo $index; ?>][enabled]" value="1" <?php echo $redirect['enabled'] ? 'checked' : ''; ?>>
                                        Активен
                                    </label>
                                </div>
                                <?php if (!empty($redirect['bots_excluded']) && $botStatus['enabled']): ?>
                                    <span class="badge badge-ok">боты исключены</span>
                                <?php elseif ($botStatus['enabled']): ?>
                                    <span class="badge badge-warn">нет правила для ботов</span>
                                <?php else: ?>
                                    <span class="badge badge-off">исключение выкл.</span>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label>Комментарий</label>
                                <input type="text" name="redirects[<?php echo $index; ?>][comment]" value="<?php echo htmlspecialchars($redirect['comment']); ?>" placeholder="Описание редиректа">
                            </div>

                            <div class="form-group">
                                <label>С какой страницы</label>
                                <input type="text" name="redirects[<?php echo $index; ?>][from_page]" value="<?php echo htmlspecialchars($redirect['from_page']); ?>" required>
                            </div>

                            <div class="utm-fields">
                                <div class="form-group">
                                    <label>UTM-параметр</label>
                                    <input type="text" name="redirects[<?php echo $index; ?>][utm_param]" value="<?php echo htmlspecialchars($redirect['utm_param']); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label>Значение параметра</label>
                                    <input type="text" name="redirects[<?php echo $index; ?>][utm_value]" value="<?php echo htmlspecialchars($redirect['utm_value']); ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>На какую страницу</label>
                                <input type="text" name="redirects[<?php echo $index; ?>][to_page]" value="<?php echo htmlspecialchars($redirect['to_page']); ?>" required>
                            </div>

                            <div class="redirect-actions">
                                <button type="submit" name="update_redirects">Сохранить изменения</button>
                                <a href="?delete=<?php echo $index; ?>" class="btn btn-danger" onclick="return confirm('Удалить этот редирект?')">Удалить</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
