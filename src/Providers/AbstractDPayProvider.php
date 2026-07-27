<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Client\DPayClientInterface;
use DPay\Contracts\PaymentProviderInterface;
use DPay\Dto\OpenSessionRequest;
use DPay\Dto\PaymentField;

/**
 * Shared base for all DPay-backed OTP providers.
 *
 * Concrete subclasses declare:
 *   - static identity (code, displayName, logo)
 *   - default field schema via defaultFields()
 *
 * sendOtp() reads the field schema to decide what to forward to DPay:
 *   - a 'phone_number' field      -> openSession(..., customerMobile: ...)
 *   - a 'card_number' field       -> openSession(..., cardNumber: ...)
 * No special-casing per provider; everything is data-driven.
 */
abstract class AbstractDPayProvider implements PaymentProviderInterface
{
    /** @var list<PaymentField> */
    private readonly array $fields;

    /**
     * @param  list<PaymentField>|null  $requiredFields  override the per-provider default
     */
    public function __construct(
        protected readonly DPayClientInterface $client,
        protected readonly string $payMethod,
        protected readonly bool $enabled = true,
        ?array $requiredFields = null,
    ) {
        $this->fields = $requiredFields ?? $this->defaultFields();
    }

    /**
     * Per-provider default field schema. Override in each subclass.
     *
     * @return list<PaymentField>
     */
    abstract protected function defaultFields(): array;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function requiresOtp(): bool
    {
        return true;
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function supportsStatusCheck(): bool
    {
        return false;
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

    public function sendOtp(float $amount, array $fields): string
    {
        $phoneNumber = null;
        $cardNumber = null;

        foreach ($this->fields as $field) {
            $value = isset($fields[$field->key]) ? (string) $fields[$field->key] : null;
            if ($value === '') {
                $value = null;
            }

            if ($field->key === 'phone_number') {
                $phoneNumber = $value;
            } elseif ($field->key === 'card_number') {
                $cardNumber = $value;
            }
        }

        $session = $this->client->openSession(new OpenSessionRequest(
            payMethod: $this->payMethod,
            amount: $amount,
            customerMobile: $phoneNumber,
            cardNumber: $cardNumber,
        ));

        return (string) $session->sessionId;
    }

    public function verifyOtp(string $reference, string $otp): bool
    {
        $result = $this->client->verifySession((int) $reference, $otp);

        return $result !== null && $result->isPaid();
    }
}
