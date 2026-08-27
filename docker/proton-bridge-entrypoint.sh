#!/bin/sh
set -eu

bridge_user_directory=/home/proton-bridge
export GNUPGHOME="${bridge_user_directory}/.gnupg"
export PASSWORD_STORE_DIR="${bridge_user_directory}/.password-store"

umask 077
mkdir -p "$GNUPGHOME" "$PASSWORD_STORE_DIR"
chmod 700 "$GNUPGHOME" "$PASSWORD_STORE_DIR"

if [ ! -s "$PASSWORD_STORE_DIR/.gpg-id" ]; then
    key_identity='DNR Proton Bridge <bridge@localhost>'
    gpg --batch --pinentry-mode loopback --passphrase '' \
        --quick-generate-key "$key_identity" default default never
    key_fingerprint=$(
        gpg --batch --with-colons --list-secret-keys "$key_identity" \
            | awk -F: '$1 == "fpr" { print $10; exit }'
    )
    if [ -z "$key_fingerprint" ]; then
        echo 'Unable to initialize the Proton Bridge pass keychain.' >&2
        exit 1
    fi
    pass init "$key_fingerprint"
fi

# Invoke the Bridge engine directly. Proton's desktop launcher defaults to the
# Qt GUI before it forwards flags and therefore requires display-only OpenGL
# libraries that a headless container neither needs nor uses.
exec /usr/lib/protonmail/bridge/bridge "$@"
