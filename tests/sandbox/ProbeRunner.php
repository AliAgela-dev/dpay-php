<?php

declare(strict_types=1);

use DPay\Exceptions\DPayRateLimitException;

/**
 * Paced, resumable runner for the sandbox scenarios.
 *
 * The sandbox throttles at roughly four rapid calls, so every call is spaced
 * and 429s are retried with exponential backoff. Results are appended to a
 * ledger so an interrupted run resumes instead of restarting.
 */
final class ProbeRunner
{
    private const LEDGER = __DIR__.'/.probe-ledger.json';

    public function __construct(
        private readonly float $spacingSeconds = 3.0,
        private readonly int $maxRetries = 4,
    ) {}

    /** @return array<string, mixed> */
    public function ledger(): array
    {
        if (! is_file(self::LEDGER)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents(self::LEDGER), true);

        return is_array($raw) ? $raw : [];
    }

    public function record(string $scenario, string $status, string $detail): void
    {
        $ledger = $this->ledger();
        $ledger[$scenario] = ['status' => $status, 'detail' => $this->scrub($detail)];

        file_put_contents(self::LEDGER, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function isDone(string $scenario): bool
    {
        return ($this->ledger()[$scenario]['status'] ?? null) === 'pass';
    }

    /**
     * Run a call, pacing it and backing off on 429.
     *
     * @template T
     * @param  callable():T  $call
     * @return T
     */
    public function paced(callable $call): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            usleep((int) ($this->spacingSeconds * 1_000_000));

            try {
                return $call();
            } catch (DPayRateLimitException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }

                $wait = (2 ** $attempt) * 5;
                fwrite(STDERR, "  429 — backing off {$wait}s\n");
                sleep($wait);
            }
        }
    }

    /**
     * Strip anything resembling an API token before it reaches disk or stdout.
     */
    public function scrub(string $text): string
    {
        return (string) preg_replace('/sb_tk_[A-Za-z0-9]+/', 'sb_tk_[REDACTED]', $text);
    }

    public function writeReport(string $path): void
    {
        $lines = ['# Sandbox Validation Report', '', '| Scenario | Status | Detail |', '|---|---|---|'];

        foreach ($this->ledger() as $scenario => $result) {
            $lines[] = sprintf('| `%s` | %s | %s |', $scenario, $result['status'], $result['detail']);
        }

        file_put_contents($path, implode("\n", $lines)."\n");
    }
}
