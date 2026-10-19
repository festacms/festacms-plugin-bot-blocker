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
    'enabled'        => true,
    'default_action' => '404',
    'redirect_url'   => '/',
    'log_enabled'    => true,
    'admin_ips'      => [],
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

<div class="sys-block">
    <div class="sys-block-header bb-tabs-header">
        <span class="sys-block-title">Управление</span>
        <div class="bb-tabs-nav">
            <button class="bb-tab-btn active" data-tab="rules">📜 Правила</button>
            <button class="bb-tab-btn" data-tab="log">📋 Лог</button>
            <button class="bb-tab-btn" data-tab="settings">⚙️ Настройки</button>
        </div>
    </div>

    <div class="bb-tab-content active" id="tab-rules">
        <div class="bb-toolbar">
            <button class="sys-btn sys-btn-primary" onclick="openRuleModal()">➕ Добавить правило</button>
        </div>
        <div class="bb-table-wrap" id="rules-table-wrap">
            <div class="log-empty">Загрузка...</div>
        </div>
    </div>

    <div class="bb-tab-content" id="tab-log">
        <div class="bb-toolbar">
            <button class="sys-btn" onclick="clearLogs()">🗑 Очистить лог</button>
        </div>
        <div class="bb-table-wrap" id="log-table-wrap">
            <div class="log-empty">Загрузка...</div>
        </div>
    </div>

    <div class="bb-tab-content" id="tab-settings">
        <form id="settingsForm" onsubmit="saveSettings(event)">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="bb-form-group">
                <label>Статус блокировщика</label>
                <label class="bb-switch">
                    <input type="checkbox" name="enabled" value="1" <?= !empty($config['enabled']) ? 'checked' : '' ?>>
                    <span class="bb-slider"></span>
                </label>
            </div>

            <div class="bb-form-group">
                <label>Действие по умолчанию</label>
                <select name="default_action" class="bb-input">
                    <option value="404" <?= $config['default_action'] === '404' ? 'selected' : '' ?>>Отдавать 404 (Not Found)</option>
                    <option value="403" <?= $config['default_action'] === '403' ? 'selected' : '' ?>>Отдавать 403 (Forbidden)</option>
                    <option value="redirect" <?= $config['default_action'] === 'redirect' ? 'selected' : '' ?>>Редирект</option>
                </select>
            </div>

            <div class="bb-form-group">
                <label>URL для редиректа</label>
                <input type="text" name="redirect_url" class="bb-input" value="<?= htmlspecialchars($config['redirect_url']) ?>">
            </div>

            <div class="bb-form-group">
                <label>Записывать в лог?</label>
                <label class="bb-switch">
                    <input type="checkbox" name="log_enabled" value="1" <?= !empty($config['log_enabled']) ? 'checked' : '' ?>>
                    <span class="bb-slider"></span>
                </label>
            </div>

            <div class="bb-form-group">
                <label>Белый список IP (через запятую или с новой строки)</label>
                <textarea name="admin_ips" class="bb-input" rows="3"><?= htmlspecialchars(implode("\n", $config['admin_ips'])) ?></textarea>
            </div>

            <button type="submit" class="sys-btn sys-btn-primary">💾 Сохранить настройки</button>
            <div id="settings-msg" style="margin-top:10px; display:none;"></div>
        </form>
    </div>
</div>

<div class="bb-modal" id="ruleModal">
    <div class="bb-modal-content">
        <h3>Добавить правило</h3>
        <form id="ruleForm" onsubmit="saveRule(event)">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="bb-form-group">
                <label>Тип</label>
                <select name="type" class="bb-input">
                    <option value="ip">IP-адрес</option>
                    <option value="cidr">CIDR-диапазон</option>
                    <option value="ua">User-Agent</option>
                    <option value="referer">Referer</option>
                </select>
            </div>
            <div class="bb-form-group">
                <label>Значение (шаблон)</label>
                <input type="text" name="pattern" class="bb-input" required placeholder="Например: 192.168.1.1 или curl">
            </div>
            <div class="bb-form-group">
                <label>Действие</label>
                <select name="action" class="bb-input">
                    <option value="block">Заблокировать</option>
                    <option value="allow">Разрешить (Whitelist)</option>
                    <option value="log">Только логировать</option>
                </select>
            </div>
            <div class="bb-form-group">
                <label>Комментарий</label>
                <input type="text" name="comment" class="bb-input" placeholder="Зачем это правило?">
            </div>

            <div class="bb-modal-actions">
                <button type="button" class="sys-btn" onclick="closeRuleModal()">Отмена</button>
                <button type="submit" class="sys-btn sys-btn-primary">Добавить</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/bot-blocker.js"></script>
<?php include __DIR__ . '/../../admin_footer.php'; ?>