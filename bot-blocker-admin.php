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

$rulesCount   = Database::fetchColumn("SELECT COUNT(*) FROM bot_blocker_rules");
$logCount     = Database::fetchColumn("SELECT COUNT(*) FROM bot_blocker_log");
$blockedCount = Database::fetchColumn("SELECT SUM(hits) FROM bot_blocker_rules WHERE action = 'block'");

$configFile = __DIR__ . '/config.runtime.php';
$config = file_exists($configFile) ? include $configFile : [
    'enabled' => true, 'default_action' => '404', 'redirect_url' => '/',
    'log_enabled' => true, 'admin_ips' => []
];

include __DIR__ . '/../../admin_header.php';
renderAdminBreadcrumbs([
    ['title' => 'Плагины', 'url' => '../plugins_list.php'],
    ['title' => 'Bot Blocker'],
]);
?>

<link rel="stylesheet" href="assets/bot-blocker.css">

<div class="sys-grid">
    <div class="sys-card">
        <div class="sys-card-icon">📜</div>
        <div class="sys-card-label">Всего правил</div>
        <div class="sys-card-value"><?= number_format((int)$rulesCount, 0, '.', ' ') ?></div>
    </div>
    <div class="sys-card">
        <div class="sys-card-icon">🚫</div>
        <div class="sys-card-label">Заблокировано</div>
        <div class="sys-card-value" style="color:#dc2626;"><?= number_format((int)$blockedCount, 0, '.', ' ') ?></div>
    </div>
    <div class="sys-card">
        <div class="sys-card-icon">📝</div>
        <div class="sys-card-label">Записей в логе</div>
        <div class="sys-card-value"><?= number_format((int)$logCount, 0, '.', ' ') ?></div>
    </div>
</div>

<!-- Нативные вкладки FestaCMS -->
<div class="greet-tabs">
    <button class="greet-tab active" data-tab="rules">📜 Правила</button>
    <button class="greet-tab" data-tab="log">📋 Лог</button>
    <button class="greet-tab" data-tab="settings">⚙️ Настройки</button>
</div>

<div class="sys-block bb-tab-content active" id="tab-rules">
    <div class="sys-block-header">
        <span class="sys-block-title">Правила блокировки</span>
        <div class="sys-block-header-actions">
            <button class="sys-btn sys-btn-primary" onclick="openRuleModal()">➕ Добавить правило</button>
        </div>
    </div>
    <div id="rules-table-wrap">
        <div class="log-empty">Загрузка...</div>
    </div>
</div>

<div class="sys-block bb-tab-content" id="tab-log">
    <div class="sys-block-header">
        <span class="sys-block-title">Журнал срабатываний</span>
        <div class="sys-block-header-actions">
            <button class="sys-btn sys-btn-danger" onclick="clearLogs()">🗑 Очистить лог</button>
        </div>
    </div>
    <div id="log-table-wrap">
        <div class="log-empty">Загрузка...</div>
    </div>
</div>

<div class="sys-block bb-tab-content" id="tab-settings">
    <div class="sys-block-header">
        <span class="sys-block-title">Настройки плагина</span>
    </div>
    <!-- Используем .greet-form для сброса глобальных стилей форм -->
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
            <option value="404" <?= $config['default_action'] === '404' ? 'selected' : '' ?>>Отдавать 404 (Not Found)</option>
            <option value="403" <?= $config['default_action'] === '403' ? 'selected' : '' ?>>Отдавать 403 (Forbidden)</option>
            <option value="redirect" <?= $config['default_action'] === 'redirect' ? 'selected' : '' ?>>Редирект</option>
        </select>

        <label class="greet-label">URL для редиректа</label>
        <input type="text" name="redirect_url" class="greet-input" value="<?= htmlspecialchars($config['redirect_url']) ?>" style="margin-bottom:1rem;">

        <label class="greet-label">Белый список IP</label>
        <textarea name="admin_ips" class="greet-input" rows="3" style="margin-bottom:1rem;"><?= htmlspecialchars(implode("\n", $config['admin_ips'])) ?></textarea>

        <button type="submit" class="sys-btn sys-btn-primary">💾 Сохранить настройки</button>
        <div id="settings-msg" style="margin-top:10px; display:none;"></div>
    </form>
</div>

<!-- Нативная модалка FestaCMS -->
<div class="greet-preview-modal" id="ruleModal">
    <div class="greet-preview-backdrop" onclick="closeRuleModal()"></div>
    <div class="greet-preview-content">
        <button class="random-modal-close" onclick="closeRuleModal()">✕</button>
        <h3 class="random-modal-title">Добавить правило</h3>
        
        <form id="ruleForm" class="greet-form" onsubmit="saveRule(event)">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            
            <label class="greet-label">Тип</label>
            <select name="type" class="greet-input" style="margin-bottom:1rem;">
                <option value="ip">IP-адрес</option>
                <option value="cidr">CIDR-диапазон</option>
                <option value="ua">User-Agent</option>
                <option value="referer">Referer</option>
            </select>

            <label class="greet-label">Значение (шаблон)</label>
            <input type="text" name="pattern" class="greet-input" required placeholder="Например: 192.168.1.1 или curl" style="margin-bottom:1rem;">

            <label class="greet-label">Действие</label>
            <select name="action" class="greet-input" style="margin-bottom:1rem;">
                <option value="block">Заблокировать</option>
                <option value="allow">Разрешить (Whitelist)</option>
                <option value="log">Только логировать</option>
            </select>

            <label class="greet-label">Комментарий</label>
            <input type="text" name="comment" class="greet-input" placeholder="Зачем это правило?" style="margin-bottom:1rem;">
            
            <div class="greet-form-actions" style="border:none; padding-top:0;">
                <button type="button" class="sys-btn" onclick="closeRuleModal()">Отмена</button>
                <button type="submit" class="sys-btn sys-btn-primary" style="margin-left:auto;">Добавить</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/bot-blocker.js"></script>
<?php include __DIR__ . '/../../admin_footer.php'; ?>