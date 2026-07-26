# DPay spec alignment — design

- **Date:** 2026-07-26
- **Target release:** `v0.2.0` (breaking)
- **Status:** approved, pending implementation plan

---

## 1. Context

`dpay-php` v0.1.0 was reverse-engineered from the health-portal Laravel app and
probed against DPay's sandbox. Its README states the field names and endpoint
paths *"have not been validated against an official DPay spec."*

That is no longer true. An official spec exists:

- HTML reference: <https://dpay.ly/docs/api>
- Machine-readable: <https://dpay.ly/assets/dits-pgs-api.postman_collection.json>

Reading both against the source revealed that the SDK is correct on the
fundamentals — base URL, `Bearer` auth, the three session endpoint paths, the
`pay_method` / `customer_mobile` / `card_number` keys, and the `pending`/`paid`
status strings — but diverges in ways that reject valid payments, and in one case
would silently alter an amount.

This design brings the SDK to full parity with the published spec.

### Defects this closes

| # | Defect | Impact |
|---|---|---|
| 1 | Card fields hardcoded to `digits:7`; spec allows 7 **or** 9 (OnePay cross-bank) | Valid cross-bank cards rejected before reaching DPay |
| 2 | `OpenSessionRequest::toBody()` casts `(int) $amount` | Latent. `10.50` → `10` if the fractional guard is relaxed |
| 3 | Fractional amounts rejected; `min_amount` defaults to 5 | Spec allows decimals, minimum `0.01` |
| 4 | `description` nested as `data.description` | Spec puts it top-level; `data` is free-form metadata |
| 5 | `SessionStatus` has no `voided` | Real status, silently degrades to `UNKNOWN` |
| 6 | `Idempotency-Key` never sent | No safe retry; contradicts our own troubleshooting advice |
| 7 | Sadad absent, wrongly concluded unsupported | Documented gateway; needs `birth_year` the abstraction can't express |
| 8 | No webhooks | Webhooks are how DPay confirms Moamalat; SDK polls instead |
| 9 | `UnknownProviderException` outside `DPayException` tree | `catch (DPayException)` misses it — including in our own docs example |
| 10 | Invoices, payments, pay-methods, auth unimplemented | ~11 spec'd endpoints with no SDK surface |

---

## 2. Decisions taken

| Decision | Choice |
|---|---|
| Scope | All five areas (correctness, Sadad, webhooks, merchant reads, invoices) in one cycle, phased internally |
| Breaking changes | Clean break to spec defaults; release `0.2.0` with `UPGRADING.md` |
| Field mapping | `PaymentField` gains a `sendAs` wire name; `OpenSessionRequest` gains typed properties for every spec'd field |
| Webhooks | Framework-agnostic verifier + DTOs in core; Laravel wiring opt-in, route off by default |

---

## 3. Transport refactor

`DPayClient` currently owns both transport and the session endpoints. Adding four
more domains to it would make one class responsible for five.

Extract `send` / `decode` / `buildException` into `DPay\Http\Transport`, then
compose five focused clients over it:

| Class | Endpoints |
|---|---|
| `DPayClient` | `POST /payment/sessions/open`, `/verify`, `GET /payment/sessions/{id}` |
| `AccountClient` | `GET /auth/me`, `POST /auth/logout` |
| `PaymentsClient` | `GET /payments`, `POST /payments/filter` |
| `PayMethodsClient` | `GET /pay-methods` |
| `InvoicesClient` | `GET/POST /invoices`, `GET/PUT/DELETE /invoices/{id}`, `POST /invoices/{id}/send` |

One place builds requests, attaches `Bearer` auth, decodes JSON, and maps status →
exception. `DPayClient` keeps its name and behaviour so the change is invisible to
callers that only use sessions.

---

## 4. Wire-format correctness

### `OpenSessionRequest`

Typed properties for every documented field: `payMethod`, `amount` (`float`),
`customerMobile`, `cardNumber`, `birthYear`, `category` (`?int`), `description`,
`data` (`array`).

`toBody()` changes:

- **Delete the `(int)` cast.** Amount serialises as a number.
- **`description` moves to top level.**
- **`data`** becomes free-form merchant metadata, emitted only when non-empty.
- Keep `array_filter(… !== null)` — deliberately, so `category: 0` (a valid Sadad
  category) survives. A truthiness filter would drop it.

### `DPayConfig`

`minAmount` becomes `float`, default `5` → `0.01`. Constructor guard relaxes to
`>= 0`.

### `DPayClient::openSession()`

The positional parameter list would reach eight. Signature becomes:

```php
openSession(OpenSessionRequest $request, ?string $idempotencyKey = null): OpenSessionResponse
```

The fractional-amount guard is removed. The `min_amount` check becomes a float
comparison. `Idempotency-Key` is sent as a header when supplied.

This breaks every existing call site — covered by `UPGRADING.md`.

### `SessionStatus`

Add `VOIDED = 'voided'`, included in `isTerminal()`.

---

## 5. Field mapping and card digits

`PaymentField` gains two properties:

- **`sendAs: ?string`** — wire field name, defaulting to `key`.
  `AbstractDPayProvider` maps every declared field through `sendAs`. The two
  hardcoded `if` branches are deleted. Adding a gateway field becomes a schema
  change, not a base-class change.
- **`digitsOneOf: ?list<int>`** — bank cards are 7 **or** 9 digits.
  `digits:7` cannot express it; `digits_between:7,9` would wrongly admit 8.
  `PaymentFieldRules` emits `regex:/^(\d{7}|\d{9})$/`. `toArray()` exposes it so
  the frontend validates identically.

Per the spec this splits the card providers:

| Provider | Rule |
|---|---|
| MasrefyPay, YousrPay, SaharaPay | 7 or 9 digits (OnePay cross-bank) |
| MobiCash | exactly 7 — spec is explicit |

New named constructors: `PaymentField::bankCardNumber()`, `::birthYear()`,
`::sadadCategory()`.

Confirmed cross-bank prefixes: `11` Jumhouria, `33` Commercial, `66` Sahara.

---

## 6. Sadad

With §5 in place, `SadadProvider` is an ordinary `AbstractDPayProvider` subclass
declaring `[phoneNumber(), birthYear(), sadadCategory()]`. No base-class change.

- 6-digit OTP, 10-minute validity.
- `category` is optional (0–36); omitting it uses the merchant's configured default.
- Config entry ships **disabled by default** — the gateway is merchant-gated,
  which is the actual reason the v0.1.0 probe saw `500 "Unsupported payment method"`.

---

## 7. Webhooks

### Core (framework-agnostic)

- **`WebhookVerifier`** — `hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret)`,
  compared with `hash_equals`. Rejects timestamps older than 300 seconds.
- **`WebhookEventType`** enum — `payment.paid`, `payment.failed`,
  `payment.expired`, `payment.refunded`, `payment.voided`, `webhook.test`.
- **Two payload shapes** behind a shared interface:
  - `PaymentEvent` — `session_id`, `status`, `amount`, `pay_method`, `tx_id`,
    `system_reference`, `network_reference`, `paid_through`, `payer_account`,
    `data`, `created_at`, `paid_at`, `live`.
  - `TestEvent` — `merchant_id`, `merchant_email`, `webhook_id`, `webhook_label`,
    `timestamp`, `message`. No `session_id`.
  - `WebhookEventFactory::fromArray()` discriminates on `event`.
- **`InvalidWebhookException`** with distinct signature and replay cases.

### Laravel bridge

A ready-made controller plus a `dpay.webhooks` config block (`enabled` **false**
by default, `route`, `secret`), dispatching a `DPayWebhookReceived` event. The
host keeps control of path, middleware, and CSRF exemption — a payment SDK should
not silently claim a public POST endpoint on install.

### Capability flags

- `supportsWebhook()` → `true` for all providers. Webhooks are account-level, not
  per-gateway.
- `supportsRefund()` → `true` for Moamalat only, with a docblock stating that
  refunds and voids are initiated out-of-band (dashboard) and observed via
  `payment.refunded` / `payment.voided`. The spec exposes no REST endpoint to
  trigger either.

---

## 8. Merchant reads and invoices

DTOs: `PayMethod` (`name`, `slug`, `icon` *(deprecated)*, `logo_url`, `active`,
`fee`, `min_deposit`, `max_deposit`), `Payment`, `Paginated`, `AuthUser`
(`user`, `roles`, `permissions`).

`POST /payments/filter` supports `from`, `to`, and `type`
(`all|success|failed|pending`) — the `type` param appears only in the Postman
collection, not the HTML page.

Invoices: `Invoice`, `InvoiceItem`, `InvoiceStatus` enum
(`draft|sent|paid|overdue|cancelled`), plus request objects mirroring the
documented validation — at least one item, `quantity >= 0.01`, integer
`unit_price`, `tax_rate` 0–100, 3-letter `currency` defaulting to `LYD`.

`GatewayManager` is **not** auto-hydrated from `/pay-methods` — a network call at
container boot is a bad failure mode. An opt-in helper is provided instead.

---

## 9. Exception hierarchy

`UnknownProviderException` extends `InvalidArgumentException`, so
`catch (DPayException)` misses it — a hole present in our own
`docs/checkout-flow.md` example.

Rather than move it in the hierarchy (which would break existing catch blocks),
introduce a `DPayExceptionInterface` marker implemented by both trees.
`catch (DPayExceptionInterface)` then catches everything, and nothing existing
breaks.

---

## 10. Verification strategy

### Two gates per phase

1. **Offline (always, CI):** `composer check` — PHPUnit + PHPStan level 8. A phase
   is not done until green.
2. **Live sandbox (local, opt-in):** runs against `https://dpay.ly/api/sandbox`
   with the token supplied via environment. Never hardcoded, never committed,
   never written to a log that is committed.

Work is sequenced so each provider is implemented, passes the offline gate, then
is verified live against the sandbox before moving on.

### Probe harness rebuild

The v0.1.0 `probe.php` is a one-shot script that was rate-limited mid-run — three
429s in the captured log, two providers skipped. It becomes:

- One scenario per provider, individually runnable:
  `php tests/sandbox/probe.php --provider=masrefypay`
- Paced with exponential backoff — the sandbox throttles at roughly four rapid calls
- Resumable via a JSON result ledger, so a throttled run continues rather than restarts
- Self-reporting — regenerates `SANDBOX-VALIDATION.md` from actual results
- Token read from environment only; output log scrubbed of any `sb_tk_` string

### Sandbox credentials

Fixed OTP `111111` for all gateways. Expiry `01/27` for card entry.

| Provider | Test data |
|---|---|
| EDFali | phones `0912345678`, `0923456789` |
| MobiCash | card `7279627` (7 digits) |
| MasrefyPay | `1234567` (Jumhouria), `111234567` (cross-bank) |
| YousrPay | `1234567` (Commercial), `331234567` (cross-bank, prefix 33) |
| SaharaPay | `1234567` (Sahara), `661234567` (cross-bank, prefix 66) |
| Moamalat | LightBox; cards `6395043835180860`, `6395043165725698`, `6395043170446256`, `6395043987382215`, `6395043165743733` |

Moamalat sandbox merchant credentials (Merchant ID, Terminal ID, Secure Key) are
supplied via the dashboard and are **not** recorded in this repository.

### Per-provider live matrix

| Provider | Proves |
|---|---|
| edfali | open · **decimal `10.50` round-trips as `10.5`** · verify `111111` · verify wrong → false · getSession · **`Idempotency-Key` replay returns the same `session_id`** |
| mobicash | **`description` lands top-level, not under `data`** · full OTP cycle |
| masrefypay | same-bank **and 9-digit cross-bank** — proves the `digits:7` fix |
| yousrpay | same, prefix 33 |
| saharapay | same, prefix 66 |
| moamalat | open · `payment_link` present · **LightBox driven in a browser** to reach `paid` |

Cross-cutting: 401, 404, 429 backoff, invalid `pay_method`, and `category: 0`
surviving the null filter.

The two bold rows are the money defects (#1, #2, #4). They are proven against the
real gateway, not only against a fake HTTP client.

### Merchant endpoints

The sandbox is reported to cover `/api/sandbox/*` beyond payment sessions. This is
**verified empirically as the first step of the relevant phase** with a read-only
`GET /api/sandbox/pay-methods`. If it returns the gateway list, invoice
create → update → cancel runs against the sandbox. If it 404s, invoice writes stay
mocked against Postman golden bodies and no write is fired at the live account.

### Blocked, with defined fallback

Both are blocked on information the user will supply; neither blocks other phases.

| Item | Blocker | Fallback until unblocked |
|---|---|---|
| **Sadad** | No sandbox test mobile or `birth_year` published; gateway likely not enabled on the merchant | Provider and DTOs are built and unit-tested against the Postman golden body. The live scenario runs and **records the failure honestly** rather than being skipped or asserted green |
| **Webhooks** | Signing secret is dashboard-only; delivery needs a public HTTPS endpoint (DPay rejects localhost and private IPs) | Verifier tested offline against known-good and tampered vectors, both payload shapes, and an expired timestamp. End-to-end delivery deferred |

### Not coverable

- **Real OTP delivery** — sandbox uses a fixed code.
- **Refund / void initiation** — no REST endpoint exists in either the HTML docs
  or the Postman collection.

---

## 11. Testing

Golden-body tests are the core: assert the exact JSON emitted for each of the
eight Postman `openSession` examples, byte-for-byte. Plus an explicit regression
that `10.50` serialises as `10.5` and never `10`.

Webhook tests cover a valid signature, a tampered body, an expired timestamp, and
both payload shapes. Laravel feature tests cover the controller and confirm the
route is off by default. PHPStan stays at level 8 with `src/Laravel` excluded,
covered instead by feature tests.

`MockTransport` gains per-gateway expiry (15 min default, 10 for Moamalat and
Sadad) and mirrors the sandbox's `000000` → decline behaviour.

---

## 12. Documentation

- `README.md` — provider table, test counts, and removal of the "not validated
  against an official DPay spec" claim
- `composer.json` — drop Yaser from description and keywords, restore Sadad
- `CHANGELOG.md` — `0.2.0`
- New `UPGRADING.md` — covers the `openSession` signature change, `minAmount`
  int → float, and decimal amounts now being accepted
- New `docs/webhooks.md`, `docs/invoices.md`
- All seven existing `docs/` files updated, including the
  `catch (DPayException)` hole in `checkout-flow.md`
- `SANDBOX-VALIDATION.md` — regenerated by the harness, marked as superseded by
  the official spec
- `CLAUDE.md` — divergence table updated to reflect resolution

---

## 13. Out of scope

- **`mpgs`** — appears in webhook examples and expiry notes but not in the
  documented `pay_method` list. `pay_method` is a plain string, so the DTOs handle
  it as data. No provider will be shipped for an undocumented gateway. Worth
  confirming with DPay.
- **Refund / void initiation** — no endpoint exists.
- **Yaser** — absent from the official spec entirely. Correctly dropped.

Also resolved in passing: `logo()` currently returns
`images/payment-methods/*.svg` while the bridge publishes to
`public/vendor/dpay/`. The paths are aligned, and `logo_url` is exposed on the new
`PayMethod` DTO as the upstream-authoritative alternative.

---

## 14. Success criteria

1. Every request body the SDK emits matches its Postman counterpart byte-for-byte.
2. A decimal amount reaches DPay unaltered, proven live.
3. A 9-digit OnePay card opens a session on all three bank gateways, proven live.
4. `description` arrives top-level, proven live.
5. Replaying an `Idempotency-Key` returns the original `session_id`, proven live.
6. Moamalat reaches `paid` via the LightBox, driven in a browser.
7. Webhook signature verification passes known-good vectors and rejects tampered
   bodies and expired timestamps.
8. `composer check` green on PHP 8.2, 8.3, 8.4.
9. Sadad and webhook end-to-end are recorded as blocked with their reasons, not
   silently omitted.
