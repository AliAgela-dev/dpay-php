# Docs Finalization (Plan 5, docs-only) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring every doc file (`CLAUDE.md`, `README.md`, all of `docs/`,
`CHANGELOG.md`, `SANDBOX-VALIDATION.md`) into agreement with what Plans 1–3
actually shipped, and add the `UPGRADING.md` the design spec called for —
without touching `src/` and without bumping the version or cutting a
release.

**Scope decision (confirmed with the user 2026-07-28):** Plan 4 (merchant
reads / invoices — `AuthClient`, `PaymentsClient`, `InvoicesClient`,
`PayMethodsClient`) is still unbuilt. Rather than block docs on it, this
plan does the docs pass now and explicitly **excludes** anything that
depends on Plan 4 code: no `docs/invoices.md`, no `PayMethod` DTO /
`logo_url` alignment (design spec §12/§13). `CHANGELOG.md` stays under
`## [Unreleased]` — it does not get rolled to `[0.2.0]`, and no git tag is
created. `UPGRADING.md` is written now (so nothing has to be reconstructed
later) but framed as documenting the unreleased branch, not a specific
version.

**Why this matters:** an audit (2026-07-28) found that four `docs/*.md`
files had **zero commits** since before Plan 1 despite `src/` changing in
77 files (+8277/-1162 lines), and two of the SDK's most-read code examples
— `README.md`'s Quick Start and `docs/checkout-flow.md`'s controller
examples — call `openSession()` with the pre-Plan-1 scalar signature. Both
would throw a `TypeError` today if copy-pasted. This is a correctness pass,
not a polish pass.

**Architecture:** Docs-only. No `src/` changes, no new tests. Each task
is one file (or one small file), with a stale→corrected diff spelled out
in full. Verification is grep-based (old text gone, new text present) plus,
for every PHP code block that constructs SDK objects, a `php -l` syntax
check — and for the two highest-traffic examples (README Quick Start,
`checkout-flow.md`'s `initiate()`), an actual mock-mode execution proving
the snippet runs, not just parses.

**Tech Stack:** Markdown, PHP 8.2+ (for snippet verification only — no
PHPUnit involved).

---

### Task 1: CLAUDE.md — resolve the divergence table and dependent sections

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Rewrite "Source of truth for the API"**

Replace lines 16–32 (the whole `## Source of truth for the API` section)
with:

```markdown
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
```

- [ ] **Step 2: Replace the divergence table and the paragraph after it**

Replace lines 34–62 (from `## Where the SDK diverges from the official spec`
through the `Also unimplemented...->raw` paragraph) with:

```markdown
## Spec alignment status

Plans 1–3 (v0.2.0 work) closed every gap below except per-gateway limits.
This table was last cross-checked against `src/` on 2026-07-28 — verify
against the spec yourself before assuming a row is still accurate.

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
| Per-gateway limits | `GET /api/pay-methods` returns live `fee`, `min_deposit`, `max_deposit` per gateway | ⚠️ **Still open.** No `PayMethod` DTO, no `PayMethodsClient`. Single global `min_amount`, no max check. |

A live sandbox check on 2026-07-28 found: `GET /api/sandbox/pay-methods`
returns real per-gateway data today (200, all 9 gateways with
`fee`/`min_deposit`/`max_deposit`/`enabled`) — building `PayMethodsClient`
is unblocked. `/auth/me`, `/payments`, `/invoices` aren't deployed to
sandbox yet (a branded 404 page, not a Laravel response — genuinely
unrouted, not a permissions issue). `/payments/filter` is a partial
exception: `POST` hits a real registered route (`405 Method Not Allowed`,
naming the route explicitly) but `GET` to the identical URL falls through
to the same branded 404 — looks like a routing-order bug on DPay's side,
not something to work around here. `VerifySessionResponse` maps only the
flat legacy fields — the spec's nested `payment` object, `receipt_url`,
`system_reference`, `network_reference`, `paid_through`, and
`payer_account` are reachable only via `->raw`.
```

- [ ] **Step 3: Fix the Architecture section's "exactly two field keys" claim**

Replace lines 109–124 (from `**The provider layer is schema-driven...`
through the `See [docs/extending.md]` bullet) with:

```markdown
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
```

- [ ] **Step 4: Fix the amount/mock/buildException bullets in "Behavioral contracts to preserve"**

Replace the "Amounts are forced to whole numbers" bullet (currently around
line 152) with:

```markdown
- **Amounts allow decimals; only a configurable floor is enforced.**
  `DPayClient` checks `$request->amount < $config->minAmount` before opening
  a session (default `minAmount` is `0.01`, matching the spec's documented
  minimum) and throws `DPayValidationException` if it's too low. There is
  no whole-number check — that was an SDK-imposed invariant inherited from
  health-portal and it contradicted the spec; Plan 1 removed it. Don't
  reintroduce it.
```

Replace the "Mock mode short-circuits before validation" bullet with:

```markdown
- **Mock mode short-circuits before validation.** In `openSession()`, the
  `config->mock` branch returns before the `min_amount` check — so mock
  mode accepts amounts below the configured floor too. Intentional today;
  know it before writing tests that assume otherwise.
```

Replace the `HTTP status → exception mapping` bullet with:

```markdown
- HTTP status → exception mapping lives in one place, `Transport::buildException()`
  (`src/Http/Transport.php`, extracted from `DPayClient` in Plan 1): 401/403 →
  `DPayAuthException`, 404 → `DPaySessionNotFoundException`, 429 →
  `DPayRateLimitException`, other 4xx → `DPayValidationException`, else
  `DPayException`. All extend `DPayException`.
```

- [ ] **Step 5: Rewrite "Keeping docs in sync"**

Replace the whole `## Keeping docs in sync` section with:

```markdown
## Keeping docs in sync

Provider or test changes ripple into more files than usual here. When you
add/remove a provider or change test counts, check: `README.md` (provider
table, project layout, test counts), `composer.json` (`description` +
`keywords`), `CHANGELOG.md`, `src/Laravel/config/dpay.php`, and every file
under `docs/` — a 2026-07-28 audit found four `docs/*.md` files
(dto-reference, troubleshooting, sandbox-testing, plus a partial miss in
configuration) that had gone untouched since before Plan 1 despite `src/`
changing in 77 files. Don't assume a doc is current just because it reads
confidently.

**Sadad** is shipped (`SadadProvider`, `src/Providers/SadadProvider.php`),
disabled by default via `PAYMENT_GATEWAY_SADAD_ENABLED=false` — it's
merchant-gated on DPay's side (their sandbox returns
`"Unsupported payment method: sadad"` until DPay enables it for this
merchant), not missing SDK capability. It needed zero `AbstractDPayProvider`
changes; see the Architecture section above. **Yaser** does not appear in
the official spec at all and was correctly never added — if you see a
stray "Yaser" reference anywhere outside `CHANGELOG.md`'s `[0.1.0]`
historical section, it's a leftover that should be deleted.
```

Leave the `## Logo path mismatch` section untouched — it's still accurate.

- [ ] **Step 6: Verify**

```bash
grep -n "defaults to \*\*5\*\*\|casts (int)\|Not shipped; \`AbstractDPayProvider\`\|supportsWebhook: false everywhere\|Never sent\|exactly two field keys\|forced to whole numbers\|DPayClient::buildException\|still list Sadad and Yaser" CLAUDE.md
```
Expected: no output (every stale phrase is gone).

```bash
grep -n "Spec alignment status\|wireName()\|Transport::buildException\|Sadad is shipped" CLAUDE.md
```
Expected: all four hit.

- [ ] **Step 7: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: resolve CLAUDE.md's divergence table and Architecture claims against shipped code"
```

---

### Task 2: README.md — fix broken code examples, provider table, stale claims

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Fix the intro paragraph (lines 3–10)**

Old:
```markdown
Framework-agnostic PHP SDK for the **DPay** payment gateway (Libya), with
provider abstractions for Edfali, MobiCash, SaharaPay, YousrPay,
MasrefyPay, and Moamalat. Ships an optional Laravel bridge.

Reverse-engineered from the production `health-portal` implementation. **The
field names and endpoint paths are believed correct but have not been validated
against an official DPay spec.** Run against DPay's sandbox and adjust before
flipping `mock => false` in production.
```

New:
```markdown
Framework-agnostic PHP SDK for the **DPay** payment gateway (Libya), with
provider abstractions for Edfali, MobiCash, Sadad, SaharaPay, YousrPay,
MasrefyPay, and Moamalat. Ships an optional Laravel bridge.

Reverse-engineered from the production `health-portal` implementation, then
aligned against DPay's official API spec (https://dpay.ly/docs/api) and
live-verified against their sandbox — see [SANDBOX-VALIDATION.md](SANDBOX-VALIDATION.md).
Sandbox credentials are per-merchant; get your own before flipping
`mock => false` in production.
```

- [ ] **Step 2: Fix the pure-PHP Quick Start (imports + Section A)**

Old imports (lines 45–50):
```php
use DPay\Client\DPayClient;
use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\GatewayManager;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MoamalatProvider;
```

New imports:
```php
use DPay\Client\DPayClient;
use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\Dto\OpenSessionRequest;
use DPay\GatewayManager;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MoamalatProvider;
```

Old (lines 52–64):
```php
$config = new DPayConfig(
    baseUrl: 'https://dpay.ly/api',
    apiKey: getenv('DPAY_API_KEY'),
    timeout: 15,
    mock: false,
    minAmount: 5,
);

$client = DPayClientFactory::create($config);   // Guzzle-backed by default

// A) Talk to DPay directly:
$session = $client->openSession('edfali', 50, customerMobile: '0911234567');
$verify  = $client->verifySession($session->sessionId, '1234');
```

New:
```php
$config = new DPayConfig(
    baseUrl: 'https://dpay.ly/api',
    apiKey: getenv('DPAY_API_KEY'),
    timeout: 15,
    mock: false,
    minAmount: 0.01,
);

$client = DPayClientFactory::create($config);   // Guzzle-backed by default

// A) Talk to DPay directly:
$session = $client->openSession(new OpenSessionRequest(
    payMethod: 'edfali',
    amount: 50.0,
    customerMobile: '0911234567',
));
$verify  = $client->verifySession($session->sessionId, '1234');
```

- [ ] **Step 3: Fix the Laravel Quick Start env block and Section B**

Old env block (lines 93–103):
```env
DPAY_BASE_URL=https://dpay.ly/api
DPAY_API_KEY=your-key
DPAY_TIMEOUT=15
DPAY_MOCK=true
DPAY_MIN_AMOUNT=5

PAYMENT_GATEWAY_EDFALI_ENABLED=true
PAYMENT_GATEWAY_MOBICASH_ENABLED=true
# ... see config/dpay.php for the full list
```

New:
```env
DPAY_BASE_URL=https://dpay.ly/api
DPAY_API_KEY=your-key
DPAY_TIMEOUT=15
DPAY_MOCK=true
DPAY_MIN_AMOUNT=0.01

PAYMENT_GATEWAY_EDFALI_ENABLED=true
PAYMENT_GATEWAY_MOBICASH_ENABLED=true
# ... see config/dpay.php for the full list
```

Old (lines 105–113):
```php
use DPay\Laravel\Facades\DPay;

$reference = DPay::provider('edfali')->sendOtp(50, ['phone_number' => '0911234567']);
$paid      = DPay::provider('edfali')->verifyOtp($reference, '1234');

// Lower-level access to the client:
$session = DPay::openSession('moamalat', 50);
$status  = DPay::getSession($session->sessionId);
```

New:
```php
use DPay\Dto\OpenSessionRequest;
use DPay\Laravel\Facades\DPay;

$reference = DPay::provider('edfali')->sendOtp(50, ['phone_number' => '0911234567']);
$paid      = DPay::provider('edfali')->verifyOtp($reference, '1234');

// Lower-level access to the client:
$session = DPay::openSession(new OpenSessionRequest(payMethod: 'moamalat', amount: 50.0));
$status  = DPay::getSession($session->sessionId);
```

- [ ] **Step 4: Fix the sandbox-testing.md documentation table row (line 127)**

Old:
```markdown
| [docs/sandbox-testing.md](docs/sandbox-testing.md) | _(Stub)_ How to go from mock to live once DPay sandbox creds arrive. |
```

New:
```markdown
| [docs/sandbox-testing.md](docs/sandbox-testing.md) | You have sandbox creds and want to run the live probe, or need the sandbox test-input cheat sheet. |
```

- [ ] **Step 5: Fix the provider table and Sadad/Yaser callout (lines 133–145)**

Old:
```markdown
| Code | Class | `requiresOtp` | `supportsStatusCheck` | Fields consumed |
|---|---|---|---|---|
| `edfali`     | `EdfaliProvider`     | true  | false | `phone_number` |
| `mobicash`   | `MobiCashProvider`   | true  | false | `card_number`  |
| `saharapay`  | `SaharaPayProvider`  | true  | **true** | `card_number` |
| `yousrpay`   | `YousrPayProvider`   | true  | **true** | `card_number` |
| `masrefypay` | `MasrefyPayProvider` | true  | **true** | `card_number` |
| `moamalat`   | `MoamalatProvider`   | **false** | true | (none — payment-link flow) |

> **Sadad / Yaser** are not shipped — DPay's sandbox doesn't enable them
> for our merchant (returns `500 "Unsupported payment method"`). They'll
> be added back when DPay enables them. To re-add manually if your
> tenant supports them, see [docs/extending.md](docs/extending.md).
```

New:
```markdown
| Code | Class | `requiresOtp` | `supportsStatusCheck` | Fields consumed |
|---|---|---|---|---|
| `edfali`     | `EdfaliProvider`     | true  | false | `phone_number` |
| `mobicash`   | `MobiCashProvider`   | true  | false | `card_number`  |
| `saharapay`  | `SaharaPayProvider`  | true  | **true** | `card_number` |
| `yousrpay`   | `YousrPayProvider`   | true  | **true** | `card_number` |
| `masrefypay` | `MasrefyPayProvider` | true  | **true** | `card_number` |
| `sadad`      | `SadadProvider`      | true  | false | `phone_number`, `birth_year`, `category` (optional) |
| `moamalat`   | `MoamalatProvider`   | **false** | true | (none — payment-link flow) |

> **Sadad ships disabled by default** (`PAYMENT_GATEWAY_SADAD_ENABLED=false`) —
> it's merchant-gated on DPay's side, not missing SDK support. Confirm with
> DPay that Sadad is enabled on your merchant account before flipping it on.
> **Yaser** is not shipped — it does not appear in the official DPay spec at
> all.
```

- [ ] **Step 6: Fix the Mock mode section (lines 213–221)**

Old:
```markdown
Set `mock: true` (or `DPAY_MOCK=true` in Laravel) and the client bypasses HTTP
entirely:

- `openSession` returns a synthetic session with a random `session_id` (1–99999)
- `verifySession` accepts **any 4–6 digit numeric OTP** and returns `paid`
- `getSession` returns `paid` for any id

Useful for local dev and for the test suite — same behavior as the original
health-portal mock.
```

New:
```markdown
Set `mock: true` (or `DPAY_MOCK=true` in Laravel) and the client bypasses HTTP
entirely:

- `openSession` returns a synthetic session with a random `session_id`
  (1–99999) and a session lifetime of 10 minutes for `moamalat`/`sadad`,
  15 minutes for everything else — matching DPay's documented expiries.
- `verifySession` accepts **any 4–6 digit numeric OTP except `000000`**,
  which simulates a decline (mirrors the sandbox). A matching OTP returns
  `paid`; `000000` or a non-numeric/wrong-length value returns `null`.
- `getSession` returns `paid` for any id.

Useful for local dev and for the test suite — same behavior as the real
DPay sandbox for these cases.
```

- [ ] **Step 7: Verify — grep for stale text**

```bash
grep -n "have not been validated\|minAmount: 5,\|DPAY_MIN_AMOUNT=5$\|openSession('edfali', 50\|openSession('moamalat', 50)\|Sadad / Yaser\*\* are not shipped\|_(Stub)_" README.md
```
Expected: no output.

- [ ] **Step 8: Verify — the two fixed PHP examples actually run in mock mode**

Save the corrected pure-PHP Quick Start (Section A only, with `mock: true`
substituted for `false` and a fake API key) to a scratch file and run it:

```bash
cat > /tmp/readme_check.php <<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';

use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\Dto\OpenSessionRequest;

$config = new DPayConfig(baseUrl: 'https://dpay.ly/api', apiKey: 'fake', timeout: 15, mock: true, minAmount: 0.01);
$client = DPayClientFactory::create($config);

$session = $client->openSession(new OpenSessionRequest(payMethod: 'edfali', amount: 50.0, customerMobile: '0911234567'));
$verify  = $client->verifySession($session->sessionId, '1234');

echo $verify?->isPaid() ? "OK: paid\n" : "FAIL\n";
PHP
php /tmp/readme_check.php
```
Expected: `OK: paid`. Delete `/tmp/readme_check.php` after.

- [ ] **Step 9: Commit**

```bash
git add README.md
git commit -m "docs: fix README's broken openSession() examples, add Sadad to provider table"
```

---

### Task 3: docs/configuration.md — fix defaults, constructor examples, add webhooks section

**Files:**
- Modify: `docs/configuration.md`

- [ ] **Step 1: Fix `DPAY_MIN_AMOUNT` default row (line 20)**

Old:
```markdown
| `DPAY_MIN_AMOUNT` | `5` | no | LYD floor. Anything below throws `DPayValidationException` pre-flight. |
```

New:
```markdown
| `DPAY_MIN_AMOUNT` | `0.01` | no | LYD floor, decimals allowed. Anything below throws `DPayValidationException` pre-flight. |
```

- [ ] **Step 2: Insert a new "Webhooks" subsection after "Per-gateway `pay_method` override"**

Insert immediately before the `---` separator that follows the
`pay_method` override table (after the table ending in
`| \`DPAY_PAY_METHOD_MOAMALAT\` | \`moamalat\` |`):

```markdown
### Webhooks

Off by default — enabling the route is a deliberate opt-in, not automatic.
See [webhooks.md](webhooks.md) for the full setup.

| Variable | Default | Required | Notes |
|---|---|---|---|
| `DPAY_WEBHOOKS_ENABLED` | `false` | no | Registers the receiver route when true. Resolves `WebhookVerifier` eagerly at boot, so a missing secret fails at deploy time, not on your first real webhook. |
| `DPAY_WEBHOOK_ROUTE` | `/webhooks/dpay` | no | The path DPay POSTs to. |
| `DPAY_WEBHOOK_SECRET` | _empty_ | yes, if enabled | From Dashboard → Webhooks → Reveal Secret. `WebhookVerifier` refuses to construct with an empty secret. |

The `middleware` config key (`src/Laravel/config/dpay.php`) isn't
env-driven — edit the published config file directly if you want e.g.
`['throttle:60,1']` on the route.
```

- [ ] **Step 3: Fix the `config/dpay.php` keys example**

Old (the fenced block starting `return [` under `## \`config/dpay.php\` keys`):
```php
return [
    'base_url'   => env('DPAY_BASE_URL', 'https://dpay.ly/api'),
    'api_key'    => env('DPAY_API_KEY', ''),
    'timeout'    => (int) env('DPAY_TIMEOUT', 15),
    'mock'       => (bool) env('DPAY_MOCK', true),
    'min_amount' => (int) env('DPAY_MIN_AMOUNT', 5),

    'gateways' => [
        'edfali' => [
            'enabled'         => (bool) env('PAYMENT_GATEWAY_EDFALI_ENABLED', true),
            'provider'        => \DPay\Providers\EdfaliProvider::class,
            'pay_method'      => env('DPAY_PAY_METHOD_EDFALI', 'edfali'),
            'required_fields' => null,   // optional — see below
        ],
        // ... seven more gateways
    ],
];
```

New:
```php
return [
    'base_url'   => env('DPAY_BASE_URL', 'https://dpay.ly/api'),
    'api_key'    => env('DPAY_API_KEY', ''),
    'timeout'    => (int) env('DPAY_TIMEOUT', 15),
    'mock'       => (bool) env('DPAY_MOCK', true),
    'min_amount' => (float) env('DPAY_MIN_AMOUNT', 0.01),

    'gateways' => [
        'edfali' => [
            'enabled'         => (bool) env('PAYMENT_GATEWAY_EDFALI_ENABLED', true),
            'provider'        => \DPay\Providers\EdfaliProvider::class,
            'pay_method'      => env('DPAY_PAY_METHOD_EDFALI', 'edfali'),
            'required_fields' => null,   // optional — see below
        ],
        // ... six more gateways: mobicash, masrefypay, yousrpay,
        // saharapay, sadad, moamalat
    ],

    // Off by default. See "Webhooks" above.
    'webhooks' => [
        'enabled' => (bool) env('DPAY_WEBHOOKS_ENABLED', false),
        'route'   => env('DPAY_WEBHOOK_ROUTE', '/webhooks/dpay'),
        'secret'  => env('DPAY_WEBHOOK_SECRET', ''),
        'middleware' => [],
    ],
];
```

- [ ] **Step 4: Fix the `DPayConfig` constructor example**

Old:
```php
new DPayConfig(
    baseUrl: 'https://dpay.ly/api',  // string
    apiKey:  '...',                  // string
    timeout: 15,                     // int >= 1
    mock:    false,                  // bool
    minAmount: 5,                    // int >= 0
);
```

New:
```php
new DPayConfig(
    baseUrl: 'https://dpay.ly/api',  // string
    apiKey:  '...',                  // string
    timeout: 15,                     // int >= 1
    mock:    false,                  // bool
    minAmount: 0.01,                 // float >= 0, defaults to DPay's documented minimum
);
```

- [ ] **Step 5: Fix the `DPayClient` constructor example**

Old:
```markdown
### `DPayClient`

```php
new DPayClient(
    config: $config,                       // DPayConfig
    httpClient: $psr18,                    // Psr\Http\Client\ClientInterface
    requestFactory: $psr17,                // Psr\Http\Message\RequestFactoryInterface
    streamFactory:  $psr17,                // Psr\Http\Message\StreamFactoryInterface
    logger:         $psr3,                 // Psr\Log\LoggerInterface, defaults to NullLogger
    mockTransport:  null,                  // DPay\Support\MockTransport — used when $config->mock = true
);
```

Or use the convenience factory (requires `guzzlehttp/guzzle`):

```php
DPayClientFactory::create($config);
```
```

New:
```markdown
### `DPayClient`

`DPayClient` takes a `Transport` (the HTTP plumbing), not raw PSR-18/17
objects directly — `Transport` owns those:

```php
use DPay\Http\Transport;

$transport = new Transport(
    config: $config,                       // DPayConfig
    httpClient: $psr18,                    // Psr\Http\Client\ClientInterface
    requestFactory: $psr17,                // Psr\Http\Message\RequestFactoryInterface
    streamFactory:  $psr17,                // Psr\Http\Message\StreamFactoryInterface
    logger:         $psr3,                 // Psr\Log\LoggerInterface, defaults to NullLogger
);

$client = new DPayClient(
    config: $config,                       // DPayConfig
    transport: $transport,                 // DPay\Http\Transport
    mockTransport: null,                   // DPay\Support\MockTransport — used when $config->mock = true
);
```

Or use the convenience factory (requires `guzzlehttp/guzzle`, builds the
`Transport` for you):

```php
DPayClientFactory::create($config);
```
```

- [ ] **Step 6: Verify**

```bash
grep -n "'min_amount' => (int)\|minAmount: 5,\|httpClient: \$psr18,\s*// Psr" docs/configuration.md
```
Expected: no output (the old `DPayClient` example with `httpClient` as a
direct constructor arg is gone; only `Transport`'s constructor should have
it now).

```bash
grep -n "DPAY_WEBHOOKS_ENABLED\|use DPay\\\\Http\\\\Transport" docs/configuration.md
```
Expected: both hit.

Extract the two corrected constructor examples (`DPayConfig`, `Transport` +
`DPayClient`) into a scratch file and lint:

```bash
cat > /tmp/config_check.php <<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';

use DPay\Client\DPayClient;
use DPay\Config\DPayConfig;
use DPay\Http\Transport;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Log\NullLogger;

$config = new DPayConfig(
    baseUrl: 'https://dpay.ly/api',
    apiKey:  '...',
    timeout: 15,
    mock:    false,
    minAmount: 0.01,
);

$psr18 = new GuzzleClient();
$psr17 = new HttpFactory();

$transport = new Transport(
    config: $config,
    httpClient: $psr18,
    requestFactory: $psr17,
    streamFactory: $psr17,
    logger: new NullLogger(),
);

$client = new DPayClient(config: $config, transport: $transport, mockTransport: null);

echo get_class($client)."\n";
PHP
php -l /tmp/config_check.php && php /tmp/config_check.php
```
Expected: `No syntax errors detected` then `DPay\Client\DPayClient`. Delete
the scratch file after.

- [ ] **Step 7: Commit**

```bash
git add docs/configuration.md
git commit -m "docs: fix configuration.md's stale constructor examples, add webhooks section"
```

---

### Task 4: docs/dto-reference.md — bring every DTO/exception table current

**Files:**
- Modify: `docs/dto-reference.md`

- [ ] **Step 1: Fix the `DPayConfig` table row**

Old:
```markdown
| `minAmount` | `int` | `5` | Anything below throws `DPayValidationException`. Must be ≥ 0. |
```

New:
```markdown
| `minAmount` | `float` | `0.01` | Anything below throws `DPayValidationException`. Must be ≥ 0. Matches DPay's documented minimum. |
```

- [ ] **Step 2: Fix the `OpenSessionRequest` table**

Old:
```markdown
| Property | Type | Notes |
|---|---|---|
| `payMethod` | `string` | The exact DPay string (e.g. `edfali`). |
| `amount` | `float` | Whole-number LYD. Fractional → `DPayValidationException`. |
| `customerMobile` | `?string` | Required by phone-OTP providers. |
| `cardNumber` | `?string` | Required by card-OTP providers. |
| `description` | `?string` | Optional. Sent as `data.description`. |

**Method:** `toBody(): array` — builds the JSON body, stripping nulls.
```

New:
```markdown
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
```

- [ ] **Step 3: Fix the `SessionStatus` table**

Old:
```markdown
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
```

New:
```markdown
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
```

- [ ] **Step 4: Fix the `PaymentField` table and method list**

Old:
```markdown
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
```

New:
```markdown
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
```

- [ ] **Step 5: Fix the exceptions quick-reference**

Old:
```markdown
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
```

New:
```markdown
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
```

- [ ] **Step 6: Verify**

```bash
grep -n "minAmount\` | \`int\`\|Whole-number LYD\|Sent as \`data.description\`\|Reserved — no provider supports refunds\|digitsOneOf\|sendAs\`" docs/dto-reference.md
```
First four patterns: no output. `digitsOneOf`/`sendAs`: should hit (new
content).

```bash
grep -n "VOIDED\|DPayRateLimitException\|WebhookSignatureMismatchException\|WebhookTimestampExpiredException\|bankCardNumber\|birthYear()\|sadadCategory()\|wireName()" docs/dto-reference.md
```
Expected: all hit.

- [ ] **Step 7: Commit**

```bash
git add docs/dto-reference.md
git commit -m "docs: bring dto-reference.md current — DPayConfig, OpenSessionRequest, SessionStatus, PaymentField, exceptions"
```

---

### Task 5: docs/providers.md — fix Edfali's stale capability flag

**Files:**
- Modify: `docs/providers.md`

- [ ] **Step 1: Fix the capability row**

Old:
```markdown
| `supportsRefund` / `supportsWebhook` | false / false |
```

New:
```markdown
| `supportsRefund` / `supportsWebhook` | false / **true** |
```

- [ ] **Step 2: Verify**

```bash
grep -n "false / false\|false / \*\*true\*\*" docs/providers.md
```
Expected: only the second pattern hits.

- [ ] **Step 3: Commit**

```bash
git add docs/providers.md
git commit -m "docs: fix Edfali's stale supportsWebhook flag in providers.md"
```

---

### Task 6: docs/sandbox-testing.md — status framing, wire format, Sadad/Yaser, min_amount

**Files:**
- Modify: `docs/sandbox-testing.md`

- [ ] **Step 1: Fix the status header**

Old (lines 3–8):
```markdown
**Status: ✅ VALIDATED against the real DPay sandbox** (2026-05-22). Every
endpoint, field name, status string, auth scheme, and exception path
exercised by [tests/sandbox/probe.php](../tests/sandbox/probe.php).

See [SANDBOX-VALIDATION.md](../SANDBOX-VALIDATION.md) for the raw probe
output and a detailed per-provider results table.
```

New:
```markdown
**Status: ✅ VALIDATED against the real DPay sandbox.** Initially validated
2026-05-22 (v0.1.0); re-validated live after the v0.2.0 spec-alignment work
(decimal amounts, 9-digit OnePay cards, `Idempotency-Key`, Sadad) — see
[SANDBOX-VALIDATION.md](../SANDBOX-VALIDATION.md) for the current raw probe
output and per-provider results table, regenerated by
[tests/sandbox/probe.php](../tests/sandbox/probe.php) each run.
```

- [ ] **Step 2: Fix the Laravel sandbox env block**

Old (lines 54–66):
```env
DPAY_BASE_URL=https://dpay.ly/api/sandbox
DPAY_API_KEY=sb_tk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
DPAY_TIMEOUT=30
DPAY_MOCK=false
DPAY_MIN_AMOUNT=5

PAYMENT_GATEWAY_EDFALI_ENABLED=true
PAYMENT_GATEWAY_MOBICASH_ENABLED=true
PAYMENT_GATEWAY_MASREFYPAY_ENABLED=true
PAYMENT_GATEWAY_YOUSRPAY_ENABLED=true
PAYMENT_GATEWAY_SAHARAPAY_ENABLED=true
PAYMENT_GATEWAY_MOAMALAT_ENABLED=true
```

New:
```env
DPAY_BASE_URL=https://dpay.ly/api/sandbox
DPAY_API_KEY=sb_tk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
DPAY_TIMEOUT=30
DPAY_MOCK=false
DPAY_MIN_AMOUNT=0.01

PAYMENT_GATEWAY_EDFALI_ENABLED=true
PAYMENT_GATEWAY_MOBICASH_ENABLED=true
PAYMENT_GATEWAY_MASREFYPAY_ENABLED=true
PAYMENT_GATEWAY_YOUSRPAY_ENABLED=true
PAYMENT_GATEWAY_SAHARAPAY_ENABLED=true
PAYMENT_GATEWAY_MOAMALAT_ENABLED=true
# Sadad is merchant-gated — leave false until DPay confirms it's enabled
# on your account, then set true:
PAYMENT_GATEWAY_SADAD_ENABLED=false
```

- [ ] **Step 3: Fix the Sadad/Yaser callout right after it**

Old (lines 69–72):
```markdown
> Sadad and Yaser providers aren't shipped with the SDK — both return
> `500 "Unsupported payment method"` from this sandbox. When DPay enables
> them on your merchant, re-add by following
> [extending.md § Scenario 2](extending.md#scenario-2--a-new-dpay-pay_method).
```

New:
```markdown
> **Sadad** ships with the SDK but is disabled by default
> (`PAYMENT_GATEWAY_SADAD_ENABLED=false`) — this sandbox rejects it with
> `"Unsupported payment method: sadad"` until DPay enables it for this
> merchant account. Set `PAYMENT_GATEWAY_SADAD_ENABLED=true` once confirmed.
> **Yaser** isn't shipped and doesn't appear in the official DPay spec.
```

- [ ] **Step 4: Fix the "Field names" line**

Old:
```markdown
- Request: `pay_method`, `amount`, `customer_mobile`, `card_number`, `data.description` ✅
```

New:
```markdown
- Request: `pay_method`, `amount` (decimals allowed), `customer_mobile`, `card_number`, `birth_year`, `category`, top-level `description`, `data` ✅
```

- [ ] **Step 5: Insert a new subsection after "### Field names" and before "### Differences from our initial assumptions"**

```markdown
### Idempotency-Key and webhooks

- Replaying an `Idempotency-Key` on `/payment/sessions/open` did **not**
  return the original session in this sandbox — it opened a second one.
  Reproduced independently via raw `curl`, outside the SDK, with two
  different key formats. This looks like a sandbox-side gap, not an SDK
  bug — the SDK sends the header correctly. See `SANDBOX-VALIDATION.md`.
- Webhook delivery (`payment.paid`/`failed`/`expired`/`refunded`/`voided`,
  `webhook.test`) has not been live-verified — that needs a signing secret
  from Dashboard → Webhooks and a publicly reachable HTTPS endpoint, neither
  of which a local probe script can provide. Signature verification and
  event parsing are covered by 52 offline unit/feature tests instead. See
  [webhooks.md](webhooks.md).
```

- [ ] **Step 6: Fix the "Going to production" checklist item 5**

Old:
```markdown
5. ⚠️ If you need Sadad / Yaser in prod, follow
   [extending.md § Scenario 2](extending.md#scenario-2--a-new-dpay-pay_method)
   to add them — they aren't shipped because DPay's sandbox doesn't
   enable them.
```

New:
```markdown
5. ⚠️ If you need Sadad in prod, set `PAYMENT_GATEWAY_SADAD_ENABLED=true` —
   only after DPay confirms the gateway is enabled on your merchant account.
   Yaser isn't shipped and doesn't appear in the official spec; there's
   nothing to enable.
```

- [ ] **Step 7: Verify**

```bash
grep -n "DPAY_MIN_AMOUNT=5$\|Sadad and Yaser providers aren't shipped\|data.description\` ✅\|Sadad / Yaser in prod" docs/sandbox-testing.md
```
Expected: no output.

```bash
grep -n "PAYMENT_GATEWAY_SADAD_ENABLED\|Idempotency-Key and webhooks" docs/sandbox-testing.md
```
Expected: both hit.

- [ ] **Step 8: Commit**

```bash
git add docs/sandbox-testing.md
git commit -m "docs: fix sandbox-testing.md's stale wire format, min_amount, and Sadad/Yaser claims"
```

---

### Task 7: docs/troubleshooting.md — remove dead error, fix defaults, add new exception coverage

**Files:**
- Modify: `docs/troubleshooting.md`

- [ ] **Step 1: Remove the whole-number error row, fix the min_amount row**

Old:
```markdown
| `Amount must be a whole number for this payment provider.` | You passed `49.5` (or any non-integer). DPay only accepts integers in LYD. | Round at the caller — `(int) round($amount)`. |
| `Amount is below the minimum of N.` | Amount < `min_amount` (default 5). | Either raise the amount or lower `min_amount` in config. |
```

New:
```markdown
| `Amount is below the minimum of N.` | Amount < `min_amount` (default `0.01`). | Either raise the amount or lower `min_amount` in config. Decimal amounts (e.g. `49.5`) are accepted — there's no whole-number requirement. |
```

- [ ] **Step 2: Insert a `DPayRateLimitException` section after `DPayNetworkException`, before `UnknownProviderException`**

```markdown
### `DPayRateLimitException` (429)

| Symptom | Cause | Fix |
|---|---|---|
| `Too Many Attempts.` | You (or your test suite) fired requests faster than DPay's rate limit. The sandbox is aggressive — even 4–5 requests in quick succession can trip it. | Back off and retry later. Don't loop-retry immediately; space calls out (the sandbox probe uses 2.5–15s delays). |
```

- [ ] **Step 3: Insert a webhook exceptions section after `UnknownProviderException`, before `## Common gotchas`**

```markdown
### Webhook verification exceptions

These come from `DPay\Webhooks\WebhookVerifier::verify()`, not from
`DPayClient`. None of them ever include the expected signature or your
webhook secret in `->getMessage()` — the request that triggers them is
attacker-controlled, so nothing sensitive is echoed back.

| Symptom | Cause | Fix |
|---|---|---|
| `WebhookSignatureMismatchException` | Computed HMAC didn't match `X-DPAY-Signature`. | Confirm `DPAY_WEBHOOK_SECRET` matches Dashboard → Webhooks → Reveal Secret exactly. Also check nothing upstream (a proxy, middleware) is re-encoding the JSON body before your handler sees it — the signature is computed over the exact raw bytes DPay sent. |
| `WebhookTimestampExpiredException` | `X-DPAY-Timestamp` is more than 5 minutes from "now," in either direction. | Check server clock drift (NTP). A future timestamp usually means clock skew, not a replay attack. |
| Constructing `WebhookVerifier` throws `InvalidArgumentException` | Empty secret. `WebhookVerifier` refuses to construct with `''` rather than silently rejecting every webhook with a misleading signature-mismatch message. | Set `DPAY_WEBHOOK_SECRET`. In Laravel, this fails at **boot** time when `dpay.webhooks.enabled` is true, not on your first real webhook. |

See [webhooks.md](webhooks.md) for full setup.
```

- [ ] **Step 4: Fix the "Where are Sadad / Yaser?" gotcha**

Old:
```markdown
### "Where are Sadad / Yaser?"

Not shipped. DPay's sandbox returns `500 "Unsupported payment method"`
for both. When DPay enables them for your merchant, follow
[extending.md § Scenario 2](extending.md#scenario-2--a-new-dpay-pay_method)
to add them back — it's two short PHP classes plus a config entry.
```

New:
```markdown
### "Where is Sadad / Why does it fail with 'Unsupported payment method'?"

Sadad ships with the SDK (`SadadProvider`) but is **disabled by default**
(`PAYMENT_GATEWAY_SADAD_ENABLED=false`) because it's merchant-gated on
DPay's side — their sandbox rejects it with
`"Unsupported payment method: sadad"` until DPay enables it for your
merchant account. Confirm with DPay, then set
`PAYMENT_GATEWAY_SADAD_ENABLED=true`. No code changes needed.

**Yaser** isn't shipped and doesn't appear in the official DPay spec at all
— there's nothing to enable.
```

- [ ] **Step 5: Verify**

```bash
grep -n "whole number for this payment provider\|default 5\)\.\|Not shipped. DPay's sandbox returns \`500" docs/troubleshooting.md
```
Expected: no output.

```bash
grep -n "DPayRateLimitException\` (429)\|Webhook verification exceptions" docs/troubleshooting.md
```
Expected: both hit.

- [ ] **Step 6: Commit**

```bash
git add docs/troubleshooting.md
git commit -m "docs: remove dead whole-number error, add rate-limit and webhook exception coverage to troubleshooting.md"
```

---

### Task 8: docs/checkout-flow.md — fix the exception-catching hole and two broken openSession() calls

**Files:**
- Modify: `docs/checkout-flow.md`

- [ ] **Step 1: Fix the `catch (DPayException)` hole in `initiate()`**

Old:
```php
// app/Http/Controllers/CheckoutController.php
use DPay\Exceptions\DPayException;
use DPay\Laravel\Facades\DPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

public function initiate(Request $request)
{
    $data = $request->validate([
        'order_id'     => 'required|integer|exists:orders,id',
        'method'       => 'required|string',
        'phone_number' => 'required_if:method,edfali|string',
        'card_number'  => 'required_if:method,mobicash,saharapay,yousrpay,masrefypay|string',
    ]);

    $order = Order::findOrFail($data['order_id']);

    try {
        $reference = DPay::provider($data['method'])->sendOtp(
            amount: $order->total_amount,
            fields: $data,
        );
    } catch (DPayException $e) {
        return back()->withErrors(['payment' => $e->getMessage()]);
    }
```

New:
```php
// app/Http/Controllers/CheckoutController.php
use DPay\Exceptions\DPayExceptionInterface;
use DPay\Laravel\Facades\DPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

public function initiate(Request $request)
{
    $data = $request->validate([
        'order_id'     => 'required|integer|exists:orders,id',
        'method'       => 'required|string',
        'phone_number' => 'required_if:method,edfali|string',
        'card_number'  => 'required_if:method,mobicash,saharapay,yousrpay,masrefypay|string',
    ]);

    $order = Order::findOrFail($data['order_id']);

    try {
        // DPay::provider() throws UnknownProviderException (extends
        // InvalidArgumentException, NOT DPayException) for an unregistered
        // or disabled code. Catch DPayExceptionInterface, not DPayException,
        // so this one block covers both that and sendOtp()'s DPayException
        // tree.
        $reference = DPay::provider($data['method'])->sendOtp(
            amount: $order->total_amount,
            fields: $data,
        );
    } catch (DPayExceptionInterface $e) {
        return back()->withErrors(['payment' => $e->getMessage()]);
    }
```

(The rest of `initiate()` — the `Payment::create` call and JSON response —
is unchanged.)

- [ ] **Step 2: Fix the Moamalat `openSession()` call in "Step 2"**

Old:
```php
$session = DPay::openSession('moamalat', $order->total_amount);
$paymentLink = $session->paymentLink;   // hand to the front-end
// Persist $session->sessionId as the reference.
```

New:
```php
use DPay\Dto\OpenSessionRequest;

$session = DPay::openSession(new OpenSessionRequest(
    payMethod: 'moamalat',
    amount: $order->total_amount,
));
$paymentLink = $session->paymentLink;   // hand to the front-end
// Persist $session->sessionId as the reference.
```

- [ ] **Step 3: Fix the "WRONG" example in "A safety rule"**

Old:
```php
// WRONG — this just opens a session
$session = DPay::openSession('edfali', 50, '0911234567');
$order->markPaid();   // ❌ user hasn't paid yet
```

New:
```php
// WRONG — this just opens a session
use DPay\Dto\OpenSessionRequest;

$session = DPay::openSession(new OpenSessionRequest(
    payMethod: 'edfali',
    amount: 50.0,
    customerMobile: '0911234567',
));
$order->markPaid();   // ❌ user hasn't paid yet
```

- [ ] **Step 4: Verify — grep**

```bash
grep -n "catch (DPayException \$e)\|openSession('moamalat', \$order->total_amount)\|openSession('edfali', 50, '0911234567')" docs/checkout-flow.md
```
Expected: no output.

```bash
grep -n "DPayExceptionInterface" docs/checkout-flow.md
```
Expected: 2 hits (the `use` statement and the `catch`).

- [ ] **Step 5: Verify — the `initiate()` exception handling actually works as claimed**

Prove `UnknownProviderException` is now caught by the updated block, and
that it was NOT caught by the old `DPayException` catch (i.e. this is a
real fix, not just a text change):

```bash
cat > /tmp/checkout_check.php <<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';

use DPay\Exceptions\DPayException;
use DPay\Exceptions\DPayExceptionInterface;
use DPay\Exceptions\UnknownProviderException;

$e = new UnknownProviderException('Payment provider [foo] is not supported.');

$caughtByOldCatch = false;
try {
    throw $e;
} catch (DPayException $ex) {
    $caughtByOldCatch = true;
} catch (\Throwable $ex) {
    // falls through uncaught by the old, narrower catch
}

$caughtByNewCatch = false;
try {
    throw $e;
} catch (DPayExceptionInterface $ex) {
    $caughtByNewCatch = true;
}

printf("old catch (DPayException) caught it: %s\n", $caughtByOldCatch ? 'yes' : 'no');
printf("new catch (DPayExceptionInterface) caught it: %s\n", $caughtByNewCatch ? 'yes' : 'no');
PHP
php /tmp/checkout_check.php
```
Expected:
```
old catch (DPayException) caught it: no
new catch (DPayExceptionInterface) caught it: yes
```
This proves the documented hole was real and the fix closes it. Delete the
scratch file after.

- [ ] **Step 6: Commit**

```bash
git add docs/checkout-flow.md
git commit -m "docs: fix checkout-flow.md's UnknownProviderException catch hole and two broken openSession() calls"
```

---

### Task 9: CHANGELOG.md — fill the three gaps found in the diff audit

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Extend the webhook route bullet in "Added" to mention the middleware key**

Old:
```markdown
- Laravel bridge: opt-in webhook receiver route (`dpay.webhooks.enabled`,
  off by default) and `DPayWebhookReceived` event.
```

New:
```markdown
- Laravel bridge: opt-in webhook receiver route (`dpay.webhooks.enabled`,
  off by default), `DPayWebhookReceived` event, and a `dpay.webhooks.middleware`
  config key for applying rate limiting or other middleware to the route.
```

- [ ] **Step 2: Add two missing "Changed" bullets, after the existing `openSession()` bullet**

Old (end of the `### Changed` section):
```markdown
- `openSession()` takes an `OpenSessionRequest` DTO and an optional
  `Idempotency-Key` instead of positional scalar arguments. **Breaking.**
```

New:
```markdown
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
```

- [ ] **Step 3: Verify**

```bash
grep -n "DPay\\\\Http\\\\Transport\|MoamalatProvider::supportsRefund\|dpay.webhooks.middleware" CHANGELOG.md
```
Expected: all three hit, inside the `## [Unreleased]` section (confirm with
`grep -n -B30 "DPay\\\\Http\\\\Transport" CHANGELOG.md | grep "\[Unreleased\]"`).

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: fill CHANGELOG gaps — Transport extraction, capability flag flips, webhooks.middleware key"
```

---

### Task 10: New UPGRADING.md

**Files:**
- Create: `UPGRADING.md`

- [ ] **Step 1: Write the file**

```markdown
# Upgrading

## From 0.1.0 (unreleased changes on this branch)

These changes are on `feat/dpay-spec-alignment-v0.2.0`, not yet tagged as a
release. This guide will move under a `## [0.2.0]` heading once that
happens — written now so nothing has to be reconstructed later. See
`CHANGELOG.md` for the full list; this covers only what requires you to
change calling code.

### `openSession()` no longer takes positional scalar arguments

**Before:**
```php
$session = $client->openSession('edfali', 50, customerMobile: '0911234567');
```

**After:**
```php
use DPay\Dto\OpenSessionRequest;

$session = $client->openSession(new OpenSessionRequest(
    payMethod: 'edfali',
    amount: 50.0,
    customerMobile: '0911234567',
));
```

Full `OpenSessionRequest` shape: `payMethod`, `amount`, `customerMobile`,
`cardNumber`, `birthYear`, `category`, `description`, `data`. See
[docs/dto-reference.md](docs/dto-reference.md).

If you go through `GatewayManager`/the `DPay` facade's
`provider($code)->sendOtp()` path instead of calling `openSession()`
directly, **you are not affected** — `sendOtp(float $amount, array $fields)`
keeps its existing signature.

### `openSession()` also takes an optional `Idempotency-Key`

```php
$session = $client->openSession($request, idempotencyKey: 'my-unique-key');
```

Optional — omit it and behavior is unchanged. New capability, not a
breaking change.

### `DPayClient`'s constructor takes a `Transport`, not raw PSR-18/17 objects

Only affects you if you construct `DPayClient` directly instead of using
`DPayClientFactory::create()`.

**Before:**
```php
new DPayClient(
    config: $config,
    httpClient: $psr18,
    requestFactory: $psr17,
    streamFactory: $psr17,
    logger: $psr3,
    mockTransport: null,
);
```

**After:**
```php
use DPay\Http\Transport;

$client = new DPayClient(
    config: $config,
    transport: new Transport(
        config: $config,
        httpClient: $psr18,
        requestFactory: $psr17,
        streamFactory: $psr17,
        logger: $psr3,
    ),
    mockTransport: null,
);
```

If you use `DPayClientFactory::create($config)`, **you are not affected** —
the factory builds the `Transport` for you.

### `minAmount` is now a `float`, and its default changed

`DPayConfig::$minAmount` was `int`, default `5`. It's now `float`, default
`0.01` — matching DPay's documented minimum instead of an SDK-imposed
whole-number floor inherited from health-portal.

**If you relied on the old default of 5:** set it explicitly —
`minAmount: 5.0` (constructor) or `DPAY_MIN_AMOUNT=5` (env, Laravel).
Nothing breaks if you don't; amounts between `0.01` and `5` will simply
stop being rejected pre-flight, which matches DPay's own rules more
closely than the old default did.

### Decimal amounts are now accepted

There was a pre-flight check rejecting any non-integer `amount` (e.g.
`49.5`) with `DPayValidationException`. It's gone — the spec always
allowed decimals, and the SDK's own whole-number cast (`(int) $amount` in
`toBody()`) would have silently truncated `10.50` to `10` if that check
were ever removed without also fixing the cast. Both are fixed together.

**If your integration relies on amounts being rejected unless whole:** add
your own check before calling `sendOtp()`/`openSession()`. The SDK no
longer does this for you.

### Capability flags changed on every provider

`GatewayManager::describe()`'s JSON output — and each provider's
`supportsWebhook()`/`supportsRefund()` — changed:

- `supportsWebhook()` is now `true` for every provider (was `false`
  everywhere). Webhooks are configured account-wide at DPay's Dashboard →
  Webhooks, not per-gateway.
- `MoamalatProvider::supportsRefund()` is now `true` (was `false`). DPay
  supports refunds/voids for Moamalat, triggered from their dashboard and
  observed via `payment.refunded`/`payment.voided` webhooks — not
  something this SDK can invoke directly.

If your frontend renders UI conditionally on these flags (e.g. hiding a
"webhook available" badge), it will start showing differently. Nothing in
the SDK's behavior changed beyond the flags themselves.

### Sadad is now available (opt-in)

New `SadadProvider`. Ships registered but disabled
(`PAYMENT_GATEWAY_SADAD_ENABLED=false`) — DPay gates it per-merchant. No
action needed unless you want to enable it; see
[docs/providers.md § Sadad](docs/providers.md#sadad).

### New: webhooks

New `DPay\Webhooks\WebhookVerifier` and `WebhookEventFactory` (framework-
agnostic), plus an opt-in Laravel receiver route
(`DPAY_WEBHOOKS_ENABLED=false` by default). Entirely additive — nothing to
change unless you want to adopt it. See
[docs/webhooks.md](docs/webhooks.md).
```

- [ ] **Step 2: Verify**

```bash
test -f UPGRADING.md && echo "exists"
grep -c "^### " UPGRADING.md
```
Expected: `exists`, then `7` (seven `###` subsections).

Lint the two PHP-code-bearing examples for syntax validity (they use
undefined variables like `$client`/`$config` on purpose — this only checks
they parse):

```bash
grep -A6 '^```php$' UPGRADING.md | grep -v '^```' | grep -v '^--$' > /tmp/upgrading_snippets.php
sed -i '1i <?php' /tmp/upgrading_snippets.php
php -l /tmp/upgrading_snippets.php
```
Expected: `No syntax errors detected` (or, if the crude extraction above
trips on a boundary, manually verify by eye that every fenced `php` block
is syntactically plausible PHP — the goal is catching typos, not full
extraction fidelity). Delete the scratch file after.

- [ ] **Step 3: Commit**

```bash
git add UPGRADING.md
git commit -m "docs: add UPGRADING.md covering openSession, Transport, minAmount, and capability-flag changes"
```

---

### Task 11: SANDBOX-VALIDATION.md — mark as superseded by the official spec

**Files:**
- Modify: `SANDBOX-VALIDATION.md`

- [ ] **Step 1: Add prose before the table**

Old (full current file):
```markdown
# Sandbox Validation Report

| Scenario | Status | Detail |
|---|---|---|
| `edfali` | fail | Idempotency-Key replay opened a new session (1497) instead of returning the original (1496) |
| `mobicash` | pass | session 1502 paid, amount 10.5 preserved |
| `masrefypay` | pass | session 1503 paid, amount 10.5 preserved |
| `masrefypay-crossbank` | pass | session 1504 paid, amount 10.5 preserved |
| `yousrpay` | pass | session 1505 paid, amount 10.5 preserved |
| `yousrpay-crossbank` | pass | session 1506 paid, amount 10.5 preserved |
| `saharapay` | pass | session 1507 paid, amount 10.5 preserved |
| `saharapay-crossbank` | pass | session 1508 paid, amount 10.5 preserved |
| `moamalat` | pass | payment_link present |
| `sadad` | fail | DPay\Exceptions\DPayValidationException: Unsupported payment method: sadad |
```

New (full file):
```markdown
# Sandbox Validation Report

Regenerated by [tests/sandbox/probe.php](tests/sandbox/probe.php). Superseded
as the primary spec reference by the official DPay API spec
(https://dpay.ly/docs/api) and its Postman collection — treat this file as
corroborating live evidence for what the spec documents, not as the source
of truth itself. `CLAUDE.md` explains the split in more detail.

The two `fail` rows below are documented, understood gaps, not regressions:
`edfali`'s Idempotency-Key replay is a DPay sandbox-side limitation
(reproduced independently via raw `curl`, outside the SDK); `sadad` fails
because the gateway isn't enabled on this merchant account yet, not because
of a code defect — see [docs/providers.md § Sadad](docs/providers.md#sadad).

| Scenario | Status | Detail |
|---|---|---|
| `edfali` | fail | Idempotency-Key replay opened a new session (1497) instead of returning the original (1496) |
| `mobicash` | pass | session 1502 paid, amount 10.5 preserved |
| `masrefypay` | pass | session 1503 paid, amount 10.5 preserved |
| `masrefypay-crossbank` | pass | session 1504 paid, amount 10.5 preserved |
| `yousrpay` | pass | session 1505 paid, amount 10.5 preserved |
| `yousrpay-crossbank` | pass | session 1506 paid, amount 10.5 preserved |
| `saharapay` | pass | session 1507 paid, amount 10.5 preserved |
| `saharapay-crossbank` | pass | session 1508 paid, amount 10.5 preserved |
| `moamalat` | pass | payment_link present |
| `sadad` | fail | DPay\Exceptions\DPayValidationException: Unsupported payment method: sadad |
```

- [ ] **Step 2: Verify**

```bash
grep -n "Superseded as the primary spec reference" SANDBOX-VALIDATION.md
```
Expected: 1 hit. Confirm the table itself is byte-identical to before (no
accidental data changes):

```bash
grep -c "| \`" SANDBOX-VALIDATION.md
```
Expected: `10` (unchanged row count, header + 9 data rows... actually 10
data rows + 1 header separator; just confirm this count matches what it
was before your edit by running the same command against `git show
HEAD:SANDBOX-VALIDATION.md` first and diffing).

- [ ] **Step 3: Commit**

```bash
git add SANDBOX-VALIDATION.md
git commit -m "docs: mark SANDBOX-VALIDATION.md as superseded by the official spec"
```

---

### Task 12: Final Yaser sweep and full-suite regression check

**Files:** none (verification-only task)

- [ ] **Step 1: Repo-wide Yaser grep, excluding intentional historical/planning references**

```bash
grep -rni "yaser" --include="*.md" --include="*.php" . \
  --exclude-dir=vendor --exclude-dir=.git --exclude-dir=.claude \
  | grep -v "CHANGELOG.md:.*\[0.1.0\]" \
  | grep -v "docs/superpowers/specs/\|docs/superpowers/plans/"
```

Expected: **no output**. If anything remains, it's a live doc that Tasks
1–11 should have already fixed — go back and fix it before proceeding.
(`CHANGELOG.md`'s `[0.1.0]` historical section and the `docs/superpowers/`
planning archive are the only permitted hits — they're a record of a past
decision, not living documentation.)

- [ ] **Step 2: Confirm no `src/` files changed**

```bash
git diff --stat main...HEAD -- src/
```
Expected: no output — this plan is docs-only.

- [ ] **Step 3: Full regression check**

```bash
composer check
```
Expected: `PHPStan: [OK] No errors` and the full PHPUnit suite green
(same count as before this plan — this plan doesn't touch `src/` or
`tests/`, so the count should be unchanged from Plan 3's `191 tests, 396
assertions`; if it differs, something outside this plan's scope changed
and needs investigation before continuing).

- [ ] **Step 4: Spot-check cross-file consistency**

```bash
grep -n "min_amount\|minAmount" README.md docs/configuration.md docs/dto-reference.md CLAUDE.md | grep -i "5\b" | grep -v "0.01\|5.0\|# Sadad"
```
Expected: no output (no stray `5` default left anywhere in the four files
most likely to repeat it — a `5` appearing as part of `0.01`, `5.0`, or an
unrelated line like a Sadad category example is fine; a bare `5` presented
as *the* `min_amount` default is not).

- [ ] **Step 5: No commit for this task** — it's verification-only. If Step 1,
3, or 4 surface a problem, fix it in the relevant file, commit that fix
with `git commit -m "docs: fix remaining staleness found in final sweep"`,
and re-run the check that failed.
