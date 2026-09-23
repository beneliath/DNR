# Native Ollama coaching pilot

## Purpose

Help users learn how to operate MOED through interactive guidance in the
application. The assistant should understand the current page, highlight the
next relevant control, explain why to use it, and continue after the user makes
progress. Manual links support the explanation; they are not the primary
interaction. Users perform all edits and other actions themselves.

The intended inference host is the **separate Mac mini at 192.168.1.31**, whose
Sharing panel identifies it as **shunbun.local**. The current development Mac,
iwate.local, is a different machine. Do not substitute its benchmark results.

## Pilot boundaries

`scripts/ai_help/pilot.py` extracts current static prose from `src/help.php`
without executing PHP, ranks manual topics with a lexical baseline, and sends
synthetic page contexts to native Ollama. It uses no MOED database, live user
records, screenshots, credentials, or production endpoints. It cannot perform
application actions. This standalone benchmark has no chat interface. The subsequent
local UI integration and its stricter passage-selection policy are documented in
[ai-coach-integration.md](ai-coach-integration.md).

The fixtures test PPT selection and saving, role restrictions, missing controls,
standard checklist customization, the built-in closeout rule, task duplication,
Waiting validation, ambiguous requests, unsupported features, and attempts to
override the teaching role. One case supplies a previous exchange to test
continuation after an ambiguous "done" while the page still has unsaved changes.
This is a synthetic conversation fixture, not a complete live conversation test.

The model returns an explanation, one allowed control ID to highlight, a
continuation question, and supporting manual-topic IDs. The schema restricts
control IDs and citations to the current supplied context. These restrictions
ensure shape and membership, not correctness: every answer needs manual review.

The control IDs in these fixtures are a **proposed interface contract**, not
selectors already implemented in MOED. An eventual UI integration should map
approved IDs to current visible controls, show an optional highlight, and let
the user operate them. It must never execute model-generated code or selectors.

## Run

Python 3 with the standard library is sufficient. Prepare the cases and check
manual topic references without any inference service:

```sh
python3 scripts/ai_help/pilot.py --prepare-only --output tmp/ai-help/prepared.json
```

For the requested Mac, first establish SSH access with its actual macOS account.
Keep Ollama bound to loopback on that Mac and use an SSH forward for testing:

```sh
ssh -N -L 127.0.0.1:11435:127.0.0.1:11437 dgilmore@192.168.1.31
python3 scripts/ai_help/pilot.py --endpoint http://127.0.0.1:11435 \
  --model qwen3:8b --output tmp/ai-help/qwen3-8b.json
```

The runner does not install Ollama, pull models, or manage the server. Verify the
remote hardware, available storage/memory, installed Ollama version, existing
models, and service state before starting it. Reuse an existing suitable native
installation. If Qwen3 8B is absent, its Q4_K_M download is approximately 5.2 GB.
Use native macOS Ollama for Metal acceleration, with one request at a time and
cloud features disabled for this local pilot. Do not change a shared service's
configuration or interrupt its existing workloads to run the tests.

Inspect remote `ollama ps` and startup logs to confirm Metal/GPU use. Record
remote model digest, quantization, hardware, and whether other workloads were
active. The report records the API's model inventory, Ollama version, manual
source checksum, MOED version, per-case timings, answers, and smoke checks.

Measure initial model load separately from warm requests. `first_token_seconds`
is time to the first streamed JSON fragment; the complete structured answer may
not be usable by a future UI until the stream finishes. Report total latency as
well as generation speed. Sequential tests do not establish multi-user capacity.

## Review before integration

For each scenario, compare the full answer with its retrieved passages and the
`review_for` criteria. Check instruction accuracy, suitable next step, teaching
value, target choice, permission handling, unsupported assumptions, and whether
the model falsely claims an action or save succeeded. Automated regex and
membership checks are smoke checks, not a factual-accuracy score.

Before production integration, test live page changes, multi-turn sessions,
validation feedback, stale or removed controls, keyboard/screen-reader behavior,
model unavailability, and more than one user. The MOED backend should supply only
the minimal approved page state, validate response IDs against the current page
and permissions, and discard stale guidance. Field completion/validation flags
usually suffice; private field contents and secrets should not be sent.

No changes to s1 or a production MOED deployment are included in this pilot.

## Startup and service identity requirements

The service must be available after a restart without an in-person desktop
sign-in. Use a system LaunchDaemon, configured to run as a dedicated
non-administrator service account with its own writable model and log storage.
The account and daemon were installed on September 22, 2026. The daemon does
not need an interactive password in its
configuration. Ordinary user-level `brew services start ollama` runs at login
and does not meet this requirement; the system launchd domain is needed.

Configure launchd to restart an exited inference process, set explicit binary,
model, home, and log paths, and ensure the Mac remains awake while serving.
Verify GPU/Metal inference under the actual service identity. Do not assume
that a successful run in an administrator's desktop session proves operation
before login. Keep a stable network address and test an actual inference request
from s1's network path after a restart, with nobody logged into the desktop.
A system service alone also does not establish that a future authenticated
network proxy or tunnel starts correctly: verify that complete connection path.

Inspect FileVault before promising automatic restart recovery. FileVault can
block Ollama until the startup volume is unlocked. Apple documents remote SSH
unlock on Apple Silicon with macOS 26 or later when Remote Login and networking
are available. Remote unlock avoids an in-person visit but still involves
authentication; it is not fully unattended startup. Do not disable FileVault,
enable automatic desktop login, or store unlock credentials to work around this.
Choose a supported restart/recovery approach after the Mac's state is known.

Record service restart and reboot validation separately from answer quality.
The prepare-only test does not install or validate startup configuration.

## Measured on shunbun, September 22, 2026

SSH access is established as `dgilmore`. The host reports macOS 26.6.2, a
14-core M4 Pro, 64 GiB memory, and native Homebrew Ollama 0.33.0. Its existing
Ollama runs as a user LaunchAgent on loopback port 11434 with Llama 3 installed.
It was preserved. FileVault is enabled, and the user explicitly accepted remote
SSH disk unlock after restart while keeping FileVault enabled.

An isolated native pilot on loopback port 11436 downloaded Qwen3 8B and 14B,
used the Metal backend, disabled cloud features, and ran one request at a time.
`ollama ps` reported 100% GPU for both models, approximately 6.6 GB for 8B and
10.9 GB for 14B at an 8,192-token context. Requests originated on iwate through
an SSH tunnel; inference ran on shunbun. The contexts were synthetic.

The two comparable runs used the same revised prompt, source manual, scenarios,
and runner. Across 12 sequential requests each:

- Qwen3 8B: median complete response **4.846 seconds**, range **1.811–6.850**,
  median generation **42.84 tokens/second**, 8 of 12 automated smoke checks passed.
- Qwen3 14B: median complete response **9.166 seconds**, range **3.270–15.163**,
  median generation **25.09 tokens/second**, 10 of 12 automated smoke checks passed.

These are small engineering samples, not accuracy percentages or concurrency
estimates. First streamed JSON fragments arrived before complete answers but
are not necessarily usable by the interface. The 8B revised run reused a loaded
model; the first 14B request included a 1.180-second model load.

The [reviewed evaluation](ai-help-pilot-evaluation.json) preserves responses,
model digests, source/prompt/fixture/runner checksums, metrics, smoke checks, and
case-specific manual review notes. The original 8B trial and full raw reports
remain locally under `tmp/ai-help/`.

**Neither tested configuration is ready for unsupervised UI coaching.** Both
guessed a workflow for an ambiguous request. The 8B model proposed archiving a
required closeout reminder; 14B stated the restriction but then suggested an
unsupported way around it. Other answers invented labels or mishandled the
reviewer role. Some of these errors passed automated checks, demonstrating why
format and citation membership are insufficient.

For a future MOED integration, the application should control the workflow
state, role restrictions, available next steps, and completion evidence. Let the
model explain an approved step; validate its response and fall back to curated
instructions when the request is unsupported or the context is insufficient.
Further model evaluation can use these failures as regression cases.

## Dedicated service installer

The reviewed, host-specific installer is
`scripts/ai_help/install-shunbun-service.sh`, accompanied by
`scripts/ai_help/com.moed.ollama.plist`. Both are staged on shunbun in
`/Users/dgilmore/moed-ollama-pilot/setup/`. Administrator authentication
was required for installation; no broad passwordless sudo permission was
requested. The installation command was:

```sh
ssh -t dgilmore@192.168.1.31 'sudo /bin/bash /Users/dgilmore/moed-ollama-pilot/setup/install-shunbun-service.sh'
```

The installer creates a hidden, non-administrator `_moedollama` service identity
with a non-login shell and no interactive password, copies the verified
Ollama 0.33.0 GGUF/Metal runtime into a root-owned versioned directory, and clones
the pilot model files into that account's private storage. It intentionally
omits Homebrew's optional MLX backend and does not modify Homebrew permissions.
Runtime upgrades require a deliberate update to this pinned service copy.

The system LaunchDaemon uses loopback port **11437**, cloud disabled, one loaded
model, one simultaneous request, and a bounded queue. It restarts exited
processes and holds an idle-sleep assertion while running. This does not prevent
an intentional shutdown or replace disk unlock. Model data and logs live under
`/Library/Application Support/MOED/Ollama`. The existing user service on 11434
is unchanged. The installer refuses unrelated existing identities/destinations.
Its only account-recovery exception is the exact UID/GID and UUID pair inspected
after the September 22 partial installation.

Installation succeeded after recovering the verified partial account from the
earlier `eDSPermissionError`. `_moedollama` has UID 502, GID 702, a non-login
shell, and no administrator membership. The installed plist and runtime are
root-owned; the account owns its private model and log directory. Any temporary
**Allow full disk access for remote users** permission can be disabled after
verification; Ollama itself does not need that SSH permission to run. Do not
rerun the installer on the completed installation: it intentionally refuses
existing runtime and model destinations.

## Dedicated service verification, before restart

`launchctl print system/com.moed.ollama` reports a running system LaunchDaemon.
The Ollama server, its inference runner, and its idle-sleep assertion all run as
`_moedollama`. Two real Qwen3 14B requests through the service on port 11437
completed successfully: the unsaved presentation case in **18.873 seconds**
(including **6.669 seconds** of model load), then the Waiting validation case in
**7.974 seconds** (model load **0.001 seconds**). Generation was approximately
**25.5 tokens/second**. The first answer correctly selected Save Changes; the
second selected Waiting on and explained its required value. These two cases
verify service inference and do not supersede the broader quality failures.

The API reported 10,885,057,740 bytes both for loaded model size and GPU memory,
with an 8,192-token context. The model digest matches the earlier 14B trial.
The existing user service on 11434 remains present. The temporary pilot server
on 11436 has been stopped. FileVault remains enabled.

These initial service requests ran while `dgilmore` was signed into the desktop.
The recorded pre-test boot time was Unix 1787046272 (August 18, 2026), and
192.168.1.31 is on the built-in Ethernet interface. Open WebUI was running in
Docker before the restart.

## Verified restart recovery without desktop login

On September 22, the user performed the restart and remote unlock procedure
below. The subsequent checks confirmed a new boot time of **13:48:39 CDT**
(Unix **1790102919**), `/dev/console` owned by **root**, and no sessions listed
by `who`. FileVault remained enabled. The system LaunchDaemon was already
running as `_moedollama` with PID 582 and launch count 1; no service-start
command or desktop login was used during verification. Its idle-sleep assertion
was also active.

Two Qwen3 14B requests then completed through an SSH forward to port 11437:
the unsaved presentation case took **18.322 seconds**, including **6.428 seconds**
of model load, and the Waiting validation case took **7.947 seconds**, with
**0.001 seconds** of model load. Generation was approximately **25.9 tokens per
second**. Both answers matched the pre-restart replies and selected the correct
supplied control. The inference runner ran as `_moedollama`; the API again
reported all 10,885,057,740 loaded bytes in GPU memory at an 8,192-token context.
During the post-inference check, the console remained owned by root and `who`
remained empty.

This establishes that the native system service can start and generate GPU
answers after a restart and remote disk unlock, without a desktop sign-in.
It does not change the answer-quality limitations found in the broader pilot.
The [evaluation record](ai-help-pilot-evaluation.json) includes these results;
raw responses and the host snapshot remain under `tmp/ai-help/`.

### Repeat the restart check

After saving work on shunbun, the administrator can initiate the restart from
another Mac's Terminal:

```sh
ssh -t dgilmore@192.168.1.31 'sudo /sbin/shutdown -r now'
```

When SSH returns, unlock FileVault remotely:

```sh
ssh dgilmore@192.168.1.31
```

Enter the Mac account password only in Terminal. The installed Apple
`apple_ssh_and_filevault(7)` manual explains that password authentication can
unlock the data volume and SSH then briefly disconnects while normal services
start. A normal shell need not open on that first connection. Leave the Mac at
its desktop login screen so the test can establish operation without a desktop
session. Keep normal SSH host-key verification in place.

For future checks, after unlock, verify that the boot time changed, no desktop user is logged in,
the system LaunchDaemon started without manual intervention, and another real
request completes with GPU inference. An authenticated production connection
from s1, log rotation, and the MOED coaching interface are subsequent integration
work. Nothing has been deployed to s1 by this pilot.

## References

- [Ollama on macOS](https://docs.ollama.com/macos)
- [Ollama chat API](https://docs.ollama.com/api/chat)
- [Ollama network and local-only settings](https://docs.ollama.com/faq)
- [Qwen3 8B model and quantization](https://ollama.com/library/qwen3:8b)
- [Apple launch daemon lifecycle](https://developer.apple.com/library/archive/documentation/MacOSX/Conceptual/BPSystemStartup/Chapters/DesigningDaemons.html)
- [Homebrew services: boot versus login](https://docs.brew.sh/Manpage#services-subcommand)
- [Apple FileVault management and remote unlock](https://support.apple.com/guide/security/managing-filevault-sec8447f5049/web)
