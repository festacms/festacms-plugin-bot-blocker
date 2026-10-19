<?php
/**
 * FestaCMS — Bot Blocker: AJAX-обработчик
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../../api/Database.php';

header('Content-Type: application/json; charset=utf-8');

// Проверка CSRF
$token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    echo json_encode(['success' => false, 'error' => 'Ошибка CSRF-токена']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_rules':
            $rules = Database::select("SELECT * FROM bot_blocker_rules ORDER BY id DESC");
            echo json_encode(['success' => true, 'data' => $rules]);
            break;

        case 'get_logs':
            $logs = Database::select("SELECT * FROM bot_blocker_log ORDER BY id DESC LIMIT 200");
            echo json_encode(['success' => true, 'data' => $logs]);
            break;

        case 'add_rule':
            $type    = $_POST['type'] ?? '';
            $pattern = trim($_POST['pattern'] ?? '');
            $act     = $_POST['action_type'] ?? 'block';
            $comment = trim($_POST['comment'] ?? '');

            if (!$pattern || !in_array($type, ['ip', 'cidr', 'ua', 'referer'])) {
                throw new Exception('Неверные данные');
            }

            Database::execute(
                "INSERT INTO bot_blocker_rules (type, pattern, action, comment) VALUES (?, ?, ?, ?)",
                [$type, $pattern, $act, $comment]
            );
            echo json_encode(['success' => true]);
            break;

        case 'delete_rule':
            $id = (int)($_POST['id'] ?? 0);
            Database::execute("DELETE FROM bot_blocker_rules WHERE id = ?", [$id]);
            echo json_encode(['success' => true]);
            break;

        case 'clear_logs':
            Database::execute("TRUNCATE TABLE bot_blocker_log");
            echo json_encode(['success' => true]);
            break;

        case 'save_settings':
            $configFile = __DIR__ . '/config.runtime.php';
            
            // Читаем текущие настройки
            $current = file_exists($configFile) ? include $configFile : [];
            
            // Обновляем
            $config = [
                'enabled' => !empty($_POST['enabled']),
                'default_action' => $_POST['default_action'] ?? '404',
                'redirect_url' => $_POST['redirect_url'] ?? '/',
                'log_enabled' => !empty($_POST['log_enabled']),
                'log_skip_allowed' => false,
                'admin_ips' => array_filter(array_map('trim', explode("\n", $_POST['admin_ips'] ?? '')))
            ];

            $content = "<?php\n// Bot Blocker runtime config — auto-generated.\nreturn " . var_export($config, true) . ";\n";
            if (@file_put_contents($configFile, $content, LOCK_EX) === false) {
                throw new Exception('Не удалось записать config.runtime.php. Проверьте права на папку.');
            }
            
            // Сбрасываем кеш правил, если он есть
            $cacheFile = __DIR__ . '/rules.cache.php';
            if (file_exists($cacheFile)) @unlink($cacheFile);

            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}