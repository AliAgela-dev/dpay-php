<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Providers\SadadProvider;
use PHPUnit\Framework\TestCase;

final class SadadProviderTest extends TestCase
{
    public function test_identity(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        self::assertSame('sadad', $provider->code());
        self::assertSame('Sadad', $provider->displayName());
        self::assertSame('images/payment-methods/sadad.svg', $provider->logo());
    }

    public function test_default_fields_are_phone_birth_year_and_category(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        $keys = array_map(static fn ($f) => $f->key, $provider->requiredFields());

        self::assertSame(['phone_number', 'birth_year', 'category'], $keys);
    }

    public function test_category_is_the_only_optional_field(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        $required = array_map(static fn ($f) => $f->required, $provider->requiredFields());

        self::assertSame([true, true, false], $required);
    }

    public function test_requires_otp(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        self::assertTrue($provider->requiresOtp());
    }

    public function test_inherits_universal_capability_flags(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        // Webhooks are account-level (Plan 1, AbstractDPayProvider::supportsWebhook).
        self::assertTrue($provider->supportsWebhook());
        // Refunds are Moamalat-only per the spec; Sadad gets no special case.
        self::assertFalse($provider->supportsRefund());
        // No override needed: Sadad doesn't poll getSession() for status the
        // way SaharaPay/YousrPay/MasrefyPay do, so this stays the
        // AbstractDPayProvider default of false, same as Edfali.
        self::assertFalse($provider->supportsStatusCheck());
    }
}
