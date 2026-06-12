# Changelog

## [1.1.8] - 2026-06-12

### Security
- Webhook and reconcile now pass the gateway-reported paid amount into the
  payment detail DTO so YS CART core verifies `paid_amount` against the order
  total before marking the order paid. Previously the amount guard was a no-op
  for JKOPay because no `paid_amount` was supplied, allowing a tampered/low
  amount to settle the order at full price.
