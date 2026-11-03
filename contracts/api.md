# NF API contract (v0)

Status: not drafted yet. WP 0.4 (ACF API inventory, A5) writes the inventory, and WP 0.5 (A0) freezes it.

## ACF surface inventory (WP 0.4)

## Public NF API

### Block Bindings (ADR-0012, WP 4.11)

```php
/**
 * Make a block type's attributes bindable to NF fields.
 * Wraps core's block_bindings_supported_attributes_{$block_type} filter (WP 6.9+).
 *
 * @param string   $block_type Block name, e.g. 'my/event-card'.
 * @param string[] $attributes Attribute names declared in the block's block.json.
 * @return bool False if the block type is unknown or an attribute does not exist
 *              (with _doing_it_wrong() under WP_DEBUG).
 */
function nf_register_bindable_attributes( string $block_type, array $attributes ): bool;
```

- The block.json equivalent is `"nf": { "bindable": [ ... ] }`, read when the block is registered.
- It lives in `src/functions/bindings.php`, guarded by `function_exists()`, and delegates to `NF\Bindings\Registry`.
- **Filter `nf/bindings/attributes`:** `( string[] $attributes, string $block_type, array $field ): string[]`. It narrows the attributes the bindings UI offers for one field. It runs only for blocks that NF binds.

## Internal boundaries

Store, compiler, loader and registry, plus the backend adapter `nf_db()` and the SQL dialect seam (MySQL now, PostgreSQL in Phase 5; ADR-0009). WP 0.5 defines them so PostgreSQL is an added dialect, not a rewrite.

## Hooks
