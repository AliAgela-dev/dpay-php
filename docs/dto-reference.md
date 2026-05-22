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
| `minAmount` | `int` | `5` | Anything below throws `DPayValidationException`. Must be ≥ 0. |

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
| `amount` | `float` | Whole-number LYD. Fractional → `DPayValidationException`. |
| `customerMobile` | `?string` | Required by phone-OTP providers. |
| `cardNumber` | `?string` | Required by card-OTP providers. |
| `description` | `?string` | Optional. Sent as `data.description`. |

**Method:** `toBody(): array` — builds the JSON body, stripping nulls.

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
| `REFUNDED` | `refunded` | Reserved — no provider supports refunds yet. |
| `UNKNOWN` | `unknown` | Fallback for any string DPay returns that we don't recognize. |

**Methods:**
- `SessionStatus::fromString(?string): self` — never throws; falls back to `UNKNOWN`.
- `$status->isTerminal(): bool` — true for PAID / FAILED / EXPIRED / REFUNDED.

---

## `DPay\Dto\PaymentField`

Describes one input the provider expects in `sendOtp`'s `$fields`.

| Property | Type | Notes |
|---|---|---|
| `key` | `string` | The array key — e.g. `phone_number`, `card_number`. |
| `type` | `string` | One of `string` / `integer` / `numeric` / `boolean` / `date`. |
| `required` | `bool` | False makes the field nullable. |
| `regex` | `?string` | PCRE with delimiters, e.g. `/^09\d{8}$/`. |
| `digits` | `?int` | Exact digit count (alternative to regex). |
| `labels` | `array<string,string>` | `['en' => '...', 'ar' => '...']`. |
| `placeholders` | `array<string,string>` | Same shape. |
| `inputType` | `string` | HTML5 `<input type=...>`. `tel`, `number`, `text`, etc. |

**Named constructors:**
- `PaymentField::phoneNumber($key = 'phone_number', $regex = '/^09\d{8}$/'): self`
- `PaymentField::cardNumber(int $digits = 7, $key = 'card_number'): self`

**Methods:**
- `label(string $locale = 'en'): string` — with fallback to `en` then `key`.
- `placeholder(string $locale = 'en'): string` — same fallback chain.
- `toArray(): array` — for serializing to JSON. **Includes all keys**, even
  null ones, so JSON consumers don't have to check for absence vs. null.
- `static fromArray(array $a): self` — for hydrating from config / DB.

---

## Exceptions — quick reference

All inherit from `DPay\Exceptions\DPayException` which is a `RuntimeException`.

| Class | When |
|---|---|
| `DPayValidationException` | Any 4xx other than 401/403/404. Includes our pre-flight checks (fractional amount, below-min). |
| `DPayAuthException` | 401 / 403. |
| `DPaySessionNotFoundException` | 404 on `getSession`. |
| `DPayNetworkException` | PSR-18 transport failure. Original exception is in `->getPrevious()`. |
| `UnknownProviderException` | `GatewayManager::provider($code)` for an unknown or disabled code. Extends `InvalidArgumentException`, NOT `DPayException`. |

Every `DPayException` carries:
- `->getMessage()` — human-readable
- `->httpStatus` (`int`) — the HTTP status code (0 for pre-flight / transport errors)
- `->errors` (`?array`) — field-level errors if DPay supplied them
