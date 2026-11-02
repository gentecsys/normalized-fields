# Decision log

Owned by A0. Newest entries go at the bottom. Each entry gives the date, the decision, the reason, and the alternatives considered. D-xx entries are owner decisions from the build plan's section 5. ADR-xxxx entries are architecture decisions.

## Settled (from the design doc and build plan)

### ADR-0001: Replace ACF outright (2026-10-29)
- **Decision:** NF is a standalone plugin. ACF is only a migration source.
- **Why:** sitting underneath ACF ties NF to ACF internals and caps the speed gain.
- **Alternatives:** a storage layer under ACF, which remains a temporary bridge mode only (WP 1.2).

### ADR-0002: Generic typed store (2026-10-29)
- **Decision:** one table per data type, not per content type, so field changes never need DDL. Pivot, row pivot, dictionary, count and archive tables are derived and can be rebuilt.
- **Alternatives:** a table per collection, rejected because every schema edit would need DDL.

### ADR-0003: Fluent query builder, not an ORM (2026-10-29)
- **Decision:** `NF::query()` returns native `WP_Post` / `WP_Term` / `WP_User`, and `WP_Query` `meta_query` is translated transparently.

### ADR-0004: React admin on core WordPress packages (2026-10-29)
- **Decision:** the admin uses the core `@wordpress/*` packages, kept external. Only `@wordpress/dataviews` is bundled, at a pinned version.

### ADR-0005: Multi-agent build against frozen contracts (2026-11-02)
- **Decision:** specialised Claude Code agents (A0–A13), one WP per branch and PR, a fresh-context reviewer on every PR, and owner approval at each gate.

### ADR-0006: "Collection" replaces "field group" in NF's own vocabulary (2026-11-02, owner)
- **Decision:** a set of fields shown together is a **collection**. This applies to NF's admin UI (Collections list, Collection builder), NF's PHP API (`nf_register_collection()`), the NF JSON top-level key (`"collections"`), docs, plans and code identifiers inside `includes/` (no new `nf_*field_group*` names).
- **Exception:** the ACF compatibility surface keeps ACF's names exactly: `acf_add_local_field_group()`, `acf_get_field_groups()`, `acf_get_field_group()`, `group_`-prefixed keys, the `acf-field-group` post type, ACF JSON files and ACF hooks. Themes and plugins call them, so renaming them would break compatibility. Where both appear, write "collection (ACF: field group)".
- **Why:** owner preference for a clearer, product-neutral term.

### ADR-0007: "Series" is the UI name for the repeater field (2026-11-02, owner)
- **Decision:** the admin UI and user-facing strings call the repeater field **Series**; each entry is a **row**. Code identifiers, storage (`nf_rows`, `nf_row_pivot`) and technical docs keep "repeater".
- **Exception:** ACF compatibility keeps `type: "repeater"` in ACF JSON, plus `have_rows()`, `the_row()` and `get_sub_field()`.
- **Why:** not a copy of ACF's vocabulary.

### ADR-0008: No flexible content field (2026-11-02, owner)
- **Decision:** NF doesn't implement flexible content. The `layout_id` columns are dropped from `nf_rows` and `nf_row_pivot`. There's no layout picker, and NF doesn't provide `get_row_layout()` or `acf_fc_layout`.
- **Why:** flexible content is an old page-building paradigm; blocks cover that use case.
- **Consequences:** the scanner and the port dry-run list ACF flexible content fields. Collections that contain one aren't ported: they stay on ACF in bridge mode or get rebuilt, for example as blocks. Templates that call `get_row_layout()` won't work once ACF is deactivated. This is listed in the design doc's risks.
- **Alternatives:** read-only import of flexible content as JSON. Not chosen for now; it can be revisited if a pilot site needs it.

## Owner decisions

| # | Decision | Needed by | Status | Value |
| --- | --- | --- | --- | --- |
| D-01 | Coding conventions | G0 | **Default, pending owner confirmation** | As in `CLAUDE.md` |
| D-02 | Plugin name | G0 | **Default, pending owner confirmation** | Keep "NF" internally until 4.10 |
| D-03 | Minimum WordPress | G0 | **Decided by owner 2026-11-02** | 7.0 (test 7.0, 7.1, nightly) |
| D-04 | Minimum PHP | G0 | **Default, pending owner confirmation** | 8.1 |
| D-05 | ACF PRO + SCF support | G0 | **Default, pending owner confirmation** | Both from day one (same public API) |
| D-06 | Pilot site | G1 | Open | Default: one large client site copy |
| D-07 | ACF shim default | G3 | Open | Default: off, enabled by the scanner's suggestion |
| D-08 | Business model | 4.10 | Open | Undecided; doesn't block the build |
| D-09 | Third-party adapters in 4.8 | Start of Phase 4 | Open | Default: the scanner's top hits on client sites |

## Gate approvals

Only the owner edits this section. To approve a gate, add a line in the form `- G0: approved YYYY-MM-DD (notes)`. The daily build agent won't start the next phase until the matching line exists. To ask for changes instead, add a line in the form `- G0: changes requested YYYY-MM-DD: <what>`.

_No gates approved yet._
