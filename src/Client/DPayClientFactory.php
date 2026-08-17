<?php

declare(strict_types=1);

namespace DPay\Client;

use DPay\Config\DPayConfig;
use DPay\Http\Transport;
use DPay\Support\MockTransport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Convenience factory for environments without a configured DI container.
 *
 * If the host project already has PSR-18 / PSR-17 implementations wired
 * up, prefer instantiating DPayClient directly. This factory falls back
 * to Guzzle (if installed) so a brand-new project can do:
 *
 *   $client = DPayClientFactory::create(DPayConfig::fromArray([...]));
 *
 * Throws RuntimeException if no HTTP client is available and Guzzle is not installed.
 */
final class DPayClientFactory
{
    /**
     * @param  bool  $validateAgainstLiveLimits  Opt in to checking each amount
     *   against DPay's live per-gateway `min_deposit`/`max_deposit` before a
     *   session is opened. Off by default, because it is an extra call on
     *   first use and changes when openSession() can throw. Pass it by name
     *   — `validateAgainstLiveLimits: true` — so the call site says what it
     *   means. See PayMethodsClient for the memoisation and fail-open rules.
     */
    public static function create(
        DPayConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?MockTransport $mockTransport = null,
        bool $validateAgainstLiveLimits = false,
    ): DPayClient {
        $transport = self::createTransport($config, $httpClient, $requestFactory, $streamFactory, $logger);

        return new DPayClient(
            config: $config,
            transport: $transport,
            mockTransport: $mockTransport,
            // Deliberately shares the same Transport, so auth, timeouts and
            // logging are identical for both endpoints.
            payMethods: $validateAgainstLiveLimits ? new PayMethodsClient($transport) : null,
        );
    }

    /**
     * Build just the Transport, applying the same dependency-guessing as
     * create().
     *
     * Public because PayMethodsClient composes over a Transport too, and a
     * host that wants one without a DPayClient shouldn't have to reimplement
     * the Guzzle/Nyholm fallbacks.
     */
    public static function createTransport(
        DPayConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ): Transport {
        return new Transport(
            config: $config,
            httpClient: $httpClient ?? self::guessHttpClient($config),
            requestFactory: $requestFactory ?? self::guessRequestFactory(),
            streamFactory: $streamFactory ?? self::guessStreamFactory(),
            logger: $logger ?? new \Psr\Log\NullLogger(),
        );
    }

    private static function guessHttpClient(DPayConfig $config): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client([
                'timeout' => $config->timeout,
                'connect_timeout' => min($config->timeout, 10),
                'http_errors' => false,
            ]);
        }

        throw new RuntimeException(
            'No PSR-18 HTTP client supplied and guzzlehttp/guzzle is not installed. '
            .'Install it via "composer require guzzlehttp/guzzle" or pass your own ClientInterface.'
        );
    }

    private static function guessRequestFactory(): RequestFactoryInterface
    {
        if (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            return new \GuzzleHttp\Psr7\HttpFactory();
        }

        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            return new \Nyholm\Psr7\Factory\Psr17Factory();
        }

        throw new RuntimeException(
            'No PSR-17 request factory supplied. Install guzzlehttp/psr7 or nyholm/psr7, '
            .'or pass your own RequestFactoryInterface.'
        );
    }

    private static function guessStreamFactory(): StreamFactoryInterface
    {
        if (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            return new \GuzzleHttp\Psr7\HttpFactory();
        }

        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            return new \Nyholm\Psr7\Factory\Psr17Factory();
        }

        throw new RuntimeException(
            'No PSR-17 stream factory supplied. Install guzzlehttp/psr7 or nyholm/psr7, '
            .'or pass your own StreamFactoryInterface.'
        );
    }
}
