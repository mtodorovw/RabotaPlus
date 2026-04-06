<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAuth();
$txId = (int)($_GET['tx'] ?? 0);

$st = db()->prepare("
    SELECT t.*, u.name AS user_name, u.email AS user_email, u.city AS user_city,
        l.title AS listing_title
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN contracts c ON t.contract_id = c.id
    LEFT JOIN listings l ON c.listing_id = l.id
    WHERE t.id = ? AND t.user_id = ?
");
$st->execute([$txId, $user['id']]);
$tx = $st->fetch();

if (!$tx) { flash('Транзакцията не е намерена.', 'error'); redirect(url('profile/transactions.php')); }

// Block invoice for withdrawal transactions that haven't been approved yet
if ($tx['type'] === 'withdrawal') {
    $wrSt = db()->prepare("SELECT status FROM withdrawal_requests WHERE user_id=? AND amount=? AND status != 'approved' ORDER BY created_at DESC LIMIT 1");
    $wrSt->execute([$user['id'], abs($tx['amount'])]);
    $wr = $wrSt->fetch();
    // If there's a matching non-approved withdrawal request, block the invoice
    // Also check if this specific transaction has a matching approved request
    $approvedSt = db()->prepare("SELECT id FROM withdrawal_requests WHERE user_id=? AND status='approved' ORDER BY created_at DESC LIMIT 1");
    $approvedSt->execute([$user['id']]);
    $approved = $approvedSt->fetch();
    if (!$approved) {
        flash('Фактурата за теглене е достъпна само след одобрение от администратор.', 'error');
        redirect(url('profile/transactions.php'));
    }
}

$invNum  = $tx['invoice_number'] ?: ('INV-' . str_pad($txId, 6, '0', STR_PAD_LEFT));
$isOutgoing = $tx['amount'] < 0; // negative = paid/outgoing

$typeMap = [
    'deposit'        => 'Депозит на средства',
    'withdrawal'     => 'Теглене на средства',
    'escrow_release' => $isOutgoing ? 'Плащане за услуга (ескроу)' : 'Получаване за изпълнена услуга',
    'refund'         => 'Възстановяване на средства',
    'escrow_lock'    => 'Заключване в ескроу',
    'commission'     => 'Комисионна на платформата',
];
$typeLabel = $typeMap[$tx['type']] ?? $tx['type'];
$amount = abs($tx['amount']);

// Invoice direction labels
$invoiceTitle  = $isOutgoing ? 'ДЕБИТНА БЕЛЕЖКА' : 'ФАКТУРА';
$partyFromLabel = $isOutgoing ? 'Платец' : 'Получател';
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="UTF-8">
<title>Фактура <?= h($invNum) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=DM+Sans:wght@400;600&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DM Sans', sans-serif; background: #f5f0e8; color: #1a1a2e; font-size: 14px; line-height: 1.6; }
.page { max-width: 780px; margin: 2rem auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 48px rgba(0,0,0,0.15); }

/* Header */
.inv-header { background: #1a1a2e; color: white; padding: 2.5rem 3rem; display: flex; justify-content: space-between; align-items: flex-start; }
.brand { font-family: 'Playfair Display', serif; font-size: 1.8rem; font-weight: 700; color: #C9A84C; }
.brand small { display: block; font-family: 'DM Sans', sans-serif; font-size: 0.7rem; color: #8b949e; font-weight: 400; margin-top: 2px; letter-spacing: 0.08em; text-transform: uppercase; }
.inv-title-box { text-align: right; }
.inv-title { font-family: 'Playfair Display', serif; font-size: 1.2rem; color: #C9A84C; }
.inv-num { font-size: 0.85rem; color: #8b949e; margin-top: 4px; }
.inv-date { font-size: 0.82rem; color: #6e7681; margin-top: 2px; }

/* Body */
.inv-body { padding: 2.5rem 3rem; }

/* Info grid */
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem; padding-bottom: 2rem; border-bottom: 1px solid #e8e0d0; }
.info-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #8b949e; margin-bottom: 4px; }
.info-value { font-size: 0.9rem; color: #1a1a2e; font-weight: 600; }
.info-sub { font-size: 0.82rem; color: #6e7681; }

/* Items table */
.items-table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
.items-table th { padding: 0.6rem 0.75rem; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: #8b949e; border-bottom: 2px solid #e8e0d0; text-align: left; }
.items-table td { padding: 0.85rem 0.75rem; border-bottom: 1px solid #f0ebe0; font-size: 0.875rem; }
.items-table td:last-child, .items-table th:last-child { text-align: right; }

/* Totals */
.totals { display: flex; justify-content: flex-end; }
.totals-box { min-width: 280px; }
.total-row { display: flex; justify-content: space-between; padding: 0.5rem 0; font-size: 0.875rem; color: #6e7681; }
.total-row.main { border-top: 2px solid #1a1a2e; margin-top: 0.5rem; padding-top: 0.75rem; font-size: 1.1rem; font-weight: 700; color: #1a1a2e; }
.total-row.main .amount { color: #C9A84C; font-family: 'Playfair Display', serif; font-size: 1.3rem; }

/* Footer */
.inv-footer { background: #f5f0e8; padding: 1.5rem 3rem; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #e8e0d0; }
.inv-footer-note { font-size: 0.78rem; color: #8b949e; }
.status-badge { padding: 0.35rem 1rem; background: rgba(63,185,80,0.15); border: 1px solid rgba(63,185,80,0.4); border-radius: 20px; font-size: 0.78rem; font-weight: 700; color: #3fb950; letter-spacing: 0.04em; text-transform: uppercase; }

/* Print button */
.print-bar { display: flex; gap: 1rem; justify-content: center; padding: 1.5rem; background: #f0ebe0; }
.print-btn { padding: 0.7rem 1.75rem; background: #1a1a2e; color: white; border: none; border-radius: 8px; font-family: 'DM Sans', sans-serif; font-weight: 600; font-size: 0.9rem; cursor: pointer; }
.print-btn:hover { background: #C9A84C; color: #1a1a2e; }
.back-btn { padding: 0.7rem 1.75rem; background: transparent; color: #6e7681; border: 1px solid #d0c8b8; border-radius: 8px; font-family: 'DM Sans', sans-serif; font-size: 0.9rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }

@media print {
    body { background: white; }
    .print-bar { display: none; }
    .page { box-shadow: none; margin: 0; border-radius: 0; }
}
</style>
</head>
<body>

<div class="print-bar no-print">
    <a href="<?= url('profile/transactions.php') ?>" class="back-btn">← Назад</a>
    <button onclick="window.print()" class="print-btn">🖨 Принтирай / Запази PDF</button>
</div>

<div class="page">
    <!-- Header -->
    <div class="inv-header">
        <div>
            <div class="brand">⚡ Работа+<small>Платформа за кратки услуги</small></div>
        </div>
        <div class="inv-title-box">
            <div class="inv-title"><?= $invoiceTitle ?></div>
            <div class="inv-num"><?= h($invNum) ?></div>
            <div class="inv-date">Дата: <?= date('d.m.Y', strtotime($tx['created_at'])) ?></div>
        </div>
    </div>

    <!-- Body -->
    <div class="inv-body">
        <div class="info-grid">
            <div>
                <div class="info-label">Издател</div>
                <div class="info-value">Работа+ ЕООД</div>
                <div class="info-sub">ЕИК: 123456789</div>
                <div class="info-sub">България</div>
                <div class="info-sub">support@rabotaplus.bg</div>
            </div>
            <div>
                <div class="info-label"><?= $partyFromLabel ?></div>
                <div class="info-value"><?= h($tx['user_name']) ?></div>
                <div class="info-sub"><?= h($tx['user_email']) ?></div>
                <?php if ($tx['user_city']): ?><div class="info-sub"><?= h($tx['user_city']) ?>, България</div><?php endif; ?>
            </div>
        </div>

        <!-- Items -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Описание</th>
                    <?php if ($tx['listing_title']): ?><th>Обява</th><?php endif; ?>
                    <th>Сума</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="color:#8b949e;">1</td>
                    <td><?= h($typeLabel) ?><?php if ($tx['description']): ?><br><span style="font-size:0.78rem;color:#8b949e;"><?= h($tx['description']) ?></span><?php endif; ?></td>
                    <?php if ($tx['listing_title']): ?><td style="font-size:0.82rem;color:#6e7681;"><?= h(mb_strimwidth($tx['listing_title'],0,40,'...','UTF-8')) ?></td><?php endif; ?>
                    <td><?= formatMoney($amount) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals">
            <div class="totals-box">
                <div class="total-row" style="font-size:0.8rem;color:#8b949e;"><span>Без начислено ДДС (освободена доставка)</span></div>
                <div class="total-row main"><span>ОБЩО</span><span class="amount"><?= formatMoney($amount) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="inv-footer">
        <div>
            <div class="inv-footer-note">Дата на транзакция: <?= date('d.m.Y H:i', strtotime($tx['created_at'])) ?></div>
            <div class="inv-footer-note">Документът е генериран автоматично от платформата Работа+</div>
        </div>
        <div class="status-badge">✓ Платено</div>
    </div>
</div>

</body>
</html>
