<?php

declare(strict_types=1);

namespace DPay\Laravel\Facades;

use DPay\Client\DPayClientInterface;
use DPay\Client\PayMethodsClient;
use DPay\GatewayManager;
use Illuminate\Contracts\Container\Container;

/**
 * Implementation backing the DPay facade.
 *
 * Forwards calls to either the DPayClient (openSession/verifySession/getSession)
 * or the GatewayManager (provider/isEnabled/requiresOtp/features/listEnabled),
 * so a single facade exposes the full surface.
 */
class DPayFacadeAccessor
{
    public function __construct(
        private readonly DPayClientInterface $client,
        private readonly GatewayManager $gateways,
        private readonly PayMethodsClient $payMethods,
    ) {}

    public static function bind(Container $container): void
    {
        $container->singleton(self::class, fn (Container $c) => new self(
            client: $c->make(DPayClientInterface::class),
            gateways: $c->make(GatewayManager::class),
            payMethods: $c->make(PayMethodsClient::class),
        ));
    }

    public function openSession(\DPay\Dto\OpenSessionRequest $request, ?string $idempotencyKey = null): \DPay\Dto\OpenSessionResponse
    {
        return $this->client->openSession($request, $idempotencyKey);
    }

    public function verifySession(int $sessionId, string $otp): ?\DPay\Dto\VerifySessionResponse
    {
        return $this->client->verifySession($sessionId, $otp);
    }

    public function getSession(int $sessionId): \DPay\Dto\GetSessionResponse
    {
        return $this->client->getSession($sessionId);
    }

    /**
     * The live pay-methods reader — DPay's per-merchant gateway list, with
     * `fee`, `min_deposit`, `max_deposit`, `active` and `logo_url`.
     *
     * This is the container's shared singleton, so its memoised list is
     * reused; it performs no HTTP until you actually call list() or find().
     */
    public function payMethods(): PayMethodsClient
    {
        return $this->payMethods;
    }

    public function provider(string $code): \DPay\Contracts\PaymentProviderInterface
    {
        return $this->gateways->provider($code);
    }

    public function isEnabled(string $code): bool
    {
        return $this->gateways->isEnabled($code);
    }

    public function requiresOtp(string $code): bool
    {
        return $this->gateways->requiresOtp($code);
    }

    /**
     * @return array{supports_refund:bool, supports_status_check:bool, supports_webhook:bool}
     */
    public function features(string $code): array
    {
        return $this->gateways->features($code);
    }

    /**
     * @return list<string>
     */
    public function listEnabled(): array
    {
        return $this->gateways->listEnabled();
    }
}
