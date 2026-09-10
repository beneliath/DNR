# MOED comprehensive PDF manual

The PDF follows the 13 chapters and light-theme palette of `src/help.php`, with
actual application screenshots, numbered walkthroughs, PDF bookmarks, a clickable
contents page, related-chapter links, an alphabetical topic finder, and page
navigation. Text remains searchable and selectable.

Reference text, the topic finder, and the README use two columns. Walkthrough
screenshots span the columns for legibility, with their numbered instructions in
two columns underneath. The cover draws the paths from `src/assets/dnr-logo.svg`
as native PDF vectors and omits the light asset's white background path.
Reference tables and paragraphs with long unbreakable references span the full
page width, then text resumes in two columns beneath them when space permits.
Automatic word splitting and hyphenation are disabled. The build checks table
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

The September 10, 2026 update identifies application version 2.0.8. The Inbox
overview and selected-message screenshots were replaced from the current source,
and the Chron/Email and troubleshooting text uses the current filing controls.
Other screenshots retain their September 9 capture date.

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
The edition in this branch used the isolated `dnr-rolling007-preview` containers
with development bind mounts of the current source. Its older image footer does
not identify the source edition; the PDF identifies the checked-out source in
its edition notes. Mail transport was disabled and Mattermost was unconfigured.

`scripts/manual/seed.php` creates the connected fictional records. It requires
`DNR_MANUAL_FIXTURE=disposable` and `DNR_MANUAL_USER` naming an existing preview
account, and refuses to overwrite an existing manual organization.
`scripts/manual/augment.php` adds a fictional finalized report, visit counts,
birthday, user, and manually confirmed map pin. Pass the seed's ID JSON as its
first argument. These scripts are CLI-only and never send mail.

Capture with `node scripts/manual/capture.cjs`, supplying:

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
records each illustrated view and its steps. `scripts/manual/build.py` expands
the source guide with current calendar-feed/birthday, recovery-email, map-pin,
statistics-reset, and form guidance. Reconcile these supplements with the live
source when updating the manual. The Mattermost chapter describes the configured
plugin from the application guide; the preview screenshot explicitly shows the
unconfigured state rather than a simulated connected service.
