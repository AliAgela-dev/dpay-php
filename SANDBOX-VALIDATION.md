# Sandbox Validation Report

| Scenario | Status | Detail |
|---|---|---|
| `edfali` | fail | Idempotency-Key replay opened a new session (1497) instead of returning the original (1496) |
| `mobicash` | pass | session 1502 paid, amount 10.5 preserved |
| `masrefypay` | pass | session 1503 paid, amount 10.5 preserved |
| `masrefypay-crossbank` | pass | session 1504 paid, amount 10.5 preserved |
| `yousrpay` | pass | session 1505 paid, amount 10.5 preserved |
| `yousrpay-crossbank` | pass | session 1506 paid, amount 10.5 preserved |
| `saharapay` | pass | session 1507 paid, amount 10.5 preserved |
| `saharapay-crossbank` | pass | session 1508 paid, amount 10.5 preserved |
| `moamalat` | pass | payment_link present |
| `sadad` | fail | DPay\Exceptions\DPayValidationException: Unsupported payment method: sadad |
