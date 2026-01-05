# Holler MU Plugins

This must-use plugin bundle provides common functionality for Holler Digital sites.

- SEO tweaks (`app/seo/class-holler-seo.php`)
- Security helpers (`app/security/class-banned-plugins.php`)
- Shortcodes (`app/shortcodes/class-holler-shortcodes.php`)
- Performance tools (`app/performance/class-hd-db-optimizer.php`)
- Admin helpers (`app/helpers/holler-helper.php`)

Admin view templates live in `admin/`, and WP‑CLI commands live in `wp-cli/`.

## DB Optimizer

A safe, scriptable set of database maintenance operations with both admin UI and WP‑CLI support.

- Admin page: Tools → DB Optimizer
- Class: `app/performance/class-hd-db-optimizer.php`
- Admin template: `admin/db-optimizer-page.php`

### WP‑CLI Commands

Command group: `holler db-optimize`

If using GridPane's wrapper, the full prefix is:

```
gp wp code.hollerdigital.dev holler db-optimize <subcommand> [options]
```

Otherwise, use standard `wp`:

```
wp holler db-optimize <subcommand> [options]
```

#### Subcommands

- `list`
  - Shows available optimization operations (IDs, labels, descriptions).
  - Examples:
    - `gp wp code.hollerdigital.dev holler db-optimize list`
    - Back-compat alias: `gp wp code.hollerdigital.dev holler db-optimize list-ops`

- `run`
  - Runs optimization operations. If `--ops` is omitted, all operations are run.
  - Options:
    - `--ops=<ids>` Comma-separated operation IDs.
    - `--dry-run` Preview only (no writes).
    - `--revision-days=<days>` Number of days for the `delete_old_revisions` operation. Default: `14`.
  - Examples:
    - Run all ops as a dry run:
      - `gp wp code.hollerdigital.dev holler db-optimize run --dry-run`
    - Run specific ops:
      - `gp wp code.hollerdigital.dev holler db-optimize run --ops=expired_transients,analyze_tables`
    - Run with custom revision window:
      - `gp wp code.hollerdigital.dev holler db-optimize run --revision-days=30`

#### Available Operation IDs

- `expired_transients`
- `orphan_postmeta`
- `orphan_term_rel`
- `delete_old_revisions`
- `analyze_tables`
- `optimize_tables`
- `convert_myisam`
- `report_autoload_bloat`

### Notes

- If you run WP‑CLI directly as root (outside GridPane’s wrapper), append `--allow-root`.
- Some sites may output PHP notices from other plugins during CLI bootstrap (e.g., Advanced Custom Fields block registrations). These do not affect DB Optimizer behavior.
- The default `revision_days` in the UI is `14`. The CLI `run` command also defaults to `14` unless overridden.

## Project Structure

```
holler-mu-plugins/
├─ app/
│  ├─ helpers/
│  │  └─ holler-helper.php
│  ├─ performance/
│  │  └─ class-hd-db-optimizer.php
│  ├─ security/
│  │  └─ class-banned-plugins.php
│  ├─ seo/
│  │  └─ class-holler-seo.php
│  └─ shortcodes/
│     └─ class-holler-shortcodes.php
├─ admin/
│  └─ db-optimizer-page.php
├─ wp-cli/
│  └─ class-hd-db-optimizer-command.php
└─ holler-mu-plugins.php
```

## Bootstrap

Classes are required and initialized from `holler-mu-plugins.php`. WP‑CLI commands are conditionally loaded only when `WP_CLI` is defined.

```php
// In holler-mu-plugins.php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once PLUGIN_DIR . '/holler-mu-plugins/wp-cli/class-hd-db-optimizer-command.php';
    if ( class_exists( '\\Holler_DB_Optimizer_Command' ) ) {
        \WP_CLI::add_command( 'holler db-optimize', '\\Holler_DB_Optimizer_Command' );
    }
}
```
