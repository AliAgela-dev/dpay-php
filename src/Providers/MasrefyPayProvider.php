<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Dto\PaymentField;

/**
 * MasrefyPay — OTP via 7-digit card number, supports status check.
 *
 * Default field schema: [card_number] (digits:7).
 */
final class MasrefyPayProvider extends AbstractDPayProvider
{
    public function code(): string
    {
        return 'masrefypay';
    }

    public function displayName(): string
    {
        return 'MasrefyPay';
    }

    public function logo(): string
    {
        return 'images/payment-methods/masrefypay.svg';
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
