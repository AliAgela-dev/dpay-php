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
