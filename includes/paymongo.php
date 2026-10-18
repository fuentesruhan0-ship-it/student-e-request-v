<?php
/**
 * PayMongo (https://paymongo.com) — real online payment integration.
 *
 * Uses PayMongo's Hosted Checkout (v2 checkout_sessions) so students pay with
 * GCash / Maya / card on PayMongo's secure page. We never touch card data.
 *
 * Server-side only: every call below requires PAYMONGO_SECRET_KEY and must
 * run on the backend. Amounts are integer centavos (₱250.00 = 25000).
 *
 * Docs: https://docs.paymongo.com/docs/payment-channels-hosted-checkout
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// Pull config lazily if config/database.php hasn't been loaded yet.
if (!defined('PAYMONGO_ENABLED')) {
    require_once __DIR__ . '/../config/database.php';
    if (!defined('PAYMONGO_ENABLED')) {
        define('PAYMONGO_ENABLED', (string) env('PAYMONGO_SECRET_KEY', '') !== '');
        define('PAYMONGO_SECRET_KEY', (string) env('PAYMONGO_SECRET_KEY', ''));
        define('PAYMONGO_MODE',       (string) env('PAYMONGO_MODE', ''));
    }
}

/** True when a PayMongo secret key is configured. */
function paymongo_enabled(): bool
{
    return PAYMONGO_ENABLED && PAYMONGO_SECRET_KEY !== '';
}

/**
 * HTTP POST to PayMongo. Returns [status:int, body:array] where body is the
 * decoded JSON (JSON-RPC-ish {data:{attributes:...}} envelope on success).
 */
function paymongo_request(string $method, string $path, ?array $payload = null): array
{
    $ch = curl_init('https://api.paymongo.com' . $path);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => PAYMONGO_SECRET_KEY . ':', // Basic auth: secret key, blank password
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    if (PHP_MAJOR_VERSION < 9) {
        curl_close($ch);
    }
    if ($body === false || $body === '') {
        return [$code ?: 0, ['_error' => $err !== '' ? $err : 'empty response from PayMongo, HTTP ' . $code]];
    }
    $decoded = json_decode((string) $body, true);
    return [$code, is_array($decoded) ? $decoded : ['_error' => 'non-JSON response', 'raw' => $body]];
}

/**
 * Create a Hosted Checkout Session for one payment.
 * Returns [ok:bool, checkout_url:string, session_id:string, error:string].
 */
function paymongo_create_checkout(
    int $amountCentavos,
    string $description,
    array $methodTypes,
    string $successUrl,
    string $cancelUrl,
    array $metadata = []
): array {
    if (!paymongo_enabled()) {
        return [false, '', '', 'PayMongo is not configured (PAYMONGO_SECRET_KEY missing).'];
    }
    if ($amountCentavos < 10000) { // PayMongo minimum: ₱100.00
        return [false, '', '', 'Amount below PayMongo minimum of PHP 100.00.'];
    }

    $attributes = [
        'line_items' => [[
            'name'          => mb_substr($description, 0, 200),
            'amount'        => $amountCentavos,
            'currency'      => 'PHP',
            'quantity'      => 1,
            'description'   => mb_substr($description, 0, 500),
        ]],
        'payment_method_types' => array_values(array_unique(array_filter($methodTypes))),
        'success_url'          => $successUrl,
        'cancel_url'           => $cancelUrl,
        'metadata'             => $metadata,
    ];

    [$code, $body] = paymongo_request('POST', '/v2/checkout_sessions', ['data' => ['attributes' => $attributes]]);
    if ($code < 200 || $code >= 300 || !isset($body['data']['attributes']['checkout_url'])) {
        $err = $body['_error'] ?? ($body['errors']['0']['detail'] ?? ('HTTP ' . $code . ' from PayMongo'));
        error_log('PayMongo checkout create failed: ' . $err);
        return [false, '', '', 'Could not start online payment. Please try again or use manual upload. (' . $err . ')'];
    }
    return [
        true,
        (string) $body['data']['attributes']['checkout_url'],
        (string) ($body['data']['id'] ?? ''),
        '',
    ];
}

/**
 * Retrieve a Checkout Session (by id) — used to verify payment status
 * server-side. Returns raw attributes array, or null on failure.
 */
function paymongo_get_checkout(string $sessionId): ?array
{
    if ($sessionId === '' || !paymongo_enabled()) return null;
    [$code, $body] = paymongo_request('GET', '/v2/checkout_sessions/' . rawurlencode($sessionId));
    if ($code < 200 || $code >= 300 || !isset($body['data']['attributes'])) {
        error_log('PayMongo checkout retrieve failed (HTTP ' . $code . ')');
        return null;
    }
    return $body['data']['attributes'] ?? null;
}

/**
 * Check whether a retrieved checkout session has at least one PAID payment.
 * Per PayMongo docs: session.status is 'active'/'expired' (not 'paid');
 * you look at attributes.payments[] and any element with status 'paid'.
 */
function paymongo_checkout_is_paid(array $attrs): bool
{
    $payments = $attrs['payments'] ?? [];
    if (!is_array($payments)) return false;
    foreach ($payments as $p) {
        $st = (string) ($p['attributes']['status'] ?? '');
        if ($st === 'paid') return true;
    }
    return false;
}

/**
 * Verify a PayMongo webhook signature (Paymongo-Signature header).
 * Header format:  t=<unix_ts>, te=<test_sig>, li=<live_sig>, v2=<test_sig>...
 * HMAC-SHA256 over "<t>.<raw_body>", keyed with the webhook secret.
 * Returns true if ANY provided signature matches for this environment.
 */
function paymongo_verify_webhook_signature(string $rawBody, string $paymongoSignatureHeader, string $webhookSecret): bool
{
    if ($webhookSecret === '') return false;
    if ($paymongoSignatureHeader === '') return false;

    // Split "t=...,te=... ,li=...,or v2=<sig>" into parts
    $parts = [];
    foreach (explode(',', $paymongoSignatureHeader) as $chunk) {
        $kv = explode('=', trim($chunk), 2);
        if (count($kv) === 2) $parts[trim($kv[0])] = trim($kv[1]);
    }
    $ts = $parts['t'] ?? '';
    if ($ts === '') return false;

    // Replay protection: signature must be within 5 minutes
    if (!ctype_digit($ts) || abs(time() - (int) $ts) > 300) return false;

    $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $webhookSecret);

    // Accept the signature declared for EITHER mode (te or li) — we can't
    // know which env the webhook came from without looking, and hash_-
    // equals() against both is harmless: only the right one can match.
    foreach (['te', 'li', 'v2'] as $key) {
        $candidate = $parts[$key] ?? '';
        if ($candidate !== '' && hash_equals($expected, $candidate)) {
            return true;
        }
    }
    return false;
}

/**
 * Complete an in-house payment + request after PayMongo confirms a paid
 * checkout (used by BOTH the browser return page and the webhook).
 *
 * IMPORTANT: the webhook auto-issues the official receipt via the DB
 * trigger trg_payments_after_update when status flips to 'paid'.
 *
 * Idempotent: marks paid only when the payment is still pending.
 * Returns [changed:bool, method:string].
 */
function paymongo_complete_payment(int $paymentId, string $gatewayRef): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1');
    $stmt->execute([$paymentId]);
    $p = $stmt->fetch();
    if (!$p) return [false, 'missing'];

    if ($p['status'] === 'paid') return [false, $p['method']]; // already done

    // Flip the payment to paid and advance the request in one transaction.
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE payments SET status = "paid", paid_at = NOW(), gateway_reference = ? WHERE id = ? AND status IN ("pending","failed")');
        $upd->execute([$gatewayRef, $paymentId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('paymongo_complete_payment failed: ' . $e->getMessage());
        return [false, $p['method']];
    }

    // Mark the request as processing (safe on idempotent retry, since the
    // trigger trg_payments_after_update already fired on the first one).
    $pdo->prepare('UPDATE requests SET status = "processing", updated_at = NOW() WHERE id = ? AND status IN ("pending","approved")')
        ->execute([(int) $p['request_id']]);

    return [true, (string) $p['method']];
}
