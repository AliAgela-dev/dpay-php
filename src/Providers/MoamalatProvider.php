<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Client\DPayClientInterface;
use DPay\Contracts\PaymentProviderInterface;
use DPay\Dto\OpenSessionRequest;
use DPay\Dto\PaymentField;

/**
 * Moamalat — payment-link / status-check flow, NOT OTP.
 *
 * Diverges from AbstractDPayProvider:
 *   - sendOtp opens a session with no customer_mobile / card_number.
 *     The DPay response carries the payment_link the front-end uses to
 *     drive Moamalat's LightBox UI.
 *   - verifyOtp ignores the OTP parameter and polls getSession,
 *     returning true once the status is 'paid'.
 *
 * Default requiredFields() is [] — the health-portal seeder includes a
 * 16-digit card field for legacy reasons, but the provider never reads
 * it. Hosts that want to keep collecting that field client-side can
 * override via the constructor or Laravel config.
 */
final class MoamalatProvider implements PaymentProviderInterface
{
    /** @var list<PaymentField> */
    private readonly array $fields;

    /**
     * @param  list<PaymentField>|null  $requiredFields  override the default ([])
     */
    public function __construct(
        private readonly DPayClientInterface $client,
        private readonly string $payMethod = 'moamalat',
        private readonly bool $enabled = true,
        ?array $requiredFields = null,
    ) {
        $this->fields = $requiredFields ?? [];
    }

    public function code(): string
    {
        return 'moamalat';
    }

    public function displayName(): string
    {
        return 'Moamalat';
    }

    public function logo(): string
    {
        return 'images/payment-methods/moamalat.svg';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function requiresOtp(): bool
    {
        return false;
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function supportsStatusCheck(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    /**
     * @return list<PaymentField>
     */
    public function requiredFields(): array
    {
        return $this->fields;
    }

    /**
     * Opens a Moamalat session and returns the session_id.
     * The payment_link for the LightBox UI is available via getSession()
     * or by inspecting OpenSessionResponse on a direct DPayClient call.
     */
    public function sendOtp(float $amount, array $fields): string
    {
        $session = $this->client->openSession(new OpenSessionRequest(
            payMethod: $this->payMethod,
            amount: $amount,
        ));

        return (string) $session->sessionId;
    }

    /**
     * Moamalat does not use OTP verification. The OTP parameter is ignored;
     * payment status is polled via getSession instead.
     */
    public function verifyOtp(string $reference, string $otp): bool
    {
        return $this->client->getSession((int) $reference)->isPaid();
    }
}
