# wp-lalia

The Lalia WordPress plugin: a single consolidated plugin that wires three modules into the LALIA WP install.

This repository is consumed as a Git submodule by [`antonhoreis/lalia-wp`](https://github.com/antonhoreis/lalia-wp) at `custom_plugins/lalia/`. It is also reachable from the parent monorepo `antonhoreis/lalia-system` through `lalia-wp/custom_plugins/lalia/`.

| Detail | Value |
|--------|-------|
| Plugin Name | Lalia |
| Plugin Slug | `lalia` |
| Entry Point | `lalia.php` |
| Author | Anton Horeis |
| Update URI | `https://europe-west3-horeis.cloudfunctions.net/wp_update/lalia` |

## Modules

The plugin has three independently toggleable modules. Each stores its on/off state as a WordPress option; defaults to `'yes'`. Toggle from **WP Admin → Lalia → Overview**.

| Option | Default | Module |
|--------|---------|--------|
| `lalia_enable_sso` | `'yes'` | JWT-based single sign-on to the LALIA learning platform |
| `lalia_enable_single_item_cart` | `'yes'` | WooCommerce cart restriction (one product, qty 1) |
| `lalia_enable_stripe_package_id` | `'yes'` | Copy each WC product's `_lalia_package_id` post meta into Stripe PaymentIntent metadata at checkout, so the LALIA ERP `stripe-sales-event-worker` can attribute the purchase to the right LALIA package |

### SSO module

`includes/wp-sso/` — bundled SSO classes (`class-sso-handler`, `class-jwt-generator`, `class-settings`, `class-logger`, `class-menu-manager`, `class-activator`). Uses the bundled Firebase PHP-JWT library in `vendor/firebase/php-jwt/`. Configurable settings (API key, external domain, return URL, error page) live under **Lalia → SSO Settings**; events visible under **Lalia → SSO Logs**.

### Single Item Cart module

`includes/wc-single-item-cart.php` — limits the WooCommerce cart to one product with quantity 1. Adding a different product replaces the existing cart contents.

### Stripe → LALIA package_id injector module

`includes/wc-stripe-package-id.php` (class `LaliaStripePackageId`) — registers a filter on `wc_stripe_payment_intent_metadata` that copies each order's first line item's `_lalia_package_id` post meta into the Stripe PaymentIntent metadata. The LALIA ERP `stripe-sales-event-worker` reads `pi.metadata.package_id` as its priority-1 package resolver.

Per-product setup: WP Admin → Products → edit each LALIA-mapped product → **LALIA package id** field (added by this module to the General product data tab) → paste the LALIA `public.packages.id` UUID. Single-package carts only — multi-item orders log a notice and take the first line item's value.

UUID-validated on save and at filter time; malformed values fail closed (log + don't forward).

## Auto-update pipeline

This plugin uses the same `wp_update` / `wp_package` cloud functions as `antonhoreis/user_auth`. The GitHub Actions workflow in `.github/workflows/main.yml`:

1. On every push to `main` (or `v*.*.*` tag, or manual `workflow_dispatch`), bumps the patch version in `lalia.php` (both the header `Version:` line and the `LALIA_VERSION` constant), commits with `[skip ci]`, and pushes.
2. Sends `{plugin_slug:"lalia", commit_hash, version}` to the `wp_update` cloud function (`X-API-KEY` header).
3. WordPress's update check fires `update_plugins_<host>` against the Update URI, picks up the new version, and downloads the ZIP from `wp_package`, which fetches this repo at the registered commit hash.

The on-WP integration (filter on `update_plugins_europe-west3-horeis.cloudfunctions.net`) lives in `lalia.php` itself.

### Required GitHub Actions secrets

The repo needs both secrets set before the registration step succeeds:

| Secret | Purpose |
|--------|---------|
| `WP_UPDATE_URL` | Full URL of the wp_update cloud function (`https://europe-west3-horeis.cloudfunctions.net/wp_update`) |
| `WP_UPDATE_API_KEY` | API key for the registration POST (same value as on `antonhoreis/user_auth`) |

### WP-side authorization (IP allowlist)

The `wp_update` cloud function authorizes the WP-side GET via a Redis-backed IP allowlist (see `_is_authorized` in `wp_update/main.py`). The plugin doesn't carry the API key in its source — the live WordPress install's outbound IP is in the allowlist, and that's how the update check completes. If you move the WP install to a new host, add its outbound IP to the allowlist (`wp:allowed_ips` Redis key) or the update check will silently return "no update".

## Local development

Edits are made inside the parent `lalia-wp` repo as a submodule:

```bash
cd lalia-wp/custom_plugins/lalia
git checkout main
# edit, commit, push as usual
cd ../../
git add custom_plugins/lalia
git commit -m "chore(lalia-plugin): bump submodule"
git push
```

The lalia-wp Docker stack mounts `custom_plugins/` into the WordPress container, so changes are picked up on the next page load.

## Tag-based releases

Pushing a `v*.*.*` tag skips the auto patch bump and registers the tag's version directly. Useful for minor or major releases.

## License

Same as the parent lalia-system repo.
