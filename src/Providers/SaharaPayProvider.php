<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Dto\PaymentField;

/**
 * SaharaPay — OTP via 7-digit card number, supports status check.
 *
 * Default field schema: [card_number] (digits:7).
 */
final class SaharaPayProvider extends AbstractDPayProvider
{
    public function code(): string
    {
        return 'saharapay';
    }

    public function displayName(): string
    {
        return 'SaharaPay';
    }

    public function logo(): string
    {
        return 'images/payment-methods/saharapay.svg';
    }

    public function supportsStatusCheck(): bool
    {
        return true;
    }

    protected function defaultFields(): array
    {
        return [PaymentField::cardNumber(digits: 7)];
    }
}
