<?php

declare(strict_types=1);

namespace DPay\Http;

use DPay\Config\DPayConfig;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayException;
use DPay\Exceptions\DPayNetworkException;
use DPay\Exceptions\DPayRateLimitException;
use DPay\Exceptions\DPaySessionNotFoundException;
use DPay\Exceptions\DPayValidationException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Shared HTTP plumbing for every DPay client.
 *
 * Owns exactly one thing: turning a method/path/body into a decoded array,
 * or into the right exception. Endpoint semantics live in the clients.
 */
final class Transport
{
    public function __construct(
        private readonly DPayConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Perform a request, throwing a mapped DPayException on any non-2xx.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $response = $this->send($method, $path, $body, $headers);

        if (! $this->isSuccessful($response)) {
            $decoded = $this->decode($response);
            $message = (string) ($decoded['message'] ?? 'DPay request failed.');

            $this->logger->error('DPay request failed', [
                'status' => $response->getStatusCode(),
                'method' => $method,
                'path' => $path,
                'message' => $message,
            ]);

            throw $this->buildException($response->getStatusCode(), $message, $decoded);
        }

        return $this->decode($response);
    }

    /**
     * Perform a request, returning null on any non-2xx instead of throwing.
     *
     * Used by verifySession, where a wrong OTP is an ordinary user error
     * rather than an exceptional condition.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    public function attempt(string $method, string $path, ?array $body = null, array $headers = []): ?array
    {
        $response = $this->send($method, $path, $body, $headers);

        if (! $this->isSuccessful($response)) {
            $this->logger->warning('DPay request unsuccessful', [
                'status' => $response->getStatusCode(),
                'method' => $method,
                'path' => $path,
                'message' => (string) ($this->decode($response)['message'] ?? ''),
            ]);

            return null;
        }

        return $this->decode($response);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $path, ?array $body, array $headers): ResponseInterface
    {
        $request = $this->requestFactory
            ->createRequest($method, rtrim($this->config->baseUrl, '/').$path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer '.$this->config->apiKey);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $this->assertEncodable($body);

            // No JSON_PRESERVE_ZERO_FRACTION: 100.0 must encode as 100 to
            // match DPay's documented bodies byte-for-byte.
            $payload = json_encode($body, JSON_THROW_ON_ERROR);

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($payload));
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('DPay transport failure', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new DPayNetworkException('Failed to reach DPay: '.$e->getMessage(), 0, null, $e);
        }
    }

    /**
     * Reject body values json_encode would silently mangle.
     *
     * JSON_THROW_ON_ERROR already covers resources, INF/NAN, recursion and
     * malformed UTF-8. It does NOT cover plain objects: a Closure encodes as
     * {} with no error, silently dropping merchant metadata that DPay would
     * otherwise echo back in webhooks. Fail loudly instead.
     *
     * Runs before json_encode — and therefore before sendRequest — so a body
     * the merchant cannot have meant never opens a session.
     *
     * @param  array<array-key, mixed>  $body  Nested values may be lists, hence array-key.
     */
    private function assertEncodable(array $body, string $path = ''): void
    {
        foreach ($body as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($value)) {
                $this->assertEncodable($value, $here);

                continue;
            }

            if ($value === null || is_scalar($value) || $value instanceof \JsonSerializable) {
                continue;
            }

            throw new DPayValidationException(
                sprintf(
                    'Request field [%s] holds a %s, which cannot be sent to DPay. '
                    .'Use scalars, arrays, or JsonSerializable objects.',
                    $here,
                    get_debug_type($value),
                ),
                422,
            );
        }
    }

    private function isSuccessful(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function buildException(int $status, string $message, array $body): DPayException
    {
        $errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : null;

        return match (true) {
            $status === 401 || $status === 403 => new DPayAuthException($message, $status, $errors),
            $status === 404 => new DPaySessionNotFoundException($message, $status, $errors),
            $status === 429 => new DPayRateLimitException($message, $status, $errors),
            $status >= 400 && $status < 500 => new DPayValidationException($message, $status, $errors),
            default => new DPayException($message, $status, $errors),
        };
    }
}
