<?php

declare(strict_types=1);

namespace DPay;

use DPay\Contracts\PaymentProviderInterface;
use DPay\Exceptions\UnknownProviderException;

/**
 * In-memory registry of payment providers.
 *
 * Replaces the Laravel-bound PaymentGatewayManager + the four GatewayManager
 * actions in the original app. No service container required — providers
 * are added via register(), then resolved by code.
 *
 * Disabled providers are rejected at resolve time, matching the original
 * behavior where ResolveGatewayProviderAction throws on disabled gateways.
 */
class GatewayManager
{
    /** @var array<string, PaymentProviderInterface> */
    private array $providers = [];

    public function register(PaymentProviderInterface $provider): self
    {
        $this->providers[$provider->code()] = $provider;

        return $this;
    }

    /**
     * Resolve an enabled provider by its code.
     *
     * @throws UnknownProviderException if the code is not registered, or
     *                                   the matching provider is disabled.
     */
    public function provider(string $code): PaymentProviderInterface
    {
        if (! isset($this->providers[$code])) {
            throw new UnknownProviderException("Payment provider [{$code}] is not supported.");
        }

        $provider = $this->providers[$code];

        if (! $provider->isEnabled()) {
            throw new UnknownProviderException("Payment provider [{$code}] is disabled.");
        }

        return $provider;
    }

    public function isEnabled(string $code): bool
    {
        return isset($this->providers[$code]) && $this->providers[$code]->isEnabled();
    }

    public function requiresOtp(string $code): bool
    {
        return $this->provider($code)->requiresOtp();
    }

    /**
     * @return array{supports_refund:bool, supports_status_check:bool, supports_webhook:bool}
     */
    public function features(string $code): array
    {
        $provider = $this->provider($code);

        return [
            'supports_refund' => $provider->supportsRefund(),
            'supports_status_check' => $provider->supportsStatusCheck(),
            'supports_webhook' => $provider->supportsWebhook(),
        ];
    }

    /**
     * @return list<string>
     */
    public function listEnabled(): array
    {
        $codes = [];
        foreach ($this->providers as $code => $provider) {
            if ($provider->isEnabled()) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Return every registered provider, enabled or not.
     *
     * @return array<string, PaymentProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Frontend-ready listing of every enabled provider. Drop-in replacement
     * for the JSON shape the original app's PaymentMethodController returns.
     *
     * @return list<array{
     *     code: string,
     *     name: string,
     *     logo: string,
     *     requires_otp: bool,
     *     supports_status_check: bool,
     *     supports_refund: bool,
     *     supports_webhook: bool,
     *     required_fields: list<array<string, mixed>>,
     * }>
     */
    public function describe(bool $onlyEnabled = true): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            if ($onlyEnabled && ! $provider->isEnabled()) {
                continue;
            }

            $out[] = [
                'code' => $provider->code(),
                'name' => $provider->displayName(),
                'logo' => $provider->logo(),
                'requires_otp' => $provider->requiresOtp(),
                'supports_status_check' => $provider->supportsStatusCheck(),
                'supports_refund' => $provider->supportsRefund(),
                'supports_webhook' => $provider->supportsWebhook(),
                'required_fields' => array_map(
                    static fn ($f) => $f->toArray(),
                    $provider->requiredFields(),
                ),
            ];
        }

        return $out;
    }
}
