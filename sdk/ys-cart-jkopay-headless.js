/**
 * Lightweight constants for headless YS CART + JKoPay storefronts.
 */
export const YS_CART_JKOPAY_GATEWAY_ID = 'ys_ec_jkopay';

export const YS_CART_JKOPAY_ROUTES = Object.freeze({
  callback: '/wp-json/ys-ecommerce-headless/v1/payment/jkopay/callback',
  adminTestConnection: '/wp-json/ys-ecommerce-headless/v1/admin/jkopay/test-connection',
});

export function withJkopayPayment(checkoutPayload = {}) {
  return {
    ...checkoutPayload,
    payment_method: YS_CART_JKOPAY_GATEWAY_ID,
  };
}
