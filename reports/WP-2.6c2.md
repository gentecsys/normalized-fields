# WP 2.6c2: Port, definitions and report counts

Agent: A9 (daily build agent). Branch `wp/2.6c2-port-definitions`.

## What changed
- `NF\Migration\DefinitionReader`: reads ACF's `acf-post-type`, `acf-taxonomy` and `acf-ui-options-page` posts (core tables via `$wpdb`), decodes the serialized content with `allowed_classes => false`, sets `active` from post status.
- `NF\Migration\DefinitionPorter` (pure): turns those lists into the `post_types`, `taxonomies` and `options_pages` sections of NF JSON (drops IDs and internals, skips entries with no identifier, sorts, counts).
- `NF\Migration\OrphanCounter`: counts `_name => field_key` references (post, user, term, comment meta) whose field key is unknown; keyset-paged.
- `NF\Migration\RawSqlScanner`: finds raw SQL on `postmeta` in a string or a directory of PHP files (skips vendor, node_modules, NF).
- `tests/unit/Migration/DefinitionsTest.php`.

## Tests / tooling
No `vendor/`, phpcs or phpstan run locally; `php -l` passes on all files and the scanner regex was checked by hand. CI is authoritative.

## Deviations / follow-ups
- Definitions are produced as NF JSON sections only. Registering them as live NF post types/taxonomies/options pages and writing them to NF JSON export belongs to SchemaIO / a later WP; nothing in NF consumes them yet.
- Wiring these into the dry-run report output and `wp nf port scan` is left to 2.6d.
- Orphan counts cover only `field_*` references in meta; no `_name` row means no count.

## Contract change requested
None.
