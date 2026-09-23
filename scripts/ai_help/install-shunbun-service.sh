#!/bin/bash
# Host-specific installer, including recovery of the audited partial account.
# Run via sudo on shunbun only.
# Does not change FileVault, SSH policy, sudo policy, or the existing Ollama service.
set -euo pipefail
umask 027

service_user=_moedollama
service_home='/Library/Application Support/MOED/Ollama'
runtime='/Library/Application Support/MOED/OllamaRuntime/0.33.0'
source_runtime='/opt/homebrew/Cellar/ollama/0.33.0/libexec'
source_models='/Users/dgilmore/moed-ollama-pilot/models'
plist='/Library/LaunchDaemons/com.moed.ollama.plist'
installer_dir="$(cd "$(dirname "$0")" && pwd)"

fail() { echo "STOP: $*" >&2; exit 1; }
read_identity_value() {
    /usr/bin/dscl . -read "$1" "$2" | /usr/bin/awk -v key="$2:" '$1 == key {print $2}'
}
set_account_attribute() {
    echo "Setting service account attribute: $1"
    if ! /usr/bin/dscl . -create "/Users/$service_user" "$1" "$2"; then
        fail "macOS denied setting $1. Account creation is incomplete. Check Remote Login's full disk access permission before retrying; do not delete or recreate this account."
    fi
}
[[ $(id -u) == 0 ]] || fail 'Run this installer with sudo.'
[[ $(/usr/sbin/scutil --get LocalHostName) == shunbun ]] || fail 'This installer is only for shunbun.'
[[ $(/usr/bin/uname -m) == arm64 ]] || fail 'Apple Silicon is required.'
[[ -f "$installer_dir/com.moed.ollama.plist" ]] || fail 'The reviewed launchd plist must accompany this script.'
/usr/bin/plutil -lint "$installer_dir/com.moed.ollama.plist"

# Resume only the exact partial identities audited after the September 22
# directory-service denial. Refuse any unrelated account or group with the name.
resume_identity=false
if /usr/bin/dscl . -read "/Users/$service_user" >/dev/null 2>&1; then
    [[ $(read_identity_value "/Users/$service_user" GeneratedUID) == 3C6D5E72-7764-4836-A9C2-419A2B10AC2D ]] || fail 'Unexpected existing service user UUID.'
    [[ $(read_identity_value "/Users/$service_user" UniqueID) == 502 ]] || fail 'Unexpected existing service UID.'
    [[ $(read_identity_value "/Users/$service_user" PrimaryGroupID) == 702 ]] || fail 'Unexpected existing service user group.'
    [[ $(read_identity_value "/Groups/$service_user" GeneratedUID) == BC7FD13A-C684-435F-BF15-AD55BFC52CE3 ]] || fail 'Unexpected existing service group UUID.'
    [[ $(read_identity_value "/Groups/$service_user" PrimaryGroupID) == 702 ]] || fail 'Unexpected existing service GID.'
    if /usr/sbin/dseditgroup -o checkmember -m "$service_user" admin >/dev/null 2>&1; then
        fail 'The service identity unexpectedly has administrator membership.'
    fi
    resume_identity=true
elif /usr/bin/dscl . -read "/Groups/$service_user" >/dev/null 2>&1; then
    fail 'A service group exists without the audited service user; inspect before retrying.'
fi
for destination in "$service_home" "$runtime" "$plist"; do
    [[ ! -e "$destination" && ! -L "$destination" ]] || fail "Destination already exists: $destination"
done
for parent in '/Library/Application Support/MOED' '/Library/Application Support/MOED/OllamaRuntime'; do
    [[ ! -L "$parent" ]] || fail "Refusing symbolic-link parent: $parent"
    if [[ -e "$parent" ]]; then
        [[ $(/usr/bin/stat -f '%Su:%Sg:%Lp' "$parent") == root:wheel:755 ]] || fail "Unexpected parent ownership/permissions: $parent"
    fi
done
! /usr/sbin/lsof -nP -iTCP:11437 -sTCP:LISTEN >/dev/null 2>&1 || fail 'Port 11437 is already occupied.'
[[ -d "$source_models" ]] || fail 'Pilot models are missing.'
[[ -z $(/usr/bin/find "$source_models" -type l -print -quit) ]] || fail 'Model directory contains symbolic links.'
[[ -z $(/usr/bin/find "$source_models" -name '*partial*' -print -quit) ]] || fail 'Model download is incomplete.'
[[ -f "$source_models/manifests/registry.ollama.ai/library/qwen3/8b" ]] || fail 'Qwen3 8B manifest is missing.'

# These two verified native executables use only Apple system frameworks.
# The GGUF/Metal runtime is pinned and copied; Homebrew's optional MLX symlinks
# and owner-only executable permissions are not inherited by this service.
[[ $(/usr/bin/shasum -a 256 "$source_runtime/ollama" | /usr/bin/awk '{print $1}') == e9104e224bd12435301f2d52d67762e608b79660e9439396d23e4178262d56ba ]] || fail 'Ollama runtime checksum changed.'
[[ $(/usr/bin/shasum -a 256 "$source_runtime/lib/ollama/llama-server" | /usr/bin/awk '{print $1}') == 27215b39bf27ae80b84357acf792749f2633e2b5571f3788780f8d690dd69ec3 ]] || fail 'llama-server checksum changed.'

if [[ $resume_identity == false ]]; then
    service_uid=$(/usr/bin/dscl . -list /Users UniqueID | /usr/bin/awk 'BEGIN {max=500} $2 >= 500 && $2 < 60000 && $2 > max {max=$2} END {print max+1}')
    service_gid=$(/usr/bin/dscl . -list /Groups PrimaryGroupID | /usr/bin/awk 'BEGIN {max=500} $2 >= 500 && $2 < 60000 && $2 > max {max=$2} END {print max+1}')
    [[ $service_uid -lt 60000 && $service_gid -lt 60000 ]] || fail 'No service ID available.'
    echo "Creating dedicated non-administrator service identity (UID $service_uid, GID $service_gid)."
    /usr/bin/dscl . -create "/Groups/$service_user"
    /usr/bin/dscl . -create "/Groups/$service_user" PrimaryGroupID "$service_gid"
    /usr/bin/dscl . -create "/Groups/$service_user" RealName 'MOED Ollama Service'
    /usr/bin/dscl . -create "/Groups/$service_user" GeneratedUID "$(/usr/bin/uuidgen)"
    /usr/bin/dscl . -create "/Users/$service_user"
    /usr/bin/dscl . -create "/Users/$service_user" UniqueID "$service_uid"
    /usr/bin/dscl . -create "/Users/$service_user" PrimaryGroupID "$service_gid"
    /usr/bin/dscl . -create "/Users/$service_user" GeneratedUID "$(/usr/bin/uuidgen)"
else
    echo 'Resuming the verified partial service identity (UID 502, GID 702).'
fi
set_account_attribute RealName 'MOED Ollama Service'
set_account_attribute UserShell /usr/bin/false
set_account_attribute NFSHomeDirectory "$service_home"
set_account_attribute Password '*'
set_account_attribute IsHidden 1

/usr/bin/install -d -o root -g wheel -m 755 '/Library/Application Support/MOED' '/Library/Application Support/MOED/OllamaRuntime' "$runtime" "$runtime/lib" "$runtime/lib/ollama"
/usr/bin/install -o root -g wheel -m 755 "$source_runtime/ollama" "$runtime/ollama"
/usr/bin/install -o root -g wheel -m 755 "$source_runtime/lib/ollama/llama-server" "$runtime/lib/ollama/llama-server"
/usr/bin/install -d -o "$service_user" -g "$service_user" -m 750 "$service_home" "$service_home/models" "$service_home/logs"
# APFS copy-on-write clone: independent files without a second full model download.
/bin/cp -cR "$source_models/." "$service_home/models/"
/usr/sbin/chown -R "$service_user:$service_user" "$service_home/models"
/bin/chmod -R u+rwX,g+rX,o-rwx "$service_home/models"
/usr/bin/install -o root -g wheel -m 644 "$installer_dir/com.moed.ollama.plist" "$plist"
/usr/bin/plutil -lint "$plist"
/bin/launchctl bootstrap system "$plist"

for attempt in {1..20}; do
    if /usr/bin/curl --noproxy '*' --fail --silent --max-time 2 http://127.0.0.1:11437/api/version; then
        echo
        echo 'MOED Ollama system service is responding on loopback port 11437.'
        echo 'FileVault and the existing user Ollama service were preserved.'
        echo 'Next: verify real GPU inference under this service and startup after remote disk unlock.'
        exit 0
    fi
    /bin/sleep 1
done
fail "Service did not become healthy; inspect $service_home/logs/service.log. No existing service was changed."
