<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAuth();
$pageTitle = 'Профил — ' . SITE_NAME;
$errors = [];

if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name = trim($_POST['name'] ?? '');
    $bio  = trim($_POST['bio']  ?? '');
    $city = $_POST['city'] ?? '';
    $nb   = trim($_POST['neighborhood'] ?? '');

    if (!$name) $errors[] = 'Въведи пълното си име.';

    $avatar = $user['avatar'];
    if (!empty($_FILES['avatar']['name'])) {
        $file = $_FILES['avatar'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $errors[] = 'Позволени формати: JPG, PNG, GIF, WEBP.';
        } elseif ($file['size'] > 2*1024*1024) {
            $errors[] = 'Снимката трябва да е под 2MB.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Грешка при качване (код: '.$file['error'].'). Провери upload_max_filesize в php.ini.';
        } else {
            $newName = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $newName)) {
                if ($user['avatar'] && file_exists(UPLOAD_DIR . $user['avatar'])) {
                    @unlink(UPLOAD_DIR . $user['avatar']);
                }
                $avatar = $newName;
            } else {
                $errors[] = 'Неуспешно записване на файла. Провери правата на папката assets/uploads/.';
            }
        }
    }

    if (empty($errors)) {
        db()->prepare('UPDATE users SET name=?, bio=?, city=?, neighborhood=?, avatar=? WHERE id=?')
           ->execute([$name, $bio ?: null, $city ?: null, $nb ?: null, $avatar, $user['id']]);
        // Force refresh user session data
        static $user = null; // reset static cache
        flash('Профилът е обновен успешно!');
        redirect(url('profile/edit.php'));
    }
}

// Re-fetch fresh user data
$fresh = db()->prepare('SELECT * FROM users WHERE id=?');
$fresh->execute([$user['id']]);
$user = $fresh->fetch();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container fade-in">

<div class="profile-header">
    <div class="profile-avatar">
        <img src="<?= avatarUrl($user['avatar'], $user['name']) ?>" alt="<?= h($user['name']) ?>" id="avatar-preview">
    </div>
    <div class="profile-info">
        <h2><?= h($user['name']) ?></h2>
        <p><?= ($user['city'] ? h($user['city']) . ($user['neighborhood'] ? ', ' . h($user['neighborhood']) : '') : 'Местоположение не е зададено') ?></p>
        <?php if ($user['bio']): ?>
            <p style="margin-top:0.5rem;font-size:0.875rem;color:var(--text-muted);"><?= h($user['bio']) ?></p>
        <?php endif; ?>
    </div>
    <div class="profile-balance">
        <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-dim);">Баланс</div>
        <div style="font-family:'Playfair Display',serif;font-size:2rem;color:var(--gold);font-weight:700;"><?= formatMoney((float)$user['balance']) ?></div>
        <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
            <a href="<?= url('profile/deposit.php') ?>" class="btn btn-outline btn-sm">⬆ Депозит</a>
            <a href="<?= url('profile/withdraw.php') ?>" class="btn btn-outline btn-sm">⬇ Теглене</a>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <h3 style="font-family:'DM Sans',sans-serif;font-size:1.05rem;">Редактирай профила</h3>
    </div>
    <div class="card-body">
        <?php foreach ($errors as $e): ?>
            <div class="flash flash-error"><?= h($e) ?></div>
        <?php endforeach; ?>

        <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:1.25rem;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">

            <!-- Avatar -->
            <div class="form-group">
                <label class="form-label">Профилна снимка</label>
                <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                    <img src="<?= avatarUrl($user['avatar'], $user['name']) ?>" id="avatar-preview-form"
                         style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--gold);">
                    <label style="cursor:pointer;">
                        <span class="btn btn-outline btn-sm">📷 Избери снимка</span>
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp"
                               style="display:none;" id="avatar-input"
                               onchange="previewAvatar(this)">
                    </label>
                    <span id="avatar-filename" style="font-size:0.8rem;color:var(--text-dim);">Не е избран файл</span>
                </div>
                <span class="form-hint">JPG, PNG или WEBP · макс. 2MB</span>
            </div>

            <div class="form-group">
                <label class="form-label">Пълно име *</label>
                <input type="text" name="name" class="form-control" required value="<?= h($user['name']) ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Биография</label>
                <textarea name="bio" class="form-control" rows="3"
                    placeholder="Разкажи малко за себе си..."><?= h((string)($user['bio'] ?? '')) ?></textarea>
            </div>

            <div class="form-grid form-grid-2">
                <div class="form-group">
                    <label class="form-label">Град</label>
                    <select name="city" class="form-control">
                        <option value="">— Избери —</option>
                        <?php foreach (bgCities() as $c): ?>
                            <option value="<?= h($c) ?>" <?= ($user['city'] ?? '') === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Квартал</label>
                    <input type="text" name="neighborhood" class="form-control"
                           value="<?= h((string)($user['neighborhood'] ?? '')) ?>">
                </div>
            </div>

            <div style="display:flex;gap:1rem;align-items:center;">
                <button type="submit" class="btn btn-primary">💾 Запази промените</button>
                <a href="<?= url('index.php') ?>" class="btn btn-outline">Отказ</a>
            </div>
        </form>
    </div>
</div>

<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:2rem;">
    <a href="<?= url('profile/transactions.php') ?>" class="btn btn-outline">💳 Всички транзакции</a>
    <a href="<?= url('contracts/index.php') ?>" class="btn btn-outline">📄 Моите договори</a>
    <a href="<?= url('contracts/index.php?tab=listings') ?>" class="btn btn-outline">📋 Моите обяви</a>
</div>

</div>

<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('avatar-preview-form').src = e.target.result;
            document.getElementById('avatar-preview').src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
        document.getElementById('avatar-filename').textContent = input.files[0].name;
    }
}
</script>

<style>
@media (max-width: 768px) {
    .profile-header { flex-direction: column; align-items: center; text-align: center; gap: 1rem; }
    [style*="display:grid;grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr !important; }
    [style*="display:flex;gap:1rem;flex-wrap:wrap"] { flex-direction: column; }
    [style*="display:flex;gap:1rem;flex-wrap:wrap"] .btn { width: 100%; text-align: center; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
