// FestaCMS — Bot Blocker Scripts

const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;

// Переключение вкладок
document.querySelectorAll('.bb-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.bb-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.bb-tab-content').forEach(c => c.classList.remove('active'));
        
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');

        if (btn.dataset.tab === 'rules') loadRules();
        if (btn.dataset.tab === 'log') loadLogs();
    });
});

// Загрузка правил
function loadRules() {
    const wrap = document.getElementById('rules-table-wrap');
    if (!wrap) return;
    
    wrap.innerHTML = '<div class="log-empty">Загрузка...</div>';
    fetch(`bot-blocker-ajax.php?action=get_rules&csrf_token=${csrfToken}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            if (!res.data.length) return wrap.innerHTML = '<div class="log-empty">Правил пока нет.</div>';
            
            let html = `<table class="bb-table"><thead><tr>
                <th>ID</th><th>Тип</th><th>Значение</th><th>Действие</th><th>Срабатываний</th><th>Комментарий</th><th>Удалить</th>
            </tr></thead><tbody>`;
            
            res.data.forEach(r => {
                const badge = `<span class="bb-badge bb-badge-${r.action}">${r.action}</span>`;
                html += `<tr>
                    <td>${r.id}</td>
                    <td>${r.type}</td>
                    <td><code>${r.pattern}</code></td>
                    <td>${badge}</td>
                    <td>${r.hits}</td>
                    <td>${r.comment || '-'}</td>
                    <td><button class="sys-btn" onclick="deleteRule(${r.id})">🗑</button></td>
                </tr>`;
            });
            html += '</tbody></table>';
            wrap.innerHTML = html;
        }).catch(err => wrap.innerHTML = `<div class="log-empty" style="color:red">${err.message}</div>`);
}

// Загрузка лога
function loadLogs() {
    const wrap = document.getElementById('log-table-wrap');
    if (!wrap) return;

    wrap.innerHTML = '<div class="log-empty">Загрузка...</div>';
    fetch(`bot-blocker-ajax.php?action=get_logs&csrf_token=${csrfToken}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            if (!res.data.length) return wrap.innerHTML = '<div class="log-empty">Лог пуст.</div>';
            
            let html = `<table class="bb-table"><thead><tr>
                <th>Время</th><th>IP</th><th>URI</th><th>Действие</th>
            </tr></thead><tbody>`;
            
            res.data.forEach(l => {
                html += `<tr>
                    <td>${l.created_at}</td>
                    <td>${l.ip}</td>
                    <td><code>${l.uri}</code></td>
                    <td>${l.action}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            wrap.innerHTML = html;
        }).catch(err => wrap.innerHTML = `<div class="log-empty" style="color:red">${err.message}</div>`);
}

// Модалка добавления правила
function openRuleModal() { document.getElementById('ruleModal').classList.add('active'); }
function closeRuleModal() { document.getElementById('ruleModal').classList.remove('active'); }

// Сохранение правила
function saveRule(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    
    // Исправляем name="action" в форме, так как в AJAX он называется action_type
    form.append('action', 'add_rule');
    form.append('action_type', form.get('action')); 
    form.delete('action'); // Удаляем дубликат, чтобы не ломать роутер

    fetch('bot-blocker-ajax.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            closeRuleModal();
            e.target.reset();
            loadRules();
        }).catch(err => alert('Ошибка: ' + err.message));
}

// Удаление правила
function deleteRule(id) {
    if (!confirm('Удалить правило?')) return;
    const form = new FormData();
    form.append('action', 'delete_rule');
    form.append('id', id);
    form.append('csrf_token', csrfToken);

    fetch('bot-blocker-ajax.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(res => { if (res.success) loadRules(); });
}

// Очистка логов
function clearLogs() {
    if (!confirm('Очистить весь лог?')) return;
    const form = new FormData();
    form.append('action', 'clear_logs');
    form.append('csrf_token', csrfToken);

    fetch('bot-blocker-ajax.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(res => { if (res.success) loadLogs(); });
}

// Сохранение настроек
function saveSettings(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'save_settings');
    
    const msg = document.getElementById('settings-msg');
    msg.style.display = 'block';
    msg.style.color = '#666';
    msg.textContent = 'Сохранение...';

    fetch('bot-blocker-ajax.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            msg.style.color = '#16a34a';
            msg.textContent = '✅ Настройки сохранены!';
            setTimeout(() => msg.style.display = 'none', 3000);
        }).catch(err => {
            msg.style.color = '#dc2626';
            msg.textContent = '❌ ' + err.message;
        });
}

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', () => {
    loadRules(); // Загружаем правила по умолчанию
});