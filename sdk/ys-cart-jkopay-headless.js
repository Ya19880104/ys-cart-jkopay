/**
 * Lightweight constants for headless YS CART + JKoPay storefronts.
 */
export const YS_CART_JKOPAY_GATEWAY_ID = 'ys_ec_jkopay';

export const YS_CART_JKOPAY_ROUTES = Object.freeze({
  // Provider/server route. Do not call from customer storefront UI.
  callback: '/wp-json/ys-ecommerce-headless/v1/payment/jkopay/callback',
  // Authenticated admin route. Do not bundle into customer storefront UI.
  adminTestConnection: '/wp-json/ys-ecommerce-headless/v1/admin/jkopay/test-connection',
});

export function withJkopayPayment(checkoutPayload = {}) {
  return {
    ...checkoutPayload,
    payment_method: YS_CART_JKOPAY_GATEWAY_ID,
  };
}
