<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Test double for PSR-18 ClientInterface. Each test queues responses
 * (status + JSON body) and the fake hands them out in FIFO order while
 * recording the requests it received.
 *
 * Also exposes a transport-failure hook so DPayNetworkException paths
 * can be exercised.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<array{status:int, body:string}> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $sent = [];

    public ?\Exception $throwOnNext = null;

    private Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    public function queueJson(int $status, array|string $body): self
    {
        $this->queue[] = [
            'status' => $status,
            'body' => is_string($body) ? $body : (string) json_encode($body),
        ];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        if ($this->throwOnNext !== null) {
            $e = $this->throwOnNext;
            $this->throwOnNext = null;
            // Wrap any exception in a ClientExceptionInterface to satisfy PSR-18.
            throw new class($e->getMessage(), 0, $e) extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
        }

        if ($this->queue === []) {
            throw new RuntimeException('FakeHttpClient: no queued response for '.$request->getMethod().' '.$request->getUri());
        }

        $next = array_shift($this->queue);

        return $this->factory
            ->createResponse($next['status'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($next['body']));
    }

    public function lastRequest(): RequestInterface
    {
        if ($this->sent === []) {
            throw new RuntimeException('FakeHttpClient: no requests sent yet.');
        }

        return $this->sent[count($this->sent) - 1];
    }
}
