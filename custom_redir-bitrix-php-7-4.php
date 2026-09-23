<?php
/**
 * Панель управления UTM-редиректами. zdoroviymalysh.ru
 * Адаптация версии МДС. Отличия, критичные для ЗМ:
 *  1. PHP 7.4 — убран str_starts_with() (функция PHP 8, давала бы фатал).
 *  2. Блок редиректов вставляется ПЕРЕД якорем "Options -Indexes", т.е. выше
 *     catch-all Битрикса (RewriteRule ^(.*)$ /bitrix/urlrewrite.php [L]).
 *     Исходная версия дописывала блок в конец файла — на Битриксе такие
 *     редиректы не срабатывают вообще.
 *  3. Перед каждой записью — бэкап .htaccess ВНЕ вебрута (в самом .htaccess
 *     лежит блоклист IP и правила закрытия ПДн, публично его класть нельзя).
 *  4. После записи — проверка сайта; при ответе != 200 автоматический откат.
 *     Синтаксическая ошибка в .htaccess роняет весь сайт в 500.
 *  5. Вставка через substr_replace вместо preg_replace: в блоке есть "\" и "$",
 *     которые preg_replace трактует как backreference и портит правила.
 *  6. Входные данные чистятся от переводов строк — иначе можно случайно
 *     инжектировать произвольную директиву в .htaccess.
 */

session_start();

// ── Конфигурация ────────────────────────────────────────────────────────────
$PASSWORD      = 'Ux72K2ON5';
$HTACCESS_FILE = '/var/www/u3647392/data/www/public_html/.htaccess';
$BACKUP_DIR    = '/var/www/u3647392/data/htaccess_backups';   // вне public_html
$HEALTH_URL    = 'https://zdoroviymalysh.ru/';
$ANCHOR        = 'Options -Indexes';   // блок ставится перед этой строкой
$KEEP_BACKUPS  = 30;
$MARKER_START  = '# BEGIN Custom Redir';
$MARKER_END    = '# END Custom Redir';
$SELF          = basename(__FILE__);

header('X-Robots-Tag: noindex, nofollow', true);

// ── Авторизация ─────────────────────────────────────────────────────────────
function isAuthenticated() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

if (isset($_POST['password'])) {
    if (hash_equals($PASSWORD, (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
    } else {
        $error = "Неверный пароль";
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = array();
    session_destroy();
    header('Location: ' . $SELF);
    exit;
}

if (!isAuthenticated()) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Авторизация</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 400px; margin: 100px auto; padding: 20px; }
            .login-form { background: #f9f9f9; padding: 30px; border-radius: 8px; }
            input[type="password"] { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
            button { background: #e2231a; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
            .error { color: red; margin-bottom: 15px; }
        </style>
    </head>
    <body>
        <div class="login-form">
            <h2>Авторизация</h2>
            <?php if (isset($error)) echo "<div class='error'>" . htmlspecialchars($error) . "</div>"; ?>
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

// ── Безопасность записи в .htaccess ─────────────────────────────────────────

/** Убирает переводы строк и лишние пробелы: защита от инжекта директив. */
function zmClean($v) {
    $v = str_replace(array("\r", "\n", "\t"), ' ', (string)$v);
    return trim(preg_replace('/\s+/', ' ', $v));
}

/** Путь без пробелов — иначе директива RewriteRule развалится. */
function zmCleanPath($v) {
    return str_replace(' ', '', zmClean($v));
}

/** Бэкап .htaccess вне вебрута. Возвращает путь к копии или false. */
function zmBackupHtaccess(&$err = null) {
    global $HTACCESS_FILE, $BACKUP_DIR, $KEEP_BACKUPS;

    if (!is_dir($BACKUP_DIR) && !@mkdir($BACKUP_DIR, 0700, true)) {
        $err = 'Не удалось создать каталог бэкапов: ' . $BACKUP_DIR;
        return false;
    }
    $dst = $BACKUP_DIR . '/htaccess-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6);
    if (!@copy($HTACCESS_FILE, $dst)) {
        $err = 'Не удалось сделать бэкап .htaccess — запись отменена';
        return false;
    }
    $files = glob($BACKUP_DIR . '/htaccess-*');
    if ($files && count($files) > $KEEP_BACKUPS) {
        sort($files);
        foreach (array_slice($files, 0, count($files) - $KEEP_BACKUPS) as $old) @unlink($old);
    }
    return $dst;
}

/**
 * HTTP-код сайта. Системный /usr/bin/curl: у PHP-curl на этом сервере
 * старый OpenSSL 1.0.2k, который не проверяет часть цепочек сертификатов.
 */
function zmSiteHttpCode() {
    global $HEALTH_URL;
    $out = array(); $rc = null;
    @exec('/usr/bin/curl -s -o /dev/null -m 15 -w ' . escapeshellarg('%{http_code}')
          . ' ' . escapeshellarg($HEALTH_URL), $out, $rc);
    return (int) trim(implode('', $out));
}

/**
 * Подстановка блока без preg_replace.
 * Есть маркеры — заменяем между ними. Нет — вставляем ПЕРЕД якорем.
 * Якоря нет — false (лучше отказаться, чем дописать в конец, где Битрикс).
 */
function zmReplaceBlock($content, $newBlock, &$err = null) {
    global $MARKER_START, $MARKER_END, $ANCHOR;

    $s = strpos($content, $MARKER_START);
    if ($s !== false) {
        $e = strpos($content, $MARKER_END, $s);
        if ($e === false) {
            $err = 'Найден только открывающий маркер — .htaccess повреждён, запись отменена';
            return false;
        }
        $e += strlen($MARKER_END);
        return substr($content, 0, $s) . $newBlock . substr($content, $e);
    }

    $a = strpos($content, $ANCHOR);
    if ($a === false) {
        $err = 'В .htaccess не найден якорь "' . $ANCHOR . '". Блок обязан стоять '
             . 'выше правил Битрикса, поэтому запись отменена.';
        return false;
    }
    return substr($content, 0, $a) . $newBlock . "\n\n" . substr($content, $a);
}

// ── Чтение / разбор ─────────────────────────────────────────────────────────
function getCurrentRedirects() {
    global $HTACCESS_FILE, $MARKER_START, $MARKER_END;

    $redirects = array();
    if (!file_exists($HTACCESS_FILE)) return $redirects;

    $content = file_get_contents($HTACCESS_FILE);
    $s = strpos($content, $MARKER_START);
    if ($s === false) return $redirects;
    $e = strpos($content, $MARKER_END, $s);
    if ($e === false) return $redirects;

    $blockContent = substr($content, $s + strlen($MARKER_START), $e - $s - strlen($MARKER_START));

    foreach (explode('</IfModule>', $blockContent) as $block) {
        if (trim($block) === '') continue;
        $redirect = parseRedirectBlock($block . '</IfModule>');
        if ($redirect) $redirects[] = $redirect;
    }
    return $redirects;
}

function parseRedirectBlock($block) {
    $redirect = array(
        'enabled' => true, 'comment' => '', 'from_page' => '',
        'utm_param' => '', 'utm_value' => '', 'to_page' => ''
    );

    $lines = explode("\n", $block);

    // Блок целиком закомментирован => выключен
    $all_commented = true;
    foreach ($lines as $line) {
        if (trim($line) !== '' && strpos(trim($line), '#') !== 0) { $all_commented = false; break; }
    }
    $redirect['enabled'] = !$all_commented;

    // Комментарий — первая '#'-строка, которая не является директивой.
    // (В оригинале для выключенных блоков сюда попадало "RewriteEngine On".)
    $directives = array('<IfModule', '</IfModule', 'RewriteEngine', 'RewriteCond', 'RewriteRule');
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') !== 0) continue;
        $body = ltrim(substr($line, 1));
        $isDirective = false;
        foreach ($directives as $d) {
            if (strpos($body, $d) === 0) { $isDirective = true; break; }
        }
        if ($isDirective) continue;
        $redirect['comment'] = trim($body);
        break;
    }

    $block_clean = preg_replace('/^[ \t]*#[ ]?/m', '', $block);

    if (preg_match('/RewriteCond\s+%\{REQUEST_URI\}\s+\^\/\?\$/', $block_clean)) {
        $redirect['from_page'] = '/';
    } elseif (preg_match('/RewriteCond\s+%\{REQUEST_URI\}\s+\^\/(\S*?)\\\\?\/\?\$/', $block_clean, $m)) {
        $redirect['from_page'] = '/' . str_replace('\\', '', rtrim($m[1], '?'));
    }

    if (preg_match('/RewriteCond\s+%\{QUERY_STRING\}\s+\(\^\|&\)([^=]+)=([^\(&]+)/', $block_clean, $m)) {
        $redirect['utm_param'] = str_replace('\\', '', trim($m[1]));
        $redirect['utm_value'] = str_replace('\\', '', trim($m[2]));
    }

    if (preg_match('/RewriteRule\s+\S+\s+([^\s\?]+)/', $block_clean, $m)) {
        $redirect['to_page'] = $m[1];
    }

    return $redirect;
}

// ── Генерация ───────────────────────────────────────────────────────────────
function generateRedirectBlock($redirect) {
    $fromPage = trim($redirect['from_page'], '/');
    if ($fromPage === '') {
        $fromPageRegex   = '^/?$';
        $rewriteRuleFrom = '^$';
    } else {
        $fromPageRegex   = '^/' . preg_quote($fromPage) . '/?$';
        $rewriteRuleFrom = '^' . preg_quote($fromPage) . '/?$';
    }

    $utmParam = preg_quote($redirect['utm_param']);
    $utmValue = preg_quote($redirect['utm_value']);
    $toPage   = $redirect['to_page'];

    $block = '';
    if ($redirect['comment'] !== '') {
        $block .= "# " . $redirect['comment'] . "\n";
    }

    $lines = array(
        "<IfModule mod_rewrite.c>",
        "RewriteEngine On",
        "RewriteCond %{REQUEST_URI} {$fromPageRegex} [NC]",
        "RewriteCond %{QUERY_STRING} (^|&){$utmParam}={$utmValue}(&|\$) [NC]",
        "RewriteRule {$rewriteRuleFrom} {$toPage}?%{QUERY_STRING} [R=301,L]",
        "</IfModule>"
    );

    if (!$redirect['enabled']) {
        foreach ($lines as $i => $line) { $lines[$i] = "# " . $line; }
    }

    return rtrim($block . implode("\n", $lines));
}

// ── Сохранение ──────────────────────────────────────────────────────────────
function saveRedirects($redirects, &$err = null) {
    global $HTACCESS_FILE, $MARKER_START, $MARKER_END;

    $blocks = array();
    foreach ($redirects as $r) $blocks[] = generateRedirectBlock($r);

    $newBlock = $MARKER_START . "\n" . implode("\n\n", $blocks) . "\n" . $MARKER_END;

    if (!file_exists($HTACCESS_FILE) || !is_writable($HTACCESS_FILE)) {
        $err = '.htaccess не найден или недоступен для записи';
        return false;
    }
    $content = file_get_contents($HTACCESS_FILE);
    if ($content === false) { $err = 'Не удалось прочитать .htaccess'; return false; }

    $new = zmReplaceBlock($content, $newBlock, $err);
    if ($new === false) return false;

    $backup = zmBackupHtaccess($err);
    if ($backup === false) return false;

    if (@file_put_contents($HTACCESS_FILE, $new) === false) {
        $err = 'Ошибка записи в .htaccess';
        return false;
    }

    // Проверка живости и авто-откат
    $code = zmSiteHttpCode();
    if ($code !== 200) {
        @copy($backup, $HTACCESS_FILE);
        $err = 'Сайт ответил HTTP ' . $code . ' после изменения — правка АВТОМАТИЧЕСКИ ОТКАЧЕНА '
             . '(бэкап: ' . basename($backup) . '). .htaccess возвращён в рабочее состояние.';
        return false;
    }
    return true;
}

// ── Обработка форм ──────────────────────────────────────────────────────────
$message = '';
$isError = false;
$currentRedirects = getCurrentRedirects();

if (isset($_POST['add_redirect'])) {
    $newRedirect = array(
        'enabled'   => true,
        'comment'   => zmClean($_POST['comment']),
        'from_page' => zmCleanPath($_POST['from_page']),
        'utm_param' => zmCleanPath($_POST['utm_param']),
        'utm_value' => zmCleanPath($_POST['utm_value']),
        'to_page'   => zmCleanPath($_POST['to_page'])
    );

    if ($newRedirect['from_page'] && $newRedirect['utm_param'] && $newRedirect['utm_value'] && $newRedirect['to_page']) {
        $candidate = $currentRedirects;
        $candidate[] = $newRedirect;
        $err = null;
        if (saveRedirects($candidate, $err)) {
            $message = "Редирект успешно добавлен!";
            $currentRedirects = $candidate;
        } else {
            $message = "Ошибка: " . $err; $isError = true;
        }
    } else {
        $message = "Заполните все обязательные поля!"; $isError = true;
    }
}

if (isset($_POST['update_redirects']) && isset($_POST['redirects']) && is_array($_POST['redirects'])) {
    $updatedRedirects = array();
    foreach ($_POST['redirects'] as $redirectData) {
        $updatedRedirects[] = array(
            'enabled'   => isset($redirectData['enabled']),
            'comment'   => zmClean($redirectData['comment']),
            'from_page' => zmCleanPath($redirectData['from_page']),
            'utm_param' => zmCleanPath($redirectData['utm_param']),
            'utm_value' => zmCleanPath($redirectData['utm_value']),
            'to_page'   => zmCleanPath($redirectData['to_page'])
        );
    }
    $err = null;
    if (saveRedirects($updatedRedirects, $err)) {
        $message = "Редиректы успешно обновлены!";
        $currentRedirects = $updatedRedirects;
    } else {
        $message = "Ошибка: " . $err; $isError = true;
    }
}

if (isset($_GET['delete'])) {
    $index = (int)$_GET['delete'];
    if (isset($currentRedirects[$index])) {
        $candidate = $currentRedirects;
        array_splice($candidate, $index, 1);
        $err = null;
        if (saveRedirects($candidate, $err)) {
            header('Location: ' . $SELF . '?deleted=1');
            exit;
        }
        header('Location: ' . $SELF . '?failed=' . urlencode($err));
        exit;
    }
    header('Location: ' . $SELF);
    exit;
}
if (isset($_GET['deleted'])) { $message = "Редирект успешно удалён!"; }
if (isset($_GET['failed']))  { $message = "Ошибка: " . $_GET['failed']; $isError = true; }
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Управление редиректами — БФ «ЗМ»</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        .container { display: flex; gap: 20px; margin-top: 20px; flex-wrap: wrap; }
        .sidebar { flex: 1 1 320px; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .main { flex: 2 1 480px; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; }
        input[type="text"], textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .required { color: red; }
        button, .btn { background: #e2231a; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        button:hover, .btn:hover { background: #c01c14; }
        .btn-danger { background: #dc3545; } .btn-danger:hover { background: #c82333; }
        .btn-secondary { background: #6c757d; } .btn-secondary:hover { background: #545b62; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .redirect-item { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 4px; border-left: 4px solid #e2231a; }
        .redirect-actions { display: flex; gap: 10px; margin-top: 10px; }
        .disabled { opacity: 0.6; border-left-color: #6c757d; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .utm-fields { display: flex; gap: 10px; flex-wrap: wrap; }
        .utm-fields .form-group { flex: 1 1 140px; }
        small { color: #666; font-size: 12px; }
        .note { background: #fff8e1; border: 1px solid #ffe9a8; padding: 10px; border-radius: 4px; font-size: 13px; color: #6b5900; margin-bottom: 12px; }
        code { background: #eee; padding: 1px 4px; border-radius: 3px; }
    </style>
    <link rel="icon" type="image/png" href="/bitrix/templates/simai.fund/favicon/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/bitrix/templates/simai.fund/favicon/favicon.svg" />
    <link rel="shortcut icon" href="/bitrix/templates/simai.fund/favicon/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/bitrix/templates/simai.fund/favicon/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="БФ «ЗМ»" />
</head>
<body>
    <div class="header">
        <h1>Управление редиректами</h1>
        <div class="header-actions">
            <a href="?logout" class="btn btn-secondary">Выйти</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message <?php echo $isError ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="note">
        Правила пишутся в <code>.htaccess</code> между маркерами <code>BEGIN/END Custom Redir</code>, выше правил Битрикса.
        Перед каждой записью делается бэкап, после записи — проверка сайта: если он ответит не 200, правка откатывается автоматически.
        Редирект <code>/help_ab/vasilenko-matvey/</code> (без UTM) остаётся в <code>.htaccess</code> вручную — он не описывается этой формой.
    </div>

    <div class="container">
        <div class="sidebar">
            <h2>Добавить новый редирект</h2>
            <form method="post">
                <div class="form-group">
                    <label>Комментарий (необязательно)</label>
                    <textarea name="comment" rows="2" placeholder="Например: Редирект с главной для РСЯ"></textarea>
                </div>
                <div class="form-group">
                    <label>С какой страницы <span class="required">*</span></label>
                    <input type="text" name="from_page" placeholder="/ или /how-help" required>
                    <small>"/" — главная, либо путь вида "/how-help"</small>
                </div>
                <div class="utm-fields">
                    <div class="form-group">
                        <label>UTM-параметр <span class="required">*</span></label>
                        <input type="text" name="utm_param" placeholder="utm_campaign" required>
                    </div>
                    <div class="form-group">
                        <label>Значение <span class="required">*</span></label>
                        <input type="text" name="utm_value" placeholder="liz_rsy" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>На какую страницу <span class="required">*</span></label>
                    <input type="text" name="to_page" placeholder="/help_ab/shilovskaya-liza/" required>
                </div>
                <button type="submit" name="add_redirect">Добавить редирект</button>
            </form>
        </div>

        <div class="main">
            <h2>Текущие редиректы</h2>
            <?php if (empty($currentRedirects)): ?>
                <p>Редиректов пока нет. Добавьте первый, используя форму слева.</p>
            <?php else: ?>
                <form method="post">
                    <?php foreach ($currentRedirects as $index => $redirect): ?>
                        <div class="redirect-item <?php echo !$redirect['enabled'] ? 'disabled' : ''; ?>">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="redirects[<?php echo $index; ?>][enabled]" value="1" <?php echo $redirect['enabled'] ? 'checked' : ''; ?>>
                                    Активен
                                </label>
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
                                    <label>Значение</label>
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
