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

### What has actually been delivered to us (2026-08-16)

Verified end-to-end against a real DPay-signed delivery over a public
HTTPS endpoint — signature checked with `WebhookVerifier`, payload parsed
by `WebhookEventFactory`:

| Event | Live-verified | Notes |
|---|---|---|
| `payment.paid` | ✅ | Parsed to `PaymentEvent`, all fields mapped |
| `payment.failed` | ✅ | Triggered via Moamalat's "Simulate Declined Payment" |
| `payment.expired` | ✅ | **Arrived ~5 minutes after `expired_at`.** `getSession()` reported `expired` immediately — don't read webhook silence as "still pending" |
| `webhook.test` | ✅ | Real dashboard event, parsed to `TestEvent`. Carries an extra `test: true` field the DTO doesn't map — it lands in `->raw` |
| `payment.refunded` | ❌ | Dashboard-only; no REST endpoint to trigger one |
| `payment.voided` | ❌ | Same. `SessionStatus::VOIDED` has therefore never been seen in a real response |

### Refunds and voids cannot be triggered — by anyone

Worth stating plainly, because it is easy to go looking for a button that
does not exist. Enumerating **every** endpoint in the official Postman
collection (auth, sessions, payments, pay-methods, invoices) turns up **no
refund or void route at all**. `payment.refunded` and `payment.voided`
exist only as *inbound* webhook examples: DPay tells you a refund happened,
and gives you no way to cause one.

Both are **Moamalat only** — the spec says so explicitly — so they will
never appear for Edfali, MobiCash, Sadad or the bank gateways. And per the
spec, a void "typically only works within a short window after
authorisation", since it releases a hold that has not yet settled; a refund
reverses an already-settled charge and has no such window.

If you need to reverse a payment, that happens outside this API.

Two caveats from those deliveries:

- **`system_reference`, `network_reference`, `paid_through` and
  `payer_account` were `null` in every single one.** That looks like
  correct behaviour rather than a gap: the Postman examples only show them
  populated (`"Visa"`, `"****1234"`) on Moamalat events, so they appear to
  be card-rail fields that don't apply to wallet or bank gateways. The
  SDK's `?string` handling is proven; the populated case is not.
- **The `data` you sent comes back with DPay's `fee_amount`, `fee_percent`
  and `original_amount` merged in**, and `amount` is the *rounded settled*
  figure, not what you requested. See
  [troubleshooting.md](troubleshooting.md#the-settled-amount-doesnt-match-what-i-asked-for).

`tests/sandbox/webhook-receiver.php` is a standalone receiver for repeating
this — it exercises `WebhookVerifier` and `WebhookEventFactory` directly,
with no framework in between.

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

Size this to your actual delivery volume, not a guess. DPay treats a 429
the same as any other non-2xx — it counts toward the 5-attempt redelivery
budget below, so a limit set too low doesn't reject excess traffic, it
just makes DPay retry it, on the same limited budget.

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
- **Doesn't check the sender's IP address.** Only the signature and
  timestamp are verified. DPay doesn't publish a fixed source-IP range to
  allowlist against, so don't rely on network-level IP restrictions as a
  substitute for signature verification — the signature is the actual
  trust boundary here.
