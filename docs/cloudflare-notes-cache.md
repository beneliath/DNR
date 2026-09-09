# Cloudflare speaker-note caching

The audience URL stays `/surls/{code}`. It checks availability and records one
link visit, then redirects to `/surls/{code}/speaker-notes.pdf`. Only the canonical
PDF URL with no query string may be cached. Existing QR codes, images, speaker
attribution and historical statistics are preserved. New notes statistics count
link visits, including when the PDF is served from Cloudflare. Direct PDF visits
and cache fills do not count. HEAD, bots and prefetches remain excluded.
The image precompiles the detector's static YAML rules into immutable PHP data
shared through OPcache. This avoids reparsing those rules for every scan while
preserving browser/OS classification and bot filtering. A content hash selects
the matching artifact; development uses the original parser when no match exists.

## Cloudflare rule

Create a Cache Rule named **MOED public speaker notes** with this expression:

```
(http.host eq "moed.beneliath.com" and http.request.uri.path wildcard "/surls/*/speaker-notes.pdf" and http.request.uri.query eq "")
```

- Cache eligibility: **Eligible for cache**.
- Edge TTL: **Use cache-control header if present, bypass cache if not**.
- Browser TTL: **Respect origin TTL**.
- Respect strong ETags: enabled.
- Leave cache keys unchanged. Do not override TTL by status code.

Do not broaden this rule to redirects or authenticated routes. Do not override
`no-store`: unavailable files, invalid ranges and pending purges need to bypass
the edge. The application emits `Cloudflare-CDN-Cache-Control: public,
max-age=300, must-revalidate` only for successful, eligible public PDFs, while
`Cache-Control: private, no-store` continues to govern browser caches. Query
variants and `short_link.php` aliases remain uncached. Cloudflare supports
separate [CDN cache controls](https://developers.cloudflare.com/cache/concepts/cdn-cache-control/)
and [byte ranges and request collapsing](https://developers.cloudflare.com/cache/concepts/default-cache-behavior/).

## Deployment

Apply the ordered migration and updated grants through the normal release
workflow. It adds a durable invalidation queue and transactional triggers; it
does not change notes, QR codes or existing statistics. The web image uses 20
PHP workers in a 14 GiB container envelope (512 MiB per-request PHP limit,
1.25 GiB upload/backup tmpfs, plus native/runtime headroom). The memory limit is
a ceiling, not a reservation. Host memory and competing workloads still matter.

Provision these files outside version control in the owner-protected `secrets/`
directory, with read access for the unprivileged container process:

- `cloudflare_zone_id`: the beneliath.com zone ID.
- `cloudflare_purge_token`: a dedicated API token with only **Zone → Cache Purge
  → Purge**, scoped to **beneliath.com**. Cloudflare's permission covers the zone;
  the worker submits only canonical notes URLs. No DNS or rule-edit permission
  is needed.

`scripts/compose_with_provenance.sh` detects the token file, requires the zone
file, and adds `docker-compose.cloudflare.yaml`. Absolute file-path overrides are
`DNR_CLOUDFLARE_ZONE_ID_FILE` and `DNR_CLOUDFLARE_PURGE_TOKEN_FILE`. The overlay
enables a 300-second edge TTL on web and starts the `notes-cache` worker. Without
the overlay, caching remains disabled. Only the worker receives the API token;
it uses the existing restricted geocoder database account with access to the
purge queue, not PDF contents or user records. The release workflow stops this
worker with the other writers before its verified database backup.

## Invalidation and failure behavior

Notes insert/update/delete, link edits/deletion, and presentation/event deletion
enqueue affected bearer codes in the same database transaction. Explicit parent
triggers cover MySQL cascades. Jobs have no foreign key, so deleting the source
record cannot delete its invalidation. Rollbacks discard their jobs too.

The worker polls every five seconds, batches up to 30 URLs, and calls
[purge by URL](https://developers.cloudflare.com/cache/how-to/purge-cache/purge-by-single-file/).
It retries failures indefinitely with capped backoff; failed jobs survive process
or host restarts. A generation check prevents an old response from acknowledging
a newer edit. It purges again after 90 seconds to clear old responses that were
already in flight. While a job remains pending, MOED suppresses new origin cache
fills for that URL. During this brief period downloads still work through PHP.

An already cached copy may remain available until a purge succeeds or its
five-minute TTL expires. This is not instantaneous revocation. No stale-serving
override should be enabled. The worker healthcheck reports loss of successful
progress; inspect its logs and `notes_cache_purge_queue.last_error` on failure.
Do not log the token or full Cloudflare response bodies. Restoring an older
database also requires purging the affected notes URLs before re-enabling
caching; a data restore is separate from ordinary edits.

## Verification

The unit and database suites cover scope, queue rollback, retries, concurrent
edits, cascaded deletions, cache headers, ranges, statistics and disabled links.
Run `notes_cache_http_integration_test.php` as the disposable maintenance account;
set `DNR_TEST_NOTES_EDGE_CACHE_ENABLED=1` only when the test web service has caching
enabled. The standard integration runner tests the disabled-by-default mode.

Use `tests/notes_download_burst_test.py` with a synthetic fixture, its SHA-256,
and size. Start with the default 20 clients; increase to 350 or 500 explicitly
after checking available runtime memory and setting limits for the disposable
web, ingress and database containers. A Docker Desktop VM may have much less
memory than the Mac or production host. It refuses non-loopback or non-disposable targets
and validates every full response's size and hash. A local origin result is not
a Cloudflare or conference-Wi-Fi measurement.

On 2026-09-09, the staged local origin test passed 20, 100 and 350 simultaneous
downloads of an 11,312,016-byte synthetic PDF. The 350-client stage had zero
failures, a 15.87-second p95 and a 16.11-second maximum. All 470 QR visits across
the stages were counted exactly once. This used the precompiled detector rules,
a 16 GiB Docker Desktop VM, a 2 CPU / 2 GiB web limit, a 2 GiB database limit and
a 768 MiB ingress limit. No service restarted during the successful stages.
Earlier runs in the crowded 8 GiB VM failed with out-of-memory events; increasing
PHP workers alone was insufficient. These local results do not qualify a
production image or verify Cloudflare HIT behavior after rollout.

After rollout, verify public responses transition from MISS to HIT after the
initial purge queue drains. Verify the short redirect remains uncached, statistics
increment once per link visit, and replacement/removal invalidates the cached
PDF. Use a dedicated presentation fixture for these checks to avoid altering
real speaker files or polluting audience statistics. Warm-cache and cold-cache
behavior both need validation; a single warm request does not fill every edge
location. Keep the queued-purge worker healthy throughout presentations.
