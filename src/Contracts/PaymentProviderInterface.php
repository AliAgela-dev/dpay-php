<?php

declare(strict_types=1);

namespace DPay\Contracts;

use DPay\Dto\PaymentField;

/**
 * Contract every payment provider in the SDK implements.
 *
 * Ported verbatim from the original production implementation at
 *   app/Services/Payment/Contracts/PaymentProviderInterface.php
 * so host applications can swap their existing imports for this namespace
 * without changing call sites.
 */
interface PaymentProviderInterface
{
    /**
     * Unique provider code (e.g. 'edfali', 'mobicash').
     */
    public function code(): string;

    /**
     * Human-readable provider name shown to end users.
     */
    public function displayName(): string;

    /**
     * Relative path to the provider's logo image.
     *
     * In the original app's layout this returned paths under `public/`
     * (e.g. "images/payment-methods/edfali.svg"). The SDK keeps the same
     * shape; the Laravel bridge publishes the bundled SVGs into
     * `public/vendor/dpay/` so the URL works out of the box.
     */
    public function logo(): string;

    /**
     * Whether this provider is enabled from config.
     */
    public function isEnabled(): bool;

    /**
     * Whether this provider requires OTP verification.
     */
    public function requiresOtp(): bool;

    /**
     * Whether DPay supports refunds for this gateway.
     *
     * This describes DPay-side capability, NOT an SDK-invokable action —
     * there is no refund()/void() method on this interface. Where true
     * (currently Moamalat only), refunds and voids are triggered from the
     * DPay dashboard and observed via the payment.refunded / payment.voided
     * webhooks, not called through this SDK. Consumers building a checkout
     * UI off GatewayManager::describe() should not render an in-app "Refund"
     * action from this flag alone.
     */
    public function supportsRefund(): bool;

    /**
     * Whether this provider supports status checks via getSession.
     */
    public function supportsStatusCheck(): bool;

    /**
     * Whether DPay can deliver webhook events for this gateway.
     *
     * Webhooks are configured account-wide at Dashboard -> Webhooks, not
     * per-gateway, so this is true for every provider. It signals that
     * payment.paid/failed/expired/refunded/voided events are available for
     * this gateway's sessions. Signature verification and typed event
     * parsing live in DPay\Webhooks\* (WebhookVerifier, WebhookEventFactory);
     * the Laravel bridge adds an opt-in receiver route — see docs/webhooks.md.
     */
    public function supportsWebhook(): bool;

    /**
     * Send an OTP / initiate a charge for the given amount.
     *
     * `$fields` is the validated map of payment-method-specific inputs
     * (e.g. `phone_number`, `card_number`). The shape is documented per
     * provider; callers are expected to validate before invoking.
     *
     * Must return a unique reference string used to identify the transaction
     * during the verify step (typically the DPay session_id as a string).
     *
     * @param  array<string, mixed>  $fields
     */
    public function sendOtp(float $amount, array $fields): string;

    /**
     * Verify the given OTP against the reference.
     *
     * Returns true on successful settlement, false otherwise (wrong OTP,
     * expired session, etc.). Should NOT throw for normal user-error
     * cases — callers expect a boolean.
     */
    public function verifyOtp(string $reference, string $otp): bool;

    /**
     * Schema describing the keys this provider reads from sendOtp()'s
     * $fields argument. Empty array means sendOtp ignores $fields
     * (Moamalat works this way).
     *
     * Hosts can override the per-provider default via constructor
     * (or via Laravel config), e.g. to tighten regex or add a locale.
     *
     * @return list<PaymentField>
     */
    public function requiredFields(): array;
}
