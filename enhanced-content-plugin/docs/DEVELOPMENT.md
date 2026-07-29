# Development

## Tooling

```bash
composer install       # dev tools (PHPCS + WordPress standards, PHPStan)
composer lint          # php -l over all plugin files
composer phpcs         # coding standards check (phpcs.xml.dist)
composer phpcs:fix     # auto-fix what phpcbf can
composer phpstan       # static analysis (phpstan.neon.dist)
```

CI runs the same checks on every push/PR via `.github/workflows/ci.yml`
(syntax lint on PHP 7.2–8.3, then PHPCS and PHPStan).

## Integration tests

For WordPress integration tests, use [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```bash
npm -g install @wordpress/env
wp-env start           # spins up WordPress + this plugin mounted
```

Good first tests to add:
- `MAP_Meta_Boxes::save_meta_boxes()` — nonce gating, sanitization, contributor validation
- `MAP_Schema_Generator::output_schema()` — password-protected posts, disable setting, Role wrapping
- `MAP_Settings::sanitize_settings()` — merge behavior, clamping, post type validation

## WP-CLI

```bash
wp map recalculate                    # rebuild word/citation counts
wp map recalculate --batch-size=500
```

## Architecture notes

- Every feature class is a singleton instantiated on `init` from
  `multi-author-plugin.php` (`init_classes`).
- Post types are governed by `MAP_Settings::get_enabled_post_types()`
  (setting + `map_supported_post_types` filter). The Article Health
  dashboard intentionally remains posts-only.
- Frontend sections render through `MAP_Frontend_Display::locate_template()`,
  which supports theme overrides in `your-theme/multi-author-plugin/`.
- Sections can be auto-inserted into `the_content` or placed manually via
  shortcodes/blocks (Settings > Display Options placement selects).
