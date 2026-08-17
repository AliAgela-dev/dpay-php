<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Client;

use DPay\Client\PayMethodsClient;
use DPay\Config\DPayConfig;
use DPay\Dto\PayMethod;
use DPay\Exceptions\DPayAuthException;
use DPay\Http\Transport;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class PayMethodsClientTest extends TestCase
{
    /**
     * Verbatim from the official Postman collection's success example.
     *
     * @return list<array<string, mixed>>
     */
    private function goldenBody(): array
    {
        return [
            ['name' => 'EDFali', 'slug' => 'edfali', 'icon' => 'edfali.png', 'logo_url' => 'https://dpay.ly/assets/img/logos/edfali.svg', 'active' => true, 'fee' => 2.5, 'min_deposit' => 1, 'max_deposit' => 5000],
            ['name' => 'MobiCash', 'slug' => 'mobicash', 'icon' => 'mobicash.png', 'logo_url' => 'https://dpay.ly/assets/img/logos/mobicash.svg', 'active' => true, 'fee' => 2.5, 'min_deposit' => 1, 'max_deposit' => 10000],
            ['name' => 'MasrefyPay', 'slug' => 'masrefypay', 'icon' => 'masrefypay.png', 'logo_url' => 'https://dpay.ly/assets/img/logos/masrefypay.svg', 'active' => true, 'fee' => 3.33, 'min_deposit' => 1, 'max_deposit' => 5000],
        ];
    }

    private function client(FakeHttpClient $http): PayMethodsClient
    {
        $psr17 = new Psr17Factory();
        $config = new DPayConfig(baseUrl: 'https://dpay.ly/api', apiKey: 'k');

        return new PayMethodsClient(new Transport($config, $http, $psr17, $psr17));
    }

    public function test_it_parses_the_documented_list_keyed_by_slug(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, $this->goldenBody());

        $methods = $this->client($http)->list();

        self::assertSame(['edfali', 'mobicash', 'masrefypay'], array_keys($methods));
        self::assertContainsOnlyInstancesOf(PayMethod::class, $methods);
        self::assertSame(10000.0, $methods['mobicash']->maxDeposit);
    }

    public function test_it_calls_the_documented_endpoint(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, $this->goldenBody());

        $this->client($http)->list();

        self::assertSame('GET', $http->lastRequest()->getMethod());
        self::assertSame('https://dpay.ly/api/pay-methods', (string) $http->lastRequest()->getUri());
    }

    public function test_the_list_is_fetched_once_and_memoised(): void
    {
        // Only ONE response is queued. FakeHttpClient throws if a second
        // request is made, so a repeat call proves memoisation rather than
        // merely asserting a count.
        $http = (new FakeHttpClient())->queueJson(200, $this->goldenBody());
        $client = $this->client($http);

        $client->list();
        $client->list();
        $client->find('edfali');

        self::assertCount(1, $http->sent);
    }

    public function test_refresh_forces_a_second_fetch(): void
    {
        $http = (new FakeHttpClient())
            ->queueJson(200, $this->goldenBody())
            ->queueJson(200, []);
        $client = $this->client($http);

        $client->list();
        $client->refresh();
        $client->list();

        self::assertCount(2, $http->sent);
    }

    public function test_find_returns_the_matching_method(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, $this->goldenBody());

        $found = $this->client($http)->find('masrefypay');

        self::assertInstanceOf(PayMethod::class, $found);
        self::assertSame(3.33, $found->fee);
    }

    public function test_find_returns_null_for_a_slug_dpay_does_not_list(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, $this->goldenBody());

        self::assertNull($this->client($http)->find('sadad'));
    }

    public function test_an_empty_list_is_valid_and_not_an_error(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);

        self::assertSame([], $this->client($http)->list());
    }

    public function test_transport_exceptions_propagate(): void
    {
        // The client does not swallow errors; fail-open is the caller's
        // policy decision, applied in DPayClient, not buried here.
        $http = (new FakeHttpClient())->queueJson(401, ['message' => 'Invalid sandbox API token.']);

        $this->expectException(DPayAuthException::class);
        $this->client($http)->list();
    }

    public function test_entries_that_are_not_objects_are_skipped(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, [
            ['slug' => 'edfali', 'active' => true],
            'unexpected scalar',
            ['no_slug' => 'dropped'],
        ]);

        self::assertSame(['edfali'], array_keys($this->client($http)->list()));
    }
}
