<?php

declare(strict_types=1);

namespace DPay\Contracts;

use DPay\Dto\PaymentField;

/**
 * Contract every payment provider in the SDK implements.
 *
 * Ported verbatim from the health-portal implementation at
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
     * In the health-portal layout this returned paths under `public/`
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
     * Whether this provider supports refunds.
     */
    public function supportsRefund(): bool;

    /**
     * Whether this provider supports status checks via getSession.
     */
    public function supportsStatusCheck(): bool;

    /**
     * Whether this provider supports webhook callbacks.
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
