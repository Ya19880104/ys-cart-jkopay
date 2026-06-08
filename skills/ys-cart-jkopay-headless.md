# YS CART JKoPay Headless Skill

Use this when integrating JKoPay with a headless YS CART storefront.

- Use payment method `ys_ec_jkopay` during checkout.
- Never call the provider callback route from browser UI.
- Never call the admin test route from customer storefront UI.
- Keep JKoPay credentials in YS CART admin settings.
- Keep payment status/reconciler checks provider-scoped to JKoPay identifiers.
- Verify the plugin is installed and active through YS Hub Installer before troubleshooting gateway registration.
- Do not put JKoPay runtime source back into YS CART core.
