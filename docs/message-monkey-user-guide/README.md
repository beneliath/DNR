# MessageMonkey Comprehensive User Guide

The November 24, 2026 edition documents MessageMonkey in MOED 2.11.35. It
refreshes four figures and adds three native 3x figures covering the published
signup-page background, tags on a new pending connection, and the Daily Summary
settings. The guide describes the 24-hour Chicago reporting window and the
distinction between assigned tags and confirmed consent. The examples use
fictional data in a separate local preview.

## Deliverables and sources

- `output/pdf/message-monkey-comprehensive-user-guide.pdf`: authoring output (ignored).
- `src/assets/docs/message-monkey-comprehensive-user-guide.pdf`: installed downloadable copy.
- `src/templates/audience/manual.php`: PDF launch link in the online manual.
- `chapters.json`: exported trusted text from `src/templates/audience/manual-content.php`.
- `screenshots.json`: figure descriptions, instructions, actual preview routes, native capture metadata, hashes, and optional lossless pixel crops.
- `screenshots/`: original native 3x PNG captures, never enlarged, sharpened, or recompressed as JPEG.
- `build-report.json`, `verification.json`, `ui-verification.json`: page destinations, image dimensions and DPI, navigation checks, and browser checks.
- `scripts/message-monkey-manual/`: preview preparation, fictional account/data setup, capture, build, and verification tools.

The PDF uses the repository's MessageMonkey logo as vector paths, illustrated workflow strips, action legends, a role table, clickable contents and bookmarks, page/chapter navigation, and an alphabetical topic finder. Capture pages may intentionally show draft, empty, unconfigured, or blocked states; instructions distinguish those states from publication, activation, and successful delivery.

## Native screenshot workflow

Follow the account-release-parity and Docker collision-check rules before starting a preview. Inspect host routes and all Docker networks, including networks whose containers are stopped. `preview.py` selects an unused dedicated subnet and creates a loopback-only Compose configuration with new, labelled volumes. It refuses to reuse a running preview. Never point these scripts at a production account or reuse its data volumes.

For the October screenshot captures, the project is `dnr-mm-guide-20261031`, the configuration is `tmp/pdfs/message-monkey-manual/compose.json`, and the URL is `http://127.0.0.1:18147`. Update edition-specific values deliberately for a new edition. The preview has no dispatch worker, uses the log mail transport, and has no configured delivery or AI credentials. The scripts require `DNR_MANUAL_FIXTURE=disposable` and the dedicated account key.

1. Run `python3 scripts/message-monkey-manual/preview.py` after checking routes and available images. Review its network record and generated configuration.
2. Start only that project with `docker compose -p dnr-mm-guide-20261031 -f tmp/pdfs/message-monkey-manual/compose.json up -d --no-build --wait`.
3. Initialize its fresh database by running the existing `/opt/dnr/bin/migrate.sh` in the `db` service, with `DNR_PRIVILEGE_SCRIPT=/opt/dnr/bin/configure_database_privileges.sh`. This does not create or change migration source files.
4. Run `/opt/dnr/bin/message-monkey-manual/account.php` as `www-data` in the `web` service. Redirect its output to the ignored `credentials.json` in the work directory. Never print, publish, or commit that file.
5. Run `/opt/dnr/bin/seed_audience_demo.php` with `DNR_SEED_AUDIENCE_DEMO=local-only`, then `/opt/dnr/bin/message-monkey-manual/seed.php`, as `www-data` in `web`. Save the latter output as ignored `fixture.json`. Seed scripts refuse duplicate setup; inspect partial failures before retrying. If IDs differ from the clean fixture, update capture routes from the returned fixture IDs.
6. For the public signup and pending-tag figures, also run the guide seed with
   `--signup-examples` and save its output as ignored `signup-examples.json` in
   the same work directory. The preview seeds a pending confirmation with
   fictional readiness metadata, restores that metadata, and publishes a
   separate single opt-in form for the appearance figure.
7. Export `src/templates/audience/manual-content.php` as JSON to `chapters.json` using PHP in the preview. Keep its stable chapter and topic identifiers.
8. Run `capture.cjs` with `PLAYWRIGHT_MODULE` pointing to the installed Playwright package and `CHROMIUM_EXECUTABLE` pointing to its matching Chromium headless shell. `DNR_MANUAL_ONLY` can select comma-separated figure IDs for targeted recapture.

The browser context sets `deviceScaleFactor: 3` and PNG `scale: device`. Most desktop captures are 3840x3000 native pixels from a 1280x1000 CSS viewport. Mobile and clipped captures record their actual dimensions. External network requests and business-changing POSTs are blocked; authentication, read-only previews, and local fictional Inbox read/presence updates are allowed. Raw HTML, session state, and credentials remain in ignored `tmp/pdfs/`.

## Build and verify

Use the Codex bundled Python runtime with ReportLab, Pillow, lxml, pypdf, and pdfplumber. The builder embeds the macOS Arial font family and the source SVG logo; it does not require network access. It copies the captured PNG pixels losslessly into PDF Flate streams, using optional crops only to improve page composition. The effective-DPI floor is 300.

```sh
python3 scripts/message-monkey-manual/build.py
python3 scripts/message-monkey-manual/verify.py
pdftoppm -r 100 -png output/pdf/message-monkey-comprehensive-user-guide.pdf tmp/pdfs/message-monkey-manual/proof
```

Review every rendered page and inspect representative pages at full resolution. Use a full matching Chromium build for WebGL map captures. Set `DNR_MANUAL_SOFTWARE_WEBGL=1` when a software renderer is required; the capture refuses to accept a failed map. `DNR_MANUAL_WORK_DIR` and `DNR_MANUAL_OUTPUT_DIR` support staging captures before installation. Public OpenStreetMap tile GETs are allowed for the real map; other external requests remain blocked.

`verify.py` checks internal destinations, all bookmarks, page totals, chapter/topic coverage, text and image bounds, one-page walkthroughs, native 3x dimensions, capture hashes, and exact embedded/source pixel equality. It rejects lossy screenshot compression and resampling. Rebuild after any source or capture changes; the printed contents and navigation converge across build passes.

After verification, copy the final PDF to `src/assets/docs/`, rebuild the asset manifest, and run `verify-ui.cjs` against the still-running disposable preview. It checks the PDF response and byte identity, the online link in light/dark mode at desktop/mobile widths, and email-editor rendering and block selection. Run the focused PHP manual renderer test and the designer model checks when those sources change.

Once work is complete, inspect labels and remove only this disposable Compose project with `down -v`. Remove its ignored credential, session, raw HTML, and render intermediates. Keep the checked source PNGs, manifests, build/verification reports, installed PDF, and final authoring output.
