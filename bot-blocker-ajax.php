<?php
/**
 * FestaCMS — Bot Blocker: AJAX-обработчик
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../../api/Database.php';

// Для экспорта мы не отдаем JSON, поэтому проверяем экшен до заголовков
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Ошибка CSRF-токена']);
    exit;
}

if ($action === 'export') {
    $format = $_GET['format'] ?? 'json';
    $rules = Database::select("SELECT type, pattern, action, comment FROM bot_blocker_rules ORDER BY id ASC");
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bot-blocker-rules.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['type', 'pattern', 'action', 'comment'], ';');
        foreach ($rules as $r) fputcsv($output, $r, ';');
        fclose($output);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="bot-blocker-rules.json"');
        echo json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {
        case 'get_rules':
            $rules = Database::select("SELECT * FROM bot_blocker_rules ORDER BY id DESC");
            echo json_encode(['success' => true, 'data' => $rules]);
            break;

        case 'get_logs':
            $where = [];
            $params = [];
            $filterIp = trim($_GET['ip'] ?? '');
            if ($filterIp !== '') {
                $where[] = "ip LIKE ?";
                $params[] = '%' . $filterIp . '%';
            }
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
            Database::execute("INSERT INTO bot_blocker_rules (type, pattern, action, comment) VALUES (?, ?, ?, ?)", [$type, $pattern, $act, $comment]);
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
                    ['ua', 'sqlmap', 'block', 'SQLMap'], ['ua', 'nikto', 'block', 'Nikto'],
                    ['ua', 'masscan', 'block', 'Masscan'], ['ua', 'nmap', 'block', 'Nmap'],
                    ['path', '/.env', 'block', '.env'], ['path', '/.git/', 'block', '.git'],
                    ['path', '/wp-login.php', 'block', 'WP login'], ['path', '/phpmyadmin', 'block', 'phpMyAdmin'],
                    ['path', '/vendor/', 'block', 'vendor'],
                ],
                'seo' => [
                    ['ua', 'semrush', 'block', 'SemrushBot'], ['ua', 'ahrefs', 'block', 'AhrefsBot'],
                    ['ua', 'mj12bot', 'block', 'MJ12bot'], ['ua', 'dotbot', 'block', 'DotBot'],
                ],
                'cli' => [
                    ['ua', 'curl', 'block', 'curl'], ['ua', 'wget', 'block', 'wget'],
                    ['ua', 'python-requests', 'block', 'Python'], ['ua', 'go-http-client', 'block', 'Go'],
                    ['ua', 'libwww-perl', 'block', 'Perl'],
                ]
            ];
            if (!isset($presets[$preset])) throw new Exception('Неизвестный пресет');
            
            $inserted = 0;
            foreach ($presets[$preset] as $rule) {
                $exists = Database::fetchColumn("SELECT COUNT(*) FROM bot_blocker_rules WHERE type = ? AND pattern = ? AND action = ?", [$rule[0], $rule[1], $rule[2]]);
                if ((int)$exists === 0) {
                    Database::execute("INSERT INTO bot_blocker_rules (type, pattern, action, comment) VALUES (?, ?, ?, ?)", $rule);
                    $inserted++;
                }
            }
            echo json_encode(['success' => true, 'inserted' => $inserted]);
            break;

        case 'import':
            if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Файл не загружен');
            }
            $file = $_FILES['import_file']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            $rules = [];

            if ($ext === 'json') {
                $data = json_decode(file_get_contents($file), true);
                if (is_array($data)) $rules = $data;
            } elseif ($ext === 'csv') {
                if (($handle = fopen($file, "r")) !== FALSE) {
                    $header = fgetcsv($handle, 1000, ";");
                    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                        if (count($data) >= 3) {
                            $rules[] = ['type'=>$data[0], 'pattern'=>$data[1], 'action'=>$data[2], 'comment'=>$data[3]??''];
                        }
                    }
                    fclose($handle);
                }
            } else {
                throw new Exception('Разрешены только JSON или CSV');
            }

            $inserted = 0;
            foreach ($rules as $r) {
                if (!in_array($r['type'], ['ip','cidr','ua','referer'])) continue;
                Database::execute("INSERT INTO bot_blocker_rules (type, pattern, action, comment) VALUES (?, ?, ?, ?)", [$r['type'], $r['pattern'], $r['action'], $r['comment']]);
                $inserted++;
            }
            echo json_encode(['success' => true, 'inserted' => $inserted]);
            break;

        case 'save_settings':
            $configFile = __DIR__ . '/config.runtime.php';
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