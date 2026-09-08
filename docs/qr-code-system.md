# Presentation QR Codes

Saving a presentation creates one random `/surls/{code}` link for each populated
speaker URL (website, bio, donations, connection, blog, books). A notes link is
created only after a PDF is uploaded. Presentations without notes display no notes
QR card or placeholder. Repeated saves preserve the same codes. Existing
presentation links are backfilled by the forward migration. Legacy notes links
without PDFs remain hidden and acquire images only when notes are uploaded.

Upload **PDF Speaker Notes** on the presentation form. Notes are the presentation’s single PDF resource, publicly viewable by anyone holding the notes URL. Each
file may be up to 100 MB; the existing 120 MB combined request limit applies.
Replacing notes preserves the QR code. Removing notes makes the PDF
unavailable and hides its QR card until another PDF is uploaded. Re-uploading
reuses the original code and its statistics. Scanning the notes QR redirects
directly to `/surls/{code}/speaker-notes.pdf`, which opens the PDF in the browser,
like the presentation's **View PDF Speaker Notes** button.
There is no intermediate page, extra button, browser-specific code, or login.
The PDF endpoint rechecks the link and notes availability and supports
HEAD requests and single byte ranges. Existing QR codes and image bytes remain
unchanged. The short-link redirect does not count as a visit; only serving
the PDF does.

Public notes and authenticated presentation PDFs share an `application/pdf`
response with `Content-Disposition: inline` and the original filename. The browser
can display the PDF and offer its own save/share controls. The response retains
`nosniff` and a CSP restricting resource loading and framing, but omits CSP
`sandbox`, which can block WebKit's native PDF viewer (WebKit bug 284594). The PDF
contents are unchanged. Browser capabilities and user preferences control the
final viewing behavior; the application needs no JavaScript or user-agent detection.

Each generated QR code has a **Statistics** button on the presentation's card.
It opens analytics for that exact short-link ID, with the resource type,
presentation and event identified. Date filters and Clear Filters retain that
code's scope. Speaker records
provide a **Statistics** button across that speaker’s presentations. Speaker reports
show analytics only; presentation link cards remain in presentation and event views.
Admins and editors can edit a destination explicitly, disable/re-enable a link,
and generate links for newly populated speaker URLs. Reviewers can view all
statistics and download PNG/SVG QR images. Link editing requires CSRF validation
and a matching version to prevent stale edits. QR previews/downloads do not count
as audience visits. Selecting a presentation tab updates the current history
entry, so returning from statistics with the browser Back button restores that tab.

Speaker URL edits do not change existing destinations. Replacing a presentation's
speaker creates separate links and a separate notes slot. The previous speaker's
links, notes and statistics remain available in the management page. Switching
back reuses that speaker's original codes. Archiving or canceling does not expire
links; disable them explicitly when needed. Permanently deleting an event or
presentation removes its dependent links and statistics. Previously uploaded QR
images remain in storage and accessible through the authenticated legacy asset
route; existing third-party destinations cannot acquire MOED tracking retroactively.

## Statistics

Administrators can select **Reset Presentation Statistics** below a presentation's
QR cards. The existing sensitive-action unlock requires the administrator's password
and a fresh authenticator or recovery code, and lasts five minutes. After unlocking,
the administrator must confirm **Reset Statistics to Zero**. The reset permanently
removes all visit aggregates for that presentation across all dates, including
disabled links and links retained for previous speakers. QR codes, destinations,
notes, presentation details, and other presentations' statistics are preserved.
New visits count normally, and the administrator and presentation are recorded in
the audit log. Deployments must apply the updated restricted grants so the web
account can delete from `short_link_stats`.

MOED stores hourly aggregates of visits, browsers, operating systems, countries,
and referrer hostnames, with filters for speaker, event, presentation, individual
link, link type and an inclusive UTC date range (up to 366 days). The report uses
a light-blue visits area chart, a referrer doughnut, horizontal browser and OS
charts, and an interactive country map. Quick ranges cover 7, 30, 90 and 365 days;
ranges longer than 90 days group visits by month. Empty dates remain visible,
and partial months include only dates within the selected range. Small categories
are grouped into a remainder so chart totals include all visits. All countries
are included; unknown locations and territories absent from the simplified map
are shown separately. Every chart has an accessible exact-count table, with
keyboard inspection and light/dark theme support. Chart.js and Natural Earth
outlines are bundled locally; attribution is in `src/assets/licenses/short-link-stats.txt`.
To regenerate the projected map, download the Natural Earth GeoJSON referenced in
`scripts/build-stats-map.mjs` and run that script with the local file path, then
run `npm run build:assets`. Normal builds use the committed geometry without a download.
Raw IP addresses, full
referrer URLs, raw user agents, visitor IDs and analytics cookies are not stored
in these tables. Existing web-server access logging is separate.

These are visits, not unique attendees, verified camera scans, or completed
external transactions. Camera apps often provide no referrer. Known bots,
prefetches, and HEAD requests are excluded. Country and user-agent classifications
are approximate. Direct/unknown referrers and unknown countries remain visible.
PDF counts cover successfully served full requests and ranges beginning at byte
zero; later range requests are excluded. Repeated initial requests may still
count more than once. Statistics write failures are logged without blocking
normal redirects.

## Deployment

Both PNG and SVG are rendered and stored in `short_link_qr_images` in the same
transaction that creates the link. Page loads and downloads never render images.
Previews embed the small saved PNG directly in the authenticated page to avoid
multiple image requests; downloads serve the saved format from the database,
with private browser caching and content-based ETags. Changing a destination,
replacing notes, or saving a presentation preserves the image bytes and timestamp.

Apply the ordered migrations and restricted grants using the normal deployment
workflow, and rebuild the PHP image for the new Composer dependencies and Apache
rewrite module. Set `DNR_PUBLIC_BASE_URL` to the canonical origin, for example
`https://moed.beneliath.com`. It is required for production QR generation. Keep the
public origin stable after distributing codes. After applying migrations and
grants, run `php /opt/dnr/bin/backfill_short_link_qr.php` in the web container
with `DNR_PUBLIC_BASE_URL` set. This explicitly renders missing images for existing
links with available resources, in bounded batches. It is safely re-runnable and
preserves existing images and their encoded origin. Include it in deployment
before making the new pages available. The guarded s1 workflow runs this step
automatically while writers remain paused. Missing images return 503; GET requests
never generate replacements. Image data is included in normal database backups. Apache resolves the dedicated
`/surls/` path; front proxies must forward that path to MOED without caching the
responses. No external Kutt service is required. The feature uses Kutt as a
behavioral reference; it does not embed Kutt's code or user-management system.

Country detection accepts `CF-IPCountry` only through the configured trusted
Cloudflare proxy path with Cloudflare request metadata. Ensure Cloudflare sends
that header and keep trusted proxy networks accurate. Other installations can
mount a locally maintained country MMDB database (such as GeoLite2 Country) using
`docker-compose.geoip.yaml` and `DNR_GEOIP_DATABASE_FILE=/absolute/path/Country.mmdb`.
The PHP process uses `DNR_GEOIP_DATABASE` for the container path. Acquire/update
that database under its provider's terms. No external geolocation request is made
per visit. Missing country data is reported as Unknown.

Native PHP tests cover validation, bot filtering, trusted country headers, both
QR image formats, snapshot persistence, separate speakers and presentations,
notes storage, optimistic updates, and aggregate counts. HTTP integration tests
exercise `/surls/`, PDF/range delivery, QR downloads, authentication, CSRF and all
three roles against an explicitly disposable database.

Speaker Notes uses only `presentation_notes`, keyed by presentation and speaker so
published links retain their original attribution. Both new and edit forms show
one upload, and every presentation with a PDF has a **View PDF Speaker Notes**
button opening its own file in a new tab. Old `type=slides` bookmarks and old upload
field names remain compatibility aliases for the same notes resource.

The `20260907_unify_speaker_notes.sql` migration copies legacy PDFs into this store,
deduplicates identical files without changing existing notes metadata or short links,
and removes the old PDF columns. It stops before changing files if both stores hold
different PDFs for the same presentation and speaker; reconcile those files from a
verified backup before retrying through the migration recovery process. Run the
normal QR image backfill after migration to prepare newly migrated notes links.
