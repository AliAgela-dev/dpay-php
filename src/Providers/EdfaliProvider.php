<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Dto\PaymentField;

/**
 * Edfali — OTP via mobile number.
 *
 * Default field schema: [phone_number] (regex /^09\d{8}$/).
 */
final class EdfaliProvider extends AbstractDPayProvider
{
    public function code(): string
    {
        return 'edfali';
    }

    public function displayName(): string
    {
        return 'Edfali';
    }

    public function logo(): string
    {
        return 'images/payment-methods/edfali.svg';
    }

    protected function defaultFields(): array
    {
        return [PaymentField::phoneNumber()];
    }
}
