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
use DPay\Exceptions\DPaySessionNotFoundException;

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

echo "Verifying credentials...\n";

try {
    // A bogus id 404ing (DPaySessionNotFoundException) proves the token and
    // base URL are valid — the request reached DPay and was authenticated,
    // it just didn't find that session. Anything else at this point (auth
    // failure, network error, or any other SDK exception) means the whole
    // run would fail identically on every scenario, so abort loudly instead
    // of grinding through 10 scenarios' worth of pacing and backoff blind.
    $runner->paced(static fn () => $client->getSession(999999999));
} catch (DPaySessionNotFoundException) {
    // Expected — proceed.
} catch (DPayExceptionInterface $e) {
    fwrite(STDERR, "\n=== ABORTING: credentials or connectivity problem, 0 of 10 scenarios exercised ===\n");
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n");
    exit(1);
}

echo "Credentials OK.\n\n";

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

// Error scenarios run last, deliberately: they are the cheapest to redo, so
// if anything here trips the throttle it costs a retry rather than one of the
// payment proofs above.
$badTokenClient = DPayClientFactory::create(new DPayConfig(
    baseUrl: getenv('DPAY_BASE_URL') ?: 'https://dpay.ly/api/sandbox',
    apiKey: 'sb_tk_deliberately_invalid_token',
    timeout: 30,
    mock: false,
    minAmount: 0.01,
));

foreach (dpay_error_scenarios() as $name => $scenario) {
    if (is_string($only) && $only !== '' && $name !== $only) {
        continue;
    }

    if ($runner->isDone($name)) {
        echo "[skip] {$name} — already passed\n";
        continue;
    }

    echo "[run ] {$name} — {$scenario['proves']}\n";

    $target = $scenario['client'] === 'bad-token' ? $badTokenClient : $client;
    $expect = $scenario['expect'];
    $call = $scenario['run'];

    try {
        $runner->paced(static fn () => $call($target));

        $runner->record($name, 'fail', "expected {$expect} but the call succeeded");
    } catch (DPayExceptionInterface $e) {
        $matched = $e instanceof $expect;

        $runner->record(
            $name,
            $matched ? 'pass' : 'fail',
            $matched
                ? $e::class.' as expected: '.$e->getMessage()
                : "expected {$expect}, got ".$e::class.': '.$e->getMessage(),
        );
    }
}

$runner->writeReport(__DIR__.'/../../SANDBOX-VALIDATION.md');

echo "\nLedger:\n";
foreach ($runner->ledger() as $name => $result) {
    printf("  %-24s %s — %s\n", $name, strtoupper($result['status']), $result['detail']);
}
