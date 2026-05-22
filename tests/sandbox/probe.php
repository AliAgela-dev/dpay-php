<?php

declare(strict_types=1);

/**
 * Live sandbox probe.
 *
 * Hits the real DPay sandbox to validate that the SDK's assumptions about
 * field names, status strings, endpoint paths, and auth scheme are correct.
 *
 * Run:
 *   php tests/sandbox/probe.php
 *
 * Output is printed to stdout AND saved to tests/sandbox/probe-output.log
 * so we have a permanent record to diff against the DTOs.
 *
 * Safe to re-run; each call gets a fresh session_id.
 */

require __DIR__.'/../../vendor/autoload.php';

use DPay\Client\DPayClient;
use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\Exceptions\DPayException;
use DPay\GatewayManager;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MasrefyPayProvider;
use DPay\Providers\MoamalatProvider;
use DPay\Providers\MobiCashProvider;
use DPay\Providers\SaharaPayProvider;
use DPay\Providers\YousrPayProvider;

// --- Sandbox creds (set via environment, never commit the token) ------
$BASE_URL  = getenv('DPAY_BASE_URL') ?: 'https://dpay.ly/api/sandbox';
$API_KEY   = getenv('DPAY_API_KEY');
$TEST_OTP  = '111111';
$BAD_OTP   = '999999';
$AMOUNT    = 50;

if ($API_KEY === '' || $API_KEY === false) {
    fwrite(STDERR, "Set DPAY_API_KEY before running:\n  DPAY_API_KEY=sb_tk_... php tests/sandbox/probe.php\n");
    exit(1);
}

$TEST_DATA = [
    'edfali'     => ['phone_number' => '0912345678'],
    'mobicash'   => ['card_number' => '7279627'],
    'masrefypay' => ['card_number' => '1234567'],
    'yousrpay'   => ['card_number' => '1234567'],
    'saharapay'  => ['card_number' => '1234567'],
    'moamalat'   => [],
];
// ----------------------------------------------------------------------

$logFile = __DIR__.'/probe-output.log';
@unlink($logFile);

$config = new DPayConfig(
    baseUrl: $BASE_URL,
    apiKey: $API_KEY,
    timeout: 30,
    mock: false,
);

$client = DPayClientFactory::create($config);

$manager = (new GatewayManager())
    ->register(new EdfaliProvider($client, 'edfali'))
    ->register(new MobiCashProvider($client, 'mobicash'))
    ->register(new MasrefyPayProvider($client, 'masrefypay'))
    ->register(new YousrPayProvider($client, 'yousrpay'))
    ->register(new SaharaPayProvider($client, 'saharapay'))
    ->register(new MoamalatProvider($client, 'moamalat'));

$out = function (string $msg) use ($logFile) {
    echo $msg.PHP_EOL;
    file_put_contents($logFile, $msg.PHP_EOL, FILE_APPEND);
};

$dump = function ($label, $value) use ($out): void {
    $out("  $label:");
    $out('    '.str_replace("\n", "\n    ", var_export($value, true)));
};

$out('=================================================================');
$out('  DPay sandbox probe');
$out('  base_url: '.$BASE_URL);
$out('  api_key:  '.substr($API_KEY, 0, 12).'…(masked)');
$out('  amount:   '.$AMOUNT.' LYD');
$out('  good OTP: '.$TEST_OTP.'   bad OTP: '.$BAD_OTP);
$out('  started:  '.date('c'));
$out('=================================================================');

// ----------------------------------------------------------------------
// SECTION 1 — Per-provider happy + bad-OTP path
// ----------------------------------------------------------------------

$results = [];

$pause = function (int $ms = 2500) {
    usleep($ms * 1000);
};

foreach ($manager->all() as $code => $provider) {
    $fields = $TEST_DATA[$code] ?? [];

    $out('');
    $out('-----------------------------------------------------------------');
    $out('PROVIDER: '.$code.'    fields: '.json_encode($fields));
    $out('-----------------------------------------------------------------');
    $pause();

    // 1) openSession
    try {
        $session = $provider->sendOtp((float) $AMOUNT, $fields);
        $out('[1] sendOtp() OK -> reference='.$session);
        $results[$code]['sendOtp'] = ['ok' => true, 'ref' => $session];
    } catch (DPayException $e) {
        $out('[1] sendOtp() FAILED: '.get_class($e).' ('.$e->httpStatus.'): '.$e->getMessage());
        if ($e->errors) {
            $dump('errors', $e->errors);
        }
        $results[$code]['sendOtp'] = ['ok' => false, 'msg' => $e->getMessage()];
        continue;   // can't continue with no reference
    }

    // 1b) raw openSession via DPayClient to see the full response shape
    try {
        $resp = $client->openSession(
            payMethod: $code,
            amount: (float) $AMOUNT,
            customerMobile: $fields['phone_number'] ?? null,
            cardNumber: $fields['card_number'] ?? null,
        );
        $out('[1b] raw openSession response:');
        $dump('raw', $resp->raw);
        $results[$code]['openSession_raw'] = $resp->raw;
    } catch (DPayException $e) {
        $out('[1b] raw openSession FAILED: '.$e->getMessage());
    }

    $pause();

    // 2) verifyOtp with the GOOD OTP
    if ($code === 'moamalat') {
        // Moamalat uses status-poll; user must complete LightBox first. Verify
        // will return false because nobody has paid in the sandbox session.
        try {
            $ok = $provider->verifyOtp($session, '');
            $out('[2] verifyOtp() (Moamalat status poll) -> '.($ok ? 'true (paid)' : 'false (still pending)'));
            $results[$code]['verifyOtp_good'] = $ok;
        } catch (DPayException $e) {
            $out('[2] verifyOtp() FAILED: '.$e->getMessage());
        }
    } else {
        try {
            $ok = $provider->verifyOtp($session, $TEST_OTP);
            $out('[2] verifyOtp(\''.$TEST_OTP.'\') -> '.($ok ? 'TRUE (paid)' : 'FALSE (not paid)'));
            $results[$code]['verifyOtp_good'] = $ok;

            // 2b) raw verify response to see actual fields
            $verifyResp = $client->verifySession((int) $session, $TEST_OTP);
            if ($verifyResp !== null) {
                $out('[2b] raw verifySession response:');
                $dump('raw', $verifyResp->raw);
                $results[$code]['verifySession_raw'] = $verifyResp->raw;
            } else {
                $out('[2b] raw verifySession returned null (session may already be consumed)');
            }
        } catch (DPayException $e) {
            $out('[2] verifyOtp() FAILED: '.$e->getMessage());
        }
    }

    $pause();

    // 3) verifyOtp with a BAD OTP (skip Moamalat — different flow)
    if ($code !== 'moamalat') {
        try {
            // Need a fresh session because step 2 consumed the previous one.
            $session2 = $provider->sendOtp((float) $AMOUNT, $fields);
            $ok = $provider->verifyOtp($session2, $BAD_OTP);
            $out('[3] verifyOtp(\''.$BAD_OTP.'\' BAD) -> '.($ok ? 'TRUE (UNEXPECTED!)' : 'FALSE (expected)'));
            $results[$code]['verifyOtp_bad'] = $ok;
        } catch (DPayException $e) {
            $out('[3] verifyOtp(bad) FAILED with exception: '.$e->getMessage());
        }
    }

    $pause();

    // 4) getSession on a known id
    if (isset($results[$code]['sendOtp']['ref'])) {
        try {
            $get = $client->getSession((int) $results[$code]['sendOtp']['ref']);
            $out('[4] getSession() OK status='.$get->status->value);
            $dump('raw', $get->raw);
            $results[$code]['getSession_raw'] = $get->raw;
        } catch (DPayException $e) {
            $out('[4] getSession() FAILED: '.$e->getMessage());
        }
    }
}

// ----------------------------------------------------------------------
// SECTION 2 — Negative paths
// ----------------------------------------------------------------------

$out('');
$out('=================================================================');
$out('  Negative paths');
$out('=================================================================');

$pause(5000);   // give the rate limiter a breather before negative paths

// 5) Fractional amount (caught client-side)
try {
    $client->openSession('edfali', 50.5, customerMobile: '0912345678');
    $out('[5] fractional amount: UNEXPECTED success');
} catch (DPayException $e) {
    $out('[5] fractional amount: '.get_class($e).': '.$e->getMessage());
}

// 6) Below min amount (caught client-side)
try {
    $client->openSession('edfali', 1, customerMobile: '0912345678');
    $out('[6] below min amount: UNEXPECTED success');
} catch (DPayException $e) {
    $out('[6] below min amount: '.get_class($e).': '.$e->getMessage());
}

$pause();

// 7) Unknown pay_method (server side)
try {
    $client->openSession('nonexistent_method', 50, customerMobile: '0912345678');
    $out('[7] unknown pay_method: UNEXPECTED success');
} catch (DPayException $e) {
    $out('[7] unknown pay_method: '.get_class($e).' ('.$e->httpStatus.'): '.$e->getMessage());
    if ($e->errors) { $dump('errors', $e->errors); }
}

$pause();

// 8) getSession on non-existent id
try {
    $client->getSession(99999999);
    $out('[8] bogus session id: UNEXPECTED success');
} catch (DPayException $e) {
    $out('[8] bogus session id: '.get_class($e).' ('.$e->httpStatus.'): '.$e->getMessage());
}

$pause();

// 9) Auth failure (wrong key) — use a separate client so we don't corrupt $client
try {
    $badClient = DPayClientFactory::create(new DPayConfig(
        baseUrl: $BASE_URL,
        apiKey: 'sb_tk_INVALID_KEY_FOR_TESTING',
        timeout: 10,
    ));
    $badClient->openSession('edfali', 50, customerMobile: '0912345678');
    $out('[9] bad API key: UNEXPECTED success');
} catch (DPayException $e) {
    $out('[9] bad API key: '.get_class($e).' ('.$e->httpStatus.'): '.$e->getMessage());
}

$out('');
$out('=================================================================');
$out('  Probe finished: '.date('c'));
$out('  Full log: '.$logFile);
$out('=================================================================');
