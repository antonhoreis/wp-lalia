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
| `lalia_enable_course_schedule` | `'yes'` | `[lalia_course_schedule]` — renders upcoming courses read live from the LALIA ERP |

### SSO module

`includes/wp-sso/` — bundled SSO classes (`class-sso-handler`, `class-jwt-generator`, `class-settings`, `class-logger`, `class-menu-manager`, `class-activator`). Uses the bundled Firebase PHP-JWT library in `vendor/firebase/php-jwt/`. Configurable settings (API key, external domain, return URL, error page) live under **Lalia → SSO Settings**; events visible under **Lalia → SSO Logs**.

### Single Item Cart module

`includes/wc-single-item-cart.php` — limits the WooCommerce cart to one product with quantity 1. Adding a different product replaces the existing cart contents.

### Stripe → LALIA package_id injector module

`includes/wc-stripe-package-id.php` (class `LaliaStripePackageId`) — registers a filter on `wc_stripe_payment_intent_metadata` that copies each order's first line item's `_lalia_package_id` post meta into the Stripe PaymentIntent metadata. The LALIA ERP `stripe-sales-event-worker` reads `pi.metadata.package_id` as its priority-1 package resolver.

Per-product setup: WP Admin → Products → edit each LALIA-mapped product → **LALIA package id** field (added by this module to the General product data tab) → paste the LALIA `public.packages.id` UUID. Single-package carts only — multi-item orders log a notice and take the first line item's value.

UUID-validated on save and at filter time; malformed values fail closed (log + don't forward).

### Course schedule module

`includes/course-schedule.php` (class `Lalia_Course_Schedule`) — registers the
`[lalia_course_schedule]` shortcode. It renders the schedule card designed in the Claude Design
project *Lalia Course Schedule Mockups* (options 3a and 3b), driven by the LALIA ERP's public
endpoint:

```
GET https://api.lalia-berlin.com/functions/v1/public-course-schedule?days=60
```

Unauthenticated by design. The contract, the seat-disclosure mask and the weekday fallback are
documented in **lalia-erp** `docs/developers/public-course-schedule-api.mdx`.

| Attribute | Default | Meaning |
|-----------|---------|---------|
| `level` | *(empty)* | Level abbreviation or name (`N3`, `Novice 3`), comma separated for several. Empty renders every level. |
| `layout` | `auto` | `single` drops the Level column and lets the heading name the level (design 3a); `all` keeps it (3b). `auto` picks `single` when `level` resolves to one level. |
| `per_level` | `auto` | `all` lists every start; `first` keeps only each level's next start. `auto` means `all` for one level, `first` across levels — so 3a lists a level's upcoming starts and 3b lists one row per level, as the mockups show. |
| `days` | `60` | Window passed to the endpoint. Clamped here to 1–365; the endpoint then snaps it to one of 30/60/90/180/365, rounding **up**, so `days="45"` returns a 60-day window. |
| `limit` | `0` | Maximum rows; `0` is no limit. Worth setting on the all-levels card — a 60-day window currently holds 17 levels. |
| `title` | *derived* | `"Novice 3 — upcoming courses"` / `"Upcoming courses"`. Empty omits it. |
| `subtitle` | *derived* | `"4 times a week, 50 minutes each session"`, derived **only when every row agrees** on rhythm and lesson length — one sentence over a mixed table would be false. Empty omits it. |
| `cta_text` | `Purchase Now` | Button label. |
| `cta_url` | `https://lalia-berlin.com/pricing/` | Button target. Empty renders no button. |
| `empty_text` | *(a sentence)* | Shown when nothing matches. Empty renders nothing. |
| `variant` | `card` | `inline` drops the panel, padding, heading, sub-line and button so the table can sit inside a card that already has them. See below. |
| `class` | *(empty)* | Extra class on the card. |

Examples:

```
[lalia_course_schedule level="N3"]                  one level, every upcoming start
[lalia_course_schedule]                             all levels, next start each
[lalia_course_schedule limit="6" days="90"]         a 90-day window, six rows
[lalia_course_schedule level="N3,N4,N5" title=""]   the novice levels, no heading
```

#### Inside a level card

`variant="inline"` is for the level containers on `/novice-levels/`, `/intermediate-levels/`,
`/advanced-levels/` and `/absolute-beginners/`. Each of those is already a `#F7F7F7` panel with a
22 px radius, its own heading, a "Structure: …" line and a Purchase Now button — a second card
nested inside would double all of it. The inline variant renders the table alone, which is what
mockup 2a shows.

Add a Shortcode widget to the level's text column, between the "Structure: …" text and the button:

```
[lalia_course_schedule level="Novice 3" variant="inline"]
```

The heading on every level container is the ERP level name — "Novice 3", "Intermediate Low 1",
"Intermediate Mid 4", "Current Events in the German Speaking Sphere" — so the `level` attribute
takes the heading text as-is. Matching is case-insensitive, which is why `/absolute-beginners/`
works with its "Absolute beginners" heading against the ERP's "Absolute Beginners". Abbreviations
(`N3`, `IL1`, `Ad1`, `AB`) work too.

The column leaves roughly 700 px inside its padding (60 % of the kit's 1500 px container, less
140 + 40), and the table needs about 515 px, so it fits without a horizontal scroll. The variant
carries 6 px above and 12 px below to land on the mockup's 26/32 px once Elementor's 20 px
inter-widget margin is added.

A level with nothing scheduled in the window renders one muted line instead of an empty table —
"Novice 2" is in that state today.

**Caching.** Responses are held in a transient for 300 s, mirroring the endpoint's own
`Cache-Control: max-age=300`. Each success also writes `lalia_course_schedule_last_good`; if the
endpoint is unreachable that copy is served instead, and the retry is held off for 60 s. With no
copy at all the shortcode renders nothing visible — never an empty table, which would read as "no
courses are running" when the truth is "we could not ask". Failures land in the PHP error log.

**Seat counts.** The endpoint publishes an exact number only at three seats or fewer, `null` above
that. The renderer treats anything above the threshold as "say nothing" rather than printing it, so
a contract change upstream cannot leak enrolment figures the mask was built to hide. `0` renders
"Fully booked".

**Styling.** `assets/css/course-schedule.css`, printed inline the first time a card renders rather
than enqueued: Elementor renders widgets during `the_content`, long after `wp_enqueue_scripts`, so
an enqueue lands in the footer and the table flashes unstyled.

Elementor section templates that drop the shortcode onto a page, plus a preview harness that
renders the real module against a captured payload, live in the parent repo at
`lalia-wp/elementor-templates/`.

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

## Module: Checkout Prefill (payment links)

Redeems signed payment links of the form
`/checkout/?add-to-cart=<product_id>&prefill=<JWT>`:
verifies the HS256 JWT, prefills all billing fields from its payload,
auto-applies an optional coupon, and redirects to a clean `/checkout/`
URL so the token never reaches browser history or analytics.

- Toggle: WP Admin → Lalia → "Checkout Prefill" (`lalia_enable_checkout_prefill`)
- Shared secret: WP Admin → Lalia → "Checkout Prefill Configuration" (`lalia_prefill_secret`).
  Empty secret = module inert.
- Token contract & external generator examples (n8n/Python): see
  `docs/superpowers/specs/2026-06-04-checkout-prefill-links-design.md` in the
  `lalia-system` repo ("Generating Tokens from External Apps").
- Expired/invalid tokens degrade gracefully: product checkout still works, form just isn't prefilled.
- Logged-in users: token data overrides saved billing data (user_auth prefill).
- Note: works with `woocommerce_cart_redirect_after_add=yes` — the module carries the
  token through WooCommerce's add-to-cart redirect (`woocommerce_add_to_cart_redirect` filter).
- Dev utilities: `bin/make-prefill-token.php` (mint links), `bin/test-prefill.php`
  (13-check assertion suite) — both via `wp eval-file`.
