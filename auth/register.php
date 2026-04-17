<?php
require_once __DIR__ . '/../includes/functions.php';
if (auth()) redirect(url('index.php'));
$pageTitle = 'Регистрация — ' . SITE_NAME;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    $city  = $_POST['city'] ?? '';
    $nb    = trim($_POST['neighborhood'] ?? '');

    if (!$name)  $errors[] = 'Въведи пълното си име.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Невалиден имейл.';
    if (strlen($pass) < 6) $errors[] = 'Паролата трябва да е поне 6 символа.';
    if ($pass !== $pass2) $errors[] = 'Паролите не съвпадат.';

    if (empty($errors)) {
        $st = db()->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) {
            $errors[] = 'Този имейл вече е регистриран.';
        } else {
            $st = db()->prepare('INSERT INTO users (name, email, password, city, neighborhood) VALUES (?,?,?,?,?)');
            $st->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $city ?: null, $nb ?: null]);
            login((int)db()->lastInsertId());
            flash('Добре дошъл в Работа+! Зареди баланс за да публикуваш обяви.');
            redirect(url('index.php'));
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-page">
<div class="auth-card fade-in" style="max-width:480px;">
    <div class="auth-card-header">
        <span class="auth-logo">⚡</span>
        <h1>Създай акаунт</h1>
        <p>Безплатна регистрация</p>
    </div>
    <div class="auth-card-body">
        <?php foreach ($errors as $e): ?>
            <div class="flash flash-error"><?= h($e) ?></div>
        <?php endforeach; ?>
        <form method="post" style="display:flex;flex-direction:column;gap:1.1rem;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <div class="form-group">
                <label class="form-label">Пълно име</label>
                <input type="text" name="name" class="form-control" required placeholder="Иван Петров" value="<?= h($_POST['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Имейл адрес</label>
                <input type="email" name="email" class="form-control" required placeholder="you@example.bg" value="<?= h($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-grid form-grid-2">
                <div class="form-group">
                    <label class="form-label">Парола</label>
                    <input type="password" name="password" class="form-control" required placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label class="form-label">Потвърди паролата</label>
                    <input type="password" name="password2" class="form-control" required placeholder="••••••••">
                </div>
            </div>
            <div class="form-grid form-grid-2">
                <div class="form-group">
                    <label class="form-label">Град</label>
                    <select name="city" class="form-control">
                        <option value="">— Избери —</option>
                        <?php foreach (bgCities() as $c): ?>
                            <option value="<?= h($c) ?>" <?= ($_POST['city'] ?? '') === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Квартал</label>
                    <input type="text" name="neighborhood" class="form-control" placeholder="напр. Лозенец" value="<?= h($_POST['neighborhood'] ?? '') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-full">Регистрирай се</button>
        </form>
    </div>
    <div class="auth-footer">
        Вече имаш акаунт? <a href="<?= url('auth/login.php') ?>">Влез</a>
    </div>
</div>
</div>
<style>
@media (max-width: 480px) {
    .auth-card { margin: 0 0.5rem; }
    .auth-card-body { padding: 1.25rem; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
