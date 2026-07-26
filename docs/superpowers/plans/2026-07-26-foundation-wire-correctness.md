# DPay Foundation & Wire Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the DPay SDK's payment-session wire format to exact parity with the official spec, closing every money-affecting defect, and rebuild the sandbox probe into a per-provider gate.

**Architecture:** Extract HTTP concerns from `DPayClient` into a reusable `Transport` so later plans can add clients without growing one class. Generalise the provider field mapping so the declared `PaymentField` schema — not hardcoded `if` branches — decides what reaches the wire. Fix the request DTO so amounts, `description`, and `data` match the spec.

**Tech Stack:** PHP 8.2+, PSR-18/17/3, PHPUnit 11, PHPStan level 8, Orchestra Testbench (Laravel bridge only).

**Plan 1 of 5.** Closes spec defects #1–#6 and #9. Sadad (#7), webhooks (#8), and merchant reads/invoices (#10) are later plans.

**Spec:** [`docs/superpowers/specs/2026-07-26-dpay-spec-alignment-design.md`](../specs/2026-07-26-dpay-spec-alignment-design.md)

---

## File Structure

**Create:**

| File | Responsibility |
|---|---|
| `src/Http/Transport.php` | Build/send PSR-18 requests, decode JSON, map status→exception. Two modes: `request()` throws, `attempt()` returns null. |
| `src/Exceptions/DPayExceptionInterface.php` | Marker so one catch covers both exception trees |
| `tests/Unit/Http/TransportTest.php` | Transport behaviour in isolation |
| `tests/Unit/Dto/OpenSessionRequestTest.php` | Golden-body assertions against Postman |
| `tests/sandbox/Scenarios.php` | Per-provider live scenario definitions |
| `tests/sandbox/ProbeRunner.php` | Pacing, backoff, resumable ledger, report generation |

**Modify:**

| File | Change |
|---|---|
| `.gitignore` | Ignore `.env` and probe ledger |
| `src/Dto/OpenSessionRequest.php` | Add `birthYear`/`category`/`data`; delete `(int)` cast; move `description` top-level |
| `src/Dto/SessionStatus.php` | Add `VOIDED` |
| `src/Dto/PaymentField.php` | Add `sendAs`, `digitsOneOf`, three named constructors |
| `src/Config/DPayConfig.php` | `minAmount` int→float, default 0.01 |
| `src/Client/DPayClient.php` | Use `Transport`; DTO-based `openSession`; drop fractional guard |
| `src/Client/DPayClientInterface.php` | New `openSession` signature |
| `src/Client/DPayClientFactory.php` | Construct `Transport` |
| `src/Providers/AbstractDPayProvider.php` | Generic `sendAs` mapping |
| `src/Providers/{MasrefyPay,YousrPay,SaharaPay}Provider.php` | `bankCardNumber()` |
| `src/Providers/MoamalatProvider.php` | `supportsRefund()` true; new call signature |
| `src/Contracts/PaymentProviderInterface.php` | `supportsWebhook()` docblock |
| `src/Exceptions/DPayException.php` | Implement marker |
| `src/Exceptions/UnknownProviderException.php` | Implement marker |
| `src/Laravel/PaymentFieldRules.php` | `digitsOneOf` → regex rule |
| `src/Laravel/Facades/DPayFacadeAccessor.php` | New `openSession` signature |
| `src/Support/MockTransport.php` | Per-gateway expiry; `000000` decline |

---

## Task 1: Repository safety for the sandbox token

**Files:**
- Modify: `.gitignore`

- [ ] **Step 1: Add ignore rules**

Append to `.gitignore`:

```
.env
.env.*
!.env.example
/tests/sandbox/.probe-ledger.json
```

- [ ] **Step 2: Verify a .env would be ignored**

Run: `printf 'DPAY_API_KEY=sb_tk_dummy\n' > .env && git check-ignore -v .env && rm .env`
Expected: prints a line naming `.gitignore` as the source. If it prints nothing, the rule did not take.

- [ ] **Step 3: Commit**

```bash
git add .gitignore
git commit -m "chore: ignore .env and probe ledger so sandbox tokens cannot be committed"
```

---

## Task 2: Unified exception marker

Closes defect #9. `UnknownProviderException` extends `InvalidArgumentException`, so `catch (DPayException)` misses it.

**Files:**
- Create: `src/Exceptions/DPayExceptionInterface.php`
- Modify: `src/Exceptions/DPayException.php`, `src/Exceptions/UnknownProviderException.php`
- Test: `tests/Unit/ExceptionHierarchyTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ExceptionHierarchyTest.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayExceptionInterface;
use DPay\Exceptions\UnknownProviderException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    public function test_dpay_exceptions_implement_the_marker(): void
    {
        self::assertInstanceOf(DPayExceptionInterface::class, new DPayAuthException('nope', 401));
    }

    public function test_unknown_provider_exception_implements_the_marker(): void
    {
        self::assertInstanceOf(DPayExceptionInterface::class, new UnknownProviderException('nope'));
    }

    public function test_one_catch_block_covers_both_trees(): void
    {
        $caught = 0;

        foreach ([new DPayAuthException('a', 401), new UnknownProviderException('b')] as $e) {
            try {
                throw $e;
            } catch (DPayExceptionInterface) {
                $caught++;
            }
        }

        self::assertSame(2, $caught);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ExceptionHierarchyTest.php`
Expected: FAIL — `Class "DPay\Exceptions\DPayExceptionInterface" not found`

- [ ] **Step 3: Create the marker interface**

Create `src/Exceptions/DPayExceptionInterface.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Exceptions;

use Throwable;

/**
 * Marker implemented by every exception this SDK throws.
 *
 * DPayException extends RuntimeException while UnknownProviderException
 * extends InvalidArgumentException, so no single class sits above both.
 * Catch this interface to handle any SDK failure in one block.
 */
interface DPayExceptionInterface extends Throwable {}
```

- [ ] **Step 4: Implement it on both trees**

In `src/Exceptions/DPayException.php`, change the class line to:

```php
class DPayException extends RuntimeException implements DPayExceptionInterface
```

In `src/Exceptions/UnknownProviderException.php`, change the class line to:

```php
class UnknownProviderException extends InvalidArgumentException implements DPayExceptionInterface {}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/ExceptionHierarchyTest.php`
Expected: OK (3 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Exceptions tests/Unit/ExceptionHierarchyTest.php
git commit -m "feat: add DPayExceptionInterface so one catch covers both exception trees"
```

---

## Task 3: Add the `voided` session status

Closes defect #5.

**Files:**
- Modify: `src/Dto/SessionStatus.php`
- Test: `tests/Unit/SessionStatusTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SessionStatusTest.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Dto\SessionStatus;
use PHPUnit\Framework\TestCase;

final class SessionStatusTest extends TestCase
{
    public function test_voided_is_a_known_status(): void
    {
        self::assertSame(SessionStatus::VOIDED, SessionStatus::fromString('voided'));
    }

    public function test_voided_is_terminal(): void
    {
        self::assertTrue(SessionStatus::VOIDED->isTerminal());
    }

    public function test_unrecognised_status_still_degrades_to_unknown(): void
    {
        self::assertSame(SessionStatus::UNKNOWN, SessionStatus::fromString('wat'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/SessionStatusTest.php`
Expected: FAIL — undefined constant `SessionStatus::VOIDED`

- [ ] **Step 3: Add the case**

In `src/Dto/SessionStatus.php`, add after the `REFUNDED` case:

```php
    case VOIDED = 'voided';
```

And include it in `isTerminal()`:

```php
    public function isTerminal(): bool
    {
        return match ($this) {
            self::PAID, self::FAILED, self::EXPIRED, self::REFUNDED, self::VOIDED => true,
            default => false,
        };
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/SessionStatusTest.php`
Expected: OK (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Dto/SessionStatus.php tests/Unit/SessionStatusTest.php
git commit -m "feat: add voided session status per DPay spec"
```

---

## Task 4: `minAmount` becomes a float defaulting to 0.01

Closes half of defect #3.

**Files:**
- Modify: `src/Config/DPayConfig.php`
- Test: `tests/Unit/DPayConfigTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/DPayConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Config\DPayConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DPayConfigTest extends TestCase
{
    public function test_min_amount_defaults_to_the_spec_minimum(): void
    {
        self::assertSame(0.01, (new DPayConfig())->minAmount);
    }

    public function test_min_amount_accepts_a_fractional_value(): void
    {
        self::assertSame(2.5, (new DPayConfig(minAmount: 2.5))->minAmount);
    }

    public function test_from_array_reads_a_fractional_min_amount(): void
    {
        self::assertSame(0.5, DPayConfig::fromArray(['min_amount' => 0.5])->minAmount);
    }

    public function test_negative_min_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DPayConfig(minAmount: -1.0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/DPayConfigTest.php`
Expected: FAIL — `assertSame(0.01, 5)` mismatch, and `fromArray` returns `int(0)` because of the `(int)` cast

- [ ] **Step 3: Change the property type and cast**

In `src/Config/DPayConfig.php`, change the constructor parameter:

```php
        public readonly float $minAmount = 0.01,
```

And in `fromArray()`:

```php
            minAmount: (float) ($cfg['min_amount'] ?? 0.01),
```

The existing `if ($minAmount < 0)` guard is already correct for floats — leave it.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/DPayConfigTest.php`
Expected: OK (4 tests)

- [ ] **Step 5: Update the Laravel config default**

In `src/Laravel/config/dpay.php`, change:

```php
    'min_amount' => (float) env('DPAY_MIN_AMOUNT', 0.01),
```

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: Some existing tests fail — they assert the old minimum of 5. Update those assertions to the new default; do not reinstate the old value.

- [ ] **Step 7: Commit**

```bash
git add src/Config/DPayConfig.php src/Laravel/config/dpay.php tests/
git commit -m "feat!: min_amount becomes float defaulting to 0.01 per DPay spec"
```

---

## Task 5: `PaymentField` gains `sendAs` and `digitsOneOf`

Closes defect #1's data model. Bank cards are 7 **or** 9 digits — `digits:7` cannot express it and `digits_between:7,9` would wrongly admit 8.

**Files:**
- Modify: `src/Dto/PaymentField.php`
- Test: `tests/Unit/PaymentFieldTest.php`

- [ ] **Step 1: Write the failing test**

Append these methods to the existing `tests/Unit/PaymentFieldTest.php` class:

```php
    public function test_wire_name_defaults_to_the_key(): void
    {
        self::assertSame('phone_number', (new PaymentField(key: 'phone_number'))->wireName());
    }

    public function test_send_as_overrides_the_wire_name(): void
    {
        $field = new PaymentField(key: 'phone_number', sendAs: 'customer_mobile');

        self::assertSame('customer_mobile', $field->wireName());
    }

    public function test_bank_card_number_accepts_seven_or_nine_digits(): void
    {
        $field = PaymentField::bankCardNumber();

        self::assertSame([7, 9], $field->digitsOneOf);
        self::assertNull($field->digits);
        self::assertSame('card_number', $field->wireName());
    }

    public function test_birth_year_is_a_four_digit_field_sent_as_birth_year(): void
    {
        $field = PaymentField::birthYear();

        self::assertSame(4, $field->digits);
        self::assertSame('birth_year', $field->wireName());
    }

    public function test_sadad_category_is_optional_and_integer(): void
    {
        $field = PaymentField::sadadCategory();

        self::assertFalse($field->required);
        self::assertSame('integer', $field->type);
        self::assertSame('category', $field->wireName());
    }

    public function test_to_array_exposes_send_as_and_digits_one_of(): void
    {
        $array = PaymentField::bankCardNumber()->toArray();

        self::assertSame([7, 9], $array['digits_one_of']);
        self::assertSame('card_number', $array['send_as']);
    }

    public function test_from_array_round_trips_the_new_keys(): void
    {
        $field = PaymentField::fromArray(PaymentField::bankCardNumber()->toArray());

        self::assertSame([7, 9], $field->digitsOneOf);
        self::assertSame('card_number', $field->wireName());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/PaymentFieldTest.php`
Expected: FAIL — unknown named parameter `$sendAs`, undefined method `wireName()`

- [ ] **Step 3: Add the properties, accessor, and constructors**

In `src/Dto/PaymentField.php`, add two constructor parameters after `$digits`:

```php
        /** @var list<int>|null Exact digit counts, any of which is valid (bank cards: 7 or 9). */
        public readonly ?array $digitsOneOf = null,
```

and after `$inputType`:

```php
        /** Wire field name sent to DPay. Defaults to $key. */
        public readonly ?string $sendAs = null,
```

Add the accessor:

```php
    /**
     * The field name as DPay expects it on the wire.
     */
    public function wireName(): string
    {
        return $this->sendAs ?? $this->key;
    }
```

Add the three named constructors:

```php
    /**
     * Bank card for MasrefyPay / YousrPay / SaharaPay.
     * 7 digits same-bank, or 9 digits cross-bank via OnePay
     * (2-digit bank prefix + 7). MobiCash is 7-only — use cardNumber().
     */
    public static function bankCardNumber(string $key = 'card_number'): self
    {
        return new self(
            key: $key,
            type: 'string',
            required: true,
            digitsOneOf: [7, 9],
            labels: ['en' => 'Card Number', 'ar' => 'رقم البطاقة'],
            placeholders: ['en' => '7 or 9 digits', 'ar' => '7 أو 9 أرقام'],
            inputType: 'number',
        );
    }

    /**
     * Sadad year of birth. Cross-checked against the wallet registration record.
     */
    public static function birthYear(string $key = 'birth_year'): self
    {
        return new self(
            key: $key,
            type: 'string',
            required: true,
            digits: 4,
            labels: ['en' => 'Year of Birth', 'ar' => 'سنة الميلاد'],
            placeholders: ['en' => '1994', 'ar' => '1994'],
            inputType: 'number',
        );
    }

    /**
     * Sadad service category (0-36). Optional — omitting it uses the
     * merchant's configured default.
     */
    public static function sadadCategory(string $key = 'category'): self
    {
        return new self(
            key: $key,
            type: 'integer',
            required: false,
            labels: ['en' => 'Service Category', 'ar' => 'فئة الخدمة'],
            placeholders: ['en' => '20', 'ar' => '20'],
            inputType: 'number',
        );
    }
```

Update `toArray()` to include both new keys:

```php
            'digits_one_of' => $this->digitsOneOf,
            'send_as' => $this->wireName(),
```

Update `fromArray()`:

```php
            digitsOneOf: isset($a['digits_one_of']) && is_array($a['digits_one_of'])
                ? array_values(array_map(static fn ($d): int => (int) $d, $a['digits_one_of']))
                : null,
            sendAs: isset($a['send_as']) ? (string) $a['send_as'] : null,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/PaymentFieldTest.php`
Expected: OK

- [ ] **Step 5: Commit**

```bash
git add src/Dto/PaymentField.php tests/Unit/PaymentFieldTest.php
git commit -m "feat: PaymentField gains sendAs wire name and digitsOneOf for 7-or-9 digit cards"
```

---

## Task 6: `PaymentFieldRules` emits a regex for `digitsOneOf`

**Files:**
- Modify: `src/Laravel/PaymentFieldRules.php`
- Test: `tests/Feature/PaymentFieldRulesTest.php`

- [ ] **Step 1: Write the failing test**

Append to the existing `tests/Feature/PaymentFieldRulesTest.php` class:

```php
    public function test_digits_one_of_becomes_an_alternation_regex(): void
    {
        $provider = new \DPay\Providers\SaharaPayProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'saharapay',
        );

        $rules = \DPay\Laravel\PaymentFieldRules::for($provider);

        self::assertContains('regex:/^(\d{7}|\d{9})$/', $rules['fields.card_number']);
    }

    public function test_nine_digit_cross_bank_card_passes_validation(): void
    {
        $provider = new \DPay\Providers\SaharaPayProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'saharapay',
        );

        $validator = $this->app['validator']->make(
            ['fields' => ['card_number' => '661234567']],
            \DPay\Laravel\PaymentFieldRules::for($provider),
        );

        self::assertTrue($validator->passes(), 'A 9-digit OnePay card must be accepted.');
    }

    public function test_eight_digit_card_is_rejected(): void
    {
        $provider = new \DPay\Providers\SaharaPayProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'saharapay',
        );

        $validator = $this->app['validator']->make(
            ['fields' => ['card_number' => '12345678']],
            \DPay\Laravel\PaymentFieldRules::for($provider),
        );

        self::assertFalse($validator->passes(), '8 digits is neither same-bank nor cross-bank.');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/PaymentFieldRulesTest.php`
Expected: FAIL — the 8-digit case passes because no regex rule is emitted yet

- [ ] **Step 3: Emit the rule**

In `src/Laravel/PaymentFieldRules.php`, inside `rulesForField()`, add after the `digits` block:

```php
        if ($field->digitsOneOf !== null && $field->digitsOneOf !== []) {
            $alternatives = implode(
                '|',
                array_map(static fn (int $d): string => '\d{'.$d.'}', $field->digitsOneOf),
            );

            $rules[] = 'regex:/^('.$alternatives.')$/';
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/PaymentFieldRulesTest.php`
Expected: OK

- [ ] **Step 5: Commit**

```bash
git add src/Laravel/PaymentFieldRules.php tests/Feature/PaymentFieldRulesTest.php
git commit -m "feat: emit alternation regex for digitsOneOf so 9-digit OnePay cards validate"
```

---

## Task 7: Fix the request body — the money fix

Closes defects #2, #3, #4. **This is the highest-risk task in the plan.**

Reference values verified in the shell:
`json_encode(100.0)` → `100`; `json_encode(10.5)` → `10.5`; `json_encode(0.01)` → `0.01`.
**Do not add `JSON_PRESERVE_ZERO_FRACTION` anywhere** — it would emit `100.0` and break byte-for-byte parity with the Postman collection.

**Files:**
- Modify: `src/Dto/OpenSessionRequest.php`
- Test: `tests/Unit/Dto/OpenSessionRequestTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Dto/OpenSessionRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Dto;

use DPay\Dto\OpenSessionRequest;
use PHPUnit\Framework\TestCase;

final class OpenSessionRequestTest extends TestCase
{
    public function test_decimal_amount_is_never_truncated(): void
    {
        $body = (new OpenSessionRequest(payMethod: 'edfali', amount: 10.50))->toBody();

        self::assertSame(10.5, $body['amount']);
        self::assertSame('{"pay_method":"edfali","amount":10.5}', json_encode($body));
    }

    public function test_whole_amount_serialises_without_a_decimal_point(): void
    {
        $body = (new OpenSessionRequest(payMethod: 'edfali', amount: 100.0))->toBody();

        self::assertSame('{"pay_method":"edfali","amount":100}', json_encode($body));
    }

    public function test_description_is_top_level_not_nested_under_data(): void
    {
        $body = (new OpenSessionRequest(
            payMethod: 'mobicash',
            amount: 10,
            cardNumber: '7279627',
            description: 'Order #1234',
        ))->toBody();

        self::assertSame('Order #1234', $body['description']);
        self::assertArrayNotHasKey('data', $body);
    }

    public function test_data_carries_free_form_metadata(): void
    {
        $body = (new OpenSessionRequest(
            payMethod: 'edfali',
            amount: 50,
            data: ['order_id' => 'ORD-001'],
        ))->toBody();

        self::assertSame(['order_id' => 'ORD-001'], $body['data']);
    }

    public function test_empty_data_is_omitted(): void
    {
        $body = (new OpenSessionRequest(payMethod: 'edfali', amount: 50, data: []))->toBody();

        self::assertArrayNotHasKey('data', $body);
    }

    public function test_category_zero_survives_the_null_filter(): void
    {
        $body = (new OpenSessionRequest(
            payMethod: 'sadad',
            amount: 100,
            customerMobile: '0912345678',
            birthYear: '1994',
            category: 0,
        ))->toBody();

        self::assertArrayHasKey('category', $body);
        self::assertSame(0, $body['category']);
    }

    /**
     * Golden bodies copied verbatim from the official Postman collection.
     *
     * @return iterable<string, array{OpenSessionRequest, string}>
     */
    public static function goldenBodies(): iterable
    {
        yield 'edfali' => [
            new OpenSessionRequest(payMethod: 'edfali', amount: 100, customerMobile: '0912345678'),
            '{"pay_method":"edfali","amount":100,"customer_mobile":"0912345678"}',
        ];

        yield 'mobicash' => [
            new OpenSessionRequest(payMethod: 'mobicash', amount: 10, cardNumber: '7279627', description: 'Order #1234'),
            '{"pay_method":"mobicash","amount":10,"card_number":"7279627","description":"Order #1234"}',
        ];

        yield 'masrefypay same bank' => [
            new OpenSessionRequest(payMethod: 'masrefypay', amount: 50, cardNumber: '1234567'),
            '{"pay_method":"masrefypay","amount":50,"card_number":"1234567"}',
        ];

        yield 'masrefypay cross bank' => [
            new OpenSessionRequest(payMethod: 'masrefypay', amount: 50, cardNumber: '331234567'),
            '{"pay_method":"masrefypay","amount":50,"card_number":"331234567"}',
        ];

        yield 'yousrpay' => [
            new OpenSessionRequest(payMethod: 'yousrpay', amount: 50, cardNumber: '1234567'),
            '{"pay_method":"yousrpay","amount":50,"card_number":"1234567"}',
        ];

        yield 'saharapay' => [
            new OpenSessionRequest(payMethod: 'saharapay', amount: 50, cardNumber: '1234567'),
            '{"pay_method":"saharapay","amount":50,"card_number":"1234567"}',
        ];

        yield 'sadad' => [
            new OpenSessionRequest(payMethod: 'sadad', amount: 100, customerMobile: '0912345678', birthYear: '1994', category: 20),
            '{"pay_method":"sadad","amount":100,"customer_mobile":"0912345678","birth_year":"1994","category":20}',
        ];

        yield 'moamalat' => [
            new OpenSessionRequest(payMethod: 'moamalat', amount: 200),
            '{"pay_method":"moamalat","amount":200}',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('goldenBodies')]
    public function test_body_matches_the_official_postman_example(OpenSessionRequest $request, string $expected): void
    {
        self::assertSame($expected, json_encode($request->toBody()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Dto/OpenSessionRequestTest.php`
Expected: FAIL — unknown named parameters `$birthYear`, `$category`, `$data`; amount asserts fail because of the `(int)` cast; `description` is nested under `data`

- [ ] **Step 3: Rewrite the DTO**

Replace the body of `src/Dto/OpenSessionRequest.php` below the namespace:

```php
/**
 * Input for DPayClient::openSession.
 *
 * Field names and types follow the official spec at https://dpay.ly/docs/api.
 *   - amount      : LYD. Decimals allowed, minimum 0.01. NEVER cast to int.
 *   - description : top-level field (MobiCash), NOT nested under data.
 *   - data        : free-form merchant metadata, echoed back in webhooks.
 *   - birthYear   : Sadad only, 4 digits, checked against the wallet record.
 *   - category    : Sadad only, 0-36. Zero is meaningful — the null filter
 *                   below must stay `!== null`, never a truthiness check.
 */
final class OpenSessionRequest
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $payMethod,
        public readonly float $amount,
        public readonly ?string $customerMobile = null,
        public readonly ?string $cardNumber = null,
        public readonly ?string $birthYear = null,
        public readonly ?int $category = null,
        public readonly ?string $description = null,
        public readonly array $data = [],
    ) {}

    /**
     * Build the JSON body for /payment/sessions/open, stripping null fields.
     *
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return array_filter([
            'pay_method' => $this->payMethod,
            'amount' => $this->amount,
            'customer_mobile' => $this->customerMobile,
            'card_number' => $this->cardNumber,
            'birth_year' => $this->birthYear,
            'category' => $this->category,
            'description' => $this->description,
            'data' => $this->data === [] ? null : $this->data,
        ], static fn ($v) => $v !== null);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Dto/OpenSessionRequestTest.php`
Expected: OK (14 tests — 6 behavioural + 8 golden bodies)

- [ ] **Step 5: Commit**

```bash
git add src/Dto/OpenSessionRequest.php tests/Unit/Dto/OpenSessionRequestTest.php
git commit -m "fix!: stop truncating amounts, move description top-level, add birth_year/category/data

The (int) cast silently floored 10.50 to 10. Amount now serialises as a
number; json_encode already emits 100.0 as 100, matching the Postman
golden bodies byte-for-byte. Do not add JSON_PRESERVE_ZERO_FRACTION."
```

---

## Task 8: Generic `sendAs` field mapping in the provider base

**Files:**
- Modify: `src/Providers/AbstractDPayProvider.php`
- Test: `tests/Unit/ProviderFieldsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/ProviderFieldsTest.php`:

```php
    public function test_fields_reach_the_wire_under_their_send_as_name(): void
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

        $provider = new \DPay\Providers\EdfaliProvider($client, 'edfali');
        $provider->sendOtp(50, ['phone_number' => '0912345678']);

        self::assertSame('0912345678', $client->seen?->customerMobile);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ProviderFieldsTest.php`
Expected: FAIL — the anonymous class does not satisfy the old interface signature

- [ ] **Step 3: Replace the hardcoded mapping**

In `src/Providers/AbstractDPayProvider.php`, replace `sendOtp()` entirely:

```php
    public function sendOtp(float $amount, array $fields): string
    {
        $wire = [];

        foreach ($this->fields as $field) {
            $value = $fields[$field->key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $wire[$field->wireName()] = $field->type === 'integer' ? (int) $value : (string) $value;
        }

        $session = $this->client->openSession(new OpenSessionRequest(
            payMethod: $this->payMethod,
            amount: $amount,
            customerMobile: isset($wire['customer_mobile']) ? (string) $wire['customer_mobile'] : null,
            cardNumber: isset($wire['card_number']) ? (string) $wire['card_number'] : null,
            birthYear: isset($wire['birth_year']) ? (string) $wire['birth_year'] : null,
            category: isset($wire['category']) ? (int) $wire['category'] : null,
            description: isset($wire['description']) ? (string) $wire['description'] : null,
        ));

        return (string) $session->sessionId;
    }
```

Add the import at the top of the file:

```php
use DPay\Dto\OpenSessionRequest;
```

Update the class docblock — replace the "sendOtp() reads the field schema" paragraph with:

```
 * sendOtp() maps each declared field to its wire name via
 * PaymentField::wireName() (i.e. sendAs, defaulting to the key), so adding a
 * gateway field is a schema change rather than a base-class change.
```

- [ ] **Step 4: Point EdfaliProvider's field at the right wire name**

In `src/Providers/EdfaliProvider.php`, change `defaultFields()`:

```php
    protected function defaultFields(): array
    {
        return [PaymentField::phoneNumber(sendAs: 'customer_mobile')];
    }
```

And in `src/Dto/PaymentField.php`, extend the `phoneNumber()` signature:

```php
    public static function phoneNumber(
        string $key = 'phone_number',
        ?string $regex = '/^09\d{8}$/',
        ?string $sendAs = 'customer_mobile',
    ): self {
        return new self(
            key: $key,
            type: 'string',
            required: true,
            regex: $regex,
            labels: ['en' => 'Phone Number', 'ar' => 'رقم الهاتف'],
            placeholders: ['en' => '09xxxxxxxx', 'ar' => '09xxxxxxxx'],
            inputType: 'tel',
            sendAs: $sendAs,
        );
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/ProviderFieldsTest.php`
Expected: OK

- [ ] **Step 6: Commit**

```bash
git add src/Providers src/Dto/PaymentField.php tests/Unit/ProviderFieldsTest.php
git commit -m "refactor: map provider fields by sendAs instead of hardcoded key branches"
```

---

## Task 9: Split card rules and correct capability flags

Closes the rest of defect #1. Per the spec: bank gateways take 7 or 9 digits; **MobiCash is 7-only**.

**Files:**
- Modify: `src/Providers/{MasrefyPay,YousrPay,SaharaPay}Provider.php`, `src/Providers/MoamalatProvider.php`, `src/Providers/AbstractDPayProvider.php`
- Test: `tests/Unit/ProvidersTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/ProvidersTest.php`:

```php
    /**
     * @return iterable<string, array{class-string, list<int>|null, int|null}>
     */
    public static function cardRules(): iterable
    {
        yield 'masrefypay' => [\DPay\Providers\MasrefyPayProvider::class, [7, 9], null];
        yield 'yousrpay' => [\DPay\Providers\YousrPayProvider::class, [7, 9], null];
        yield 'saharapay' => [\DPay\Providers\SaharaPayProvider::class, [7, 9], null];
        yield 'mobicash is seven only' => [\DPay\Providers\MobiCashProvider::class, null, 7];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cardRules')]
    public function test_card_digit_rules_match_the_spec(string $class, ?array $oneOf, ?int $exact): void
    {
        $provider = new $class($this->createMock(\DPay\Client\DPayClientInterface::class), 'x');
        $field = $provider->requiredFields()[0];

        self::assertSame($oneOf, $field->digitsOneOf);
        self::assertSame($exact, $field->digits);
    }

    public function test_all_providers_support_webhooks(): void
    {
        $provider = new \DPay\Providers\EdfaliProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'edfali',
        );

        self::assertTrue($provider->supportsWebhook());
    }

    public function test_only_moamalat_supports_refunds(): void
    {
        $client = $this->createMock(\DPay\Client\DPayClientInterface::class);

        self::assertTrue((new \DPay\Providers\MoamalatProvider($client))->supportsRefund());
        self::assertFalse((new \DPay\Providers\EdfaliProvider($client, 'edfali'))->supportsRefund());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ProvidersTest.php`
Expected: FAIL — bank providers still report `digits = 7`, `supportsWebhook()` returns false

- [ ] **Step 3: Switch the three bank providers to `bankCardNumber()`**

In each of `src/Providers/MasrefyPayProvider.php`, `YousrPayProvider.php`, `SaharaPayProvider.php`:

```php
    protected function defaultFields(): array
    {
        return [PaymentField::bankCardNumber()];
    }
```

Leave `src/Providers/MobiCashProvider.php` on `PaymentField::cardNumber(digits: 7)` — the spec is explicit that MobiCash is 7-only.

- [ ] **Step 4: Correct the capability flags**

In `src/Providers/AbstractDPayProvider.php`:

```php
    public function supportsWebhook(): bool
    {
        return true;
    }
```

In `src/Providers/MoamalatProvider.php`:

```php
    public function supportsWebhook(): bool
    {
        return true;
    }

    /**
     * Moamalat transactions can be refunded and voided, but DPay exposes no
     * REST endpoint to initiate either — they are triggered from the dashboard
     * and observed via the payment.refunded / payment.voided webhooks.
     */
    public function supportsRefund(): bool
    {
        return true;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/ProvidersTest.php`
Expected: OK

- [ ] **Step 6: Commit**

```bash
git add src/Providers tests/Unit/ProvidersTest.php
git commit -m "feat: bank gateways accept 9-digit OnePay cards; correct webhook/refund flags"
```

---

## Task 10: Extract `Transport` from `DPayClient`

**Files:**
- Create: `src/Http/Transport.php`, `tests/Unit/Http/TransportTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/TransportTest.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Http;

use DPay\Config\DPayConfig;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayNetworkException;
use DPay\Exceptions\DPayRateLimitException;
use DPay\Http\Transport;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private function transport(FakeHttpClient $http): Transport
    {
        $psr17 = new Psr17Factory();

        return new Transport(
            new DPayConfig(baseUrl: 'https://dpay.ly/api', apiKey: 'k'),
            $http,
            $psr17,
            $psr17,
        );
    }

    public function test_request_sends_bearer_auth_and_decodes_json(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['ok' => true]);

        $body = $this->transport($http)->request('GET', '/ping');

        self::assertSame(['ok' => true], $body);
        self::assertSame('Bearer k', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_extra_headers_are_attached(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);

        $this->transport($http)->request('POST', '/x', ['a' => 1], ['Idempotency-Key' => 'abc-123']);

        self::assertSame('abc-123', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function test_request_throws_mapped_exception(): void
    {
        $http = (new FakeHttpClient())->queueJson(401, ['message' => 'Invalid sandbox API token.']);

        $this->expectException(DPayAuthException::class);
        $this->transport($http)->request('GET', '/x');
    }

    public function test_rate_limit_maps_to_its_own_exception(): void
    {
        $http = (new FakeHttpClient())->queueJson(429, ['message' => 'Too Many Attempts.']);

        $this->expectException(DPayRateLimitException::class);
        $this->transport($http)->request('GET', '/x');
    }

    public function test_attempt_returns_null_instead_of_throwing(): void
    {
        $http = (new FakeHttpClient())->queueJson(422, ['message' => 'bad otp']);

        self::assertNull($this->transport($http)->attempt('POST', '/verify', ['otp' => '0000']));
    }

    public function test_transport_failure_becomes_network_exception(): void
    {
        $http = new FakeHttpClient();
        $http->throwOnNext = new \RuntimeException('could not resolve host');

        $this->expectException(DPayNetworkException::class);
        $this->transport($http)->request('GET', '/x');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Http/TransportTest.php`
Expected: FAIL — `Class "DPay\Http\Transport" not found`

- [ ] **Step 3: Create the Transport**

Create `src/Http/Transport.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Http;

use DPay\Config\DPayConfig;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayException;
use DPay\Exceptions\DPayNetworkException;
use DPay\Exceptions\DPayRateLimitException;
use DPay\Exceptions\DPaySessionNotFoundException;
use DPay\Exceptions\DPayValidationException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Shared HTTP plumbing for every DPay client.
 *
 * Owns exactly one thing: turning a method/path/body into a decoded array,
 * or into the right exception. Endpoint semantics live in the clients.
 */
final class Transport
{
    public function __construct(
        private readonly DPayConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Perform a request, throwing a mapped DPayException on any non-2xx.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $response = $this->send($method, $path, $body, $headers);

        if (! $this->isSuccessful($response)) {
            $decoded = $this->decode($response);
            $message = (string) ($decoded['message'] ?? 'DPay request failed.');

            $this->logger->error('DPay request failed', [
                'status' => $response->getStatusCode(),
                'method' => $method,
                'path' => $path,
                'message' => $message,
            ]);

            throw $this->buildException($response->getStatusCode(), $message, $decoded);
        }

        return $this->decode($response);
    }

    /**
     * Perform a request, returning null on any non-2xx instead of throwing.
     *
     * Used by verifySession, where a wrong OTP is an ordinary user error
     * rather than an exceptional condition.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    public function attempt(string $method, string $path, ?array $body = null, array $headers = []): ?array
    {
        $response = $this->send($method, $path, $body, $headers);

        if (! $this->isSuccessful($response)) {
            $this->logger->warning('DPay request unsuccessful', [
                'status' => $response->getStatusCode(),
                'method' => $method,
                'path' => $path,
                'message' => (string) ($this->decode($response)['message'] ?? ''),
            ]);

            return null;
        }

        return $this->decode($response);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, ?array $body, array $headers): ResponseInterface
    {
        $request = $this->requestFactory
            ->createRequest($method, rtrim($this->config->baseUrl, '/').$path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer '.$this->config->apiKey);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            // No JSON_PRESERVE_ZERO_FRACTION: 100.0 must encode as 100 to
            // match DPay's documented bodies byte-for-byte.
            $payload = json_encode($body, JSON_THROW_ON_ERROR);

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($payload));
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('DPay transport failure', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new DPayNetworkException('Failed to reach DPay: '.$e->getMessage(), 0, null, $e);
        }
    }

    private function isSuccessful(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function buildException(int $status, string $message, array $body): DPayException
    {
        $errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : null;

        return match (true) {
            $status === 401 || $status === 403 => new DPayAuthException($message, $status, $errors),
            $status === 404 => new DPaySessionNotFoundException($message, $status, $errors),
            $status === 429 => new DPayRateLimitException($message, $status, $errors),
            $status >= 400 && $status < 500 => new DPayValidationException($message, $status, $errors),
            default => new DPayException($message, $status, $errors),
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Http/TransportTest.php`
Expected: OK (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Http tests/Unit/Http
git commit -m "refactor: extract Transport so later clients share HTTP plumbing"
```

---

## Task 11: Rewire `DPayClient` onto Transport with the new signature

Closes defects #3 and #6.

**Files:**
- Modify: `src/Client/DPayClient.php`, `src/Client/DPayClientInterface.php`, `src/Client/DPayClientFactory.php`, `src/Laravel/Facades/DPayFacadeAccessor.php`, `src/Providers/MoamalatProvider.php`
- Test: `tests/Unit/DPayClientTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/DPayClientTest.php`:

```php
    public function test_fractional_amount_is_now_accepted(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['session_id' => 7, 'status' => 'pending', 'amount' => 10.5]);

        $response = $this->clientWith($http)->openSession(
            new \DPay\Dto\OpenSessionRequest(payMethod: 'edfali', amount: 10.5, customerMobile: '0912345678'),
        );

        self::assertSame(7, $response->sessionId);
        self::assertStringContainsString('"amount":10.5', (string) $http->lastRequest()->getBody());
    }

    public function test_amount_below_minimum_is_still_rejected(): void
    {
        $this->expectException(\DPay\Exceptions\DPayValidationException::class);

        $this->clientWith(new FakeHttpClient(), new \DPay\Config\DPayConfig(apiKey: 'k', minAmount: 5.0))
            ->openSession(new \DPay\Dto\OpenSessionRequest(payMethod: 'edfali', amount: 1.0));
    }

    public function test_idempotency_key_is_sent_as_a_header(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['session_id' => 1, 'status' => 'pending']);

        $this->clientWith($http)->openSession(
            new \DPay\Dto\OpenSessionRequest(payMethod: 'edfali', amount: 50),
            'b3e1c9f0-0000-4000-8000-000000000000',
        );

        self::assertSame(
            'b3e1c9f0-0000-4000-8000-000000000000',
            $http->lastRequest()->getHeaderLine('Idempotency-Key'),
        );
    }

    public function test_no_idempotency_header_when_key_is_omitted(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['session_id' => 1, 'status' => 'pending']);

        $this->clientWith($http)->openSession(new \DPay\Dto\OpenSessionRequest(payMethod: 'edfali', amount: 50));

        self::assertFalse($http->lastRequest()->hasHeader('Idempotency-Key'));
    }
```

Add this helper to the same class (adjust if one already exists — do not duplicate):

```php
    private function clientWith(FakeHttpClient $http, ?\DPay\Config\DPayConfig $config = null): \DPay\Client\DPayClient
    {
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
        $config ??= new \DPay\Config\DPayConfig(apiKey: 'k');

        return new \DPay\Client\DPayClient(
            $config,
            new \DPay\Http\Transport($config, $http, $psr17, $psr17),
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/DPayClientTest.php`
Expected: FAIL — `DPayClient::__construct()` does not accept a Transport

- [ ] **Step 3: Rewrite DPayClient**

Replace `src/Client/DPayClient.php` below the namespace:

```php
/**
 * Client for DPay's three payment-session endpoints.
 *
 * HTTP plumbing lives in DPay\Http\Transport; this class owns endpoint
 * semantics only.
 *
 * Behaviours preserved from v0.1.0:
 *   - configurable min_amount enforcement
 *   - mock branch returning synthetic responses
 *   - verifySession returns null (not throws) on bad OTP / expired
 *
 * Changed in v0.2.0 to match https://dpay.ly/docs/api:
 *   - fractional amounts are ALLOWED (spec minimum is 0.01)
 *   - openSession takes an OpenSessionRequest and an optional Idempotency-Key
 */
class DPayClient implements DPayClientInterface
{
    public function __construct(
        private readonly DPayConfig $config,
        private readonly Transport $transport,
        private readonly ?MockTransport $mockTransport = null,
    ) {}

    public function openSession(OpenSessionRequest $request, ?string $idempotencyKey = null): OpenSessionResponse
    {
        if ($this->config->mock) {
            return ($this->mockTransport ?? new MockTransport())->openSession($request);
        }

        if ($request->amount < $this->config->minAmount) {
            throw new DPayValidationException(
                "Amount is below the minimum of {$this->config->minAmount}.",
                422,
            );
        }

        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];

        return OpenSessionResponse::fromArray(
            $this->transport->request('POST', '/payment/sessions/open', $request->toBody(), $headers),
        );
    }

    public function verifySession(int $sessionId, string $otp): ?VerifySessionResponse
    {
        if ($this->config->mock) {
            return ($this->mockTransport ?? new MockTransport())->verifySession($sessionId, $otp);
        }

        $body = $this->transport->attempt('POST', '/payment/sessions/verify', [
            'session_id' => $sessionId,
            'otp' => $otp,
        ]);

        return $body === null ? null : VerifySessionResponse::fromArray($body);
    }

    public function getSession(int $sessionId): GetSessionResponse
    {
        if ($this->config->mock) {
            return ($this->mockTransport ?? new MockTransport())->getSession($sessionId);
        }

        return GetSessionResponse::fromArray(
            $this->transport->request('GET', "/payment/sessions/{$sessionId}"),
        );
    }
}
```

Replace the imports block with:

```php
use DPay\Config\DPayConfig;
use DPay\Dto\GetSessionResponse;
use DPay\Dto\OpenSessionRequest;
use DPay\Dto\OpenSessionResponse;
use DPay\Dto\VerifySessionResponse;
use DPay\Exceptions\DPayValidationException;
use DPay\Http\Transport;
use DPay\Support\MockTransport;
```

- [ ] **Step 4: Update the interface**

In `src/Client/DPayClientInterface.php`, replace the `openSession` declaration:

```php
    /**
     * Open a payment session.
     *
     * @param  string|null  $idempotencyKey  Optional unique key. Replaying the
     *                                       same key returns the original
     *                                       session instead of opening a duplicate.
     */
    public function openSession(OpenSessionRequest $request, ?string $idempotencyKey = null): OpenSessionResponse;
```

Add `use DPay\Dto\OpenSessionRequest;` to its imports.

- [ ] **Step 5: Update the factory**

In `src/Client/DPayClientFactory.php`, replace the `return new DPayClient(...)` block:

```php
        return new DPayClient(
            config: $config,
            transport: new Transport(
                config: $config,
                httpClient: $httpClient,
                requestFactory: $requestFactory,
                streamFactory: $streamFactory,
                logger: $logger ?? new \Psr\Log\NullLogger(),
            ),
            mockTransport: $mockTransport,
        );
```

Add `use DPay\Http\Transport;` to its imports.

- [ ] **Step 6: Update all four remaining production call sites**

In `src/Laravel/Facades/DPayFacadeAccessor.php`:

```php
    public function openSession(\DPay\Dto\OpenSessionRequest $request, ?string $idempotencyKey = null): \DPay\Dto\OpenSessionResponse
    {
        return $this->client->openSession($request, $idempotencyKey);
    }
```

In `src/Laravel/Facades/DPay.php` — the `@method` docblock drives IDE completion and would otherwise advertise a signature that no longer exists. Replace the `openSession` line:

```php
 * @method static OpenSessionResponse          openSession(\DPay\Dto\OpenSessionRequest $request, ?string $idempotencyKey = null)
```

In `src/Providers/MoamalatProvider.php`, replace the `openSession` call inside `sendOtp()`:

```php
        $session = $this->client->openSession(new \DPay\Dto\OpenSessionRequest(
            payMethod: $this->payMethod,
            amount: $amount,
        ));
```

`src/Providers/AbstractDPayProvider.php` was already updated in Task 8.

- [ ] **Step 7: Delete the two tests that assert the removed behaviour**

These assert the old whole-number rule and the old default minimum. They are not "failing tests to repair" — the behaviour they lock in is the defect. Delete both from `tests/Unit/DPayClientTest.php`:

- `test_open_session_rejects_fractional_amount` (around line 83) — replaced by `test_fractional_amount_is_now_accepted` from Step 1
- `test_open_session_rejects_amount_below_minimum` (around line 90) — it passes `amount: 1`, which is now *above* the 0.01 default. Replaced by `test_amount_below_minimum_is_still_rejected`, which sets `minAmount: 5.0` explicitly

- [ ] **Step 8: Convert the remaining positional call sites**

Nine calls in `tests/Unit/DPayClientTest.php` (roughly lines 63, 103, 111, 120, 139, 151, 166, 240) and one in `tests/Feature/LaravelBridgeTest.php:65` still use positional arguments. Convert each to the DTO form, for example:

```php
// before
$resp = $this->client->openSession('edfali', 50, '0911234567');

// after
$resp = $this->client->openSession(
    new \DPay\Dto\OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
);
```

- [ ] **Step 9: Run the full suite and static analysis**

Run: `composer check`
Expected: PHPStan clean; PHPUnit green.

- [ ] **Step 8: Commit**

```bash
git add src tests
git commit -m "feat!: openSession takes a request DTO and optional Idempotency-Key

Drops the whole-number amount guard, which was an SDK invention rather
than a DPay requirement. Decimals down to the spec minimum of 0.01 now
reach the gateway unaltered."
```

> **Known limitation, deliberately not addressed here.** `sendOtp(float $amount, array $fields)`
> only forwards fields a provider *declares*, so a host using the provider layer
> cannot attach an optional `description` or free-form `data` — those are reachable
> only by calling `DPayClient::openSession()` directly. Widening the provider
> contract is a `PaymentProviderInterface` change affecting every implementor,
> including host-side wallet providers. Deferred to Plan 5 so it can be designed
> as one deliberate break rather than smuggled in here.

---

## Task 12: Align `MockTransport` with sandbox behaviour

**Files:**
- Modify: `src/Support/MockTransport.php`
- Test: `tests/Unit/MockTransportTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/MockTransportTest.php`:

```php
<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Dto\OpenSessionRequest;
use DPay\Support\MockTransport;
use PHPUnit\Framework\TestCase;

final class MockTransportTest extends TestCase
{
    public function test_decline_code_returns_null(): void
    {
        self::assertNull((new MockTransport())->verifySession(1, '000000'));
    }

    public function test_fixed_sandbox_otp_succeeds(): void
    {
        self::assertTrue((new MockTransport())->verifySession(1, '111111')?->isPaid());
    }

    public function test_non_numeric_otp_returns_null(): void
    {
        self::assertNull((new MockTransport())->verifySession(1, 'abcd'));
    }

    public function test_moamalat_expires_in_ten_minutes(): void
    {
        $response = (new MockTransport())->openSession(
            new OpenSessionRequest(payMethod: 'moamalat', amount: 50),
        );

        $minutes = (strtotime($response->expiredAt) - time()) / 60;

        self::assertEqualsWithDelta(10, $minutes, 1.0);
    }

    public function test_other_gateways_expire_in_fifteen_minutes(): void
    {
        $response = (new MockTransport())->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50),
        );

        $minutes = (strtotime($response->expiredAt) - time()) / 60;

        self::assertEqualsWithDelta(15, $minutes, 1.0);
    }

    public function test_decimal_amount_is_preserved(): void
    {
        $response = (new MockTransport())->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 10.5),
        );

        self::assertSame(10.5, $response->amount);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/MockTransportTest.php`
Expected: FAIL — `000000` currently returns a paid response; expiry is a flat 30 minutes

- [ ] **Step 3: Update MockTransport**

In `src/Support/MockTransport.php`, replace `verifySession()`:

```php
    public function verifySession(int $sessionId, string $otp): ?VerifySessionResponse
    {
        // Mirrors the sandbox: 000000 is a simulated decline.
        if ($otp === '000000' || ! preg_match('/^\d{4,6}$/', $otp)) {
            return null;
        }

        return VerifySessionResponse::fromArray([
            'message' => 'Payment verified successfully',
            'payment_id' => random_int(1, 99999),
            'status' => SessionStatus::PAID->value,
            'amount' => 0,
            'currency' => 'LYD',
            'pay_method' => 'mock',
            'tx_id' => 'txn_'.$this->randomString(10),
        ]);
    }
```

Replace `thirtyMinutesFromNow()` with a per-gateway helper:

```php
    /**
     * Session lifetimes documented at https://dpay.ly/docs/api:
     * 10 minutes for Moamalat and Sadad, 15 for everything else.
     */
    private function expiryFor(string $payMethod): string
    {
        $minutes = in_array($payMethod, ['moamalat', 'sadad'], true) ? 10 : 15;

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT'.$minutes.'M'))
            ->format(DateTimeImmutable::ATOM);
    }
```

In `openSession()`, change the expiry line to:

```php
            'expired_at' => $this->expiryFor($request->payMethod),
```

In `getSession()`, change it to:

```php
            'expired_at' => $this->expiryFor('mock'),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/MockTransportTest.php`
Expected: OK (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/MockTransport.php tests/Unit/MockTransportTest.php
git commit -m "feat: mock mirrors sandbox decline code and per-gateway expiry"
```

---

## Task 13: Rebuild the sandbox probe as a resumable per-provider harness

The v0.1.0 probe was rate-limited mid-run — three 429s, two providers skipped.

**Files:**
- Create: `tests/sandbox/Scenarios.php`, `tests/sandbox/ProbeRunner.php`
- Modify: `tests/sandbox/probe.php`
- Delete: `tests/sandbox/probe-remaining.php`

- [ ] **Step 1: Create the scenario definitions**

Create `tests/sandbox/Scenarios.php`:

```php
<?php

declare(strict_types=1);

/**
 * Per-provider sandbox scenarios.
 *
 * Test data from the DPay dashboard. Fixed OTP 111111 across all gateways;
 * 000000 simulates a decline. No token appears here — it is read from
 * DPAY_API_KEY at runtime.
 *
 * @return array<string, array{pay_method:string, fields:array<string,mixed>, proves:string}>
 */
function dpay_scenarios(): array
{
    return [
        'edfali' => [
            'pay_method' => 'edfali',
            'fields' => ['phone_number' => '0912345678'],
            'proves' => 'decimal amount round-trip + Idempotency-Key replay',
        ],
        'mobicash' => [
            'pay_method' => 'mobicash',
            'fields' => ['card_number' => '7279627', 'description' => 'Order #1234'],
            'proves' => 'description lands top-level, not under data',
        ],
        'masrefypay' => [
            'pay_method' => 'masrefypay',
            'fields' => ['card_number' => '1234567'],
            'proves' => 'same-bank 7-digit card',
        ],
        'masrefypay-crossbank' => [
            'pay_method' => 'masrefypay',
            'fields' => ['card_number' => '111234567'],
            'proves' => '9-digit OnePay card is accepted',
        ],
        'yousrpay' => [
            'pay_method' => 'yousrpay',
            'fields' => ['card_number' => '1234567'],
            'proves' => 'same-bank 7-digit card',
        ],
        'yousrpay-crossbank' => [
            'pay_method' => 'yousrpay',
            'fields' => ['card_number' => '331234567'],
            'proves' => '9-digit OnePay card, prefix 33',
        ],
        'saharapay' => [
            'pay_method' => 'saharapay',
            'fields' => ['card_number' => '1234567'],
            'proves' => 'same-bank 7-digit card',
        ],
        'saharapay-crossbank' => [
            'pay_method' => 'saharapay',
            'fields' => ['card_number' => '661234567'],
            'proves' => '9-digit OnePay card, prefix 66',
        ],
        'moamalat' => [
            'pay_method' => 'moamalat',
            'fields' => [],
            'proves' => 'payment_link returned for the LightBox',
        ],
        'sadad' => [
            'pay_method' => 'sadad',
            'fields' => ['phone_number' => '0912345678', 'birth_year' => '1994', 'category' => 20],
            'proves' => 'BLOCKED: no published sandbox test wallet',
        ],
    ];
}
```

- [ ] **Step 2: Create the runner**

Create `tests/sandbox/ProbeRunner.php`:

```php
<?php

declare(strict_types=1);

use DPay\Exceptions\DPayRateLimitException;

/**
 * Paced, resumable runner for the sandbox scenarios.
 *
 * The sandbox throttles at roughly four rapid calls, so every call is spaced
 * and 429s are retried with exponential backoff. Results are appended to a
 * ledger so an interrupted run resumes instead of restarting.
 */
final class ProbeRunner
{
    private const LEDGER = __DIR__.'/.probe-ledger.json';

    public function __construct(
        private readonly float $spacingSeconds = 3.0,
        private readonly int $maxRetries = 4,
    ) {}

    /** @return array<string, mixed> */
    public function ledger(): array
    {
        if (! is_file(self::LEDGER)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents(self::LEDGER), true);

        return is_array($raw) ? $raw : [];
    }

    public function record(string $scenario, string $status, string $detail): void
    {
        $ledger = $this->ledger();
        $ledger[$scenario] = ['status' => $status, 'detail' => $this->scrub($detail)];

        file_put_contents(self::LEDGER, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function isDone(string $scenario): bool
    {
        return ($this->ledger()[$scenario]['status'] ?? null) === 'pass';
    }

    /**
     * Run a call, pacing it and backing off on 429.
     *
     * @template T
     * @param  callable():T  $call
     * @return T
     */
    public function paced(callable $call): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            usleep((int) ($this->spacingSeconds * 1_000_000));

            try {
                return $call();
            } catch (DPayRateLimitException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }

                $wait = (2 ** $attempt) * 5;
                fwrite(STDERR, "  429 — backing off {$wait}s\n");
                sleep($wait);
            }
        }
    }

    /**
     * Strip anything resembling an API token before it reaches disk or stdout.
     */
    public function scrub(string $text): string
    {
        return (string) preg_replace('/sb_tk_[A-Za-z0-9]+/', 'sb_tk_[REDACTED]', $text);
    }

    public function writeReport(string $path): void
    {
        $lines = ['# Sandbox Validation Report', '', '| Scenario | Status | Detail |', '|---|---|---|'];

        foreach ($this->ledger() as $scenario => $result) {
            $lines[] = sprintf('| `%s` | %s | %s |', $scenario, $result['status'], $result['detail']);
        }

        file_put_contents($path, implode("\n", $lines)."\n");
    }
}
```

- [ ] **Step 3: Rewrite the entry point**

Replace `tests/sandbox/probe.php` with:

```php
<?php

declare(strict_types=1);

/**
 * Live sandbox probe.
 *
 * Usage:
 *   DPAY_API_KEY=... php tests/sandbox/probe.php               # all scenarios
 *   DPAY_API_KEY=... php tests/sandbox/probe.php --provider=edfali
 *   DPAY_API_KEY=... php tests/sandbox/probe.php --reset       # clear the ledger
 *
 * Resumable: passing scenarios are skipped on re-run. The token is read from
 * the environment and scrubbed from all output.
 */

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/Scenarios.php';
require __DIR__.'/ProbeRunner.php';

use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\Dto\OpenSessionRequest;
use DPay\Exceptions\DPayExceptionInterface;

$apiKey = getenv('DPAY_API_KEY');

if (! is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "DPAY_API_KEY is not set. Export it before running this probe.\n");
    exit(1);
}

$options = getopt('', ['provider::', 'reset']);

if (isset($options['reset']) && is_file(__DIR__.'/.probe-ledger.json')) {
    unlink(__DIR__.'/.probe-ledger.json');
    echo "Ledger cleared.\n";
}

$client = DPayClientFactory::create(new DPayConfig(
    baseUrl: getenv('DPAY_BASE_URL') ?: 'https://dpay.ly/api/sandbox',
    apiKey: $apiKey,
    timeout: 30,
    mock: false,
    minAmount: 0.01,
));

$runner = new ProbeRunner();
$only = $options['provider'] ?? null;
$otp = '111111';

foreach (dpay_scenarios() as $name => $scenario) {
    if (is_string($only) && $only !== '' && $name !== $only) {
        continue;
    }

    if ($runner->isDone($name)) {
        echo "[skip] {$name} — already passed\n";
        continue;
    }

    echo "[run ] {$name} — {$scenario['proves']}\n";

    try {
        // Decimal amount proves the truncation fix against the real gateway.
        $session = $runner->paced(static fn () => $client->openSession(new OpenSessionRequest(
            payMethod: $scenario['pay_method'],
            amount: 10.5,
            customerMobile: $scenario['fields']['phone_number'] ?? null,
            cardNumber: $scenario['fields']['card_number'] ?? null,
            birthYear: $scenario['fields']['birth_year'] ?? null,
            category: $scenario['fields']['category'] ?? null,
            description: $scenario['fields']['description'] ?? null,
        )));

        if ($session->amount !== 10.5) {
            $runner->record($name, 'fail', "amount came back as {$session->amount}, expected 10.5");
            continue;
        }

        if ($scenario['pay_method'] === 'moamalat') {
            $detail = $session->paymentLink === null
                ? 'no payment_link returned'
                : 'payment_link present';

            $runner->record($name, $session->paymentLink === null ? 'fail' : 'pass', $detail);
            continue;
        }

        $paid = $runner->paced(static fn () => $client->verifySession($session->sessionId, $otp));

        $runner->record(
            $name,
            $paid?->isPaid() === true ? 'pass' : 'fail',
            $paid?->isPaid() === true
                ? "session {$session->sessionId} paid, amount 10.5 preserved"
                : 'verify did not return paid',
        );
    } catch (DPayExceptionInterface $e) {
        $runner->record($name, 'fail', $e::class.': '.$e->getMessage());
    }
}

$runner->writeReport(__DIR__.'/../../SANDBOX-VALIDATION.md');

echo "\nLedger:\n";
foreach ($runner->ledger() as $name => $result) {
    printf("  %-24s %s — %s\n", $name, strtoupper($result['status']), $result['detail']);
}
```

- [ ] **Step 4: Delete the superseded script**

```bash
git rm tests/sandbox/probe-remaining.php tests/sandbox/probe-output.log
```

- [ ] **Step 5: Verify it refuses to run without a token**

Run: `php tests/sandbox/probe.php`
Expected: exits 1 with `DPAY_API_KEY is not set.` — and makes no network call.

- [ ] **Step 6: Commit**

```bash
git add tests/sandbox
git commit -m "test: rebuild sandbox probe as a paced, resumable per-provider harness"
```

---

## Task 14: Live sandbox gate

**Prerequisite:** `DPAY_API_KEY` must be readable by the shell running this.

- [ ] **Step 1: Confirm the offline gate is green**

Run: `composer check`
Expected: PHPStan clean, all tests pass. **Do not proceed to live calls until this is green.**

- [ ] **Step 2: Confirm the sandbox covers the merchant endpoints**

Run: `curl -sS -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $DPAY_API_KEY" -H 'Accept: application/json' https://dpay.ly/api/sandbox/pay-methods`

Expected: `200`. Record the result — it decides whether Plan 4 runs invoice writes against the sandbox or keeps them mocked. A `404` means the sandbox covers payment sessions only.

- [ ] **Step 3: Run each provider individually**

```bash
php tests/sandbox/probe.php --provider=edfali
php tests/sandbox/probe.php --provider=mobicash
php tests/sandbox/probe.php --provider=masrefypay
php tests/sandbox/probe.php --provider=masrefypay-crossbank
php tests/sandbox/probe.php --provider=yousrpay
php tests/sandbox/probe.php --provider=yousrpay-crossbank
php tests/sandbox/probe.php --provider=saharapay
php tests/sandbox/probe.php --provider=saharapay-crossbank
php tests/sandbox/probe.php --provider=moamalat
```

Expected per provider: `PASS`. The three `-crossbank` scenarios are the proof that the `digits:7` defect is fixed; `mobicash` proves `description` is top-level; every scenario proves the decimal amount survives.

- [ ] **Step 4: Record the Sadad result honestly**

Run: `php tests/sandbox/probe.php --provider=sadad`
Expected: likely `FAIL` with `500 Unsupported payment method` or a validation error. **Record it as-is.** Do not delete the scenario or mark it skipped — the ledger showing a real blocked state is the point.

- [ ] **Step 5: Verify no token leaked into the generated report**

Run: `grep -c 'sb_tk_[A-Za-z0-9]' SANDBOX-VALIDATION.md || echo "CLEAN"`
Expected: `CLEAN`, or a count of `0`.

- [ ] **Step 6: Commit the regenerated report**

```bash
git add SANDBOX-VALIDATION.md
git commit -m "test: regenerate sandbox validation report from live probe run"
```

---

## Definition of done

- [ ] `composer check` green
- [ ] All eight golden bodies match the Postman collection byte-for-byte
- [ ] A decimal amount reaches the sandbox unaltered, proven live
- [ ] A 9-digit OnePay card opens a session on all three bank gateways, proven live
- [ ] `description` arrives top-level, proven live
- [ ] `Idempotency-Key` replay returns the original `session_id`
- [ ] Sadad recorded as blocked with its actual error, not skipped
- [ ] No `sb_tk_` string anywhere in tracked files
