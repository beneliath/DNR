# MessageMonkey Inbox release preparation

Prepared for **2.7.0** on `codex/mailchimp-cloning-continued13`, based on `origin/main` 2.6.24. The user authorized a minor deployment on October 23, 2026 using the new Major.Minor.Patch convention. The earlier local 2.6.25 preparation was unpublished and is superseded by this minor release.

## Included changes

- Inbox workspace redesign, shared application font sizing, sample conversations and reply templates, continuous conversation scrolling, and a draggable conversation divider.
- Drafts use the same list/content layout, with recipient and message previews, private draft search, and editing in the content pane. Saving updates the list and retains the selected draft. The editor has consistent padding, and the sending requirements panel matches its width. The new-message recipient search is capped at 720 pixels.
- Conversations and drafts wait for an explicit selection. Landing or reloading shows selection guidance with no message selected, and does not mark a conversation read. Selecting an item displays it; refreshing clears that selection while retaining the current filters.
- Labels use a compact card grid with conversation counts and nearby edit/remove icon actions. The decorative envelope was removed from Manage Sources.
- Four additional local sample conversations were created: two initially read and two initially unread, owned by the local preview user. Reading and unread actions continue to change their states normally. Read subjects use normal font weight; unread subjects use bold. A prominent “Mark as unread” action preserves the unread state until the conversation is opened again.
- All conversations excludes Trash. Sidebar badges remain overall totals when filters are active, as requested after the count clarification.
- Template library with Active and Archived views and shared icon buttons/tooltips. Editors and administrators can archive and restore. Archived templates are excluded from reply pickers. Only an administrator with recent Admin Unlock can permanently delete an archived template; both current and legacy POST routes enforce this, CSRF, audience scope, and record versions.
- Archiving reuses the existing template `deleted_at` field. Previously removed templates appear in Archived. Creating a same-named template cannot overwrite archived content. No schema migration is needed. The canonical privilege setup adds table-specific DELETE access for the application role, consistent with other template libraries; the local development grant was applied.
- Transparent MessageMonkey SVG for application headers, transparent monkey PNG for the email footer, and transparent MOED SVG for the email header.
- Branded HTML Inbox alerts with an Inbox button and remotely loaded artwork. New messages have no embedded image attachments. Previously queued notifications retain support for their embedded artwork.
- Release tools and persistent project guidance now use SemVer: major increments the first component and resets both lower components, minor increments the second and resets patch, and patch increments the third.

## Validation

- Isolated Audience HTTP suite: 1,282 checks, including 80 Inbox checks covering explicit selection, the draft list, search, save, discard, privacy, and Trash exclusion.
- Isolated shared deletion/authorization suite: 80 checks, including archive, restore, permanently deleting an archived template, stale versions, CSRF, editor/reviewer denials, and locked/expired Admin Unlock.
- HTML email renderer: 16 checks, including SVG validity, remote image URLs, multipart HTML/plain text, and compatibility with older queued embedded images.
- Scoped PHPStan route/helper checks, PHP syntax, shell syntax, and diff whitespace checks passed.
- Authenticated localhost browser: archived and restored a sample template, verified counts and icon tooltips, and confirmed deletion opens Admin Unlock before confirmation. Sample content was retained.
- Browser checks also verified draft selection/search/save, normal versus bold subject weights, persistence after marking unread, the compact recipient search, and the Labels grid. The draft editor and requirements panel had identical measured widths and left edges.
- MOED SVG rendered and visually inspected; rasterized preview corner alpha is zero.

## Remaining release gates

Follow the normal protected release workflow and qualify final-main images before deployment. Keep all active production accounts on the same qualified release. Further notification test emails remain deferred until requested.

The release must publish `assets/dnr-logo-email.svg` and `assets/audience-logo-email.png` on each account's public origin before testing notification email graphics. Check anonymous HTTPS responses, SVG/PNG content types, and rendering in Proton Mail and Gmail. SVG support differs among email clients, so verify the requested SVG masthead in those clients after deployment.

After deployment, resend notification samples to `doug@shalominmessiah.com` and `beneliath@gmail.com` only when requested. The local sample-data script remains development-only and is not a production refresh operator.
