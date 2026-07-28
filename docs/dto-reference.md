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
| `data` | `array<string, mixed>` | Optional free-form merchant metadata, echoed back in webhooks. Independent of `description`. |

**Method:** `toBody(): array` — builds the JSON body, stripping null fields
(and dropping `data` entirely when it's an empty array).

---

## `DPay\Dto\OpenSessionResponse`

Returned by `openSession()`.

| Property | Type | Notes |
|---|---|---|
| `sessionId` | `int` | Use as the persisted reference. |
| `status` | `SessionStatus` | Usually `PENDING` on open. |
| `amount` | `float` | Echoed back from DPay. |
| `currency` | `string` | Always `LYD` in practice. |
| `fee` | `float` | Fee percentage. |
| `feeAmount` | `float` | Absolute fee in LYD. |
| `total` | `float` | `amount + feeAmount`. |
| `payMethod` | `string` | Echoed back. |
| `expiredAt` | `string` | ISO-8601, default ~30 min from open. |
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
| `amount` | `float` | Settled amount. |
| `currency` | `string` | |
| `payMethod` | `string` | |
| `txId` | `string` | DPay transaction ID — what you log / display to the user. |
| `raw` | `array` | |

**Methods:** `isPaid(): bool`, `toArray(): array`.

---

## `DPay\Dto\GetSessionResponse`

Returned by `getSession()`. Used by Moamalat for status polling.

| Property | Type | Notes |
|---|---|---|
| `sessionId` | `int` | |
| `status` | `SessionStatus` | The lifecycle stage. |
| `amount` | `float` | |
| `currency` | `string` | |
| `payMethod` | `string` | |
| `expiredAt` | `string` | |
| `data` | `mixed` | |
| `raw` | `array` | |

**Methods:** `isPaid(): bool`, `toArray(): array`.

---

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
