<?php

declare(strict_types=1);

namespace DPay\Client;

use DPay\Dto\PayMethod;
use DPay\Http\Transport;

/**
 * Reads GET /pay-methods — the live, per-merchant gateway list.
 *
 * This is the only authoritative source for a gateway's `fee`, `min_deposit`
 * and `max_deposit`, all of which are configured per pay method from DPay's
 * dashboard and therefore differ between merchants. No SDK constant can
 * substitute for it.
 *
 * **The list is fetched once and memoised for this instance's lifetime.**
 * The values change rarely (a dashboard edit) and the alternative is a
 * network round-trip on every lookup, so a long-lived container pays one
 * call rather than one per payment. Call refresh() after changing anything
 * in the dashboard, or construct a new client.
 *
 * Errors are **not** swallowed here. Whether a failed lookup should block a
 * payment is a policy decision, and it belongs to the caller — DPayClient
 * applies fail-open explicitly rather than having it hidden in this class.
 */
final class PayMethodsClient
{
    /** @var array<string, PayMethod>|null null until the first fetch */
    private ?array $memoised = null;

    public function __construct(private readonly Transport $transport) {}

    /**
     * Every pay method DPay reports for this merchant, keyed by slug.
     *
     * @return array<string, PayMethod>
     */
    public function list(): array
    {
        if ($this->memoised !== null) {
            return $this->memoised;
        }

        $methods = [];

        foreach ($this->transport->requestList('GET', '/pay-methods') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $method = PayMethod::fromArray($entry);

            // An entry with no slug can't be addressed by callers and would
            // collide under the '' key, so drop it rather than keep a
            // gateway nobody can look up.
            if ($method->slug === '') {
                continue;
            }

            $methods[$method->slug] = $method;
        }

        return $this->memoised = $methods;
    }

    /**
     * One pay method by its DPay slug, or null if this merchant has none.
     *
     * Null is not an error: it means DPay didn't list the gateway for this
     * account, which is exactly what Sadad looks like until DPay enables it.
     */
    public function find(string $slug): ?PayMethod
    {
        return $this->list()[$slug] ?? null;
    }

    /**
     * Drop the memoised list so the next call refetches.
     */
    public function refresh(): void
    {
        $this->memoised = null;
    }
}
