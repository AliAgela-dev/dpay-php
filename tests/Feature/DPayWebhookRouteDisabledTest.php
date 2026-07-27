<?php

declare(strict_types=1);

namespace DPay\Tests\Feature;

use DPay\Laravel\DPayServiceProvider;
use Orchestra\Testbench\TestCase;

final class DPayWebhookRouteDisabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DPayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('dpay.mock', true);
        $app['config']->set('dpay.api_key', 'k');
        // Deliberately NOT setting dpay.webhooks.enabled — proving the
        // documented default (false) genuinely leaves no route registered.
    }

    public function test_the_webhook_route_does_not_exist_when_disabled(): void
    {
        $response = $this->call('POST', '/webhooks/dpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $response->assertStatus(404);
    }

    public function test_a_missing_secret_does_not_break_the_app_when_webhooks_are_disabled(): void
    {
        // WebhookVerifier throws on construction with an empty secret. If it
        // were bound eagerly, ANY host with webhooks disabled and no secret
        // configured would break on boot. Prove the lazy singleton binding
        // genuinely protects against that: resolving something completely
        // unrelated must not trigger WebhookVerifier's construction at all.
        $manager = $this->app->make(\DPay\GatewayManager::class);

        self::assertInstanceOf(\DPay\GatewayManager::class, $manager);
        self::assertFalse($this->app->resolved(\DPay\Webhooks\WebhookVerifier::class));
    }
}
