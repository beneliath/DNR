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

The September 22, 2026 edition applies to application version **2.3.0**. The
cover, scope and edition notes, README appendix version reference, and PDF
metadata use the current `VERSION` value.

The latest refresh adds ten shared online/PDF topics for ai coach: conversational
questions, keyboard shortcuts, eight interactive walkthroughs, navigation while an
answer is being prepared, stopping or resetting the conversation, answer ratings,
missing-control reports, administrator request review, verified improvement cases,
request-log clearing, and troubleshooting. The new procedures name controls from
the current application source; no new ai coach screenshots were fabricated.
The alphabetical topic finder and PDF bookmarks include the new topics. Feature
availability is explicitly conditional on ai coach being enabled in the installation.

The administrator guidance distinguishes a user's rating, an administrator's
assessment, an approved reusable guidance case, and a development review. Feedback
does not directly train the model or publish changes. Approved cases need
revalidation after guidance changes. The documentation describes the queue without
implying that a local development automation is installed on every MOED deployment.

Presentation instructions now begin at the event's Presentations tab and Edit
Presentations. PowerPoint .ppt/.pptx files allow 500 MB per file; PDF notes remain
100 MB and each save must stay below 600 MB total. Existing screenshot crops retain
the unchanged file controls and exclude the obsolete size-limit line. The event
creation procedure starts at Engagements, then + New Engagement, with organization
selection inside that form. Account Security also names the current Change Password
panel and its confirmation fields.

The earlier refresh documents persistent Save Changes/Cancel controls on Edit
Engagement, the relocated presentation statistics reset action, the Edit
Presentations button, gold financial closeouts and purple mail indicators, and
clickable record rows/cards. It replaces the obsolete instructions for saving
beside an individual presentation. The troubleshooting chapter explains how to
customize the shared Standard Event Tasks checklist, including the distinction
between a definition and an existing event copy and the required closeout task.

The two missing-file QR questions are consolidated into one presentation-file
entry with the current save workflow. The PowerPoint download question is
removed because that expected behavior is already explained in the presentation
chapter. The organization archive rule now includes active inquiries. The other
troubleshooting entries still describe current constraints or recovery steps.
The 2.2.3 deletion, account-control, and administrator-unlock guidance is retained.
The online manual now lists specific search results, highlights matching text,
opens relevant troubleshooting entries, and provides fixed Top/Bottom links.
The guide explains these controls and retains PDF-specific navigation guidance.

Ten figures were refreshed through CUA in the disposable September 22 preview:
manual, dashboard, dark, engagement-form, presentations, presentation-form,
presentation-files, presentation-file-pending, standard-tasks, and manual-search.
The manual-search figure now shows a specific topic result with highlighted words. The
manual figures are original 1265 by 712 browser captures; the other refreshed
images are original 1351 by 1309 browser captures. Crop bounds frame the relevant
controls; the engagement editing viewport also shows the persistent bottom bar
as the form continues beneath it. Decorative footers are excluded, preserving
the profile controls in the full-shell dark view. The unchanged mobile navigation
and other figures were retained after reviewing the changes since 2.2.3.

Alex Morgan, Jordan Parker, Avery Morgan, and the connected Cedar Grove records
are fictional. The September 22 Compose project has its own database, keyring,
and uploaded-file volumes, and binds only to loopback port 18132. Mail uses a
local log and Mattermost is unconfigured. CLI fixtures set up sample data and a
test PowerPoint. No record-edit forms were saved, no records or uploaded files
were deleted, and no email or invitations were sent during browser capture.
The pending-removal checkbox was cleared without saving. Temporary containers,
volumes, and preview credentials were removed after verification.

## Rebuild from the checked-in screenshots

For a requested s1 deployment that includes a changed Comprehensive Guide, finalize
the release `VERSION` before rebuilding. The installed PDF must identify the app
version it documents in that deployment across its cover, scope/edition notes,
appendix version reference, and metadata. Reconcile stale draft wording and include
the verified PDF and updated sources/reports in the release commit. Follow the
[s1 guide version requirement](../release-workflow.md#comprehensive-guide-version-for-s1).

Use Python with the dependencies in `scripts/manual/requirements.txt`:

```sh
python3 scripts/manual/build.py
python3 scripts/manual/verify.py
pdftoppm -r 90 -png output/pdf/moed-comprehensive-user-manual.pdf tmp/pdfs/manual-page
```

Inspect the rendered pages after any content or layout change. Then install the
verified download with `python3 scripts/manual/build.py --install`, and rerun
`verify.py`. Run `node scripts/build-asset-manifest.mjs` after installing the PDF
and include the updated asset manifest in the release so the download's cache
identity matches its contents. The builder uses Arial when installed and Helvetica otherwise.

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
The September 19 refresh used its own Compose project, database, and uploaded-file volume on port 18129. The PDF identifies the application version it documents.
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
figures explicitly when their workflows change. Supplementary figures include presentation-files, presentation-ppt-qr, presentation-file-pending, and the September 21 deletion reviews; attach an illustrative .pptx to the first fictional presentation before capturing its QR selector. Set a fictional task due on the day after capture to illustrate Due tomorrow. The September 21 figures use a 1265 by 712 browser viewport, with crop bounds in source-image pixels. The Mattermost chapter describes the configured
plugin from the application guide; the preview screenshot explicitly shows the
unconfigured state rather than a simulated connected service.
