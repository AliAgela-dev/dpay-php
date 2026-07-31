<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Client\DPayClient;
use DPay\Config\DPayConfig;
use DPay\Exceptions\UnknownProviderException;
use DPay\GatewayManager;
use DPay\Http\Transport;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MoamalatProvider;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class GatewayManagerTest extends TestCase
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

    public function test_register_and_resolve(): void
    {
        $c = $this->client();
        $manager = (new GatewayManager())
            ->register(new EdfaliProvider($c, 'edfali'))
            ->register(new MoamalatProvider($c, 'moamalat'));

        self::assertSame('edfali', $manager->provider('edfali')->code());
        self::assertTrue($manager->isEnabled('edfali'));
        self::assertTrue($manager->requiresOtp('edfali'));
        self::assertFalse($manager->requiresOtp('moamalat'));
    }

    public function test_resolve_unknown_throws(): void
    {
        $manager = new GatewayManager();
        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('not supported');
        $manager->provider('nope');
    }

    public function test_resolve_disabled_throws(): void
    {
        $c = $this->client();
        $manager = (new GatewayManager())->register(new EdfaliProvider($c, 'edfali', enabled: false));

        self::assertFalse($manager->isEnabled('edfali'));
        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('disabled');
        $manager->provider('edfali');
    }

    public function test_features_passthrough(): void
    {
        $c = $this->client();
        $manager = (new GatewayManager())->register(new MoamalatProvider($c, 'moamalat'));

        self::assertSame([
            'supports_refund' => true,
            'supports_status_check' => true,
            'supports_webhook' => true,
        ], $manager->features('moamalat'));
    }

    public function test_list_enabled_skips_disabled(): void
    {
        $c = $this->client();
        $manager = (new GatewayManager())
            ->register(new EdfaliProvider($c, 'edfali'))
            ->register(new MoamalatProvider($c, 'moamalat', enabled: false));

        self::assertSame(['edfali'], $manager->listEnabled());
        self::assertCount(2, $manager->all());
    }
}
