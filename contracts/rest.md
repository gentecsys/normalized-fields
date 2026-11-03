# nf/v1 REST contract

Owner: A8, drafting; A0 freezes it.
- **Public part:** routes, parameters, response shapes and error codes. Frozen in contracts v0 (WP 0.5, gate G0) and versioned: a breaking change needs `nf/v2`.
- **Admin-private routes:** marked `private`; they may change until the Phase 4 freeze.

Design: `docs/design.md`, section "Public REST API and blocks" (ADR-0012, D-17).

## Public routes (to be specified in WP 0.5)

| Route | Method | Permission |
| --- | --- | --- |
| `/nf/v1/collections`, `/nf/v1/collections/{key}` | GET | Anyone; `show_in_rest` fields only |
| `/nf/v1/{type}/{id}/fields` | GET | The object's read permission, as in core |
| `/nf/v1/{type}/{id}/fields` | PATCH | The object's edit capability, plus a nonce or an application password |
| `/nf/v1/query/{post_type}` | GET | Anyone, for public post types and `show_in_rest` / `rest_filterable` fields, within the limits |
| `/nf/v1/facets/{post_type}` | GET | As `/query` |

Core endpoint extensions: an `nf` field and `nf` query parameters on `/wp/v2/{rest_base}`, with `allow_batch` on every route.

## To specify
- Query parameter grammar: `where`, `any`, `related`, `row`, `search`, `tax`, `orderby`, `order`, `per_page`, `page`, `after`, `count`, `fields`, `embed`.
- Response shapes, cursor format, and the headers `X-WP-Total`, `X-WP-TotalPages`, `X-NF-Total-Capped` and `Link`.
- Anonymous limits, with their filter names and defaults.
- Error codes, including `nf_rest_field_not_exposed`, `nf_rest_field_not_filterable`, `nf_rest_limit_exceeded` and `nf_rest_use_cursor`.
- Cache headers: `Cache-Control` and `Surrogate-Key` tags.
- Block Bindings argument shapes for `nf/field` and `acf/field`.
