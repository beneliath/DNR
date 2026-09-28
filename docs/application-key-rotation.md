# Application encryption key rotation

The application accepts the existing base64-encoded 32-byte key file. To enable
versioned encryption, replace the configured key file with a private JSON keyring:

```json
{
  "active": "current",
  "legacy": "previous",
  "keys": {
    "previous": "BASE64_OF_EXISTING_32_BYTE_KEY",
    "current": "BASE64_OF_NEW_RANDOM_32_BYTE_KEY"
  }
}
```

The placeholders above are not usable keys. Preserve the existing key as the
legacy key; otherwise existing two-factor secrets and encrypted outbox payloads
cannot be recovered. Key identifiers are case-sensitive and limited to 48
letters, digits, underscores or hyphens, starting with a letter. At most eight
keys are accepted. The optional `DNR_APPLICATION_KEYRING_FILE` setting can select
another file for CLI operations; the normal Compose secret also detects JSON.

Recreate all application processes after replacing the secret. New payloads use
`dnr1:<key-id>:<authenticated-ciphertext>`. Reads accept any configured key, and
legacy unversioned payloads use the key designated by `legacy`. Recovery codes
record their key identifier; existing codes with no identifier use the legacy
key. Hashes cannot be re-encrypted, so those codes must be consumed or replaced
before retiring their key.

Use the on-demand `key-rotation` Compose service, which combines the maintenance
identity with the application key secret. The web identity intentionally cannot
read queued mail ciphertext; do not broaden its grants for rotation.

```sh
sh scripts/compose_with_provenance.sh production-ubuntu-proton-mattermost run --rm --no-deps key-rotation --table=users --limit=100
```

`/opt/dnr/bin/rotate_application_key.php` defaults to a dry run. It supports
`--table`, `--after`, `--limit` (at most 500), and `--apply`. Run each allowlisted
table and advance using the returned cursor until `finished` is true. Each batch
locks and authenticates its rows before re-encrypting; a failed batch rolls back.
Coordinate rotation with backups and keep the previous key available throughout.

Use `--retire-check=<key-id>` to check live encrypted payloads and unused recovery
codes. A clear live-data report is not permission to discard that key: retained
backups may still require it. Complete a restore exercise with the recovery copy
of the keyring, and retain old keys for as long as any retained backup needs them.
The tool never deletes keys automatically.
