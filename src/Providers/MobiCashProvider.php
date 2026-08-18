<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Dto\PaymentField;

/**
 * MobiCash — OTP via 7-digit card number.
 *
 * Default field schema: [card_number] (digits:7).
 * Note: sends card_number only, no customer_mobile.
 */
final class MobiCashProvider extends AbstractDPayProvider
{
    public function code(): string
    {
        return 'mobicash';
    }

    public function displayName(): string
    {
        return 'MobiCash';
    }

    public function logo(): string
    {
        return 'vendor/dpay/mobicash.svg';
    }

    protected function defaultFields(): array
    {
        return [PaymentField::cardNumber(digits: 7)];
    }
}
