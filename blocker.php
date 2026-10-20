<?php
/**
 * FestaCMS — Bot Blocker Plugin
 * Точка перехвата ботов и плохих IP
 *
 * Подключается через auto_prepend_file (в .htaccess) или
 * вручную в начале index.php, category.php и т.д.
 *
 * @package    FestaCMS
 * @subpackage Plugin\BotBlocker
 * @version    1.1.1
 * @license    MIT
 */

declare(strict_types=1);

// ============================================================
// 0. Защита от повторного подключения
// ============================================================

if (defined('FESTACMS_BOT_BLOCKER_LOADED')) {
    return;
}
define('FESTACMS_BOT_BLOCKER_LOADED', true);

// ============================================================
// 1. Не работаем в CLI (терминал, cron)
// ============================================================

if (PHP_SAPI === 'cli') {
    return;
}

// ============================================================
// 2. Определяем пути
// ============================================================

$pluginDir = __DIR__;
$siteRoot = dirname($pluginDir, 3);
$cacheFile = $pluginDir . '/rules.cache.php';
$configFile = $pluginDir . '/config.runtime.php';

// ============================================================
// 3. Загружаем runtime-конфиг
// ============================================================

$config = [
    'enabled'          => true,
    'default_action'   => '404',
    'redirect_url'     => '/',
    'log_enabled'      => true,
    'admin_ips'        => [],
    'log_skip_allowed' => false,
];

if (is_file($configFile)) {
    $loaded = @include $configFile;
    if (is_array($loaded)) {
        $config = array_merge($config, $loaded);
    }
}

if (empty($config['enabled'])) {
    return;
}

// ============================================================
// 4. Получаем данные о запросе
// ============================================================

function bb_get_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR']  ?? '',
        $_SERVER['HTTP_X_REAL_IP']        ?? '',
        $_SERVER['REMOTE_ADDR']           ?? '',
    ];

    foreach ($candidates as $ip) {
        if ($ip === '') continue;
        $first = trim(explode(',', $ip)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return '0.0.0.0';
}

function bb_ip_in_cidr(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }

    [$subnet, $mask] = explode('/', $cidr, 2);
    $mask = (int) $mask;

    if (strpos($ip, ':') !== false || strpos($subnet, ':') !== false) {
        return false;
    }

    $ipLong     = ip2long($ip);
    $subnetLong = ip2long($subnet);

    if ($ipLong === false || $subnetLong === false || $mask < 0 || $mask > 32) {
        return false;
    }

    $maskLong = $mask === 0 ? 0 : (-1 << (32 - $mask));
    return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
}

$ip      = bb_get_ip();
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER']    ?? '';
$uri     = $_SERVER['REQUEST_URI']     ?? '/';

// ============================================================
// 5. Проверка админских IP — всегда пропускаем
// ============================================================

foreach ($config['admin_ips'] as $adminIp) {
    if ($ip === $adminIp || bb_ip_in_cidr($ip, (string) $adminIp)) {
        return;
    }
}

// ============================================================
// 6. Загрузка правил (из кеша или БД)
// ============================================================

$rules = null;

if (is_file($cacheFile)) {
    $cached = @include $cacheFile;
    if (is_array($cached) && isset($cached['rules'], $cached['created_at'])) {
        if (time() - (int) $cached['created_at'] < 300) {
            $rules = $cached['rules'];
        }
    }
}

if (!is_array($rules)) {
    $rules = bb_load_rules_from_db($siteRoot);
    if (is_array($rules)) {
        bb_save_rules_cache($cacheFile, $rules);
    } else {
        return;
    }
}

/**
 * Загружает правила из БД.
 */
function bb_load_rules_from_db(string $siteRoot): ?array
{
    try {
        $dbFile = $siteRoot . '/api/Database.php';
        if (!is_file($dbFile)) return null;
        require_once $dbFile;
        if (!class_exists('Database')) return null;

        $rows = Database::select("SELECT id, type, pattern, action FROM bot_blocker_rules ORDER BY id ASC");

        // ДОБАВЛЕН 'path' ВО ВСЕ ГРУППЫ
        $grouped = [
            'allow' => ['ip' => [], 'cidr' => [], 'ua' => [], 'referer' => [], 'path' => []],
            'block' => ['ip' => [], 'cidr' => [], 'ua' => [], 'referer' => [], 'path' => []],
            'log'   => ['ip' => [], 'cidr' => [], 'ua' => [], 'referer' => [], 'path' => []],
        ];

        foreach ($rows as $row) {
            $action = $row['action'] ?? 'block';
            $type   = $row['type']   ?? 'ip';
            if (isset($grouped[$action][$type])) {
                $grouped[$action][$type][] = [
                    'id'      => (int) $row['id'],
                    'pattern' => (string) $row['pattern'],
                ];
            }
        }

        return $grouped;
    } catch (Throwable $e) {
        return null;
    }
}

function bb_save_rules_cache(string $file, array $rules): void
{
    $data = ['created_at' => time(), 'rules' => $rules];
    $content = "<?php\n// Bot Blocker cache — auto-generated. Do not edit.\nreturn " . var_export($data, true) . ";\n";
    @file_put_contents($file, $content, LOCK_EX);
}

// ============================================================
// 7. Проверка WHITELIST (allow)
// ============================================================

if (isset($rules['allow'])) {
    foreach ($rules['allow']['ip'] ?? [] as $rule) {
        if ($ip === $rule['pattern']) return;
    }
    foreach ($rules['allow']['cidr'] ?? [] as $rule) {
        if (bb_ip_in_cidr($ip, $rule['pattern'])) return;
    }
    foreach ($rules['allow']['ua'] ?? [] as $rule) {
        if ($rule['pattern'] !== '' && stripos($ua, $rule['pattern']) !== false) return;
    }
    foreach ($rules['allow']['referer'] ?? [] as $rule) {
        if ($rule['pattern'] !== '' && stripos($referer, $rule['pattern']) !== false) return;
    }
    // ПРОВЕРКА ПУТИ (ALLOW)
    foreach ($rules['allow']['path'] ?? [] as $rule) {
        if ($rule['pattern'] !== '' && stripos($uri, $rule['pattern']) !== false) return;
    }
}

// ============================================================
// 8. Проверка BLACKLIST (block)
// ============================================================

$matchedRuleId = null;
$matchedAction = null;

if (isset($rules['block'])) {
    foreach ($rules['block']['ip'] ?? [] as $rule) {
        if ($ip === $rule['pattern']) {
            $matchedRuleId = $rule['id']; $matchedAction = 'block'; break;
        }
    }
    if ($matchedRuleId === null) {
        foreach ($rules['block']['cidr'] ?? [] as $rule) {
            if (bb_ip_in_cidr($ip, $rule['pattern'])) {
                $matchedRuleId = $rule['id']; $matchedAction = 'block'; break;
            }
        }
    }
    if ($matchedRuleId === null) {
        foreach ($rules['block']['ua'] ?? [] as $rule) {
            if ($rule['pattern'] !== '' && stripos($ua, $rule['pattern']) !== false) {
                $matchedRuleId = $rule['id']; $matchedAction = 'block'; break;
            }
        }
    }
    if ($matchedRuleId === null) {
        foreach ($rules['block']['referer'] ?? [] as $rule) {
            if ($rule['pattern'] !== '' && stripos($referer, $rule['pattern']) !== false) {
                $matchedRuleId = $rule['id']; $matchedAction = 'block'; break;
            }
        }
    }
    // ПРОВЕРКА ПУТИ (BLOCK)
    if ($matchedRuleId === null) {
        foreach ($rules['block']['path'] ?? [] as $rule) {
            if ($rule['pattern'] !== '' && stripos($uri, $rule['pattern']) !== false) {
                $matchedRuleId = $rule['id']; $matchedAction = 'block'; break;
            }
        }
    }
}

// ============================================================
// 9. Проверка LOG-ONLY (log)
// ============================================================

if ($matchedRuleId === null && isset($rules['log'])) {
    foreach ($rules['log']['ip'] ?? [] as $rule) {
        if ($ip === $rule['pattern']) {
            $matchedRuleId = $rule['id']; $matchedAction = 'log'; break;
        }
    }
    if ($matchedRuleId === null) {
        foreach ($rules['log']['cidr'] ?? [] as $rule) {
            if (bb_ip_in_cidr($ip, $rule['pattern'])) {
                $matchedRuleId = $rule['id']; $matchedAction = 'log'; break;
            }
        }
    }
    if ($matchedRuleId === null) {
        foreach ($rules['log']['ua'] ?? [] as $rule) {
            if ($rule['pattern'] !== '' && stripos($ua, $rule['pattern']) !== false) {
                $matchedRuleId = $rule['id']; $matchedAction = 'log'; break;
            }
        }
    }
    if ($matchedRuleId === null) {
        foreach ($rules['log']['referer'] ?? [] as $rule) {
            if ($rule['pattern'] !== '' && stripos($referer, $rule['pattern']) !== false) {
                $matchedRuleId = $rule['id']; $matchedAction = 'log'; break;
            }
        }
    }
    // ПРОВЕРКА ПУТИ (LOG)
    if ($matchedRuleId === null) {
        foreach ($rules['log']['path'] ?? [] as $rule) {
            if ($rule['pattern'] !== '' && stripos($uri, $rule['pattern']) !== false) {
                $matchedRuleId = $rule['id']; $matchedAction = 'log'; break;
            }
        }
    }
}

// ============================================================
// 10. Ничего не совпало — пропускаем
// ============================================================

if ($matchedRuleId === null) {
    return;
}

// ============================================================
// 11. Логирование срабатывания
// ============================================================

if (!empty($config['log_enabled'])) {
    bb_log_hit($siteRoot, $matchedRuleId, $ip, $ua, $uri, $referer, $matchedAction);
}

function bb_log_hit(string $siteRoot, int $ruleId, string $ip, string $ua, string $uri, string $referer, string $action): void
{
    try {
        $dbFile = $siteRoot . '/api/Database.php';
        if (!is_file($dbFile)) return;
        require_once $dbFile;
        if (!class_exists('Database')) return;

        Database::execute(
            "INSERT INTO bot_blocker_log (rule_id, ip, ua, uri, referer, action, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$ruleId, mb_substr($ip, 0, 45), mb_substr($ua, 0, 500), mb_substr($uri, 0, 500), mb_substr($referer, 0, 500), $action]
        );

        Database::execute("UPDATE bot_blocker_rules SET hits = hits + 1, last_hit_at = NOW() WHERE id = ?", [$ruleId]);
    } catch (Throwable $e) {}
}

// ============================================================
// 12. Действие при блокировке
// ============================================================

if ($matchedAction === 'log') {
    return;
}

$defaultAction = $config['default_action'] ?? '404';

switch ($defaultAction) {
    case '403':
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Доступ запрещён.</p></body></html>';
        exit;

    case 'redirect':
        $url = $config['redirect_url'] ?? '/';
        header('Location: ' . $url, true, 302);
        exit;

    case '404':
    default:
        http_response_code(404);
        $notFoundFile = $siteRoot . '/404.php';
        if (is_file($notFoundFile)) {
            include $notFoundFile;
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1><p>Страница не найдена.</p></body></html>';
        }
        exit;
}