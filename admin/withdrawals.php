<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = requireAdmin();
$pageTitle = 'Заявки за теглене — ' . SITE_NAME;

// ── Handle approve / reject ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wr_id'])) {
    verifyCsrf();
    $wrId   = (int)$_POST['wr_id'];
    $action = $_POST['wr_action'];
    $note   = trim($_POST['wr_note'] ?? '');

    $wrRow = db()->prepare('SELECT wr.*, u.name AS user_name
        FROM withdrawal_requests wr JOIN users u ON wr.user_id = u.id
        WHERE wr.id = ? AND wr.status = "pending"');
    $wrRow->execute([$wrId]);
    $wr = $wrRow->fetch();

    if ($wr) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($action === 'reject') {
                $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')
                    ->execute([$wr['amount'], $wr['user_id']]);
                // 'deposit' type so it doesn't pollute income-from-jobs
                $pdo->prepare('INSERT INTO transactions (user_id, type, amount, description) VALUES (?,?,?,?)')
                    ->execute([$wr['user_id'], 'deposit', $wr['amount'], 'Върнати средства — отказана заявка #'.$wrId]);
                addNotification($wr['user_id'], 'withdrawal',
                    'Заявката ти за теглене на ' . formatMoney($wr['amount']) . ' беше отказана. Средствата са върнати.',
                    url('profile/transactions.php?tab=withdraw'));
            } else {
                addNotification($wr['user_id'], 'withdrawal',
                    'Заявката ти за теглене на ' . formatMoney($wr['amount']) . ' беше одобрена и обработена.',
                    url('profile/transactions.php?tab=withdraw'));
            }
            $pdo->prepare("UPDATE withdrawal_requests SET status=?, admin_note=?, processed_by=?, processed_at=NOW() WHERE id=?")
                ->execute([$action === 'approve' ? 'approved' : 'rejected', $note, $admin['id'], $wrId]);
            $pdo->commit();
            flash('Заявката е ' . ($action === 'approve' ? 'одобрена ✓' : 'отказана ✕') . '.');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('Грешка: ' . $e->getMessage(), 'error');
        }
    }
    redirect(url('admin/withdrawals.php'));
}

$view = $_GET['view'] ?? 'pending'; // pending | history

// ── History filters ───────────────────────────────────────
$search  = trim($_GET['s']      ?? '');
$status  = $_GET['status']      ?? ''; // approved | rejected
$method  = $_GET['method']      ?? '';
$sortBy  = $_GET['sort']        ?? 'date';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$allowedSorts = ['date'=>'wr.created_at','amount'=>'wr.amount','status'=>'wr.status','method'=>'wr.method'];
$orderCol = $allowedSorts[$sortBy] ?? 'wr.created_at';

// ── Load pending ──────────────────────────────────────────
$pendingSearch = trim($_GET['ps'] ?? '');
$pendingRows   = [];
try {
    $psWhere  = "wr.status = 'pending'";
    $psParams = [];
    if ($pendingSearch) {
        $psWhere .= " AND (u.name LIKE ? OR u.email LIKE ? OR wr.iban LIKE ?)";
        $psParams = ["%$pendingSearch%", "%$pendingSearch%", "%$pendingSearch%"];
    }
    $psSt = db()->prepare("SELECT wr.*, u.name AS user_name, u.email AS user_email
        FROM withdrawal_requests wr JOIN users u ON wr.user_id = u.id
        WHERE $psWhere ORDER BY wr.created_at ASC");
    $psSt->execute($psParams);
    $pendingRows = $psSt->fetchAll();
} catch (Exception $e) { flash('Таблицата не е създадена. Изпълни migrate2.sql.', 'error'); }

// ── Load history (approved/rejected only) ─────────────────
$histRows = [];
$histTotals = ['approved'=>0,'rejected'=>0,'amount_approved'=>0,'amount_rejected'=>0];
try {
    $hWhere  = ["wr.status != 'pending'"];
    $hParams = [];
    if ($search) {
        $hWhere[]  = '(u.name LIKE ? OR u.email LIKE ? OR wr.iban LIKE ?)';
        $hParams[] = "%$search%"; $hParams[] = "%$search%"; $hParams[] = "%$search%";
    }
    if ($status) { $hWhere[] = 'wr.status = ?'; $hParams[] = $status; }
    if ($method) { $hWhere[] = 'wr.method = ?'; $hParams[] = $method; }
    $hSQL = "SELECT wr.*, u.name AS user_name, u.email AS user_email, adm.name AS admin_name
        FROM withdrawal_requests wr JOIN users u ON wr.user_id=u.id LEFT JOIN users adm ON wr.processed_by=adm.id
        WHERE " . implode(' AND ', $hWhere) . " ORDER BY $orderCol $sortDir LIMIT 300";
    $hSt = db()->prepare($hSQL);
    $hSt->execute($hParams);
    $histRows = $hSt->fetchAll();
    foreach ($histRows as $r) {
        $histTotals[$r['status']]++;
        if ($r['status']==='approved') $histTotals['amount_approved'] += $r['amount'];
        if ($r['status']==='rejected') $histTotals['amount_rejected'] += $r['amount'];
    }
} catch (Exception $e) {}

function sortUrl(string $col): string {
    global $sortBy, $sortDir, $search, $status, $method;
    $dir = ($sortBy === $col && $sortDir === 'DESC') ? 'asc' : 'desc';
    return '?view=history&' . http_build_query(array_filter(['s'=>$search,'status'=>$status,'method'=>$method,'sort'=>$col,'dir'=>$dir]));
}
function sortArrow(string $col): string {
    global $sortBy, $sortDir;
    if ($sortBy !== $col) return '<span style="opacity:0.3">↕</span>';
    return $sortDir === 'DESC' ? ' ↓' : ' ↑';
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="fade-in">
<div class="breadcrumb">
    <a href="<?= url('admin/disputes.php') ?>">Администрация</a>
    <span class="breadcrumb-sep">›</span>
    <span>Заявки за теглене</span>
</div>

<div class="page-header page-header-row">
    <div>
        <h1>⬇ Заявки за теглене</h1>
        <p><?= $view==='pending' ? 'Изчакващи одобрение' : 'История на обработените заявки' ?></p>
    </div>
</div>

<!-- Tabs -->
<div class="tabs" style="margin-bottom:1.75rem;">
    <a href="?view=pending"  class="tab<?= $view==='pending'  ? ' active' : '' ?>">
        ⏳ Изчакващи
        <?php if (count($pendingRows) > 0): ?>
            <span style="background:var(--danger);color:#fff;font-size:0.68rem;font-weight:700;padding:0.1rem 0.45rem;border-radius:20px;margin-left:0.35rem;"><?= count($pendingRows) ?></span>
        <?php endif; ?>
    </a>
    <a href="?view=history" class="tab<?= $view==='history'  ? ' active' : '' ?>">📋 История</a>
</div>

<?php if ($view === 'pending'): ?>
<!-- ═══════════════ PENDING ═══════════════ -->

<!-- Search for pending -->
<form method="get" style="display:flex;gap:0.75rem;align-items:flex-end;margin-bottom:1.25rem;flex-wrap:wrap;">
    <input type="hidden" name="view" value="pending">
    <div class="form-group" style="margin:0;flex:1;min-width:220px;">
        <label class="form-label" style="font-size:0.75rem;">Търси по потребител / IBAN</label>
        <input type="text" name="ps" class="form-control" value="<?= h($pendingSearch) ?>" placeholder="Иван Петров, BG80...">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Търси</button>
    <?php if ($pendingSearch): ?><a href="?view=pending" class="btn btn-outline btn-sm">✕ Изчисти</a><?php endif; ?>
</form>

<?php if (empty($pendingRows)): ?>
    <div class="empty-state"><span class="empty-state-icon">✅</span><h3>Няма изчакващи заявки</h3></div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:1rem;">
<?php foreach ($pendingRows as $wr): ?>
<div class="card" style="border-color:rgba(201,168,76,0.3);">
    <div class="card-body">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
            <div>
                <div style="font-size:0.72rem;color:var(--text-dim);margin-bottom:0.3rem;">
                    Подадена: <?= date('d.m.Y H:i', strtotime($wr['created_at'])) ?>
                    (<?= timeAgo($wr['created_at']) ?>)
                </div>
                <div style="font-size:1rem;font-weight:700;"><?= h($wr['user_name']) ?>
                    <span style="color:var(--text-dim);font-weight:400;font-size:0.82rem;margin-left:0.5rem;"><?= h($wr['user_email']) ?></span>
                </div>
                <?php if ($wr['method'] === 'iban'): ?>
                <div style="margin-top:0.5rem;font-size:0.875rem;color:var(--text-muted);">
                    🏦 IBAN: <code style="background:var(--navy-3);padding:0.2rem 0.5rem;border-radius:4px;font-size:0.82rem;"><?= h($wr['iban']) ?></code>
                    &nbsp; Титуляр: <strong><?= h($wr['account_name']) ?></strong>
                </div>
                <?php else: ?>
                <div style="margin-top:0.5rem;font-size:0.875rem;color:var(--text-muted);">💳 По карта (Stripe)</div>
                <?php endif; ?>
            </div>
            <div style="font-family:'Playfair Display',serif;font-size:1.75rem;color:var(--gold);font-weight:700;white-space:nowrap;">
                <?= formatMoney($wr['amount']) ?>
            </div>
        </div>
        <form method="post" style="display:flex;align-items:flex-end;gap:0.75rem;flex-wrap:wrap;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="wr_id" value="<?= $wr['id'] ?>">
            <div class="form-group" style="flex:1;min-width:180px;margin:0;">
                <input type="text" name="wr_note" class="form-control" placeholder="Бележка за потребителя (по желание)">
            </div>
            <button type="submit" name="wr_action" value="approve" class="btn btn-success"
                onclick="return confirm('Одобри теглене от <?= h($wr['user_name']) ?> — <?= formatMoney($wr['amount']) ?>?')">
                ✓ Одобри
            </button>
            <button type="submit" name="wr_action" value="reject" class="btn btn-outline" style="border-color:var(--danger);color:var(--danger);"
                onclick="return confirm('Откажи и върни средствата на потребителя?')">
                ✕ Откажи
            </button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ═══════════════ HISTORY ═══════════════ -->

<!-- Summary -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <?php foreach ([
        ['Одобрени',  $histTotals['approved'],                     '✅', 'var(--success)'],
        ['Отказани',  $histTotals['rejected'],                     '✕',  'var(--danger)'],
        ['Изплатено', formatMoney($histTotals['amount_approved']), '💸', 'var(--success)'],
        ['Върнато',   formatMoney($histTotals['amount_rejected']), '↩',  'var(--text-muted)'],
    ] as [$lbl, $val, $ico, $col]): ?>
    <div style="background:var(--navy-2);border:1px solid var(--border-dim);border-radius:var(--radius-lg);padding:1rem;text-align:center;">
        <div style="font-size:1.3rem;"><?= $ico ?></div>
        <div style="font-family:'Playfair Display',serif;font-size:1.2rem;color:<?= $col ?>;font-weight:700;"><?= $val ?></div>
        <div style="font-size:0.72rem;color:var(--text-dim);"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="get" style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem;">
    <input type="hidden" name="view" value="history">
    <div class="form-group" style="margin:0;flex:1;min-width:200px;">
        <label class="form-label" style="font-size:0.75rem;">Търси</label>
        <input type="text" name="s" class="form-control" value="<?= h($search) ?>" placeholder="Потребител, имейл, IBAN…">
    </div>
    <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:0.75rem;">Статус</label>
        <select name="status" class="form-control">
            <option value="">Всички</option>
            <option value="approved" <?= $status==='approved'?'selected':'' ?>>✅ Одобрени</option>
            <option value="rejected" <?= $status==='rejected'?'selected':'' ?>>✕ Отказани</option>
        </select>
    </div>
    <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:0.75rem;">Метод</label>
        <select name="method" class="form-control">
            <option value="">Всички</option>
            <option value="iban" <?= $method==='iban'?'selected':'' ?>>🏦 IBAN</option>
            <option value="card" <?= $method==='card'?'selected':'' ?>>💳 Карта</option>
        </select>
    </div>
    <input type="hidden" name="sort" value="<?= h($sortBy) ?>">
    <input type="hidden" name="dir"  value="<?= strtolower($sortDir) ?>">
    <button type="submit" class="btn btn-primary btn-sm">Филтрирай</button>
    <?php if ($search||$status||$method): ?><a href="?view=history" class="btn btn-outline btn-sm">✕ Изчисти</a><?php endif; ?>
</form>

<?php if (empty($histRows)): ?>
    <div class="empty-state"><span class="empty-state-icon">📭</span><h3>Няма намерени записи</h3></div>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead><tr>
        <th>#</th>
        <th>Потребител</th>
        <th><a href="<?= sortUrl('amount') ?>" style="color:inherit;text-decoration:none;">Сума<?= sortArrow('amount') ?></a></th>
        <th><a href="<?= sortUrl('method') ?>" style="color:inherit;text-decoration:none;">Метод<?= sortArrow('method') ?></a></th>
        <th>IBAN / Детайли</th>
        <th><a href="<?= sortUrl('status') ?>" style="color:inherit;text-decoration:none;">Статус<?= sortArrow('status') ?></a></th>
        <th>Администратор</th>
        <th><a href="<?= sortUrl('date') ?>" style="color:inherit;text-decoration:none;">Дата<?= sortArrow('date') ?></a></th>
    </tr></thead>
    <tbody>
    <?php foreach ($histRows as $r):
        $sc = ['approved'=>['pill-open','✓ Одобрена'],'rejected'=>['pill-disputed','✕ Отказана']];
        [$cls,$lbl] = $sc[$r['status']] ?? ['pill-pending', $r['status']];
    ?>
    <tr>
        <td style="color:var(--text-dim);font-size:0.75rem;"><?= str_pad($r['id'],4,'0',STR_PAD_LEFT) ?></td>
        <td>
            <div style="font-weight:600;font-size:0.875rem;"><?= h($r['user_name']) ?></div>
            <div style="font-size:0.72rem;color:var(--text-dim);"><?= h($r['user_email']) ?></div>
        </td>
        <td style="font-family:'Playfair Display',serif;color:var(--gold);font-weight:700;white-space:nowrap;"><?= formatMoney($r['amount']) ?></td>
        <td><span class="pill pill-pending"><?= $r['method']==='iban'?'🏦 IBAN':'💳 Карта' ?></span></td>
        <td style="font-size:0.78rem;color:var(--text-muted);max-width:200px;">
            <?= $r['method']==='iban' ? h($r['iban']??'—').'<br><span style="color:var(--text-dim);">'.h($r['account_name']??'').'</span>' : 'По карта' ?>
            <?php if($r['admin_note']): ?><div style="color:var(--text-dim);font-style:italic;font-size:0.72rem;">"<?= h(mb_strimwidth($r['admin_note'],0,50,'...','UTF-8')) ?>"</div><?php endif; ?>
        </td>
        <td><span class="pill <?= $cls ?>"><?= $lbl ?></span></td>
        <td style="font-size:0.82rem;"><?= h($r['admin_name']??'—') ?><?php if($r['processed_at']): ?><div style="font-size:0.7rem;color:var(--text-dim);"><?= date('d.m.Y H:i',strtotime($r['processed_at'])) ?></div><?php endif; ?></td>
        <td style="font-size:0.75rem;color:var(--text-dim);white-space:nowrap;"><?= date('d.m.Y',strtotime($r['created_at'])) ?><br><span style="font-size:0.7rem;"><?= date('H:i',strtotime($r['created_at'])) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div style="margin-top:0.5rem;font-size:0.78rem;color:var(--text-dim);">Показани <?= count($histRows) ?> записа</div>
<?php endif; ?>
<?php endif; ?>
</div>
<style>
@media (max-width: 768px) {
    .table-wrap { overflow-x: auto; }
    table { min-width: 600px; }
    /* Approve/reject buttons */
    [style*="display:flex;gap:0.5rem"] { flex-direction: column; }
    [style*="display:flex;gap:0.5rem"] .btn { width: 100%; text-align: center; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
