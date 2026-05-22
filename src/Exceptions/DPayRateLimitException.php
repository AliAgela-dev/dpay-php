<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * Thrown when DPay returns 429 Too Many Attempts.
 *
 * The sandbox is aggressive about this — even 4–5 requests in quick
 * succession can trip it. In production you should treat this as a
 * "back off and retry later" signal, not a permanent failure.
 *
 * If DPay supplies a Retry-After header, it'll be in the response
 * but the SDK doesn't currently surface it — read the original
 * exception via $e->getPrevious() if you need to inspect headers.
 */
class DPayRateLimitException extends DPayException {}
