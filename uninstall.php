<?php
/**
 * FestaCMS — Bot Blocker Plugin
 * Деинсталлятор плагина
 *
 * Вызывается ядром FestaCMS при удалении плагина.
 * Удаляет таблицы, настройки и запись в реестре плагинов.
 *
 * @package    FestaCMS
 * @subpackage Plugin\BotBlocker
 * @version    1.0.0
 * @license    MIT
 */

declare(strict_types=1);

// Защита от прямого запуска
if (!defined('FESTACMS_PLUGIN_UNINSTALL')) {
    http_response_code(403);
    exit('Прямой запуск запрещён. Удаление выполняется через ядро FestaCMS.');
}

// ============================================================
// 1. Проверка окружения
// ============================================================

if (!class_exists('Database')) {
    return [
        'success' => false,
        'errors'  => ['Не найден класс Database. Плагин запущен вне FestaCMS?'],
    ];
}

// ============================================================
// 2. Параметры плагина
// ============================================================

$pluginSlug = 'bot-blocker';
$pluginName = '🛡 Bot Blocker';

$tableRules   = 'bot_blocker_rules';
$tableLog     = 'bot_blocker_log';
$tablePlugins = 'plugins';
$tableSettings = 'site_settings';

// ============================================================
// 3. Собираем статистику перед удалением
// ============================================================

$stats = [
    'rules' => 0,
    'logs'  => 0,
];

try {
    $stats['rules'] = (int) Database::fetchColumn("SELECT COUNT(*) FROM `{$tableRules}`");
} catch (Throwable $e) {
    // таблицы может не быть — это нормально
}

try {
    $stats['logs'] = (int) Database::fetchColumn("SELECT COUNT(*) FROM `{$tableLog}`");
} catch (Throwable $e) {
    // таблицы может не быть — это нормально
}

// ============================================================
// 4. Удаление таблиц
// ============================================================

$errors = [];

try {
    Database::execute("DROP TABLE IF EXISTS `{$tableRules}`");
} catch (Throwable $e) {
    $errors[] = 'Не удалось удалить таблицу правил: ' . $e->getMessage();
}

try {
    Database::execute("DROP TABLE IF EXISTS `{$tableLog}`");
} catch (Throwable $e) {
    $errors[] = 'Не удалось удалить таблицу логов: ' . $e->getMessage();
}

// ============================================================
// 5. Удаление настроек из site_settings
// ============================================================
//
// Плагин мог хранить свои настройки как отдельные ключи в site_settings.
// Удаляем всё, что начинается с bot_blocker_*.
//
try {
    Database::execute(
        "DELETE FROM `{$tableSettings}` WHERE `key` LIKE 'bot_blocker_%'"
    );
} catch (Throwable $e) {
    // Не критично — возможно, таблицы нет или ключей не было
}

// ============================================================
// 6. Удаление из реестра плагинов
// ============================================================

try {
    Database::execute(
        "DELETE FROM `{$tablePlugins}` WHERE slug = ?",
        [$pluginSlug]
    );
} catch (Throwable $e) {
    $errors[] = 'Не удалось удалить запись из реестра: ' . $e->getMessage();
}

// ============================================================
// 7. Логирование
// ============================================================

if (class_exists('Logger')) {
    try {
        Logger::action(
            'plugin_uninstall',
            "Удалён плагин {$pluginName}. "
            . "Удалено правил: {$stats['rules']}, "
            . "записей лога: {$stats['logs']}"
        );
    } catch (Throwable $e) {
        // логирование не критично
    }
}

// ============================================================
// 8. Результат
// ============================================================

if (!empty($errors)) {
    return [
        'success' => false,
        'errors'  => $errors,
        'stats'   => $stats,
        'message' => 'Плагин удалён частично. Проверьте ошибки выше.',
    ];
}

return [
    'success' => true,
    'message' => "Плагин {$pluginName} полностью удалён.",
    'stats'   => [
        'rules_deleted' => $stats['rules'],
        'logs_deleted'  => $stats['logs'],
        'tables_dropped' => [$tableRules, $tableLog],
    ],
];