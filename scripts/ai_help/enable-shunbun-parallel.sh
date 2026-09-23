#!/bin/bash
# Change only the existing MOED system daemon's parallel request count.
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Run with sudo on shunbun.' >&2; exit 1; }
[[ $(/usr/sbin/scutil --get LocalHostName) == shunbun ]] || { echo 'This script is for shunbun only.' >&2; exit 1; }
plist=/Library/LaunchDaemons/com.moed.ollama.plist
[[ -f "$plist" && ! -L "$plist" ]] || { echo 'Expected system service plist is missing.' >&2; exit 1; }
[[ $(/usr/bin/stat -f '%Su' "$plist") == root ]] || { echo 'Expected root-owned service plist.' >&2; exit 1; }
[[ $(/usr/libexec/PlistBuddy -c 'Print :Label' "$plist") == com.moed.ollama ]]
[[ $(/usr/libexec/PlistBuddy -c 'Print :EnvironmentVariables:OLLAMA_HOST' "$plist") == 127.0.0.1:11437 ]]
backup="${plist}.before-parallel-two.$(/bin/date +%Y%m%d%H%M%S)"
/bin/cp -p "$plist" "$backup"
bootstrap_with_retry() {
    # bootout can return before launchd finishes removing the old job.
    for attempt in {1..15}; do
        if /bin/launchctl print system/com.moed.ollama >/dev/null 2>&1; then
            /bin/sleep 1
            continue
        fi
        if /bin/launchctl bootstrap system "$plist"; then return 0; fi
        /bin/sleep 2
    done
    return 1
}
rollback() {
    trap - ERR
    echo 'Restart failed; restoring the previous service configuration.' >&2
    /bin/launchctl bootout system/com.moed.ollama 2>/dev/null || true
    /bin/cp -p "$backup" "$plist"
    bootstrap_with_retry || echo 'The previous plist is restored, but launchd still rejected loading it.' >&2
}
trap rollback ERR
/usr/libexec/PlistBuddy -c 'Set :EnvironmentVariables:OLLAMA_NUM_PARALLEL 2' "$plist"
/usr/bin/plutil -lint "$plist"
if /bin/launchctl print system/com.moed.ollama >/dev/null 2>&1; then
    /bin/launchctl bootout system/com.moed.ollama
fi
bootstrap_with_retry
for attempt in {1..30}; do
    if /usr/bin/curl --noproxy '*' --silent --fail --max-time 2 http://127.0.0.1:11437/api/version; then
        echo
        echo 'MOED Ollama restarted with two parallel request slots. GPU concurrency still needs a live benchmark.'
        echo "Previous configuration: $backup"
        exit 0
    fi
    /bin/sleep 1
done
echo "Service did not respond. Previous configuration saved at $backup" >&2
rollback
exit 1
