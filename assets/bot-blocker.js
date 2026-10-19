// FestaCMS — Bot Blocker Scripts

const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;

function showToast(title, message, type = 'success') {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <div class="toast-icon">${icons[type] || 'ℹ️'}</div>
        <div class="toast-body"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div>
        <button class="toast-close">✕</button><div class="toast-progress" style="animation-duration: 4s;"></div>`;
    container.appendChild(toast);
    toast.querySelector('.toast-close').onclick = () => { toast.classList.add('is-closing'); setTimeout(() => toast.remove(), 300); };
    setTimeout(() => { if (toast.parentNode) { toast.classList.add('is-closing'); setTimeout(() => toast.remove(), 300); } }, 4000);
}

document.querySelectorAll('.greet-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.greet-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.bb-tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        if (btn.dataset.tab === 'rules') loadRules();
        if (btn.dataset.tab === 'log') loadLogs();
    });
});

function loadRules() {
    const wrap = document.getElementById('rules-table-wrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="log-empty">Загрузка...</div>';
    fetch(`bot-blocker-ajax.php?action=get_rules&csrf_token=${csrfToken}`)
        .then(r => r.json()).then(res => {
            if (!res.success) throw new Error(res.error);
            if (!res.data.length) return wrap.innerHTML = '<div class="log-empty">Правил пока нет.</div>';
            let html = `<div class="sys-table-full"><table class="sys-table"><thead><tr><th>ID</th><th>Тип</th><th>Значение</th><th>Действие</th><th>Срабатываний</th><th>Комментарий</th><th></th></tr></thead><tbody>`;
            res.data.forEach(r => {
                let badgeClass = r.action === 'allow' ? 'sys-badge-ok' : (r.action === 'log' ? 'sys-badge-warn' : 'sys-badge-error');
                html += `<tr><td class="cat-id">${r.id}</td><td>${r.type}</td><td><code class="cat-slug">${r.pattern}</code></td><td><span class="sys-badge ${badgeClass}">${r.action}</span></td><td>${r.hits}</td><td>${r.comment || '-'}</td><td><button class="sys-btn greet-btn-icon" onclick="deleteRule(${r.id})">🗑</button></td></tr>`;
            });
            wrap.innerHTML = html + '</tbody></table></div>';
        }).catch(err => wrap.innerHTML = `<div class="log-empty" style="color:red">${err.message}</div>`);
}

function loadLogs() {
    const wrap = document.getElementById('log-table-wrap');
    if (!wrap) return;
    const ip = document.getElementById('filterIp')?.value || '';
    const act = document.getElementById('filterAction')?.value || '';
    wrap.innerHTML = '<div class="log-empty">Загрузка...</div>';
    fetch(`bot-blocker-ajax.php?action=get_logs&ip=${encodeURIComponent(ip)}&act=${encodeURIComponent(act)}&csrf_token=${csrfToken}`)
        .then(r => r.json()).then(res => {
            if (!res.success) throw new Error(res.error);
            if (!res.data.length) return wrap.innerHTML = '<div class="log-empty">Лог пуст.</div>';
            let html = `<div class="sys-table-full"><table class="sys-table"><thead><tr><th>Время</th><th>IP</th><th>URI</th><th>Действие</th></tr></thead><tbody>`;
            res.data.forEach(l => {
                let badgeClass = l.action === 'allow' ? 'sys-badge-ok' : (l.action === 'log' ? 'sys-badge-warn' : 'sys-badge-error');
                html += `<tr><td>${l.created_at}</td><td><code class="cat-slug">${l.ip}</code></td><td><code class="cat-slug">${l.uri}</code></td><td><span class="sys-badge ${badgeClass}">${l.action}</span></td></tr>`;
            });
            wrap.innerHTML = html + '</tbody></table></div>';
        }).catch(err => wrap.innerHTML = `<div class="log-empty" style="color:red">${err.message}</div>`);
}

function applyPreset(name) {
    if (!confirm(`Добавить правила из пресета "${name}"?`)) return;
    const form = new FormData(); form.append('action', 'apply_preset'); form.append('preset', name); form.append('csrf_token', csrfToken);
    fetch('bot-blocker-ajax.php', { method: 'POST', body: form }).then(r => r.json()).then(res => {
        if (!res.success) throw new Error(res.error);
        showToast('Успех', `Добавлено правил: ${res.inserted}`, 'success');
        loadRules();
    }).catch(err => showToast('Ошибка', err.message, 'error'));
}

function openRuleModal() { document.getElementById('ruleModal').classList.add('is-open'); }
function closeRuleModal() { document.getElementById('ruleModal').classList.remove('is-open'); }

function saveRule(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'add_rule'); form.append('action_type', form.get('action')); form.delete('action');
    fetch('bot-blocker-ajax.php', { method: 'POST', body: form }).then(r => r.json()).then(res => {
        if (!res.success) throw new Error(res.error);
        closeRuleModal(); e.target.reset(); loadRules();
    }).catch(err => showToast('Ошибка', err.message, 'error'));
}

function deleteRule(id) {
    if (!confirm('Удалить правило?')) return;
    const form = new FormData(); form.append('action', 'delete_rule'); form.append('id', id); form.append('csrf_token', csrfToken);
    fetch('bot-blocker-ajax.php', { method: 'POST', body: form }).then(r => r.json()).then(res => { if (res.success) loadRules(); });
}

function clearLogs() {
    if (!confirm('Очистить весь лог?')) return;
    const form = new FormData(); form.append('action', 'clear_logs'); form.append('csrf_token', csrfToken);
    fetch('bot-blocker-ajax.php', { method: 'POST', body: form }).then(r => r.json()).then(res => { if (res.success) loadLogs(); });
}

function saveSettings(e) {
    e.preventDefault();
    const form = new FormData(e.target); form.append('action', 'save_settings');
    fetch('bot-blocker-ajax.php', { method: 'POST', body: form }).then(r => r.json()).then(res => {
        if (!res.success) throw new Error(res.error);
        showToast('Сохранено', 'Настройки плагина обновлены.', 'success');
    }).catch(err => showToast('Ошибка', err.message, 'error'));
}

// Импорт/Экспорт
function openImportModal() { document.getElementById('importModal').classList.add('is-open'); }
function closeImportModal() { document.getElementById('importModal').classList.remove('is-open'); }

function importRules(e) {
    e.preventDefault();
    const form = new FormData(e.target); form.append('action', 'import');
    fetch('bot-blocker-ajax.php', { method: 'POST', body: form }).then(r => r.json()).then(res => {
        if (!res.success) throw new Error(res.error);
        closeImportModal(); e.target.reset(); loadRules();
        showToast('Импорт завершён', `Добавлено правил: ${res.inserted}`, 'success');
    }).catch(err => showToast('Ошибка импорта', err.message, 'error'));
}

// Проверка обновлений
function checkUpdates() {
    const resultDiv = document.getElementById('update-result');
    const versionDiv = document.getElementById('current-version');
    resultDiv.style.display = 'block'; resultDiv.style.color = '#666'; resultDiv.innerHTML = '⏳ Проверка GitHub...';
    
    // Здесь мы стучимся в ядро (plugin_update.php находится в папке уровнем выше)
    const form = new FormData();
    form.append('action', 'check'); form.append('slug', 'bot-blocker'); form.append('csrf_token', csrfToken);

    fetch('../plugin_update.php', { method: 'POST', body: form })
        .then(r => r.json()).then(res => {
            if (!res.success) throw new Error(res.error);
            versionDiv.innerHTML = `Текущая: <b>${res.data.local}</b>`;
            if (res.data.has_update) {
                resultDiv.style.color = '#d97706';
                resultDiv.innerHTML = `🎉 Доступна версия <b>${res.data.remote}</b>! <button class="sys-btn sys-btn-primary" style="margin-left:10px; padding:2px 8px; font-size:0.8rem;" onclick="doUpdate()">Установить</button>`;
            } else {
                resultDiv.style.color = '#16a34a';
                resultDiv.innerHTML = `✅ У вас последняя версия.`;
            }
        }).catch(err => { resultDiv.style.color = '#dc2626'; resultDiv.innerHTML = `❌ Ошибка: ${err.message}`; });
}

function doUpdate() {
    if (!confirm('Начать обновление? Будет создан бэкап.')) return;
    const resultDiv = document.getElementById('update-result');
    resultDiv.innerHTML = '⏳ Скачивание и установка...';
    
    const form = new FormData();
    form.append('action', 'update'); form.append('slug', 'bot-blocker'); form.append('csrf_token', csrfToken);

    fetch('../plugin_update.php', { method: 'POST', body: form })
        .then(r => r.json()).then(res => {
            if (!res.success) throw new Error(res.error);
            resultDiv.style.color = '#16a34a'; resultDiv.innerHTML = '✅ Обновлено! Перезагрузка...';
            setTimeout(() => window.location.reload(), 2000);
        }).catch(err => { resultDiv.style.color = '#dc2626'; resultDiv.innerHTML = `❌ ${err.message}`; });
}

document.addEventListener('DOMContentLoaded', () => {
    loadRules();
    if (document.getElementById('current-version')) checkUpdates();
});