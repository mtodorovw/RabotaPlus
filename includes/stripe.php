<?php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();


// ── Stripe configuration ──────────────────────────────────
// Replace these with your actual keys from https://dashboard.stripe.com/apikeys
if (!defined('STRIPE_PUBLIC_KEY')) {
    define('STRIPE_PUBLIC_KEY', 'pk_test_51T2hSTFdjxJXHvqQsyqEtquZ3rcwSycU7aHe74tciZYvdFXE0YC2sJTY90nCVMVam42EblMDDSSsmhKhRzAHyQhx00Gi21dC5t'); // pk_test_...
    define('STRIPE_SECRET_KEY', $_ENV['STRIPE_SECRET_KEY'] ?? ''); // sk_test_...
    define('STRIPE_ENABLED',    true); // Set to TRUE once keys are entered
}

// ── Stripe API helper (no composer needed — uses curl) ───────
// Uses Stripe PaymentIntents API (current standard)

function stripeRequest(string $method, string $endpoint, array $data = [], string $secretKey = ''): array {
    if (!$secretKey) $secretKey = STRIPE_SECRET_KEY;

    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $secretKey . ':',
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno) throw new RuntimeException('Stripe connection error: ' . curl_error($ch));

    $result = json_decode($body, true);
    if (!$result) throw new RuntimeException('Invalid Stripe response');

    return $result;
}

// Create & confirm PaymentIntent in one step
function stripeCharge(float $amountBGN, string $paymentMethodId, string $description): array {
    // Create PaymentIntent
    $intent = stripeRequest('POST', 'payment_intents', [
        'amount'               => (int)round($amountBGN * 100), // стотинки
        'currency'             => 'eur',
        'payment_method'       => $paymentMethodId,
        'description'          => $description,
        'confirm'              => 'true',
        'return_url'           => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
                               . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                               . '/' . ltrim(rtrim(str_replace(
                                   str_replace('\\','/',realpath($_SERVER['DOCUMENT_ROOT']??'')),
                                   '',
                                   str_replace('\\','/',realpath(__DIR__.'/..'))
                               ),'/') . '/profile/deposit.php', '/'), // full URL for 3DS
        'automatic_payment_methods[enabled]' => 'true',
        'automatic_payment_methods[allow_redirects]' => 'never',
    ]);

    if (isset($intent['error'])) {
        throw new RuntimeException($intent['error']['message'] ?? 'Stripe error');
    }

    return $intent; // ['id'=>'pi_xxx', 'status'=>'succeeded', ...]
}
