<?php

declare(strict_types=1);

namespace DPay\Laravel\Facades;

use DPay\Client\DPayClientInterface;
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
    ) {}

    public static function bind(Container $container): void
    {
        $container->singleton(self::class, fn (Container $c) => new self(
            client: $c->make(DPayClientInterface::class),
            gateways: $c->make(GatewayManager::class),
        ));
    }

    public function openSession(string $payMethod, float $amount, ?string $customerMobile = null, ?string $cardNumber = null, ?string $description = null): \DPay\Dto\OpenSessionResponse
    {
        return $this->client->openSession($payMethod, $amount, $customerMobile, $cardNumber, $description);
    }

    public function verifySession(int $sessionId, string $otp): ?\DPay\Dto\VerifySessionResponse
    {
        return $this->client->verifySession($sessionId, $otp);
    }

    public function getSession(int $sessionId): \DPay\Dto\GetSessionResponse
    {
        return $this->client->getSession($sessionId);
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
