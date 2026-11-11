<?php
declare(strict_types=1);

// Trusted, application-authored HTML. Keep chapter/topic IDs stable for bookmarks.
return [
    [
        'id'=>'getting-started', 'title'=>'Getting Started',
        'summary'=>'Find your way around MessageMonkey and choose the right starting point.',
        'links'=>[['Home', ['view'=>'home']], ['Connections', ['view'=>'contacts']]],
        'topics'=>[
            ['workspace', 'Your MessageMonkey Workspace', <<<'HTML'
<p>MessageMonkey is MOED’s email and audience workspace. Use it to organize connections, create campaigns, collect signups and survey responses, run automations, and review results. Each account has one shared audience. Check the account banner before making changes.</p>
<p>A MessageMonkey connection is an audience record with an email status, tags, and profile fields. Do not assume that editing an audience record also updates an organization or contact elsewhere in MOED.</p>
<p>Start at <strong>Home</strong> for a snapshot of activity. For a first campaign, review your connections, prepare your content, and check delivery setup before sending.</p>
HTML],
            ['navigation', 'Navigation, Search, and Display', <<<'HTML'
<p>The sidebar opens Campaigns, Automations, Forms, Audience, Content, Analytics, and the AI tools available to your role. Related pages appear beneath the active area; <strong>Groups</strong> follows <strong>Segments</strong> under Audience. The sidebar control minimizes navigation; on a small screen, use the menu button to open it.</p>
<p>Use <strong>Search MessageMonkey</strong> in the top bar to find available pages, connections, campaigns, flows, and help. Use each page’s search box for that list. Theme controls in the account tools switch between light and dark display.</p>
<p>Return here through <strong>User Manual</strong>. Search this guide with words such as “import”, “unsubscribe”, or “schedule”. Press <kbd>/</kbd> to focus the manual search, <kbd>Enter</kbd> to open the first matching topic, and <kbd>Escape</kbd> in the search field to clear it.</p>
HTML],
            ['lists-actions', 'Lists, Selection, and Action Icons', <<<'HTML'
<p>Search, filters, sorting, and pagination work together. If an item seems missing, clear the filters and check the archived or removed view. The page arrows move through the current result set; the page-size control changes how many rows you see.</p>
<p>Before a bulk action, read the selection count and scope. Selected rows and all matching results are different scopes. Hover over an action icon, or focus it with the keyboard, to read its label. Forms, Surveys, and Automations show <strong>Results</strong> before <strong>Edit</strong>.</p>
<p>Action icons gain a background on hover or focus. An orange outline that remains visible indicates an action protected by <strong>Admin Unlock</strong>. The outline is a permission cue; it does not mean the action has already run.</p>
HTML],
        ],
    ],
    [
        'id'=>'roles', 'title'=>'Roles and Protected Actions',
        'summary'=>'Understand which tools are available and how confirmation protects important changes.',
        'links'=>[],
        'topics'=>[
            ['permissions', 'Reviewer, Editor, and Administrator', <<<'HTML'
<div class="manual-table-wrap"><table class="data-table"><caption>Common role permissions</caption><thead><tr><th scope="col">Role</th><th scope="col">What You Can Do</th></tr></thead><tbody>
<tr><th scope="row">Reviewer</th><td>Browse permitted audience records, campaigns, forms, automations, and reports; use Ask Analytics AI and this manual. Editing, imports, exports, Inbox, and Write with AI require an editor or administrator.</td></tr>
<tr><th scope="row">Editor</th><td>Create and edit content, manage audience records, use imports and exports, and work in Inbox. Sending also requires unrestricted access and successful delivery checks.</td></tr>
<tr><th scope="row">Administrator</th><td>Use editor tools plus administrative delivery settings and protected deletion or cleanup actions. Some actions also require a recent Admin Unlock.</td></tr>
</tbody></table></div>
<p>Demo restrictions, account security requirements, and role preview can further limit these capabilities. A role name alone does not guarantee that sending or account configuration is available.</p>
HTML],
            ['admin-unlock', 'Admin Unlock and Confirmation', <<<'HTML'
<p>An orange outline marks a control that needs Admin Unlock. An administrator must complete the unlock prompt before the protected operation can proceed. If an unlock expires, complete it again when prompted.</p>
<p>Unlocking does not replace the action’s confirmation. Read the item name, count, and consequences before confirming. If a dialog requests a particular word, type that word exactly. Do not use permanent deletion when archiving or removing an item from a list would meet your need.</p>
HTML],
            ['saving-conflicts', 'Saving and Concurrent Edits', <<<'HTML'
<p>Use the page’s Save or Save draft control and wait for the saved confirmation. A preview, AI suggestion, or open editor is not proof that a change has been saved.</p>
<p>If MessageMonkey says a record changed, another save may have happened since you opened it. Preserve your unsaved text, reload the current record, compare the changes, and apply what is still needed. Repeatedly submitting an old screen will not resolve a version conflict.</p>
HTML],
        ],
    ],
    [
        'id'=>'connections', 'title'=>'Connections, Imports, and Exports',
        'summary'=>'Maintain accurate audience profiles and move data with the correct scope.',
        'links'=>[['Connections', ['view'=>'contacts']], ['Import Connections', ['view'=>'import'], 'manage'], ['Import History', ['view'=>'jobs','kind'=>'import'], 'manage']],
        'topics'=>[
            ['add-connection', 'Add or Update a Connection', <<<'HTML'
<ol><li>Open <strong>Audience</strong>. Choose <strong>Add connections → Add a single connection</strong>, or open an existing connection.</li><li>Enter or review the email address, name, profile fields, and email subscription status. Use the person’s actual consent when choosing a status.</li><li>Add relevant tags and groups. Use notes for useful context that does not belong in a structured field.</li><li>Save, then review the profile and its activity. For multiple records, filter the list and check the selection before applying a bulk change.</li></ol>
<p>Custom fields and merge tags are managed from the audience menu. Required fields and validation messages must be resolved before saving.</p>
<p>The <strong>Interaction locations</strong> card summarizes recorded signup, subscription confirmation, preference, unsubscribe, and linked survey activity. Open <strong>Activity</strong> to see individual recorded events, including tracked email opens and clicks. Locations are approximate and based on the request's IP address; country, region, and city appear only when available. Tracked email activity and one-click unsubscribes may reflect an email service or scanner's location. Editors and administrators can also see the recorded IP address. Earlier activity is not backfilled.</p>
HTML],
            ['subscription-status', 'Subscription Status, Archive, and Delete', <<<'HTML'
<p>A connection's email status describes its permission or ability to receive campaign email. Having an audience record, tag, or group membership does not by itself give permission to send.</p>
<div class="manual-table-wrap"><table class="data-table"><caption>Audience member email statuses</caption><thead><tr><th scope="col">Status</th><th scope="col">What It Means</th><th scope="col">Email Eligibility and Next Steps</th></tr></thead><tbody>
<tr><th scope="row"><strong>Subscribed</strong></th><td>Permission to receive campaign email has been recorded.</td><td>May receive campaigns and automation emails when active, selected, and otherwise eligible. This status does not guarantee delivery.</td></tr>
<tr><th scope="row"><strong>Unsubscribed</strong></th><td>The person has opted out of campaign email, or the record was explicitly marked unsubscribed.</td><td>Excluded from campaign email. A public signup must complete a fresh email confirmation before this address can rejoin, even on a single opt-in form.</td></tr>
<tr><th scope="row"><strong>Non-Subscribed</strong></th><td>The audience record exists, but permission to receive campaign email has not been obtained or recorded. This is different from an opt-out.</td><td>Excluded from campaign email. Obtain and record permission, or have the person subscribe through a signup form, before treating them as subscribed.</td></tr>
<tr><th scope="row"><strong>Cleaned</strong></th><td>The address has been identified as invalid or undeliverable, such as after a permanent bounce.</td><td>Excluded from campaign email. Supply a corrected address and fresh permission before subscribing it. Changing the status or repeating a signup cannot clear the block on the same address.</td></tr>
</tbody></table></div>
<p><strong>Pending</strong> is an additional confirmation state: a double opt-in signup is awaiting email confirmation and is not yet subscribed. Confirmation and delivery events control Pending and Cleaned; do not use a status change to bypass either safeguard.</p>
<p><strong>Non-subscribed versus Unsubscribed:</strong> both are excluded from campaign email, but Non-subscribed means permission is missing, while Unsubscribed records an opt-out. Importing a record or adding a tag does not supply missing permission.</p>
<p>Archiving removes a connection from the active audience view while retaining its record and email status. Find it under <strong>Archived connections</strong> to review or restore it. Restoring a record does not create new consent or clear an email suppression.</p>
<p>Permanent deletion is different from unsubscribing and requires an administrator, a recent <strong>Admin Unlock</strong>, and the requested typed confirmation. Use <strong>Unsubscribe</strong> to honor an opt-out.</p>
<p>A permanently deleted address can subscribe again through a published signup form. The recipient must confirm a fresh email, even when the form normally uses single opt-in. This creates a new connection without recovering erased profile data. Imports remain blocked for that address, including after the recipient confirms.</p>
HTML],
            ['import-connections', 'Import Connections', <<<'HTML'
<ol><li>Choose <strong>Add connections → Import connections</strong> and upload a file accepted by the import screen.</li><li>Map columns to email, names, custom fields, groups, or tags. Inspect the preview instead of relying only on column names.</li><li>Review subscription status, existing-record handling, and the target tags or groups. Confirm that the source supports the intended email permissions.</li><li>Start the import. Follow its progress in the job view or <strong>Import history</strong>.</li><li>Read the completion summary and row errors. Correct rejected data and verify a few imported profiles before using the audience in a campaign.</li></ol>
<p>A queued or partially processed job is not a completed import. Use the available job controls to resume or cancel as appropriate. Import undo is a protected operation: read its stated scope before confirming, especially if records have since been edited.</p>
HTML],
            ['export-connections', 'Export Connections and Job History', <<<'HTML'
<p>Editors and administrators can export permitted data. Set the desired filters, choose the export scope, and confirm whether the export contains selected connections or the complete matching set. A single connection also has an Export action on its profile.</p>
<p>Open <strong>Export history</strong> from the audience menu to review progress and download completed jobs. Check the file contents and any included supporting files before sharing them. Keep exports only where the people receiving them are allowed to access the audience data.</p>
HTML],
        ],
    ],
    [
        'id'=>'organization', 'title'=>'Tags, Groups, Fields, and Segments',
        'summary'=>'Choose the right way to organize connections and target a message.',
        'links'=>[['Tags', ['view'=>'tags']], ['Groups', ['view'=>'groups']], ['Fields and Merge Tags', ['view'=>'fields']], ['Segments', ['view'=>'segments']]],
        'topics'=>[
            ['tags', 'Tags: Internal Labels', <<<'HTML'
<p>Use <strong>Audience → Tags</strong> to create internal labels. Click a tag name to see its connections. Apply tags from a profile, the audience list, or an import; removing one from a connection changes only that assignment.</p>
<p>Archived tags retain existing assignments and saved filters but cannot be assigned anew until restored. Find them in <strong>Archived</strong>.</p>
HTML],
            ['groups-fields', 'Groups and Profile Fields', <<<'HTML'
<p>Groups collect related choices that can appear on signup or preferences forms. Set the display type and options to fit the question. Archived groups keep existing selections but cannot be selected anew until restored.</p>
<p>Fields store structured values and merge tags. In <strong>Fields and merge tags</strong>, set each type, required setting, public visibility, and default. Removing an option can change existing data. Preview personalization with blank optional values.</p>
HTML],
            ['segments', 'Segments: Reusable Audience Rules', <<<'HTML'
<ol><li>Open <strong>Audience → Segments</strong> and create a segment.</li><li>Add profile, membership, activity, or engagement rules; choose <strong>all</strong> or <strong>any</strong> matching and review the count.</li><li>Save with a useful name, then use it when selecting a campaign audience.</li></ol>
<p>Membership can change, and recipient eligibility still applies at send time. Archived segments retain their rules and existing references but cannot be used for new targeting until restored from <strong>Archived</strong>.</p>
HTML],
        ],
    ],
    [
        'id'=>'campaigns', 'title'=>'Campaigns and Planning',
        'summary'=>'Move from an idea to a complete email draft and an organized campaign plan.',
        'links'=>[['Campaigns', ['view'=>'campaigns']], ['Campaign Manager', ['view'=>'campaign-manager']]],
        'topics'=>[
            ['campaign-draft', 'Create an Email Draft', <<<'HTML'
<ol><li>Choose <strong>Create Campaign</strong> and select the available email type. Give the draft an internal name you can recognize later.</li><li>Complete the recipient selection, sender details, subject, and preview text. Review any exclusions.</li><li>Choose or create the email content. Save your edits before returning to the campaign checklist.</li><li>Review settings and tracking, preview the email, and send an appropriate test when delivery access allows it.</li><li>Use <strong>Finish later</strong> to return to the campaign list, or continue to the separate send review when the draft is ready.</li></ol>
<p>Saving a campaign does not send it. The final sending or scheduling step is separate and performs its own readiness checks.</p>
HTML],
            ['campaign-list', 'Find, Duplicate, and Archive Campaigns', <<<'HTML'
<p>Use the Campaigns search, filters, folders, and status views to find the right email. Open its name to review it. A duplicate is a starting point for a new message; recheck recipients, dates, sender information, links, and subject before sending.</p>
<p>Archive campaigns you want out of the active list while retaining their history. Review archived items before restoring or permanently deleting anything. If an email has already been sent, changing a new draft cannot change the copy in recipients’ inboxes.</p>
HTML],
            ['campaign-manager', 'Campaign Manager and Touchpoints', <<<'HTML'
<p><strong>Campaign Manager</strong> groups planned work around a campaign. Create a campaign plan, then add touchpoints for the messages and activities that belong to it. Use the available calendar and list views to review timing and open a touchpoint to adjust its details.</p>
<p>A planning date helps coordinate work. Confirm the linked email’s actual send or schedule status separately. Before an event date changes, review all affected touchpoints and scheduled emails instead of assuming one edit updates every message.</p>
HTML],
        ],
    ],
    [
        'id'=>'editor', 'title'=>'Email Templates and the Editor',
        'summary'=>'Build reusable emails and check how the final message will read.',
        'links'=>[['Email Templates', ['view'=>'templates','tab'=>'saved']]],
        'topics'=>[
            ['templates', 'Choose or Save a Template', <<<'HTML'
<p>Open <strong>Campaigns → Email templates</strong> to browse saved designs or start a new one. Choose a starter that fits the message, then replace example text, links, and images. Organize reusable designs with clear names and folders.</p>
<p>Saving a template makes it available for future work; it does not send an email. A campaign using a template still needs its own content review and recipient settings. After changing a reusable template, check an existing campaign directly rather than assuming its draft has changed too.</p>
HTML],
            ['email-editor', 'Edit Content and Design', <<<'HTML'
<ol><li>Select a content block to edit its text, image, button, or other supported content.</li><li>Use the block and style controls for spacing, alignment, colors, and typography. Keep button labels short and descriptive.</li><li>Insert approved files and logos from Content. Add useful image descriptions and check every destination URL.</li><li>Use Undo and Redo to correct recent edits, then Save. Review the saved state before leaving the editor.</li></ol>
<p>Keep essential information in text as well as images. Include a meaningful link label, such as “View the event details”, so the message remains understandable when images are blocked.</p>
<p>When an edit has not been saved, the <strong>Save</strong> button’s outline pulses. Save and wait for the saved state before leaving the designer.</p>
HTML],
            ['email-preview', 'Preview, Personalization, and Test Email', <<<'HTML'
<p>Review desktop and mobile previews, the plain-text content where available, and the final subject and preview text. Check merge tags with realistic connection values and missing optional values. Confirm dates, names, amounts, contact information, and unsubscribe or preferences links.</p>
<p>Send a test through the provided test-email control when allowed, then inspect the actual received email and click its links. A visual preview does not prove that sender setup or delivery is working. Resolve any test or preflight error before attempting the real send.</p>
HTML],
        ],
    ],
    [
        'id'=>'content', 'title'=>'Content and Brand Kit',
        'summary'=>'Keep images, files, and brand choices ready for consistent messages.',
        'links'=>[['My Files', ['view'=>'content']], ['Brand Kit', ['view'=>'brand']]],
        'topics'=>[
            ['content-library', 'Upload and Organize Files', <<<'HTML'
<p>Open <strong>Content → My files</strong> to upload supported assets and organize them into folders. Choose a descriptive filename and inspect the uploaded result. Search or filter the library when selecting content for an email or form.</p>
<p>When an image-editing control is available, check the crop and dimensions in the destination layout. Avoid embedding important text only inside an image. Before removing a file, consider where it is already used: recipients may open older messages long after they were sent.</p>
HTML],
            ['brand-kit', 'Set Up the Brand Kit', <<<'HTML'
<p>Use <strong>Content → Brand Kit</strong> to manage the logos, colors, and font choices offered by MessageMonkey. Review a logo against both light and dark backgrounds and choose colors with enough contrast for readable text and controls.</p>
<p>Save the brand choices, then inspect them in the email or form you are building. Brand settings help you stay consistent, but each design still needs a preview. Verify the final appearance instead of assuming a brand update has revised every previously saved item.</p>
<p>A newly created email design or form uses the uploaded Brand Kit font for its text styles when one is available. Otherwise it uses <strong>Default font and email fallback</strong>. Review headings, body text, and footer text in the editor; previously saved explicit font choices remain in place.</p>
HTML],
        ],
    ],
    [
        'id'=>'sending', 'title'=>'Delivery, Sending, and Scheduling',
        'summary'=>'Check readiness, approve the final audience, and follow delivery outcomes.',
        'links'=>[['Campaigns', ['view'=>'campaigns']], ['Email Delivery', ['view'=>'delivery'], 'admin']],
        'topics'=>[
            ['delivery-setup', 'Prepare Email Delivery', <<<'HTML'
<p>An unrestricted administrator configures <strong>Campaigns → Email delivery</strong>. Follow the setup screen for the Amazon SES sending identity, domain authentication, event notifications, and public email links. Complete the displayed verification steps and review readiness results.</p>
<p>Use an address and domain the account is authorized to send from. If verification is pending or a delivery requirement fails, fix that requirement before sending. Inbox receiving sources have their own setup; a working outgoing sender does not by itself establish an inbound route.</p>
<p>When Amazon SES is connected, <strong>Disconnect</strong> requires a recent <strong>Admin Unlock</strong> and confirmation. Disconnecting stops MessageMonkey delivery until a connection is configured again.</p>
HTML],
            ['send-review', 'Send or Schedule a Campaign', <<<'HTML'
<ol><li>Open the saved campaign and complete its checklist. Recheck the recipients and exclusions, sender, reply address, subject, and content.</li><li>Run the available review and test tools. Resolve blockers and understand any warnings.</li><li>Choose <strong>Send</strong> or the scheduling option. Review the final recipient count and the date, time, and time zone displayed by the scheduling screen.</li><li>Complete the final confirmation. Wait for the success or queued result before leaving the page.</li><li>Open the delivery view to confirm the campaign’s actual state and progress.</li></ol>
<p>Sending requires an unrestricted editor or administrator, suitable account security access, and successful readiness checks. A saved draft or planning touchpoint is not a scheduled send.</p>
<p>On <strong>Review your email</strong>, choose <strong>Preview your recipients</strong> under To to inspect the eligible names and addresses. The displayed list may show only the first recipients when the audience is large; use the full eligible count for the send decision. After pressing the final <strong>Send now</strong> button, a progress animation indicates that the request is being processed. Wait for the result before taking another action.</p>
HTML],
            ['delivery-results', 'Understand Delivery and Suppression', <<<'HTML'
<p><strong>Queued</strong> means work is waiting to be processed. Provider acceptance is different from confirmed delivery. Delivery events can include delivered messages, bounces, complaints, and other failures. Read the campaign’s delivery details to understand which stage has been reached.</p>
<p>Unsubscribed, cleaned, archived, or suppressed connections may be excluded from a send. Do not change status simply to force delivery. Investigate the reason, correct bad data when appropriate, and obtain valid permission where needed.</p>
<p>If sending appears stalled, check the current delivery state and error details before retrying. A repeat confirmation or a duplicate campaign can create a second send; first establish what the original operation actually did.</p>
<p>The campaign delivery page refreshes automatically every 20 seconds while it remains open. Use <strong>Refresh status</strong> when you need an immediate update.</p>
HTML],
        ],
    ],
    [
        'id'=>'automations', 'title'=>'Automations',
        'summary'=>'Build a flow, review its rules, and monitor what happens to enrolled connections.',
        'links'=>[['Automations', ['view'=>'automations']], ['Flow Templates', ['view'=>'flow-templates']]],
        'topics'=>[
            ['build-flow', 'Create and Review a Flow', <<<'HTML'
<ol><li>Open <strong>Automations</strong> and choose a flow template or <strong>Build from scratch</strong>.</li><li>Configure the entry trigger and the available conditions, delays, and actions. Read each step’s description; a template still needs your account’s choices.</li><li>Review every email and recipient condition. Check for missing values, unintended branches, and delays that do not fit the intended timing.</li><li>Save the flow, choose <strong>Continue</strong>, and resolve the validation issues before turning it on.</li></ol>
<p>While editing a draft or paused flow with <strong>Rules &amp; Actions</strong> open, the outline of the selected map card pulses to match the rule or action shown in the editing column. When editing the starting point, all of its trigger cards are outlined together. With reduced motion enabled, the outline stays steady.</p>
<p>Keep a new flow in draft while reviewing it. Activating an email automation can lead to real sends when its trigger and conditions are met.</p>
HTML],
            ['pause-flow', 'Pause, Edit, and Resume', <<<'HTML'
<p>An active flow offers <strong>Pause &amp; Edit</strong>. Pausing keeps connections’ positions while you review the flow. Save the changes, review them, and use <strong>Turn back on</strong> when ready.</p>
<p>Inspect the flow’s history and affected steps before changing timing or branching. Do not assume that a saved edit has already resumed a paused flow, or that it rewrites messages that have already been sent.</p>
HTML],
            ['flow-results', 'Results and Flow Lifecycle', <<<'HTML'
<p>In the automation list, <strong>Results</strong> appears before <strong>Edit</strong>. Use Results to review the flow’s reported activity and execution outcomes. Investigate failed or waiting steps in context rather than treating every enrolled connection as a delivered email.</p>
<p>To rename an automation, select <strong>Rename</strong> in its row, enter the new flow name, and select <strong>Save name</strong>. An active flow keeps running, and enrolled connections keep their places.</p>
<p>In <strong>Report</strong>, <strong>Step analysis</strong> shows waiting, completed, skipped, and needs-attention counts for each step. Select a step to filter <strong>Connection activity</strong>, or use its status and step filters together. Open a connection's activity row to inspect its path and any error before retrying or removing it from the flow.</p>
<p>Archive a flow when it is no longer needed in the active list. Restoring an archived flow returns it as a draft so it can be reviewed before activation. Permanent deletion is a separate protected action. A duplicate also needs its own review before use.</p>
HTML],
        ],
    ],
    [
        'id'=>'forms', 'title'=>'Forms, Surveys, and Preferences',
        'summary'=>'Collect signups, gather feedback, and let connections manage their choices.',
        'links'=>[['Forms', ['view'=>'forms']], ['Landing Pages', ['view'=>'landing-pages']], ['Surveys', ['view'=>'surveys']], ['Subscriber Preferences', ['view'=>'preferences']]],
        'topics'=>[
            ['publish-form', 'Build and Publish a Signup Form', <<<'HTML'
<ol><li>Open <strong>Forms</strong> and choose the supported form type, such as hosted, embedded, or popup. Landing pages are available under Campaigns.</li><li>Edit the fields, wording, styling, success message, and settings. Request only information you need and make the subscription purpose clear.</li><li>Preview the form on desktop and mobile. Test required fields, validation, and the completion experience.</li><li>Publish when ready, then use the public link or supplied embed instructions. Check the published version from the place where visitors will find it.</li></ol>
<p>Saving a draft does not publish it. If confirmation or double opt-in is enabled, test the confirmation email and follow-up step too. A submission awaiting confirmation is not the same as a confirmed subscription.</p>
<p>In the editor, use the field list to choose and reorder fields. <strong>More actions</strong> offers pause, duplicate, and archive controls. A public Address field begins with <strong>Address Line 1</strong>, followed by its other address inputs; it does not add a separate Address heading above them.</p>
HTML],
            ['survey-results', 'Create Surveys and Review Results', <<<'HTML'
<p>Open <strong>Audience → Surveys</strong>, choose a starting point, and edit the questions and answer choices. Preview the complete survey before publishing and sharing its public link. Check whether the questions collect the information you actually need.</p>
<p>Forms and Surveys place the <strong>Results</strong> action before <strong>Edit</strong>. Open Results to inspect the available submissions, counts, and charts. Apply the report filters and date range before interpreting totals. Export controls require the appropriate role.</p>
<p>A form visit, a submission, and a confirmed signup are different events. Use the metric label and the resource’s settings to understand what a result represents.</p>
HTML],
            ['preferences', 'Subscriber Preferences and Unsubscribe', <<<'HTML'
<p><strong>Audience → Subscriber preferences</strong> controls the experience for updating the choices exposed to connections. Review the fields and groups presented there, then test the experience using a representative connection.</p>
<p>Changing preferences is different from unsubscribing. Keep the unsubscribe path available and understandable. Use the supported personalized links in messages rather than copying one person’s private preferences URL into a campaign for everyone.</p>
<p>Archive an unused form or survey to remove it from active work while preserving its results. Restoring a resource as a draft lets you review its content and settings before publishing again.</p>
HTML],
        ],
    ],
    [
        'id'=>'inbox', 'title'=>'Inbox Conversations',
        'summary'=>'Manage replies, drafts, and follow-up with individual connections.',
        'links'=>[['Inbox', ['view'=>'inbox'], 'manage']],
        'topics'=>[
            ['inbox-setup', 'Access and Receiving Sources', <<<'HTML'
<p>Editors and administrators open <strong>Audience → Inbox</strong>. Use its navigation to review To do, Done, all conversations, Trash, drafts, and the available source or label filters.</p>
<p>An unrestricted administrator manages receiving sources. Follow the Sources setup and routing verification instructions, including an external test message when requested. Preparing a receiving address does not itself send that test. If replies are missing, verify the receiving route and filters before assuming the conversation was deleted.</p>
HTML],
            ['inbox-reply', 'Reply, Save a Draft, or Add a Note', <<<'HTML'
<ol><li>Open the conversation and read the latest messages. Verify the connection and the intended recipient.</li><li>Write a reply, optionally starting from a saved message template. Personalize its text and inspect links and attachments.</li><li>Use <strong>Save draft</strong> to keep unfinished work, or <strong>Send reply</strong> after reviewing it. Sending requires unrestricted access and the necessary delivery readiness.</li><li>Check the queued message and its delivery status in the conversation.</li></ol>
<p>An internal note records context for your team; it is different from a reply to the connection. Check which composer mode you are using before saving or sending. Respect opt-outs and any send restrictions shown for the recipient.</p>
HTML],
            ['inbox-done', 'Mark Done, Label, and Organize', <<<'HTML'
<p>Use labels to organize conversations and <strong>Mark done</strong> when follow-up is complete. Done is a workflow state, not deletion; find completed conversations through the Done or all-conversations view.</p>
<p>Use Trash only when you intend to remove a conversation from ordinary work. Check the available restore controls before considering permanent cleanup. Reusable message templates can be managed separately from the conversation drafts that use them.</p>
HTML],
        ],
    ],
    [
        'id'=>'analytics', 'title'=>'Analytics and Custom Reports',
        'summary'=>'Read performance in context and save useful report definitions.',
        'links'=>[['Email Dashboard', ['view'=>'analytics']], ['Custom Reports', ['view'=>'analytics','section'=>'reports']], ['Conversion Insights', ['view'=>'analytics','section'=>'conversions']]],
        'topics'=>[
            ['analytics-filters', 'Choose the Reporting Context', <<<'HTML'
<p>Start in <strong>Analytics</strong> and select the relevant dashboard or report. Set the date range, comparison period, message scope, and other available filters. Check the bot-activity setting when interpreting opens or clicks.</p>
<p>Email reports focus on message performance; Audience reports describe the audience; Conversion insights report the supported form or conversion activity. Recipient activity, link performance, locations and clients, and timing views answer more specific questions.</p>
<p>Read each metric’s label and denominator. A sent count differs from delivered messages, and a rate may use a different base from a nearby total. Mail privacy tools and automated link checks can affect engagement signals. An open or click is evidence of a recorded event, not proof that a person read the message.</p>
HTML],
            ['custom-reports', 'Create and Find Custom Reports', <<<'HTML'
<ol><li>Open <strong>Analytics → Custom reports</strong> and start a report if your role allows it.</li><li>Choose the available dataset, metrics, grouping, and filters. Preview the resulting table or chart.</li><li>Give the report a useful name and select the available visibility setting. Confirm whether it is private or shared.</li><li>Save it and return to the report list. Use Search reports and Favorites only to find reports you use often.</li></ol>
<p>Use <strong>Active</strong> for reports you can open. Owners and administrators can archive a report and restore it from <strong>Archive</strong>. Restoring brings back its saved settings, favorites, and sharing. An administrator can permanently delete an archived report after completing <strong>Admin Unlock</strong> and confirming deletion.</p>
HTML],
            ['report-sharing', 'Compare, Export, and Explain Results', <<<'HTML'
<p>Use the same date range and filters when comparing reports. If two totals differ, first check message selection, dataset, bot filtering, and whether one result counts unique recipients while another counts events.</p>
<p>Editors and administrators can use available export controls. Include the report name, date range, filters, and metric definitions when sharing results so someone else can understand the numbers. Re-run the report when fresh delivery or engagement events may have arrived.</p>
HTML],
        ],
    ],
    [
        'id'=>'ai', 'title'=>'Ask Analytics AI and Write with AI',
        'summary'=>'Use AI for evidence-based questions and editable writing suggestions.',
        'links'=>[['Ask Analytics AI', ['view'=>'assistant','mode'=>'analytics']], ['Write with AI', ['view'=>'assistant','mode'=>'content'], 'manage']],
        'topics'=>[
            ['analytics-ai', 'Ask a Question About Your Data', <<<'HTML'
<p>Open <strong>Ask Analytics AI</strong> and set the period and scope. Ask a specific question, such as which selected messages had the strongest click performance. Use <strong>Copy prompt</strong> beside a question to reuse its wording.</p>
<p>Check the answer's evidence and reporting context. For a connection count, <strong>View matching connections</strong> shows current records, which may differ from the saved count; use <strong>Load more connections</strong> as needed. Check unexpected figures in Analytics. An AI explanation does not establish causation or replace the report.</p>
HTML],
            ['writing-ai', 'Generate and Review Writing Suggestions', <<<'HTML'
<ol><li>Open <strong>Write with AI</strong>, available to editors and administrators.</li><li>Choose the writing task and provide the text, purpose, audience, and tone that will help shape the result. A starter button can supply an initial prompt.</li><li>Select <strong>Generate suggestions</strong>. Read the suggestions and choose the text you want to use.</li><li>Use <strong>Check content</strong> when you want feedback, then make any needed changes in the actual draft.</li><li>Review facts, dates, names, promises, links, and merge tags before saving or sending the final email.</li></ol>
<p>Your text and basic brand context go to MOED’s AI service; include only appropriate information. Suggestions do not send a campaign. Review or copy the result. Editing the request or starting a <strong>New request</strong> brings back <strong>Generate suggestions</strong> and <strong>Check content</strong>; a content check reviews the current text without an AI writing request.</p>
HTML],
            ['ai-history', 'History, Stopping, and Errors', <<<'HTML'
<p>Use request history to revisit earlier work. <strong>New request</strong> starts fresh; resetting the visible list also prepares a new request while retaining saved responses. Give each new question enough context to stand on its own.</p>
<p>If a request is taking too long, use Stop and check its state before retrying. If the AI service is unavailable, preserve your text and continue manually. A failed response is not a completed content check.</p>
HTML],
        ],
    ],
    [
        'id'=>'troubleshooting', 'title'=>'Troubleshooting and Common Questions',
        'summary'=>'Resolve the most common problems without losing work or repeating a send.',
        'links'=>[['Home', ['view'=>'home']]],
        'topics'=>[
            ['missing-items', 'An Item or Action Is Missing', <<<'HTML'
<p>Confirm the account banner, search text, filters, page number, and selected status view. Check the archive or trash for that section. If a control is missing, check your role and any role-preview or demo restrictions.</p>
<p>For a visible orange-outlined control, complete Admin Unlock using an administrator account. If access is still denied, report the exact action and error rather than trying a bookmarked administrative URL.</p>
HTML],
            ['blocked-send', 'An Email Will Not Send or a Reply Is Missing', <<<'HTML'
<p>Read the readiness or delivery error first. Check the sender identity, recipient eligibility, required content, public links, and account security restrictions. Ask an administrator to resolve delivery configuration issues.</p>
<p>For a campaign already submitted, inspect its delivery view before retrying. For an Inbox reply, inspect the conversation’s queued message and delivery state. For missing incoming mail, check Inbox sources and routing verification as well as conversation filters.</p>
HTML],
            ['unsaved-work', 'A Save Failed or the Page Changed', <<<'HTML'
<p>Copy valuable unsaved text before refreshing. Resolve any field validation message. If another user changed the record, reopen the latest version and reconcile your edits with it. If your session expired, sign in again before continuing.</p>
<p>When a long-running import or export appears stuck, review its job status and errors. Use the supported job controls instead of starting the same operation again without checking its progress.</p>
HTML],
            ['get-help', 'A Layout Looks Wrong or You Need More Help', <<<'HTML'
<p>Preview the affected email or form at both desktop and mobile sizes. Check image dimensions, long links, oversized text, and contrast in the chosen theme. For an email, compare the preview with an actual test message because email clients render designs differently.</p>
<p>When requesting help, provide the page or item name, the steps you took, the expected result, the exact error, and the time of the problem. Include your role and whether you were using a preview or demo session. A screenshot can help, but omit passwords, private tokens, and unnecessary connection data.</p>
HTML],
        ],
    ],
];
