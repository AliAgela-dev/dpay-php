# Sandbox Validation Report — 2026-05-22

This is the one-time evidence that the SDK works against the real DPay
sandbox, not just against the mock. Captured by running
[tests/sandbox/probe.php](tests/sandbox/probe.php) +
[tests/sandbox/probe-remaining.php](tests/sandbox/probe-remaining.php).

The raw probe log is at [tests/sandbox/probe-output.log](tests/sandbox/probe-output.log).

---

## Environment

- Base URL: `https://dpay.ly/api/sandbox`
- API key: `sb_tk_x2Splq…` _(redacted — set via `DPAY_API_KEY` env var; never commit the full token)_
- Universal OTP: `111111`
- Probe ran: 2026-05-22T21:29:18Z → 21:36:14Z
- SDK version at time of probe: pre-`v0.1.0`

---

## Per-provider results

| Provider | sendOtp | verifyOtp(`111111`) | verifyOtp(wrong) | getSession | Notes |
|---|---|---|---|---|---|
| **edfali**     | ✅ session_id=600 | ✅ TRUE | ✅ FALSE | ✅ status=paid | Phone `0912345678`, fee 0.2% |
| **mobicash**   | ✅ session_id=603 | ✅ TRUE | ✅ FALSE | ✅ status=paid | Card `7279627`, fee 0.8% |
| **masrefypay** | ✅ session_id=606 | ✅ TRUE | ✅ FALSE | ✅ status=paid | Card `1234567`, fee 0% |
| **yousrpay**   | ✅ session_id=610 | ✅ TRUE | _(not run)_ | _(not run)_ | Card `1234567`, fee 0% |
| **saharapay**  | ✅ session_id=612 | ✅ TRUE | _(not run)_ | _(not run)_ | Card `1234567`, fee 0% |
| **moamalat**   | ✅ session_id=614 + `payment_link` | ⏳ pending (expected) | N/A | _(not run)_ | LightBox URL: `https://dpay.ly/sandbox/moamalat-pay/614` |

> Sadad and Yaser were probed too and **both returned `500 "Unsupported
> payment method"`**. The provider classes were subsequently removed from
> the SDK — they can be re-added by following
> [docs/extending.md § Scenario 2](docs/extending.md#scenario-2--a-new-dpay-pay_method)
> when DPay enables them on your merchant.

> "_not run_" cells were skipped to avoid rate-limiting; the lifecycle is
> identical to MasrefyPay (same provider class, same body shape) — runs
> were prioritised on the more popular gateways.

---

## Negative-path results

| Test | Expected | Actual | OK |
|---|---|---|---|
| `openSession` with fractional amount (50.5) | `DPayValidationException` pre-flight | ✅ Same | ✅ |
| `openSession` with amount < `min_amount` (1) | `DPayValidationException` pre-flight | ✅ Same | ✅ |
| `openSession` with unknown `pay_method` | Server-side rejection | `DPayException (500): The selected pay method is invalid.` | ✅ |
| `getSession` with bogus id (99999999) | `DPaySessionNotFoundException` | `DPaySessionNotFoundException (404): Payment session not found` | ✅ |
| `openSession` with invalid API key | `DPayAuthException` | `DPayAuthException (401): Invalid sandbox API token.` | ✅ |
| Burst of requests | `DPayRateLimitException` | `DPayRateLimitException (429): Too Many Attempts.` | ✅ |

---

## Raw response shapes (cite-able)

### `openSession` response (Edfali)

```json
{
  "message": "Payment session created successfully",
  "session_id": 601,
  "status": "pending",
  "amount": 50,
  "fee": 0.2,
  "fee_amount": 0.1,
  "total": 50.1,
  "pay_method": "edfali",
  "expired_at": "2026-05-22T21:37:47.000000Z",
  "data": null,
  "sandbox": true
}
```

### `openSession` response (Moamalat — note `payment_link`)

```json
{
  "message": "Payment session created successfully",
  "session_id": 614,
  "status": "pending",
  "amount": 50,
  "fee": 0.2,
  "fee_amount": 0.1,
  "total": 50.1,
  "pay_method": "moamalat",
  "expired_at": "2026-05-22T21:40:35.000000Z",
  "data": null,
  "sandbox": true,
  "payment_link": "https://dpay.ly/sandbox/moamalat-pay/614"
}
```

### `verifySession` success response

```json
{
  "message": "Payment already verified",
  "payment_id": 262,
  "status": "paid",
  "amount": 50,
  "pay_method": "edfali",
  "tx_id": "sb_txn_08bc1cd03e0bc43ebce12bc2b8ffd887",
  "sandbox": true
}
```

> `"Payment already verified"` because step 2 in the probe called verify
> twice (once via `provider->verifyOtp`, once via `client->verifySession`
> for the raw dump). First call would return `"Payment verified successfully"`.

### `getSession` response

```json
{
  "session_id": 600,
  "status": "paid",
  "amount": 50,
  "pay_method": "edfali",
  "expired_at": "2026-05-22T21:37:47.000000Z",
  "data": {
    "fee_amount": 0.1,
    "fee_percent": 0.2,
    "original_amount": 50
  },
  "sandbox": true
}
```

> Note `data` here is **structured** (unlike `data: null` in openSession).
> Our `GetSessionResponse::$data` is typed as `mixed` — works as-is.

### Error response shapes

| Type | Real response |
|---|---|
| 401 | `{"message": "Invalid sandbox API token."}` |
| 404 | `{"message": "Payment session not found"}` |
| 429 | `{"message": "Too Many Attempts."}` |
| 500 (bad pay_method) | `{"message": "Unsupported payment method: sadad"}` or `{"message": "The selected pay method is invalid."}` |

All map cleanly to our exception hierarchy.

---

## DTO patches applied as a result of this validation

1. **`OpenSessionResponse::$message`** — added (sandbox always returns it).
2. **`DPayRateLimitException`** — added; 429 mapping in `DPayClient::buildException`.
3. New unit tests in [DPayClientTest](tests/Unit/DPayClientTest.php) covering both above.

No changes needed to `VerifySessionResponse`, `GetSessionResponse`,
`SessionStatus`, or any provider class — they all matched reality.

---

## Things we didn't validate (yet)

- **Real OTP delivery** to phones / cards (sandbox uses a fixed `111111`).
- **Production status strings** for non-paid terminal states (`failed`,
  `expired`, `refunded`) — `SessionStatus` enum has all of them but the
  sandbox doesn't seem to expose a way to reach them deliberately.
- **Webhook callbacks** — no provider in the SDK has `supportsWebhook: true`
  yet. If DPay rolls out webhooks, the validation strategy is the same:
  receive one in a controlled test, dump the shape, write a DTO.
- **Refunds** — same as webhooks. `supportsRefund: false` everywhere today.
- **Sadad / Yaser** — both return 500 in sandbox. May be tenant-specific.
  Need to confirm with DPay whether they're enabled per-merchant or
  globally disabled.

---

## Re-running this validation

The probe is idempotent and re-runnable. After significant SDK changes
(particularly DTO edits), run:

```bash
php tests/sandbox/probe.php           # 8 providers
php tests/sandbox/probe-remaining.php # focused re-run with longer delays
```

If output differs from the captured log, diff it and patch.
