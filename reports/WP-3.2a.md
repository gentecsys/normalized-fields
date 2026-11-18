# WP 3.2a: Builder, fluent spec

State: in-review. Branch `wp/3.2a-builder`.

## What changed
- Split WP 3.2 into 3.2a (this), 3.2b (resolver, `nf_query()`, `toSql`/`explain`/`ids`/`count`) and 3.2c (posts, wp_query, values, relations, search, tax, cursors); 3.5 and 3.9 now depend on 3.2c.
- `NF\Query\Builder`: target (`event`, `term:genre`, `user`), `status`, `where` (2 args = `=`, `null` value = IS NULL, 3 args = operator), `whereBetween`, `whereAny/All/None`, `whereRow( $series, callable )`, `orderBy`, `limit`, `offset`, `paginate( $per, $page, $count )`, `countMode()`, `toSpec()`. Mutable fluent object, as the design examples use it. Pure, no database.
- `NF\Query\RowBuilder`: `where`, `whereBetween`, same validation.
- `whereBetween( $f, [a, b] )` equals `where( $f, 'between', [a, b] )` (asserted by `assertEquals` on the specs, main and row builder); not exactly two values throws.
- Validation throws `InvalidArgumentException`: empty field or target, unknown operator or status, non-scalar value for scalar operators, empty `in`, empty membership values, bad direction, negative limit/offset, page size below 1, bad count mode.

## Tests
`tests/unit/Query/BuilderTest.php` (10 tests incl. data provider) plus existing Planner tests: 21 tests, 70 assertions, OK (phpunit 10.5 phar, minimal autoload bootstrap). Not run: WP-bootstrapped suite, phpcs, phpstan; CI is authoritative.

## Deviations / follow-ups
- No contract change. `whereRelated`, `search`, `tax`, `with`, `after` and results are 3.2b/3.2c.
- `paginate( count: 'capped' )` only stores the mode; the counting tier is WP 3.4.
