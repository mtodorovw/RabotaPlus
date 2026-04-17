<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAuth();
$id   = (int)($_GET['id'] ?? 0);
$isAdmin = $user['role'] === 'admin';

// Admin can see any contract; users only their own
$st = $isAdmin
    ? db()->prepare("SELECT c.*, l.title AS listing_title, l.id AS listing_id,
        e.name AS emp_name, e.avatar AS emp_avatar,
        co.name AS contractor_name, co.avatar AS contractor_avatar
        FROM contracts c JOIN listings l ON c.listing_id=l.id
        JOIN users e ON c.employer_id=e.id JOIN users co ON c.contractor_id=co.id
        WHERE c.id=?")
    : db()->prepare("SELECT c.*, l.title AS listing_title, l.id AS listing_id,
        e.name AS emp_name, e.avatar AS emp_avatar,
        co.name AS contractor_name, co.avatar AS contractor_avatar
        FROM contracts c JOIN listings l ON c.listing_id=l.id
        JOIN users e ON c.employer_id=e.id JOIN users co ON c.contractor_id=co.id
        WHERE c.id=? AND (c.employer_id=? OR c.contractor_id=?)");

$params = $isAdmin ? [$id] : [$id, $user['id'], $user['id']];
$st->execute($params);
$contract = $st->fetch();
if (!$contract) { flash('Договорът не е намерен.', 'error'); redirect(url('contracts/index.php')); }

$isEmployer   = (int)$user['id'] === (int)$contract['employer_id'];
$isContractor = (int)$user['id'] === (int)$contract['contractor_id'];

// Handle actions — admin can also act IF they are a participant (employer/contractor)
$canAct = !$isAdmin || $isEmployer || $isContractor;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canAct) {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'confirm' && $contract['status'] === 'active') {
        $field = $isEmployer ? 'employer_confirmed' : 'contractor_confirmed';
        $pdo->prepare("UPDATE contracts SET $field=1 WHERE id=?")->execute([$id]);

        $updated = $pdo->prepare('SELECT * FROM contracts WHERE id=?');
        $updated->execute([$id]);
        $c2 = $updated->fetch();

        // Notify the other party about confirmation
        $otherId = $isEmployer ? $contract['contractor_id'] : $contract['employer_id'];
        $myRole  = $isEmployer ? 'Работодателят' : 'Изпълнителят';
        addNotification($otherId, 'confirmed',
            $myRole . ' потвърди завършването на „' . mb_strimwidth($contract['listing_title'],0,50,'...','UTF-8') . '"',
            url('contracts/view.php?id=' . $id)
        );

        if ($c2['employer_confirmed'] && $c2['contractor_confirmed']) {
            $commission     = round($c2['amount'] * 0.05, 2);          // 5% комисионна
            $contractorPays = round($c2['amount'] - $commission, 2);   // сумата за изпълнителя
            $pdo->prepare("UPDATE contracts SET status='completed', escrow_held=0, completed_at=NOW() WHERE id=?")->execute([$id]);
            // Update employer's escrow_lock → escrow_release so it shows as 💸 Плащане
            $pdo->prepare("UPDATE transactions SET type='escrow_release', description=? WHERE contract_id=? AND user_id=? AND type='escrow_lock' ORDER BY id DESC LIMIT 1")
                ->execute(['Плащане за завършен договор #' . $id, $id, $c2['employer_id']]);
            $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$contractorPays, $c2['contractor_id']]);
            logTransaction($c2['contractor_id'], 'escrow_release', $contractorPays,
                'Плащане за завършен договор #' . $id . ' (след 5% комисионна)', $id);
            // Log commission
            logTransaction($c2['employer_id'], 'commission', -$commission,
                'Комисионна 5% за договор #' . $id, $id);

            addNotification($c2['contractor_id'], 'escrow_release',
                'Получи ' . formatMoney($contractorPays) . ' за „' . mb_strimwidth($contract['listing_title'],0,50,'...','UTF-8') . '" (след 5% комисионна)',
                url('contracts/view.php?id=' . $id)
            );
            flash('Договорът е завършен! Сумата е преведена. 🎉');
        } else {
            flash('Потвърждението е записано. Изчакваме другата страна.');
        }
    }

    if ($action === 'unconfirm' && $contract['status'] === 'active') {
        $field = $isEmployer ? 'employer_confirmed' : 'contractor_confirmed';
        $pdo->prepare("UPDATE contracts SET $field=0 WHERE id=?")->execute([$id]);
        flash('Потвърждението е отменено.');
    }

    if ($action === 'dispute' && $contract['status'] === 'active') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason) {
            $pdo->prepare("UPDATE contracts SET status='disputed' WHERE id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO disputes (contract_id, opened_by, reason) VALUES (?,?,?)")->execute([$id, $user['id'], $reason]);
            flash('Спорът е отворен. Администратор ще разгледа случая.', 'info');
        } else {
            flash('Въведи причина.', 'error');
        }
    }

    redirect(url('contracts/view.php?id=' . $id));
}

// Reload
$st->execute($params);
$contract = $st->fetch();

// Dispute
$dispute = null;
$st2 = db()->prepare('SELECT d.*, u.name AS opener_name FROM disputes d JOIN users u ON d.opened_by=u.id WHERE d.contract_id=? ORDER BY d.created_at DESC LIMIT 1');
$st2->execute([$id]);
$dispute = $st2->fetch();

// Chat
$chat = null;
if (!$isAdmin) {
    $chatSt = db()->prepare('SELECT id FROM chats WHERE listing_id=? AND applicant_id=?');
    $chatSt->execute([$contract['listing_id'], $contract['contractor_id']]);
    $chat = $chatSt->fetch();
}

// Transactions
$txSt = db()->prepare('SELECT t.*, u.name FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.contract_id=? ORDER BY t.created_at DESC');
$txSt->execute([$id]);
$transactions = $txSt->fetchAll();

$pageTitle = 'Договор #'.$id.' — '.SITE_NAME;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-wide fade-in">
<div class="breadcrumb">
    <?php if ($isAdmin): ?>
        <a href="<?= url('admin/disputes.php') ?>">Администрация</a>
    <?php else: ?>
        <a href="<?= url('contracts/index.php') ?>">Договори</a>
    <?php endif; ?>
    <span class="breadcrumb-sep">›</span>
    <span>Договор #<?= $id ?></span>
</div>

<?php if ($dispute && in_array($contract['status'], ['disputed'])): ?>
<div class="dispute-banner">
    <h4>⚠️ Активен спор</h4>
    <p>Отворен от <?= h($dispute['opener_name']) ?> — „<?= h(mb_strimwidth($dispute['reason'],0,120,'...','UTF-8')) ?>"</p>
</div>
<?php endif; ?>

<div class="contract-detail">
<div style="display:flex;flex-direction:column;gap:1.5rem;">

    <div class="card">
        <div class="card-header">
            <h2 style="font-size:1.2rem;font-family:'DM Sans',sans-serif;"><?= h($contract['listing_title']) ?></h2>
            <span class="pill pill-<?= $contract['status'] ?>"><?= ['active'=>'Активен','completed'=>'Завършен','disputed'=>'Спор','cancelled'=>'Отменен'][$contract['status']] ?></span>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.25rem;" class="contract-parties-grid">
                <div>
                    <div style="font-size:0.72rem;text-transform:uppercase;color:var(--text-dim);margin-bottom:0.3rem;">Работодател</div>
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <img src="<?= avatarUrl($contract['emp_avatar'], $contract['emp_name']) ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                        <span style="font-weight:600;"><?= h($contract['emp_name']) ?></span>
                    </div>
                </div>
                <div>
                    <div style="font-size:0.72rem;text-transform:uppercase;color:var(--text-dim);margin-bottom:0.3rem;">Изпълнител</div>
                    <div style="display:flex;align-items:center;gap:0.6rem;">
                        <img src="<?= avatarUrl($contract['contractor_avatar'], $contract['contractor_name']) ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                        <span style="font-weight:600;"><?= h($contract['contractor_name']) ?></span>
                    </div>
                </div>
            </div>
            <div style="display:flex;justify-content:space-around;align-items:center;gap:1rem;text-align:center;">
                <div style="display:flex;flex-direction:column;align-items:center;gap:0.2rem;">
                    <div style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--gold);line-height:1.2;"><?= formatMoney($contract['amount']) ?></div>
                    <div style="font-size:0.72rem;color:var(--text-dim);">Сума</div>
                </div>
                <div style="width:1px;height:2.5rem;background:var(--border-dim);"></div>
                <div style="display:flex;flex-direction:column;align-items:center;gap:0.2rem;">
                    <div style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--gold);line-height:1.2;"><?= date('d.m.Y', strtotime($contract['created_at'])) ?></div>
                    <div style="font-size:0.72rem;color:var(--text-dim);">Създаден</div>
                </div>
                <div style="width:1px;height:2.5rem;background:var(--border-dim);"></div>
                <div style="display:flex;flex-direction:column;align-items:center;gap:0.2rem;">
                    <div style="font-family:'Playfair Display',serif;font-size:1.5rem;color:var(--gold);line-height:1.2;"><?= $contract['completed_at'] ? date('d.m.Y', strtotime($contract['completed_at'])) : '—' ?></div>
                    <div style="font-size:0.72rem;color:var(--text-dim);">Завършен</div>
                </div>
            </div>
        </div>
        <?php if ($chat): ?>
        <div class="card-footer">
            <a href="<?= url('messages/chat.php?id='.$chat['id']) ?>" class="btn btn-outline btn-sm">💬 Чат</a>
            <a href="<?= url('listings/view.php?id='.$contract['listing_id']) ?>" class="btn btn-outline btn-sm">📋 Обявата</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (($canAct) && $contract['status']==='active'): ?>
    <div class="confirm-box" id="confirm-box">
        <h3>Потвърждение за завършване</h3>
        <p style="font-size:0.85rem;color:var(--text-muted);">И двете страни трябва да потвърдят.</p>
        <div class="confirm-status">
            <div class="confirm-row">
                <span><?= h($contract['emp_name']) ?> (Работодател)</span>
                <span id="conf-employer"><?= $contract['employer_confirmed'] ? '<span class="checkmark">✅</span>' : '<span class="xmark">⬜</span>' ?></span>
            </div>
            <div class="confirm-row">
                <span><?= h($contract['contractor_name']) ?> (Изпълнител)</span>
                <span id="conf-contractor"><?= $contract['contractor_confirmed'] ? '<span class="checkmark">✅</span>' : '<span class="xmark">⬜</span>' ?></span>
            </div>
        </div>
        <?php $myConf = $isEmployer ? $contract['employer_confirmed'] : $contract['contractor_confirmed']; ?>
        <form method="post" style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap;" id="confirm-form">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" id="confirm-action" value="<?= !$myConf ? 'confirm' : 'unconfirm' ?>">
            <button type="submit" id="confirm-btn" class="<?= !$myConf ? 'btn btn-success' : 'btn btn-outline btn-sm' ?>">
                <?= !$myConf ? '✓ Потвърди завършването' : 'Отмени потвърждението' ?>
            </button>
        </form>
    </div>
    <script>
    (function() {
        const CONTRACT_ID = <?= $contract['id'] ?>;
        const IS_EMPLOYER = <?= $isEmployer ? 'true' : 'false' ?>;
        let lastEmp = <?= (int)$contract['employer_confirmed'] ?>;
        let lastCon = <?= (int)$contract['contractor_confirmed'] ?>;

        function updateConfirmUI(empConf, conConf) {
            // Update the checkmarks
            const empEl = document.getElementById('conf-employer');
            const conEl = document.getElementById('conf-contractor');
            if (empEl) empEl.innerHTML = empConf ? '<span class="checkmark">✅</span>' : '<span class="xmark">⬜</span>';
            if (conEl) conEl.innerHTML = conConf ? '<span class="checkmark">✅</span>' : '<span class="xmark">⬜</span>';

            // Update MY button
            const myConf = IS_EMPLOYER ? empConf : conConf;
            const btn    = document.getElementById('confirm-btn');
            const action = document.getElementById('confirm-action');
            if (btn && action) {
                action.value    = myConf ? 'unconfirm' : 'confirm';
                btn.textContent = myConf ? 'Отмени потвърждението' : '✓ Потвърди завършването';
                btn.className   = myConf ? 'btn btn-outline btn-sm' : 'btn btn-success';
            }
        }

        function pollConfirm() {
            fetch(BASE_URL + '/api/poll.php?type=contract_status&contract_id=' + CONTRACT_ID)
                .then(r => r.json())
                .then(data => {
                    if (!data || !('employer_confirmed' in data)) return;

                    const emp = parseInt(data.employer_confirmed);
                    const con = parseInt(data.contractor_confirmed);

                    // Only update DOM if something changed
                    if (emp !== lastEmp || con !== lastCon) {
                        lastEmp = emp;
                        lastCon = con;
                        updateConfirmUI(emp, con);

                        // If both confirmed, reload to show completion
                        if (data.status === 'completed') {
                            window.location.reload();
                        }
                    }
                })
                .catch(() => {});
        }

        setInterval(pollConfirm, 3000);
    })();
    </script>

    <div class="card">
        <div class="card-header" style="cursor:pointer;" onclick="document.getElementById('dispute-form').classList.toggle('hidden')">
            <h3 style="font-size:1rem;font-family:'DM Sans',sans-serif;color:var(--danger);">⚠️ Отвори спор</h3>
            <span style="font-size:0.8rem;color:var(--text-dim);">При несъгласие</span>
        </div>
        <div class="card-body hidden" id="dispute-form">
            <form method="post" style="display:flex;flex-direction:column;gap:1rem;">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="dispute">
                <div class="form-group">
                    <label class="form-label">Причина</label>
                    <textarea name="reason" class="form-control" rows="3" required></textarea>
                </div>
                <button type="submit" class="btn btn-danger" onclick="return confirm('Сигурен ли си?')">Отвори спор</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($transactions)): ?>
    <div>
        <div class="section-title">Транзакции</div>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Тип</th><th>Потребител</th><th>Сума</th><th>Описание</th><th>Дата</th></tr></thead>
            <tbody>
            <?php foreach ($transactions as $tx): ?>
            <tr>
                <td><span class="pill <?= $tx['type']==='escrow_lock'?'pill-pending':($tx['amount']>=0?'pill-open':'pill-disputed') ?>"><?= ['deposit'=>'Депозит','escrow_lock'=>'🔒 Ескроу','escrow_release'=>'💸 Плащане','refund'=>'↩ Върнати','withdrawal'=>'⬇ Теглене','commission'=>'🏦 Комисионна'][$tx['type']] ?? $tx['type'] ?></span></td>
                <td><?= h($tx['name']) ?></td>
                <td style="color:<?= $tx['amount']>=0?'var(--success)':'var(--danger)' ?>;font-weight:600;"><?= $tx['amount']>=0?'+':'' ?><?= formatMoney(abs($tx['amount'])) ?></td>
                <td style="font-size:0.82rem;color:var(--text-muted);"><?= h($tx['description']??'') ?></td>
                <td style="font-size:0.78rem;color:var(--text-dim);"><?= date('d.m.Y H:i', strtotime($tx['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Right sidebar -->
<div>
    <div class="escrow-box mb-2">
        <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-dim);margin-bottom:0.5rem;">Ескроу</div>
        <div class="escrow-amount"><?= formatMoney($contract['amount']) ?></div>
        <div class="escrow-label"><?= $contract['escrow_held'] ? '🔒 Заключена' : '✅ Освободена' ?></div>
    </div>

    <div style="padding:1.25rem;background:var(--navy-2);border:1px solid var(--border-dim);border-radius:var(--radius-lg);">
        <div style="font-size:0.78rem;text-transform:uppercase;color:var(--text-dim);margin-bottom:0.75rem;">Стъпки</div>
        <div class="timeline">
            <div class="timeline-item"><div class="timeline-dot"></div><div><div class="timeline-text">Ескроу заключен</div></div></div>
            <div class="timeline-item"><div class="timeline-dot" style="<?= (!$contract['employer_confirmed'] && !$contract['contractor_confirmed'])?'background:var(--navy-4)':'' ?>"></div><div><div class="timeline-text">Потвърждения</div></div></div>
            <div class="timeline-item"><div class="timeline-dot" style="<?= $contract['status']!=='completed'?'background:var(--navy-4)':'' ?>"></div><div><div class="timeline-text">Сумата преведена</div></div></div>
        </div>
    </div>
</div>
</div>
</div>
<style>
@media (max-width: 768px) {
    .contract-parties-grid { grid-template-columns: 1fr !important; }
    [style*="display:flex;justify-content:space-around"] { flex-wrap: wrap; gap: 0.5rem !important; }
    [style*="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center"] { flex-direction: column; align-items: stretch !important; }
    [style*="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center"] .btn,
    [style*="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center"] a.btn { width: 100%; text-align: center; justify-content: center; }
    .table-wrap { overflow-x: auto; }
    .confirm-status .confirm-row { padding: 0.5rem 0.75rem; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
