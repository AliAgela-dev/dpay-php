<?php

declare(strict_types=1);

namespace DPay\Webhooks;

use DPay\Exceptions\WebhookSignatureMismatchException;
use DPay\Exceptions\WebhookTimestampExpiredException;
use InvalidArgumentException;

/**
 * Verifies a DPay webhook request against X-DPAY-Signature and
 * X-DPAY-Timestamp, per https://dpay.ly/docs/api.
 *
 * Signature: hmac_sha256(timestamp + '.' + raw_body, secret), compared in
 * constant time via hash_equals(). Timestamp: rejected if more than 5
 * minutes from now in EITHER direction — an old timestamp is a replay
 * attempt, a future one is clock skew or forgery, neither is legitimate.
 *
 * Framework-agnostic: takes the raw values a controller reads off the
 * request, throws on failure, returns void on success. The Laravel bridge
 * wires this to the actual HTTP request in DPayWebhookController.
 */
final class WebhookVerifier
{
    // Private deliberately: if this were public, a natural future test
    // would be time() - (self::MAX_AGE_SECONDS + 1), which would silently
    // track any accidental widening of the window instead of catching it.
    // See test_a_timestamp_one_second_past_the_window_is_rejected, which
    // uses a hardcoded literal specifically to avoid that trap.
    private const MAX_AGE_SECONDS = 300;

    public function __construct(private readonly string $secret)
    {
        if ($this->secret === '') {
            throw new InvalidArgumentException(
                'WebhookVerifier requires a non-empty secret. Set DPAY_WEBHOOK_SECRET '
                .'(Laravel) or pass one explicitly — an empty secret would silently '
                .'reject every webhook rather than signal misconfiguration.',
            );
        }
    }

    /**
     * @throws WebhookTimestampExpiredException if the timestamp is missing,
     *         non-numeric, or more than 5 minutes from now.
     * @throws WebhookSignatureMismatchException if the HMAC doesn't match.
     */
    public function verify(string $rawBody, string $signature, string $timestamp): void
    {
        if (! ctype_digit($timestamp)) {
            throw new WebhookTimestampExpiredException(
                'X-DPAY-Timestamp is missing or not a valid integer.',
            );
        }

        $age = time() - (int) $timestamp;

        if (abs($age) > self::MAX_AGE_SECONDS) {
            throw new WebhookTimestampExpiredException(
                sprintf('X-DPAY-Timestamp is %d seconds from now, outside the %d-second window.', $age, self::MAX_AGE_SECONDS),
            );
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);

        if (! hash_equals($expected, $signature)) {
            throw new WebhookSignatureMismatchException('X-DPAY-Signature did not match the computed HMAC.');
        }
    }
}
