<?php

declare(strict_types=1);

/**
 * Standalone live-webhook receiver for sandbox verification.
 *
 * Deliberately not the Laravel bridge: this exercises the framework-agnostic
 * core (WebhookVerifier + WebhookEventFactory) directly, with no framework
 * in the way to mask or reinterpret a failure.
 *
 * Usage:
 *   DPAY_WEBHOOK_SECRET=... php -S 0.0.0.0:8787 tests/sandbox/webhook-receiver.php
 *
 * Then expose :8787 over HTTPS and register that URL in DPay's dashboard.
 *
 * Every delivery is appended to tests/sandbox/.webhook-log.jsonl (gitignored)
 * and echoed to stderr, whether it verifies or not — a rejected delivery is
 * exactly as interesting as an accepted one.
 *
 * Responds 200 on success and 400 on a verification failure, matching the
 * Laravel controller. DPay may retry on non-2xx; that is intended, since a
 * retry of a genuinely bad signature is still a bad signature.
 */

require __DIR__.'/../../vendor/autoload.php';

use DPay\Exceptions\InvalidWebhookException;
use DPay\Webhooks\PaymentEvent;
use DPay\Webhooks\TestEvent;
use DPay\Webhooks\WebhookEventFactory;
use DPay\Webhooks\WebhookVerifier;

const WEBHOOK_LOG = __DIR__.'/.webhook-log.jsonl';

$secret = getenv('DPAY_WEBHOOK_SECRET');

if (! is_string($secret) || $secret === '') {
    http_response_code(500);
    // error_log(), not fwrite(STDERR): the STDERR constant does not exist
    // under the built-in server's SAPI, and referencing it fatals the
    // request. error_log() reaches the server's stderr from every SAPI.
    error_log('DPAY_WEBHOOK_SECRET is not set — start the receiver with it exported.');
    echo "receiver misconfigured\n";

    return true;
}

$rawBody = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_DPAY_SIGNATURE'] ?? '');
$timestamp = (string) ($_SERVER['HTTP_X_DPAY_TIMESTAMP'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

/**
 * Append one structured record, and mirror a human line to stderr.
 *
 * @param  array<string, mixed>  $extra
 */
$record = static function (string $outcome, string $detail, array $extra = []) use ($rawBody, $signature, $timestamp): void {
    $entry = [
        // Wall-clock only; this is an operator log, not test state.
        'at' => date('c'),
        'outcome' => $outcome,
        'detail' => $detail,
        'signature_present' => $signature !== '',
        'timestamp_header' => $timestamp,
        'body_bytes' => strlen($rawBody),
        'raw_body' => $rawBody,
    ] + $extra;

    file_put_contents(
        WEBHOOK_LOG,
        json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        FILE_APPEND,
    );

    error_log(sprintf('[%s] %s', strtoupper($outcome), $detail));
};

// A GET is almost always a human or a health check confirming the tunnel is
// up. Answer it plainly rather than logging it as a failed delivery.
if ($method !== 'POST') {
    http_response_code(200);
    echo "DPay sandbox webhook receiver is up. POST signed payloads here.\n";

    return true;
}

try {
    (new WebhookVerifier($secret))->verify($rawBody, $signature, $timestamp);
} catch (InvalidWebhookException $e) {
    // Status first, logging second — deliberately. When these were the other
    // way round, a fatal inside the logger left the default 200 in place and
    // a tampered webhook was answered "accepted". Never let bookkeeping
    // decide the response.
    http_response_code(400);
    $record('rejected', $e::class.': '.$e->getMessage());
    echo "rejected\n";

    return true;
}

$decoded = json_decode($rawBody, true);

if (! is_array($decoded)) {
    http_response_code(400);
    $record('verified-unparseable', 'signature valid but body is not a JSON object');
    echo "bad body\n";

    return true;
}

$event = WebhookEventFactory::fromArray($decoded);

$summary = match (true) {
    $event instanceof PaymentEvent => sprintf(
        'PaymentEvent %s — session %d, status %s, amount %s, live=%s, tx %s',
        $event->event->value,
        $event->sessionId,
        $event->status->value,
        var_export($event->amount, true),
        var_export($event->live, true),
        $event->txId,
    ),
    $event instanceof TestEvent => sprintf(
        'TestEvent — webhook %d (%s), merchant %d',
        $event->webhookId,
        $event->webhookLabel,
        $event->merchantId,
    ),
    default => 'unrecognized event class '.$event::class,
};

http_response_code(200);
$record('accepted', $summary, ['event' => $decoded['event'] ?? null, 'class' => $event::class]);
echo "ok\n";

return true;
