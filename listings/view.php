<?php
require_once __DIR__ . '/../includes/functions.php';
$user = auth();
$id   = (int)($_GET['id'] ?? 0);

$st = db()->prepare('SELECT l.*, u.name AS emp_name, u.avatar AS emp_avatar, u.city AS emp_city
    FROM listings l JOIN users u ON l.employer_id = u.id WHERE l.id = ?');
$st->execute([$id]);
$listing = $st->fetch();
if (!$listing) { flash('Обявата не е намерена.', 'error'); redirect(url('index.php')); }

$isEmployer = $user && $user['id'] === $listing['employer_id'];

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $action = $_POST['action'];

    // ── Delete listing ─────────────────────────────────────
    if ($action === 'delete' && $isEmployer) {
        // Only allowed if no active contract exists for this listing
        $hasContract = db()->prepare("SELECT id FROM contracts WHERE listing_id = ? AND status IN ('active','completed','disputed') LIMIT 1");
        $hasContract->execute([$id]);
        if ($hasContract->fetch()) {
            flash('Не можеш да изтриеш обява с активен или завършен договор.', 'error');
        } else {
            db()->prepare('DELETE FROM listings WHERE id = ? AND employer_id = ?')->execute([$id, $user['id']]);
            flash('Обявата беше изтрита.');
            redirect(url('index.php'));
        }
    }

    // ── Select applicant ───────────────────────────────────
    if ($action === 'select' && $isEmployer && $listing['status'] === 'open') {
        $appId = (int)$_POST['application_id'];
        $st2 = db()->prepare('SELECT a.*, u.name, u.id AS uid FROM applications a JOIN users u ON a.applicant_id = u.id WHERE a.id = ? AND a.listing_id = ?');
        $st2->execute([$appId, $id]);
        $app = $st2->fetch();
        if ($app) {
            $stBal = db()->prepare('SELECT balance FROM users WHERE id = ?');
            $stBal->execute([$user['id']]);
            $currentBalance = (float)$stBal->fetchColumn();

            if ($currentBalance < $listing['budget']) {
                flash('Недостатъчен баланс. Необходими: ' . formatMoney($listing['budget']), 'error');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')
                        ->execute([$listing['budget'], $user['id']]);
                    $pdo->prepare('INSERT INTO contracts (listing_id, employer_id, contractor_id, amount) VALUES (?,?,?,?)')
                        ->execute([$id, $user['id'], $app['applicant_id'], $listing['budget']]);
                    $contractId = (int)$pdo->lastInsertId();
                    // Set invoice number

                    $pdo->prepare("UPDATE listings SET status='closed', selected_applicant_id=? WHERE id=?")
                        ->execute([$app['applicant_id'], $id]);
                    $pdo->prepare("UPDATE applications SET status='accepted' WHERE id=?")->execute([$appId]);
                    $pdo->prepare("UPDATE applications SET status='rejected' WHERE listing_id=? AND id!=?")->execute([$id, $appId]);
                    logTransaction($user['id'], 'escrow_lock', -$listing['budget'], 'Ескроу за обява #'.$id, $contractId);
                    $pdo->commit();

                    // Notify contractor
                    addNotification(
                        $app['applicant_id'], 'accepted',
                        'Кандидатурата ти за „' . mb_strimwidth($listing['title'],0,50,'...','UTF-8') . '" беше приета!',
                        url('contracts/view.php?id=' . $contractId)
                    );

                    flash('Избра ' . h($app['name']) . '! Ескроу е заключен.');
                    redirect(url('contracts/view.php?id=' . $contractId));
                } catch (Exception $e) {
                    $pdo->rollBack();
                    flash('Грешка: ' . $e->getMessage(), 'error');
                }
            }
        }
    }

    // ── Apply ──────────────────────────────────────────────
    if ($action === 'apply' && !$isEmployer && $listing['status'] === 'open') {
        $msg = trim($_POST['cover_message'] ?? '');
        $st2 = db()->prepare('SELECT id FROM applications WHERE listing_id=? AND applicant_id=?');
        $st2->execute([$id, $user['id']]);
        if ($st2->fetch()) {
            flash('Вече си кандидатствал за тази обява.', 'error');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO applications (listing_id, applicant_id, cover_message) VALUES (?,?,?)')
                    ->execute([$id, $user['id'], $msg]);
                $appId2 = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO chats (listing_id, application_id, employer_id, applicant_id) VALUES (?,?,?,?)')
                    ->execute([$id, $appId2, $listing['employer_id'], $user['id']]);
                $pdo->commit();

                // Notify employer
                addNotification(
                    $listing['employer_id'], 'application',
                    h($user['name']) . ' кандидатства за обявата „' . mb_strimwidth($listing['title'],0,50,'...','UTF-8') . '"',
                    url('listings/view.php?id=' . $id)
                );

                flash('Кандидатурата е изпратена! Чатът с работодателя е отворен.');
                $chatSt = db()->prepare('SELECT id FROM chats WHERE application_id=?');
                $chatSt->execute([$appId2]);
                $chatId = $chatSt->fetchColumn();
                redirect(url('messages/chat.php?id=' . $chatId));
            } catch (Exception $e) {
                $pdo->rollBack();
                flash('Грешка: ' . $e->getMessage(), 'error');
            }
        }
    }
    redirect(url('listings/view.php?id=' . $id));
}

// Reload listing
$st->execute([$id]);
$listing = $st->fetch();

// Applications
$applications = [];
$myApplication = null;
if ($user) {
    $st = db()->prepare('SELECT a.*, u.name AS app_name, u.avatar AS app_avatar, u.city AS app_city,
        c.id AS chat_id
        FROM applications a
        JOIN users u ON a.applicant_id = u.id
        LEFT JOIN chats c ON c.application_id = a.id
        WHERE a.listing_id = ?
        ORDER BY a.created_at ASC');
    $st->execute([$id]);
    $applications = $st->fetchAll();
    foreach ($applications as $a) {
        if ($a['applicant_id'] === $user['id']) { $myApplication = $a; break; }
    }
}

$pageTitle = h($listing['title']) . ' — ' . SITE_NAME;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-wide fade-in">
<div class="breadcrumb">
    <a href="<?= url('index.php') ?>">Начало</a>
    <span class="breadcrumb-sep">›</span>
    <span><?= h(mb_strimwidth($listing['title'], 0, 50, '...', 'UTF-8')) ?></span>
</div>

<div class="listing-detail-grid">
<!-- Left -->
<div class="flex-col">
    <div class="card">
        <div class="card-body">
            <div class="flex-between mb-2">
                <?php $smap=['open'=>'pill-open','closed'=>'pill-closed','cancelled'=>'pill-closed'];
                      $slbl=['open'=>'Свободна','closed'=>'Затворена','cancelled'=>'Отменена']; ?>
                <span class="pill <?= $smap[$listing['status']] ?>"><?= $slbl[$listing['status']] ?></span>
                <?php if ($isEmployer): ?>
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <span style="font-size:0.8rem;color:var(--text-dim);">Твоя обява</span>
                    <?php
                    $hasActiveContract = false;
                    $chkC = db()->prepare("SELECT id FROM contracts WHERE listing_id=? AND status IN ('active','completed','disputed') LIMIT 1");
                    $chkC->execute([$id]);
                    if ($chkC->fetch()) $hasActiveContract = true;
                    ?>
                    <?php if (!$hasActiveContract): ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('Сигурен ли си, че искаш да изтриеш тази обява? Действието е необратимо.')"
                            style="padding:0.25rem 0.65rem;font-size:0.78rem;">
                            🗑 Изтрий
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <h1 style="font-size:1.75rem;margin-bottom:1rem;"><?= h($listing['title']) ?></h1>
            <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:1.25rem;font-size:0.875rem;color:var(--text-muted);">
                <span style="display:flex;align-items:center;gap:0.5rem;">
                    <img src="<?= avatarUrl($listing['emp_avatar'], $listing['emp_name']) ?>" style="width:24px;height:24px;border-radius:50%;">
                    <?= h($listing['emp_name']) ?>
                </span>
                <?php if ($listing['city']): ?><span>📍 <?= h($listing['city']) ?><?= $listing['neighborhood'] ? ', '.h($listing['neighborhood']) : '' ?></span><?php endif; ?>
                <span>🕐 <span class="time-ago-live" data-created="<?= h($listing['created_at']) ?>"><?= timeAgo($listing['created_at']) ?></span></span>
            </div>
            <div style="font-size:0.95rem;line-height:1.8;color:var(--text-muted);white-space:pre-line;"><?= h($listing['description']) ?></div>
        </div>
    </div>

    <?php if ($isEmployer && !empty($applications)): ?>
    <div>
        <div class="section-title">Кандидати (<?= count($applications) ?>)</div>
        <?php foreach ($applications as $app): ?>
        <div class="applicant-row">
            <img src="<?= avatarUrl($app['app_avatar'], $app['app_name']) ?>" alt="">
            <div class="applicant-info">
                <div class="applicant-name"><?= h($app['app_name']) ?></div>
                <?php if ($app['app_city']): ?><div style="font-size:0.78rem;color:var(--text-dim);">📍 <?= h($app['app_city']) ?></div><?php endif; ?>
                <?php if ($app['cover_message']): ?><div class="applicant-msg"><?= h(mb_strimwidth($app['cover_message'],0,120,'...','UTF-8')) ?></div><?php endif; ?>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:0.5rem;">
                <span class="pill pill-<?= $app['status'] ?>"><?= ['pending'=>'Изчакващ','accepted'=>'Приет','rejected'=>'Отказан'][$app['status']] ?></span>
                <div style="display:flex;gap:0.5rem;">
                    <?php if ($app['chat_id']): ?>
                        <a href="<?= url('messages/chat.php?id='.$app['chat_id']) ?>" class="btn btn-outline btn-sm">Чат</a>
                    <?php endif; ?>
                    <?php if ($listing['status']==='open' && $app['status']==='pending'): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= csrf() ?>">
                            <input type="hidden" name="action" value="select">
                            <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                            <button type="submit" class="btn btn-success btn-sm"
                                onclick="return confirm('Избери <?= h($app['app_name']) ?> и заключи <?= formatMoney($listing['budget']) ?> като ескроу?')">✓ Избери</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php elseif ($isEmployer && $listing['status']==='open'): ?>
        <div class="empty-state" style="padding:2rem;"><span class="empty-state-icon">⏳</span><h3>Все още няма кандидати</h3></div>
    <?php endif; ?>

    <?php if (!$isEmployer && $myApplication): ?>
    <div class="card" style="border-color:rgba(201,168,76,0.3);">
        <div class="card-body" style="text-align:center;">
            <div style="font-size:2rem;margin-bottom:0.5rem;">✅</div>
            <h3 style="font-size:1rem;font-family:'DM Sans',sans-serif;">Вече си кандидатствал</h3>
            <p style="color:var(--text-muted);font-size:0.875rem;margin:0.5rem 0;">Статус: <span class="pill pill-<?= $myApplication['status'] ?>"><?= ['pending'=>'Изчакващ','accepted'=>'Приет','rejected'=>'Отказан'][$myApplication['status']] ?></span></p>
            <?php if ($myApplication['chat_id']): ?>
                <a href="<?= url('messages/chat.php?id='.$myApplication['chat_id']) ?>" class="btn btn-primary btn-sm mt-2">Отвори чата</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Right sidebar -->
<div style="display:flex;flex-direction:column;gap:1.25rem;position:sticky;top:80px;">
    <div class="card">
        <div class="card-body" style="text-align:center;">
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-dim);margin-bottom:0.5rem;">Бюджет</div>
            <div style="font-family:'Playfair Display',serif;font-size:2.5rem;font-weight:700;color:var(--gold);"><?= formatMoney($listing['budget']) ?></div>
            <div style="font-size:0.78rem;color:var(--text-dim);margin-top:0.25rem;">Блокира се като ескроу при избор</div>
        </div>
    </div>

    <?php if ($user && !$isEmployer && !$myApplication && $listing['status']==='open'): ?>
    <div class="card" style="border-color:rgba(201,168,76,0.3);">
        <div class="card-header"><h3 style="font-size:1rem;font-family:'DM Sans',sans-serif;">Кандидатствай</h3></div>
        <div class="card-body">
            <form method="post" style="display:flex;flex-direction:column;gap:1rem;">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="apply">
                <div class="form-group">
                    <label class="form-label">Съобщение (по желание)</label>
                    <textarea name="cover_message" class="form-control" rows="3" placeholder="Представи се..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-full">Изпрати кандидатура</button>
                <p style="font-size:0.78rem;color:var(--text-dim);text-align:center;">Автоматично се открива чат с работодателя</p>
            </form>
        </div>
    </div>
    <?php elseif (!$user): ?>
    <div class="card"><div class="card-body" style="text-align:center;">
        <p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:1rem;">Влез за да кандидатстваш</p>
        <a href="<?= url('auth/login.php') ?>" class="btn btn-primary w-full">Вход</a>
    </div></div>
    <?php elseif ($listing['status']!=='open'): ?>
    <div class="card"><div class="card-body" style="text-align:center;color:var(--text-muted);">Обявата е затворена</div></div>
    <?php endif; ?>

    <div style="padding:1rem;background:var(--navy-2);border:1px solid var(--border-dim);border-radius:var(--radius-lg);">
        <div style="display:flex;justify-content:space-around;align-items:center;gap:0.75rem;text-align:center;">
            <div style="display:flex;flex-direction:column;align-items:center;gap:0.2rem;">
                <div style="font-size:1.5rem;font-weight:700;line-height:1.2;"><?= count($applications) ?></div>
                <div style="font-size:0.72rem;color:var(--text-dim);">Кандидати</div>
            </div>
            <div style="width:1px;height:2.5rem;background:var(--border-dim);"></div>
            <div style="display:flex;flex-direction:column;align-items:center;gap:0.2rem;">
                <div style="font-size:1.5rem;font-weight:700;line-height:1.2;"><span class="time-ago-live" data-created="<?= h($listing['created_at']) ?>"><?= timeAgo($listing['created_at']) ?></span></div>
                <div style="font-size:0.72rem;color:var(--text-dim);">Публикувана</div>
            </div>
        </div>
    </div>
</div>
</div><!-- .listing-detail-grid -->
</div>

<style>
.listing-detail-grid { display:grid; grid-template-columns:2fr 1fr; gap:2rem; align-items:start; }
@media(max-width:768px){ .listing-detail-grid { grid-template-columns:1fr; } }
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
