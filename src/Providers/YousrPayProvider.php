<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Dto\PaymentField;

/**
 * YousrPay — OTP via card number, supports status check.
 *
 * Default field schema: [card_number] (digitsOneOf: 7 same-bank or 9
 * cross-bank via OnePay).
 */
final class YousrPayProvider extends AbstractDPayProvider
{
    public function code(): string
    {
        return 'yousrpay';
    }

    public function displayName(): string
    {
        return 'YousrPay';
    }

    public function logo(): string
    {
        return 'vendor/dpay/yousrpay.svg';
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
