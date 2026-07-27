<?php

declare(strict_types=1);

/**
 * Live sandbox probe.
 *
 * Usage:
 *   DPAY_API_KEY=... php tests/sandbox/probe.php               # all scenarios
 *   DPAY_API_KEY=... php tests/sandbox/probe.php --provider=edfali
 *   DPAY_API_KEY=... php tests/sandbox/probe.php --reset       # clear the ledger
 *
 * Resumable: passing scenarios are skipped on re-run. The token is read from
 * the environment and scrubbed from all output.
 */

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/Scenarios.php';
require __DIR__.'/ProbeRunner.php';

use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\Dto\OpenSessionRequest;
use DPay\Exceptions\DPayExceptionInterface;

$apiKey = getenv('DPAY_API_KEY');

if (! is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "DPAY_API_KEY is not set. Export it before running this probe.\n");
    exit(1);
}

$options = getopt('', ['provider::', 'reset']);

if (isset($options['reset']) && is_file(__DIR__.'/.probe-ledger.json')) {
    unlink(__DIR__.'/.probe-ledger.json');
    echo "Ledger cleared.\n";
}

$client = DPayClientFactory::create(new DPayConfig(
    baseUrl: getenv('DPAY_BASE_URL') ?: 'https://dpay.ly/api/sandbox',
    apiKey: $apiKey,
    timeout: 30,
    mock: false,
    minAmount: 0.01,
));

$runner = new ProbeRunner();
$only = $options['provider'] ?? null;
$otp = '111111';

foreach (dpay_scenarios() as $name => $scenario) {
    if (is_string($only) && $only !== '' && $name !== $only) {
        continue;
    }

    if ($runner->isDone($name)) {
        echo "[skip] {$name} — already passed\n";
        continue;
    }

    echo "[run ] {$name} — {$scenario['proves']}\n";

    try {
        $idempotencyKey = $name === 'edfali' ? bin2hex(random_bytes(16)) : null;

        // Decimal amount proves the truncation fix against the real gateway.
        $session = $runner->paced(static fn () => $client->openSession(new OpenSessionRequest(
            payMethod: $scenario['pay_method'],
            amount: 10.5,
            customerMobile: $scenario['fields']['phone_number'] ?? null,
            cardNumber: $scenario['fields']['card_number'] ?? null,
            birthYear: $scenario['fields']['birth_year'] ?? null,
            category: $scenario['fields']['category'] ?? null,
            description: $scenario['fields']['description'] ?? null,
        ), $idempotencyKey));

        if ($session->amount !== 10.5) {
            $runner->record($name, 'fail', "amount came back as {$session->amount}, expected 10.5");
            continue;
        }

        if ($idempotencyKey !== null) {
            $replay = $runner->paced(static fn () => $client->openSession(new OpenSessionRequest(
                payMethod: $scenario['pay_method'],
                amount: 10.5,
                customerMobile: $scenario['fields']['phone_number'] ?? null,
            ), $idempotencyKey));

            if ($replay->sessionId !== $session->sessionId) {
                $runner->record(
                    $name,
                    'fail',
                    "Idempotency-Key replay opened a new session ({$replay->sessionId}) instead of returning the original ({$session->sessionId})",
                );
                continue;
            }
        }

        if ($scenario['pay_method'] === 'moamalat') {
            $detail = $session->paymentLink === null
                ? 'no payment_link returned'
                : 'payment_link present';

            $runner->record($name, $session->paymentLink === null ? 'fail' : 'pass', $detail);
            continue;
        }

        $paid = $runner->paced(static fn () => $client->verifySession($session->sessionId, $otp));

        $runner->record(
            $name,
            $paid?->isPaid() === true ? 'pass' : 'fail',
            $paid?->isPaid() === true
                ? "session {$session->sessionId} paid, amount 10.5 preserved"
                : 'verify did not return paid',
        );
    } catch (DPayExceptionInterface $e) {
        $runner->record($name, 'fail', $e::class.': '.$e->getMessage());
    }
}

$runner->writeReport(__DIR__.'/../../SANDBOX-VALIDATION.md');

echo "\nLedger:\n";
foreach ($runner->ledger() as $name => $result) {
    printf("  %-24s %s — %s\n", $name, strtoupper($result['status']), $result['detail']);
}
