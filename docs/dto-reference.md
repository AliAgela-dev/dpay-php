# DTO reference

Every public field on every DTO and value object the SDK exposes.

> All DTOs are **immutable** (`readonly` properties). All carry a `raw`
> array of the original decoded JSON so you can read fields the SDK
> didn't bother mapping (DPay may add fields in future).

---

## `DPay\Config\DPayConfig`

The single config object passed into `DPayClient`. Immutable.

| Property | Type | Default | Notes |
|---|---|---|---|
| `baseUrl` | `string` | `https://dpay.ly/api` | No trailing slash needed; client strips it. |
| `apiKey` | `string` | `''` | Used as `Authorization: Bearer <key>`. |
| `timeout` | `int` | `15` | Seconds. Must be ≥ 1. |
| `mock` | `bool` | `false` | When true, no HTTP traffic happens. |
| `minAmount` | `float` | `0.01` | Anything below throws `DPayValidationException`. Must be ≥ 0. Matches DPay's documented minimum. |

**Factory:** `DPayConfig::fromArray($cfg)` — builds from an associative
array with snake_case keys (`base_url`, `api_key`, `min_amount`, etc.).
The Laravel bridge uses this to convert `config('dpay')` into a config
object.

---

## `DPay\Dto\OpenSessionRequest`

What you'd pass conceptually to `openSession`. The client builds this
internally; you usually won't construct it yourself unless you're writing
a custom provider.

| Property | Type | Notes |
|---|---|---|
| `payMethod` | `string` | The exact DPay string (e.g. `edfali`). |
| `amount` | `float` | LYD, decimals allowed (e.g. `10.50`). Only enforced floor is `DPayConfig::$minAmount`, checked by `DPayClient`, not here. |
| `customerMobile` | `?string` | Required by phone-OTP providers. |
| `cardNumber` | `?string` | Required by card-OTP providers. |
| `birthYear` | `?string` | Sadad only. 4 digits, cross-checked against the wallet registration record. |
| `category` | `?int` | Sadad only, optional. 0–36. Zero is meaningful — never filtered as falsy. |
| `description` | `?string` | Optional. Sent as a **top-level** field (matches MobiCash's documented shape), not nested under `data`. |
| `data` | `array<string, mixed>` | Optional free-form merchant metadata, echoed back in webhooks. Independent of `description`. **Not exclusively yours on the way back:** DPay merges its own keys into it. Every payment gets `fee_amount`, `fee_percent` and `original_amount` (verified live 2026-08-16); a `payment.refunded` also carries `refund_amount` and `refund_reference`, and a `payment.voided` carries `void_reference` (per the official Postman collection). Your own keys survive alongside them, but reusing any of those six names means your value is silently replaced. |

**Method:** `toBody(): array` — builds the JSON body, stripping null fields
(and dropping `data` entirely when it's an empty array).

---

## `DPay\Dto\OpenSessionResponse`

Returned by `openSession()`.

| Property | Type | Notes |
|---|---|---|
| `sessionId` | `int` | Use as the persisted reference. |
| `status` | `SessionStatus` | Usually `PENDING` on open. |
| `amount` | `float` | Echoed back from DPay, unrounded. **Not what finally settles** — see the note below. |
| `currency` | `string` | Always `LYD` in practice. |
| `fee` | `float` | Fee percentage. |
| `feeAmount` | `float` | Absolute fee in LYD. |
| `total` | `float` | `amount + feeAmount`. Still not the settled figure. |
| `payMethod` | `string` | Echoed back. |
| `expiredAt` | `string` | ISO-8601. 10 minutes from open for Moamalat and Sadad, 15 for everything else — verified live for Moamalat on 2026-08-16. (An earlier revision of this table said ~30 minutes; that was wrong.) |

> **None of `amount`, `fee`, `feeAmount` or `total` is the amount that
> finally settles.** DPay settles at `round(total)` to the nearest whole
> LYD, half up, and only at payment time — so a session opened for `10.49`
> with a `0.02` fee (`total` `10.51`) settles at `11`, while `10.01`
> (`total` `10.03`) settles at `10`. Read the settled value from
> `getSession()` or the `payment.paid` webhook; your original is preserved
> as `data.original_amount`. See
> [troubleshooting.md](troubleshooting.md#the-settled-amount-doesnt-match-what-i-asked-for).
| `data` | `mixed` | Provider-specific payload (`null` for most). |
| `paymentLink` | `?string` | Set by providers that use redirect/Lightbox (Moamalat). |
| `raw` | `array` | The full decoded JSON, for fields not mapped above. |

**Methods:** `toArray()` — for serializing to JSON.

---

## `DPay\Dto\VerifySessionResponse`

Returned by `verifySession()` **on success only.** `null` is returned for
bad OTP / expired / not-found.

| Property | Type | Notes |
|---|---|---|
| `message` | `string` | DPay's human-readable success message. |
| `paymentId` | `int` | DPay's internal payment ID (different from `sessionId`). |
| `status` | `SessionStatus` | Should be `PAID` if you got the object at all. |
| `amount` | `float` | Settled amount — the rounded figure, see the note under `OpenSessionResponse`. |
| `currency` | `string` | DPay does **not** send this at the top level; it is read from the nested `payment` object, falling back to `LYD`. |
| `payMethod` | `string` | |
| `txId` | `string` | DPay transaction ID — what you log / display to the user. |
| `receiptUrl` | `?string` | Hosted receipt, when DPay issues one. |
| `payment` | `?Payment` | The nested payment record — see [`Payment`](#daypaydtopayment) below. Null when DPay omits it. |
| `raw` | `array` | |

**Methods:** `isPaid(): bool`, `toArray(): array`.

---

## `DPay\Dto\GetSessionResponse`

Returned by `getSession()`. Used by Moamalat for status polling.

| Property | Type | Notes |
|---|---|---|
| `sessionId` | `int` | |
| `status` | `SessionStatus` | The lifecycle stage. |
| `amount` | `float` | The **settled** figure once paid — DPay's rounded value, not what you requested. Your original is in `data.original_amount`. |
| `currency` | `string` | DPay does not return this; it defaults to `LYD`. |
| `payMethod` | `string` | |
| `expiredAt` | `string` | ISO-8601. 10 minutes from open for Moamalat/Sadad, 15 otherwise. |
| `txId` | `string` | Gateway transaction reference — what you reconcile against. Absent from the spec's minimal example but present in **every** live response. `''` when absent. |
| `paymentLink` | `?string` | Moamalat's hosted payment page; how that flow is resumed. Null on other gateways. |
| `data` | `mixed` | Your merchant metadata, **with DPay's `fee_amount` / `fee_percent` / `original_amount` merged in**. |
| `raw` | `array` | |

**Methods:** `isPaid(): bool`, `toArray(): array`.

---

## `DPay\Dto\Payment`

The nested `payment` object on a verify response, exposed as
`VerifySessionResponse::$payment`. **This is where the card-rail
reconciliation fields live** — previously reachable only through `->raw`.

| Property | Type | Notes |
|---|---|---|
| `id` | `int` | DPay's payment record ID. |
| `paymentSessionId` | `int` | Links back to the session. |
| `amount` | `float` | |
| `currency` | `string` | Defaults to `LYD`. |
| `status` | `SessionStatus` | The payment record's own status. DPay uses a **different vocabulary** here (`completed`), so this degrades to `UNKNOWN` — read `VerifySessionResponse::$status` for the session's lifecycle stage. |
| `payMethod` | `string` | |
| `txId` | `string` | |
| `systemReference` | `?string` | |
| `networkReference` | `?string` | |
| `paidThrough` | `?string` | Card scheme, e.g. `Visa`. |
| `payerAccount` | `?string` | Masked account, e.g. `****1234`. |
| `createdAt` | `string` | |
| `userId` / `companyId` | `?int` | DPay-internal. |
| `raw` | `array` | |

> The four reference fields are `?string` deliberately. Every sandbox
> delivery we captured had them null, and the official examples show them
> populated only on Moamalat (card) payments. **Wallet and bank gateways
> leaving them null is correct behaviour, not a gap** — but note that means
> the populated case has never been verified first-hand.

## `DPay\Dto\PayMethod`

One entry from `GET /pay-methods`, returned by
[`PayMethodsClient`](#daypay-clientpaymethodsclient). DPay documents this as
*"all active payment methods with your merchant-specific fee overrides
applied"* — so every numeric field here is **per-merchant**, configured from
DPay's dashboard, and cannot be hardcoded by any SDK.

| Property | Type | Notes |
|---|---|---|
| `name` | `string` | Display name, e.g. `EDFali`. |
| `slug` | `string` | The `pay_method` value, e.g. `edfali`. Entries without one are dropped. |
| `active` | `bool` | Whether DPay has this gateway enabled for your account. |
| `fee` | `float` | **A percentage**, e.g. `2.5` for 2.5% — not an absolute amount. Same meaning as `OpenSessionResponse::$fee`. |
| `minDeposit` | `float` | Minimum accepted amount. Normalised to float; DPay sends it as a JSON integer. |
| `maxDeposit` | `float` | Maximum accepted amount. |
| `logoUrl` | `?string` | Absolute URL to the gateway's SVG. Upstream-authoritative — prefer it over the bundled logos. |
| `icon` | `?string` | Deprecated upstream in favour of `logoUrl`; still sent, so still mapped. |
| `raw` | `array` | The untouched entry. |

**Methods:** `static fromArray(array): self` · `toArray(): array`.

Absent or non-scalar values degrade rather than throw — a missing `name`
becomes `''`, a missing limit becomes `0.0`.

## `DPay\Client\PayMethodsClient`

```php
$methods = $payMethods->list();          // array<string, PayMethod>, keyed by slug
$edfali  = $payMethods->find('edfali');  // ?PayMethod — null if DPay doesn't list it
$payMethods->refresh();                  // drop the memoised list
```

**The list is fetched once and memoised for the instance's lifetime.** The
values change only when someone edits the dashboard, and the alternative is
a network round-trip per lookup. In Laravel it's bound as a singleton for
the same reason — `DPay::payMethods()`.

Errors are **not** swallowed here: a failed lookup throws, exactly like any
other client call. Whether that should block a payment is a policy decision,
and it lives in `DPayClient` (which fails open) rather than being hidden in
this class.

`find()` returning `null` is not an error — it means DPay doesn't list that
gateway for your account, which is precisely what Sadad looks like until
DPay enables it.

## `DPay\Dto\SessionStatus` (enum)

Lifecycle states. Backed by lowercase strings.

| Case | Value | Notes |
|---|---|---|
| `PENDING` | `pending` | Open, awaiting user action. |
| `PAID` | `paid` | Settled. The only "good" terminal state. |
| `FAILED` | `failed` | User explicitly failed (3DS denied, etc.). |
| `EXPIRED` | `expired` | TTL hit before user completed. |
| `REFUNDED` | `refunded` | Reverses an already-settled charge. Moamalat-only; triggered from DPay's dashboard, observed via the `payment.refunded` webhook. |
| `VOIDED` | `voided` | Cancels an authorization before capture, returning the hold without ever settling. Moamalat-only; observed via the `payment.voided` webhook. Not interchangeable with `REFUNDED`. |
| `UNKNOWN` | `unknown` | Fallback for any string DPay returns that we don't recognize. |

**Methods:**
- `SessionStatus::fromString(?string): self` — never throws; falls back to `UNKNOWN`.
- `$status->isTerminal(): bool` — true for PAID / FAILED / EXPIRED / REFUNDED / VOIDED.

---

## `DPay\Dto\PaymentField`

Describes one input the provider expects in `sendOtp`'s `$fields`.

| Property | Type | Notes |
|---|---|---|
| `key` | `string` | The array key in `sendOtp()`'s `$fields` — e.g. `phone_number`, `card_number`. |
| `type` | `string` | One of `string` / `integer` / `numeric` / `boolean` / `date`. |
| `required` | `bool` | False makes the field nullable. |
| `regex` | `?string` | PCRE with delimiters, e.g. `/^09\d{8}$/`. |
| `digits` | `?int` | Exact digit count. Cannot be combined with `digitsOneOf` — the constructor throws `InvalidArgumentException` if both are set. |
| `digitsOneOf` | `?list<int>` | Several valid exact-length counts, e.g. `[7, 9]` for bank cards accepting same-bank (7) or cross-bank OnePay (9). Cannot be combined with `digits`. |
| `labels` | `array<string,string>` | `['en' => '...', 'ar' => '...']`. |
| `placeholders` | `array<string,string>` | Same shape. |
| `inputType` | `string` | HTML5 `<input type=...>`. `tel`, `number`, `text`, etc. |
| `sendAs` | `?string` | The wire field name sent to DPay, if different from `key`. Defaults to `key` when null — read via `wireName()`, never this property directly. |

**Named constructors:**
- `PaymentField::phoneNumber($key = 'phone_number', $regex = '/^09\d{8}$/', $sendAs = 'customer_mobile'): self`
- `PaymentField::cardNumber(int $digits = 7, $key = 'card_number'): self` — exact digit count, `sendAs` defaults to `key` (`card_number`).
- `PaymentField::bankCardNumber($key = 'card_number'): self` — `digitsOneOf: [7, 9]`, for MasrefyPay/YousrPay/SaharaPay.
- `PaymentField::birthYear($key = 'birth_year'): self` — Sadad only, 4 digits.
- `PaymentField::sadadCategory($key = 'category'): self` — Sadad only, optional, `type: 'integer'`.

**Methods:**
- `wireName(): string` — the resolved wire field name (`sendAs ?? key`). Use
  this, not `$sendAs` directly, when you need the concrete name.
- `label(string $locale = 'en'): string` — with fallback to `en` then `key`.
- `placeholder(string $locale = 'en'): string` — same fallback chain.
- `toArray(): array` — for serializing to JSON. **Includes all keys**, even
  null ones (JSON key `digits_one_of` for `digitsOneOf`, `send_as` for the
  *resolved* wire name via `wireName()` — not the raw nullable property), so
  JSON consumers don't have to check for absence vs. null.
- `static fromArray(array $a): self` — for hydrating from config / DB. Casts
  `digits_one_of` string values to int (config/JSON deliver strings).

---

## Exceptions — quick reference

Most inherit from `DPay\Exceptions\DPayException` (a `RuntimeException`).
`UnknownProviderException` doesn't — it extends `InvalidArgumentException`
instead, to match `GatewayManager`'s existing contract. Every SDK exception,
across both branches, implements the `DPayExceptionInterface` marker —
`catch (DPayExceptionInterface)` covers all of them in one block.

| Class | When |
|---|---|
| `DPayValidationException` | Any 4xx other than 401/403/404/429. Includes our pre-flight check (below `minAmount`). |
| `DPayAuthException` | 401 / 403. |
| `DPaySessionNotFoundException` | 404 on `getSession`. |
| `DPayRateLimitException` | 429. The sandbox trips this aggressively — even 4–5 requests in quick succession. |
| `DPayNetworkException` | PSR-18 transport failure. Original exception is in `->getPrevious()`. |
| `UnknownProviderException` | `GatewayManager::provider($code)` for an unknown or disabled code. Extends `InvalidArgumentException`, NOT `DPayException` — but does implement `DPayExceptionInterface`. |
| `InvalidWebhookException` | Base class for webhook verification failures (below). Never put the expected signature or the secret in its message — the request that triggers it is attacker-controlled. |
| `WebhookSignatureMismatchException` | `WebhookVerifier::verify()` — computed HMAC didn't match `X-DPAY-Signature`. Extends `InvalidWebhookException`. |
| `WebhookTimestampExpiredException` | `WebhookVerifier::verify()` — `X-DPAY-Timestamp` more than 5 minutes from now, either direction. Extends `InvalidWebhookException`. |

Every `DPayException` carries:
- `->getMessage()` — human-readable
- `->httpStatus` (`int`) — the HTTP status code (0 for pre-flight / transport errors)
- `->errors` (`?array`) — field-level errors if DPay supplied them

See [webhooks.md](webhooks.md) for the webhook exception hierarchy in
context.
