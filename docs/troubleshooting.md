# Troubleshooting

## Error catalog

Every error the SDK can throw, what it means, what to check.

### `DPayValidationException` (4xx, including 422)

| Symptom | Cause | Fix |
|---|---|---|
| `Amount is below the minimum of N.` | Amount < `min_amount` (default `0.01`). | Either raise the amount or lower `min_amount` in config. Decimal amounts (e.g. `49.5`) are accepted — there's no whole-number requirement. |
| `pay_method is required` / `customer_mobile is required` / similar messages from DPay | Your provider's `sendOtp` produced a body DPay rejected — typically because the `$fields` you passed didn't match the provider's `requiredFields()` schema. | Run `$provider->requiredFields()` and make sure your input includes those keys. Check [docs/providers.md](providers.md). |
| Sudden 422 on a previously-working integration | DPay changed their accepted `pay_method` strings. | Override via env, e.g. `DPAY_PAY_METHOD_EDFALI=edfali_v2`. No redeploy needed. |

### `DPayAuthException` (401 / 403)

| Symptom | Cause | Fix |
|---|---|---|
| `invalid api key` | `DPAY_API_KEY` is empty / wrong / expired. | Check your `.env`, regenerate with DPay. |
| 403 on `getSession` only | Some keys are restricted to certain endpoints. | Confirm with DPay support which key tier you have. |

### `DPaySessionNotFoundException` (404)

| Symptom | Cause | Fix |
|---|---|---|
| `Payment session not found.` from `getSession` | Session ID is wrong, expired, or DPay purged it. | If polling Moamalat, treat as `expired` and stop polling. |

### `DPayNetworkException`

| Symptom | Cause | Fix |
|---|---|---|
| `Failed to reach DPay: cURL error 6: Could not resolve host` | DNS failure / wrong `DPAY_BASE_URL`. | Verify URL; ping the host. |
| `Failed to reach DPay: cURL error 28: Operation timed out` | DPay slow or unreachable. | Bump `DPAY_TIMEOUT`; never automatically retry — a half-completed openSession could leave a dangling session. |

### `DPayRateLimitException` (429)

| Symptom | Cause | Fix |
|---|---|---|
| `Too Many Attempts.` | You (or your test suite) fired requests faster than DPay's rate limit. The sandbox is aggressive — even 4–5 requests in quick succession can trip it. | Back off and retry later. Don't loop-retry immediately; space calls out (the sandbox probe uses 2.5–15s delays). |

### `UnknownProviderException`

| Symptom | Cause | Fix |
|---|---|---|
| `Payment provider [foo] is not supported.` | You called `DPay::provider('foo')` for a code you never registered. | Either register it (see [extending.md](extending.md)) or fix the typo. |
| `Payment provider [foo] is disabled.` | Code exists in config but `enabled => false`. | Set `PAYMENT_GATEWAY_FOO_ENABLED=true`, or check why it's off. |

### Webhook verification exceptions

These come from `DPay\Webhooks\WebhookVerifier::verify()`, not from
`DPayClient`. None of them ever include the expected signature or your
webhook secret in `->getMessage()` — the request that triggers them is
attacker-controlled, so nothing sensitive is echoed back.

| Symptom | Cause | Fix |
|---|---|---|
| `WebhookSignatureMismatchException` | Computed HMAC didn't match `X-DPAY-Signature`. | Confirm `DPAY_WEBHOOK_SECRET` matches Dashboard → Webhooks → Reveal Secret exactly. Also check nothing upstream (a proxy, middleware) is re-encoding the JSON body before your handler sees it — the signature is computed over the exact raw bytes DPay sent. |
| `WebhookTimestampExpiredException` | `X-DPAY-Timestamp` is more than 5 minutes from "now," in either direction. | Check server clock drift (NTP). A future timestamp usually means clock skew, not a replay attack. |
| Constructing `WebhookVerifier` throws `InvalidArgumentException` | Empty secret. `WebhookVerifier` refuses to construct with `''` rather than silently rejecting every webhook with a misleading signature-mismatch message. | Set `DPAY_WEBHOOK_SECRET`. In Laravel, this fails at **boot** time when `dpay.webhooks.enabled` is true, not on your first real webhook. |

See [webhooks.md](webhooks.md) for full setup.

---

## Common gotchas

### "`verifyOtp` returns `false` even though I entered the right code"

The DPay session can be:
- expired (default 30 min after open)
- already verified once (DPay's idempotency rules; depends on tier)
- never opened (you passed the wrong reference from `sendOtp`)
- using a stale OTP (user requested a new one — only the latest one works)

In all four cases `verifySession` returns `null` and the provider's
`verifyOtp` returns `false`. To distinguish, call `DPay::getSession($ref)`:
the status string tells you (`expired` / `failed` / etc.).

### "I'm in mock mode but my tests still fail"

`DPAY_MOCK=true` accepts only **4–6 digit numeric** OTPs. Anything else
returns `null` (so `verifyOtp` returns false). Use `'1234'` in tests, not
`'abc'`.

### "Logos 404 in production"

You skipped `php artisan vendor:publish --tag=dpay-logos`. The SVGs live in
the package's `resources/logos/` and need to be copied to
`public/vendor/dpay/`. Run that command on deploy.

### "Where is Sadad / Why does it fail with 'Unsupported payment method'?"

Sadad ships with the SDK (`SadadProvider`) but is **disabled by default**
(`PAYMENT_GATEWAY_SADAD_ENABLED=false`) because it's merchant-gated on
DPay's side — their sandbox rejects it with
`"Unsupported payment method: sadad"` until DPay enables it for your
merchant account. Confirm with DPay, then set
`PAYMENT_GATEWAY_SADAD_ENABLED=true`. No code changes needed.

**Yaser** isn't shipped and doesn't appear in the official DPay spec at all
— there's nothing to enable.

### "Moamalat `verifyOtp` always returns `false` even though the user paid"

Two possibilities:
1. You're calling it too early — the user hasn't actually completed payment
   in the Lightbox yet. Poll, don't single-fire. See
   [checkout-flow.md § Polling Moamalat](checkout-flow.md#polling-moamalat).
2. DPay's webhook hasn't updated the session status. `getSession` reflects
   the gateway's view, which can lag 1–2 seconds behind the user's action.

### "`DPAY_MOCK=true` left on in staging"

Symptom: any 4–6 digit OTP "works" and no real charge happens. There's no
runtime warning. Add a deploy-check that asserts `config('dpay.mock') === false`
in your `production` and `staging` environments.

### "Tightening the regex broke existing customers"

If you change the regex via config (e.g. `/^09\d{8}$/` → `/^09[1-6]\d{7}$/`)
you're telling your frontend "don't let new charges through that don't
match." That's safe forward — but if you had stored payment methods or
in-flight sessions for numbers like `0917...`, those references still work
on the DPay side; the regex only affects newly entered values.

### "Mock-mode tests are flaky"

`MockTransport` uses `random_int(1, 99999)` for session IDs — collisions
are vanishingly rare. If you actually see flakiness, you're probably
asserting equality against a hardcoded session_id. Use a regex or just
assert it's an int > 0.

### "I'm getting `DPayNetworkException` immediately, without any HTTP traffic"

Your `httpClient` constructor argument is wrong. With no Guzzle installed
and no PSR-18 client passed, `DPayClientFactory::create()` throws a
`RuntimeException` (not `DPayNetworkException`). Different error class —
check for it explicitly.
