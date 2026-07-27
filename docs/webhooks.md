# Webhooks

DPay can push payment status changes to your server instead of (or in
addition to) polling `getSession()`. This SDK verifies the signature and
parses the payload into a typed object; wiring the HTTP endpoint is on you
in pure PHP, or opt-in and mostly automatic in Laravel.

---

## The six events

| Event | Fires when |
|---|---|
| `payment.paid` | Payment completed successfully |
| `payment.failed` | Declined, errored, or OTP retries exhausted (an OTP mis-type alone does NOT fire this — the session stays pending until retries run out or it expires) |
| `payment.expired` | Session timed out before the customer completed payment |
| `payment.refunded` | A previously-paid transaction was refunded (Moamalat only; DPay exposes no REST endpoint to *trigger* a refund — this event is how you find out one happened) |
| `payment.voided` | An authorization was cancelled before capture (Moamalat only) |
| `webhook.test` | Sent by the "Send test event" button at Dashboard -> Webhooks. Different payload shape — no `session_id` at all. |

Configure up to 5 independent endpoints at **Dashboard -> Webhooks**, each
with its own signing secret and event filter.

---

## Verifying a request — pure PHP

```php
use DPay\Webhooks\WebhookVerifier;
use DPay\Webhooks\WebhookEventFactory;
use DPay\Exceptions\InvalidWebhookException;

$verifier = new WebhookVerifier($secret); // from Dashboard -> Webhooks

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_DPAY_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_DPAY_TIMESTAMP'] ?? '';

try {
    $verifier->verify($rawBody, $signature, $timestamp);
} catch (InvalidWebhookException $e) {
    http_response_code(401);
    exit;
}

$event = WebhookEventFactory::fromArray(json_decode($rawBody, true));

if ($event instanceof \DPay\Webhooks\PaymentEvent && $event->eventType() === \DPay\Webhooks\WebhookEventType::PAID) {
    // mark the order paid, keyed by $event->data['order_id'] if you sent one
    // when opening the session (data is free-form merchant metadata DPay
    // echoes back here)
}

http_response_code(200);
```

**Verify before you decode.** An unverified body must never reach your
business logic — `WebhookVerifier::verify()` throws before anything else
happens if the signature or timestamp is wrong.

---

## Laravel — opt-in setup

Off by default. Three steps:

1. Create a signing secret at **Dashboard -> Webhooks** and point DPay at
   `https://your-app.example/webhooks/dpay` (or your own path — see step 3).
2. Set in `.env`:
   ```env
   DPAY_WEBHOOKS_ENABLED=true
   DPAY_WEBHOOK_SECRET=whsec_...
   ```
3. Optionally override the path:
   ```env
   DPAY_WEBHOOK_ROUTE=/dpay/webhook
   ```

The route registers with **no middleware group** — it's a bare POST route,
so Laravel's CSRF protection (which only applies inside the `web` group)
never blocks it. You don't need to add anything to `VerifyCsrfToken::$except`.

**Rate limiting.** The route ships with no middleware by default — it's
now a public, internet-facing endpoint at a well-known path, so add
Laravel's built-in throttle if you want it (this doesn't touch CSRF and
won't reintroduce the problem above):

```php
// config/dpay.php, after publishing
'webhooks' => [
    // ...
    'middleware' => ['throttle:60,1'],
],
```

Not env-driven — middleware specs don't serialize cleanly to a single env
var, so edit the published config file directly.

**Misconfiguration fails at boot, not on your first real webhook.** If you
set `DPAY_WEBHOOKS_ENABLED=true` and forget `DPAY_WEBHOOK_SECRET`, the app
throws immediately when it boots (a normal, visible deploy-time error) —
not a silent 500 the first time DPay actually sends you something.

Listen for the parsed event anywhere in your app:

```php
use DPay\Laravel\Events\DPayWebhookReceived;
use DPay\Webhooks\PaymentEvent;
use Illuminate\Support\Facades\Event;

Event::listen(function (DPayWebhookReceived $e) {
    if (! $e->event instanceof PaymentEvent) {
        return; // webhook.test — nothing to act on
    }

    match ($e->event->eventType()) {
        \DPay\Webhooks\WebhookEventType::PAID => Order::markPaid($e->event->data['order_id'] ?? null),
        \DPay\Webhooks\WebhookEventType::EXPIRED => Order::markExpired($e->event->data['order_id'] ?? null),
        default => null,
    };
});
```

The controller returns 401 (not a thrown exception your app has to catch)
on a bad signature or stale timestamp — that response is what tells DPay
whether to retry.

**Keep listeners fast, or make them queued.** `event()` inside the
controller dispatches synchronously — if your listener throws, that
exception escapes the controller uncaught and DPay sees a 500, triggering
a redelivery that replays the same listener from scratch. For anything
that can fail (a DB write, an outbound call), implement
`Illuminate\Contracts\Queue\ShouldQueue` on the listener so a transient
failure retries via your queue, not via DPay hammering your endpoint.

---

## Idempotency

DPay may redeliver the same event up to 5 times (1 initial + 4 retries,
exponential backoff) if your endpoint doesn't return 2xx within 15 seconds.
Dedupe on `session_id + event` — process a `(42, "payment.paid")` you've
already seen as a no-op, not a duplicate charge confirmation.

---

## What this SDK does NOT do

- **Doesn't register a webhook endpoint with DPay.** You still create the
  endpoint and secret at Dashboard -> Webhooks yourself.
- **Doesn't retry delivery to you** — that's DPay's job, not this SDK's.
- **Doesn't dedupe events for you.** `session_id + event` idempotency is
  your application's responsibility (a `payments` table unique constraint,
  a Redis SETNX, whatever fits your stack).
- **`mpgs` as a `pay_method` value is passed through as a plain string,
  not modeled with a provider class** — it appears in official example
  payloads but isn't in the documented `pay_method` list for
  `POST /payment/sessions/open`. `PaymentEvent::$payMethod` handles it
  fine either way; there's just no `MpgsProvider`.
