# JKoPay Headless Integration

## Checkout payment method

Set the YS CART checkout payment method to:

```text
ys_ec_jkopay
```

The gateway is registered by this plugin through YS CART provider hooks. Do not add JKoPay classes back into YS CART core.

## Callback

JKoPay notifications should be sent to:

```text
/wp-json/ys-ecommerce-headless/v1/payment/jkopay/callback
```

The callback route validates provider payloads and updates the YS CART order through the payment lifecycle. It is not a storefront route.

## Admin test route

Authenticated YS CART admins can test credentials through:

```text
/wp-json/ys-ecommerce-headless/v1/admin/jkopay/test-connection
```

The route requires an authenticated admin request and a valid nonce.

## Security notes

- Keep all credentials in the YS CART admin settings page.
- Do not expose platform credentials to the browser.
- Treat provider callback requests as untrusted until signature validation succeeds.
