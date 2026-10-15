<?php
/**
 * FestaCMS — Bot Blocker Plugin
 * Установщик плагина
 *
 * @package    FestaCMS
 * @subpackage Plugin\BotBlocker
 * @version    1.0.0
 * @license    MIT
 */

declare(strict_types=1);

// Защита от прямого запуска
if (!defined('FESTACMS_PLUGIN_INSTALL')) {
    http_response_code(403);
    exit('Прямой запуск запрещён. Установка выполняется через ядро FestaCMS.');
}

// ============================================================
// 1. Проверка окружения
// ============================================================

$errors = [];
$warnings = [];

// PHP-версия
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    $errors[] = 'Требуется PHP 7.4 или выше. Сейчас: ' . PHP_VERSION;
}

// MySQL — проверяем через класс Database (если он есть)
if (!class_exists('Database')) {
    $errors[] = 'Не найден класс Database. Плагин запущен вне FestaCMS?';
}

if (!empty($errors)) {
    return [
        'success' => false,
        'errors'  => $errors,
    ];
}

// ============================================================
// 2. Параметры плагина
// ============================================================

$pluginSlug     = 'bot-blocker';
$pluginName     = '🛡 Bot Blocker';
$pluginVersion  = '1.0.0';
$pluginAuthor   = 'FestaCMS';
$pluginType     = 'module';

// ============================================================
// 3. SQL-запросы создания таблиц
// ============================================================

$tableRules = 'bot_blocker_rules';
$tableLog   = 'bot_blocker_log';
$tablePlugins = 'plugins';

// --- Таблица правил ---
$sqlRules = "
CREATE TABLE IF NOT EXISTS `{$tableRules}` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` ENUM('ip','cidr','ua','referer') NOT NULL COMMENT 'Тип правила',
    `pattern` VARCHAR(255) NOT NULL COMMENT 'Что искать',
    `action` ENUM('block','allow','log') NOT NULL DEFAULT 'block' COMMENT 'Что делать',
    `comment` VARCHAR(255) DEFAULT NULL COMMENT 'Комментарий админа',
    `hits` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Сколько раз сработало',
    `last_hit_at` DATETIME DEFAULT NULL COMMENT 'Когда последний раз',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_type` (`type`),
    KEY `idx_action` (`action`),
    KEY `idx_hits` (`hits`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bot Blocker — правила блокировки';
";

// --- Таблица лога ---
$sqlLog = "
CREATE TABLE IF NOT EXISTS `{$tableLog}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rule_id` INT UNSIGNED DEFAULT NULL COMMENT 'ID сработавшего правила',
    `ip` VARCHAR(45) NOT NULL COMMENT 'IP-адрес посетителя',
    `ua` VARCHAR(500) DEFAULT NULL COMMENT 'User-Agent',
    `uri` VARCHAR(500) DEFAULT NULL COMMENT 'Запрошенный URI',
    `referer` VARCHAR(500) DEFAULT NULL COMMENT 'Referer',
    `action` ENUM('block','allow','log') NOT NULL COMMENT 'Что сделали',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ip` (`ip`),
    KEY `idx_created` (`created_at`),
    KEY `idx_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bot Blocker — лог срабатываний';
";

// --- Таблица plugins (создаём если нет — пока ядро не готово) ---
$sqlPlugins = "
CREATE TABLE IF NOT EXISTS `{$tablePlugins}` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(64) NOT NULL,
    `name` VARCHAR(128) NOT NULL,
    `version` VARCHAR(20) NOT NULL,
    `type` VARCHAR(20) NOT NULL DEFAULT 'module',
    `author` VARCHAR(128) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `settings` JSON DEFAULT NULL,
    `installed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `last_update_check` DATETIME DEFAULT NULL,
    `available_version` VARCHAR(20) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='FestaCMS — реестр плагинов';
";

// ============================================================
// 4. Выполнение
// ============================================================

try {
    Database::execute($sqlPlugins);
    Database::execute($sqlRules);
    Database::execute($sqlLog);
} catch (Throwable $e) {
    return [
        'success' => false,
        'errors'  => ['Ошибка создания таблиц: ' . $e->getMessage()],
    ];
}

// ============================================================
// 5. Регистрация в реестре плагинов
// ============================================================

try {
    $existing = Database::select(
        "SELECT id FROM `{$tablePlugins}` WHERE slug = ? LIMIT 1",
        [$pluginSlug]
    );

    if (!empty($existing)) {
        // Обновляем версию (переустановка)
        Database::execute(
            "UPDATE `{$tablePlugins}` 
             SET name = ?, version = ?, type = ?, author = ?, updated_at = NOW() 
             WHERE slug = ?",
            [$pluginName, $pluginVersion, $pluginType, $pluginAuthor, $pluginSlug]
        );
    } else {
        // Новая установка
        Database::execute(
            "INSERT INTO `{$tablePlugins}` 
             (slug, name, version, type, author, is_active, settings) 
             VALUES (?, ?, ?, ?, ?, 0, ?)",
            [
                $pluginSlug,
                $pluginName,
                $pluginVersion,
                $pluginType,
                $pluginAuthor,
                json_encode([
                    'auto_update_check' => true,
                    'log_retention_days' => 30,
                    'default_action'    => '404',
                ], JSON_UNESCAPED_UNICODE),
            ]
        );
    }
} catch (Throwable $e) {
    return [
        'success'  => false,
        'errors'   => ['Ошибка регистрации плагина: ' . $e->getMessage()],
        'warnings' => ['Таблицы созданы, но плагин не зарегистрирован.'],
    ];
}

// ============================================================
// 6. Логирование
// ============================================================

if (class_exists('Logger')) {
    try {
        Logger::action('plugin_install', "Установлен плагин {$pluginName} v{$pluginVersion}");
    } catch (Throwable $e) {
        // логирование не критично
    }
}

// ============================================================
// 7. Результат
// ============================================================

return [
    'success'   => true,
    'message'   => "Плагин {$pluginName} v{$pluginVersion} успешно установлен.",
    'tables'    => [$tableRules, $tableLog],
    'plugin'    => [
        'slug'    => $pluginSlug,
        'name'    => $pluginName,
        'version' => $pluginVersion,
    ],
    'next_step' => 'Активируйте плагин в админке: Плагины → Bot Blocker → Включить',
];