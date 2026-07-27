<?php

declare(strict_types=1);

namespace DPay\Tests\Feature;

use DPay\GatewayManager;
use DPay\Laravel\DPayServiceProvider;
use DPay\Webhooks\WebhookVerifier;
use Illuminate\Http\Request;
use InvalidArgumentException;
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
        $manager = $this->app->make(GatewayManager::class);

        self::assertInstanceOf(GatewayManager::class, $manager);
        self::assertFalse($this->app->resolved(WebhookVerifier::class));
    }

    public function test_enabled_with_no_secret_fails_fast_at_boot_not_on_first_request(): void
    {
        // The app was already built once in setUp() with webhooks disabled
        // (defineEnvironment() above never sets dpay.webhooks.enabled), so
        // DPayServiceProvider::boot() already ran and skipped the webhook
        // branch entirely. To exercise the enabled+no-secret path we flip
        // the config now and re-invoke boot() directly on a fresh provider
        // instance bound to the same container — register() already ran
        // during the original app boot, so every binding boot() depends on
        // (config, router, the WebhookVerifier singleton definition) is
        // already in place; only boot()'s own logic needs to run again.
        config(['dpay.webhooks.enabled' => true]);
        config(['dpay.webhooks.secret' => '']);

        $this->app->forgetInstance(WebhookVerifier::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty secret');

        (new DPayServiceProvider($this->app))->boot();
    }

    public function test_configured_middleware_is_applied_to_the_webhook_route(): void
    {
        config(['dpay.webhooks.enabled' => true]);
        config(['dpay.webhooks.secret' => 'whsec_test']);
        config(['dpay.webhooks.middleware' => ['throttle:5,1']]);

        $this->app->forgetInstance(WebhookVerifier::class);

        // Re-trigger boot() the same way as the fail-fast test above, now
        // with a valid secret so it registers the route instead of throwing.
        (new DPayServiceProvider($this->app))->boot();

        $route = $this->app['router']->getRoutes()->match(
            Request::create('/webhooks/dpay', 'POST'),
        );

        self::assertContains('throttle:5,1', $route->gatherMiddleware());
    }
}
