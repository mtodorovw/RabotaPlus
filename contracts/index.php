<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAuth();
$pageTitle = 'Договори — ' . SITE_NAME;

$tab = $_GET['tab'] ?? 'active';

// Map tab → status values (PHP-side, no CASE in SQL = no collation mismatch)
$statusMap = [
    'active'    => ['active', 'disputed'],
    'disputed'  => ['disputed'],
    'completed' => ['completed', 'cancelled'],
];
$statuses  = $statusMap[$tab] ?? ['active', 'disputed'];
$inClause  = implode(',', array_fill(0, count($statuses), '?'));
$params    = array_merge([$user['id'], $user['id']], $statuses);

$st = db()->prepare("
    SELECT c.*, l.title AS listing_title,
        e.name AS emp_name, e.avatar AS emp_avatar,
        co.name AS contractor_name, co.avatar AS contractor_avatar
    FROM contracts c
    JOIN listings l ON c.listing_id = l.id
    JOIN users e ON c.employer_id = e.id
    JOIN users co ON c.contractor_id = co.id
    WHERE (c.employer_id = ? OR c.contractor_id = ?)
    AND c.status IN ($inClause)
    ORDER BY c.created_at DESC
");
$st->execute($params);
$contracts = $st->fetchAll();

// My listings
$myListings = [];
if ($tab === 'listings') {
    $st2 = db()->prepare('SELECT l.*, 
        (SELECT COUNT(*) FROM applications a WHERE a.listing_id = l.id) AS app_count
        FROM listings l WHERE l.employer_id = ? ORDER BY l.created_at DESC');
    $st2->execute([$user['id']]);
    $myListings = $st2->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="fade-in">
<div class="page-header page-header-row">
    <div>
        <h1>Моите договори</h1>
        <p>Управлявай активни и завършени договори</p>
    </div>
</div>

<div class="tabs">
    <a href="?tab=active"    class="tab<?= $tab==='active'    ? ' active' : '' ?>">Активни</a>
    <a href="?tab=disputed"  class="tab<?= $tab==='disputed'  ? ' active' : '' ?>">Спорове</a>
    <a href="?tab=completed" class="tab<?= $tab==='completed' ? ' active' : '' ?>">Завършени</a>
    <a href="?tab=listings"  class="tab<?= $tab==='listings'  ? ' active' : '' ?>">Мои обяви</a>
</div>

<?php if ($tab === 'listings'): ?>
    <?php if (empty($myListings)): ?>
        <div class="empty-state">
            <span class="empty-state-icon">📋</span>
            <h3>Нямаш публикувани обяви</h3>
            <a href="<?= url('listings/create.php') ?>" class="btn btn-primary mt-2">Публикувай обява</a>
        </div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>Обява</th>
            <th>Бюджет</th>
            <th>Статус</th>
            <th>Кандидати</th>
            <th>Дата</th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($myListings as $l): ?>
        <tr>
            <td><?= h(mb_strimwidth($l['title'], 0, 60, '...', 'UTF-8')) ?></td>
            <td class="text-gold"><?= formatMoney($l['budget']) ?></td>
            <td><span class="pill pill-<?= $l['status'] ?>"><?= ['open'=>'Свободна','closed'=>'Затворена','cancelled'=>'Отменена'][$l['status']] ?></span></td>
            <td><?= $l['app_count'] ?></td>
            <td style="color:var(--text-dim);font-size:0.8rem;"><?= timeAgo($l['created_at']) ?></td>
            <td><a href="<?= url('listings/view.php?id=' . $l['id']) ?>" class="btn btn-outline btn-sm">Преглед</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

<?php elseif (empty($contracts)): ?>
    <div class="empty-state">
        <span class="empty-state-icon">📄</span>
        <h3>Няма <?= $tab==='active' ? 'активни' : ($tab==='completed' ? 'завършени' : '') ?> договори</h3>
    </div>
<?php else: ?>
    <div style="display:flex;flex-direction:column;gap:1rem;">
    <?php foreach ($contracts as $c): ?>
    <?php $iEmp = $c['employer_id'] === $user['id']; ?>
    <div class="card contract-card" style="cursor:pointer;" onclick="location.href='<?= url("contracts/view.php?id=" . $c['id']) ?>'">
        <div class="card-body">
            <!-- Top row: title + role -->
            <div style="margin-bottom:0.5rem;">
                <div style="font-family:'Playfair Display',serif;font-size:1.05rem;margin-bottom:0.25rem;"><?= h($c['listing_title']) ?></div>
                <div style="font-size:0.82rem;color:var(--text-muted);">
                    <?php if ($iEmp): ?>
                        👷 Изпълнител: <strong><?= h($c['contractor_name']) ?></strong>
                    <?php else: ?>
                        💼 Работодател: <strong><?= h($c['emp_name']) ?></strong>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.78rem;color:var(--text-dim);margin-top:4px;"><?= timeAgo($c['created_at']) ?></div>
            </div>
            <!-- Bottom row: amount + confirmations + status + button -->
            <div class="contract-card-meta">
                <div style="display:flex;align-items:center;gap:1rem;flex:1;">
                    <div>
                        <div style="font-family:'Playfair Display',serif;font-size:1.3rem;color:var(--gold);"><?= formatMoney($c['amount']) ?></div>
                        <div style="font-size:0.72rem;color:var(--text-dim);">Сума</div>
                    </div>
                    <div style="font-size:0.8rem;line-height:1.6;">
                        <?= $c['employer_confirmed'] ? '✅' : '⬜' ?> Работодател<br>
                        <?= $c['contractor_confirmed'] ? '✅' : '⬜' ?> Изпълнител
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
                    <span class="pill pill-<?= $c['status'] ?>"><?= ['active'=>'Активен','completed'=>'Завършен','disputed'=>'Спор','cancelled'=>'Отменен'][$c['status']] ?></span>
                    <a href="<?= url("contracts/view.php?id=" . $c['id']) ?>" class="btn btn-outline btn-sm" onclick="event.stopPropagation()">Детайли</a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

</div>
<style>
.contract-card-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px solid var(--border-dim);
}
@media (max-width: 768px) {
    .table-wrap { overflow-x: auto; }
    .contract-card-meta { gap: 0.5rem; }
    .contract-card-meta .pill { font-size: 0.75rem; padding: 0.2rem 0.5rem; }
    .contract-card-meta .btn { font-size: 0.78rem; padding: 0.3rem 0.6rem; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
