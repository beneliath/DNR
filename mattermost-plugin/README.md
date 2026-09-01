# Moed for Mattermost

This server plugin keeps Moed as the system of record while exposing a small,
auditable workflow surface inside Mattermost.

Version 0.3.1 provides:

- short-lived, single-use account linking;
- `/moed status` plus polished `/moed today` and `/moed tasks` dashboards;
- responsive, theme-aware share-safe engagement cards;
- editor/admin channel-to-engagement binding;
- role-checked Assign to me, Start, Complete, and Reopen task buttons;
- post-menu actions to create a linked-engagement task or save a post to its Chron;
- optimistic task concurrency and idempotency protection;
- private slash-command responses except for deliberate channel binding.

The plugin never connects to the Moed database. It authenticates to the Moed
integration API with a deployment secret and sends the Mattermost user identity
on every request. Moed resolves the link, verifies the active account and role,
and performs all reads and writes.

## Build

Go 1.25 or newer and Node.js with the repository's root dependencies are
required. Run `npm install` from the repository root once before building the
plugin webapp.

```sh
cd mattermost-plugin
make dist
```

The installable bundle is written to
`dist/org.moed.mattermost-0.3.1.tar.gz` with its SHA-256 digest printed at the
end of the build.

## Install

Follow the complete deployment sequence in
[`docs/mattermost-plugin.md`](../docs/mattermost-plugin.md). In outline:

1. generate one random 32-byte shared secret;
2. deploy Moed with `docker-compose.mattermost.yaml` and run the migration;
3. enable Mattermost plugin uploads;
4. upload the `.tar.gz` bundle in **System Console → Plugins → Plugin Management**;
5. configure the Moed URL, shared token, and matching Instance ID;
6. enable the plugin and verify with `/moed status`;
7. each user links from **Moed → Mattermost**.

Do not commit the shared token or paste it into a channel. Rotate it by changing
the Moed secret and the masked Mattermost setting together.
