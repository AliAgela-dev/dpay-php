<?php

declare(strict_types=1);

namespace DPay\Providers;

use DPay\Dto\PaymentField;

/**
 * Sadad — REST mobile wallet (Almadar Aljadid), OTP-based.
 *
 * The only DPay gateway needing birth_year + category alongside the phone
 * number. No sendOtp()/verifyOtp() override is needed: AbstractDPayProvider's
 * generic wireName()-driven mapping (see sendOtp()) already routes
 * birth_year and category onto OpenSessionRequest — that generic mapping
 * exists specifically so a gateway like this needs no base-class changes.
 *
 * Ships disabled by default (see config/dpay.php) — Sadad is merchant-gated;
 * DPay's sandbox returns "Unsupported payment method: sadad" until the
 * gateway is enabled on the merchant account, confirmed live.
 */
final class SadadProvider extends AbstractDPayProvider
{
    public function code(): string
    {
        return 'sadad';
    }

    public function displayName(): string
    {
        return 'Sadad';
    }

    public function logo(): string
    {
        return 'vendor/dpay/sadad.svg';
    }

    protected function defaultFields(): array
    {
        return [
            PaymentField::phoneNumber(),
            PaymentField::birthYear(),
            PaymentField::sadadCategory(),
        ];
    }
}
