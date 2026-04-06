<?php
require_once __DIR__ . '/../includes/functions.php';
if (auth()) redirect(url('index.php'));
$pageTitle = 'Вход — ' . SITE_NAME;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if ($u && password_verify($pass, $u['password'])) {
        login($u['id']);
        flash('Добре дошъл, ' . $u['name'] . '!');
        redirect(url('index.php'));
    } else {
        $error = 'Грешен имейл или парола.';
    }
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-page">
<div class="auth-card fade-in">
    <div class="auth-card-header">
        <span class="auth-logo">⚡</span>
        <h1>Вход в платформата</h1>
        <p>Добре дошъл обратно</p>
    </div>
    <div class="auth-card-body">
        <?php if ($error): ?>
            <div class="flash flash-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" style="display:flex;flex-direction:column;gap:1.25rem;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <div class="form-group">
                <label class="form-label">Имейл адрес</label>
                <input type="email" name="email" class="form-control" required placeholder="you@example.bg" value="<?= h($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Парола</label>
                <input type="password" name="password" class="form-control" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn btn-primary w-full">Влез в профила</button>
        </form>
        <div style="text-align:center;font-size:0.82rem;color:var(--text-dim);">
            <strong>Демо акаунти:</strong> ivan@example.bg / password
        </div>
    </div>
    <div class="auth-footer">
        Нямаш акаунт? <a href="<?= url('auth/register.php') ?>">Регистрирай се</a>
    </div>
</div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
