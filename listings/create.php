<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAuth();
$pageTitle = 'Нова обява — ' . SITE_NAME;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $title  = trim($_POST['title'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $budget = (float)($_POST['budget'] ?? 0);
    $city   = $_POST['city'] ?? '';
    $nb     = trim($_POST['neighborhood'] ?? '');

    if (!$title)   $errors[] = 'Въведи заглавие.';
    if (!$desc)    $errors[] = 'Въведи описание.';
    if ($budget < 1) $errors[] = 'Бюджетът трябва да е поне 1 €';

    if (empty($errors)) {
        $st = db()->prepare('INSERT INTO listings (employer_id, title, description, budget, city, neighborhood) VALUES (?,?,?,?,?,?)');
        $st->execute([$user['id'], $title, $desc, $budget, $city ?: null, $nb ?: null]);
        $id = db()->lastInsertId();
        flash('Обявата е публикувана успешно!');
        redirect(url('listings/view.php?id=' . $id));
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container fade-in">
    <div class="breadcrumb">
        <a href="<?= url('index.php') ?>">Начало</a>
        <span class="breadcrumb-sep">›</span>
        <span>Нова обява</span>
    </div>

    <div class="page-header">
        <h1>Публикувай обява</h1>
        <p>Опиши задачата и определи бюджета — кандидатите ще се свържат с теб.</p>
    </div>

    <div class="card">
        <div class="card-body">
            <?php foreach ($errors as $e): ?>
                <div class="flash flash-error"><?= h($e) ?></div>
            <?php endforeach; ?>
            <form method="post" style="display:flex;flex-direction:column;gap:1.5rem;">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">

                <div class="form-group">
                    <label class="form-label">Заглавие на обявата *</label>
                    <input type="text" name="title" class="form-control" required
                        placeholder="напр. Нужен бояджия за хол" maxlength="200"
                        value="<?= h($_POST['title'] ?? '') ?>">
                    <span class="form-hint">Кратко и ясно заглавие</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Описание на задачата *</label>
                    <textarea name="description" class="form-control" required
                        placeholder="Опиши задачата в детайли — какво трябва да се направи, кога, какви материали се осигуряват..."
                        rows="6"><?= h($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Бюджет (€) *</label>
                        <input type="number" name="budget" class="form-control" required
                            min="1" step="0.01" placeholder="0.00"
                            value="<?= h($_POST['budget'] ?? '') ?>">
                        <span class="form-hint">Сумата ще бъде блокирана като ескроу при избор на кандидат</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Твоят баланс</label>
                        <div class="form-control" style="background:var(--navy-3);border-color:var(--border-dim);">
                            <strong style="color:var(--gold);"><?= formatMoney($user['balance']) ?></strong>
                        </div>
                        <?php if ($user['balance'] < 1): ?>
                        <span class="form-hint"><a href="<?= url('profile/deposit.php') ?>">Зареди баланс</a> за да наемеш кандидат</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Град</label>
                        <select name="city" class="form-control">
                            <option value="">— Не е задължително —</option>
                            <?php foreach (bgCities() as $c): ?>
                                <option value="<?= h($c) ?>" <?= ($_POST['city'] ?? $user['city']) === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Квартал</label>
                        <input type="text" name="neighborhood" class="form-control"
                            placeholder="напр. Лозенец"
                            value="<?= h($_POST['neighborhood'] ?? $user['neighborhood'] ?? '') ?>">
                    </div>
                </div>

                <div style="display:flex;gap:1rem;justify-content:flex-end;">
                    <a href="<?= url('index.php') ?>" class="btn btn-outline">Отказ</a>
                    <button type="submit" class="btn btn-primary">Публикувай обявата</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
