<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Client\DPayClient;
use DPay\Config\DPayConfig;
use DPay\GatewayManager;
use DPay\Http\Transport;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MobiCashProvider;
use DPay\Providers\MoamalatProvider;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class GatewayManagerDescribeTest extends TestCase
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

    public function test_describe_returns_full_metadata_per_enabled_provider(): void
    {
        $c = $this->client();
        $manager = (new GatewayManager())
            ->register(new EdfaliProvider($c, 'edfali'))
            ->register(new MoamalatProvider($c, 'moamalat'))
            ->register(new MobiCashProvider($c, 'mobicash', enabled: false));

        $rows = $manager->describe();

        self::assertCount(2, $rows, 'disabled providers are filtered by default');

        $edfali = $rows[0];
        self::assertSame('edfali', $edfali['code']);
        self::assertSame('Edfali', $edfali['name']);
        self::assertStringEndsWith('edfali.svg', $edfali['logo']);
        self::assertTrue($edfali['requires_otp']);
        self::assertFalse($edfali['supports_status_check']);
        self::assertCount(1, $edfali['required_fields']);
        self::assertSame('phone_number', $edfali['required_fields'][0]['key']);
        self::assertSame('/^09\d{8}$/', $edfali['required_fields'][0]['regex']);
        self::assertSame('Phone Number', $edfali['required_fields'][0]['labels']['en']);

        $moamalat = $rows[1];
        self::assertSame('moamalat', $moamalat['code']);
        self::assertFalse($moamalat['requires_otp']);
        self::assertTrue($moamalat['supports_status_check']);
        self::assertSame([], $moamalat['required_fields']);
    }

    public function test_describe_includes_disabled_when_requested(): void
    {
        $c = $this->client();
        $manager = (new GatewayManager())
            ->register(new EdfaliProvider($c, 'edfali'))
            ->register(new MoamalatProvider($c, 'moamalat', enabled: false));

        self::assertCount(1, $manager->describe());
        self::assertCount(2, $manager->describe(onlyEnabled: false));
    }
}
