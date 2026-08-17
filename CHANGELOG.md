# Changelog

All notable changes to `aliagela-dev/dpay-php` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **`docs/providers.md` documented the wrong card schema for the three bank
  gateways.** It claimed SaharaPay, YousrPay and MasrefyPay use
  `cardNumber(digits: 7)`; they have used `bankCardNumber()`
  (`digitsOneOf: [7, 9]`) since v0.2.0. The page was missed by that
  release's docs pass, so it told integrators that 9-digit cross-bank
  OnePay numbers are invalid — the exact defect v0.2.0 fixed in code.
  MobiCash correctly stays 7-only.

### Changed
- Published to Packagist as `aliagela-dev/dpay-php`. Install is now a plain
  `composer require` — the VCS `repositories` block is no longer needed, and
  the README documents that Guzzle is a separate install only if you want
  the zero-config `DPayClientFactory` path.

## [0.3.0] — 2026-08-16

Relicensed under MIT, and the first release verified live against DPay's
real sandbox rather than only against a fake HTTP client. That verification
turned up gateway behaviour the SDK had never documented — most importantly
that **DPay does not settle the amount you send** — so much of this release
is corrections to what the docs claimed.

### Changed
- **Relicensed MIT.** `LICENSE` and `composer.json` previously declared the
  package proprietary ("all rights reserved") while the repository was
  public — a contradiction that made it unusable by anyone who read it. The
  copyright holder owns the work and has chosen to release it as open source.
- Removed every reference to the internal application the SDK was originally
  extracted from (24 mentions across 15 files, including source docblocks),
  replacing them with neutral wording. The history is still explained; the
  private system is no longer named.
- Deleted the `docs/superpowers/` planning archive (design spec and six
  implementation plans). It was internal working material, not user
  documentation, and nothing linked to it.
- README no longer describes the package as private, and points at the
  licence, contributing guide and security policy.

### Added
- **`SECURITY.md`** — a private vulnerability disclosure path via GitHub
  Security Advisories. For a payments library this is close to mandatory:
  previously the only way to report a signature-verification flaw was a
  public issue. Scopes in webhook verification, credential leakage and
  request forgery; scopes out DPay's own gateway behaviour.
- **`CONTRIBUTING.md`** — setup, the command set, why `composer.lock` is
  gitignored, testing conventions (including that the Laravel bridge is
  guarded only by feature tests), how to run the live sandbox probe, and the
  intentional behaviours not to "fix" without discussion.
- **A README section on the three DPay behaviours that surprise people** —
  settlement rounding, per-gateway deposit limits, and `data` key merging —
  promoted from `troubleshooting.md` to the front page, since each one
  changes what a merchant actually gets paid.
- `.claude/` is now gitignored, so local agent artifacts can't be committed
  by accident.
- Cross-cutting `error-*` scenarios in the live sandbox probe, asserting
  which exception a real DPay failure maps to: `error-401-bad-token`,
  `error-404-unknown-session`, `error-422-invalid-pay-method`. See
  [docs/sandbox-testing.md](docs/sandbox-testing.md).

### Fixed
- **`ProbeRunner::writeReport()` destroyed the explanatory prose in
  `SANDBOX-VALIDATION.md` on every run.** It rebuilt the file as a heading
  plus the results table, so anything written by hand above the table was
  lost the next time the probe ran. The preamble now lives in the generator
  and is re-emitted each time.

### Documentation
- **Documented DPay's settlement rounding.** Measured live: the settled
  amount is `round(amount + fee)` to the nearest whole LYD, half up, applied
  at payment time. `10.49` settles at `11`, `10.01` settles at `10` — so a
  payment can settle *below* the requested amount. None of
  `OpenSessionResponse`'s `amount`/`fee`/`feeAmount`/`total` equals the
  settled figure. Previously undocumented anywhere.
- **Documented DPay's per-gateway deposit limits.** Enforced server-side and
  merchant-configurable per pay method (Edfali on our sandbox merchant: min
  `5`, max `60000`). Records why `min_amount` must stay permissive at `0.01`
  rather than hardcoding a floor DPay may not share.
- **Documented that `data` is not exclusively yours on the way back** — DPay
  merges `fee_amount`, `fee_percent` and `original_amount` into it. Merchant
  keys survive, but those three names collide.
- Corrected the rate-limit claim: `GET /payment/sessions/{id}` tolerated **35
  consecutive unpaced calls** and 429'd on the 36th. Docs and `ProbeRunner`
  previously claimed 4–5 requests would trip it.
- Corrected session expiry: 10 minutes for Moamalat/Sadad, 15 otherwise.
  `dto-reference.md` and `troubleshooting.md` said ~30 minutes.
- Recorded that the Moamalat sandbox link is a **two-button simulator**, not
  a card-entry LightBox — the design spec's Moamalat test cards are unusable
  there, and production remains unverified.
- Recorded that `payment.expired` is delivered ~5 minutes after `expired_at`,
  while `getSession()` reports `expired` immediately.
- Recorded which webhook events have now been verified against real
  DPay-signed deliveries (`payment.paid`, `payment.failed`,
  `payment.expired`, `webhook.test`) and which have not
  (`payment.refunded`, `payment.voided` — not triggerable at all, so
  `SessionStatus::VOIDED` remains unseen in a real response).
- **Refunds and voids cannot be triggered by anyone.** Enumerated every
  endpoint in the official Postman collection: there is no refund or void
  route. Both events are inbound-only, Moamalat-only, and a void works only
  within a short window after authorisation. Documented in
  [docs/webhooks.md](docs/webhooks.md) so nobody else goes hunting for the
  button.
- Completed the `data` collision list — beyond `fee_amount`/`fee_percent`/
  `original_amount`, a refund adds `refund_amount` and `refund_reference`
  and a void adds `void_reference`.
- Softened the "reference fields are null" note: `system_reference`,
  `network_reference`, `paid_through` and `payer_account` appear populated
  only on Moamalat events in the official examples, so nulls on wallet and
  bank gateways look like correct behaviour rather than a mapping gap.

### Tests
- Three tests for the Laravel webhook controller, the least-covered code in
  the repo (`src/Laravel` is excluded from PHPStan, so feature tests are its
  only safety net):
  - **The signature is verified against the raw request bytes, not a
    re-serialized body.** This is the failure mode `troubleshooting.md` tells
    people to look for, and nothing pinned it. The fixture is built so a
    `json_decode`/`json_encode` round-trip must change the bytes (Arabic
    text, an escaped slash, a trailing zero on `10.50`, non-alphabetical key
    order), and asserts the premise so it cannot silently stop proving
    anything. Verified by mutation: making the controller re-serialize kills
    this test and no other.
  - A real captured delivery from 2026-08-16 parses end to end — pinning the
    rounded settled `amount`, DPay's fee keys merged into merchant `data`,
    and null reference fields on a wallet gateway.
  - Missing signature headers entirely are rejected with 401.
- Nine tests covering previously unexercised error paths in `Transport`:
  `403` → `DPayAuthException` (only `401` was tested, though both share the
  match arm), the `errors` property in all three of its states (populated,
  absent, non-array), `decode()` against an HTML error page / empty body /
  valid-but-scalar JSON, and `3xx` falling through to the generic arm.

## [0.2.0] — 2026-07-31

### Added
- `DPay\Providers\SadadProvider` — REST mobile wallet (Almadar Aljadid),
  needs `customer_mobile` + `birth_year` + optional `category`. Ships
  disabled by default; the gateway is merchant-gated on DPay's side.
- `DPay\Webhooks\WebhookVerifier` — HMAC-SHA256 signature verification with
  a 5-minute replay window.
- `DPay\Webhooks\WebhookEventFactory` — typed parsing for all 6 webhook
  events (`PaymentEvent` for the 5 payment.* events, `TestEvent` for
  webhook.test's distinct shape).
- Laravel bridge: opt-in webhook receiver route (`dpay.webhooks.enabled`,
  off by default), `DPayWebhookReceived` event, and a `dpay.webhooks.middleware`
  config key for applying rate limiting or other middleware to the route.

### Changed
- `AbstractDPayProvider::sendOtp()` maps every declared field by its wire
  name (`PaymentField::wireName()`), not a hardcoded `phone_number`/
  `card_number` check — this is what makes SadadProvider possible with no
  base-class changes.
- `min_amount` now a float defaulting to `0.01` (was `int`, default `5`).
- `OpenSessionRequest` no longer truncates fractional amounts; `description`
  moves to a top-level request field instead of `data.description`.
- Bank-card gateways (MasrefyPay/YousrPay/SaharaPay) accept 7-digit
  same-bank **or** 9-digit cross-bank (OnePay) cards. MobiCash stays 7-only.
- `openSession()` takes an `OpenSessionRequest` DTO and an optional
  `Idempotency-Key` instead of positional scalar arguments. **Breaking.**
- `DPayClient`'s constructor now takes a `DPay\Http\Transport` instead of
  PSR-18/17 clients and a logger directly — those moved onto `Transport`,
  which owns HTTP plumbing so `DPayClient` only owns endpoint semantics.
  **Breaking** for anyone constructing `DPayClient` directly (not via
  `DPayClientFactory`, which absorbs the change).
- `supportsWebhook()` now returns `true` for every provider (was `false`
  everywhere) — webhooks are configured account-wide on DPay's side, not
  per-gateway. `MoamalatProvider::supportsRefund()` now returns `true`
  (refunds/voids are dashboard-triggered and observed via webhooks, not
  SDK-invokable, but DPay does support them for Moamalat). Both flip the
  JSON shape of `GatewayManager::describe()`.

### Fixed
- `SessionStatus` gains `VOIDED`.
- `UnknownProviderException` now also implements `DPayExceptionInterface`,
  so a single `catch` can cover both SDK exception trees.
- **`composer install` on a fresh clone was unresolvable.** `composer.lock`
  is gitignored (library convention), so CI and new contributors resolve
  from scratch — and every Laravel 10.x/11.x release now carries a security
  advisory, which Composer blocks by default. `orchestra/testbench` was
  capped at `^9.0`, which cannot reach Laravel 12, so there was no
  installable set. Widened the dev constraints to `testbench ^10.0` /
  `illuminate/contracts ^12.0`. Dev-only — no runtime dependency changed.

## [0.1.0] — 2026-05-22

First release. Validated end-to-end against the real DPay sandbox at
`https://dpay.ly/api/sandbox` — see [SANDBOX-VALIDATION.md](SANDBOX-VALIDATION.md)
for the full report.

### Added

#### Core
- `DPay\Client\DPayClient` — PSR-18/17 HTTP client for DPay's three endpoints
  (`openSession`, `verifySession`, `getSession`), with whole-number-amount
  enforcement and configurable `min_amount`.
- `DPay\Client\DPayClientFactory` — Guzzle-backed convenience factory.
- `DPay\Config\DPayConfig` — immutable value object, `fromArray()` factory.
- `DPay\Support\MockTransport` — synthetic responses for dev/testing,
  identical behaviour to the original implementation's mock.
- Typed DTOs: `OpenSessionRequest`, `OpenSessionResponse`,
  `VerifySessionResponse`, `GetSessionResponse`, `SessionStatus` enum,
  `PaymentField` value object.
- Exception hierarchy under `DPay\Exceptions`:
  - `DPayException` (base)
  - `DPayValidationException` (4xx pre-flight + 422)
  - `DPayAuthException` (401 / 403)
  - `DPaySessionNotFoundException` (404)
  - `DPayRateLimitException` (429)
  - `DPayNetworkException` (PSR-18 transport failure)
  - `UnknownProviderException` (gateway not registered or disabled)

#### Providers (6, all sandbox-validated)
- `EdfaliProvider` — phone OTP (regex `/^09\d{8}$/`)
- `MobiCashProvider` — card OTP (7 digits)
- `MasrefyPayProvider`, `YousrPayProvider`, `SaharaPayProvider` — card OTP,
  with `supportsStatusCheck: true`
- `MoamalatProvider` — payment-link / status-poll flow (returns
  `payment_link` for the LightBox UI)
- `AbstractDPayProvider` — shared base that derives the DPay request body
  from each provider's `requiredFields()` schema, so a new gateway is one
  class + one config entry.

#### Field schema + Laravel rules
- `PaymentField` describes a `sendOtp` input: key, regex/digits, en+ar
  labels, en+ar placeholders, `input_type` for the frontend.
- `PaymentField::phoneNumber()` and `PaymentField::cardNumber(digits: 7)`
  named constructors match the original seeder defaults.
- Override via constructor (pure PHP) or `dpay.gateways.*.required_fields`
  config key (Laravel).
- `DPay\Laravel\PaymentFieldRules::for($provider, $prefix)` converts the
  schema into Laravel validation rules; `attributesFor($provider, $locale)`
  builds localized attribute names.

#### GatewayManager
- In-memory registry — no service container required.
- `register / provider / isEnabled / requiresOtp / features / listEnabled /
  all` mirror the previous Laravel `PaymentGatewayManager`.
- `describe()` returns the frontend-ready JSON listing
  (`code, name, logo, requires_otp, supports_status_check, supports_refund,
  supports_webhook, required_fields`) — drop-in replacement for the
  the original app's `PaymentMethodController` shape.

#### Laravel bridge (optional, auto-discovered)
- `DPay\Laravel\DPayServiceProvider` binds `DPayConfig`, `DPayClientInterface`,
  and `GatewayManager` from `config/dpay.php`.
- `DPay` facade exposing client + manager methods on one accessor.
- Publishable `config/dpay.php` (same env-var shape as the original
  `payment.php` config).
- Publishable logo assets at `vendor/dpay/`.

#### Tests
- 54 PHPUnit tests, 183 assertions, all passing.
- Unit tests against a fake PSR-18 client cover every status code,
  per-provider body shapes, and the field-driven `sendOtp` mapping.
- Feature tests via Orchestra Testbench cover the Laravel bridge,
  config-driven field override, and `PaymentFieldRules` against a real
  `Illuminate\Validation\Factory`.

#### Sandbox probe
- `tests/sandbox/probe.php` exercises every provider + every exception
  path against the real DPay sandbox.
- Reads `DPAY_API_KEY` from environment — no token committed.
- `SANDBOX-VALIDATION.md` documents the captured response shapes.

#### Static analysis
- PHPStan level 8 on `src/` (Laravel bridge excluded — runtime-validated
  by feature tests).
- `composer analyse`, `composer test`, `composer check` scripts.

#### CI
- `.github/workflows/ci.yml` runs PHPStan + PHPUnit on PHP 8.2, 8.3, 8.4.

#### Docs (in `docs/`)
- `checkout-flow.md` — end-to-end walkthrough (pure PHP + Laravel).
- `providers.md` — per-provider reference cards.
- `extending.md` — how to add your own provider (e.g. wallet) or a new
  DPay `pay_method` (e.g. Sadad/Yaser when DPay enables them).
- `troubleshooting.md` — error catalog + common gotchas.
- `dto-reference.md` — every public field of every DTO.
- `configuration.md` — env-var + config-key reference.
- `sandbox-testing.md` — how to run the probe + what was validated.

### Known limitations

- **Sadad / Yaser not shipped.** DPay's sandbox returns
  `500 "Unsupported payment method"` for both. Re-add via
  [docs/extending.md](docs/extending.md) when DPay enables them.
- **No refund / webhook support.** Every provider reports
  `supportsRefund: false` and `supportsWebhook: false` because DPay
  doesn't expose those endpoints yet.
- **Sandbox `tx_id` is `sb_txn_<hex>`.** Production format may differ;
  the SDK doesn't parse it, so no impact expected.

[unreleased]: https://github.com/AliAgela-dev/dpay-php/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/AliAgela-dev/dpay-php/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/AliAgela-dev/dpay-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/AliAgela-dev/dpay-php/releases/tag/v0.1.0
