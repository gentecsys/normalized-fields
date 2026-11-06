# Backend adapter and dialect seam (ADR-0009)

Owner: A0 (drafted in WP 0.5; frozen at G0). Implemented by A2 (adapter) and A4 (dialects).
Design: `docs/design.md`, "Remote PostgreSQL backend".

## Rules
1. NF tables are read and written only through `NF\Backend\BackendInterface`, reached by `nf_db()`. `$wpdb` is for core tables only.
2. No NF query joins a core table. A query resolves IDs on the NF side; WordPress then loads objects by ID.
3. SQL text comes only from `NF\Backend\DialectInterface`. Table and column names come from the schema contract, never from input. Values are always bound parameters.
4. The dialect writes explicitly: `NULLS FIRST`/`NULLS LAST` on nullable sorts, casts from the declared field type, `ON DUPLICATE KEY UPDATE` vs `ON CONFLICT`, and index hints only in the MySQL dialect.
5. The adapter re-resolves the table prefix (MySQL) or `site_id` (PostgreSQL) on `switch_blog`.

## Interfaces (names and signatures frozen; bodies owned by A2/A4)

```php
namespace NF\Backend;

interface BackendInterface {
	public function name(): string;                                   // 'mysql' | 'pgsql'
	public function dialect(): DialectInterface;
	public function table( string $logical ): string;                 // 'nf_int' -> 'wp_2_nf_int'
	/** @param array<int|string,scalar|null> $params @return array<int,array<string,scalar|null>> */
	public function select( string $sql, array $params = array() ): array;
	/** @param array<int|string,scalar|null> $params */
	public function execute( string $sql, array $params = array() ): int; // affected rows
	public function insertId(): int;                                  // MySQL: last id; pgsql: RETURNING
	public function transaction( callable $fn ): mixed;               // commit on return, rollback on throw
	public function ping(): bool;
}

interface DialectInterface {
	public function quoteIdentifier( string $name ): string;
	public function upsert( string $table, array $keyColumns, array $valueColumns ): string;
	public function limit( int $limit, int $offset ): string;
	public function nullsOrder( string $column, string $dir, string $nulls ): string; // ASC/DESC, FIRST/LAST
	public function cast( string $expr, string $dataType ): string;   // int|dec|date|str
	public function indexHint( string $table, string $index ): string; // '' outside MySQL
	public function bit( string $column, int $bit ): string;          // bitmask test
}
```

## Result equality
For the same data, both backends return the same values, IDs, order, `found_posts` and `max_num_pages`. Collation: text compares case- and accent-insensitively (match the site's MySQL collation). Datetimes round-trip as the same `Y-m-d H:i:s` strings. Bit 63 of an `m` slot is mapped by the adapter.

## Writes across two databases (PostgreSQL, Phase 5)
Core write commits first; NF write is attempted in the same request; on failure the operation is queued in the MySQL `nf_outbox` and replayed by a background job. Reads never see a half-applied object: the pivot row is written last. Details are fixed in WP 5.6.

## Multisite
MySQL: per-site prefix, no `site_id`. User values: network tables on the base prefix (`nf_user_*`). PostgreSQL: `site_id` leads every key; user values use `site_id = 0`. Cache groups are per site, except user groups (global).
