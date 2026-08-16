# Security Policy

## Reporting a vulnerability

**Please do not open a public GitHub issue for a security problem.**

Report it privately through
[GitHub Security Advisories](https://github.com/AliAgela-dev/dpay-php/security/advisories/new),
which lets us discuss and fix the issue before any detail becomes public.

Useful things to include: the affected version, what an attacker can achieve,
and a minimal reproduction. A failing test case is ideal but not required.

We aim to acknowledge a report within a few days. Since this is a small
project, please allow reasonable time for a fix before publishing.

## Supported versions

| Version | Supported |
|---|---|
| `0.2.x` | ✅ |
| `0.1.x` | ❌ — upgrade, see [UPGRADING.md](UPGRADING.md) |

## What we consider a vulnerability

This SDK's security-sensitive surface is narrow but real. Reports in these
areas are always in scope:

- **Webhook signature verification** — anything that lets a forged or
  replayed payload pass `DPay\Webhooks\WebhookVerifier`. Signatures are
  HMAC-SHA256 over `timestamp + "." + raw_body`, compared with
  `hash_equals()`, and timestamps outside a 300-second window are rejected in
  both directions.
- **Credential leakage** — an API key or webhook secret reaching a log, an
  exception message, or any serialized output. Exception messages
  deliberately never include the expected signature or the secret, because
  the request that triggers them is attacker-controlled.
- **Request forgery or injection** — anything that lets caller-supplied data
  alter the shape of a request to DPay beyond its intended field.

## What is out of scope

- **The DPay gateway itself.** Bugs in DPay's API, dashboard or sandbox
  belong to DPay, not this SDK. One known example: replaying an
  `Idempotency-Key` does not return the original session. The SDK sends the
  header correctly; the behaviour is DPay-side.
- **Amounts changing between request and settlement.** DPay settles at
  `round(amount + fee)` to the nearest whole LYD. This is documented gateway
  behaviour, not a defect — see
  [docs/troubleshooting.md](docs/troubleshooting.md).
- **The `tests/sandbox/` tooling.** `probe.php` and `webhook-receiver.php`
  are local diagnostics, never loaded by the library at runtime and never
  part of a production dependency graph. The receiver in particular binds a
  plaintext HTTP port and is not intended to be exposed as-is.

## Handling credentials

If you are reporting an issue, or filing any bug report, **never paste a real
API key, webhook secret, or webhook payload from a live merchant.** Sandbox
values or redacted samples are enough to reproduce anything we need.
