// FestaCMS — Bot Blocker Scripts

const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;

// Переключение вкладок
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

// Загрузка правил
function loadRules() {
    const wrap = document.getElementById('rules-table-wrap');
    if (!wrap) return;
    
    wrap.innerHTML = '<div class="log-empty">Загрузка...</div>';
    fetch(`bot-blocker-ajax.php?action=get_rules&csrf_token=${csrfToken}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            if (!res.data.length) return wrap.innerHTML = '<div class="log-empty">Правил пока нет. Установите пресет или добавьте правило.</div>';
            
            let html = `<div class="sys-table-full"><table class="sys-table"><thead><tr>
                <th>ID</th><th>Тип</th><th>Значение</th><th>Действие</th><th>Срабатываний</th><th>Комментарий</th><th></th>
            </tr></thead><tbody>`;
            
            res.data.forEach(r => {
                let badgeClass = 'sys-badge-error'; // block
                if (r.action === 'allow') badgeClass = 'sys-badge-ok';
                if (r.action === 'log') badgeClass = 'sys-badge-warn';

                html += `<tr>
                    <td class="cat-id">${r.id}</td>
                    <td>${r.type}</td>
                    <td><code class="cat-slug">${r.pattern}</code></td>
                    <td><span class="sys-badge ${badgeClass}">${r.action}</span></td>
                    <td>${r.hits}</td>
                    <td>${r.comment || '-'}</td>
                    <td><button class="sys-btn greet-btn-icon" onclick="deleteRule(${r.id})" title="Удалить">🗑</button></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            wrap.innerHTML = html;
        }).catch(err => wrap.innerHTML = `<div class="log-empty" style="color:red">${err.message}</div>`);
}

// Загрузка лога (с фильтрами)
function loadLogs() {
    const wrap = document.getElementById('log-table-wrap');
    if (!wrap) return;

    const ip = document.getElementById('filterIp')?.value || '';
    const act = document.getElementById('filterAction')?.value || '';
    
    wrap.innerHTML = '<div class="log-empty">Загрузка...</div>';
    fetch(`bot-blocker-ajax.php?action=get_logs&ip=${encodeURIComponent(ip)}&act=${encodeURIComponent(act)}&csrf_token=${csrfToken}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            if (!res.data.length) return wrap.innerHTML = '<div class="log-empty">Лог пуст или ничего не найдено.</div>';
            
            let html = `<div class="sys-table-full"><table class="sys-table"><thead><tr>
                <th>Время</th><th>IP</th><th>URI</th><th>Действие</th>
            </tr></thead><tbody>`;
            
            res.data.forEach(l => {
                let badgeClass = 'sys-badge-error';
                if (l.action === 'allow') badgeClass = 'sys-badge-ok';
                if (l.action === 'log') badgeClass = 'sys-badge-warn';

                html += `<tr>
                    <td>${l.created_at}</td>
                    <td><code class="cat-slug">${l.ip}</code></td>
                    <td><code class="cat-slug">${l.uri}</code></td>
                    <td><span class="sys-badge ${badgeClass}">${l.action}</span></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            wrap.innerHTML = html;
        }).catch(err => wrap.innerHTML = `<div class="log-empty" style="color:red">${err.message}</div>`);
}

// Применение пресета
function applyPreset(presetName) {
    if (!confirm('Добавить правила из этого пресета? Существующие дубликаты будут пропущены.')) return;
    
    const form = new FormData();
    form.append('action', 'apply_preset');
    form.append('preset', presetName);
    form.append('csrf_token', csrfToken);

    fetch('bot-blocker-ajax.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.error);
            alert(`✅ Добавлено новых правил: ${res.inserted}`);
            loadRules();
        }).catch(err => alert('Ошибка: ' + err.message));
}

// Модалка
function openRuleModal() { document.getElementById('ruleModal').classList.add('is-open'); }
function closeRuleModal() { document.getElementById('ruleModal').classList.remove('is-open'); }

// Сохранение правила
function saveRule(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    form.append('action', 'add_rule');
    form.append('action_type', form.get('action')); 
    form.delete('action');

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

// Инициализация
document.addEventListener('DOMContentLoaded', () => {
    loadRules();
});