<?php
require_once __DIR__ . '/../includes/functions.php';
$admin = requireAdmin();
$pageTitle = 'Администрация — ' . SITE_NAME;

// ── AJAX: poll messages ───────────────────────────────────
if (isset($_GET['poll_chat'])) {
    header('Content-Type: application/json');
    $chatId = (int)($_GET['poll_chat'] ?? 0);
    $lastId = (int)($_GET['last_id']   ?? 0);
    $st = db()->prepare('SELECT m.id, m.body, m.attachment, m.attachment_type, m.created_at, m.sender_id,
        u.name AS sender_name, u.avatar AS sender_avatar, u.role AS sender_role
        FROM messages m JOIN users u ON m.sender_id = u.id
        WHERE m.chat_id = ? AND m.id > ? ORDER BY m.created_at ASC');
    $st->execute([$chatId, $lastId]);
    $msgs = $st->fetchAll();
    $out = [];
    foreach ($msgs as $m) {
        $out[] = [
            'id'              => (int)$m['id'],
            'body'            => $m['body'],
            'attachment'      => $m['attachment'] ? url($m['attachment']) : null,
            'attachment_type' => $m['attachment_type'] ?? null,
            'sender_id'       => (int)$m['sender_id'],
            'sender_name'     => $m['sender_name'],
            'sender_role'     => $m['sender_role'],
            'avatar'          => avatarUrl($m['sender_avatar'], $m['sender_name']),
            'time_ago'        => timeAgo($m['created_at']),
            'is_admin'        => $m['sender_role'] === 'admin',
            'created_at'      => $m['created_at'],
        ];
    }
    echo json_encode(['messages' => $out]);
    exit;
}

// ── AJAX: send message ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_chat'])) {
    header('Content-Type: application/json');
    $chatId     = (int)($_POST['chat_id'] ?? 0);
    $body       = trim($_POST['body'] ?? '');
    $attachment = trim($_POST['attachment'] ?? '');
    $attType    = $_POST['attachment_type'] ?? null;
    if (!$chatId || (!$body && !$attachment)) { echo json_encode(['ok' => false]); exit; }

    // Only assigned admin can send (unless no one is assigned yet)
    $dispSt = db()->prepare('SELECT d.id, d.assigned_to, c.employer_id, c.contractor_id
        FROM disputes d JOIN contracts c ON d.contract_id=c.id
        JOIN chats ch ON ch.listing_id=c.listing_id WHERE ch.id=? AND d.status="open" LIMIT 1');
    $dispSt->execute([$chatId]);
    $disp = $dispSt->fetch();
    if ($disp && $disp['assigned_to'] && (int)$disp['assigned_to'] !== (int)$admin['id']) {
        echo json_encode(['ok' => false, 'err' => 'Не си поел този спор']); exit;
    }

    $chk = db()->prepare('SELECT id FROM chats WHERE id=? LIMIT 1');
    $chk->execute([$chatId]);
    if (!$chk->fetch()) { echo json_encode(['ok' => false, 'err' => 'Chat not found']); exit; }

    $msgBody = $body ? '[Администратор] ' . $body : null;
    db()->prepare('INSERT INTO messages (chat_id, sender_id, body, attachment, attachment_type) VALUES (?,?,?,?,?)')
       ->execute([$chatId, $admin['id'], $msgBody, $attachment ?: null, $attType ?: null]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Handle: take dispute ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['take_dispute'])) {
    verifyCsrf();
    $disputeId = (int)$_POST['take_dispute'];

    // Load dispute + contract to check if admin is participant
    $dSt = db()->prepare('SELECT d.*, c.employer_id, c.contractor_id FROM disputes d
        JOIN contracts c ON d.contract_id=c.id WHERE d.id=? AND d.status="open"');
    $dSt->execute([$disputeId]);
    $d = $dSt->fetch();

    if (!$d) { flash('Спорът не е намерен.', 'error'); }
    elseif ((int)$d['employer_id'] === (int)$admin['id'] || (int)$d['contractor_id'] === (int)$admin['id']) {
        flash('Не можеш да поемеш спор в който участваш.', 'error');
    } elseif ($d['assigned_to']) {
        flash('Спорът вече е поет от друг администратор.', 'error');
    } else {
        db()->prepare('UPDATE disputes SET assigned_to=?, assigned_at=NOW() WHERE id=?')
           ->execute([$admin['id'], $disputeId]);
        flash('Поел си спора успешно.');
    }
    redirect(url('admin/disputes.php'));
}

// ── Handle: release dispute ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['release_dispute'])) {
    verifyCsrf();
    $disputeId = (int)$_POST['release_dispute'];
    $dSt = db()->prepare('SELECT assigned_to FROM disputes WHERE id=? AND status="open"');
    $dSt->execute([$disputeId]);
    $d = $dSt->fetch();
    if ($d && (int)$d['assigned_to'] === (int)$admin['id']) {
        db()->prepare('UPDATE disputes SET assigned_to=NULL, assigned_at=NULL WHERE id=?')->execute([$disputeId]);
        flash('Освободил си спора — връща се в Отворени.');
    }
    redirect(url('admin/disputes.php'));
}

// ── Handle: resolve dispute ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispute_id'])) {
    verifyCsrf();
    $disputeId  = (int)$_POST['dispute_id'];
    $resolution = $_POST['resolution'];
    $note       = trim($_POST['admin_note'] ?? '');

    $dSt = db()->prepare('SELECT d.*, c.amount, c.employer_id, c.contractor_id, c.listing_id,
        l.title AS listing_title FROM disputes d
        JOIN contracts c ON d.contract_id=c.id JOIN listings l ON c.listing_id=l.id
        WHERE d.id=? AND d.status="open"');
    $dSt->execute([$disputeId]);
    $dispute = $dSt->fetch();

    if (!$dispute) { flash('Спорът не е намерен.', 'error'); redirect(url('admin/disputes.php')); }

    // Only assigned admin can resolve
    if ((int)$dispute['assigned_to'] !== (int)$admin['id']) {
        flash('Само поелият спора администратор може да го реши.', 'error');
        redirect(url('admin/disputes.php'));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($resolution === 'employer') {
            $pdo->prepare('UPDATE users SET balance=balance+? WHERE id=?')
                ->execute([$dispute['amount'], $dispute['employer_id']]);
            $desc = 'Върнати средства след спор — ' . $dispute['listing_title'];
            $pdo->prepare("UPDATE transactions SET type='refund', amount=ABS(amount), description=? WHERE contract_id=? AND user_id=? AND type='escrow_lock' ORDER BY id DESC LIMIT 1")
                ->execute([$desc, $dispute['contract_id'], $dispute['employer_id']]);
            addNotification($dispute['employer_id'], 'deposit',
                'Спорът за „'.$dispute['listing_title'].'" беше решен в твоя полза. Средствата са върнати.',
                url('contracts/view.php?id='.$dispute['contract_id']));
            addNotification($dispute['contractor_id'], 'dispute',
                'Спорът за „'.$dispute['listing_title'].'" беше решен в полза на работодателя.',
                url('contracts/view.php?id='.$dispute['contract_id']));
        } else {
            // Pay contractor with 5% commission deducted
            $commission     = round($dispute['amount'] * 0.05, 2);
            $contractorPays = round($dispute['amount'] - $commission, 2);
            $pdo->prepare('UPDATE users SET balance=balance+? WHERE id=?')
                ->execute([$contractorPays, $dispute['contractor_id']]);
            $empDesc = 'Плащане за завършен договор (спор) — '.$dispute['listing_title'];
            $pdo->prepare("UPDATE transactions SET type='escrow_release', description=? WHERE contract_id=? AND user_id=? AND type='escrow_lock' ORDER BY id DESC LIMIT 1")
                ->execute([$empDesc, $dispute['contract_id'], $dispute['employer_id']]);
            logTransaction($dispute['contractor_id'], 'escrow_release', $contractorPays,
                'Плащане за завършен договор (спор, след 5% комисионна) — '.$dispute['listing_title'], $dispute['contract_id']);
            logTransaction($dispute['employer_id'], 'commission', -$commission,
                'Комисионна 5% за договор (спор) — '.$dispute['listing_title'], $dispute['contract_id']);
            addNotification($dispute['contractor_id'], 'escrow_release',
                'Спорът за „'.$dispute['listing_title'].'" беше решен в твоя полза. Получи '.formatMoney($contractorPays).' (след 5% комисионна).',
                url('contracts/view.php?id='.$dispute['contract_id']));
            addNotification($dispute['employer_id'], 'dispute',
                'Спорът за „'.$dispute['listing_title'].'" беше решен в полза на изпълнителя.',
                url('contracts/view.php?id='.$dispute['contract_id']));
        }
        $pdo->prepare("UPDATE contracts SET status='completed', escrow_held=0, completed_at=NOW() WHERE id=?")->execute([$dispute['contract_id']]);
        $pdo->prepare("UPDATE disputes SET status='resolved', resolution=?, resolved_by=?, admin_note=?, resolved_at=NOW() WHERE id=?")->execute([$resolution, $admin['id'], $note, $disputeId]);
        $pdo->commit();
        flash('Спорът е решен успешно.');
    } catch (Exception $e) { $pdo->rollBack(); flash('Грешка: '.$e->getMessage(), 'error'); }
    redirect(url('admin/disputes.php'));
}

// ── Load data ─────────────────────────────────────────────
// Unassigned open disputes
$openSt = db()->prepare("
    SELECT d.*, c.amount, c.listing_id, c.employer_id, c.contractor_id,
        l.title AS listing_title,
        e.name AS emp_name, e.avatar AS emp_avatar,
        co.name AS contractor_name, co.avatar AS contractor_avatar,
        u.name AS opener_name
    FROM disputes d
    JOIN contracts c ON d.contract_id=c.id JOIN listings l ON c.listing_id=l.id
    JOIN users e ON c.employer_id=e.id JOIN users co ON c.contractor_id=co.id
    JOIN users u ON d.opened_by=u.id
    WHERE d.status='open' AND d.assigned_to IS NULL
    ORDER BY d.created_at DESC");
$openSt->execute();
$openDisputes = $openSt->fetchAll();

// Disputes assigned to ME
$mySt = db()->prepare("
    SELECT d.*, c.amount, c.listing_id, c.employer_id, c.contractor_id,
        l.title AS listing_title,
        e.name AS emp_name, e.avatar AS emp_avatar,
        co.name AS contractor_name, co.avatar AS contractor_avatar,
        u.name AS opener_name
    FROM disputes d
    JOIN contracts c ON d.contract_id=c.id JOIN listings l ON c.listing_id=l.id
    JOIN users e ON c.employer_id=e.id JOIN users co ON c.contractor_id=co.id
    JOIN users u ON d.opened_by=u.id
    WHERE d.status='open' AND d.assigned_to=?
    ORDER BY d.assigned_at DESC");
$mySt->execute([$admin['id']]);
$myDisputes = $mySt->fetchAll();

// Resolved disputes
$resSt = db()->prepare("
    SELECT d.*, c.amount, l.title AS listing_title,
        e.name AS emp_name, co.name AS contractor_name, adm.name AS admin_name
    FROM disputes d
    JOIN contracts c ON d.contract_id=c.id JOIN listings l ON c.listing_id=l.id
    JOIN users e ON c.employer_id=e.id JOIN users co ON c.contractor_id=co.id
    LEFT JOIN users adm ON d.resolved_by=adm.id
    WHERE d.status='resolved' ORDER BY d.resolved_at DESC LIMIT 20");
$resSt->execute();
$resolvedDisputes = $resSt->fetchAll();

$stats = db()->query("SELECT
    (SELECT COUNT(*) FROM users) total_users,
    (SELECT COUNT(*) FROM listings) total_listings,
    (SELECT COUNT(*) FROM contracts) total_contracts,
    (SELECT COALESCE(SUM(amount),0) FROM contracts WHERE status='completed') total_paid,
    (SELECT COUNT(*) FROM disputes WHERE status='open') open_disputes")->fetch();

$pendingWrCount = 0;
try { $pendingWrCount = (int)db()->query("SELECT COUNT(*) FROM withdrawal_requests WHERE status='pending'")->fetchColumn(); } catch(Exception $e){}

require_once __DIR__ . '/../includes/header.php';

// Helper: render a dispute card
function renderDisputeCard(array $d, array $admin, bool $isAssigned): void {
    $chatSt = db()->prepare('SELECT ch.id FROM chats ch JOIN contracts ct ON ct.listing_id=ch.listing_id WHERE ct.id=? LIMIT 1');
    $chatSt->execute([$d['contract_id']]);
    $chatRow = $chatSt->fetch();
    $chatId  = $chatRow ? (int)$chatRow['id'] : 0;
    $chatMsgs = []; $lastMsgId = 0;
    if ($chatId) {
        $msgSt = db()->prepare('SELECT m.id, m.body, m.created_at, m.sender_id, u.name AS sender_name, u.avatar AS sender_avatar, u.role AS sender_role FROM messages m JOIN users u ON m.sender_id=u.id WHERE m.chat_id=? ORDER BY m.created_at ASC');
        $msgSt->execute([$chatId]);
        $chatMsgs  = $msgSt->fetchAll();
        $lastMsgId = $chatMsgs ? (int)end($chatMsgs)['id'] : 0;
    }
    $isParticipant = (int)$d['employer_id'] === (int)$admin['id'] || (int)$d['contractor_id'] === (int)$admin['id'];
    ?>
<div class="card" style="border-color:<?= $isAssigned ? 'rgba(201,168,76,0.4)' : 'rgba(248,81,73,0.25)' ?>;">
    <div class="card-body">
        <div class="flex-between mb-2">
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <h3 style="font-family:'DM Sans',sans-serif;font-size:1rem;margin:0;"><?= h($d['listing_title']) ?></h3>
                <?php if ($isAssigned): ?>
                <span style="font-size:0.72rem;background:rgba(201,168,76,0.15);color:var(--gold);border:1px solid rgba(201,168,76,0.3);border-radius:20px;padding:2px 8px;">⚙️ Поет от теб</span>
                <?php endif; ?>
            </div>
            <div style="font-family:'Playfair Display',serif;font-size:1.2rem;color:var(--gold);"><?= formatMoney($d['amount']) ?></div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;font-size:0.85rem;margin-bottom:1rem;">
            <div><div style="color:var(--text-dim);font-size:0.72rem;margin-bottom:0.2rem;">Работодател</div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <img src="<?= avatarUrl($d['emp_avatar'], $d['emp_name']) ?>" style="width:24px;height:24px;border-radius:50%;">
                    <strong><?= h($d['emp_name']) ?></strong>
                    <?php if ((int)$d['employer_id'] === (int)$admin['id']): ?><span style="font-size:0.68rem;color:var(--gold);">(ти)</span><?php endif; ?>
                </div>
            </div>
            <div><div style="color:var(--text-dim);font-size:0.72rem;margin-bottom:0.2rem;">Изпълнител</div>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <img src="<?= avatarUrl($d['contractor_avatar'], $d['contractor_name']) ?>" style="width:24px;height:24px;border-radius:50%;">
                    <strong><?= h($d['contractor_name']) ?></strong>
                    <?php if ((int)$d['contractor_id'] === (int)$admin['id']): ?><span style="font-size:0.68rem;color:var(--gold);">(ти)</span><?php endif; ?>
                </div>
            </div>
            <div><div style="color:var(--text-dim);font-size:0.72rem;margin-bottom:0.2rem;">Отворен от</div>
                <strong><?= h($d['opener_name']) ?></strong>
                <div style="font-size:0.72rem;color:var(--text-dim);"><?= timeAgo($d['created_at']) ?></div>
            </div>
        </div>

        <div style="background:var(--navy-3);border:1px solid var(--border-dim);border-radius:var(--radius);padding:0.75rem 1rem;font-size:0.875rem;color:var(--text-muted);margin-bottom:1.25rem;">
            <strong>Причина:</strong> <?= h($d['reason']) ?>
        </div>

        <?php if (!$isAssigned && !$isParticipant): ?>
        <!-- Take button for unassigned disputes -->
        <form method="post" style="margin-bottom:1rem;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="take_dispute" value="<?= $d['id'] ?>">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Поемаш спора? Само ти ще можеш да го решиш.')">
                🙋 Поеми спора
            </button>
        </form>

        <?php elseif (!$isAssigned && $isParticipant): ?>
        <!-- Admin is participant - cannot take -->
        <div style="background:rgba(248,81,73,0.08);border:1px solid rgba(248,81,73,0.2);border-radius:var(--radius);padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.85rem;color:var(--danger);">
            ⚠️ Участваш в този спор — не можеш да го поемеш. Трябва друг администратор да го поеме.
        </div>

        <?php elseif ($isAssigned): ?>
        <!-- Full controls for assigned admin -->
        <?php if ($chatId): ?>
        <div style="margin-bottom:1.25rem;">
            <button onclick="toggleAdminChat(<?= $d['id'] ?>)" class="btn btn-outline btn-sm" style="margin-bottom:0.75rem;" id="chat-toggle-<?= $d['id'] ?>">💬 Чат между страните — покажи</button>
            <div id="admin-chat-wrap-<?= $d['id'] ?>" style="display:none;">
                <div style="background:var(--navy-3);border:1px solid var(--border-dim);border-radius:var(--radius-lg);overflow:hidden;">
                    <div id="admin-msgs-<?= $d['id'] ?>" style="max-height:340px;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:0.6rem;" data-chat-id="<?= $chatId ?>" data-last-id="<?= $lastMsgId ?>">
                        <?php if (empty($chatMsgs)): ?><div class="admin-no-msgs" style="text-align:center;color:var(--text-dim);font-size:0.85rem;">Няма съобщения</div><?php endif; ?>
                        <?php foreach ($chatMsgs as $m): $isMine = $m['sender_role'] === 'admin'; ?>
                        <div style="display:flex;gap:0.5rem;<?= $isMine?'align-self:flex-end;flex-direction:row-reverse;':'' ?>max-width:80%;">
                            <img src="<?= avatarUrl($m['sender_avatar'],$m['sender_name']) ?>" style="width:28px;height:28px;border-radius:50%;flex-shrink:0;align-self:flex-end;">
                            <div>
                                <div style="font-size:0.68rem;color:var(--text-dim);margin-bottom:2px;<?= $isMine?'text-align:right':'' ?>"><?= h($m['sender_name']) ?> · <span class="msg-time-rel time-ago-live" data-created="<?= h($m['created_at']) ?>"><?= timeAgo($m['created_at']) ?></span></div>
                                <div style="padding:0.5rem 0.85rem;border-radius:12px;font-size:0.875rem;<?= $isMine?'background:linear-gradient(135deg,#8c6c20,#C9A84C);color:var(--navy);border-bottom-right-radius:4px;':'background:var(--navy-2);border:1px solid var(--border-dim);color:var(--text);border-bottom-left-radius:4px;' ?>"><?= nl2br(h($m['body'] ?? '')) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="admin-att-bar-<?= $d['id'] ?>" style="display:none;padding:0.35rem 0.75rem;background:var(--navy-3);border-top:1px solid var(--border-dim);align-items:center;gap:0.5rem;">
                        <span id="admin-att-lbl-<?= $d['id'] ?>" style="flex:1;font-size:0.8rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                        <button onclick="adminClearAtt(<?= $d['id'] ?>)" style="background:none;border:none;color:var(--text-dim);cursor:pointer;padding:0;">✕</button>
                    </div>
                    <div id="admin-emoji-<?= $d['id'] ?>" style="display:none;background:var(--navy-2);border-top:1px solid var(--border-dim);padding:0.5rem;">
                        <div style="display:flex;overflow-x:auto;gap:3px;margin-bottom:0.4rem;padding-bottom:0.3rem;border-bottom:1px solid var(--border-dim);">
                            <?php foreach (['😀'=>'Усмивки','👍'=>'Жестове','❤️'=>'Символи','🐶'=>'Животни','🍕'=>'Храна','⚽'=>'Спорт','✈️'=>'Пътуване','💡'=>'Обекти'] as $ico=>$cat): ?>
                            <button type="button" onclick="adminEmojiCat(<?= $d['id'] ?>,'<?= $cat ?>')" style="background:none;border:none;font-size:1.1rem;cursor:pointer;padding:2px 5px;border-radius:4px;flex-shrink:0;" title="<?= $cat ?>"><?= $ico ?></button>
                            <?php endforeach; ?>
                        </div>
                        <div id="admin-emoji-grid-<?= $d['id'] ?>" style="display:flex;flex-wrap:wrap;gap:2px;max-height:130px;overflow-y:auto;font-size:1.2rem;"></div>
                    </div>
                    <div style="padding:0.65rem 0.75rem;border-top:1px solid var(--border-dim);background:var(--navy-2);display:flex;gap:0.4rem;align-items:center;">
                        <button type="button" onclick="adminToggleEmoji(<?= $d['id'] ?>)" style="background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--text-dim);padding:2px 4px;flex-shrink:0;">😊</button>
                        <button type="button" onclick="document.getElementById('admin-file-<?= $d['id'] ?>').click()" style="background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--text-dim);padding:2px 4px;flex-shrink:0;">📎</button>
                        <input type="file" id="admin-file-<?= $d['id'] ?>" accept="image/*,video/*,.pdf,.doc,.docx,.txt,.zip" style="display:none;" onchange="adminHandleFile(<?= $d['id'] ?>,<?= $chatId ?>,this)">
                        <input type="text" id="admin-input-<?= $d['id'] ?>" class="form-control" style="padding:0.45rem 0.75rem;flex:1;font-size:0.875rem;" placeholder="Напиши съобщение като администратор…" onkeydown="if(event.key==='Enter')adminSend(<?= $d['id'] ?>,<?= $chatId ?>)">
                        <button class="btn btn-primary btn-sm" onclick="adminSend(<?= $d['id'] ?>,<?= $chatId ?>)">→</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <form method="post" style="display:flex;flex-direction:column;gap:0.75rem;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="dispute_id" value="<?= $d['id'] ?>">
            <div class="form-group"><label class="form-label">Бележка (по желание)</label><textarea name="admin_note" class="form-control" rows="2" placeholder="Обоснование…"></textarea></div>
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
                <a href="<?= url('contracts/view.php?id='.$d['contract_id']) ?>" class="btn btn-outline btn-sm">📄 Договор</a>
                <button type="submit" name="resolution" value="employer" class="btn btn-outline" onclick="return confirm('Върни средствата на работодателя?')">↩ Върни на работодателя</button>
                <button type="submit" name="resolution" value="contractor" class="btn btn-success" onclick="return confirm('Освободи за изпълнителя?')">✓ Освободи за изпълнителя</button>
            </div>
        </form>
        <form method="post" style="margin-top:0.5rem;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="release_dispute" value="<?= $d['id'] ?>">
            <button type="submit" class="btn btn-outline btn-sm" style="color:var(--text-dim);border-color:var(--border-dim);" onclick="return confirm('Откажи поемането — спорът се връща в Отворени без да се освобождават пари. Сигурен ли си?')">🔙 Откажи поемането</button>
        </form>
        <?php endif; ?>
    </div>
</div>
    <?php
}
?>

<div class="fade-in">
<div class="page-header page-header-row">
    <div><h1>⚙️ Администрация</h1><p>Управление на спорове и статистика</p></div>
    <a href="<?= url('admin/withdrawals.php') ?>" class="btn btn-outline" style="position:relative;">
        ⬇ Заявки за теглене
        <?php if ($pendingWrCount > 0): ?>
        <span style="position:absolute;top:-6px;right:-6px;background:var(--danger);color:#fff;font-size:0.7rem;font-weight:700;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?= $pendingWrCount ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:2rem;">
<?php foreach ([
    ['👥','Потребители',$stats['total_users']],
    ['📋','Обяви',$stats['total_listings']],
    ['📄','Договори',$stats['total_contracts']],
    ['💰','Изплатено',formatMoney($stats['total_paid'])],
    ['⚠️','Открити спорове',$stats['open_disputes']],
] as $s): ?>
    <div style="background:var(--navy-2);border:1px solid var(--border-dim);border-radius:var(--radius-lg);padding:1.25rem;text-align:center;">
        <div style="font-size:1.5rem;"><?= $s[0] ?></div>
        <div style="font-family:'Playfair Display',serif;font-size:1.4rem;color:var(--gold);font-weight:700;"><?= $s[2] ?></div>
        <div style="font-size:0.75rem;color:var(--text-dim);"><?= $s[1] ?></div>
    </div>
<?php endforeach; ?>
</div>

<!-- MY assigned disputes -->
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;">
    <div class="section-title" style="margin:0;">🙋 Поети от теб (<?= count($myDisputes) ?>)</div>
</div>
<?php if (empty($myDisputes)): ?>
    <div style="background:var(--navy-2);border:1px solid var(--border-dim);border-radius:var(--radius-lg);padding:1.5rem;text-align:center;color:var(--text-dim);font-size:0.875rem;margin-bottom:2rem;">
        Нямаш поети спорове в момента.
    </div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:1.5rem;margin-bottom:2.5rem;">
    <?php foreach ($myDisputes as $d): renderDisputeCard($d, $admin, true); endforeach; ?>
</div>
<?php endif; ?>

<!-- OPEN unassigned disputes -->
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;">
    <div class="section-title" style="margin:0;">⚠️ Отворени спорове — непоети (<?= count($openDisputes) ?>)</div>
</div>
<?php if (empty($openDisputes)): ?>
    <div class="empty-state" style="padding:2rem;margin-bottom:2rem;"><span class="empty-state-icon">✅</span><h3>Няма непоети спорове</h3></div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:1.5rem;margin-bottom:2.5rem;">
    <?php foreach ($openDisputes as $d): renderDisputeCard($d, $admin, false); endforeach; ?>
</div>
<?php endif; ?>

<!-- Resolved disputes -->
<?php if (!empty($resolvedDisputes)): ?>
<div class="section-title">✅ Решени спорове (последни 20)</div>
<div class="table-wrap">
<table>
    <thead><tr><th>#</th><th>Обява</th><th>Работодател</th><th>Изпълнител</th><th>Сума</th><th>Решение</th><th>Решен от</th><th>Дата</th></tr></thead>
    <tbody>
    <?php foreach ($resolvedDisputes as $d): ?>
    <tr>
        <td><a href="<?= url('contracts/view.php?id='.$d['contract_id']) ?>" style="color:var(--gold);">#<?= $d['contract_id'] ?></a></td>
        <td><?= h(mb_strimwidth($d['listing_title'],0,40,'...','UTF-8')) ?></td>
        <td><?= h($d['emp_name']) ?></td>
        <td><?= h($d['contractor_name']) ?></td>
        <td style="font-weight:600;"><?= formatMoney($d['amount']) ?></td>
        <td><span class="pill <?= $d['resolution']==='employer'?'pill-pending':'pill-open' ?>"><?= $d['resolution']==='employer'?'↩ Работодател':'✓ Изпълнител' ?></span></td>
        <td style="font-size:0.82rem;"><?= h($d['admin_name'] ?? '—') ?></td>
        <td style="font-size:0.78rem;color:var(--text-dim);"><?= date('d.m.Y', strtotime($d['resolved_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>

<script>
const ADMIN_ID  = <?= (int)$admin['id'] ?>;
const openChats = {};
const adminAttachments = {};

function toggleAdminChat(did) {
    const wrap = document.getElementById('admin-chat-wrap-' + did);
    const btn  = document.getElementById('chat-toggle-' + did);
    const isOpen = wrap.style.display !== 'none';
    if (isOpen) {
        wrap.style.display = 'none';
        btn.textContent = '💬 Чат между страните — покажи';
        if (openChats[did]) { clearInterval(openChats[did].interval); delete openChats[did]; }
    } else {
        wrap.style.display = 'block';
        btn.textContent = '💬 Чат между страните — скрий';
        const box = document.getElementById('admin-msgs-' + did);
        const chatId = parseInt(box.dataset.chatId);
        scrollMsgs(did);
        openChats[did] = { chatId, lastId: parseInt(box.dataset.lastId)||0, isPolling: false,
            interval: setInterval(() => pollAdminChat(did), 3000) };
    }
}

function scrollMsgs(did) { const b = document.getElementById('admin-msgs-'+did); if(b) b.scrollTop=b.scrollHeight; }

function buildAdminMsgRow(m) {
    const isMine = m.is_admin;
    const row = document.createElement('div');
    row.style.cssText = `display:flex;gap:0.5rem;${isMine?'align-self:flex-end;flex-direction:row-reverse;':''}max-width:80%;`;
    row.dataset.msgId = m.id;
    const img = document.createElement('img');
    img.src=m.avatar; img.style.cssText='width:28px;height:28px;border-radius:50%;flex-shrink:0;align-self:flex-end;';
    row.appendChild(img);
    const wrap = document.createElement('div');
    const meta = document.createElement('div');
    meta.style.cssText=`font-size:0.68rem;color:var(--text-dim);margin-bottom:2px;${isMine?'text-align:right':''}`;
    meta.innerHTML=`${m.sender_name} · <span class="msg-time-rel time-ago-live" data-created="${m.created_at}">${m.time_ago}</span>`;
    wrap.appendChild(meta);
    const bubble = document.createElement('div');
    bubble.style.cssText=`padding:0.5rem 0.85rem;border-radius:12px;font-size:0.875rem;word-break:break-word;${isMine?'background:linear-gradient(135deg,#8c6c20,#C9A84C);color:var(--navy);border-bottom-right-radius:4px;':'background:var(--navy-2);border:1px solid var(--border-dim);color:var(--text);border-bottom-left-radius:4px;'}`;
    if (m.body) { const lines=m.body.split('\n'); lines.forEach((ln,i)=>{ bubble.appendChild(document.createTextNode(ln)); if(i<lines.length-1) bubble.appendChild(document.createElement('br')); }); }
    if (m.attachment) {
        const att=document.createElement('div'); att.style.marginTop=m.body?'0.4rem':'0';
        if(m.attachment_type==='image') att.innerHTML=`<a href="${m.attachment}" target="_blank"><img src="${m.attachment}" style="max-width:200px;border-radius:6px;display:block;"></a>`;
        else if(m.attachment_type==='video') att.innerHTML=`<video src="${m.attachment}" controls style="max-width:240px;border-radius:6px;"></video>`;
        else att.innerHTML=`<a href="${m.attachment}" target="_blank" style="color:var(--gold);font-size:0.82rem;">📎 ${m.attachment.split('/').pop()}</a>`;
        bubble.appendChild(att);
    }
    wrap.appendChild(bubble); row.appendChild(wrap);
    return row;
}

function pollAdminChat(did) {
    const info = openChats[did]; if (!info||info.isPolling) return;
    info.isPolling = true;
    fetch(`${BASE_URL}/admin/disputes.php?poll_chat=${info.chatId}&last_id=${info.lastId}`)
        .then(r=>r.json()).then(data=>{
            if(!data.messages||!data.messages.length) return;
            const box=document.getElementById('admin-msgs-'+did);
            const wasBottom=box.scrollHeight-box.clientHeight<=box.scrollTop+20;
            const ph=box.querySelector('.admin-no-msgs'); if(ph) ph.remove();
            data.messages.forEach(m=>{
                if(box.querySelector(`[data-msg-id="${m.id}"]`)){info.lastId=Math.max(info.lastId,m.id);return;}
                info.lastId=Math.max(info.lastId,m.id); box.dataset.lastId=info.lastId;
                box.appendChild(buildAdminMsgRow(m));
            });
            if(wasBottom) scrollMsgs(did);
        }).catch(()=>{}).finally(()=>{info.isPolling=false;});
}

// ── Emoji ────────────────────────────────────────────────
const ADMIN_EMOJI_DATA = {
  'Усмивки': ['😀','😁','😂','🤣','😃','😄','😅','😆','😉','😊','😋','😎','😍','🥰','😘','🤩','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','😈','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧'],
  'Жестове': ['👋','🤚','🖐','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','🤲','🤝','🙏','✍️','💪','🦾','👂','🦻','👃','👀','👁️'],
  'Символи': ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','✅','❌','⚠️','🔥','💯','💢','🔰','💰','💸','📈','📉','🏆','🥇','🎯','🔒','🔓','⭐','🌟','💫','✨','🎉','👏','🎊','🚀','💡','🔔','📢'],
  'Животни': ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐔','🐧','🐦','🦆','🦅','🦉','🦇','🐺','🐴','🦄','🐝','🦋','🐢','🐍','🦎','🐙','🦈','🐬','🐳'],
  'Храна':   ['🍎','🍊','🍋','🍇','🍓','🫐','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🥦','🌽','🍞','🥐','🥖','🧀','🥚','🍳','🥞','🧇','🥓','🥩','🍗','🍖','🌭','🍔','🍟','🍕','🍣','🍱','🌮','🌯','🍝','🍜','🍲','🍛','🍰','🎂','🍦','🍩','🍪','🍫','🍬','🍭','🍺','🍻','🥂','🍷','☕','🧃'],
  'Спорт':   ['⚽','🏀','🏈','⚾','🎾','🏐','🏉','🎱','🏓','🏸','🥅','⛳','🎯','🎮','🕹','🎲','♟','🃏','🎭','🎨','🎤','🎧','🎵','🎶','🥁','🎸','🎺','🎻','🎬','🎥','📺','📻'],
  'Пътуване':['🚗','🚕','🚙','🚌','🏎','🚓','🚑','🚒','🚜','🏍','🛵','🚲','🛴','✈️','🛫','🛬','🚀','🛸','🛶','⛵','🚤','🛥','🚢','⚓','🗺','🏔','⛰','🌋','🏕','🏖','🏜','🏝','🏟','🏠','🏡','🏢','🏥','🏦','🏨','🏪','🏫','🏭','🏯','🏰','⛪'],
  'Обекти':  ['💡','🔦','🕯','💰','💴','💵','💶','💷','💸','💳','🪙','📈','📉','📊','📋','📌','📍','📎','🖇','📏','📐','✂️','🔒','🔓','🔑','🗝','🔨','⚒','🛠','⚔️','🛡','🔧','🪛','🔩','⚙️','⚖️','🔗','⛓','🧲','🧪','🧬','🔬','🔭','📡','✏️','✒️','🖊','🖋','📝','📓','📔','📒','📕','📗','📘','📙','📚','📖','🔖'],
};

function adminToggleEmoji(did) {
    const p=document.getElementById('admin-emoji-'+did); if(!p) return;
    const show=p.style.display==='none'||p.style.display==='';
    p.style.display=show?'block':'none';
    if(show) adminEmojiCat(did,'Усмивки');
}

function adminEmojiCat(did, cat) {
    const grid=document.getElementById('admin-emoji-grid-'+did); if(!grid) return;
    const emojis=ADMIN_EMOJI_DATA[cat]||[];
    grid.innerHTML='';
    emojis.forEach(e=>{
        const btn=document.createElement('button');
        btn.type='button'; btn.textContent=e; btn.title=e;
        btn.style.cssText='background:none;border:none;cursor:pointer;padding:2px;border-radius:3px;font-size:1.15rem;line-height:1.2;';
        btn.onmouseover=()=>{btn.style.background='var(--navy-3)'};
        btn.onmouseout=()=>{btn.style.background='none'};
        btn.onclick=()=>adminInsertEmoji(did,e);
        grid.appendChild(btn);
    });
}

function adminInsertEmoji(did,e) {
    const input=document.getElementById('admin-input-'+did); if(!input) return;
    const s=input.selectionStart, end=input.selectionEnd;
    input.value=input.value.slice(0,s)+e+input.value.slice(end);
    input.selectionStart=input.selectionEnd=s+e.length;
    input.focus();
}

function adminHandleFile(did, chatId, input) {
    const file=input.files[0]; if(!file) return;
    adminHandleFileObj(did, chatId, file, file.name);
    input.value='';
}

function adminHandleFileObj(did, chatId, file, name) {
    const bar=document.getElementById('admin-att-bar-'+did);
    const lbl=document.getElementById('admin-att-lbl-'+did);
    if(!bar||!lbl) return;
    lbl.textContent='⏳ '+name; bar.style.display='flex';
    const fd=new FormData();
    fd.append('file',file,name); fd.append('chat_id',chatId); fd.append('csrf','<?= csrf() ?>');
    fetch(`${BASE_URL}/api/upload_attachment.php`,{method:'POST',body:fd})
        .then(r=>r.json()).then(data=>{
            if(data.ok){
                adminAttachments[did]={path:data.path,type:data.type,name};
                lbl.innerHTML=(data.type==='image'?'🖼️':data.type==='video'?'🎬':'📎')+' '+name+(data.url?` <img src="${data.url}" style="height:32px;border-radius:3px;margin-left:6px;vertical-align:middle;">`:'');
            } else { lbl.textContent='❌ '+(data.err||'Грешка'); setTimeout(()=>adminClearAtt(did),2500); }
        }).catch(()=>{lbl.textContent='❌ Грешка';setTimeout(()=>adminClearAtt(did),2500);});
}

function adminClearAtt(did) {
    if(adminAttachments[did]) delete adminAttachments[did];
    const bar=document.getElementById('admin-att-bar-'+did); if(bar) bar.style.display='none';
}

function adminSend(did, chatId) {
    const input=document.getElementById('admin-input-'+did); if(!input) return;
    const body=input.value.trim();
    const att=adminAttachments[did];
    if(!body&&!att) return;
    input.value=''; input.disabled=true;
    adminClearAtt(did);
    const ep=document.getElementById('admin-emoji-'+did); if(ep) ep.style.display='none';
    const fd=new FormData();
    fd.append('ajax_chat','1'); fd.append('chat_id',chatId);
    fd.append('body',body); fd.append('csrf','<?= csrf() ?>');
    if(att){fd.append('attachment',att.path);fd.append('attachment_type',att.type);}
    fetch(`${BASE_URL}/admin/disputes.php`,{method:'POST',body:fd})
        .then(r=>r.json()).then(data=>{if(data.ok) pollAdminChat(did);})
        .catch(()=>{}).finally(()=>{input.disabled=false;input.focus();});
}

// Clipboard paste
document.addEventListener('paste', function(ev) {
    const items=ev.clipboardData?.items; if(!items) return;
    let activeDid=null, activeChatId=null;
    Object.keys(openChats).forEach(did=>{
        const wrap=document.getElementById('admin-chat-wrap-'+did);
        if(wrap&&wrap.style.display!=='none'){activeDid=parseInt(did);activeChatId=openChats[did].chatId;}
    });
    if(!activeDid) return;
    for(const item of items){
        if(item.type.startsWith('image/')){ev.preventDefault();const f=item.getAsFile();if(f) adminHandleFileObj(activeDid,activeChatId,f,'clipboard_image.png');break;}
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
