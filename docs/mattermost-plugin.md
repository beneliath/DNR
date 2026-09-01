# MOED Mattermost Plugin

The MOED plugin is a workflow assistant for Mattermost. MOED remains the system
of record and the final authorization boundary; Mattermost stores only a
channel-to-engagement binding and renders data returned by MOED.

## Prerequisites

- Mattermost Server 9.0 or newer with permission to install custom plugins.
- A network path from the Mattermost server to the canonical MOED HTTPS URL.
- A deployed MOED version containing the Mattermost integration migration.
- The bundle `mattermost-plugin/dist/org.moed.mattermost-0.3.2.tar.gz`.

## 1. Generate the shared secret

From the MOED repository root, generate a 32-byte random token and protect it
like a password:

```sh
umask 077
openssl rand -hex 32 > secrets/mattermost_plugin_token
```

Copy the token into an approved password manager. You will paste the same value
once into the Mattermost plugin settings. Do not add the secret file to Git;
the repository already ignores `secrets/`.

Set a stable instance identifier in `.env`:

```dotenv
DNR_MATTERMOST_TOKEN_SECRET_FILE=./secrets/mattermost_plugin_token
DNR_MATTERMOST_INSTANCE_ID=primary
```

The ID may contain letters, numbers, dots, underscores, and hyphens. A user can
link only one Mattermost identity per configured instance.

## 2. Deploy or upgrade MOED

The Mattermost Compose modes add the secret only to the web container. The
normal migration service installs the link, identity, and idempotency tables
and grants the existing web database user only the required table privileges.

Choose the mode matching the deployment:

```sh
scripts/compose_with_provenance.sh production-mattermost up -d --build
```

For the Ubuntu deployment:

```sh
scripts/compose_with_provenance.sh production-ubuntu-mattermost up -d --build
```

For Ubuntu with Proton Bridge:

```sh
scripts/compose_with_provenance.sh production-ubuntu-proton-mattermost up -d --build
```

Development uses `development-mattermost`. After startup, confirm that MOED is
ready and that signed-in users can open **Mattermost** in the utility navigation.

## 3. Build the plugin bundle

Prebuilt releases can skip this step. To rebuild from source with Go 1.25 or
newer and Node.js, install the root web dependencies once and then build:

```sh
npm install
cd mattermost-plugin
make dist
```

Verify the printed digest before moving the bundle between systems. The build
produces Linux and macOS binaries for AMD64 and ARM64 plus Windows AMD64.

## 4. Allow and upload custom plugins

On self-hosted Mattermost, set `PluginSettings.EnableUploads` to `true` in
`config.json` and restart Mattermost if custom plugin uploads are disabled.
Then:

1. open **System Console → Plugins → Plugin Management**;
2. choose **Upload Plugin**;
3. select `org.moed.mattermost-0.3.2.tar.gz`;
4. open the **MOED** plugin settings;
5. enter the canonical **MOED URL**, for example `https://moed.example.org`;
6. paste the shared token into **Service Token**;
7. set **Instance ID** to the exact `DNR_MATTERMOST_INSTANCE_ID` value;
8. keep the 10-second timeout unless the network requires another value from
   2 through 30 seconds;
9. save, then enable the plugin.

Mattermost plugins execute trusted server code. Install this bundle only from
the controlled MOED build or a verified internal release.

## 5. Verify and link users

Run the private command:

```text
/moed status
```

It should report a successful MOED connection and that the account is not yet
linked. Each user then:

1. signs into MOED and opens **Mattermost**;
2. chooses **Generate One-Time Code**;
3. immediately runs `/moed connect CODE` in Mattermost;
4. confirms `/moed today` and `/moed tasks` work.

The two commands render a theme-aware MOED dashboard inside Mattermost. It
shows Overdue, Due today, Next 7 days, and Waiting counts, followed by the
user's active tasks and only the actions that MOED permits. Engagement cards
use the same webapp bundle and adapt to the available message width.

The code expires in 10 minutes, is stored only as a SHA-256 digest, is consumed
once, and is never placed in a channel-visible message. Users revoke a link
from **MOED → Mattermost**.

## Commands and permissions

- Everyone can use `help`, `status`, `connect`, personal summaries, engagement
  search, and engagement cards after linking.
- Reviewers receive read-only cards and links.
- Editors and administrators can bind/unbind channels and receive permitted
  task action buttons.
- In a bound channel, open a post's **More actions** menu, open the **MOED**
  submenu, and choose **Create MOED task** or **Save to MOED Chron**. The confirmation form lets
  editors and administrators review the text before writing it to the channel's
  linked engagement. MOED records the source author, channel, post ID, and
  permalink with the new item.
- Destructive actions, financial details, private Chron history, contacts,
  travel/compensation, files, waiting reasons, and full editing stay in MOED.

Slash responses are ephemeral. `/moed link-event ID` is the one intentional
channel-visible operation; its card contains only share-safe engagement fields.
The post-menu actions never accept an engagement ID from the browser: the
plugin resolves the selected post, verifies read-channel permission, and uses
the server-side channel binding before calling MOED. MOED then enforces the
linked account and editor/administrator role.

## Rotation and removal

To rotate the service token, replace the secret file, recreate the MOED web
container with the same Mattermost Compose mode, update the masked Service
Token setting, and run `/moed status`. Requests fail closed while the values do
not match.

To remove the integration, disable/remove the plugin in Mattermost and deploy
MOED without the Mattermost Compose overlay. Existing account-link rows contain
only internal/external user identifiers and can remain for audit continuity;
users can remove their own links before shutdown.
