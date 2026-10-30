# MOED / MessageMonkey refactor validation

Branch: `codex/continued-patch-work-13`.
Baseline: `0c7e16e5f90bd3c873a0afc9b16b5847e3ad94f6` (2.9.6).
This is a local refactor; no release or production deployment was performed.

## Scope

- Extracted 220 existing shared/MOED PHP functions into 36 modules, retaining
  compatibility entry points and their function APIs. Token-based comparison
  confirmed identical signatures and bodies after accounting for relocated
  `__DIR__` references.
- Extracted MessageMonkey contacts/definition persistence into store traits.
  All 30 methods originally declared directly in `AudienceStore` retain their
  signatures and bodies; the composed store still exposes 211 methods.
- Centralized MessageMonkey workspace validation/selection and retained the
  established dispatch precedence and access requirements. Separated the
  contacts controller from the authenticated entry point.
- Composed both browser entry points from 15 feature modules and consolidated
  the asset build catalog. Existing public routes and asset URLs remain stable.
- Updated source-contract tests, static-analysis coverage, and AI Coach's
  reviewed source hashes to follow the actual implementation modules.

See [internal architecture](internal-architecture.md) for the boundaries and
commands used for future development.

## Passed

| Check | Result |
| --- | --- |
| `composer check` | PHP lint, all four PHPStan configurations, and local tests passed |
| `npm test` | Production assets rebuilt; recursive syntax checks passed; 414 tests passed |
| `python3 scripts/ai_help/check_source_hashes.py` | All reviewed hashes match |
| `python3 scripts/integration_environment.py` | Exit 0; 98 discovered suites, zero failures; disposable project removed |
| MessageMonkey module-boundary browser test | Rename, segment edit/copy/preview, selection, unlock, cancellation, final confirmation, and rotated CSRF passed against the final bundle |
| MessageMonkey navigation | WebKit/Chromium, 12 mobile layouts plus keyboard and desktop resizing passed |
| MessageMonkey mobile workspace | 340 layouts/interactions and desktop comparisons passed |
| MOED mobile shell | 16 mobile layouts plus wide touch/desktop resizing passed |
| Native dropdowns | 200 WebKit/Chromium layouts passed after fixing the fixture's missing shared-helper bootstrap |
| Other browser regressions | Shell controls, connection deletion/unlock, campaign checklist/send previews, brand controls, image editing, and pagination passed |
| `git diff --check` | Passed |

The integration run includes account security, login/MFA, task/inquiry workflows,
inbound mail, MessageMonkey data operations/campaigns/automations, large file
uploads/downloads, worker privileges, and backup/restore. The large backup test
restored a 2,115,144,886-byte archive successfully.

The default disposable fixture skips document-scanner integration and the
Account-enabled SuperAdmin portions of AI Coach review/network reset tests.
Those specific configurations were not qualified by this run. Existing test
fixture session warnings and PHP 8.5 `curl_close()` deprecation notices remain.

## Browser failures resolved in the follow-up

The original five failures reproduced with the baseline production JavaScript.
All five suites now pass after correcting their responsive workflows and fixing
one campaign layout defect exposed by the updated checks.

| Test | Resolution and passing coverage |
| --- | --- |
| `audience-segment-actions.test.cjs` | Verify collapsed primary action, keyboard expansion/collapse, and all permitted actions after expansion; select record links within the table body. All 24 role/theme/layout cases pass, including edits, replication, export, deletion, unlock expiry and CSRF rotation. |
| `audience-tag-unlock.test.cjs` | Verify the same disclosure behavior and phone keyboard navigation through Details; select record links within the table body. All 24 role/theme/layout cases pass, including rename, export, single/bulk deletion, cancellation, unlock expiry and CSRF rotation. |
| `audience-campaign-lifecycle.test.cjs` | Preserve exact 44px desktop icons and require visible, labelled phone targets of at least 44px. Reset desktop campaign grid positions on phones: those positions had created extra columns and squeezed an Edit action to 22px wide. All 36 layout/workflow cases pass, including archive/restore, protected deletion, report alignment, pagination and hover colors. |
| `audience-brand-logos.test.cjs` | Open Editing tools on phones and switch back to Email when selecting canvas content. All 38 checks pass for insertion, saved designs, undo/redo, removal and empty states. |
| `audience-people-sticky.test.cjs` | Keep desktop table-only scrolling assertions; verify phone document/card scrolling, final-card visibility above navigation, selection, and restoration of desktop scrolling after resize. All 114 checks pass. |

The mobile campaign CSS and committed minified stylesheet/manifest were rebuilt.
The subsequent `npm test` run passed all 414 tests. Focused PHP checks for minified
assets, hover styles, mobile forms, and UI consistency also passed. This follow-up
does not change database or backend behavior and has not been deployed.
