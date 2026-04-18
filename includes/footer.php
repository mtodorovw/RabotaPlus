<?php // includes/footer.php ?>
</main>

<footer style="border-top:1px solid var(--border-dim);padding:2rem 2rem 1.75rem;background:var(--navy-2);margin-top:auto;">
    <div style="max-width:1200px;margin:0 auto;display:grid;grid-template-columns:1fr 2fr 1fr;gap:2rem;align-items:start;">

        <!-- Brand -->
        <div style="display:flex;flex-direction:column;gap:0.35rem;">
            <span style="color:var(--gold);font-weight:700;font-size:0.95rem;letter-spacing:0.02em;">⚡ <?= SITE_NAME ?></span>
            <span style="font-size:0.75rem;color:var(--text-dim);">Платформа за услуги в България</span>
        </div>

        <!-- Links — 2-column grid -->
        <nav style="display:grid;grid-template-columns:1fr 1fr;gap:0.4rem 1.5rem;">
            <a href="<?= url('pages/terms.php') ?>"       style="color:var(--text-dim);font-size:0.78rem;text-decoration:none;white-space:nowrap;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-dim)'">Общи условия</a>
            <a href="<?= url('pages/payments.php') ?>"    style="color:var(--text-dim);font-size:0.78rem;text-decoration:none;white-space:nowrap;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-dim)'">Плащания и ескроу</a>
            <a href="<?= url('pages/user-contract.php') ?>" style="color:var(--text-dim);font-size:0.78rem;text-decoration:none;white-space:nowrap;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-dim)'">Условия за договор</a>
            <a href="<?= url('pages/disputes.php') ?>"    style="color:var(--text-dim);font-size:0.78rem;text-decoration:none;white-space:nowrap;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-dim)'">Политика за спорове</a>
            <a href="<?= url('pages/privacy.php') ?>"     style="color:var(--text-dim);font-size:0.78rem;text-decoration:none;white-space:nowrap;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-dim)'">Поверителност</a>
            <a href="<?= url('pages/disclaimer.php') ?>"  style="color:var(--text-dim);font-size:0.78rem;text-decoration:none;white-space:nowrap;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-dim)'">Отказ от отговорност</a>
            <a href="<?= url('pages/cookies.php') ?>"     style="color:var(--text-dim);font-size:0.78rem;text-decoration:none;white-space:nowrap;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-dim)'">Бисквитки</a>
            <a href="<?= url('pages/contact.php') ?>"     style="color:var(--text-dim);font-size:0.78rem;text-decoration:none;white-space:nowrap;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-dim)'">Контакти</a>
        </nav>

        <!-- Copyright + email -->
        <div style="display:flex;flex-direction:column;gap:0.35rem;align-items:flex-end;text-align:right;">
            <span style="font-size:0.78rem;color:var(--text-dim);">© <?= date('Y') ?> <?= SITE_NAME ?></span>
            <a href="mailto:support@rabotaplus.bg" style="font-size:0.78rem;color:var(--gold);text-decoration:none;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">support@rabotaplus.bg</a>
        </div>

    </div>
</footer>

<?php if (auth()): ?>
<script>
// BASE_URL already declared in header.php
if (typeof BASE_URL === 'undefined') var BASE_URL = '<?= BASE_URL ?>';
function apiUrl(p){ return BASE_URL + '/' + p; }

// ── Unread messages polling ───────────────────────────────
(function pollUnread(){
    fetch(apiUrl('api/poll.php?type=unread'))
        .then(r=>r.json()).then(d=>{
            ['nav-unread','nav-unread-m'].forEach(function(id){
                var b=document.getElementById(id);
                if(b){ b.textContent=d.count||''; d.count>0?b.classList.remove('hidden'):b.classList.add('hidden'); }
            });
        }).catch(()=>{});
    setTimeout(pollUnread, 8000);
})();

// ── Notifications ─────────────────────────────────────────
function toggleNotifPanel(){
    const isMobile = window.innerWidth <= 768;
    if (isMobile) {
        // Mobile: use the mobile panel
        const panel = document.getElementById('notif-panel-mobile');
        if (!panel) return;
        const isOpen = panel.classList.toggle('open');
        // Close other dropdowns
        document.querySelectorAll('.mobile-dropdown').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.hamburger').forEach(b => b.classList.remove('active'));
        if (isOpen) {
            loadNotificationsMobile();
            setTimeout(() => {
                document.addEventListener('click', function closeMNP(e){
                    if (!panel.contains(e.target) && !e.target.closest('.notif-btn')) {
                        panel.classList.remove('open');
                        document.removeEventListener('click', closeMNP);
                    }
                });
            }, 0);
        }
    } else {
        // Desktop
        const p = document.getElementById('notif-panel');
        const w = document.getElementById('notif-wrap');
        if (!p) return;
        const isOpen = p.classList.toggle('open');
        if (isOpen) loadNotifications();
        document.addEventListener('click', function outsideClick(e){
            if(!w || !w.contains(e.target)){ p.classList.remove('open'); document.removeEventListener('click',outsideClick); }
        });
    }
}

function loadNotificationsMobile(){
    const list = document.getElementById('notif-list-mobile');
    if (!list) return;
    list.innerHTML = '<div class="notif-loading">Зарежда...</div>';
    fetch(apiUrl('api/poll.php?type=notifications'))
        .then(r=>r.json()).then(data=>{
            if(!data.notifications || data.notifications.length===0){
                list.innerHTML = '<div class="notif-empty">Нямаш нотификации</div>'; return;
            }
            list.innerHTML = data.notifications.map(n=>`
                <a href="${n.link||'#'}" class="notif-item${n.is_read=='0'?' unread':''}" data-id="${n.id}" onclick="markRead(${n.id})">
                    <span class="notif-icon">${notifIcon(n.type)}</span>
                    <div class="notif-body">
                        <div class="notif-msg">${escHtml(n.message)}</div>
                        <div class="notif-time">${n.time_ago}</div>
                    </div>
                    ${n.is_read=='0'?'<span class="notif-dot"></span>':''}
                </a>`).join('');
            // Update mobile badge
            const unread = data.notifications.filter(n=>n.is_read=='0').length;
            ['nav-notif','nav-notif-desktop'].forEach(id=>{
                const b = document.getElementById(id);
                if(b){ b.textContent=unread||''; unread>0?b.classList.remove('hidden'):b.classList.add('hidden'); }
            });
        }).catch(()=>{ if(list) list.innerHTML = '<div class="notif-empty">Грешка при зареждане</div>'; });
}

function loadNotifications(){
    fetch(apiUrl('api/poll.php?type=notifications'))
        .then(r=>r.json()).then(data=>{
            const list = document.getElementById('notif-list');
            if(!data.notifications || data.notifications.length===0){
                list.innerHTML = '<div class="notif-empty">Нямаш нотификации</div>';
                return;
            }
            list.innerHTML = data.notifications.map(n=>`
                <a href="${n.link||'#'}" class="notif-item${n.is_read=='1'?'':' unread'}" data-id="${n.id}" onclick="markRead(${n.id})">
                    <span class="notif-icon">${notifIcon(n.type)}</span>
                    <div class="notif-body">
                        <div class="notif-msg">${escHtml(n.message)}</div>
                        <div class="notif-time">${n.time_ago}</div>
                    </div>
                    ${n.is_read=='0'?'<span class="notif-dot"></span>':''}
                </a>`).join('');
            const unread = data.notifications.filter(n=>n.is_read=='0').length;
            // Update all notification badges (mobile + desktop)
            ['nav-notif','nav-notif-desktop'].forEach(function(id){
                var b = document.getElementById(id);
                if(b){ b.textContent=unread||''; unread>0?b.classList.remove('hidden'):b.classList.add('hidden'); }
            });
        }).catch(()=>{});
}

function notifIcon(type){
    const icons = {application:'📩',accepted:'✅',escrow_release:'💰',confirmed:'🤝',dispute:'⚠️',deposit:'⬆',withdrawal:'⬇'};
    return icons[type]||'🔔';
}

function escHtml(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

function markRead(id){
    fetch(apiUrl('api/poll.php?type=mark_read&id='+id));
}

function markAllRead(){
    fetch(apiUrl('api/poll.php?type=mark_all_read'))
        .then(()=>loadNotifications()).catch(()=>{});
}

// Poll notifications every 15s
setInterval(()=>{
    fetch(apiUrl('api/poll.php?type=notif_count'))
        .then(r=>r.json()).then(d=>{
            // nav-notif = mobile bell, nav-notif-desktop = desktop bell
            ['nav-notif','nav-notif-desktop'].forEach(function(id){
                var b=document.getElementById(id);
                if(b){ b.textContent=d.count||''; d.count>0?b.classList.remove('hidden'):b.classList.add('hidden'); }
            });
        }).catch(()=>{});
}, 15000);
// Shared timeAgo function (matches PHP timeAgo() exactly)
function jsTimeAgo(createdAtStr) {
    // Parse MySQL datetime string as local time
    const parts = createdAtStr.split(/[- :]/);
    const created = new Date(parts[0], parts[1]-1, parts[2], parts[3]||0, parts[4]||0, parts[5]||0);
    const diff = Math.floor((Date.now() - created.getTime()) / 1000);
    const d = Math.max(0, diff);
    if (d < 45)     return 'току-що';
    if (d < 90)     return 'преди 1 мин.';
    if (d < 3600)   return 'преди ' + Math.round(d/60) + ' мин.';
    if (d < 5400)   return 'преди 1 час';
    if (d < 86400)  return 'преди ' + Math.round(d/3600) + ' часа';
    if (d < 172800) return 'вчера';
    if (d < 604800) return 'преди ' + Math.round(d/86400) + ' дни';
    return created.toLocaleDateString('bg-BG', {day:'2-digit',month:'2-digit',year:'numeric'});
}
// Update all relative timestamps every 30 seconds
function refreshTimeAgo() {
    document.querySelectorAll('.msg-time-rel[data-created], .time-ago-live[data-created]').forEach(el => {
        el.textContent = jsTimeAgo(el.dataset.created);
    });
}
refreshTimeAgo(); // run once immediately on load
setInterval(refreshTimeAgo, 30000);

</script>
<?php endif; ?>
</body>
</html>
