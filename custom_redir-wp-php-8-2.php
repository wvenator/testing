<?php

// Дебаг
error_reporting(E_ALL);
ini_set('display_errors', 1);
// ini_set('log_errors','on');
// ini_set('error_log', __DIR__ . '/main_error.log');

session_start();

// Конфигурация
$PASSWORD = 'Ux72K2ON5';
$HTACCESS_FILE = '.htaccess';
$MARKER_START = '# BEGIN Custom Redir';
$MARKER_END = '# END Custom Redir';

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

// Очистка кэша WP Rocket
if (isset($_GET['clear_cache'])) {
    if (clearWPRocketCache()) {
        $_SESSION['cache_cleared'] = true;
    } else {
        $_SESSION['cache_error'] = true;
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Функция очистки кэша WP Rocket
function clearWPRocketCache() {
    // Путь к WordPress
    $wp_load_path = findWPLoad();
    
    if (!$wp_load_path) {
        return false;
    }
    
    // Подключаем WordPress
    require_once $wp_load_path;
    
    // Проверяем, существует ли функция очистки кэша WP Rocket
    if (!function_exists('rocket_clean_domain')) {
        return false;
    }
    
    // Очищаем кэш
    try {
        rocket_clean_domain();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Поиск wp-load.php
function findWPLoad() {
    $paths = [
        'wp-load.php',
        'wp/wp-load.php',
        '../wp-load.php',
        '../../wp-load.php',
        '../../../wp-load.php',
        'wordpress/wp-load.php'
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return false;
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
        // Разбиваем на отдельные редиректы
        $redirectBlocks = explode('</IfModule>', $blockContent);
        
        foreach ($redirectBlocks as $block) {
            if (trim($block)) {
                $redirect = parseRedirectBlock($block . '</IfModule>');
                if ($redirect) {
                    $redirects[] = $redirect;
                }
            }
        }
    }
    
    return $redirects;
}

// Парсинг блока редиректа
function parseRedirectBlock($block) {
    $redirect = [
        'enabled' => true,
        'comment' => '',
        'from_page' => '',
        'utm_param' => '',
        'utm_value' => '',
        'to_page' => ''
    ];

    // Проверяем, полностью ли блок закомментирован
    $lines = explode("\n", $block);
    $all_commented = true;
    foreach ($lines as $line) {
        if (trim($line) !== '' && strpos(trim($line), '#') !== 0) {
            $all_commented = false;
            break;
        }
    }
    $redirect['enabled'] = !$all_commented;

    // Извлекаем комментарий (первая строка после маркера)
    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') && !str_starts_with($line, '# <IfModule')) {
            $redirect['comment'] = trim(substr($line, 1));
            break;
        }
    }

    $block_clean = preg_replace('/^\s*#\s?/', '', $block);

    // Извлекаем from_page
    if (preg_match('/RewriteCond\s+%\{REQUEST_URI\}\s+\^\/\?\$/', $block_clean)) {
        $redirect['from_page'] = '/';
    } elseif (preg_match('/RewriteCond\s+%\{REQUEST_URI\}\s+\^\/([^\s]*)\/?\$/', $block_clean, $matches)) {
        $redirect['from_page'] = '/' . rtrim($matches[1], '?');
    }

    // Извлекаем UTM параметр и значение
    if (preg_match('/RewriteCond\s+%\{QUERY_STRING\}\s+\(\^\|&\)([^=]+)=([^\(&]+)/', $block_clean, $matches)) {
        $redirect['utm_param'] = trim($matches[1]);
        $redirect['utm_value'] = trim($matches[2]);
    }

    // Извлекаем to_page
    if (preg_match('/RewriteRule\s+[^\s]+\s+([^\s\?]+)/', $block_clean, $matches)) {
        $redirect['to_page'] = $matches[1];
    }

    return $redirect;
}

// Генерация блока редиректа
function generateRedirectBlock($redirect) {
    // Определяем from_page для регулярного выражения
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
    if ($redirect['comment']) {
        $block .= "# {$redirect['comment']}\n";
    }

    $lines = [
        "<IfModule mod_rewrite.c>",
        "RewriteEngine On",
        "RewriteCond %{REQUEST_URI} {$fromPageRegex} [NC]",
        "RewriteCond %{QUERY_STRING} (^|&){$utmParam}={$utmValue}(&|$) [NC]",
        "RewriteRule {$rewriteRuleFrom} {$toPage}?%{QUERY_STRING} [R=301,L]",
        "</IfModule>"
    ];

    if (!$redirect['enabled']) {
        $lines = array_map(function($line) {
            return "# " . $line;
        }, $lines);
    }

    $block .= implode("\n", $lines);
    return rtrim($block);
}

// Сохранение редиректов
function saveRedirects($redirects) {
    global $HTACCESS_FILE, $MARKER_START, $MARKER_END;
    
    $blocks = [];
    foreach ($redirects as $redirect) {
        $blocks[] = generateRedirectBlock($redirect);
    }

    $newBlock =
        $MARKER_START . "\n" .
        implode("\n\n", $blocks) . "\n" . $MARKER_END;
    
    if (!file_exists($HTACCESS_FILE)) {
        file_put_contents($HTACCESS_FILE, $newBlock);
        return true;
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

// Обработка форм
$message = '';
$currentRedirects = getCurrentRedirects();

// Добавление нового редиректа
if (isset($_POST['add_redirect'])) {
    $newRedirect = [
        'enabled' => true,
        'comment' => trim($_POST['comment']),
        'from_page' => trim($_POST['from_page']),
        'utm_param' => trim($_POST['utm_param']),
        'utm_value' => trim($_POST['utm_value']),
        'to_page' => trim($_POST['to_page'])
    ];
    
    if ($newRedirect['from_page'] && $newRedirect['utm_param'] && $newRedirect['utm_value'] && $newRedirect['to_page']) {
        $currentRedirects[] = $newRedirect;
        if (saveRedirects($currentRedirects)) {
            $message = "Редирект успешно добавлен!";
        } else {
            $message = "Ошибка при сохранении редиректа!";
        }
    } else {
        $message = "Заполните все обязательные поля!";
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
            'to_page' => trim($redirectData['to_page'])
        ];
    }
    
    if (saveRedirects($updatedRedirects)) {
        $message = "Редиректы успешно обновлены!";
        $currentRedirects = $updatedRedirects;
    } else {
        $message = "Ошибка при обновлении редиректов!";
    }
}

// Удаление редиректа
if (isset($_GET['delete'])) {
    $index = (int)$_GET['delete'];
    if (isset($currentRedirects[$index])) {
        array_splice($currentRedirects, $index, 1);
        saveRedirects($currentRedirects);
    }

    // Очищаем параметр из URL
    header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
    exit;
}
if (isset($_GET['deleted'])) {
    $message = "Редирект успешно удалён!";
}

// Сообщения об очистке кэша
if (isset($_SESSION['cache_cleared'])) {
    $message = "✅ Кэш сайта успешно очищен!";
    unset($_SESSION['cache_cleared']);
} elseif (isset($_SESSION['cache_error'])) {
    $message = "❌ Ошибка при очистке кэша сайта! Проверьте, что WP Rocket активирован.";
    unset($_SESSION['cache_error']);
}
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
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .redirect-item { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 4px; border-left: 4px solid #007cba; }
        .redirect-header { display: flex; justify-content: between; align-items: center; margin-bottom: 10px; }
        .redirect-actions { display: flex; gap: 10px; margin-top: 10px; }
        .disabled { opacity: 0.6; border-left-color: #6c757d; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-actions { display: flex; gap: 10px; }
        .utm-fields { display: flex; gap: 10px; }
        .utm-fields .form-group { flex: 1; }
        small { color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Управление редиректами</h1>
        <div class="header-actions">
            <a href="?clear_cache" class="btn btn-success" onclick="return confirm('Очистить кэш сайта?')">Очистить кэш</a>
            <a href="?logout" class="btn btn-secondary">Выйти</a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="message success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
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