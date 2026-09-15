# MOED comprehensive PDF manual

The PDF follows the 13 chapters and light-theme palette of `src/help.php`, with
actual application screenshots, numbered walkthroughs, PDF bookmarks, a clickable
contents page, related-chapter links, an alphabetical topic finder, and page
navigation. Text remains searchable and selectable.

Reference text, the topic finder, and the README use two columns. Walkthrough
screenshots span the columns for legibility, with their numbered instructions in
two columns underneath. The cover draws the paths from `src/assets/dnr-logo.svg`
as native PDF vectors and omits the light asset's white background path.
Screenshots retain the original browser image; optional `crop` bounds in
`screenshots.json` frame complete rows and form sections inside the PDF without
resampling the source. The PDF keeps only the copyright line wherever a captured
application footer appears. `omitted_regions` excludes decorative footer content
while preserving the sidebar and its profile controls in full-shell views.
Reference tables and paragraphs with long unbreakable references span the full
page width, then text resumes in two columns beneath them when space permits.
Automatic word splitting, hyphenation, and paragraph widows/orphans are disabled.
Verification rejects reference pages with only stray lines of content. The build checks table
cell widths and rejects forced word breaks; verification checks mixed-page
column boundaries and all full-width blocks.

Appendix A reproduces the complete current root `README.md`, with a section index
and bookmarks. The exact source bytes are embedded as a `README.md` PDF attachment
so operators can copy commands without line wrapping. The builder verifies the
snapshot checksum against the current README and refuses a stale snapshot.
Every appendix page uses white text on a solid black background, including code,
links, headings, and running navigation.

The downloadable copy is `src/assets/docs/moed-comprehensive-user-manual.pdf`.
The local authoring output is `output/pdf/moed-comprehensive-user-manual.pdf`.
`build-report.json` records page locations and `verification.json` records the
checks performed against the finished PDF.

## Current edition

The September 15, 2026 edition covers source version 2.1.14. It updates the
version 2.1.9 guide with inquiry and task archive/restore workflows, remembered engagement
lifecycle filters, Daily Digest day/status indicators on user cards, explicit
administrator locking, and inbound-mail import/reconciliation behavior.
It also explains how archiving an engagement or inquiry hides its unfinished
tasks from active work until the parent is restored, preserving each task's
status and independent archive flag.

The sidebar guide receives brief usability guidance about the changed controls
and their effects. The PDF adds illustrated procedures, preserved-status and
restore details, mailbox health interpretation, and the full current README
appendix. Its cover, source notes, bookmarks, topic finder, and download all
identify this edition.

Twelve figures were refreshed or added with CUA in an isolated disposable preview:
manual, pipeline, inquiry-archive, inquiry-archived, engagement-list, tasks,
task-archived, users, elevation, edit-user, admin-countdown, and operations.
The `captured_at` fields identify these figures. Avery Morgan, Casey Taylor,
and the associated records are fictional. The mailbox panel uses explicitly
labeled synthetic state; no mailbox service is connected. No email or invitation
was sent, and no record form was saved during capture. Earlier illustrations
remain where their controls still apply. Decorative footer artwork is excluded.

## Rebuild from the checked-in screenshots

Use Python with the dependencies in `scripts/manual/requirements.txt`:

```sh
python3 scripts/manual/build.py
python3 scripts/manual/verify.py
pdftoppm -r 90 -png output/pdf/moed-comprehensive-user-manual.pdf tmp/pdfs/manual-page
```

Inspect the rendered pages after any content or layout change. Then install the
verified download with `python3 scripts/manual/build.py --install`, and rerun
`verify.py`. The builder uses Arial when installed and Helvetica otherwise.

When the root README changes, refresh its Markdown snapshot before building.
Use Node with `marked` installed; set `MARKED_MODULE` to its module path if needed:

```sh
node scripts/manual/render-readme.cjs
```

`readme-appendix.json` retains the complete rendered Markdown plus the source size
and SHA-256. Verification checks every substantial README text fragment, all
section destinations, and the exact embedded source attachment.

## Refresh screenshots

Use a **disposable** local preview with current migrations and fictional data.
Do not point fixture scripts or capture automation at a production database.
Earlier editions used `dnr-rolling007-preview`. The September 15 refresh used
`dnr-manual-refresh-web` and `dnr-manual-refresh-db`, with current source bind
mounts, all migrations, and fictional Avery Morgan and Casey Taylor accounts.
The PDF identifies both the current source version and branch feature.
All temporary services and disposable volumes were removed after verification.
Mail used a development log and Mattermost was unconfigured.

`scripts/manual/seed.php` creates the connected fictional records. It requires
`DNR_MANUAL_FIXTURE=disposable` and `DNR_MANUAL_USER` naming an existing preview
account, and refuses to overwrite an existing manual organization.
`scripts/manual/augment.php` adds a fictional finalized report, visit counts,
birthday, user, and manually confirmed map pin. Pass the seed's ID JSON as its
first argument. These scripts are CLI-only and never send mail.

For a manual refresh, use CUA to sign in, navigate, and save screenshots with the
viewports and crops described in `screenshots.json`. Export each
`[data-manual-section]` element’s id, h2 text, inner HTML, and keywords to
`online-chapters.json`. Do not capture entered passwords or authentication codes.

The existing CLI capture utility can also be run by an operator with
`node scripts/manual/capture.cjs`, supplying:

- `PLAYWRIGHT_MODULE`: installed Playwright module path, if not on Node's path.
- `CHROMIUM_EXECUTABLE`: compatible Chromium executable, if not installed by Playwright.
- `DNR_MANUAL_BASE_URL`: loopback preview URL.
- `DNR_MANUAL_SESSION`: local Playwright storage-state file for a preview admin.
- `DNR_MANUAL_CREDENTIALS`: local preview JSON containing `username`, `password`,
  and `totp_secret`, used only for sign-in and fresh administrator confirmation.
- `DNR_MANUAL_FIXTURE_JSON`: local seed result JSON.
- `DNR_MANUAL_EXTRA_JSON`: local augmentation result JSON.
- Optional `DNR_MANUAL_ONLY`: comma-separated screenshot IDs to refresh.

Keep credentials, session cookies, enrollment keys, and raw captured page HTML
out of Git. Captures submit only account authentication when needed; they
do not send invitations, queue emails, reset statistics, or save record forms.
The screenshots and guide snapshot contain sample data. Map tiles retain their
in-screen attribution. No external PDF links or preview-dependent navigation
are required to read the manual.

## Content maintenance

`online-chapters.json` is the captured sidebar guide, and `screenshots.json`
records each illustrated view and its steps. `scripts/manual/build.py` renders
the shared chapter text, replaces two decorative-footer descriptions for the
PDF edition, and adds calendar, recovery-email, map-pin, presentation, and form
details. Reconcile these supplements with the
live source when updating the manual. The CLI capture utility preserves
supplementary CUA figures that are absent from its capture list; refresh those
figures explicitly when their workflows change. The Mattermost chapter describes the configured
plugin from the application guide; the preview screenshot explicitly shows the
unconfigured state rather than a simulated connected service.
