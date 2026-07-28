# Changelog

All notable changes to `aliagela-dev/dpay-php` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  identical behaviour to the original health-portal mock.
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
  named constructors match the health-portal seeder defaults.
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
  health-portal's `PaymentMethodController` shape.

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

[unreleased]: https://github.com/AliAgela-dev/dpay-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/AliAgela-dev/dpay-php/releases/tag/v0.1.0
