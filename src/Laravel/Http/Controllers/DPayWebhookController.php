<?php

declare(strict_types=1);

namespace DPay\Laravel\Http\Controllers;

use DPay\Exceptions\InvalidWebhookException;
use DPay\Laravel\Events\DPayWebhookReceived;
use DPay\Webhooks\WebhookEventFactory;
use DPay\Webhooks\WebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Receives DPay webhook deliveries at the route DPayServiceProvider
 * registers (only when dpay.webhooks.enabled is true).
 *
 * Verifies the signature and timestamp BEFORE decoding the body — an
 * unverified request is never parsed into a typed event, let alone
 * dispatched as one. On success, fires DPayWebhookReceived and returns
 * 200 so DPay doesn't retry. On failure, logs (never logging the secret
 * or expected signature) and returns 401 so DPay's retry/backoff applies.
 */
final class DPayWebhookController
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-DPAY-Signature', '');
        $timestamp = (string) $request->header('X-DPAY-Timestamp', '');

        try {
            $this->verifier->verify($rawBody, $signature, $timestamp);
        } catch (InvalidWebhookException $e) {
            $this->logger->warning('DPay webhook rejected: '.$e->getMessage());

            return new JsonResponse(['message' => 'invalid webhook'], 401);
        }

        $decoded = json_decode($rawBody, true);
        $event = WebhookEventFactory::fromArray(is_array($decoded) ? $decoded : []);

        event(new DPayWebhookReceived($event));

        return new JsonResponse(['message' => 'ok']);
    }
}
