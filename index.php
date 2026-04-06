<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Работа+ — Намери изпълнител';

$city   = $_GET['city']   ?? '';
$search = $_GET['s']      ?? '';
$minB   = $_GET['min']    ?? '';
$maxB   = $_GET['max']    ?? '';

$params = [];
$where  = ["l.status = 'open'"];
if ($city)   { $where[] = 'l.city = ?'; $params[] = $city; }
if ($search) { $where[] = '(l.title LIKE ? OR l.description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($minB !== '') { $where[] = 'l.budget >= ?'; $params[] = (float)$minB; }
if ($maxB !== '') { $where[] = 'l.budget <= ?'; $params[] = (float)$maxB; }

$sql = 'SELECT l.*, u.name AS employer_name, u.avatar AS employer_avatar,
    (SELECT COUNT(*) FROM applications a WHERE a.listing_id = l.id) AS app_count
    FROM listings l JOIN users u ON l.employer_id = u.id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC';
$st = db()->prepare($sql);
$st->execute($params);
$listings = $st->fetchAll();

$user = auth();
require_once __DIR__ . '/includes/header.php';
?>

<div class="fade-in">
<?php if (!$user): ?>
<div class="hero">
    <h1>Намери изпълнител<br>за всяка задача</h1>
    <p>Платформа за бързо свързване на работодатели и изпълнители за кратки услуги в България.</p>
    <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;">
        <a href="<?= url('auth/register.php') ?>" class="btn btn-primary btn-lg">Регистрирай се безплатно</a>
        <a href="#listings" class="btn btn-outline btn-lg">Разгледай обявите</a>
    </div>
</div>
<?php else: ?>
<div class="page-header page-header-row">
    <div><h1>Свободни обяви</h1><p>Намери задача, за която да кандидатстваш</p></div>
    <a href="<?= url('listings/create.php') ?>" class="btn btn-primary">+ Нова обява</a>
</div>
<?php endif; ?>

<form method="get" id="listings">
<div class="filters-bar">
    <span class="filter-label">Филтри:</span>
    <input type="text" name="s" class="form-control" placeholder="Търси..." value="<?= h($search) ?>" style="max-width:220px;">
    <select name="city" class="form-control">
        <option value="">Всички градове</option>
        <?php foreach (bgCities() as $c): ?>
            <option value="<?= h($c) ?>" <?= $city===$c?'selected':'' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="number" name="min" class="form-control" placeholder="Мин. €" value="<?= h($minB) ?>" min="0" style="max-width:110px;">
    <input type="number" name="max" class="form-control" placeholder="Макс. €" value="<?= h($maxB) ?>" min="0" style="max-width:110px;">
    <button type="submit" class="btn btn-primary btn-sm">Търси</button>
    <?php if ($city || $search || $minB || $maxB): ?>
        <a href="<?= url('index.php') ?>" class="btn btn-outline btn-sm">Изчисти</a>
    <?php endif; ?>
    <span style="margin-left:auto;font-size:0.82rem;color:var(--text-dim);"><?= count($listings) ?> обяви</span>
</div>
</form>

<?php if (empty($listings)): ?>
    <div class="empty-state">
        <span class="empty-state-icon">🔍</span>
        <h3>Няма намерени обяви</h3>
    </div>
<?php else: ?>
<div class="listings-grid">
    <?php foreach ($listings as $l): ?>
    <a href="<?= url('listings/view.php?id='.$l['id']) ?>" class="listing-card">
        <!-- Row 1: status + budget -->
        <div class="lc-top">
            <span class="pill pill-open">Свободна</span>
            <span class="listing-budget"><?= formatMoney($l['budget']) ?></span>
        </div>

        <!-- Row 2: title (fixed height, ellipsis) -->
        <div class="lc-title"><?= h($l['title']) ?></div>

        <!-- Row 3: description (fixed height, 3 lines, ellipsis) -->
        <div class="lc-desc"><?= h($l['description']) ?></div>

        <!-- Row 4: meta (location, candidates, time) — always at bottom -->
        <div class="lc-meta">
            <?php if ($l['city']): ?>
                <span>📍 <?= h($l['city']) ?><?= $l['neighborhood'] ? ', '.h($l['neighborhood']) : '' ?></span>
            <?php endif; ?>
            <span>👥 <?= $l['app_count'] ?></span>
            <span>🕐 <span class="time-ago-live" data-created="<?= h($l['created_at']) ?>"><?= timeAgo($l['created_at']) ?></span></span>
        </div>

        <!-- Row 5: employer -->
        <div class="lc-employer">
            <img src="<?= avatarUrl($l['employer_avatar'], $l['employer_name']) ?>" alt="">
            <span><?= h($l['employer_name']) ?></span>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
