<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAuth();
$pageTitle = 'Транзакции — ' . SITE_NAME;

$tab = $_GET['tab'] ?? 'all';

// Tab type filters
$where  = "WHERE t.user_id = ?";
$params = [$user['id']];

// Filter by tab — income/expense use amount sign, not type
if ($tab === 'income') {
    $where .= " AND t.amount > 0";
} elseif ($tab === 'expense') {
    $where .= " AND t.amount < 0 AND t.type != 'escrow_lock'";   // exclude locked (not spent yet)
} elseif ($tab === 'deposits') {
    $where .= " AND t.type = 'deposit'";
} elseif ($tab === 'withdraw') {
    $where .= " AND t.type = 'withdrawal'";
}

$st = db()->prepare("
    SELECT t.*, l.title AS listing_title
    FROM transactions t
    LEFT JOIN contracts c ON t.contract_id = c.id
    LEFT JOIN listings l  ON c.listing_id  = l.id
    $where
    ORDER BY t.created_at DESC
");
$st->execute($params);
$txs = $st->fetchAll();

// Pre-load approved withdrawal tx_ids and amounts for invoice button visibility
$approvedWrTxIds = [];
$approvedWrAmounts = [];
try {
    $wrSt = db()->prepare("SELECT tx_id, amount FROM withdrawal_requests WHERE user_id=? AND status='approved'");
    $wrSt->execute([$user['id']]);
    foreach ($wrSt->fetchAll() as $row) {
        if ($row['tx_id']) $approvedWrTxIds[] = (int)$row['tx_id'];
        $approvedWrAmounts[] = round(abs($row['amount']), 2);
    }
} catch (Exception $e) {}

// Summary totals
// Totals: separate queries for clarity and correctness
$userId = $user['id'];

// 1. Income from jobs only (escrow_release)
$t1 = db()->prepare("SELECT COALESCE(SUM(ABS(amount)),0) FROM transactions WHERE user_id=? AND type='escrow_release' AND amount>0");
$t1->execute([$userId]); $totalIncome = (float)$t1->fetchColumn();

// 2. Withdrawn = sum of APPROVED withdrawal requests only
$t2 = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawal_requests WHERE user_id=? AND status='approved'");
$t2->execute([$userId]); $totalWithdrawn = (float)$t2->fetchColumn();

// 3. Deposits = deposit transactions excluding returned-withdrawal-refunds
$t3 = db()->prepare("SELECT COALESCE(SUM(ABS(amount)),0) FROM transactions WHERE user_id=? AND type='deposit' AND (description NOT LIKE 'Върнати%' AND description NOT LIKE 'Returned%')");
$t3->execute([$userId]); $totalDeposits = (float)$t3->fetchColumn();

// 4. Escrow currently locked = only active/disputed contracts where employer hasn't been refunded yet
$t4 = db()->prepare("SELECT COALESCE(SUM(c.amount),0) FROM contracts c WHERE c.employer_id=? AND c.status IN ('active','disputed') AND c.escrow_held=1");
$t4->execute([$userId]); $totalLocked = (float)$t4->fetchColumn();

$totals = [
    'total_income'    => $totalIncome,
    'total_withdrawn' => $totalWithdrawn,
    'total_deposits'  => $totalDeposits,
    'total_locked'    => $totalLocked,
];

// Re-fetch fresh balance
$balSt = db()->prepare('SELECT balance FROM users WHERE id=?');
$balSt->execute([$user['id']]);
$balance = (float)$balSt->fetchColumn();

$typeLabels = [
    'deposit'        => ['label' => 'Депозит',         'icon' => '⬆', 'class' => 'pill-open'],
    'escrow_lock'    => ['label' => 'Ескроу заключен',  'icon' => '🔒', 'class' => 'pill-pending'],
    'escrow_release' => ['label' => 'Плащане',          'icon' => '💸', 'class' => 'pill-open'],
    'refund'         => ['label' => 'Възстановяване',   'icon' => '↩',  'class' => 'pill-open'],
    'withdrawal'     => ['label' => 'Теглене',          'icon' => '⬇', 'class' => 'pill-disputed'],
    'commission'     => ['label' => 'Комисионна',       'icon' => '🏦', 'class' => 'pill-disputed'],
];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-wide fade-in">

<div class="breadcrumb">
    <a href="<?= url('profile/edit.php') ?>">Профил</a>
    <span class="breadcrumb-sep">›</span>
    <span>Транзакции</span>
</div>

<div class="page-header page-header-row">
    <div><h1>💳 Транзакции</h1><p>История на всички финансови операции</p></div>
    <div style="display:flex;gap:0.75rem;">
        <a href="<?= url('profile/deposit.php') ?>"  class="btn btn-primary btn-sm">⬆ Депозит</a>
        <a href="<?= url('profile/withdraw.php') ?>" class="btn btn-outline btn-sm">⬇ Теглене</a>
    </div>
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:2rem;">
    <?php foreach ([
        ['Баланс',              formatMoney($balance),                    '💳', 'var(--gold)'],
        ['Приходи от обяви',    formatMoney($totals['total_income']),     '📈', 'var(--success)'],
        ['Теглено',             formatMoney($totals['total_withdrawn']),  '⬇', '#e06c75'],
        ['Депозити',            formatMoney($totals['total_deposits']),   '⬆', 'var(--info)'],
        ['Ескроу (заключено)',   formatMoney($totals['total_locked']),     '🔒', 'var(--text-muted)'],
    ] as [$label, $val, $ico, $col]): ?>
    <div style="background:var(--navy-2);border:1px solid var(--border-dim);border-radius:var(--radius-lg);padding:1.25rem;text-align:center;">
        <div style="font-size:1.4rem;"><?= $ico ?></div>
        <div style="font-family:'Playfair Display',serif;font-size:1.2rem;color:<?= $col ?>;font-weight:700;margin:0.25rem 0;"><?= $val ?></div>
        <div style="font-size:0.72rem;color:var(--text-dim);"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Tabs -->
<div class="tabs" style="margin-bottom:1.5rem;">
    <a href="?tab=all"      class="tab<?= $tab==='all'      ? ' active' : '' ?>">Всички</a>
    <a href="?tab=income"   class="tab<?= $tab==='income'   ? ' active' : '' ?>">Приходи</a>
    <a href="?tab=expense"  class="tab<?= $tab==='expense'  ? ' active' : '' ?>">Разходи</a>
    <a href="?tab=deposits" class="tab<?= $tab==='deposits' ? ' active' : '' ?>">Депозити</a>
    <a href="?tab=withdraw" class="tab<?= $tab==='withdraw' ? ' active' : '' ?>">Тегления</a>
</div>

<?php if (empty($txs)): ?>
    <div class="empty-state"><span class="empty-state-icon">📭</span><h3>Няма транзакции в тази категория</h3></div>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Тип</th>
            <th>Сума</th>
            <th>Описание</th>
            <th>Обява / Договор</th>
            <th>Дата</th>
            <th>Фактура</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($txs as $tx):
        $tInfo = $typeLabels[$tx['type']] ?? ['label' => h($tx['type']), 'icon' => '•', 'class' => 'pill-pending'];
        // Override pill color by amount sign: negative = red pill, positive = green pill
        if ($tx['amount'] < 0) $tInfo['class'] = 'pill-disputed';
        elseif ($tx['amount'] > 0 && $tInfo['class'] === 'pill-disputed') $tInfo['class'] = 'pill-open';
        // Adjust display type: escrow_release with negative amount = employer paid
        $displayType = $tx['type'];
        if ($tx['type'] === 'escrow_release' && $tx['amount'] < 0) {
            $displayType = '__release_out';
        }
        // Color by type: green = money received/returned, red = money out
        // Color is purely based on amount sign — positive = green, negative = red
        $isPositive = $tx['amount'] > 0;
    ?>
    <tr>
        <td style="color:var(--text-dim);font-size:0.78rem;"><?= str_pad($tx['id'], 6, '0', STR_PAD_LEFT) ?></td>
        <td>
            <span class="pill <?= $tInfo['class'] ?>">
                <?= $tInfo['icon'] ?> <?= $tInfo['label'] ?>
            </span>
        </td>
        <td style="font-weight:700;white-space:nowrap;color:<?= $isPositive ? 'var(--success)' : '#e06c75' ?>;">
            <?= $isPositive ? '+' : '-' ?><?= formatMoney(abs($tx['amount'])) ?>
        </td>
        <td style="font-size:0.85rem;color:var(--text-muted);max-width:200px;"><?= h($tx['description'] ?? '') ?></td>
        <td style="font-size:0.82rem;">
            <?php if ($tx['listing_title'] && $tx['contract_id']): ?>
                <a href="<?= url('contracts/view.php?id='.$tx['contract_id']) ?>" style="color:var(--gold);">
                    <?= h(mb_strimwidth($tx['listing_title'], 0, 35, '...', 'UTF-8')) ?>
                </a>
            <?php else: ?>
                <span style="color:var(--text-dim);">—</span>
            <?php endif; ?>
        </td>
        <td style="font-size:0.78rem;color:var(--text-dim);white-space:nowrap;">
            <?= date('d.m.Y H:i', strtotime($tx['created_at'])) ?>
        </td>
        <td>
            <?php
            $showInvoice = in_array($tx['type'], ['deposit','escrow_release']); // refund = no invoice
            if ($tx['type'] === 'withdrawal') {
                // Show invoice only if this exact withdrawal transaction is approved
                $showInvoice = in_array((int)$tx['id'], $approvedWrTxIds)
                    || in_array(round(abs($tx['amount']), 2), $approvedWrAmounts);
            }
            ?>
            <?php if ($showInvoice): ?>
                <a href="<?= url('profile/invoice.php?tx='.$tx['id']) ?>" class="btn btn-outline btn-sm" title="PDF фактура">📄</a>
            <?php else: ?>
                <span style="color:var(--text-dim);">—</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
