<?php
require_once __DIR__ . '/../includes/functions.php';
$user      = requireAuth();
$pageTitle = 'Депозит — ' . SITE_NAME;

$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $amount = (float)($_POST['amount'] ?? 0);
    $pmId   = trim($_POST['stripe_pm'] ?? ''); // PaymentMethod ID from Stripe.js

    if ($amount < 1 || $amount > 50000) {
        $errors[] = 'Въведи сума между 1 и 50 000 €';
    }
    if (STRIPE_ENABLED && !$pmId) {
        $errors[] = 'Моля въведи данни на картата.';
    }

    if (empty($errors)) {
        $stripeId = null;

        if (STRIPE_ENABLED) {
            try {
                $intent   = stripeCharge($amount, $pmId, 'Депозит — ' . SITE_NAME . ' (user #' . $user['id'] . ')');
                $stripeId = $intent['id'];

                if ($intent['status'] !== 'succeeded') {
                    $errors[] = 'Плащането не беше успешно (статус: ' . $intent['status'] . '). Провери картата.';
                }
            } catch (RuntimeException $e) {
                $errors[] = 'Stripe грешка: ' . $e->getMessage();
            }
        }

        if (empty($errors)) {
            $invNum = 'INV-D-' . date('Ymd') . '-' . str_pad($user['id'], 4, '0', STR_PAD_LEFT) . '-' . rand(100, 999);
            db()->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$amount, $user['id']]);
            db()->prepare('INSERT INTO transactions (user_id, type, amount, description, invoice_number, stripe_payment_id) VALUES (?,?,?,?,?,?)')
               ->execute([$user['id'], 'deposit', $amount, 'Депозит на баланс', $invNum, $stripeId]);

            addNotification($user['id'], 'deposit',
                'Депозирани ' . formatMoney($amount) . ' в акаунта ти',
                url('profile/transactions.php'));

            flash('Успешно заредени ' . formatMoney($amount) . '!' . ($stripeId ? ' (Stripe: ' . $stripeId . ')' : ''));
            redirect(url('profile/transactions.php'));
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:center;padding:2rem 1rem;">
<div style="width:100%;max-width:500px;" class="fade-in">

<div class="breadcrumb">
    <a href="<?= url('profile/edit.php') ?>">Профил</a>
    <span class="breadcrumb-sep">›</span>
    <span>Депозит</span>
</div>

<div class="card">
    <div class="card-header" style="background:linear-gradient(135deg,rgba(201,168,76,0.08),transparent);">
        <div>
            <h2 style="font-size:1.3rem;">⬆ Зареди баланс</h2>
            <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0.25rem;">
                Текущ баланс: <strong style="color:var(--gold);"><?= formatMoney((float)$user['balance']) ?></strong>
            </p>
        </div>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:1.25rem;">

        <?php foreach ($errors as $e): ?>
            <div class="flash flash-error"><?= h($e) ?></div>
        <?php endforeach; ?>

        <?php if (!STRIPE_ENABLED): ?>
        <div class="flash flash-info" style="font-size:0.85rem;">
            💡 <strong>Демо режим</strong> — Stripe не е конфигуриран. Средствата се добавят директно без плащане.
        </div>
        <?php else: ?>
        <div style="font-size:0.8rem;color:var(--text-dim);background:var(--navy-3);border-radius:var(--radius);padding:0.6rem 0.9rem;">
            🧪 Тест карта: <code style="color:var(--gold);">4242 4242 4242 4242</code> — всяка бъдеща дата, всякакъв CVV
        </div>
        <?php endif; ?>

        <form method="post" id="deposit-form" style="display:flex;flex-direction:column;gap:1.25rem;">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <?php if (STRIPE_ENABLED): ?>
            <input type="hidden" name="stripe_pm" id="stripe-pm">
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">Сума (€) *</label>
                <input type="number" name="amount" id="amount-input" class="form-control"
                       min="1" max="50000" step="0.01" placeholder="100.00"
                       value="<?= h($_POST['amount'] ?? '') ?>" required>
            </div>

            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.5rem;">
                <?php foreach ([50, 100, 200, 500] as $p): ?>
                    <button type="button" onclick="document.getElementById('amount-input').value=<?= $p ?>"
                        class="btn btn-outline btn-sm"><?= $p ?> €</button>
                <?php endforeach; ?>
            </div>

            <?php if (STRIPE_ENABLED): ?>
            <div class="form-group">
                <label class="form-label">Данни на картата</label>
                <div id="card-element" style="padding:0.75rem 1rem;background:var(--navy-3);border:1px solid var(--border-dim);border-radius:var(--radius);min-height:44px;transition:border-color 0.2s;"></div>
                <div id="card-errors" style="color:var(--danger);font-size:0.82rem;margin-top:0.4rem;min-height:1.2em;"></div>
            </div>
            <button type="submit" class="btn btn-primary w-full btn-lg" id="submit-btn">
                🔒 Плати с карта
            </button>
            <?php else: ?>
            <button type="submit" class="btn btn-primary w-full btn-lg">⬆ Зареди баланса</button>
            <?php endif; ?>
        </form>

        <div style="text-align:center;font-size:0.75rem;color:var(--text-dim);">
            <?php if (STRIPE_ENABLED): ?>
            🔒 Плащанията се обработват сигурно чрез <strong>Stripe</strong>. Данните на картата ти не достигат до нашия сървър.
            <?php else: ?>
            🔒 Демо режим — без реални плащания
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
</div>

<?php if (STRIPE_ENABLED): ?>
<script src="https://js.stripe.com/v3/"></script>
<script>
const stripe   = Stripe('<?= STRIPE_PUBLIC_KEY ?>');
const elements = stripe.elements();
const card     = elements.create('card', {
    hidePostalCode: true,
    style: {
        base: {
            color: '#e6edf3',
            fontFamily: 'DM Sans, sans-serif',
            fontSize: '15px',
            '::placeholder': { color: '#6e7681' }
        },
        invalid: { color: '#e06c75' }
    }
});
card.mount('#card-element');
card.on('change', e => {
    document.getElementById('card-errors').textContent = e.error ? e.error.message : '';
});

document.getElementById('deposit-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.textContent = '⏳ Обработва се...';
    document.getElementById('card-errors').textContent = '';

    // Create PaymentMethod (replaces old createToken)
    const { paymentMethod, error } = await stripe.createPaymentMethod({
        type: 'card',
        card: card,
    });

    if (error) {
        document.getElementById('card-errors').textContent = error.message;
        btn.disabled = false;
        btn.textContent = '🔒 Плати с карта';
    } else {
        document.getElementById('stripe-pm').value = paymentMethod.id;
        this.submit(); // real form submit → PHP processes it
    }
});
</script>
<?php endif; ?>

<style>
@media (max-width: 768px) {
    /* Quick amount buttons */
    [style*="display:flex;gap:0.5rem;flex-wrap:wrap"] { justify-content: stretch; }
    [style*="display:flex;gap:0.5rem;flex-wrap:wrap"] .btn { flex: 1; min-width: 60px; }
    /* Stripe element */
    #card-element { padding: 0.75rem; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
