<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Dto\PaymentField;

/**
 * YousrPay — OTP via 7-digit card number, supports status check.
 *
 * Default field schema: [card_number] (digits:7).
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
        return 'images/payment-methods/yousrpay.svg';
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
