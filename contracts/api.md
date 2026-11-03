# NF API contract (v0)

Status: not drafted yet. WP 0.4 (ACF API inventory, A5) writes the inventory, and WP 0.5 (A0) freezes it.

## ACF surface inventory (WP 0.4)

## Public NF API

## Internal boundaries

Store, compiler, loader and registry, plus the backend adapter `nf_db()` and the SQL dialect seam (MySQL now, PostgreSQL in Phase 5; ADR-0009). WP 0.5 defines them so PostgreSQL is an added dialect, not a rewrite.

## Hooks
