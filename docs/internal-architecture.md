# MOED and MessageMonkey internals

Both applications use the same account context, authentication, authorization,
database connection, rendering helpers, and release. Product modules must not
introduce account-specific implementations or bypass the existing access gates.

## PHP boundaries

| Entry point or module | Responsibility |
| --- | --- |
| `src/bootstrap.php`, `src/application_runtime.php` | Request bootstrap, dependency loading, account-aware configuration |
| `src/functions.php` → `src/shared/` | Stable helper API for rendering, sessions, authentication, authorization, CSRF, validation, addresses, phones, records, and audit |
| `src/follow_up_task_helpers.php` → `src/moed/tasks/` | Task policy, subject validation, input, persistence, lifecycle, presentation, templates, and generation |
| `src/booking_inquiry_helpers.php` → `src/moed/inquiries/` | Inquiry policy, input, persistence, lifecycle, conversion, email, and presentation |
| `src/inbound_email_helpers.php` → `src/moed/inbound/` | MIME parsing, sender authentication, storage, matching, markers, routing, processing, and queue operations |
| `src/app/MessageMonkey/WorkspaceRequest.php` | Validated workspace selection and transfer/delivery access requirements |
| `src/audience.php` | MessageMonkey authentication, shared API endpoints, access gates, and controller dispatch |
| `src/message_monkey/controllers/contacts.php` | Contacts, definitions, and import/export workspace controller |
| `src/audience_*_controller.php` | Existing campaign, collection, design, analytics, inbox, automation, assistant, and manual controllers |
| `src/audience_store.php` | Shared database operations, transactions, and composition of MessageMonkey store traits |
| `src/message_monkey/store/` | Contact/profile persistence and definition catalogs; other store traits retain their existing `audience_*` entry points |

The four helper entry points remain compatibility facades. Existing routes and
workers continue requiring those files; implementation modules are loaded by the
facade, rather than directly by callers. Definitions load before the facade's
other dependencies so circular includes retain their established behavior.
Function names, signatures, database contracts, and public URLs remain stable.

`WorkspaceRequest` centralizes route selection, not authorization itself.
`audience.php` still enforces login and transfer/delivery permissions before
dispatch. Each controller continues checking its action-specific privileges,
CSRF token, administrator unlock, and optimistic version tokens. Controller
precedence matters when a request contains both a view and a posted action;
`tests/message_monkey_workspace_request_test.php` covers those cases.

## Browser modules

`src/assets/js/page-actions.js` initializes MOED behavior from `moed/` modules:
contact and engagement forms, record selection, clipboard/QR operations,
submissions, and workspace interactions. Shared field behavior lives in
`moed/form-fields.mjs`.

`src/assets/js/audience.js` initializes MessageMonkey behavior from
`message-monkey/` modules: shell, dialogs and form confirmations, workspace
search, connections, segments, campaigns, and content. `dom.mjs` supplies the
shared element, request, and URL utilities. Feature initializers receive shared
configuration and dialog/confirmation capabilities explicitly instead of
depending on variables in one large closure. The configuration object remains
shared so administrator unlock can rotate the CSRF token used by later requests.

Existing standalone feature entry points, shared pagination/mobile modules, and
UI standards remain in use. The app still serves the same bundled asset URLs;
the new modules do not add runtime network imports.

## Build and verification

`scripts/frontend-assets.mjs` is the catalog of JavaScript entry points, bundle
settings, and root stylesheets. `scripts/build-frontend-assets.mjs` compiles that
catalog; page stylesheets are discovered automatically. Map/PDF dependency builds
keep their specialized existing steps. `npm run build:assets` also rebuilds the
asset fingerprint manifest. Commit generated assets with their source changes.

- `npm test` rebuilds assets, checks JavaScript syntax recursively (including
  `.mjs` modules), and runs browser-module behavior tests.
- `composer check` lints PHP, runs the four static-analysis configurations, and
  runs local tests. The shared/MOED modules are included in helper-level analysis.
- `python3 scripts/integration_environment.py` builds a disposable Docker
  environment with synthetic data and runs the HTTP/database integration suites.
  It removes its test containers and volumes afterward. Never point these tests
  at an active production account.
- `tests/browser/*.test.cjs` provides fixture-based browser checks. Set
  `DNR_PLAYWRIGHT_MODULE` and `PLAYWRIGHT_BROWSERS_PATH` when using an existing
  Playwright installation outside this repository.

Source contract tests use `tests/support/source_modules.php` to inspect explicitly
loaded modules without executing them. Prefer behavior tests for new logic;
source checks should not require an implementation to stay in a monolithic file.

AI Coach's reviewed procedure/form catalogs hash both compatibility entry points
and their implementation modules. Review affected guidance when those sources
change, then update only the reviewed hashes. Run
`python3 scripts/ai_help/check_source_hashes.py` to verify the catalogs; do not
bypass their stale-source protection.
