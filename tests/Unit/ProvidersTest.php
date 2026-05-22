<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Client\DPayClient;
use DPay\Config\DPayConfig;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MasrefyPayProvider;
use DPay\Providers\MoamalatProvider;
use DPay\Providers\MobiCashProvider;
use DPay\Providers\SaharaPayProvider;
use DPay\Providers\YousrPayProvider;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ProvidersTest extends TestCase
{
    private function client(FakeHttpClient $http): DPayClient
    {
        $f = new Psr17Factory();

        return new DPayClient(
            config: new DPayConfig(baseUrl: 'https://dpay.example/api', apiKey: 'k'),
            httpClient: $http,
            requestFactory: $f,
            streamFactory: $f,
        );
    }

    private function queueOpenSession(FakeHttpClient $http, int $sessionId = 1234): void
    {
        $http->queueJson(200, [
            'session_id' => $sessionId,
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'LYD',
            'fee' => 2.5,
            'fee_amount' => 1.25,
            'total' => 51.25,
            'pay_method' => 'x',
            'expired_at' => '',
            'data' => null,
        ]);
    }

    public function test_edfali_sends_customer_mobile_only(): void
    {
        $http = new FakeHttpClient();
        $this->queueOpenSession($http);
        $provider = new EdfaliProvider($this->client($http), 'edfali');

        $ref = $provider->sendOtp(50, ['phone_number' => '0911234567', 'card_number' => '4242']);

        self::assertSame('1234', $ref);
        self::assertSame('edfali', $provider->code());
        self::assertTrue($provider->requiresOtp());

        $body = json_decode((string) $http->lastRequest()->getBody(), true);
        self::assertSame('edfali', $body['pay_method']);
        self::assertSame('0911234567', $body['customer_mobile']);
        self::assertArrayNotHasKey('card_number', $body, 'Edfali must not send card_number');
    }

    public function test_mobicash_sends_card_number_only(): void
    {
        $http = new FakeHttpClient();
        $this->queueOpenSession($http);
        $provider = new MobiCashProvider($this->client($http), 'mobicash');

        $provider->sendOtp(50, ['phone_number' => '0911234567', 'card_number' => '4242']);

        $body = json_decode((string) $http->lastRequest()->getBody(), true);
        self::assertSame('4242', $body['card_number']);
        self::assertArrayNotHasKey('customer_mobile', $body, 'MobiCash must not send customer_mobile');
        self::assertFalse($provider->supportsStatusCheck());
    }

    public function test_saharapay_yousrpay_masrefypay_send_card_and_support_status_check(): void
    {
        foreach ([SaharaPayProvider::class, YousrPayProvider::class, MasrefyPayProvider::class] as $cls) {
            $http = new FakeHttpClient();
            $this->queueOpenSession($http);
            /** @var \DPay\Contracts\PaymentProviderInterface $provider */
            $provider = new $cls($this->client($http), 'x');

            $provider->sendOtp(50, ['card_number' => '4242']);
            $body = json_decode((string) $http->lastRequest()->getBody(), true);

            self::assertSame('4242', $body['card_number'], $cls);
            self::assertArrayNotHasKey('customer_mobile', $body, $cls);
            self::assertTrue($provider->supportsStatusCheck(), $cls);
            self::assertTrue($provider->requiresOtp(), $cls);
        }
    }

    public function test_moamalat_opens_session_without_mobile_or_card(): void
    {
        $http = new FakeHttpClient();
        $this->queueOpenSession($http, sessionId: 7777);
        $provider = new MoamalatProvider($this->client($http), 'moamalat');

        $ref = $provider->sendOtp(50, ['phone_number' => '0911234567']);

        self::assertSame('7777', $ref);
        self::assertFalse($provider->requiresOtp());
        self::assertTrue($provider->supportsStatusCheck());

        $body = json_decode((string) $http->lastRequest()->getBody(), true);
        self::assertSame(['pay_method' => 'moamalat', 'amount' => 50], $body);
    }

    public function test_moamalat_verify_polls_get_session(): void
    {
        $http = new FakeHttpClient();
        // Note: no openSession queued — we go straight to getSession for verifyOtp.
        $http->queueJson(200, [
            'session_id' => 7777,
            'status' => 'paid',
            'amount' => 50,
            'currency' => 'LYD',
            'pay_method' => 'moamalat',
            'expired_at' => '',
            'data' => null,
        ]);

        $provider = new MoamalatProvider($this->client($http), 'moamalat');

        $ok = $provider->verifyOtp('7777', '');
        self::assertTrue($ok);
        self::assertSame('GET', $http->lastRequest()->getMethod());
        self::assertStringEndsWith('/payment/sessions/7777', (string) $http->lastRequest()->getUri());
    }

    public function test_otp_provider_verify_returns_true_on_paid_status(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(200, [
            'message' => 'ok',
            'payment_id' => 1,
            'status' => 'paid',
            'amount' => 50,
            'currency' => 'LYD',
            'pay_method' => 'edfali',
            'tx_id' => 't',
        ]);

        $provider = new EdfaliProvider($this->client($http), 'edfali');

        self::assertTrue($provider->verifyOtp('1234', '5555'));
    }

    public function test_otp_provider_verify_returns_false_on_bad_otp(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(422, ['message' => 'invalid otp']);

        $provider = new EdfaliProvider($this->client($http), 'edfali');

        self::assertFalse($provider->verifyOtp('1234', '0000'));
    }

    public function test_disabled_provider_reports_disabled(): void
    {
        $http = new FakeHttpClient();
        $provider = new EdfaliProvider($this->client($http), 'edfali', enabled: false);
        self::assertFalse($provider->isEnabled());
    }
}
