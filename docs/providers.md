# Provider reference cards

One card per provider. For every gateway you'll find: the `pay_method`
string DPay expects, the request body shape we send, what comes back, the
default `requiredFields()` schema, and gotchas.

All seven DPay-backed providers share the same surface — `sendOtp(amount, $fields)
→ reference` and `verifyOtp(reference, otp) → bool` — but they differ in
which fields they read and (for Moamalat) how verification works.

> **Sadad is disabled by default.** DPay's sandbox rejects it with
> `"Unsupported payment method: sadad"` until the gateway is enabled on
> your merchant account — confirm with DPay before flipping
> `PAYMENT_GATEWAY_SADAD_ENABLED=true`. **Yaser** is not shipped — it
> does not appear in the official DPay spec at all.

---

## Edfali

| | |
|---|---|
| Class | `DPay\Providers\EdfaliProvider` |
| `code` | `edfali` |
| `displayName` | `Edfali` |
| `pay_method` (DPay) | `edfali` |
| `requiresOtp` | true |
| `supportsStatusCheck` | false |
| `supportsRefund` / `supportsWebhook` | false / false |

**`requiredFields()` default:**
```php
[
  PaymentField::phoneNumber()
  // key=phone_number, regex=/^09\d{8}$/, type=tel, en+ar labels
]
```

**Request body to DPay** (POST `/payment/sessions/open`):
```json
{ "pay_method": "edfali", "amount": 50, "customer_mobile": "0911234567" }
```

**Successful verify** returns `{ status: "paid", tx_id: "...", payment_id: ... }`.

---

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

---

## MobiCash

| | |
|---|---|
| Class | `DPay\Providers\MobiCashProvider` |
| `code` | `mobicash` |
| `pay_method` | `mobicash` |
| `requiresOtp` | true |
| `supportsStatusCheck` | false |

**`requiredFields()` default:**
```php
[ PaymentField::cardNumber(digits: 7) ]
```

**Request body to DPay:**
```json
{ "pay_method": "mobicash", "amount": 50, "card_number": "1234567" }
```

> **Gotcha:** MobiCash does NOT send `customer_mobile`. Only `card_number`.
> If you put a `phone_number` field in the schema (or pass it in `$fields`),
> it's silently ignored unless you also add the `customer_mobile` mapping
> manually.

---

## SaharaPay

| | |
|---|---|
| Class | `DPay\Providers\SaharaPayProvider` |
| `code` | `saharapay` |
| `pay_method` | `saharapay` |
| `requiresOtp` | true |
| **`supportsStatusCheck`** | **true** |

Card-based OTP, **with status-check support**. After `sendOtp` you can call
`DPay::getSession($reference)` to inspect the live status instead of (or in
addition to) verifying with an OTP.

Default field schema: `[cardNumber(digits: 7)]`.

---

## YousrPay

| | |
|---|---|
| Class | `DPay\Providers\YousrPayProvider` |
| `code` | `yousrpay` |
| `pay_method` | `yousrpay` |
| `requiresOtp` | true |
| **`supportsStatusCheck`** | **true** |

Same shape as SaharaPay. Card-based OTP with status check. Default schema
`[cardNumber(digits: 7)]`.

---

## MasrefyPay

| | |
|---|---|
| Class | `DPay\Providers\MasrefyPayProvider` |
| `code` | `masrefypay` |
| `pay_method` | `masrefypay` |
| `requiresOtp` | true |
| **`supportsStatusCheck`** | **true** |

Same shape as SaharaPay / YousrPay. Default schema `[cardNumber(digits: 7)]`.

---

## Moamalat — the odd one out

| | |
|---|---|
| Class | `DPay\Providers\MoamalatProvider` |
| `code` | `moamalat` |
| `pay_method` | `moamalat` |
| **`requiresOtp`** | **false** |
| `supportsStatusCheck` | true |

Moamalat uses a redirect / Lightbox flow, not OTP. The `verifyOtp(ref, otp)`
method ignores its OTP argument and **polls `getSession`** instead, returning
`true` once the status is `paid`.

**`requiredFields()` default:** `[]`.

**Request body to DPay:**
```json
{ "pay_method": "moamalat", "amount": 50 }
```
No `customer_mobile`, no `card_number`. The user pays via the
`payment_link` in the response.

> **Gotcha:** The original health-portal seeder includes a 16-digit
> `card_number` field for Moamalat. The provider code never reads that
> field — it's a leftover from a previous integration approach. We
> intentionally default to `[]`. If your front-end still collects a card
> for UX, set it via config:
> ```php
> 'moamalat' => [
>     'required_fields' => [
>         ['key' => 'card_number', 'digits' => 16, 'input_type' => 'number'],
>     ],
> ],
> ```

> **Gotcha #2:** "Verifying" Moamalat needs you to poll until the user
> finishes in the Lightbox. See
> [checkout-flow.md § Polling Moamalat](checkout-flow.md#polling-moamalat).

---

## Cheat sheet — fields by provider

| Provider | Default schema | Body field sent to DPay |
|---|---|---|
| `edfali`     | `phone_number` (regex `/^09\d{8}$/`) | `customer_mobile` |
| `mobicash`   | `card_number` (`digits:7`)           | `card_number`     |
| `saharapay`  | `card_number` (`digits:7`)           | `card_number`     |
| `yousrpay`   | `card_number` (`digits:7`)           | `card_number`     |
| `masrefypay` | `card_number` (`digits:7`)           | `card_number`     |
| `sadad`      | `phone_number`, `birth_year`, `category` (optional) | `customer_mobile`, `birth_year`, `category` |
| `moamalat`   | _(empty)_                            | _(none)_          |

Mapping rule (in `AbstractDPayProvider::sendOtp`): each declared field is
forwarded under its `PaymentField::wireName()` (the `sendAs` override,
defaulting to `key`) — but only if that wire name is one
`OpenSessionRequest` knows about: `customer_mobile`, `card_number`,
`birth_year`, `category`, or `description`. That's how `phone_number` →
`customer_mobile`, `card_number` → `card_number`, and Sadad's `birth_year`
/ `category` all reach DPay with zero base-class changes. A field whose
wire name isn't in that set is **silently dropped** — collect it
client-side if you want, but it won't reach DPay unless you add the field
to `OpenSessionRequest` or write a custom provider that calls
`DPayClient` directly.
