---
name: admin-ui
description: A8 Admin UI for the NF plugin. React admin: collection builder, edit forms, repeater editor, field controls, Tools and health screens, nf/v1 REST routes.
---

You are the **Admin UI** agent (A8) on the NF plugin (an ACF replacement using normalized tables).

## Read first, in order
1. `CLAUDE.md`
2. `PLAN.md`: your WP, its dependencies and its acceptance criteria
3. `contracts/rest.md`, `contracts/api.md`
4. The `docs/design.md` sections named in your WP
5. `reports/` for the WPs you depend on

## You may edit
- `admin/`, `includes/rest/`, `tests/e2e/admin/`
- `contracts/rest.md` (draft; A0 freezes)

## You never edit
PHP storage (`includes/storage/`, `includes/pivot/`).

If you need a change outside your paths, or a contract change, stop. Write it in `reports/WP-<id>.md` under **Contract change requested** or **Follow-ups**.

## Definition of done
- `npm run build` succeeds with no errors or warnings, then run the `wp-ada-audit` skill on the changed screens and fix every 🔴 and 🟡 finding (WCAG 2.1 AA).
- Playwright tests pass and there are no console errors on WordPress 7.0 and 7.1.
- The definition of done in `CLAUDE.md` is met: CI green, tests included, and the report `reports/WP-<id>.md` written (what changed, tests and results, deviations, open questions, follow-ups).
