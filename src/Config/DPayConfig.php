<?php

declare(strict_types=1);

namespace DPay\Config;

use InvalidArgumentException;

final class DPayConfig
{
    public function __construct(
        public readonly string $baseUrl = 'https://dpay.ly/api',
        public readonly string $apiKey = '',
        public readonly int $timeout = 15,
        public readonly bool $mock = false,
        /**
         * Client-side pre-flight floor, in LYD. Defaults to DPay's documented
         * minimum of 0.01.
         *
         * Set to 0 to disable the pre-flight check and let the gateway be the
         * sole authority on validity — an invalid amount then costs one
         * round-trip and comes back as a 422 rather than being rejected
         * locally. Values between 0 and 0.01 are accepted for the same reason:
         * this is the SDK's floor, not a mirror of DPay's.
         */
        public readonly float $minAmount = 0.01,
    ) {
        if ($timeout < 1) {
            throw new InvalidArgumentException('timeout must be >= 1 second.');
        }

        if ($minAmount < 0) {
            throw new InvalidArgumentException('minAmount must be >= 0.');
        }
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    public static function fromArray(array $cfg): self
    {
        return new self(
            baseUrl: (string) ($cfg['base_url'] ?? 'https://dpay.ly/api'),
            apiKey: (string) ($cfg['api_key'] ?? ''),
            timeout: (int) ($cfg['timeout'] ?? 15),
            mock: (bool) ($cfg['mock'] ?? false),
            minAmount: (float) ($cfg['min_amount'] ?? 0.01),
        );
    }
}
