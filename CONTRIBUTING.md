# Contributing

Thanks for taking an interest. This is a small, focused SDK — contributions
are welcome, particularly from anyone integrating DPay in production who has
found the docs wrong.

Security issues do **not** belong here — see [SECURITY.md](SECURITY.md).

## Getting set up

```bash
git clone https://github.com/AliAgela-dev/dpay-php.git
cd dpay-php
composer install
composer check
```

`composer check` runs PHPStan (level 8) and the full PHPUnit suite. It must
be green before anything is merged; CI runs it on PHP 8.2, 8.3 and 8.4.

**`composer.lock` is gitignored on purpose.** That's the convention for a
library: consumers resolve their own dependency versions, so pinning ours
would be meaningless and would hide resolution failures. The trade-off is
that a fresh `composer install` resolves from scratch and can surface
upstream problems — if it fails, that's a real signal, not a local glitch.

## Useful commands

```bash
composer test          # full suite
composer test:unit     # tests/Unit only
composer test:feature  # tests/Feature only (Laravel bridge, via Testbench)
composer analyse       # PHPStan level 8 on src/
composer check         # analyse + test — run before opening a PR
```

A single test or file:

```bash
vendor/bin/phpunit --filter test_amount_below_minimum_is_still_rejected
vendor/bin/phpunit tests/Unit/DPayClientTest.php
```

PHPUnit runs with `executionOrder="random"`, `failOnRisky` and
`failOnWarning`. Order-dependent or warning-emitting tests fail the build,
which is deliberate.

## Testing conventions

- **Unit tests** use `tests/Unit/Support/FakeHttpClient.php`, a PSR-18 double
  with a FIFO response queue, a request log, and a `throwOnNext` hook for
  exercising transport failures. No test should ever make a network call.
- **The Laravel bridge is covered only by feature tests.** `src/Laravel` is
  excluded from PHPStan (it needs container resolution and facade statics
  that PHPStan can't follow without larastan), so if you change the bridge, a
  feature test is the only thing that will catch a mistake.
- **A test that passes the moment you write it deserves suspicion.** For
  anything non-obvious, break the implementation on purpose and confirm the
  test fails. Several tests here carry a comment recording exactly which
  mutation kills them.

## The live sandbox probe

`tests/sandbox/` is **not** PHPUnit and **not** in CI. It's a standalone
harness that talks to DPay's real sandbox, and it needs your own credentials:

```bash
cp .env.example .env    # then fill in DPAY_API_KEY
set -a; source .env; set +a
php tests/sandbox/probe.php
```

It's paced and resumable, and it regenerates `SANDBOX-VALIDATION.md` from
actual results. Never commit a token — `.env` is gitignored, and the probe
scrubs anything matching `sb_tk_*` from its output.

## Architecture, briefly

Two layers that can be used independently:

- **Transport** — `DPayClient` over `DPay\Http\Transport`, behind PSR-18/17
  interfaces. Never depends on a concrete HTTP client.
- **Providers** — `PaymentProviderInterface` + `GatewayManager`, an
  in-memory registry with no container dependency.

The provider layer is **schema-driven**, and that's the central design idea:
each provider declares a `PaymentField[]` schema, and that one schema drives
three consumers — the wire mapping in `sendOtp()`, the frontend JSON from
`GatewayManager::describe()`, and Laravel validation rules via
`PaymentFieldRules`. Change the schema and all three follow.

Adding a DPay-backed gateway whose fields map onto an existing
`OpenSessionRequest` parameter is usually one subclass plus a config entry —
`SadadProvider` is the proof. See [docs/extending.md](docs/extending.md).

## Behaviour that is intentional

Please don't "fix" these without opening an issue first — each exists for a
reason, and several are load-bearing for existing integrations:

- **`verifySession()` returns `null` rather than throwing** for a wrong OTP,
  an expired session, or a missing one. Provider `verifyOtp()` therefore
  returns `false` for ordinary user errors without callers needing
  try/catch.
- **`UnknownProviderException` extends `InvalidArgumentException`**, not
  `DPayException`. Catch `DPayExceptionInterface` to cover both trees.
- **Unknown session statuses degrade to `SessionStatus::UNKNOWN`** instead of
  throwing, so a new gateway state can't crash callers.
- **`minAmount` defaults to a permissive `0.01`.** DPay enforces its own
  per-gateway minimum and maximum, and those are merchant-configurable, so no
  static SDK floor can be correct.

## Pull requests

- One logical change per PR, with a clear description of the behaviour before
  and after.
- Update the docs in the same PR. Provider and test changes ripple further
  than you'd expect here — check `README.md`, `CHANGELOG.md`,
  `src/Laravel/config/dpay.php`, and everything under `docs/`.
- Add an entry to `CHANGELOG.md` under `## [Unreleased]`.
- If you change anything touching the wire format, read the official spec at
  <https://dpay.ly/docs/api> first. It is the source of truth;
  `SANDBOX-VALIDATION.md` is corroborating live evidence, not authority.
