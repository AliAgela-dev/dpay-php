<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Dto\PaymentField;

/**
 * MasrefyPay — OTP via card number, supports status check.
 *
 * Default field schema: [card_number] (digitsOneOf: 7 same-bank or 9
 * cross-bank via OnePay).
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
        return 'vendor/dpay/masrefypay.svg';
    }

    public function supportsStatusCheck(): bool
    {
        return true;
    }

    protected function defaultFields(): array
    {
        return [PaymentField::bankCardNumber()];
    }
}
