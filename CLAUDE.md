# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`aliagela-dev/dpay-php` — a framework-agnostic PHP 8.2+ SDK for the DPay payment
gateway (Libya), with an optional Laravel bridge. MIT-licensed open source,
not yet on Packagist (installed via Composer VCS repository), PSR-4 under
`DPay\` → `src/`.

The SDK was reverse-engineered from a production Laravel app (referred to
throughout the source as "the original implementation") and then probed against
DPay's sandbox —
see [SANDBOX-VALIDATION.md](SANDBOX-VALIDATION.md) for captured request/response
shapes.

## Source of truth for the API

**An official DPay API spec exists: https://dpay.ly/docs/api**, with a Postman
collection at `https://dpay.ly/assets/dits-pgs-api.postman_collection.json`.
Read it before changing anything that touches the wire format. Treat the
published spec as authoritative and the sandbox probe log
(`SANDBOX-VALIDATION.md`) as corroborating live evidence.

The core assumptions the SDK got **right**: base URL `https://dpay.ly/api`,
`Authorization: Bearer <token>`, the three endpoint paths, the `pay_method` /
`customer_mobile` / `card_number` request keys, and the `pending`/`paid` status
strings.

## Spec alignment status

Plans 1–3 (v0.2.0 work) closed every gap below except per-gateway limits.
This table was last cross-checked against `src/` on 2026-07-28 — verify
against the spec yourself before assuming a row is still accurate.

| Area | Official spec | Status |
|---|---|---|
| Amount | Decimals allowed (`10.50`), minimum `0.01` | ✅ Resolved. `min_amount` is a float, defaults to `0.01` (`DPayConfig::$minAmount`). No whole-number check anywhere. |
| Amount on the wire | `number` | ✅ Resolved. No cast; `OpenSessionRequest::$amount` stays `float` end-to-end. |
| `description` | Top-level request field (MobiCash) | ✅ Resolved. `OpenSessionRequest::$description` is a top-level constructor param, independent of `$data`. |
| `data` | Free-form merchant metadata, echoed in webhooks | ✅ Resolved. `OpenSessionRequest::$data` is its own `array` param, unrelated to `description`. |
| Card numbers | 7 digits same-bank **or 9 digits cross-bank (OnePay)** | ✅ Resolved for bank gateways. `PaymentField::bankCardNumber()` (`digitsOneOf: [7, 9]`) is used by MasrefyPay/YousrPay/SaharaPay. MobiCash stays 7-only via `cardNumber(digits: 7)` — correct, MobiCash has no OnePay cross-bank path. |
| Sadad | Supported; needs `customer_mobile` + `birth_year` (4 digits) + optional `category` (0–36) | ✅ Shipped as `SadadProvider`, disabled by default (merchant-gated on DPay's side, not code-gated). Needed **zero** `AbstractDPayProvider` changes — see Architecture below. |
| Webhooks | HMAC-signed, 5 endpoints, `payment.paid/failed/expired/refunded/voided` | ✅ Resolved. `supportsWebhook()` returns `true` for every provider; `DPay\Webhooks\WebhookVerifier` (HMAC-SHA256 + 5-min replay window) and `WebhookEventFactory` (typed parsing for all 6 events, including `webhook.test`) ship in `src/Webhooks/`. See [docs/webhooks.md](docs/webhooks.md). |
| `Idempotency-Key` | Supported header on `sessions/open`; replays return the original session | ✅ SDK-side resolved. `DPayClient::openSession()` takes an optional `$idempotencyKey` and sends the header. Live-confirmed the sandbox itself doesn't honor replay correctly yet — that's a sandbox-side gap, not an SDK bug. See `SANDBOX-VALIDATION.md`. |
| Per-gateway limits | `GET /api/pay-methods` returns live `fee`, `min_deposit`, `max_deposit` per gateway | ⚠️ **Still open, and now known to bite.** No `PayMethod` DTO, no `PayMethodsClient`. Live-confirmed 2026-08-16 that DPay enforces these server-side (Edfali on our sandbox merchant: min `5`, max `60000`) and that they are **merchant-configurable per pay method from DPay's dashboard**. That is precisely why `min_amount` must stay permissive at `0.01` — no static SDK default can be right, so DPay has to be the authority. Reading `/pay-methods` is the only correct fix. |

A live sandbox check on 2026-07-28 found: `GET /api/sandbox/pay-methods`
returns real per-gateway data today (200, all 9 gateways with
`fee`/`min_deposit`/`max_deposit`/`enabled`) — building `PayMethodsClient`
is unblocked. `/auth/me`, `/payments`, `/invoices` aren't deployed to
sandbox yet (a branded 404 page, not a Laravel response — genuinely
unrouted, not a permissions issue). `/payments/filter` is a partial
exception: `POST` hits a real registered route (`405 Method Not Allowed`,
naming the route explicitly) but `GET` to the identical URL falls through
to the same branded 404 — looks like a routing-order bug on DPay's side,
not something to work around here. `VerifySessionResponse` maps only the
flat legacy fields — the spec's nested `payment` object, `receipt_url`,
`system_reference`, `network_reference`, `paid_through`, and
`payer_account` are reachable only via `->raw`.

## Commands

```bash
composer install          # composer.lock is gitignored (library convention)
composer test             # full PHPUnit suite
composer test:unit        # tests/Unit only
composer test:feature     # tests/Feature only (Orchestra Testbench)
composer analyse          # PHPStan level 8 on src/
composer check            # analyse + test — run this before committing
```

Single test / single file:

```bash
vendor/bin/phpunit --filter test_open_session_rejects_fractional_amount
vendor/bin/phpunit tests/Unit/DPayClientTest.php
```

Live sandbox probe (standalone scripts, **not** PHPUnit, **not** in CI — needs a
real sandbox token and only runs locally):

```bash
DPAY_API_KEY=sb_tk_... php tests/sandbox/probe.php
```

PHPUnit runs with `executionOrder="random"`, `failOnRisky`, and `failOnWarning` —
order-dependent or warning-emitting tests fail the build.

## Architecture

Two layers that can be used independently:

**Transport layer** — `DPayClient` (`src/Client/`) wraps DPay's three endpoints
(`POST /payment/sessions/open`, `POST /payment/sessions/verify`,
`GET /payment/sessions/{id}`) behind PSR-18/PSR-17 interfaces. It never depends on
a concrete HTTP client; `DPayClientFactory` sniffs for Guzzle/Nyholm as a
convenience for projects without DI. All responses become typed DTOs (`src/Dto/`),
each of which keeps the undecoded body in a `raw` property — `toArray()` returns
`raw` verbatim when present, so unmapped gateway fields are never lost.

**Provider layer** — `PaymentProviderInterface` (`src/Contracts/`) +
`GatewayManager` (`src/GatewayManager.php`), an in-memory registry with no
container dependency. This is the layer host applications should target for
multi-method checkout.

**The provider layer is schema-driven, and that's the central design idea.** Each
provider declares a `PaymentField[]` schema via `defaultFields()`;
`AbstractDPayProvider::sendOtp()` reads that schema and forwards every declared
field under its `PaymentField::wireName()` (the `sendAs` override, defaulting to
`key`) — `customer_mobile`, `card_number`, `birth_year`, `category`, and
`description` are all recognized, because those are exactly the named
parameters `OpenSessionRequest` accepts. There is deliberately no
per-field special-casing in `sendOtp()` itself. Consequences:

- Adding a DPay-backed gateway whose fields map onto an existing
  `OpenSessionRequest` parameter (phone, card, Sadad's birth-year/category) =
  one subclass declaring `code()`/`displayName()`/`logo()`/`defaultFields()`,
  plus a `config/dpay.php` entry. **Nothing else** — `SadadProvider` is the
  proof: it added `birth_year` and `category` with zero
  `AbstractDPayProvider` changes, because the base class was already
  generic. (An earlier version of this file claimed Sadad-like fields
  needed base-class changes — that was wrong even before Sadad shipped;
  the generic mapping predates it.)
- A gateway needing a wire field `OpenSessionRequest` has no parameter for
  must not extend `AbstractDPayProvider` — implement `PaymentProviderInterface`
  and call `DPayClient` directly, or add the field to `OpenSessionRequest`
  first. See [docs/extending.md](docs/extending.md).

The same `PaymentField` schema drives three consumers: `GatewayManager::describe()`
(frontend JSON with regex/digits/en+ar labels), `PaymentFieldRules` (Laravel
validation rules and localized attribute names), and the `sendOtp()` body mapping
above. Change the schema and all three follow.

`MoamalatProvider` is the deliberate exception: it implements the interface
directly rather than extending `AbstractDPayProvider`, because it is a
payment-link/status-poll flow, not OTP. `sendOtp()` opens a bare session (the
`payment_link` for Moamalat's LightBox UI comes back on `OpenSessionResponse`),
and `verifyOtp()` ignores the OTP argument entirely and polls `getSession()`.

**Laravel bridge** (`src/Laravel/`) is fully optional and auto-discovered via
`composer.json` `extra.laravel`. `DPayServiceProvider` builds the `GatewayManager`
from the `dpay.gateways` config array — provider class, `enabled`, `pay_method`,
and an optional `required_fields` override. The `DPay` facade fronts a single
`DPayFacadeAccessor` that forwards to *both* the client and the manager, so one
facade exposes the whole surface.

## Behavioral contracts to preserve

These are intentional and load-bearing — several exist to match the original
original-implementation behaviour that host apps still depend on:

- **`verifySession()` returns `null`, it does not throw**, for wrong OTP / expired
  / not-found. Provider `verifyOtp()` therefore returns `false` for ordinary user
  errors without callers needing try/catch. Do not "improve" this into an exception.
- **Amounts allow decimals; only a configurable floor is enforced.**
  `DPayClient` checks `$request->amount < $config->minAmount` before opening
  a session (default `minAmount` is `0.01`, matching the spec's documented
  minimum) and throws `DPayValidationException` if it's too low. There is
  no whole-number check — that was an SDK-imposed invariant inherited from
  the original implementation and it contradicted the spec; Plan 1 removed it. Don't
  reintroduce it.
  **The `0.01` default is deliberately permissive and should stay that way.**
  DPay enforces its own per-gateway min/max deposit server-side, and those
  are merchant-configurable from DPay's dashboard — so any static SDK floor
  above `0.01` would reject amounts some merchant has legitimately enabled.
  Let DPay be the authority; the SDK floor exists only to catch nonsense.
- **DPay does not settle the amount you send.** Verified live 2026-08-16:
  the settled figure is `round(amount + fee)` to the nearest whole LYD,
  half up — applied at payment time, not at open. `10.49` (fee `0.02`,
  total `10.51`) settles at `11`; `10.01` (total `10.03`) settles at `10`,
  i.e. *below* the request. `OpenSessionResponse` exposes `amount`, `fee`,
  `feeAmount` and `total`, and **none of them equals the settled value** —
  read that from `getSession()` or the `payment.paid` webhook, where the
  original survives as `data.original_amount`. This is DPay behaviour, not
  something to "fix" in the SDK, but don't write docs or tests that assume
  an amount round-trips end to end. See
  [docs/sandbox-testing.md](docs/sandbox-testing.md) for the measured table.
- **`UnknownProviderException` extends `InvalidArgumentException`, not
  `DPayException`.** A `catch (DPayException)` around a
  `provider($code)->sendOtp(...)` chain will *not* catch an unknown or disabled
  code — catch `DPayExceptionInterface` instead, which both branches
  implement. The Laravel controller example in
  [docs/checkout-flow.md](docs/checkout-flow.md) now does this correctly.
- **Mock mode short-circuits before validation.** In `openSession()`, the
  `config->mock` branch returns before the `min_amount` check — so mock
  mode accepts amounts below the configured floor too. Intentional today;
  know it before writing tests that assume otherwise.
- **The provider reference is a `string`**, even though DPay's `session_id` is an
  int. Keeps wallet-style providers returning UUIDs/hashes on the same contract.
- **Unknown session statuses degrade to `SessionStatus::UNKNOWN`** rather than
  throwing, so a new gateway state doesn't crash callers.
- HTTP status → exception mapping lives in one place, `Transport::buildException()`
  (`src/Http/Transport.php`, extracted from `DPayClient` in Plan 1): 401/403 →
  `DPayAuthException`, 404 → `DPaySessionNotFoundException`, 429 →
  `DPayRateLimitException`, other 4xx → `DPayValidationException`, else
  `DPayException`. All extend `DPayException`.

## Static analysis boundary

PHPStan runs at **level 8** over `src/`, with `src/Laravel` excluded (see
`phpstan.neon`) — the bridge needs container resolution and facade statics PHPStan
can't follow without larastan. `phpunit.xml` excludes `src/Laravel` from `<source>`
for the same reason. **The bridge is covered by runtime feature tests instead**
(`tests/Feature/LaravelBridgeTest.php`, `ConfigOverrideTest.php`,
`PaymentFieldRulesTest.php`) — if you change `src/Laravel`, a feature test is the
only thing that will catch you.

Unit tests use `tests/Unit/Support/FakeHttpClient.php`, a PSR-18 double with a FIFO
response queue, a recorded request log, and a `throwOnNext` hook for exercising
`DPayNetworkException`.

## Keeping docs in sync

Provider or test changes ripple into more files than usual here. When you
add/remove a provider or change test counts, check: `README.md` (provider
table, project layout, test counts), `composer.json` (`description` +
`keywords`), `CHANGELOG.md`, `src/Laravel/config/dpay.php`, and every file
under `docs/` — a 2026-07-28 audit found four `docs/*.md` files
(dto-reference, troubleshooting, sandbox-testing, plus a partial miss in
configuration) that had gone untouched since before Plan 1 despite `src/`
changing in 77 files. Don't assume a doc is current just because it reads
confidently.

**Sadad** is shipped (`SadadProvider`, `src/Providers/SadadProvider.php`),
disabled by default via `PAYMENT_GATEWAY_SADAD_ENABLED=false` — it's
merchant-gated on DPay's side (their sandbox returns
`"Unsupported payment method: sadad"` until DPay enables it for this
merchant), not missing SDK capability. It needed zero `AbstractDPayProvider`
changes; see the Architecture section above. **Yaser** does not appear in
the official spec at all and was correctly never added — if you see a
stray "Yaser" reference anywhere outside `CHANGELOG.md`'s `[0.1.0]`
historical section, it's a leftover that should be deleted.

## Logo path mismatch

`PaymentProviderInterface::logo()` implementations return paths like
`images/payment-methods/edfali.svg`, but `DPayServiceProvider` publishes the
bundled SVGs to `public/vendor/dpay/` (tag `dpay-logos`). The two paths do not
line up, so a published logo won't resolve at the returned URL without host-side
mapping — [docs/checkout-flow.md](docs/checkout-flow.md) quietly works around it
by building `asset('vendor/dpay/'.$code.'.svg')` by hand instead of calling
`logo()`.

Note that `GET /api/pay-methods` returns a `logo_url` per gateway, documented as
a "stable public field" — which would remove the need to bundle and publish SVGs
at all. Confirm intent before "fixing" either side.
