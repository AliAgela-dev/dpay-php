# Checkout flow — end-to-end walkthrough

This guide shows how to charge a customer through `dpay-php` from start to
finish. It covers the two flows the SDK supports:

- **OTP flow** — Edfali, MobiCash, SaharaPay, YousrPay, MasrefyPay.
  User enters a one-time code; we verify it.
- **Status-poll flow** — Moamalat. User completes payment in a Lightbox/redirect;
  we poll until the session reports `paid`.

Every snippet has a **pure-PHP** and a **Laravel** version.

---

## The mental model

A charge has **three persistent moments**:

```
1. INITIATE       2. WAIT FOR USER       3. VERIFY
─────────────     ─────────────────      ────────────
sendOtp() ──▶     user types OTP   ──▶   verifyOtp(reference, otp)
returns                                  returns true/false
"reference"
```

You **must** persist the `reference` (typically the DPay `session_id` as a
string) between steps 1 and 3 — keyed to the cart / order / appointment the
user is paying for. The SDK is stateless; it has no idea what the reference
is for.

For Moamalat the shape is the same — the only difference is that step 3
ignores the `otp` argument and just asks DPay "is this session paid yet?".

---

## Step 0 — choose what to show in the UI

Render only the providers your tenant has enabled:

### Pure PHP

```php
use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\GatewayManager;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MoamalatProvider;

$client = DPayClientFactory::create(new DPayConfig(
    apiKey: getenv('DPAY_API_KEY'),
    mock: false,
));

$gateways = (new GatewayManager())
    ->register(new EdfaliProvider($client, payMethod: 'edfali'))
    ->register(new MoamalatProvider($client, payMethod: 'moamalat'));

foreach ($gateways->listEnabled() as $code) {
    $p = $gateways->provider($code);
    echo "[{$p->code()}] {$p->displayName()} — OTP: ".($p->requiresOtp() ? 'yes' : 'no')."\n";
}
```

### Laravel

```php
use DPay\Laravel\Facades\DPay;

return collect(DPay::listEnabled())->map(fn (string $code) => [
    'code'         => $code,
    'name'         => DPay::provider($code)->displayName(),
    'logo'         => asset('vendor/dpay/'.$code.'.svg'),
    'requires_otp' => DPay::requiresOtp($code),
]);
```

---

## Step 1 — initiate the charge

The user picked a provider and (for OTP flows) entered their phone or card.
Call `sendOtp()` — for OTP providers it triggers DPay to text the OTP to the
user; for Moamalat it opens a session that the front-end will redirect into.

### Pure PHP

```php
use DPay\Exceptions\DPayValidationException;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayNetworkException;

try {
    $reference = $gateways->provider('edfali')->sendOtp(
        amount: 50,
        fields: ['phone_number' => '0911234567'],
    );

    // PERSIST the reference. e.g. row in a `payments` table keyed to the order:
    //   INSERT INTO payments (order_id, provider, reference, status, amount)
    //   VALUES (?, 'edfali', ?, 'pending', 50)
    $orderRepo->markPaymentInitiated($orderId, 'edfali', $reference, 50);
} catch (DPayValidationException $e) {
    // 422 from DPay — amount too small, fractional, missing field, etc.
    return ['error' => $e->getMessage()];
} catch (DPayAuthException) {
    // bad/expired API key — operational, not user-facing
    throw $e;
} catch (DPayNetworkException) {
    // transient — let the user retry
    return ['error' => 'Payment gateway is unreachable. Please try again.'];
}
```

### Laravel (controller + action)

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

    Payment::create([
        'order_id'  => $order->id,
        'provider'  => $data['method'],
        'reference' => $reference,
        'status'    => 'pending',
        'amount'    => $order->total_amount,
    ]);

    return response()->json([
        'reference'    => $reference,
        'requires_otp' => DPay::requiresOtp($data['method']),
    ]);
}
```

**Important fields per provider:**

| Provider | Required `$fields` keys |
|---|---|
| `edfali` | `phone_number` |
| `mobicash`, `saharapay`, `yousrpay`, `masrefypay` | `card_number` |
| `sadad` | `phone_number`, `birth_year`, `category` (optional) |
| `moamalat` | _(none — the user pays via redirect/Lightbox)_ |

---

## Step 2 — let the user complete their side

**OTP providers:** the user receives an SMS and enters it into your form.
No SDK call happens here — your front-end collects the OTP and POSTs it to
your verify endpoint.

**Moamalat:** the front-end needs the `payment_link` from the session. Either
call `openSession()` directly to get the full response, or call
`getSession($reference)` to retrieve it later:

```php
use DPay\Dto\OpenSessionRequest;

$session = DPay::openSession(new OpenSessionRequest(
    payMethod: 'moamalat',
    amount: $order->total_amount,
));
$paymentLink = $session->paymentLink;   // hand to the front-end
// Persist $session->sessionId as the reference.
```

---

## Step 3 — verify

Look up the persisted reference, then call `verifyOtp()`. For Moamalat pass an
empty OTP — it's ignored, the SDK polls the session status instead.

### Pure PHP

```php
$payment = $orderRepo->findPendingPayment($orderId);   // your code
$paid = $gateways->provider($payment->provider)
    ->verifyOtp($payment->reference, $userEnteredOtp);

if ($paid) {
    $orderRepo->markPaymentCompleted($payment->id);
    $orderRepo->markOrderConfirmed($orderId);
} else {
    // OTP wrong, session expired, or status still pending.
    // Tell the user to retry — do NOT mark the payment failed yet,
    // they can try the OTP again until the session expires.
}
```

### Laravel

```php
// app/Http/Controllers/CheckoutController.php
public function verify(Request $request)
{
    $data = $request->validate([
        'reference' => 'required|string',
        'otp'       => 'required|string',
    ]);

    $payment = Payment::where('reference', $data['reference'])
        ->where('status', 'pending')
        ->firstOrFail();

    $paid = DB::transaction(function () use ($payment, $data) {
        // Lock the row so two concurrent verifies can't double-confirm.
        $locked = Payment::lockForUpdate()->find($payment->id);

        $ok = DPay::provider($locked->provider)
            ->verifyOtp($locked->reference, $data['otp']);

        if ($ok) {
            $locked->update(['status' => 'paid']);
            $locked->order->update(['status' => 'confirmed']);
        }

        return $ok;
    });

    return response()->json([
        'success' => $paid,
        'message' => $paid ? 'Payment confirmed' : 'OTP invalid or expired',
    ]);
}
```

---

## Polling Moamalat

For Moamalat the user might take a while in the Lightbox. Two options:

**A) Front-end polls** your verify endpoint every few seconds — same code as
above, the front-end just retries until it gets `success: true` or gives up
after a timeout.

**B) Backend uses the lower-level client** to inspect the session directly:

```php
use DPay\Dto\SessionStatus;
use DPay\Exceptions\DPaySessionNotFoundException;

try {
    $session = DPay::getSession((int) $payment->reference);
} catch (DPaySessionNotFoundException) {
    // DPay purged the session — treat as expired.
    $payment->update(['status' => 'expired']);
    return;
}

match ($session->status) {
    SessionStatus::PAID    => $payment->update(['status' => 'paid']),
    SessionStatus::EXPIRED => $payment->update(['status' => 'expired']),
    SessionStatus::FAILED  => $payment->update(['status' => 'failed']),
    default                => null,   // still pending, keep polling
};
```

Wrap that in a queued job that re-runs every 30s for up to 15 minutes, then
gives up.

> **Prefer webhooks over polling where you can.** The polling approach
> above still works, but `payment.paid`/`payment.expired`/etc. webhooks
> now exist and update the same instant DPay knows the answer, without a
> queued job hammering `getSession()`. See [docs/webhooks.md](webhooks.md).

---

## Edge cases

| What happens | Where it surfaces |
|---|---|
| Amount below `min_amount` (default `0.01`) | `sendOtp` throws `DPayValidationException` before any HTTP call. |
| Amount has many decimal places (e.g. `49.999`) | Sent to DPay as-is — the SDK does not round or reject it. DPay's own validation applies server-side. |
| User enters wrong OTP | `verifyOtp` returns `false`. The session is still alive — they can retry until it expires. |
| Session expired | `verifyOtp` returns `false`. The reference is dead; start over with a new `sendOtp`. |
| DPay returns 401 (bad API key) | `DPayAuthException`. Not user-facing — fix your env. |
| DPay unreachable / timeout | `DPayNetworkException`. Show "try again", don't mark the payment failed. |
| Mock mode on, any 4–6 digit OTP | `verifyOtp` returns `true`. Used for dev/staging. |

---

## A safety rule

Always check `isPaid()` (or `verifyOtp` returning `true`) **before** marking
an order paid — never assume settlement from a 200 response on `openSession`.
Opening a session is just creating a charge intent; the user still has to
complete it.

```php
// WRONG — this just opens a session
use DPay\Dto\OpenSessionRequest;

$session = DPay::openSession(new OpenSessionRequest(
    payMethod: 'edfali',
    amount: 50.0,
    customerMobile: '0911234567',
));
$order->markPaid();   // ❌ user hasn't paid yet

// RIGHT — wait for verification
$ref = DPay::provider('edfali')->sendOtp(50, ['phone_number' => '0911234567']);
// ... user enters OTP ...
if (DPay::provider('edfali')->verifyOtp($ref, $otp)) {
    $order->markPaid();   // ✓ settled
}
```
