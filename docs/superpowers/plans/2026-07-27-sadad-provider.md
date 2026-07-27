# Sadad Provider Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `SadadProvider`, the DPay-backed mobile-wallet gateway that needs `birth_year` and `category` — fields the v0.1.0 provider abstraction couldn't express and which Plan 1 built (`PaymentField::birthYear()`/`sadadCategory()`, generic `wireName()` mapping) specifically to unblock.

**Architecture:** `SadadProvider` is an ordinary `AbstractDPayProvider` subclass — no base-class changes. It declares three fields (`phoneNumber()`, `birthYear()`, `sadadCategory()`); the existing generic field-mapping in `sendOtp()` routes them to `customer_mobile`/`birth_year`/`category` on the wire automatically. Registered in the Laravel bridge's `config/dpay.php` gateway array, **disabled by default** — the gateway is merchant-gated, confirmed live in Plan 1 Task 14 (`"Unsupported payment method: sadad"` even with correct field mapping).

**Tech Stack:** PHP 8.2+, PHPUnit 11, PHPStan level 8, Orchestra Testbench (Laravel bridge only).

**Plan 2 of 5.** Depends on Plan 1 (merged: `PaymentField::birthYear()`/`sadadCategory()`/`digitsOneOf`, `AbstractDPayProvider`'s generic `wireName()`-driven `sendOtp()`, `OpenSessionRequest`'s `birthYear`/`category` properties — all already committed on `feat/dpay-spec-alignment-v0.2.0`). Closes spec defect #7.

**Spec:** [`docs/superpowers/specs/2026-07-26-dpay-spec-alignment-design.md`](../specs/2026-07-26-dpay-spec-alignment-design.md), §4 and §6.

---

## Why this is small

Everything Sadad needs already exists from Plan 1:

- `PaymentField::birthYear()` — 4-digit field, `sendAs` defaults to `key` (`'birth_year'`)
- `PaymentField::sadadCategory()` — optional integer field, `sendAs` defaults to `key` (`'category'`)
- `AbstractDPayProvider::sendOtp()` already maps `birth_year`/`category` wire names into `OpenSessionRequest::$birthYear`/`$category` (verified by reading the current file — this is not a plan assumption, it's already committed code)
- `MockTransport::expiryFor()` already includes `'sadad'` in its 10-minute bucket

So this plan is: one new provider class, one config entry, and closing test gaps — not new plumbing.

---

## File Structure

**Create:**

| File | Responsibility |
|---|---|
| `src/Providers/SadadProvider.php` | Identity + field schema. No behavioral overrides needed. |
| `tests/Unit/SadadProviderTest.php` | Identity, default fields, and the wire-mapping proof (golden body through `sendOtp()`) |

**Modify:**

| File | Change |
|---|---|
| `src/Laravel/config/dpay.php` | Add `sadad` gateway entry, `enabled` defaulting to `false` |
| `tests/Feature/LaravelBridgeTest.php` | Prove Sadad is registered but disabled by default |
| `tests/Unit/MockTransportTest.php` | Add the sadad-specific expiry test a Plan 1 reviewer flagged as a gap |
| `composer.json` | Drop `yaser` from `keywords` (Sadad already listed — now accurate instead of stale) |
| `docs/providers.md` | Add a Sadad card; correct the "not in this build" note |
| `docs/configuration.md` | Add Sadad's env vars to the per-gateway tables |
| `docs/checkout-flow.md` | Add Sadad to the "Important fields per provider" table |
| `docs/extending.md` | The Scenario 2 footnote currently says re-adding Sadad "needs more than the recipe" — update now that it's shipped |
| `CHANGELOG.md` | New `[Unreleased]` entry — do not rewrite the historical 0.1.0 entry |
| `tests/sandbox/probe.php` | No code change — Task 6 runs the existing `sadad` scenario, already present from Plan 1 |

---

## Task 1: `SadadProvider` class

**Files:**
- Create: `src/Providers/SadadProvider.php`
- Test: `tests/Unit/SadadProviderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SadadProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Providers\SadadProvider;
use PHPUnit\Framework\TestCase;

final class SadadProviderTest extends TestCase
{
    public function test_identity(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        self::assertSame('sadad', $provider->code());
        self::assertSame('Sadad', $provider->displayName());
        self::assertSame('images/payment-methods/sadad.svg', $provider->logo());
    }

    public function test_default_fields_are_phone_birth_year_and_category(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        $keys = array_map(static fn ($f) => $f->key, $provider->requiredFields());

        self::assertSame(['phone_number', 'birth_year', 'category'], $keys);
    }

    public function test_category_is_the_only_optional_field(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        $required = array_map(static fn ($f) => $f->required, $provider->requiredFields());

        self::assertSame([true, true, false], $required);
    }

    public function test_requires_otp(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        self::assertTrue($provider->requiresOtp());
    }

    public function test_inherits_universal_capability_flags(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        // Webhooks are account-level (Plan 1, AbstractDPayProvider::supportsWebhook).
        self::assertTrue($provider->supportsWebhook());
        // Refunds are Moamalat-only per the spec; Sadad gets no special case.
        self::assertFalse($provider->supportsRefund());
        // No override needed: Sadad doesn't poll getSession() for status the
        // way SaharaPay/YousrPay/MasrefyPay do, so this stays the
        // AbstractDPayProvider default of false, same as Edfali.
        self::assertFalse($provider->supportsStatusCheck());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/SadadProviderTest.php`
Expected: FAIL — `Class "DPay\Providers\SadadProvider" not found`

- [ ] **Step 3: Create the provider**

Create `src/Providers/SadadProvider.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Dto\PaymentField;

/**
 * Sadad — REST mobile wallet (Almadar Aljadid), OTP-based.
 *
 * The only DPay gateway needing birth_year + category alongside the phone
 * number. No sendOtp()/verifyOtp() override is needed: AbstractDPayProvider's
 * generic wireName()-driven mapping (see sendOtp()) already routes
 * birth_year and category onto OpenSessionRequest — that generic mapping
 * exists specifically so a gateway like this needs no base-class changes.
 *
 * Ships disabled by default (see config/dpay.php) — Sadad is merchant-gated;
 * DPay's sandbox returns "Unsupported payment method: sadad" until the
 * gateway is enabled on the merchant account, confirmed live.
 */
final class SadadProvider extends AbstractDPayProvider
{
    public function code(): string
    {
        return 'sadad';
    }

    public function displayName(): string
    {
        return 'Sadad';
    }

    public function logo(): string
    {
        return 'images/payment-methods/sadad.svg';
    }

    protected function defaultFields(): array
    {
        return [
            PaymentField::phoneNumber(),
            PaymentField::birthYear(),
            PaymentField::sadadCategory(),
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/SadadProviderTest.php`
Expected: OK (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Providers/SadadProvider.php tests/Unit/SadadProviderTest.php
git commit -m "feat: add SadadProvider — phone + birth_year + category, no base-class changes"
```

---

## Task 2: Prove the wire mapping end-to-end

Task 1 proves the *schema*. This task proves that schema actually produces the correct DPay request body when `sendOtp()` runs — the thing that was structurally impossible before Plan 1.

**Files:**
- Test: `tests/Unit/SadadProviderTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/SadadProviderTest.php`:

```php
    public function test_send_otp_produces_the_spec_golden_body(): void
    {
        $client = new class implements \DPay\Client\DPayClientInterface {
            public ?\DPay\Dto\OpenSessionRequest $seen = null;

            public function openSession(\DPay\Dto\OpenSessionRequest $request, ?string $idempotencyKey = null): \DPay\Dto\OpenSessionResponse
            {
                $this->seen = $request;

                return \DPay\Dto\OpenSessionResponse::fromArray(['session_id' => 1, 'status' => 'pending']);
            }

            public function verifySession(int $sessionId, string $otp): ?\DPay\Dto\VerifySessionResponse
            {
                return null;
            }

            public function getSession(int $sessionId): \DPay\Dto\GetSessionResponse
            {
                return \DPay\Dto\GetSessionResponse::fromArray(['session_id' => $sessionId, 'status' => 'paid']);
            }
        };

        $provider = new SadadProvider($client, 'sadad');

        $provider->sendOtp(100, [
            'phone_number' => '0912345678',
            'birth_year' => '1994',
            'category' => 20,
        ]);

        // Golden body from the official Postman collection — proves the
        // schema-driven mapping, not just that the fields exist.
        self::assertSame(
            '{"pay_method":"sadad","amount":100,"customer_mobile":"0912345678","birth_year":"1994","category":20}',
            json_encode($client->seen?->toBody()),
        );
    }

    public function test_category_zero_reaches_the_wire(): void
    {
        // Category 0 is a valid Sadad category (e-commerce default) and must
        // not be dropped by a truthiness check anywhere in the pipeline.
        $client = new class implements \DPay\Client\DPayClientInterface {
            public ?\DPay\Dto\OpenSessionRequest $seen = null;

            public function openSession(\DPay\Dto\OpenSessionRequest $request, ?string $idempotencyKey = null): \DPay\Dto\OpenSessionResponse
            {
                $this->seen = $request;

                return \DPay\Dto\OpenSessionResponse::fromArray(['session_id' => 1, 'status' => 'pending']);
            }

            public function verifySession(int $sessionId, string $otp): ?\DPay\Dto\VerifySessionResponse
            {
                return null;
            }

            public function getSession(int $sessionId): \DPay\Dto\GetSessionResponse
            {
                return \DPay\Dto\GetSessionResponse::fromArray(['session_id' => $sessionId, 'status' => 'paid']);
            }
        };

        $provider = new SadadProvider($client, 'sadad');
        $provider->sendOtp(100, ['phone_number' => '0912345678', 'birth_year' => '1994', 'category' => 0]);

        self::assertArrayHasKey('category', $client->seen?->toBody());
        self::assertSame(0, $client->seen?->toBody()['category']);
    }

    public function test_omitting_category_uses_the_merchant_default(): void
    {
        // Category is optional — omitting it must not send category:null or
        // category:"" to DPay; the key must be absent so the merchant's
        // configured default applies server-side.
        $client = new class implements \DPay\Client\DPayClientInterface {
            public ?\DPay\Dto\OpenSessionRequest $seen = null;

            public function openSession(\DPay\Dto\OpenSessionRequest $request, ?string $idempotencyKey = null): \DPay\Dto\OpenSessionResponse
            {
                $this->seen = $request;

                return \DPay\Dto\OpenSessionResponse::fromArray(['session_id' => 1, 'status' => 'pending']);
            }

            public function verifySession(int $sessionId, string $otp): ?\DPay\Dto\VerifySessionResponse
            {
                return null;
            }

            public function getSession(int $sessionId): \DPay\Dto\GetSessionResponse
            {
                return \DPay\Dto\GetSessionResponse::fromArray(['session_id' => $sessionId, 'status' => 'paid']);
            }
        };

        $provider = new SadadProvider($client, 'sadad');
        $provider->sendOtp(100, ['phone_number' => '0912345678', 'birth_year' => '1994']);

        self::assertArrayNotHasKey('category', $client->seen?->toBody());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/SadadProviderTest.php`
Expected: FAIL for `test_category_zero_reaches_the_wire` specifically — this is the sharpest edge case. Read the actual failure carefully: if `AbstractDPayProvider::sendOtp()`'s wire-building loop uses `$value === null || $value === ''` to decide whether to forward a field (it does, per the file already read), then `category => 0` passed as an int from the test's `$fields` array must survive. **If this test fails, it means there IS a real regression in the already-committed Plan 1 code** — stop and report rather than "fixing" the test to match broken behavior. (It should not fail — this documents the expectation, not a known bug.)

Actually — expect only `test_send_otp_produces_the_spec_golden_body` to plausibly fail, and only because `SadadProvider` doesn't exist yet if Task 1 wasn't run first. Since Task 1 already created the class, all three of these tests should FAIL solely because they didn't exist before this step, then PASS once added — there is no new production code in this task. If any of the three fails for a reason OTHER than "test didn't exist yet," treat that as a genuine bug report and stop.

- [ ] **Step 3: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/SadadProviderTest.php`
Expected: OK (8 tests)

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/SadadProviderTest.php
git commit -m "test: prove SadadProvider's wire mapping against the Postman golden body"
```

---

## Task 3: Laravel config wiring, disabled by default

**Files:**
- Modify: `src/Laravel/config/dpay.php`
- Test: `tests/Feature/LaravelBridgeTest.php`

- [ ] **Step 1: Write the failing test**

Read `tests/Feature/LaravelBridgeTest.php` first to find its existing `defineEnvironment()`/setup pattern — it already sets `dpay.mock`, `dpay.api_key`, and enables specific gateways for its own tests. Append this test to the class body, matching the file's existing style (do not duplicate an existing `defineEnvironment()` — add to it only if `sadad` needs an env override for this specific test, which it doesn't since we're testing the DEFAULT disabled state):

```php
    public function test_sadad_is_registered_but_disabled_by_default(): void
    {
        $manager = $this->app->make(\DPay\GatewayManager::class);

        self::assertArrayHasKey('sadad', $manager->all());
        self::assertNotContains('sadad', $manager->listEnabled());
        self::assertFalse($manager->isEnabled('sadad'));
    }

    public function test_sadad_can_be_enabled_via_config(): void
    {
        config(['dpay.gateways.sadad.enabled' => true]);

        // GatewayManager is a singleton built once at boot from config, so
        // a runtime config change requires a fresh manager instance to see it.
        $this->app->forgetInstance(\DPay\GatewayManager::class);
        $manager = $this->app->make(\DPay\GatewayManager::class);

        self::assertContains('sadad', $manager->listEnabled());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/LaravelBridgeTest.php`
Expected: FAIL — `sadad` key absent from `$manager->all()`, since no config entry exists yet.

- [ ] **Step 3: Add the config entry**

In `src/Laravel/config/dpay.php`, add the import:

```php
use DPay\Providers\SadadProvider;
```

Add the gateway entry to the `'gateways'` array, after `'saharapay'` and before `'moamalat'` (alphabetical-ish grouping already used by the file — Sadad sits with the other DPay-native gateways, not with Moamalat's payment-link group):

```php
        'sadad' => [
            'enabled' => (bool) env('PAYMENT_GATEWAY_SADAD_ENABLED', false),
            'provider' => SadadProvider::class,
            'pay_method' => env('DPAY_PAY_METHOD_SADAD', 'sadad'),
        ],
```

Note `enabled` defaults to `false`, matching Moamalat's pattern — both are gateways that need explicit merchant-side enablement before going live, so both default off in this SDK regardless of what the host's `.env` says, unless the host opts in.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/LaravelBridgeTest.php`
Expected: OK, all tests including the two new ones.

- [ ] **Step 5: Run the full suite**

Run: `composer check`
Expected: PHPStan level 8 clean; full suite green.

- [ ] **Step 6: Commit**

```bash
git add src/Laravel/config/dpay.php tests/Feature/LaravelBridgeTest.php
git commit -m "feat: register SadadProvider in the Laravel bridge, disabled by default"
```

---

## Task 4: Close the MockTransport expiry gap a Plan 1 reviewer flagged

A code-quality reviewer on Plan 1's Task 12 noted `sadad`'s 10-minute expiry was only proven indirectly (it shares Moamalat's `in_array` branch, but nothing asserted the literal string `'sadad'` — a typo in that array would go undetected). Now that a real `SadadProvider` exists, close it.

**Files:**
- Test: `tests/Unit/MockTransportTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/MockTransportTest.php`:

```php
    public function test_sadad_expires_in_ten_minutes(): void
    {
        $response = (new \DPay\Support\MockTransport())->openSession(
            new \DPay\Dto\OpenSessionRequest(payMethod: 'sadad', amount: 50),
        );

        $minutes = (strtotime($response->expiredAt) - time()) / 60;

        self::assertEqualsWithDelta(10, $minutes, 0.05);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/MockTransportTest.php`
Expected: This test should actually PASS immediately, since `MockTransport::expiryFor()` already includes `'sadad'` in its 10-minute list (Plan 1, Task 12, already committed). That's expected and fine — this task exists to add missing *coverage* of already-correct behavior, not to fix a bug. Confirm it passes; there is nothing to implement.

- [ ] **Step 3: Run the full suite**

Run: `composer check`
Expected: PHPStan clean, full suite green, test count up by one.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/MockTransportTest.php
git commit -m "test: pin sadad's 10-minute mock expiry explicitly, not just via moamalat's branch"
```

---

## Task 5: Documentation sync

Per `CLAUDE.md`'s "Keeping docs in sync" mandate — provider changes ripple into more files than usual in this repo.

**Files:**
- Modify: `composer.json`, `docs/providers.md`, `docs/configuration.md`, `docs/checkout-flow.md`, `docs/extending.md`, `CHANGELOG.md`

- [ ] **Step 1: Drop `yaser` from composer.json**

In `composer.json`, `keywords` currently reads:
```json
"keywords": ["dpay", "payment", "libya", "edfali", "mobicash", "sadad", "yaser", "saharapay", "yousrpay", "masrefypay", "moamalat", "sdk"],
```
`sadad` stays (now accurate). Remove `"yaser"` — it is not in the official spec at all and was correctly dropped from the codebase; the keyword should not have kept advertising it:
```json
"keywords": ["dpay", "payment", "libya", "edfali", "mobicash", "sadad", "saharapay", "yousrpay", "masrefypay", "moamalat", "sdk"],
```

Also update the `description` field, which currently reads:
```
"Framework-agnostic PHP SDK for the DPay payment gateway (Libya) with provider abstractions for Edfali, MobiCash, Sadad, Yaser, SaharaPay, YousrPay, MasrefyPay, and Moamalat. Optional Laravel bridge included."
```
Remove `Yaser` from that sentence (Sadad stays, now true):
```
"Framework-agnostic PHP SDK for the DPay payment gateway (Libya) with provider abstractions for Edfali, MobiCash, Sadad, SaharaPay, YousrPay, MasrefyPay, and Moamalat. Optional Laravel bridge included."
```

- [ ] **Step 2: Update `docs/providers.md`**

Replace the note at the top (currently: `> **Sadad / Yaser are not in this build.** ...`) with:

```markdown
> **Sadad is disabled by default.** DPay's sandbox rejects it with
> `"Unsupported payment method: sadad"` until the gateway is enabled on
> your merchant account — confirm with DPay before flipping
> `PAYMENT_GATEWAY_SADAD_ENABLED=true`. **Yaser** is not shipped — it
> does not appear in the official DPay spec at all.
```

Add a new provider card after the SaharaPay section and before "YousrPay" (or wherever fits the file's existing gateway ordering — check the file, it likely groups by similarity; Sadad is phone+OTP like Edfali, so consider placing it near Edfali instead if that reads better — use your judgment on placement, the content is what matters):

```markdown
## Sadad

| | |
|---|---|
| Class | `DPay\Providers\SadadProvider` |
| `code` | `sadad` |
| `pay_method` | `sadad` |
| `requiresOtp` | true |
| `supportsStatusCheck` | false |

REST mobile wallet (Almadar Aljadid). 6-digit OTP, 10-minute validity.
Requires `customer_mobile` **and** `birth_year` (4 digits, cross-checked
against the wallet registration record) — the only DPay gateway with this
requirement.

**`requiredFields()` default:**
```php
[
  PaymentField::phoneNumber(),
  PaymentField::birthYear(),
  PaymentField::sadadCategory(),   // optional, 0-36, omit for merchant default
]
```

**Request body to DPay** (POST `/payment/sessions/open`):
```json
{ "pay_method": "sadad", "amount": 100, "customer_mobile": "0912345678", "birth_year": "1994", "category": 20 }
```

> **Disabled by default.** Set `PAYMENT_GATEWAY_SADAD_ENABLED=true` only
> after confirming with DPay that Sadad is enabled on your merchant
> account — otherwise every session open fails server-side.
```

Update the "Cheat sheet — fields by provider" table at the bottom to add a row:

```markdown
| `sadad`      | `phone_number`, `birth_year`, `category` (optional) | `customer_mobile`, `birth_year`, `category` |
```

- [ ] **Step 3: Update `docs/configuration.md`**

In the "Per-gateway enable" table, add:
```markdown
| `PAYMENT_GATEWAY_SADAD_ENABLED` | `false` | Merchant-gated — confirm with DPay before enabling. |
```

In the "Per-gateway `pay_method` override" table, add:
```markdown
| `DPAY_PAY_METHOD_SADAD` | `sadad` |
```

- [ ] **Step 4: Update `docs/checkout-flow.md`**

In the "Important fields per provider" table (Step 1 section), add a row:
```markdown
| `sadad` | `phone_number`, `birth_year`, `category` (optional) |
```

- [ ] **Step 5: Update `docs/extending.md`**

Find the footnote under "Scenario 2 — a new DPay `pay_method`" that currently says something like Sadad "needs more than the `extending.md` recipe, since `AbstractDPayProvider` can only map `phone_number` and `card_number`." That claim is now false — the generic `wireName()` mapping (Plan 1) means `AbstractDPayProvider` can map any field. Update that sentence to point at `SadadProvider` as the worked example instead of describing it as a limitation:

Replace the outdated limitation note with:

```markdown
> **Sadad is a worked example of this.** It needs `birth_year` and
> `category` alongside `phone_number` — see
> `src/Providers/SadadProvider.php`. No base-class changes were needed;
> `AbstractDPayProvider::sendOtp()` maps every declared field by its
> `PaymentField::wireName()`, not by a hardcoded key list.
```

- [ ] **Step 6: Add a CHANGELOG entry**

In `CHANGELOG.md`, under `## [Unreleased]` (currently `_Nothing yet._`), replace with:

```markdown
## [Unreleased]

### Added
- `DPay\Providers\SadadProvider` — REST mobile wallet (Almadar Aljadid),
  needs `customer_mobile` + `birth_year` + optional `category`. Ships
  disabled by default; the gateway is merchant-gated on DPay's side.

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

### Fixed
- `SessionStatus` gains `VOIDED`.
- `UnknownProviderException` now also implements `DPayExceptionInterface`,
  so a single `catch` can cover both SDK exception trees.
```

Do not touch the existing `## [0.1.0]` section below — it is a historical record of what shipped then, not a place to retroactively note later changes.

- [ ] **Step 7: Run the full suite**

Run: `composer check`
Expected: green (docs changes don't affect tests, but confirm nothing else broke).

- [ ] **Step 8: Commit**

```bash
git add composer.json docs/providers.md docs/configuration.md docs/checkout-flow.md docs/extending.md CHANGELOG.md
git commit -m "docs: sync provider docs, composer keywords, and CHANGELOG for Sadad"
```

---

## Task 6: Live sandbox verification

**Prerequisite:** `DPAY_API_KEY` must be readable by the shell running this — it already is, from Plan 1's setup.

The `sadad` scenario already exists in `tests/sandbox/Scenarios.php` (Plan 1) and was already run once in Plan 1 Task 14, recording the expected `"Unsupported payment method: sadad"` failure. This task re-runs it now that a real `SadadProvider` exists, to confirm the SAME failure mode still holds (i.e. confirm the blocker is genuinely server-side merchant gating, not something the new provider code could have fixed) — and to make sure adding the provider didn't somehow change the request in a way that produces a *different* error.

- [ ] **Step 1: Confirm the offline gate is green**

Run: `composer check`
Expected: PHPStan clean, full suite passing (baseline + all tests from Tasks 1-4 of this plan). **Do not proceed to the live call until this is green.**

- [ ] **Step 2: Reset just the sadad ledger entry and re-run**

```bash
php -r '
$path = __DIR__."/tests/sandbox/.probe-ledger.json";
$ledger = is_file($path) ? json_decode(file_get_contents($path), true) : [];
unset($ledger["sadad"]);
file_put_contents($path, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
'
php tests/sandbox/probe.php --provider=sadad
```

Expected: still `FAIL`, still with a message naming `sadad` as unsupported (the exact wording may vary slightly from Plan 1's run — DPay's error text isn't a stability contract — but the failure MUST still be about the gateway being unsupported/disabled server-side, not a client-side validation error like a malformed body). **Record it as-is.**

If the result is somehow different — e.g. it now succeeds, or fails with a DIFFERENT kind of error (a 422 validation error about the request shape, rather than a 500/rejection about the gateway itself) — STOP and report to the coordinator rather than guessing what changed. A validation-shaped error would mean the new provider's field mapping has a real bug worth fixing before proceeding; a continued "unsupported gateway" error confirms the blocker is exactly what Plan 1 already established.

- [ ] **Step 3: Verify no token leaked**

```bash
grep -c 'sb_tk_[A-Za-z0-9]' SANDBOX-VALIDATION.md tests/sandbox/.probe-ledger.json 2>&1
```
Expected: `0` for both, or "CLEAN".

- [ ] **Step 4: Commit the regenerated report**

```bash
git add SANDBOX-VALIDATION.md
git commit -m "test: re-confirm sadad's live-blocked status with SadadProvider now shipped"
```

---

## Definition of done

- [ ] `composer check` green
- [ ] `SadadProvider` registered in `GatewayManager::all()`, absent from `listEnabled()` by default
- [ ] Golden-body test proves `sendOtp()` produces the exact Postman-documented request
- [ ] `category: 0` proven to survive the pipeline; omitted `category` proven to send no key at all
- [ ] Sadad's 10-minute mock expiry explicitly pinned, not just inherited via Moamalat's branch
- [ ] All six doc/config locations updated; `yaser` removed from `composer.json`
- [ ] Live sandbox re-confirms the same "unsupported gateway" blocker, or the plan author is alerted to a behavior change
- [ ] No `sb_tk_` string anywhere in tracked files
