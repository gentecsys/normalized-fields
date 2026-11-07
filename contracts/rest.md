# nf/v1 REST contract

Owner: A8, drafting; A0 freezes it.
- **Public part:** routes, parameters, response shapes and error codes. Frozen in contracts v0 (WP 0.5, gate G0) and versioned: a breaking change needs `nf/v2`.
- **Admin-private routes:** marked `private`; they may change until the Phase 4 freeze.

Design: `docs/design.md`, section "Public REST API and blocks" (ADR-0012, D-17).

## Conventions
- Namespace `nf/v1`. All routes set `allow_batch` (`/batch/v1`). JSON in, JSON out, UTF-8.
- `{type}` is `post`, `term`, `user`, `comment` or `options`. `{id}` is the object ID (`options`: the options page slug, or `option`).
- All reads go through `nf_query()` and the loader, never their own SQL (ADR-0012). All values are returned as `->values()` would give them: typed JSON (numbers as numbers, dates as `Y-m-d H:i:s` strings, relationships as IDs).
- Field exposure: a field appears in any response only with `show_in_rest`. It may appear in `where`, `any`, `related`, `row`, `orderby` or facets only with `rest_filterable`. Both default to false.
- Exposure keys: `show_in_rest` is on the field definition; `rest_filterable` is `nf.rest_filterable` (JSON). For anonymous requests a field must have BOTH to be used in a filter, sort or facet (no value oracle). Authenticated users may filter on fields they can edit.
- A hidden field used in a filter, sort or facet returns `nf_rest_field_not_exposed` (never `..._not_filterable`), so the code does not reveal that the field exists.
- Anonymous `count=exact` is forced to `capped`. An anonymous GET or HEAD never writes to any NF table, directly or by queuing a job, so `nf_count_cache` is read-only for these requests. The offset cap applies to anonymous requests only. Facet counts and cursors honour the `publish`-only rule.
- Visibility: anonymous requests see `publish` only (status is checked before the query). Password-protected posts return no field values without the password.

## Public routes

| Route | Method | Permission |
| --- | --- | --- |
| `/nf/v1/collections`, `/nf/v1/collections/{key}` | GET | Anyone; `show_in_rest` fields only |
| `/nf/v1/{type}/{id}/fields` | GET | The object's read permission, as in core |
| `/nf/v1/{type}/{id}/fields` | PATCH | The object's edit capability (`edit_post`, `edit_term`, `edit_user`, `edit_comment`, or the options page capability), plus a REST nonce or application password |
| `/nf/v1/query/{post_type}` | GET | Anyone, for public post types and `show_in_rest` / `rest_filterable` fields, within the limits and the query profile |
| `/nf/v1/facets/{post_type}` | GET | As `/query` |

Core endpoint extensions: an `nf` field and `nf` query parameters on `/wp/v2/{rest_base}`; the ACF-shaped `acf` field stays alongside.

### GET `/collections`, `/collections/{key}`
Response: `{ "collections": [ Collection ] }` or a single `Collection`:
`{ key, title, location, fields: [ { key, name, label, type, return_format?, choices?, required, sub_fields?, filterable } ] }`. Only `show_in_rest` fields are listed; `filterable` mirrors `rest_filterable`. Unknown key: 404 `nf_rest_collection_not_found`.

### GET `/{type}/{id}/fields`
Query: `fields=a,b,sub.name` (default: all exposed fields). Response: `{ "id": 12, "type": "post", "fields": { "name": value, ... } }`. Series are arrays of row objects; the Series order is the stored (or `on_read`) order. Unknown or hidden fields in `fields=`: 400 `nf_rest_field_not_exposed`. Object missing: 404 `nf_rest_object_not_found`. No permission: 401/403 `rest_forbidden`.

### PATCH `/{type}/{id}/fields`
Body: `{ "fields": { "name": value, ... } }`. Only exposed fields may be written. Values go through `acf/validate_value`, then the normal write path (hooks fire). All-or-nothing: on any validation failure nothing is saved. Success: 200 with the same body as GET for the changed fields. Validation: 422 `nf_rest_validation_failed` with `data.errors = { name: message }`. Hidden field: 400 `nf_rest_field_not_exposed`. Stale write: not supported in v1 (last write wins).

### GET `/query/{post_type}`
Parameters (arrays use PHP bracket syntax; scalars in a list are comma separated):

| Param | Grammar | Meaning |
| --- | --- | --- |
| `where[field][op]=v` | op: `eq` (default), `ne`, `lt`, `lte`, `gt`, `gte`, `between` (`a,b`), `in` (`a,b`), `nin`, `null` (`1`/`0`), `like` (prefix match only: `abc%`) | AND of conditions on a `rest_filterable` field |
| `any[field]=a,b` | multi-valued fields: has any of | |
| `all[field]=a,b` | has all of | |
| `related[field]=id` | relationship points at ID | |
| `row[series][sub][op]=v` | condition on one Series row (all row conditions for one Series match the same row) | one level deep |
| `search=text` | core search on title/content/excerpt | |
| `tax[taxonomy]=slug,slug` | term filter, `rest_filterable` taxonomies only | |
| `status` | authenticated only; anonymous is forced to `publish` | |
| `orderby=a,b` / `order=asc\|desc` | `title`, `date`, `modified`, `menu_order`, `id` or a `rest_filterable` field; `order` may be a list matching `orderby`; ties break on `id` | |
| `per_page` | 1..100 (default 10) | |
| `page` | 1-based; deep pages fall back to a cursor (see limits) | |
| `after` | opaque cursor from `Link rel="next"`; excludes `page` | |
| `count` | `exact` \| `capped` \| `none` (anonymous default `capped`, cap 1,001) | |
| `fields=id,title,start_date,venue.title` | projection; default `id,title,link` | |
| `embed=venue` | include the related object under `_embedded.venue`, one level | |

Response 200: `{ "items": [ { "id": 1, "title": "...", "link": "...", <projection> } ], "total": 1234|null, "total_capped": false, "next": "<cursor>|null" }`. Headers: `X-WP-Total` (omitted when `count=none`), `X-WP-TotalPages`, `X-NF-Total-Capped: 1` when the total is a cap, and `Link: <url>; rel="next"` with the cursor.

**Cursor**: base64url of `{ v:1, sort:[last sort values], id:last_id, h: hash(normalised query) }`, HMAC-signed with the site salt. Valid only with the query it was issued for; otherwise 400 `nf_rest_cursor_invalid`. Cursors page by keyset on the sort keys plus ID.

### GET `/facets/{post_type}`
Same filters as `/query`. Parameter `facets=field,field` (each must be `rest_filterable` and have a dictionary or bitmask slot). Response: `{ "facets": { "category": [ { "value": "music", "label": "Music", "count": 42 } ] }, "total": ..., "total_capped": ... }`. Counts come from the counting tier; with extra filters they may be capped or cached.

## Anonymous access and load control (D-18)
Applies to anonymous `/query`, `/facets` and `nf` query parameters on core routes. Design: `docs/design.md`, "Anti-bot design".
- **Mode:** `nf_rest_anonymous_mode` = `profiles` (default) | `open` | `off`. `off` refuses with 400 `nf_rest_query_not_allowed`.
- **Profiles:** collection setting `rest_profiles` declares fields, operators, range buckets, sorts, `per_page` and projection. Ranges snap to buckets. `search` is off unless declared. List values capped by `nf/rest/max_values`. Admin warns above `nf/rest/max_keyspace`.
- **Guard:** runs at the top of `normalized-fields.php`, before Composer, for GET and HEAD. Refuses unknown parameters, `_method`, `_envelope`, `_jsonp`, `rest_route=` and over-long URLs. Non-canonical order: 308 to the canonical URL. Strips `Vary: Origin`. Settings are constants or an autoloaded option. The same check repeats at `rest_pre_dispatch`.
- **Cold slots:** 2 count and 4 list slots via `GET_LOCK` (`nf/rest/cold_slots`), non-blocking, lock names include a per-site hash. Rollup-answered queries skip them. Timeout 250 ms (`nf/rest/statement_timeout_ms`).
- **Degraded:** no count slot: 200, `total: null`, `X-NF-Degraded: count`, `max-age=15`. No list slot: 503 `nf_rest_busy`, `Retry-After`, `no-store`.
- **Front-end `nf_query()` in anonymous web requests:** count slots and timeout apply. Lists take no slot and never 503; a timed-out list returns no rows and fires `nf/query/timeout`. CLI, cron and logged-in requests are exempt.
- **Per-prefix limits:** only with an object cache or APCu. Charged by cold cost, never by cache hits (`nf/rest/cost_budget`). IPv6 /64 plus looser /48 (`nf/rest/ipv6_prefix`); IPv4 /32 plus looser /24. Over budget: 429 `nf_rest_rate_limited`, `no-store`. Never a table or a file.
- **Client IP:** `REMOTE_ADDR`, or `NF_TRUSTED_IP_HEADER` from a proxy in `NF_TRUSTED_PROXIES`. Never `X-Forwarded-For` by default.

## Anonymous limits (all filterable)

| Limit | Default | Filter |
| --- | --- | --- |
| Max `per_page` | 100 | `nf/rest/max_per_page` |
| Max conditions (`where`+`any`+`all`+`related`+`row`+`tax`) | 8 | `nf/rest/max_conditions` |
| `row` depth / `embed` depth | 1 / 1 | `nf/rest/max_depth` |
| Default `count` | `capped` | `nf/rest/default_count` |
| Max offset-style page (`(page-1)*per_page`) | 1,000 | `nf/rest/max_offset` |
| Public cache lifetime | 300 s | `nf/rest/cache_ttl` |

Authenticated users get `per_page` up to 100 and no condition cap, but the same exposure and visibility rules.

## Error codes
Errors use WP_Error shape `{ code, message, data: { status, ... } }`.

| Code | HTTP | When |
| --- | --- | --- |
| `nf_rest_field_not_exposed` | 400 | Field is unknown or lacks `show_in_rest` (the message never says which) |
| `nf_rest_field_not_filterable` | 400 | Field used in a filter/sort/facet lacks `rest_filterable` |
| `nf_rest_limit_exceeded` | 400 | `per_page`, condition count or depth over the limit (`data.limit`, `data.max`) |
| `nf_rest_query_not_allowed` | 400 | Anonymous query outside the profile, or mode `off`; cacheable 60 s |
| `nf_rest_busy` | 503 | No list slot free; `Retry-After`, `no-store` |
| `nf_rest_rate_limited` | 429 | Prefix over its cold-cost budget; `no-store` |
| `nf_rest_use_cursor` | 400 | Page beyond the offset cap; `data.hint` says to use `after` |
| `nf_rest_cursor_invalid` | 400 | Bad, tampered or mismatched cursor |
| `nf_rest_invalid_param` | 400 | Bad operator or value for the field type |
| `nf_rest_validation_failed` | 422 | PATCH validation failed |
| `nf_rest_collection_not_found` | 404 | |
| `nf_rest_object_not_found` | 404 | |
| `nf_rest_post_type_not_public` | 403 | Query on a non-public post type by an anonymous user |
| `rest_forbidden` | 401/403 | Core permission failure |

## Caching
- Anonymous GET: `Cache-Control: public, s-maxage=300, stale-while-revalidate=600, stale-if-error=3600`; `Surrogate-Key: nf-pt-<post_type> nf-obj-<id> ...` (IDs capped at 50, then the post-type tag alone). A save purges object keys only (via `pantheon_wp_clear_edge_keys()` when present); lists age out by TTL.
- Authenticated or permission-dependent responses: `Cache-Control: private, no-store`. Never public under a URL that varies by permission.
- Canonical URLs: parameters sorted, defaults removed; other forms get a 308 redirect. Responses carry `Vary: Accept-Encoding`.

## Core `/wp/v2/{rest_base}` extension
Field `nf`: `{ name: value }` for exposed fields (read and write via `editEntityRecord`). The `nf` and `acf` fields save nothing for an anonymous request on any route, including `/wp/v2/comments` with `rest_allow_anonymous_comments` on. Query param `nf` accepts the same grammar as `/query`, as a nested array (`nf[where][price][lt]=50`), applied through `rest_{post_type}_query`; the same limits apply.

## Block Bindings argument shapes
Both sources are registered; `acf/field` uses ACF's shape.

```json
{ "source": "nf/field", "args": { "key": "price", "object": "current", "format": "raw|formatted", "row": 0, "sub": "title" } }
{ "source": "acf/field", "args": { "key": "price", "post": 12 } }
```
- `key`: field name or key (required). `object`: `current` (default, the block's context post), `post:<id>`, `term:<id>`, `user:<id>`, `option`. `row` (0-based) and `sub` read a sub-field of one Series row. `format`: `formatted` (default, as `get_field()`) or `raw`.
- `acf/field` `post` is ACF's `$post_id` convention (ID, `term_5`, `user_2`, `option`).
- Server callback honours `show_in_rest`; a non-exposed field resolves to `null`. It also applies the same visibility as GET `/fields`, whoever views the page: `publish` only unless the viewer has `read_post` (or the equivalent read capability for terms, users and comments); password-protected posts yield `null` without the password. A bound block can never leak a value that REST would withhold. Options are read-only through bindings; `setValues` writes other objects through PATCH `/fields`.
- Attribute registration: `nf_register_bindable_attributes( string $block, string[] $attributes )` or block.json `nf.bindable`; filter `nf/bindings/attributes`.

## Admin-private routes (`private`, may change until the Phase 4 freeze)
Listed when the admin is specified (WP 4.1+): collection CRUD, field-map and slot assignment, rebuild/health/jobs, port wizard.
