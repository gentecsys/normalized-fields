# WP 3.2c3: `search`, `tax`, `after()` / `chunk()`

State: in-review. Branch `wp/3.2c3-search-after`.

## What changed
- `Builder::search( $term )`: core post search resolved on the core side (`WP_Query` ids), then an `ID IN` condition. No join.
- `Builder::tax( $taxonomy, $terms )`: core taxonomy filter (slugs or ids, any-of within a call, calls ANDed) via `get_objects_in_term`, then `ID IN`.
- `Builder::after( $cursor )` and `Builder::cursor( $id )`: opaque base64url JSON cursor of the sort columns plus the object-id tie-break. `Compiler` adds the lexicographic keyset predicate (`QuerySpec::$after`), works for ASC and DESC, and refuses an offset together with a cursor. `Compiler::sortPlan()` is shared by ORDER BY and the cursor; `QueryContext::cursor()` reads the row's sort values from `nf_pivot`.
- `Builder::chunk( $size, $callback )`: keyset loop handing native objects to the callback, flushing the runtime cache after each chunk, stopping on `false`.
- Tests in `tests/unit/Query/QueryContextTest.php`: cursor pages (ties, DESC), bad cursor, chunk, search and tax combined with a field filter.

## Tests run
Only `php -l`. No vendor tooling in this sandbox; phpunit, phpcs and phpstan are left to CI.

## Deviations and follow-ups
- Mirrored taxonomy slots (m-slot on `set_object_terms`) do not exist in the pivot yet, so `tax()` always resolves on the core side. When they land, `tax()` should prefer the bitmask slot.
- `search()` / `tax()` ID lists above the compiler's 1000-entry list limit throw `UnsupportedQueryException`; large sets need a better path (follow-up, with 3.3).
- NULL sort values are supported in cursors (NULLs first ascending, last descending, as the ORDER BY); test added.
- `count()` with `after()` set counts only rows after the cursor; cursors are not bound to a sort order (length check only); `tax()` does not include child terms. Follow-ups.
- No contract change requested.
- `search()`/`tax()` are re-resolved on each `ids()`/`count()` call, so `chunk()` repeats them per chunk.
- Cursor sorts only on slotted or core columns, as `orderBy` does.
