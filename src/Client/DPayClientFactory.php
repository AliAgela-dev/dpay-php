<?php

declare(strict_types=1);

namespace DPay\Client;

use DPay\Config\DPayConfig;
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
    public static function create(
        DPayConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?MockTransport $mockTransport = null,
    ): DPayClient {
        $httpClient ??= self::guessHttpClient($config);
        $requestFactory ??= self::guessRequestFactory();
        $streamFactory ??= self::guessStreamFactory();

        return new DPayClient(
            config: $config,
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            logger: $logger ?? new \Psr\Log\NullLogger(),
            mockTransport: $mockTransport,
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
