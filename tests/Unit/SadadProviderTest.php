<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Providers\SadadProvider;
use PHPUnit\Framework\TestCase;

final class SadadProviderTest extends TestCase
{
    private function clientDouble(): \DPay\Client\DPayClientInterface
    {
        return new class implements \DPay\Client\DPayClientInterface {
            public ?\DPay\Dto\OpenSessionRequest $seen = null;

            public function openSession(\DPay\Dto\OpenSessionRequest $request, ?string $idempotencyKey = null): \DPay\Dto\OpenSessionResponse
            {
                $this->seen = $request;

                return \DPay\Dto\OpenSessionResponse::fromArray(['session_id' => 1, 'status' => 'pending']);
            }

            public function verifySession(int $sessionId, string $otp): ?\DPay\Dto\VerifySessionResponse
            {
                return null;
            }

            public function getSession(int $sessionId): \DPay\Dto\GetSessionResponse
            {
                return \DPay\Dto\GetSessionResponse::fromArray(['session_id' => $sessionId, 'status' => 'paid']);
            }
        };
    }

    public function test_identity(): void
    {
        $provider = new SadadProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'sadad',
        );

        self::assertSame('sadad', $provider->code());
        self::assertSame('Sadad', $provider->displayName());
        self::assertSame('vendor/dpay/sadad.svg', $provider->logo());
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

    // --- Wire-mapping behavior (does the schema actually produce the right body?) ---

    public function test_send_otp_produces_the_spec_golden_body(): void
    {
        $client = $this->clientDouble();

        $provider = new SadadProvider($client, 'sadad');

        $provider->sendOtp(100, [
            'phone_number' => '0912345678',
            'birth_year' => '1994',
            'category' => 20,
        ]);

        // Golden body from the official Postman collection — proves the
        // schema-driven mapping, not just that the fields exist.
        self::assertSame(
            '{"pay_method":"sadad","amount":100,"customer_mobile":"0912345678","birth_year":"1994","category":20}',
            json_encode($client->seen?->toBody()),
        );
    }

    public function test_category_zero_reaches_the_wire(): void
    {
        // Category 0 is a valid Sadad category (e-commerce default) and must
        // not be dropped by a truthiness check anywhere in the pipeline.
        $client = $this->clientDouble();

        $provider = new SadadProvider($client, 'sadad');
        $provider->sendOtp(100, ['phone_number' => '0912345678', 'birth_year' => '1994', 'category' => 0]);

        self::assertArrayHasKey('category', $client->seen?->toBody());
        self::assertSame(0, $client->seen?->toBody()['category']);
    }

    public function test_omitting_category_uses_the_merchant_default(): void
    {
        // Category is optional — omitting it must not send category:null or
        // category:"" to DPay; the key must be absent so the merchant's
        // configured default applies server-side.
        $client = $this->clientDouble();

        $provider = new SadadProvider($client, 'sadad');
        $provider->sendOtp(100, ['phone_number' => '0912345678', 'birth_year' => '1994']);

        self::assertArrayNotHasKey('category', $client->seen?->toBody());
    }
}
