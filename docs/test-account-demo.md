# Test Account demonstration data

The live demonstration is at https://moed.beneliath.com/a/test-account on s1.
`TestAdmin` is an ordinary Account Admin with `is_superadmin=0` and no platform
identity. Its password is supplied separately. This account alone may sign in
and confirm sensitive Admin actions with its password, without setting up MFA.
All other users continue to require MFA. The exception is pinned to TestAdmin's
numeric user ID in the member's private `account_profile.settings`; the fixed
account key, member deployment mode, exact username, active status and ordinary
Admin role must all match. Enabling MFA or changing eligibility revokes the
password-only session. Account Settings preserves the operator-pinned setting.

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

The fixture includes 19 organizations, 35 contacts, four speakers, 30 engagements,
51 presentations, 21 inquiries, and 293 tasks. It covers:

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
- Four final financial reports, a corrected report with revisions, a financial
  draft, confirmed zeros and amounts still awaiting confirmation.
- Sixteen owned expenses, fifteen generated PDF receipts, two simulated submitted
  reimbursement packages (one archived), a correction note, an editable draft,
  available/unreceipted expenses, and fictional reimbursement setup.

Admin features such as users, templates, settings, exports and archives are
available through the ordinary Account Admin permission set. Calendar tokens,
new invitations, AI Coach conversations and live integration activity are created
by the tester through their normal UI workflows. Immutable financial/submitted
records retain the application's normal deletion restrictions; the operator's
explicit demo refresh can replace the fictional baseline.

## Rebuild and refresh

The saved s1 bundle is `/home/dgilmore/moed/var/demo/test-account/`. It contains
the seed, asset generator, original generated assets and refresh operator. The
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
pitstop snapshot, and resumes the saved member services. Users, passwords, MFA,
account settings, standard templates and cost centers are preserved. A new
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
