<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * The computed HMAC didn't match X-DPAY-Signature.
 *
 * Either the wrong secret is configured, the raw body was mutated in
 * transit (e.g. by middleware re-encoding JSON), or the request is
 * forged. Do not include the expected signature in the message.
 */
class WebhookSignatureMismatchException extends InvalidWebhookException {}
