<?php
require_once __DIR__ . '/../includes/functions.php';
$user = requireAuth();
$pageTitle = 'Теглене — ' . SITE_NAME;

// Note: STRIPE_PUBLIC_KEY, STRIPE_SECRET_KEY, STRIPE_ENABLED
// are defined in includes/stripe.php (auto-loaded via functions.php)
// Card withdrawals require Stripe Connect (real payouts) — not available in test mode.
// For this project, card option is shown but processes as IBAN-style manual request.

// Re-fetch fresh balance
$balSt = db()->prepare('SELECT balance FROM users WHERE id=?');
$balSt->execute([$user['id']]);
$balance = (float)$balSt->fetchColumn();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $amount  = (float)($_POST['amount'] ?? 0);
    $method  = $_POST['method'] ?? 'iban';

    if ($amount < 10)       $errors[] = 'Минималното теглене е 10 €';
    if ($amount > $balance) $errors[] = 'Недостатъчен баланс. Наличен: ' . formatMoney($balance);

    if ($method === 'iban') {
        $iban    = strtoupper(preg_replace('/\s+/', '', $_POST['iban'] ?? ''));
        $accName = trim($_POST['account_name'] ?? '');
        if (!$iban)    $errors[] = 'Въведи IBAN.';
        if (!$accName) $errors[] = 'Въведи притежателя на сметката.';
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Deduct balance
            $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')
                ->execute([$amount, $user['id']]);

            // Log transaction
            $invNum = 'INV-W-' . date('Ymd') . '-' . str_pad($user['id'], 4, '0', STR_PAD_LEFT) . '-' . rand(100, 999);
            $txSt = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, description, invoice_number) VALUES (?,?,?,?,?)');
            $txSt->execute([
                $user['id'],
                'withdrawal',
                -$amount,
                $method === 'iban' ? 'Теглене по IBAN: ' . ($iban ?? '') : 'Теглене по карта',
                $invNum,
            ]);
            $txId = (int)$pdo->lastInsertId();

            // Create withdrawal request for admin (link to the transaction)
            $wrSt = $pdo->prepare('INSERT INTO withdrawal_requests (user_id, amount, method, iban, account_name, tx_id) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE tx_id=tx_id');
            try {
                $wrSt->execute([
                    $user['id'], $amount, $method,
                    $method === 'iban' ? ($iban ?? null) : null,
                    $method === 'iban' ? ($accName ?? null) : null,
                    $txId,
                ]);
            } catch (Exception $e) {
                // tx_id column may not exist yet — insert without it
                $wrSt2 = $pdo->prepare('INSERT INTO withdrawal_requests (user_id, amount, method, iban, account_name) VALUES (?,?,?,?,?)');
                $wrSt2->execute([$user['id'], $amount, $method, $method === 'iban' ? ($iban ?? null) : null, $method === 'iban' ? ($accName ?? null) : null]);
            }

            $pdo->commit();

            addNotification($user['id'], 'withdrawal',
                'Заявка за теглене на ' . formatMoney($amount) . ' е изпратена за обработка.',
                url('profile/transactions.php?tab=withdraw'));

            flash('Заявката за теглене на ' . formatMoney($amount) . ' е приета. Средствата ще постъпят в рамките на 1–3 работни дни.');
            redirect(url('profile/transactions.php?tab=withdraw'));

        } catch (Exception $e) {
            $pdo->rollBack();
            // Possible cause: ENUM doesn't include 'withdrawal' — run migrate2.sql
            $errors[] = 'Грешка: ' . $e->getMessage() . '. Моля изпълни migrate2.sql в phpMyAdmin.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:center;padding:2rem 1rem;">
<div style="width:100%;max-width:520px;" class="fade-in">

<div class="breadcrumb">
    <a href="<?= url('profile/edit.php') ?>">Профил</a>
    <span class="breadcrumb-sep">›</span>
    <a href="<?= url('profile/transactions.php') ?>">Транзакции</a>
    <span class="breadcrumb-sep">›</span>
    <span>Теглене</span>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2 style="font-size:1.3rem;">⬇ Тегли средства</h2>
            <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;">
                Наличен баланс: <strong style="color:var(--gold);"><?= formatMoney($balance) ?></strong>
            </p>
        </div>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:1.25rem;">

        <?php foreach ($errors as $e): ?>
            <div class="flash flash-error"><?= h($e) ?></div>
        <?php endforeach; ?>

        <div class="flash flash-info" style="font-size:0.85rem;">
            ℹ️ Заявките за теглене се обработват от администратор в рамките на 1–3 работни дни. Минимум: 10 €
        </div>

        <form method="post" id="withdraw-form" style="display:flex;flex-direction:column;gap:1.25rem;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">

            <!-- Amount -->
            <div class="form-group">
                <label class="form-label">Сума (€) *</label>
                <input type="number" name="amount" id="amount-input" class="form-control"
                       min="10" max="<?= $balance ?>" step="0.01"
                       placeholder="Мин. 10 €"
                       value="<?= h($_POST['amount'] ?? '') ?>" required>
                <span class="form-hint">Максимум: <?= formatMoney($balance) ?></span>
            </div>

            <!-- Quick amounts -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.5rem;">
                <?php foreach ([50, 100, 200, 500] as $p): ?>
                    <button type="button"
                            <?= $p > $balance ? 'disabled' : '' ?>
                            onclick="document.getElementById('amount-input').value=<?= $p ?>"
                            class="btn btn-outline btn-sm"><?= $p ?> €</button>
                <?php endforeach; ?>
            </div>

            <!-- Method selector -->
            <div class="form-group">
                <label class="form-label">Метод на теглене</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                    <label class="method-card" id="method-iban-card">
                        <input type="radio" name="method" value="iban" checked onchange="switchMethod('iban')" style="display:none;">
                        <span style="font-size:1.4rem;">🏦</span>
                        <span style="font-weight:600;font-size:0.9rem;">IBAN</span>
                        <span style="font-size:0.75rem;color:var(--text-dim);">Банков превод</span>
                    </label>
                    <label class="method-card" id="method-card-card" <?= !STRIPE_ENABLED ? 'style="opacity:0.5;cursor:not-allowed;" title="Изисква Stripe"' : '' ?>>
                        <input type="radio" name="method" value="card" onchange="switchMethod('card')"
                               <?= !STRIPE_ENABLED ? 'disabled' : '' ?> style="display:none;">
                        <span style="font-size:1.4rem;">💳</span>
                        <span style="font-weight:600;font-size:0.9rem;">Карта</span>
                        <span style="font-size:0.75rem;color:var(--text-dim);"><?= STRIPE_ENABLED ? 'Моментално (Stripe)' : 'Изисква Stripe' ?></span>
                    </label>
                </div>
            </div>

            <!-- IBAN fields -->
            <div id="iban-fields" style="display:flex;flex-direction:column;gap:1rem;">
                <div class="form-group">
                    <label class="form-label">IBAN *</label>
                    <input type="text" name="iban" class="form-control"
                           placeholder="BG80BNBG96611020345678"
                           value="<?= h($_POST['iban'] ?? '') ?>"
                           oninput="this.value=this.value.toUpperCase().replace(/\s/g,'')">
                </div>
                <div class="form-group">
                    <label class="form-label">Титуляр на сметката *</label>
                    <input type="text" name="account_name" class="form-control"
                           placeholder="Иван Петров"
                           value="<?= h($_POST['account_name'] ?? $user['name']) ?>">
                </div>
            </div>

            <?php if (STRIPE_ENABLED): ?>
            <!-- Card fields (Stripe) — hidden by default -->
            <div id="card-fields" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Данни на картата</label>
                    <div id="card-element" style="padding:0.7rem 1rem;background:var(--navy-3);border:1px solid var(--border-dim);border-radius:var(--radius);min-height:44px;"></div>
                    <div id="card-errors" style="color:var(--danger);font-size:0.82rem;margin-top:0.35rem;"></div>
                </div>
                <input type="hidden" name="stripe_pm" id="stripe-pm">
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary w-full btn-lg" id="submit-btn">
                ⬇ Изпрати заявка за теглене
            </button>
        </form>

        <div style="text-align:center;font-size:0.78rem;color:var(--text-dim);">
            🔒 Средствата се дебитират веднага. Преводът се обработва от администратор.
        </div>
    </div>
</div>
</div>
</div>

<style>
.method-card {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:0.35rem; padding:1rem 0.75rem;
    background:var(--navy-3); border:2px solid var(--border-dim);
    border-radius:var(--radius-lg); cursor:pointer;
    transition:all var(--transition); text-align:center;
}
.method-card.selected { border-color:var(--gold); background:var(--gold-dim2); }
</style>

<script>
function switchMethod(m) {
    document.getElementById('iban-fields').style.display   = m==='iban'  ? 'flex' : 'none';
    document.getElementById('iban-fields').style.flexDirection = 'column';
    <?php if (STRIPE_ENABLED): ?>
    document.getElementById('card-fields').style.display  = m==='card'  ? 'block' : 'none';
    <?php endif; ?>
    ['iban','card'].forEach(v => {
        document.getElementById('method-'+v+'-card').classList.toggle('selected', v===m);
    });
}
// Init
switchMethod('iban');
</script>

<?php if (STRIPE_ENABLED): ?>
<script src="https://js.stripe.com/v3/"></script>
<script>
const stripe   = Stripe('<?= STRIPE_PUBLIC_KEY ?>');
const elements = stripe.elements();
const card     = elements.create('card', {
    hidePostalCode: true,   // ← removes postcode field
    style: {
        base: {
            color: '#e6edf3',
            fontFamily: 'DM Sans, sans-serif',
            fontSize: '15px',
            '::placeholder': { color: '#6e7681' }
        }
    }
});
card.mount('#card-element');
card.on('change', e => {
    document.getElementById('card-errors').textContent = e.error ? e.error.message : '';
});

document.getElementById('withdraw-form').addEventListener('submit', async e => {
    const method = document.querySelector('[name=method]:checked')?.value;
    if (method !== 'card') return; // let normal form submit handle IBAN
    e.preventDefault();
    const btn = document.getElementById('submit-btn');
    btn.disabled = true; btn.textContent = 'Обработва се…';

    const { paymentMethod, error } = await stripe.createPaymentMethod({ type: 'card', card });
    if (error) {
        document.getElementById('card-errors').textContent = error.message;
        btn.disabled = false; btn.textContent = '⬇ Изпрати заявка за теглене';
    } else {
        document.getElementById('stripe-pm').value = paymentMethod.id;
        e.target.submit();
    }
});
</script>
<?php endif; ?>

<style>
@media (max-width: 768px) {
    [style*="display:flex;gap:1rem"] { flex-direction: column; }
    .form-group { margin-bottom: 0.75rem; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
