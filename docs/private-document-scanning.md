# Private document scanning

The optional `scanning` Compose profile runs a pinned official ClamAV image and
a private application worker. Uploaded PDFs and PowerPoint files remain outside
the public download records until a successful scan. No documents are sent to an
external scanning provider. The scanner receives bounded streams on an internal
network; no scanner port is published and it has no database credentials or
application-file volume. Internet access is used for signature updates.

Set `COMPOSE_PROFILES=scanning` (include any existing profiles) and
`DNR_DOCUMENT_SCANNING=1` together after deployment qualification. Reserve 4 GiB
RAM for the scanner plus temporary scan space. Start the scanner, verify its
health and signatures, then start `document-scans` and recreate the web service
with scanning enabled. Keep the scanner image digest current through the normal
review and vulnerability-scanning process.

The protocol follows the official [ClamD INSTREAM documentation](https://docs.clamav.net/manual/Usage/ClamdProtocol.html).
The memory budget follows [ClamAV's Docker guidance](https://docs.clamav.net/manual/Installing/Docker.html).

Unknown responses, disconnections, scan-size limits, missing signatures or
signatures older than 72 hours do not approve a file. Incomplete checks retry
later. Detections and encrypted or over-complex documents are rejected. A scan
cannot guarantee a document is harmless; existing format validation remains.

A replacement leaves the last approved file available. Generation tokens and
published-version checks prevent a stale result from publishing after another
upload, removal or speaker change. The edit form displays pending/rejected
status. Removing a file cancels its pending scan. Pending files are included in
private backups and pinned against garbage collection. Completed scan history
expires after 30 days; ordinary immutable-file retention then applies.

Test with `python3 scripts/integration_environment.py scanning`. It creates only
labelled disposable resources, downloads signatures into its own volume, and
checks clean content, the inert EICAR antivirus test string, failed replacements,
stale claims and canceled scans. Signature download access is required.
