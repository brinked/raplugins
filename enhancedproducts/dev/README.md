# Dev tools — DO NOT SHIP

Everything in this folder is for development/debugging only and must be
excluded from the plugin zip that gets deployed to a live site
(see `.distignore` in the plugin root).

- `clear-product-cache.php` / `fix-product-4694.php` — one-off utilities meant
  to be uploaded to the WordPress **root** (they `require wp-load.php`), run
  once, then deleted.
- `REPLACE_FUNCTION.php`, `payment-methods-image-version.php`,
  `functions-snippet.php` — scratch snippets from earlier iterations.
- `mobile-debug.html` — mobile layout debugging page.
- `docs/` — internal development notes and feature write-ups.
