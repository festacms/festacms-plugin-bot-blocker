<?php
/**
 * FestaCMS — Bot Blocker: Панель управления
 */
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../../api/Database.php';

$adminPageTitle   = '🛡 Bot Blocker — FestaCMS';
$adminPageHeading = '🛡 Bot Blocker';

$plugin = Database::select("SELECT is_active FROM plugins WHERE slug = 'bot-blocker' LIMIT 1");
if (empty($plugin) || !$plugin[0]['is_active']) {
    echo '<div class="sys-message sys-message-error">Плагин не активирован. Включите его в разделе «Плагины».</div>';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$configFile = __DIR__ . '/config.runtime.php';
$config = file_exists($configFile) ? include $configFile : [
    'enabled' => true, 'default_action' => '404', 'redirect_url' => '/',
    'log_enabled' => true, 'admin_ips' => [], 'log_retention_days' => 30
];

$days = (int)($config['log_retention_days'] ?? 30);
if ($days > 0) {
    try { Database::execute("DELETE FROM bot_blocker_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]); } catch (Throwable $e) {}
}

$rulesCount   = Database::fetchColumn("SELECT COUNT(*) FROM bot_blocker_rules");
$logCount     = Database::fetchColumn("SELECT COUNT(*) FROM bot_blocker_log");
$blockedCount = Database::fetchColumn("SELECT SUM(hits) FROM bot_blocker_rules WHERE action = 'block'");

include __DIR__ . '/../../admin_header.php';
renderAdminBreadcrumbs([
    ['title' => 'Плагины', 'url' => '../plugins_list.php'],
    ['title' => 'Bot Blocker'],
]);
?>

<link rel="stylesheet" href="assets/bot-blocker.css">

<div class="sys-grid">
    <div class="sys-card"><div class="sys-card-icon">📜</div><div class="sys-card-label">Всего правил</div><div class="sys-card-value"><?= number_format((int)$rulesCount, 0, '.', ' ') ?></div></div>
    <div class="sys-card"><div class="sys-card-icon">🚫</div><div class="sys-card-label">Заблокировано</div><div class="sys-card-value" style="color:#dc2626;"><?= number_format((int)$blockedCount, 0, '.', ' ') ?></div></div>
    <div class="sys-card"><div class="sys-card-icon">📝</div><div class="sys-card-label">Записей в логе</div><div class="sys-card-value"><?= number_format((int)$logCount, 0, '.', ' ') ?></div></div>
</div>

<div class="greet-tabs">
    <button class="greet-tab active" data-tab="rules">📜 Правила</button>
    <button class="greet-tab" data-tab="log">📋 Лог</button>
    <button class="greet-tab" data-tab="settings">⚙️ Настройки</button>
</div>

<div class="sys-block bb-tab-content active" id="tab-rules">
    <div class="sys-block-header">
        <span class="sys-block-title">Правила блокировки</span>
        <div class="sys-block-header-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="bot-blocker-ajax.php?action=export&format=json&csrf_token=<?= $csrfToken ?>" class="sys-btn" title="Скачать JSON">📥 JSON</a>
            <a href="bot-blocker-ajax.php?action=export&format=csv&csrf_token=<?= $csrfToken ?>" class="sys-btn" title="Скачать CSV">📥 CSV</a>
            <button class="sys-btn" onclick="openImportModal()">📤 Импорт</button>
            <button class="sys-btn sys-btn-primary" onclick="openRuleModal()">➕ Добавить правило</button>
        </div>
    </div>

    <div style="margin-bottom:1rem; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <span style="font-size:13px; font-weight:600; color:var(--text-light);">Быстрая установка:</span>
        <button class="sys-btn" onclick="applyPreset('scanners')">🛡 Сканеры</button>
        <button class="sys-btn" onclick="applyPreset('seo')">🔍 SEO-боты</button>
        <button class="sys-btn" onclick="applyPreset('cli')">💻 CLI-клиенты</button>
    </div>

    <div id="rules-table-wrap"><div class="log-empty">Загрузка...</div></div>
</div>

<div class="sys-block bb-tab-content" id="tab-log">
    <div class="sys-block-header">
        <span class="sys-block-title">Журнал срабатываний</span>
        <div class="sys-block-header-actions">
            <button class="sys-btn sys-btn-danger" onclick="clearLogs()">🗑 Очистить лог</button>
        </div>
    </div>
    <div style="display:flex; gap:10px; margin-bottom:1rem; flex-wrap:wrap; align-items:center;">
        <input type="text" id="filterIp" class="greet-input" placeholder="Поиск по IP..." style="max-width:200px; margin:0;">
        <select id="filterAction" class="greet-input" style="max-width:150px; margin:0;">
            <option value="">Все действия</option>
            <option value="block">Block</option>
            <option value="allow">Allow</option>
            <option value="log">Log</option>
        </select>
        <button class="sys-btn" onclick="loadLogs()">🔍 Найти</button>
    </div>
    <div id="log-table-wrap"><div class="log-empty">Загрузка...</div></div>
</div>

<div class="sys-block bb-tab-content" id="tab-settings">
    <div class="sys-block-header"><span class="sys-block-title">Настройки плагина</span></div>
    
    <!-- Блок обновления -->
    <div style="margin-bottom: 1.5rem; padding: 1rem; background: var(--bg); border: 1px solid var(--border); border-radius: 8px;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <div style="font-weight:600; font-size:0.95rem;">Версия плагина</div>
                <div id="current-version" style="font-size:0.85rem; color:var(--text-light);">Проверка...</div>
            </div>
            <div>
                <button type="button" class="sys-btn" onclick="checkUpdates()">🔄 Проверить обновления</button>
            </div>
        </div>
        <div id="update-result" style="margin-top:10px; font-size:0.9rem; display:none;"></div>
    </div>

    <form id="settingsForm" class="greet-form" onsubmit="saveSettings(event)">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="greet-switch">
            <input type="checkbox" name="enabled" id="setEnabled" value="1" <?= !empty($config['enabled']) ? 'checked' : '' ?>>
            <label class="greet-switch-slider" for="setEnabled"></label>
            <span class="greet-switch-label">Блокировщик включён</span>
        </div>
        <div class="greet-switch">
            <input type="checkbox" name="log_enabled" id="setLog" value="1" <?= !empty($config['log_enabled']) ? 'checked' : '' ?>>
            <label class="greet-switch-slider" for="setLog"></label>
            <span class="greet-switch-label">Записывать в лог</span>
        </div>

        <label class="greet-label" style="margin-top:1rem;">Действие по умолчанию</label>
        <select name="default_action" class="greet-input" style="margin-bottom:1rem;">
            <option value="404" <?= $config['default_action'] === '404' ? 'selected' : '' ?>>Отдавать 404</option>
            <option value="403" <?= $config['default_action'] === '403' ? 'selected' : '' ?>>Отдавать 403</option>
            <option value="redirect" <?= $config['default_action'] === 'redirect' ? 'selected' : '' ?>>Редирект</option>
        </select>

        <label class="greet-label">URL для редиректа</label>
        <input type="text" name="redirect_url" class="greet-input" value="<?= htmlspecialchars($config['redirect_url']) ?>" style="margin-bottom:1rem;">

        <label class="greet-label">Хранить логи (дней)</label>
        <input type="number" name="log_retention_days" class="greet-input" value="<?= (int)($config['log_retention_days'] ?? 30) ?>" min="0" max="365" style="margin-bottom:1rem; max-width:150px;">

        <label class="greet-label">Белый список IP</label>
        <textarea name="admin_ips" class="greet-input" rows="3" style="margin-bottom:1rem;"><?= htmlspecialchars(implode("\n", $config['admin_ips'])) ?></textarea>

        <button type="submit" class="sys-btn sys-btn-primary">💾 Сохранить настройки</button>
    </form>
</div>

<!-- Модалка: Добавить правило -->
<div class="greet-preview-modal" id="ruleModal">
    <div class="greet-preview-backdrop" onclick="closeRuleModal()"></div>
    <div class="greet-preview-content">
        <button class="random-modal-close" onclick="closeRuleModal()">✕</button>
        <h3 class="random-modal-title">Добавить правило</h3>
        <form id="ruleForm" class="greet-form" onsubmit="saveRule(event)">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label class="greet-label">Тип</label>
            <select name="type" class="greet-input" style="margin-bottom:1rem;">
                <option value="ip">IP-адрес</option><option value="cidr">CIDR</option><option value="ua">User-Agent</option><option value="referer">Referer</option>
            </select>
            <label class="greet-label">Значение</label>
            <input type="text" name="pattern" class="greet-input" required style="margin-bottom:1rem;">
            <label class="greet-label">Действие</label>
            <select name="action" class="greet-input" style="margin-bottom:1rem;">
                <option value="block">Заблокировать</option><option value="allow">Разрешить</option><option value="log">Логировать</option>
            </select>
            <label class="greet-label">Комментарий</label>
            <input type="text" name="comment" class="greet-input" style="margin-bottom:1rem;">
            <div class="greet-form-actions" style="border:none; padding-top:0;">
                <button type="button" class="sys-btn" onclick="closeRuleModal()">Отмена</button>
                <button type="submit" class="sys-btn sys-btn-primary" style="margin-left:auto;">Добавить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка: Импорт -->
<div class="greet-preview-modal" id="importModal">
    <div class="greet-preview-backdrop" onclick="closeImportModal()"></div>
    <div class="greet-preview-content">
        <button class="random-modal-close" onclick="closeImportModal()">✕</button>
        <h3 class="random-modal-title">Импорт правил</h3>
        <form id="importForm" class="greet-form" onsubmit="importRules(event)">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label class="greet-label">Выберите JSON или CSV файл</label>
            <input type="file" name="import_file" accept=".json,.csv" required style="margin-bottom:1rem; padding:8px; border:1px dashed var(--border); border-radius:8px; width:100%; background:var(--bg);">
            <div class="greet-form-actions" style="border:none; padding-top:0;">
                <button type="button" class="sys-btn" onclick="closeImportModal()">Отмена</button>
                <button type="submit" class="sys-btn sys-btn-primary" style="margin-left:auto;">Загрузить</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/bot-blocker.js"></script>
<?php include __DIR__ . '/../../admin_footer.php'; ?>