<?php

declare(strict_types=1);

namespace DPay\Tests\Feature;

use DPay\Client\DPayClientInterface;
use DPay\Config\DPayConfig;
use DPay\Dto\OpenSessionRequest;
use DPay\GatewayManager;
use DPay\Laravel\DPayServiceProvider;
use DPay\Laravel\Facades\DPay;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MoamalatProvider;
use Orchestra\Testbench\TestCase;

final class LaravelBridgeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DPayServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['DPay' => DPay::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('dpay.mock', true);
        $app['config']->set('dpay.api_key', 'unit-test-key');
        $app['config']->set('dpay.gateways.edfali.enabled', true);
        $app['config']->set('dpay.gateways.moamalat.enabled', true);
        $app['config']->set('dpay.gateways.mobicash.enabled', false);
    }

    public function test_service_provider_binds_config_client_and_manager(): void
    {
        self::assertInstanceOf(DPayConfig::class, $this->app->make(DPayConfig::class));
        self::assertInstanceOf(DPayClientInterface::class, $this->app->make(DPayClientInterface::class));
        self::assertInstanceOf(GatewayManager::class, $this->app->make(GatewayManager::class));
    }

    public function test_manager_registers_every_configured_gateway(): void
    {
        $manager = $this->app->make(GatewayManager::class);

        self::assertCount(7, $manager->all());
        self::assertContains('edfali', array_keys($manager->all()));
        self::assertContains('moamalat', array_keys($manager->all()));
    }

    public function test_manager_respects_enabled_flag(): void
    {
        $manager = $this->app->make(GatewayManager::class);

        self::assertTrue($manager->isEnabled('edfali'));
        self::assertFalse($manager->isEnabled('mobicash'));
        self::assertContains('edfali', $manager->listEnabled());
        self::assertNotContains('mobicash', $manager->listEnabled());
    }

    public function test_facade_open_session_uses_mock(): void
    {
        $resp = DPay::openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
        );

        self::assertGreaterThan(0, $resp->sessionId);
        self::assertSame('LYD', $resp->currency);
    }

    public function test_facade_provider_returns_concrete_class(): void
    {
        self::assertInstanceOf(EdfaliProvider::class, DPay::provider('edfali'));
        self::assertInstanceOf(MoamalatProvider::class, DPay::provider('moamalat'));
    }

    public function test_end_to_end_otp_flow_via_facade(): void
    {
        $reference = DPay::provider('edfali')->sendOtp(50, ['phone_number' => '0911234567']);
        self::assertNotSame('', $reference);

        self::assertTrue(DPay::provider('edfali')->verifyOtp($reference, '1234'));
        self::assertFalse(DPay::provider('edfali')->verifyOtp($reference, 'abc'));
    }

    public function test_end_to_end_moamalat_status_flow_via_facade(): void
    {
        $reference = DPay::provider('moamalat')->sendOtp(50, []);
        self::assertNotSame('', $reference);
        self::assertTrue(DPay::provider('moamalat')->verifyOtp($reference, ''));
    }

    public function test_sadad_is_registered_but_disabled_by_default(): void
    {
        $manager = $this->app->make(\DPay\GatewayManager::class);

        self::assertArrayHasKey('sadad', $manager->all());
        self::assertNotContains('sadad', $manager->listEnabled());
        self::assertFalse($manager->isEnabled('sadad'));
    }

    public function test_sadad_can_be_enabled_via_config(): void
    {
        config(['dpay.gateways.sadad.enabled' => true]);

        // GatewayManager is a singleton built once at boot from config, so
        // a runtime config change requires a fresh manager instance to see it.
        $this->app->forgetInstance(\DPay\GatewayManager::class);
        $manager = $this->app->make(\DPay\GatewayManager::class);

        self::assertContains('sadad', $manager->listEnabled());
    }
}
