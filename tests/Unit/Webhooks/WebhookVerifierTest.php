<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Webhooks;

use DPay\Exceptions\WebhookSignatureMismatchException;
use DPay\Exceptions\WebhookTimestampExpiredException;
use DPay\Webhooks\WebhookVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private function sign(string $body, string $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
    }

    public function test_a_correctly_signed_recent_request_passes(): void
    {
        $body = '{"event":"payment.paid","session_id":42}';
        $timestamp = (string) time();

        $this->expectNotToPerformAssertions();

        (new WebhookVerifier(self::SECRET))->verify($body, $this->sign($body, $timestamp), $timestamp);
    }

    public function test_a_tampered_body_fails_signature_verification(): void
    {
        $body = '{"event":"payment.paid","session_id":42}';
        $timestamp = (string) time();
        $signatureForOriginalBody = $this->sign($body, $timestamp);
        $tamperedBody = '{"event":"payment.paid","session_id":99999}';

        $this->expectException(WebhookSignatureMismatchException::class);

        (new WebhookVerifier(self::SECRET))->verify($tamperedBody, $signatureForOriginalBody, $timestamp);
    }

    public function test_wrong_secret_fails_signature_verification(): void
    {
        $body = '{"event":"payment.paid","session_id":42}';
        $timestamp = (string) time();
        $signature = $this->sign($body, $timestamp);

        $this->expectException(WebhookSignatureMismatchException::class);

        (new WebhookVerifier('a-completely-different-secret'))->verify($body, $signature, $timestamp);
    }

    public function test_a_stale_timestamp_is_rejected_even_with_a_correct_signature(): void
    {
        $body = '{"event":"payment.paid","session_id":42}';
        $sixMinutesAgo = (string) (time() - 360);

        $this->expectException(WebhookTimestampExpiredException::class);

        (new WebhookVerifier(self::SECRET))->verify($body, $this->sign($body, $sixMinutesAgo), $sixMinutesAgo);
    }

    public function test_a_future_timestamp_is_also_rejected(): void
    {
        // Defends against clock skew / forged future timestamps, not just replay of old ones.
        $body = '{"event":"payment.paid","session_id":42}';
        $sixMinutesFromNow = (string) (time() + 360);

        $this->expectException(WebhookTimestampExpiredException::class);

        (new WebhookVerifier(self::SECRET))->verify($body, $this->sign($body, $sixMinutesFromNow), $sixMinutesFromNow);
    }

    public function test_a_timestamp_just_inside_the_window_passes(): void
    {
        $body = '{"event":"payment.paid","session_id":42}';
        $fourMinutesAgo = (string) (time() - 240);

        $this->expectNotToPerformAssertions();

        (new WebhookVerifier(self::SECRET))->verify($body, $this->sign($body, $fourMinutesAgo), $fourMinutesAgo);
    }

    public function test_a_timestamp_one_second_past_the_window_is_rejected(): void
    {
        // Deliberately does NOT pair this with a test at exactly -300s expecting
        // success — that boundary can't be pinned without flakiness under random
        // test order (a clock tick during the test would push it to -301 and fail
        // spuriously). -301 has slack: even if a tick pushes it to -302, it's
        // still correctly rejected. This closes the silent-widening gap where
        // MAX_AGE_SECONDS could drift up by tens of seconds undetected.
        $body = '{"event":"payment.paid","session_id":42}';
        $justOutside = (string) (time() - 301);

        $this->expectException(WebhookTimestampExpiredException::class);

        (new WebhookVerifier(self::SECRET))->verify($body, $this->sign($body, $justOutside), $justOutside);
    }

    public function test_a_non_numeric_timestamp_is_rejected(): void
    {
        $body = '{"event":"payment.paid","session_id":42}';

        $this->expectException(WebhookTimestampExpiredException::class);

        (new WebhookVerifier(self::SECRET))->verify($body, $this->sign($body, 'not-a-timestamp'), 'not-a-timestamp');
    }

    public function test_exception_messages_never_contain_the_secret_or_expected_signature(): void
    {
        $body = '{"event":"payment.paid","session_id":42}';
        $timestamp = (string) time();

        try {
            (new WebhookVerifier(self::SECRET))->verify($body, 'deliberately-wrong-signature', $timestamp);
            self::fail('Expected WebhookSignatureMismatchException');
        } catch (WebhookSignatureMismatchException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            // The correct signature is exactly what an attacker is trying to learn —
            // it must never appear in a message they could read back via logs.
            self::assertStringNotContainsString($this->sign($body, $timestamp), $e->getMessage());
        }
    }
}
