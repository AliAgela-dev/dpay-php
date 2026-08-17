<?php

declare(strict_types=1);

namespace DPay\Laravel;

use DPay\Client\DPayClient;
use DPay\Client\DPayClientFactory;
use DPay\Client\DPayClientInterface;
use DPay\Client\PayMethodsClient;
use DPay\Config\DPayConfig;
use DPay\Contracts\PaymentProviderInterface;
use DPay\Dto\PaymentField;
use DPay\GatewayManager;
use DPay\Laravel\Facades\DPayFacadeAccessor;
use DPay\Laravel\Http\Controllers\DPayWebhookController;
use DPay\Providers\MoamalatProvider;
use DPay\Webhooks\WebhookVerifier;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Laravel integration for the dpay-php SDK.
 *
 * Binds:
 *   - DPayConfig            (from config/dpay.php)
 *   - DPayClientInterface   (Guzzle-backed by default)
 *   - GatewayManager        (singleton, with every enabled provider registered)
 *
 * Publishable:
 *   - config/dpay.php
 *   - resources/logos/*.svg -> public/vendor/dpay/
 */
class DPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'dpay');

        $this->app->singleton(DPayConfig::class, function (Container $app): DPayConfig {
            /** @var array<string, mixed> $cfg */
            $cfg = (array) $app['config']->get('dpay', []);

            return DPayConfig::fromArray($cfg);
        });

        $this->app->singleton(DPayClientInterface::class, function (Container $app): DPayClientInterface {
            $config = $app->make(DPayConfig::class);
            $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

            return DPayClientFactory::create(
                $config,
                logger: $logger,
                validateAgainstLiveLimits: (bool) $app['config']->get('dpay.validate_against_live_limits', false),
            );
        });

        // Singleton deliberately: PayMethodsClient memoises the gateway list
        // for its lifetime, so a fresh instance per resolution would turn
        // every lookup back into an HTTP call.
        $this->app->singleton(PayMethodsClient::class, function (Container $app): PayMethodsClient {
            $config = $app->make(DPayConfig::class);
            $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

            return new PayMethodsClient(DPayClientFactory::createTransport($config, logger: $logger));
        });

        $this->app->alias(DPayClientInterface::class, DPayClient::class);

        $this->app->singleton(GatewayManager::class, function (Container $app): GatewayManager {
            $manager = new GatewayManager();
            $client = $app->make(DPayClientInterface::class);
            /** @var array<string, array<string, mixed>> $gateways */
            $gateways = (array) $app['config']->get('dpay.gateways', []);

            foreach ($gateways as $code => $cfg) {
                if (! is_string($code) || $code === '') {
                    continue;
                }

                $providerClass = $cfg['provider'] ?? null;

                if (! is_string($providerClass) || ! is_a($providerClass, PaymentProviderInterface::class, true)) {
                    throw new InvalidArgumentException("Payment provider [{$code}] is misconfigured.");
                }

                $payMethod = (string) ($cfg['pay_method'] ?? $code);
                $enabled = (bool) ($cfg['enabled'] ?? false);
                $requiredFields = self::buildFields($cfg['required_fields'] ?? null);

                $provider = $providerClass === MoamalatProvider::class
                    ? new MoamalatProvider($client, $payMethod, $enabled, $requiredFields)
                    : new $providerClass($client, $payMethod, $enabled, $requiredFields);

                $manager->register($provider);
            }

            return $manager;
        });

        DPayFacadeAccessor::bind($this->app);

        $this->app->singleton(WebhookVerifier::class, function (Container $app): WebhookVerifier {
            $secret = (string) $app['config']->get('dpay.webhooks.secret', '');

            return new WebhookVerifier($secret);
        });
    }

    public function boot(): void
    {
        if ((bool) $this->app['config']->get('dpay.webhooks.enabled', false)) {
            // Resolve eagerly here, not lazily: enabled=true is an explicit host
            // opt-in, so a missing secret should fail loudly at boot — a normal
            // deploy-time error — rather than as an uncaught 500 on the first
            // real webhook delivery. Hosts who leave webhooks disabled never
            // reach this branch at all, so the original lazy-binding protection
            // (see DPayWebhookRouteDisabledTest) is untouched.
            $this->app->make(WebhookVerifier::class);

            $this->app['router']->post(
                (string) $this->app['config']->get('dpay.webhooks.route', '/webhooks/dpay'),
                [DPayWebhookController::class, 'handle'],
            )->middleware((array) $this->app['config']->get('dpay.webhooks.middleware', []));
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => $this->configPublishPath(),
            ], 'dpay-config');

            $this->publishes([
                $this->logosPath() => $this->logosPublishPath(),
            ], 'dpay-logos');
        }
    }

    /**
     * Build a PaymentField[] from a config value. Accepts:
     *   - null              : keep the provider's default schema
     *   - empty array       : explicit "no fields"
     *   - list of arrays    : custom schema (PaymentField::fromArray() each)
     *   - list of PaymentField instances : passed through unchanged
     *
     * @param  mixed  $raw
     * @return list<PaymentField>|null
     */
    private static function buildFields($raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            return null;
        }

        $out = [];
        foreach ($raw as $entry) {
            if ($entry instanceof PaymentField) {
                $out[] = $entry;
            } elseif (is_array($entry)) {
                $out[] = PaymentField::fromArray($entry);
            }
        }

        return $out;
    }

    private function configPath(): string
    {
        return __DIR__.'/config/dpay.php';
    }

    private function configPublishPath(): string
    {
        /** @var Application $app */
        $app = $this->app;

        return $app->configPath('dpay.php');
    }

    private function logosPath(): string
    {
        return dirname(__DIR__, 2).'/resources/logos';
    }

    private function logosPublishPath(): string
    {
        /** @var Application $app */
        $app = $this->app;

        return $app->publicPath('vendor/dpay');
    }
}
