# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`aliagela-dev/dpay-php` — a framework-agnostic PHP 8.2+ SDK for the DPay payment
gateway (Libya), with an optional Laravel bridge. Private package, distributed via
Composer VCS repository, PSR-4 under `DPay\` → `src/`.

The SDK was reverse-engineered from a production Laravel app (referred to
throughout the source as "health-portal") and then probed against DPay's sandbox —
see [SANDBOX-VALIDATION.md](SANDBOX-VALIDATION.md) for captured request/response
shapes.

## Source of truth for the API

**An official DPay API spec exists: https://dpay.ly/docs/api**, with a Postman
collection at `https://dpay.ly/assets/dits-pgs-api.postman_collection.json`.
Read it before changing anything that touches the wire format.

This matters because the repo's own prose predates it. `README.md` still says the
field names and endpoint paths *"have not been validated against an official DPay
spec"*, and `SANDBOX-VALIDATION.md` speculates about features ("if DPay rolls out
webhooks") that are in fact documented and live. Treat the published spec as
authoritative, the sandbox probe log as corroborating evidence, and the repo's
narrative docs as possibly stale.

The core assumptions the SDK got **right**: base URL `https://dpay.ly/api`,
`Authorization: Bearer <token>`, the three endpoint paths, the `pay_method` /
`customer_mobile` / `card_number` request keys, and the `pending`/`paid` status
strings.

## Where the SDK diverges from the official spec

These are live gaps, not style preferences. Verify against the spec before
"fixing" surrounding code — several look intentional but contradict DPay.

| Area | Official spec | This SDK |
|---|---|---|
| Amount | Decimals allowed (`10.50`), minimum `0.01` | Rejects any fractional amount; `min_amount` defaults to **5** |
| Amount on the wire | `number` | `OpenSessionRequest::toBody()` casts `(int) $amount` — **truncates** |
| `description` | Top-level request field (MobiCash) | Nested as `data.description` |
| `data` | Free-form merchant metadata, echoed in webhooks | Only ever used to carry `description` |
| Card numbers | 7 digits same-bank **or 9 digits cross-bank (OnePay)** | `PaymentField::cardNumber(digits: 7)` → a `digits:7` rule that rejects 9-digit cards |
| Sadad | Supported; needs `customer_mobile` + `birth_year` (4 digits) + optional `category` (0–36) | Not shipped; `AbstractDPayProvider` cannot express those fields |
| Webhooks | HMAC-signed, 5 endpoints, `payment.paid/failed/expired/refunded/voided` | `supportsWebhook: false` everywhere; no verification helper |
| `Idempotency-Key` | Supported header on `sessions/open`; replays return the original session | Never sent |
| Per-gateway limits | `GET /api/pay-methods` returns live `fee`, `min_deposit`, `max_deposit` per gateway | Single global `min_amount`; no max check; endpoint unimplemented |

The amount handling is the one to be careful with. The whole-number rule is an
**SDK-imposed invariant inherited from health-portal, not a DPay requirement** —
and the `(int)` cast in `toBody()` would silently truncate `10.50` → `10` if the
pre-flight guard were ever relaxed. If you loosen the guard, fix the cast in the
same change.

Also unimplemented (spec'd, no SDK surface): `GET /api/auth/me`,
`POST /api/auth/logout`, `GET /api/payments`, `POST /api/payments/filter`,
`GET /api/pay-methods`, and all of `/api/invoices`. `VerifySessionResponse` maps
only the flat legacy fields — the spec's nested `payment` object, `receipt_url`,
`system_reference`, `network_reference`, `paid_through`, and `payer_account` are
reachable only via `->raw`.

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
`AbstractDPayProvider::sendOtp()` reads that schema to decide what to forward to
`openSession()`. It recognizes exactly two field keys:

- `phone_number` → `customerMobile`
- `card_number` → `cardNumber`

There is deliberately no per-provider special-casing. Consequences:

- Adding a DPay-backed gateway that uses phone or card = one subclass declaring
  `code()`/`displayName()`/`logo()`/`defaultFields()`, plus a `config/dpay.php`
  entry. Nothing else.
- A gateway needing a *different* body field must not extend
  `AbstractDPayProvider` — implement `PaymentProviderInterface` and call
  `DPayClient` directly. See [docs/extending.md](docs/extending.md).

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
health-portal behavior that host apps still depend on:

- **`verifySession()` returns `null`, it does not throw**, for wrong OTP / expired
  / not-found. Provider `verifyOtp()` therefore returns `false` for ordinary user
  errors without callers needing try/catch. Do not "improve" this into an exception.
- **Amounts are forced to whole numbers.** Fractional values raise
  `DPayValidationException`; `min_amount` (default 5) is enforced the same way.
  Both are *SDK* rules that contradict the published spec (see the divergence
  table above) — they're load-bearing for existing health-portal callers, so
  don't relax them casually, but don't cite them as DPay requirements either.
- **`UnknownProviderException` extends `InvalidArgumentException`, not
  `DPayException`.** A `catch (DPayException)` around a
  `provider($code)->sendOtp(...)` chain will *not* catch an unknown or disabled
  code. The Laravel controller example in
  [docs/checkout-flow.md](docs/checkout-flow.md) has exactly this hole.
- **Mock mode short-circuits before validation.** In `openSession()`, the
  `config->mock` branch returns before the whole-number and `min_amount` checks —
  so mock mode accepts fractional and below-minimum amounts. Intentional today;
  know it before writing tests that assume otherwise.
- **The provider reference is a `string`**, even though DPay's `session_id` is an
  int. Keeps wallet-style providers returning UUIDs/hashes on the same contract.
- **Unknown session statuses degrade to `SessionStatus::UNKNOWN`** rather than
  throwing, so a new gateway state doesn't crash callers.
- HTTP status → exception mapping lives in one place, `DPayClient::buildException()`:
  401/403 → `DPayAuthException`, 404 → `DPaySessionNotFoundException`, 429 →
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

Provider or test changes ripple into more files than usual here, and several are
currently drifting. When you add/remove a provider or change test counts, check:
`README.md` (provider table, project layout, test counts), `composer.json`
(`description` + `keywords` — these still list Sadad and Yaser, which are **not**
shipped), `CHANGELOG.md`, `src/Laravel/config/dpay.php`, and the relevant file
under `docs/`.

Sadad and Yaser were dropped after the sandbox returned
`500 "Unsupported payment method"` for both — but read that conclusion carefully:
**`sadad` is a documented, supported `pay_method`** in the official spec, with its
own section. The probe most likely failed because it wasn't enabled on the
merchant account *and* because the SDK never sent Sadad's required `birth_year`.
Adding it back needs more than the `extending.md` recipe, since
`AbstractDPayProvider` can only map `phone_number` and `card_number`. **Yaser**
does not appear in the official spec at all; dropping it looks correct.

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
