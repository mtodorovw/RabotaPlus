<?php
header('Content-Type: text/html; charset=UTF-8');
// includes/header.php
$user    = auth();
$flash   = getFlash();
$unread  = $user ? unreadCount($user['id']) : 0;
$notifs  = $user ? unreadNotifCount($user['id']) : 0;
$cur     = $_SERVER['PHP_SELF'];
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? SITE_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/style.css') ?>">
</head>
<body>

<nav class="navbar">
    <!-- Logo -->
    <a href="<?= url('index.php') ?>" class="nav-brand">
        <span class="brand-icon">⚡</span>
        <span><?= SITE_NAME ?></span>
    </a>

    <?php if ($user): ?>
    <!-- Mobile right side: chat + notif + hamburger (nav) + profile button -->
    <div class="mobile-nav-right">
        <!-- Chat -->
        <a href="<?= url('messages/index.php') ?>" class="nav-icon-link" style="position:relative;">
            <span class="nav-icon">💬</span>
            <span class="badge<?= $unread === 0 ? ' hidden' : '' ?>" id="nav-unread-m"><?= $unread ?: '' ?></span>
        </a>
        <!-- Notifications -->
        <button class="nav-icon-link notif-btn" onclick="toggleNotifPanel()" title="Нотификации" style="position:relative;">
            <span class="nav-icon">🔔</span>
            <span class="badge<?= $notifs === 0 ? ' hidden' : '' ?>" id="nav-notif"><?= $notifs ?: '' ?></span>
        </button>
        <!-- Hamburger: nav menu (Обяви / Договори / Публикувай) — wrapped for relative positioning -->
        <div class="mob-btn-wrap">
            <button class="hamburger" id="hamburger-nav" onclick="toggleHamburger('hamburger-nav','mobile-nav-menu')" aria-label="Навигация">
                <span></span><span></span><span></span>
            </button>
            <div class="mobile-dropdown" id="mobile-nav-menu">
                <a href="<?= url('index.php') ?>" class="mobile-menu-link">🏠 Обяви</a>
                <a href="<?= url('contracts/index.php') ?>" class="mobile-menu-link">📄 Договори</a>
                <a href="<?= url('listings/create.php') ?>" class="mobile-menu-link">✏️ + Публикувай</a>
                <?php if ($user['role'] === 'admin'): ?>
                <a href="<?= url('admin/disputes.php') ?>" class="mobile-menu-link">⚙️ Админ</a>
                <?php endif; ?>
            </div>
        </div>
        <!-- Profile button — wrapped for relative positioning -->
        <div class="mob-btn-wrap">
            <button class="hamburger hamburger-profile" id="hamburger-profile" onclick="toggleHamburger('hamburger-profile','mobile-profile-menu')" aria-label="Профил">
                <img src="<?= avatarUrl($user['avatar'], $user['name']) ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;" alt="">
            </button>
            <div class="mobile-dropdown mobile-dropdown-right" id="mobile-profile-menu">
                <div class="mobile-menu-balance">Баланс: <strong><?= formatMoney((float)$user['balance']) ?></strong></div>
                <a href="<?= url('profile/edit.php') ?>" class="mobile-menu-link">👤 Профил</a>
                <a href="<?= url('profile/transactions.php') ?>" class="mobile-menu-link">💳 Транзакции</a>
                <a href="<?= url('profile/deposit.php') ?>" class="mobile-menu-link">⬆ Депозит</a>
                <a href="<?= url('profile/withdraw.php') ?>" class="mobile-menu-link">⬇ Теглене</a>
                <a href="<?= url('auth/logout.php') ?>" class="mobile-menu-link mobile-menu-logout">← Изход</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Mobile auth buttons (non-logged-in) -->
    <?php if (!$user): ?>
    <div class="mobile-nav-right">
        <a href="<?= url('auth/login.php') ?>" class="btn btn-sm" style="font-size:0.78rem;padding:0.3rem 0.65rem;">Вход</a>
        <a href="<?= url('auth/register.php') ?>" class="btn btn-sm" style="font-size:0.78rem;padding:0.3rem 0.65rem;background:var(--gold);color:var(--navy);border-color:var(--gold);">Регистрация</a>
    </div>
    <?php endif; ?>

    <!-- Desktop nav links -->
    <div class="nav-links" id="nav-links">
        <a href="<?= url('index.php') ?>" class="nav-link<?= str_contains($cur,'index') ? ' active' : '' ?>">Обяви</a>
        <?php if ($user): ?>
            <a href="<?= url('contracts/index.php') ?>" class="nav-link<?= str_contains($cur,'contracts') ? ' active' : '' ?>">Договори</a>
            <a href="<?= url('listings/create.php') ?>" class="nav-link">+ Публикувай</a>
            <a href="<?= url('messages/index.php') ?>" class="nav-link nav-icon-link<?= str_contains($cur,'messages') ? ' active' : '' ?>">
                <span class="nav-icon">💬</span>
                <span class="badge<?= $unread === 0 ? ' hidden' : '' ?>" id="nav-unread"><?= $unread ?: '' ?></span>
            </a>
            <!-- Notifications Bell (desktop) -->
            <div class="notif-wrap" id="notif-wrap">
                <button class="nav-icon-link notif-btn" onclick="toggleNotifPanel()" title="Нотификации">
                    <span class="nav-icon">🔔</span>
                    <span class="badge<?= $notifs === 0 ? ' hidden' : '' ?>" id="nav-notif-desktop"><?= $notifs ?: '' ?></span>
                </button>
                <div class="notif-panel" id="notif-panel">
                    <div class="notif-panel-header">
                        <span>Нотификации</span>
                        <button onclick="markAllRead()" class="notif-mark-all">Маркирай всички</button>
                    </div>
                    <div class="notif-list" id="notif-list">
                        <div class="notif-loading">Зарежда...</div>
                    </div>
                </div>
            </div>
            <?php if ($user['role'] === 'admin'): ?>
                <a href="<?= url('admin/disputes.php') ?>" class="nav-link admin-link">⚙️ Админ</a>
            <?php endif; ?>
            <div class="nav-user" id="nav-user-wrap">
                <button type="button" id="nav-user-btn" class="user-avatar-nav" style="background:none;cursor:pointer;border:1px solid var(--border);border-radius:40px;padding:0.35rem 0.75rem 0.35rem 0.35rem;display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;font-weight:500;color:var(--text);">
                    <img src="<?= avatarUrl($user['avatar'], $user['name']) ?>" alt="">
                    <span><?= h(explode(' ', $user['name'])[0]) ?></span>
                </button>
                <div class="nav-dropdown" id="nav-user-dropdown" style="display:none;">
                    <div class="nav-dropdown-inner">
                        <div class="dropdown-balance">Баланс: <strong><?= formatMoney((float)$user['balance']) ?></strong></div>
                        <a href="<?= url('profile/edit.php') ?>">👤 Профил</a>
                        <a href="<?= url('profile/transactions.php') ?>">💳 Транзакции</a>
                        <a href="<?= url('profile/deposit.php') ?>">⬆ Депозит</a>
                        <a href="<?= url('profile/withdraw.php') ?>">⬇ Теглене</a>
                        <a href="<?= url('auth/logout.php') ?>" class="dropdown-logout">← Изход</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <a href="<?= url('auth/login.php') ?>" class="nav-link">Вход</a>
            <a href="<?= url('auth/register.php') ?>" class="btn btn-sm">Регистрация</a>
        <?php endif; ?>
    </div>
</nav>

<!-- Notification panel for mobile (outside nav so it's not clipped) -->
<?php if ($user): ?>
<div class="notif-panel-mobile" id="notif-panel-mobile">
    <div class="notif-panel-header">
        <span>Нотификации</span>
        <button onclick="markAllRead()" class="notif-mark-all">Маркирай всички</button>
    </div>
    <div class="notif-list" id="notif-list-mobile">
        <div class="notif-loading">Зарежда...</div>
    </div>
</div>
<?php endif; ?>

<script>
// BASE_URL defined early so mobile notifications work before footer loads
var BASE_URL = '<?= BASE_URL ?>';
// ── User profile dropdown (desktop) ──────────────────────
(function() {
    function initDropdown() {
        var btn = document.getElementById('nav-user-btn');
        var dd  = document.getElementById('nav-user-dropdown');
        if (!btn || !dd) return;
        btn.addEventListener('click', function(e) {
            e.preventDefault(); e.stopPropagation();
            var isOpen = dd.style.display === 'block';
            dd.style.display = isOpen ? 'none' : 'block';
            if (!isOpen) {
                document.addEventListener('mousedown', function closeDD(e2) {
                    var wrap = document.getElementById('nav-user-wrap');
                    if (!wrap || !wrap.contains(e2.target)) {
                        dd.style.display = 'none';
                        document.removeEventListener('mousedown', closeDD);
                    }
                });
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDropdown);
    else initDropdown();
})();

// ── Mobile hamburger dropdowns ────────────────────────────
function toggleHamburger(btnId, menuId) {
    var menu = document.getElementById(menuId);
    var btn  = document.getElementById(btnId);
    var isOpen = menu.classList.contains('open');

    // Close all mobile dropdowns first
    document.querySelectorAll('.mobile-dropdown').forEach(function(m){ m.classList.remove('open'); });
    document.querySelectorAll('.hamburger').forEach(function(b){ b.classList.remove('active'); });
    // Close notif panel too
    var np = document.getElementById('notif-panel-mobile');
    if (np) np.classList.remove('open');

    if (!isOpen) {
        menu.classList.add('open');
        btn.classList.add('active');
        setTimeout(function() {
            document.addEventListener('click', function closeMenu(e2) {
                var nav = document.querySelector('.navbar');
                var m2  = document.getElementById(menuId);
                if (!nav || (!nav.contains(e2.target) && !m2.contains(e2.target))) {
                    m2.classList.remove('open');
                    document.getElementById(btnId).classList.remove('active');
                    document.removeEventListener('click', closeMenu);
                }
            });
        }, 0);
    }
}

// toggleNotifPanel is defined in footer.php and handles both mobile and desktop
</script>

<main class="main-content">
<?php if ($flash): ?>
    <div class="flash flash-<?= h($flash['type']) ?>">
        <?= h($flash['msg']) ?>
        <button onclick="this.parentElement.remove()" class="flash-close">✕</button>
    </div>
<?php endif; ?>
