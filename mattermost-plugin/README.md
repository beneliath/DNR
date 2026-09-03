# MOED for Mattermost

This server plugin keeps MOED as the system of record while exposing a small,
auditable workflow surface inside Mattermost.

Version 0.4.6 provides:

- short-lived, single-use account linking;
- `/moed status` plus polished `/moed today` and `/moed tasks` dashboards;
- responsive, theme-aware share-safe engagement cards, with a quick-copy email routing marker on private event lookups and channel links;
- an always-visible channel-header chain control plus a compact `[MOED#n]` channel-name marker in web, desktop, and mobile sidebars, while cards and email tools retain the full signed `[MOED#n.token]` routing marker;
- editor/admin channel-to-engagement binding;
- role-checked Assign to me, Start, Complete, and Reopen task buttons;
- top-level **Message actions** entries to add a linked-engagement task, save a post to its Chron, or prepare an engagement email;
- durable MOED-bot reactions on source posts: :memo: for an explicit Chron save and :email: after the first successful delivery of an email that includes the selected post or thread;
- reviewed, contact-scoped engagement email with optional post/thread context, delivery feedback, automatic Chron history, and private reply alerts;
- optimistic task concurrency and idempotency protection;
- private slash-command responses except for deliberate channel binding.

The plugin never connects to the MOED database. It authenticates to the MOED
integration API with a deployment secret and sends the Mattermost user identity
on every request. MOED resolves the link, verifies the active account and role,
and performs all reads and writes.

## Build

Go 1.26.7 or newer and Node.js with the repository's root dependencies are
required. Run `npm install` from the repository root once before building the
plugin webapp.

```sh
cd mattermost-plugin
make dist
```

The installable bundle is written to
`dist/org.moed.mattermost-0.4.6.tar.gz` with its SHA-256 digest printed at the
end of the build.

## Install

Follow the complete deployment sequence in
[`docs/mattermost-plugin.md`](../docs/mattermost-plugin.md). In outline:

1. generate one random 32-byte shared secret;
2. deploy MOED with `docker-compose.mattermost.yaml` and run the migration;
3. enable Mattermost plugin uploads;
4. upload the `.tar.gz` bundle in **System Console → Plugins → Plugin Management**;
5. configure the MOED URL, shared token, and matching Instance ID;
6. enable the plugin and verify with `/moed status`;
7. each user links from **MOED → Mattermost**.

Do not commit the shared token or paste it into a channel. Rotate it by changing
the MOED secret and the masked Mattermost setting together.
