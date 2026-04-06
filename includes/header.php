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
    <a href="<?= url('index.php') ?>" class="nav-brand">
        <span class="brand-icon">⚡</span>
        <span><?= SITE_NAME ?></span>
    </a>
    <div class="nav-links">
        <a href="<?= url('index.php') ?>" class="nav-link<?= str_contains($cur,'index') ? ' active' : '' ?>">Обяви</a>
        <?php if ($user): ?>
            <a href="<?= url('contracts/index.php') ?>" class="nav-link<?= str_contains($cur,'contracts') ? ' active' : '' ?>">Договори</a>
            <a href="<?= url('listings/create.php') ?>" class="nav-link">+ Публикувай</a>
            <a href="<?= url('messages/index.php') ?>" class="nav-link nav-icon-link<?= str_contains($cur,'messages') ? ' active' : '' ?>">
                <span class="nav-icon">💬</span>
                <?php if ($unread > 0): ?><span class="badge" id="nav-unread"><?= $unread ?></span><?php else: ?><span class="badge hidden" id="nav-unread"></span><?php endif; ?>
            </a>
            <!-- Notifications Bell -->
            <div class="notif-wrap" id="notif-wrap">
                <button class="nav-icon-link notif-btn" onclick="toggleNotifPanel()" title="Нотификации">
                    <span class="nav-icon">🔔</span>
                    <span class="badge<?= $notifs === 0 ? ' hidden' : '' ?>" id="nav-notif"><?= $notifs > 0 ? $notifs : '' ?></span>
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
<script>
(function() {
    function initDropdown() {
        var btn = document.getElementById('nav-user-btn');
        var dd  = document.getElementById('nav-user-dropdown');
        if (!btn || !dd) return;

        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var isOpen = dd.style.display === 'block';
            dd.style.display = isOpen ? 'none' : 'block';
            if (!isOpen) {
                // Use mousedown instead of click to avoid false triggers
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
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDropdown);
    } else {
        initDropdown();
    }
})();
</script>

<main class="main-content">
<?php if ($flash): ?>
    <div class="flash flash-<?= h($flash['type']) ?>">
        <?= h($flash['msg']) ?>
        <button onclick="this.parentElement.remove()" class="flash-close">✕</button>
    </div>
<?php endif; ?>
