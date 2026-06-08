# YS CART - JKoPay

External JKoPay gateway provider for YS CART.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- YS CART 2.48.4+

## Provider contract

- Gateway ID: `ys_ec_jkopay`
- Admin page slug: `ys-provider-jkopay`
- Payment callback: `/wp-json/ys-ecommerce-headless/v1/payment/jkopay/callback`
- Admin test route: `/wp-json/ys-ecommerce-headless/v1/admin/jkopay/test-connection`

## Headless use

Use YS CART checkout APIs as usual. When an order selects `ys_ec_jkopay`, the gateway creates the JKoPay payment request and returns the provider redirect/payment data through the YS CART checkout response.

The callback route is for JKoPay server notifications. It verifies the provider digest/signature and should not be called directly by storefront code.
The admin test route requires authenticated admin capability and a valid nonce;
it is not a storefront route.
Status/reconciler lookups are provider-scoped and must match JKoPay identifiers
stored by this gateway.

## YS Hub updates

This provider bundles the YS Plugin Hub Client runtime under `vendor/yangsheep/ys-plugin-hub-client`.
YS CART can install the provider from YS Hub, and the provider can then receive updates through YS Hub without adding the PayNow/JKoPay runtime back into YS CART core.
Production defaults to `https://yangsheep.com.tw`; staging may override the Hub URL with `YS_CART_HUB_URL` or the `ys_cart_hub_url` option.

## Included user files

- `docs/headless.md`: headless integration notes
- `sdk/ys-cart-jkopay-headless.js`: lightweight storefront constants/helpers
- `skills/ys-cart-jkopay-headless.md`: Codex/agent implementation guidance
- `vendor/yangsheep/ys-plugin-hub-client`: YS Hub install/update runtime
