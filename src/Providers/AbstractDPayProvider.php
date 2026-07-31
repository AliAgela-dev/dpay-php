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
 * sendOtp() maps each declared field to its wire name via
 * PaymentField::wireName() (i.e. sendAs, defaulting to the key), so adding a
 * gateway field is a schema change rather than a base-class change.
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
        // See PaymentProviderInterface::supportsWebhook() for what this flag means.
        return true;
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
        $wire = [];

        foreach ($this->fields as $field) {
            $value = $fields[$field->key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $wire[$field->wireName()] = $field->type === 'integer' ? (int) $value : (string) $value;
        }

        $session = $this->client->openSession(new OpenSessionRequest(
            payMethod: $this->payMethod,
            amount: $amount,
            customerMobile: isset($wire['customer_mobile']) ? (string) $wire['customer_mobile'] : null,
            cardNumber: isset($wire['card_number']) ? (string) $wire['card_number'] : null,
            birthYear: isset($wire['birth_year']) ? (string) $wire['birth_year'] : null,
            category: isset($wire['category']) ? (int) $wire['category'] : null,
            description: isset($wire['description']) ? (string) $wire['description'] : null,
        ));

        return (string) $session->sessionId;
    }

    public function verifyOtp(string $reference, string $otp): bool
    {
        $result = $this->client->verifySession((int) $reference, $otp);

        return $result !== null && $result->isPaid();
    }
}
