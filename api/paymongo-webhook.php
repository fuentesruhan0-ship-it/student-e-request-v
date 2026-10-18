<?php
/**
 * PayMongo webhook — event receiver. This is the source of truth for marking
 * payments paid: the browser return page can be closed/refreshed, but the
 * webhook fires server-to-server.
 *
 * Security:
 *   1. Verifies the Paymongo-Signature header (HMAC-SHA256 over "<t>.<raw_body>",
 *      keyed by PAYMONGO_WEBHOOK_SECRET) and rejects replayed/stale signatures.
 *   2. Idempotent: paymongo_complete_payment() flips statuses only once.
 *
 * Configure in PayMongo's dashboard → Webhooks, URL:
 *   https://<your-domain>/api/paymongo-webhook.php
 * Events: checkout_session.payment.paid
 */

// Standalone bootstrap (no session needed).
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paymongo.php';

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'empty body']);
    exit;
}

$header = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? $_SERVER['REDIRECT_HTTP_PAYMONGO_SIGNATURE'] ?? '';
$secret = (string) env('PAYMONGO_WEBHOOK_SECRET', '');

if ($secret === '') {
    error_log('PayMongo webhook: PAYMONGO_WEBHOOK_SECRET not configured — rejecting.');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'webhook secret not configured']);
    exit;
}

if (!paymongo_verify_webhook_signature($raw, $header, $secret)) {
    error_log('PayMongo webhook: INVALID signature.');
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid signature']);
    exit;
}

$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid JSON']);
    exit;
}

$evType  = (string) ($event['data']['attributes']['type'] ?? '');
$payload = $event['data']['attributes']['data'] ?? null;
$csAttrs = is_array($payload) ? ($payload['attributes'] ?? null) : null;
$csId    = is_array($payload) ? (string) ($payload['id'] ?? '') : '';

$paymentId = 0;
if ($evType === 'checkout_session.payment.paid' && is_array($csAttrs)) {
    $metadata  = (array) ($csAttrs['metadata'] ?? []);
    $paymentId = (int) ($metadata['payment_id'] ?? 0);
}

if ($paymentId > 0 && paymongo_enabled()) {
    // Double-check with PayMongo server-side; never trust the webhook body alone.
    $attrs = $csId !== '' ? paymongo_get_checkout($csId) : null;
    if ($attrs !== null && paymongo_checkout_is_paid($attrs)) {
        [$changed, $method] = paymongo_complete_payment($paymentId, 'PAYMONGO-' . $csId);
        if ($changed) {
            error_log('PayMongo webhook: marked payment #' . $paymentId . ' paid via checkout ' . $csId);
        } else {
            error_log('PayMongo webhook: payment #' . $paymentId . ' already processed.');
        }
    } else {
        error_log('PayMongo webhook: could not confirm paid state for checkout ' . $csId . ' (payment #' . $paymentId . ')');
    }
}

http_response_code(202);
header('Content-Type: application/json');
echo json_encode(['received' => true]);
