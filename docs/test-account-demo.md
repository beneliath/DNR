# Test Account demonstration data

The live demonstration is at https://moed.beneliath.com/a/test-account on s1.
`TestAdmin`, `TestEditor`, and `TestReviewer` are ordinary Account Admin, Editor,
and Reviewer users with no platform identity. Their passwords are supplied
separately. Only these operator-pinned demo identities may sign in without MFA;
all other users continue to require it. The exception checks the private numeric
user ID, fixed account key, member mode, exact username, matching role, active
status, and absence of both SuperAdmin privilege and enrolled MFA. Changing
eligibility revokes the password-only session.

TestAdmin sees only itself under Users. Server-side guards block other user
profiles, password/MFA resets, lifecycle actions, bulk user deletion and invitations.
Its username and role stay fixed while personal profile fields remain editable.
Account Security is hidden and forbidden for every Test Account user, including
Editor, Reviewer, ordinary Admin, and linked SuperAdmin identities. Direct GET/POST
requests to password changes/recovery, MFA setup/recovery codes, and recovery-email
changes/verification are blocked in this Account. Authentication MFA challenges
still apply to users outside the three pinned demo exceptions. Account Settings
remains hidden and forbidden for TestAdmin, including direct GET/POST requests.
The operator's SuperAdmin login remains available with its existing MFA.

The original synthetic fixture was anchored to October 12, 2026 and spans July
12, 2026 through July 12, 2027, with events in every intervening month. Its hosts,
contacts, speakers, titles, narratives, financial amounts, receipts, notes,
PowerPoint deck and avatars are fictional. Production was inspected in read-only
transactions for patterns, and its business-record hashes were recorded for a
before/after comparison. No production business records or assets are imported.
Email addresses use `example.invalid`, phone numbers use the reserved 555-01xx
range, and speaker web links point to `example.org`. Map pins are approximate
city centers, identified as such in the sample notes. Simulated correspondence
has no deliverable payload; seeding never queues SMTP messages or notifications.

## Coverage

The fixture includes 19 organizations, 35 contacts, four speakers and 15 engagements, with a smaller presentation/checklist set.
There are six active pipeline inquiries, eight visible inquiry records plus one
archived example, and only three overdue tasks at the initial anchor date. It covers:

- Every event type, confirmation status and lifecycle; single and multi-day
  schedules, upcoming/ongoing/completed events, a postponement linked to its
  replacement, cancellation, and an archived event.
- Calendar, dashboard, date filters, search, birthdays, map pins, standard event
  checklists, overdue and upcoming work, all task statuses/priorities, unassigned
  work and archived tasks.
- Every inquiry stage and intake source, linked next actions, stage history,
  booked inquiries and declined/archived examples.
- Host, on-site, travel, billing and materials contacts; multiple organization
  affiliations, an unaffiliated contact, archived contacts/organizations, and
  intentional duplicate-review/merge candidates.
- Speaker biographies, avatars, web/custom links, PDF notes, a real six-slide
  PowerPoint deck, QR downloads and illustrative link statistics.
- Chron histories, simulated incoming/outgoing correspondence, Mail Review and
  reply-follow-up work, without sending actual messages.
- Three final financial reports, a corrected report with revisions, a financial
  draft, confirmed zeros and amounts still awaiting confirmation.
- Eight owned expenses, seven generated PDF receipts, two simulated submitted
  reimbursement packages (one archived), a correction note, an editable draft,
  available/unreceipted expenses, and fictional reimbursement setup.

Admin features such as users, templates, settings, exports and archives are
available through the ordinary Account Admin permission set. Calendar tokens,
new invitations, AI Coach conversations and live integration activity are created
by the tester through their normal UI workflows. Immutable financial/submitted
records retain the application's normal deletion restrictions; the operator's
explicit demo refresh can replace the fictional baseline.

## Rebuild and refresh

A request to refresh Test Account uses the approved immutable baseline at
`/home/dgilmore/moed/var/backups/test-account-demo-baseline/20261013T002933Z-9522b69c/`,
captured on October 12, 2026 in Chicago. Its original date anchor is `2026-10-12`.
Use the then-current `America/Chicago` date as the new anchor and remap dates to
span three calendar months before through nine calendar months after, clamping
month ends and leap days. Preserve linked schedules, reimbursement snapshots and
generated documents consistently with the remapped dates.

Preserve current TestAdmin/Admin, TestEditor/Editor, and TestReviewer/Reviewer
identities, passwords, active status, pinned 2FA exemptions, and existing access
restrictions. Account Security stays unavailable to every user while in Test
Account. Preserve the operator identity and current account settings/secrets;
restore only the approved business data and assets. Keep the current shared app
release. Never use the full snapshot to overwrite live users or authentication
state, and never replay integration deliveries. Verify a fresh before-change
backup, stage the restore/remapping in an isolated database, and validate the
result before replacing live Test Account business records.

Read the baseline's `README.md`, `receipt.json`, `date-remap-manifest.json`, and
`SHA256SUMS.json` before restoring. A second verified copy is at
`output/test-account/snapshots/20261013T002933Z-9522b69c/` in the local workspace.
The private s1 bundle's `refresh-policy.json` identifies this approved baseline.
The standing [baseline refresh rule](../.cursor/rules/test-account-baseline-refresh.mdc)
applies to future requests; keep the baseline unchanged unless the user explicitly
chooses a replacement.

### Original seed regeneration (only when explicitly requested)

The existing `test_account_refresh.py` rebuilds the original synthetic seed. It
does not restore the captured baseline, so it is not the default operator for a
Test Account refresh under the standing policy above.

The saved s1 bundle is `/home/dgilmore/moed/var/demo/test-account/`. It contains
the seed, asset generator, original generated assets, pristine reference data and refresh operator. The
encrypted before-change and pitstop database/file snapshots live under
`var/backups/test-account-demo-before/` and `var/backups/test-account-demo-pitstop/`.
Their password is the existing member backup password, never a public login
password. `latest-receipt.json` records the exact snapshot paths and fixture
window. The native snapshot tool decrypts/restores the SQL into a disposable
network-isolated database and verifies every referenced file before reporting
`restore_verified=true`.

Preview a refresh on s1 without changing any records:

```sh
python3 var/demo/test-account/test_account_refresh.py --replace
```

When a refresh has been requested, rebuild for the then-current Chicago date:

```sh
python3 var/demo/test-account/test_account_refresh.py --apply --replace
```

Use `--as-of=YYYY-MM-DD` for an explicitly chosen anchor. Month-end and leap-day
anchors are clamped to the final valid day of the destination calendar month.
The operator checks the exact member/database identity, holds the deployment
lock, withdraws only Test Account's ingress, pauses its workers, makes a fresh
encrypted restore-verified backup, clears only an explicit business-table
allowlist, seeds the relative window, verifies consistency, makes a new verified
pitstop snapshot, and resumes the saved member services. Users, passwords, MFA and account settings are preserved. Original demo task
templates and cost centers are restored from the saved pristine reference fixture. A new
foreign-key relationship from an unlisted table blocks refresh for review.
An incomplete reset/seed leaves the member paused with its prior backup retained.
Do not point this operator at the primary account or alter its identity guards.

The asset generator uses Python `reportlab`, `python-pptx` and Pillow:

```sh
python3 scripts/test_account_assets.py output/test-account/assets
php scripts/test_account_seed.php --as-of=2026-10-12
```

The seed is a read-only plan by default. `--apply` additionally requires the
running member's environment and matching database profile, an active ordinary
TestAdmin, readable assets, and empty business tables. The seed writes all its
business records in one transaction and fails unless every data-consistency
count is zero. Never rerun `test_account_create_user.php` as part of a refresh;
it creates the login once and refuses to overwrite existing credentials.

Save `test_account_calendar_samples.php` alongside the seed and refresh scripts in
the private s1 fixture bundle. Refreshes preserve all three demo logins and their
operator-pinned authentication settings, and regenerate the calendar examples.
