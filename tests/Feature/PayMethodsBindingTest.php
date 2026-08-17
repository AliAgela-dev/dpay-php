<?php

declare(strict_types=1);

namespace DPay\Tests\Feature;

use DPay\Client\PayMethodsClient;
use DPay\Laravel\DPayServiceProvider;
use Orchestra\Testbench\TestCase;

final class PayMethodsBindingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DPayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('dpay.api_key', 'k');
        $app['config']->set('dpay.mock', true);
    }

    public function test_the_pay_methods_client_is_resolvable_and_a_singleton(): void
    {
        $a = $this->app->make(PayMethodsClient::class);
        $b = $this->app->make(PayMethodsClient::class);

        self::assertInstanceOf(PayMethodsClient::class, $a);
        // Must be shared, or the memoised list is rebuilt per resolution and
        // every lookup becomes a fresh HTTP call.
        self::assertSame($a, $b, 'PayMethodsClient must be a singleton to keep its memoised list.');
    }

    public function test_live_limit_validation_is_off_by_default(): void
    {
        self::assertFalse((bool) config('dpay.validate_against_live_limits'));
    }

    public function test_the_facade_accessor_exposes_pay_methods(): void
    {
        $accessor = $this->app->make(\DPay\Laravel\Facades\DPayFacadeAccessor::class);

        self::assertInstanceOf(PayMethodsClient::class, $accessor->payMethods());
    }
}
