# App review implementation — September 27, 2026

Working branch: `codex/app-review-improvements`, based on release 2.3.18.
These application changes are prepared and tested locally, not deployed to s1.
The separately installed NAS recovery service is live. The original mobile display
changes remain in the main checkout on `codex/mobile-display-fixes`.

## Data management

- Ordinary AI conversations expire after 90 days. Reviewed examples, feedback,
  protected versions and active/pending work are excluded from ordinary cleanup.
  A bounded maintenance worker replaces request-time cleanup.
- Administrator duplicate merging provides a preview, explicit surviving field
  choices, elevated authentication, CSRF protection, optimistic stale-edit checks
  and transactional relationship moves. Source records remain archived aliases;
  conflicting roles refuse the merge. Sources cannot be accidentally restored
  as independent duplicates.
- Old short-link visit records consolidate into hourly counts after 400 days.
  Historical totals survive; older per-visit dimensions no longer exist and the
  statistics interface identifies aggregated history.
- Encrypted, restore-tested recovery snapshots copy to the NAS every 30 minutes.
  See [recovery configuration and limitations](data-recovery-plan.md).

## Performance

- Read-only record views release PHP session locks before expensive work.
- Contact organization selectors search in bounded batches while preserving
  selected values. Stale browser searches cannot overwrite newer results, and a
  server-rendered search remains available without JavaScript.
- Network diagnostics cap detailed processing at 5,000 samples and disclose partial
  coverage. Short-link statistics bound resource grouping and preserve exact totals.
- A disposable 20,000-organization benchmark confirmed indexed 25-row first-page
  and deep-cursor reads. Local medians were 0.171 ms and 0.187 ms respectively;
  these are regression evidence, not production latency promises. Other benchmark
  fixture tables were empty, so their timings do not establish realistic capacity.

## Security

- Application encryption supports versioned keyrings with backward-compatible
  reads and bounded, restartable re-encryption. Recovery codes remain usable with
  their original key, and retirement checks expose remaining dependencies.
  [Rotation procedure](application-key-rotation.md). No production key was rotated.
- Optional private document scanning quarantines new notes/decks until approval,
  retains the previous approved asset, rejects detected threats, and defers when
  signatures are stale or the scanner is unavailable. Stale claims and canceled or
  replaced uploads cannot publish. It uses a pinned private ClamAV container and
  separate worker; no documents are sent to an external scanning service.
  It defaults off until the scanning profile and capacity are configured.
  [Scanning setup and limits](private-document-scanning.md).
- Apache access logs omit query strings and referrers that could contain sensitive
  links, including the separate download endpoint.

## Verification

- Full disposable app integration suite passed, including migrations, native
  encrypted backup/restore, HTTP workflows and actual worker privilege checks.
- Targeted merge/key rotation/statistics/retention integration and maintenance
  worker health checks passed.
- Online recovery integration passed with concurrent database writes, immutable
  uploaded files, encrypted configuration/source and protected recovery-key checks.
- Actual scanner integration passed with clean content and the standard inert
  EICAR test fixture. Protocol tests cover stale signatures and connection failures.
- JavaScript: 211 tests passed. Python: 68 tests completed, with five environment-
  gated skips. The separate targeted integrations cover the new recovery/scanning
  features; skipped tests are not represented as passes.
- PHP lint, all four PHPStan configurations and the helper suite passed in the
  final combined `composer check` run. Integration-only helper cases skipped in
  that local run are covered separately by the disposable integration suite.

## Remaining rollout work

Promote application changes through the protected release workflow only after an
explicit deployment request. This includes the schema migration and maintenance
worker; scanner activation also needs its optional profile and 4 GiB scanner
memory budget. The already installed backup schedule is independent of this app
release. Do a replacement-host recovery drill and add external backup failure
notifications when the operator chooses a destination. NAS snapshots or a further
off-site copy would protect against threats that affect both s1 and the NAS.
