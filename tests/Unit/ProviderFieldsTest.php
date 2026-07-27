<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Client\DPayClient;
use DPay\Config\DPayConfig;
use DPay\Dto\PaymentField;
use DPay\Http\Transport;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MasrefyPayProvider;
use DPay\Providers\MoamalatProvider;
use DPay\Providers\MobiCashProvider;
use DPay\Providers\SaharaPayProvider;
use DPay\Providers\YousrPayProvider;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ProviderFieldsTest extends TestCase
{
    private function client(): DPayClient
    {
        $f = new Psr17Factory();
        $config = new DPayConfig();

        return new DPayClient(
            config: $config,
            transport: new Transport($config, new FakeHttpClient(), $f, $f),
        );
    }

    public function test_phone_otp_providers_default_to_phone_field(): void
    {
        $provider = new EdfaliProvider($this->client(), 'edfali');
        $fields = $provider->requiredFields();

        self::assertCount(1, $fields);
        self::assertSame('phone_number', $fields[0]->key);
        self::assertSame('/^09\d{8}$/', $fields[0]->regex);
    }

    public function test_card_otp_providers_default_to_7_digit_card_field(): void
    {
        foreach ([
            MobiCashProvider::class,
            SaharaPayProvider::class,
            YousrPayProvider::class,
            MasrefyPayProvider::class,
        ] as $cls) {
            $provider = new $cls($this->client(), 'x');
            $fields = $provider->requiredFields();

            self::assertCount(1, $fields, $cls);
            self::assertSame('card_number', $fields[0]->key, $cls);
            self::assertSame(7, $fields[0]->digits, $cls);
        }
    }

    public function test_moamalat_default_is_empty(): void
    {
        $provider = new MoamalatProvider($this->client(), 'moamalat');
        self::assertSame([], $provider->requiredFields());
    }

    public function test_constructor_override_replaces_default(): void
    {
        $custom = [new PaymentField(key: 'phone_number', regex: '/^09[1-6]\d{7}$/')];
        $provider = new EdfaliProvider($this->client(), 'edfali', requiredFields: $custom);

        self::assertSame('/^09[1-6]\d{7}$/', $provider->requiredFields()[0]->regex);
    }

    public function test_send_otp_picks_phone_or_card_based_on_field_schema(): void
    {
        // Edfali (phone-only)
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'session_id' => 1, 'status' => 'pending', 'amount' => 50, 'currency' => 'LYD',
            'fee' => 0, 'fee_amount' => 0, 'total' => 50, 'pay_method' => 'x', 'expired_at' => '', 'data' => null,
        ]);
        $f = new Psr17Factory();
        $config = new DPayConfig();
        $c = new DPayClient($config, new Transport($config, $http, $f, $f));

        $edfali = new EdfaliProvider($c, 'edfali');
        $edfali->sendOtp(50, ['phone_number' => '0911234567', 'card_number' => '4242']);

        $body = json_decode((string) $http->lastRequest()->getBody(), true);
        self::assertSame('0911234567', $body['customer_mobile']);
        self::assertArrayNotHasKey('card_number', $body);

        // MobiCash (card-only)
        $http2 = new FakeHttpClient();
        $http2->queueJson(200, [
            'session_id' => 2, 'status' => 'pending', 'amount' => 50, 'currency' => 'LYD',
            'fee' => 0, 'fee_amount' => 0, 'total' => 50, 'pay_method' => 'x', 'expired_at' => '', 'data' => null,
        ]);
        $config2 = new DPayConfig();
        $c2 = new DPayClient($config2, new Transport($config2, $http2, $f, $f));
        $mobicash = new MobiCashProvider($c2, 'mobicash');
        $mobicash->sendOtp(50, ['phone_number' => '0911234567', 'card_number' => '1234567']);

        $body2 = json_decode((string) $http2->lastRequest()->getBody(), true);
        self::assertSame('1234567', $body2['card_number']);
        self::assertArrayNotHasKey('customer_mobile', $body2);
    }

    public function test_fields_reach_the_wire_under_their_send_as_name(): void
    {
        $client = new class implements \DPay\Client\DPayClientInterface {
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

        $provider = new \DPay\Providers\EdfaliProvider($client, 'edfali');
        $provider->sendOtp(50, ['phone_number' => '0912345678']);

        self::assertSame('0912345678', $client->seen?->customerMobile);
    }

    public function test_a_field_with_a_mismatched_key_and_send_as_still_reaches_the_right_wire_name(): void
    {
        // Deliberately proves routing is driven by wireName(), not by a
        // hardcoded key check. Under the old hardcoded implementation this
        // field would never reach customerMobile, because its key isn't
        // literally 'phone_number' — only sendAs says where it belongs.
        $client = new class implements \DPay\Client\DPayClientInterface {
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

        $provider = new \DPay\Providers\EdfaliProvider(
            $client,
            'edfali',
            true,
            [new \DPay\Dto\PaymentField(key: 'mobile', sendAs: 'customer_mobile')],
        );

        $provider->sendOtp(50, ['mobile' => '0912345678']);

        self::assertSame('0912345678', $client->seen?->customerMobile);
    }
}
