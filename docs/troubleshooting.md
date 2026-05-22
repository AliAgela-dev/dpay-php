# Troubleshooting

## Error catalog

Every error the SDK can throw, what it means, what to check.

### `DPayValidationException` (4xx, including 422)

| Symptom | Cause | Fix |
|---|---|---|
| `Amount must be a whole number for this payment provider.` | You passed `49.5` (or any non-integer). DPay only accepts integers in LYD. | Round at the caller — `(int) round($amount)`. |
| `Amount is below the minimum of N.` | Amount < `min_amount` (default 5). | Either raise the amount or lower `min_amount` in config. |
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

### `UnknownProviderException`

| Symptom | Cause | Fix |
|---|---|---|
| `Payment provider [foo] is not supported.` | You called `DPay::provider('foo')` for a code you never registered. | Either register it (see [extending.md](extending.md)) or fix the typo. |
| `Payment provider [foo] is disabled.` | Code exists in config but `enabled => false`. | Set `PAYMENT_GATEWAY_FOO_ENABLED=true`, or check why it's off. |

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

### "Where are Sadad / Yaser?"

Not shipped. DPay's sandbox returns `500 "Unsupported payment method"`
for both. When DPay enables them for your merchant, follow
[extending.md § Scenario 2](extending.md#scenario-2--a-new-dpay-pay_method)
to add them back — it's two short PHP classes plus a config entry.

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
