<?php

declare(strict_types=1);

/**
 * Focused second-pass probe for providers/paths still rate-limited from the
 * first run: yousrpay, saharapay, moamalat, and the unknown-pay-method path.
 *
 * Uses much longer delays.
 */

require __DIR__.'/../../vendor/autoload.php';

use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\Exceptions\DPayException;
use DPay\GatewayManager;
use DPay\Providers\MoamalatProvider;
use DPay\Providers\SaharaPayProvider;
use DPay\Providers\YousrPayProvider;

$apiKey = getenv('DPAY_API_KEY');
if ($apiKey === '' || $apiKey === false) {
    fwrite(STDERR, "Set DPAY_API_KEY before running:\n  DPAY_API_KEY=sb_tk_... php tests/sandbox/probe-remaining.php\n");
    exit(1);
}

$client = DPayClientFactory::create(new DPayConfig(
    baseUrl: getenv('DPAY_BASE_URL') ?: 'https://dpay.ly/api/sandbox',
    apiKey: $apiKey,
    timeout: 30,
    mock: false,
));

$manager = (new GatewayManager())
    ->register(new YousrPayProvider($client, 'yousrpay'))
    ->register(new SaharaPayProvider($client, 'saharapay'))
    ->register(new MoamalatProvider($client, 'moamalat'));

$TEST_DATA = [
    'yousrpay'  => ['card_number' => '1234567'],
    'saharapay' => ['card_number' => '1234567'],
    'moamalat'  => [],
];
$OTP = '111111';
$AMOUNT = 50;

$logFile = __DIR__.'/probe-output.log';
$out = function (string $msg) use ($logFile) {
    echo $msg.PHP_EOL;
    file_put_contents($logFile, $msg.PHP_EOL, FILE_APPEND);
};

$out('');
$out('=================================================================');
$out('  SECOND-PASS PROBE (yousrpay, saharapay, moamalat) at '.date('c'));
$out('  using 12s delays to avoid rate limiter');
$out('=================================================================');

sleep(15);   // cool-down before starting

foreach ($manager->all() as $code => $provider) {
    $fields = $TEST_DATA[$code] ?? [];
    $out('');
    $out('PROVIDER: '.$code);

    try {
        $ref = $provider->sendOtp((float) $AMOUNT, $fields);
        $out('[1] sendOtp() OK -> reference='.$ref);

        // raw shape
        $resp = $client->openSession(
            payMethod: $code,
            amount: (float) $AMOUNT,
            customerMobile: $fields['phone_number'] ?? null,
            cardNumber: $fields['card_number'] ?? null,
        );
        $out('[1b] raw openSession response:');
        $out('  '.str_replace("\n", "\n  ", var_export($resp->raw, true)));
    } catch (DPayException $e) {
        $out('[1] sendOtp() FAILED: '.get_class($e).' ('.$e->httpStatus.'): '.$e->getMessage());
        sleep(12);
        continue;
    }

    sleep(12);

    if ($code === 'moamalat') {
        $ok = $provider->verifyOtp($ref, '');
        $out('[2] Moamalat status poll -> '.($ok ? 'paid' : 'still pending (expected — user must finish in LightBox)'));
    } else {
        try {
            $ok = $provider->verifyOtp($ref, $OTP);
            $out('[2] verifyOtp(\''.$OTP.'\') -> '.($ok ? 'TRUE' : 'FALSE'));

            $vr = $client->verifySession((int) $ref, $OTP);
            if ($vr !== null) {
                $out('[2b] raw verifySession response:');
                $out('  '.str_replace("\n", "\n  ", var_export($vr->raw, true)));
            }
        } catch (DPayException $e) {
            $out('[2] verifyOtp FAILED: '.get_class($e).': '.$e->getMessage());
        }
    }

    sleep(12);
}

$out('');
$out('Retrying unknown pay_method test...');
sleep(15);
try {
    $client->openSession('totally_nonexistent_method', 50, customerMobile: '0912345678');
    $out('[7] unknown pay_method: UNEXPECTED success');
} catch (DPayException $e) {
    $out('[7] unknown pay_method: '.get_class($e).' ('.$e->httpStatus.'): '.$e->getMessage());
}

$out('');
$out('Second-pass finished: '.date('c'));
