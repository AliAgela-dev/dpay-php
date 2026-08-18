# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`aliagela-dev/dpay-php` — a framework-agnostic PHP 8.2+ SDK for the DPay payment
gateway (Libya), with an optional Laravel bridge. MIT-licensed open source,
published on Packagist as `aliagela-dev/dpay-php`, PSR-4 under
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

## Current state (2026-08-17)

- **Released `v0.3.0`**, MIT, published on Packagist. Tags: `v0.1.0`,
  `v0.2.0`, `v0.3.0`.
- **269 tests / 531 assertions**, PHPStan level 8 clean. CI runs
  `composer check` on PHP 8.2, 8.3 and 8.4.
- **`main` is branch-protected**: PRs required, the three PHP checks must
  pass, no force pushes, no deletion. Admins are exempt, so you *can* push
  directly — don't, unless CI itself is what's broken.
- Only `main` exists. Merged branches are deleted; squash-merging leaves the
  original branch unrecognised as merged, and a lingering branch generates
  duplicate PRs from GitHub's "Compare & pull request" banner.

## Spec alignment status

Everything below is closed except per-gateway limits. Last cross-checked
against `src/` on 2026-08-17 — verify against the spec yourself before
assuming a row is still accurate.

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
| Per-gateway limits | `GET /api/pay-methods` returns live `fee`, `min_deposit`, `max_deposit` per gateway | ✅ Resolved in v0.4.0. `DPay\Dto\PayMethod` + `DPay\Client\PayMethodsClient` read the live list, memoised per instance. `DPayClient` takes an **optional** `PayMethodsClient`; when supplied it refuses out-of-range amounts and inactive gateways before opening a session, and **fails open** if the lookup itself fails. Off by default (`DPAY_VALIDATE_LIVE_LIMITS`, `validateAgainstLiveLimits:`) — these limits are merchant-configurable, so DPay remains the authority and `min_amount` stays a permissive local floor at `0.01`. |

`PayMethodsClient` is built (v0.4.0). The remaining unbuilt endpoints —
`AuthClient`, `PaymentsClient`, `InvoicesClient` and their DTOs — stay
deferred by explicit decision: 1.0 means *stable*, not *complete*, and
adding them later is additive. Two of the three cannot even be verified,
because `/auth/me`, `/payments` and `/invoices` are *not* routed on sandbox (a
branded HTML 404, genuinely unrouted), so those three can only be verified
against Postman golden bodies. `/payments/filter` is a partial exception:
`POST` hits a real route (`405`, naming it) while `GET` on the identical
URL 404s — a routing-order bug on DPay's side, not ours to work around.

`VerifySessionResponse` now maps the spec's nested `payment` object (as
`DPay\Dto\Payment`) and `receipt_url`, so `system_reference`,
`network_reference`, `paid_through` and `payer_account` are typed rather
than `->raw`-only. `GetSessionResponse` likewise gained `txId` and
`paymentLink`, both of which appear in every live response but in neither
of the spec's minimal examples — a reminder that the spec examples are
narrower than what the gateway actually sends, and `->raw` is worth
diffing against the DTOs whenever you touch a response shape.

## Live verification status (full run 2026-08-16/17)

The SDK has been exercised end-to-end against `https://dpay.ly/api/sandbox`,
not just against a fake HTTP client. `SANDBOX-VALIDATION.md` is regenerated
by the probe; `docs/sandbox-testing.md` holds the measured behaviour table.

**Verified live:** session open/verify/getSession across 6 gateways ·
wrong OTP returns `null` and the session survives a retry · decimal amounts
· top-level `description` · 9-digit cross-bank cards on all three bank
gateways · Moamalat driven to `paid` · every error mapping (401, 404, 422,
429) · `min_deposit`/`max_deposit` enforcement · four webhook events
(`payment.paid`, `payment.failed`, `payment.expired`, `webhook.test`)
against real DPay-signed deliveries · statuses `pending`/`paid`/`failed`/
`expired`.

**Not verified, and why:**

| Gap | Reason |
|---|---|
| `payment.refunded` / `payment.voided`, and `SessionStatus::VOIDED` | **Not triggerable by anyone.** Enumerating the official Postman collection shows no refund or void endpoint exists — both events are inbound-only and Moamalat-only. Don't go looking for a way to fire one. |
| Sadad | Merchant-gated at DPay; needs them to enable it *and* publish a test wallet. |
| `DPayNetworkException` | Needs a real transport failure. |
| `VerifySessionResponse`'s reference fields | `system_reference`, `network_reference`, `paid_through`, `payer_account` were `null` in every sandbox delivery. The spec shows them populated only on Moamalat/production, so nulls on wallet and bank gateways look correct rather than broken. |
| The Laravel bridge over the wire | `DPayWebhookController` has never received a real webhook — the live testing used the standalone receiver deliberately, to exercise the framework-agnostic core with nothing in between. |
| Production | Everything was sandbox; every payload carried `live: false`. Moamalat especially differs: sandbox serves a two-button simulator, not a card LightBox, so the spec's Moamalat test cards are unusable there. |

Measured DPay quirks worth knowing before you write a test that assumes
otherwise: the rate limiter tolerated **35 consecutive unpaced calls** (docs
long claimed 4–5); sessions expire in **10 minutes** for Moamalat/Sadad and
15 otherwise (not the ~30 some docs claimed); and `payment.expired` arrives
**~5 minutes after** `expired_at`, while `getSession()` reports `expired`
immediately.

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
implementation's behaviour that host apps still depend on:

- **`verifySession()` returns `null`, it does not throw**, for wrong OTP / expired
  / not-found. Provider `verifyOtp()` therefore returns `false` for ordinary user
  errors without callers needing try/catch. Do not "improve" this into an exception.
- **Amounts allow decimals; only a configurable floor is enforced.**
  `DPayClient` checks `$request->amount < $config->minAmount` before opening
  a session (default `minAmount` is `0.01`, matching the spec's documented
  minimum) and throws `DPayValidationException` if it's too low. There is
  no whole-number check — that was an SDK-imposed invariant inherited from
  the original implementation and it contradicted the spec; the v0.2.0 work
  removed it. Don't reintroduce it.
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
  (`src/Http/Transport.php`, extracted from `DPayClient` during v0.2.0): 401/403 →
  `DPayAuthException`, 404 → `DPaySessionNotFoundException`, 429 →
  `DPayRateLimitException`, other 4xx → `DPayValidationException`, else
  `DPayException`. All extend `DPayException`.

## Static analysis boundary

PHPStan runs at **level 8** over `src/`, with `src/Laravel` excluded (see
`phpstan.neon`) — the bridge needs container resolution and facade statics PHPStan
can't follow without larastan. `phpunit.xml` excludes `src/Laravel` from `<source>`
for the same reason. **The bridge is covered by runtime feature tests instead**
(`tests/Feature/`: `LaravelBridgeTest`, `ConfigOverrideTest`,
`PaymentFieldRulesTest`, `DPayWebhookControllerTest`,
`DPayWebhookRouteDisabledTest`) — if you change `src/Laravel`, a feature test
is the only thing that will catch you.

Unit tests use `tests/Unit/Support/FakeHttpClient.php`, a PSR-18 double with a FIFO
response queue, a recorded request log, and a `throwOnNext` hook for exercising
`DPayNetworkException`.

**A test that passes the moment you write it deserves suspicion.** Several
tests here were mutation-checked — the implementation was deliberately broken
to confirm the test fails — and the commit messages record which mutation
kills which test. Two worth knowing, because both guard behaviour that is
easy to "simplify" away:

- `DPayWebhookControllerTest::test_the_signature_is_verified_against_the_raw_bytes_not_a_reserialized_body`
  — its fixture is built so a `json_decode`/`json_encode` round-trip *must*
  change the bytes, and it asserts that premise up front so it cannot
  silently stop proving anything.
- `TransportTest`'s `errors`-property tests — removing the `is_array()`
  guard in `buildException()` reproduces a real `TypeError`.

## Live sandbox tooling (`tests/sandbox/`)

Not PHPUnit, not in CI, needs real credentials. Copy `.env.example` to
`.env` (gitignored) and `set -a; source .env; set +a`.

- `probe.php` — paced, resumable, regenerates `SANDBOX-VALIDATION.md`.
  Covers 10 provider scenarios plus three `error-*` scenarios that assert
  which exception a real DPay failure maps to.
- `webhook-receiver.php` — standalone receiver for verifying real
  deliveries. Exercises `WebhookVerifier` + `WebhookEventFactory` directly,
  with no framework in between. Expose it over HTTPS (a tunnel works) and
  register the URL in DPay's dashboard. It answers **400** on a bad
  signature where `DPayWebhookController` answers **401** — deliberate, and
  documented in its docblock. Don't harmonise them.

`ProbeRunner::writeReport()` **overwrites `SANDBOX-VALIDATION.md` entirely**,
so any prose that belongs in that file lives in the generator's `PREAMBLE`
constant. Hand-written text added to the `.md` directly is destroyed on the
next run — this already happened once.

## Keeping docs in sync

Provider or test changes ripple into more files than usual here. When you
add/remove a provider or change test counts, check: `README.md` (provider
table, project layout, test counts), `composer.json` (`description` +
`keywords`), `CHANGELOG.md`, `src/Laravel/config/dpay.php`, and every file
under `docs/`.

**Docs drift here has twice reached the point of being actively wrong, so
verify rather than assume:**

- A 2026-07-28 audit found four `docs/*.md` files (dto-reference,
  troubleshooting, sandbox-testing, plus a partial miss in configuration)
  untouched since before the v0.2.0 work despite `src/` changing in 77
  files.
- Worse, on 2026-08-17 `docs/providers.md` was still telling readers that
  SaharaPay/YousrPay/MasrefyPay use `cardNumber(digits: 7)` — months after
  they moved to `bankCardNumber()` (`digitsOneOf: [7, 9]`). The published
  docs were instructing integrators to reject valid 9-digit cross-bank
  OnePay cards, i.e. re-creating in documentation the exact defect v0.2.0
  fixed in code. **Check claims against `src/`, not against how confident
  the prose sounds.**

Cheap way to catch the common class of this:

```bash
composer test 2>&1 | grep -E "^OK \("        # then grep the docs for stale counts
git ls-files | xargs grep -n "digits: 7"     # schema claims vs src/Providers/
```

**Beware `grep` in this environment.** On the maintainer's machine `grep`
resolves to a shell function wrapping `ugrep`, which has silently returned
*zero matches* for files that plainly contained the string — long enough to
produce a confident, wrong "all clean" report. When a grep result is the
evidence for a claim, confirm with `/usr/bin/grep` or a second method.

**Sadad** is shipped (`SadadProvider`, `src/Providers/SadadProvider.php`),
disabled by default via `PAYMENT_GATEWAY_SADAD_ENABLED=false` — it's
merchant-gated on DPay's side (their sandbox returns
`"Unsupported payment method: sadad"` until DPay enables it for this
merchant), not missing SDK capability. It needed zero `AbstractDPayProvider`
changes; see the Architecture section above. **Yaser** does not appear in
the official spec at all and was correctly never added — if you see a
stray "Yaser" reference anywhere outside `CHANGELOG.md`'s `[0.1.0]`
historical section, it's a leftover that should be deleted.

## Logos

`PaymentProviderInterface::logo()` returns `vendor/dpay/<code>.svg`, which is
exactly where `DPayServiceProvider` publishes the bundled SVGs (tag
`dpay-logos`). `asset($provider->logo())` therefore resolves with no
host-side mapping.

This was wrong until v0.4.0: providers returned `images/payment-methods/…`
while the bridge published to `vendor/dpay/`, so the two never lined up and
`docs/checkout-flow.md` rebuilt the path by hand rather than calling the
method. Fixing it also surfaced that `SadadProvider` had been advertising a
`sadad.svg` that was never bundled — the asset now exists.

The bundled SVGs are deliberately **generic placeholders** (a coloured rect
with the gateway name), not official brand assets. If you want real
branding, read `PayMethod::$logoUrl` from `PayMethodsClient` — an absolute
URL DPay maintains. `logo()` is the offline/bundled option; `logoUrl` is the
upstream-authoritative one. Both are legitimate; they answer different
questions.

There is a test (`tests/Unit/LogoPathTest.php`) asserting both that every
provider's `logo()` matches the publish location and that the named file
actually exists under `resources/logos/`.
