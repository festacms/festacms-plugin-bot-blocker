<?php
/**
 * FestaCMS — Bot Blocker: AJAX-обработчик
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../../api/Database.php';

header('Content-Type: application/json; charset=utf-8');

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
            $where = [];
            $params = [];
            
            // Фильтр по IP
            $filterIp = trim($_GET['ip'] ?? '');
            if ($filterIp !== '') {
                $where[] = "ip LIKE ?";
                $params[] = '%' . $filterIp . '%';
            }
            
            // Фильтр по действию
            $filterAction = $_GET['act'] ?? '';
            if (in_array($filterAction, ['block', 'allow', 'log'])) {
                $where[] = "action = ?";
                $params[] = $filterAction;
            }
            
            $sqlWhere = $where ? "WHERE " . implode(" AND ", $where) : "";
            $sql = "SELECT * FROM bot_blocker_log {$sqlWhere} ORDER BY id DESC LIMIT 200";
            
            $logs = Database::select($sql, $params);
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

        case 'apply_preset':
            $preset = $_POST['preset'] ?? '';
            
            $presets = [
                'scanners' => [
                    ['ua', 'sqlmap', 'block', 'SQLMap сканер'],
                    ['ua', 'nikto', 'block', 'Nikto сканер'],
                    ['ua', 'masscan', 'block', 'Masscan'],
                    ['ua', 'nmap', 'block', 'Nmap'],
                    ['path', '/.env', 'block', 'Сканер .env'],
                    ['path', '/.git/', 'block', 'Сканер .git'],
                    ['path', '/wp-login.php', 'block', 'Сканер WordPress'],
                    ['path', '/phpmyadmin', 'block', 'Сканер phpMyAdmin'],
                    ['path', '/vendor/', 'block', 'Сканер vendor'],
                ],
                'seo' => [
                    ['ua', 'semrush', 'block', 'SemrushBot'],
                    ['ua', 'ahrefs', 'block', 'AhrefsBot'],
                    ['ua', 'mj12bot', 'block', 'MJ12bot'],
                    ['ua', 'dotbot', 'block', 'DotBot'],
                ],
                'cli' => [
                    ['ua', 'curl', 'block', 'curl CLI'],
                    ['ua', 'wget', 'block', 'wget CLI'],
                    ['ua', 'python-requests', 'block', 'Python Requests'],
                    ['ua', 'go-http-client', 'block', 'Go HTTP Client'],
                    ['ua', 'libwww-perl', 'block', 'Perl LWP'],
                ]
            ];

            if (!isset($presets[$preset])) {
                throw new Exception('Неизвестный пресет');
            }

            $inserted = 0;
            foreach ($presets[$preset] as $rule) {
                $exists = Database::fetchColumn(
                    "SELECT COUNT(*) FROM bot_blocker_rules WHERE type = ? AND pattern = ? AND action = ?",
                    [$rule[0], $rule[1], $rule[2]]
                );
                if ((int)$exists === 0) {
                    Database::execute(
                        "INSERT INTO bot_blocker_rules (type, pattern, action, comment) VALUES (?, ?, ?, ?)",
                        [$rule[0], $rule[1], $rule[2], $rule[3]]
                    );
                    $inserted++;
                }
            }
            echo json_encode(['success' => true, 'inserted' => $inserted]);
            break;

        case 'save_settings':
            $configFile = __DIR__ . '/config.runtime.php';
            
            $current = file_exists($configFile) ? include $configFile : [];
            
            $config = [
                'enabled' => !empty($_POST['enabled']),
                'default_action' => $_POST['default_action'] ?? '404',
                'redirect_url' => $_POST['redirect_url'] ?? '/',
                'log_enabled' => !empty($_POST['log_enabled']),
                'log_skip_allowed' => false,
                'log_retention_days' => (int)($_POST['log_retention_days'] ?? 30),
                'admin_ips' => array_filter(array_map('trim', explode("\n", $_POST['admin_ips'] ?? '')))
            ];

            $content = "<?php\n// Bot Blocker runtime config — auto-generated.\nreturn " . var_export($config, true) . ";\n";
            if (@file_put_contents($configFile, $content, LOCK_EX) === false) {
                throw new Exception('Не удалось записать config.runtime.php.');
            }
            
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