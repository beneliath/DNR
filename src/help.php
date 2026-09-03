<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
startSecureSession();
requireLogin();

$manual_role = (string) ($_SESSION['role'] ?? 'reviewer');
$manual_role_label = ucfirst($manual_role);
$manual_can_manage = in_array($manual_role, ['admin', 'editor'], true);
$manual_is_admin = $manual_role === 'admin';
$manual_brand = applicationBrandName();
$manual_marker_example = applicationInboundMarkerExample(123);
$manual_marker_template = str_replace('123', 'ID', $manual_marker_example);
$manual_dashboard_days = applicationWorkflowSetting('dashboard_upcoming_days');
$manual_task_days = applicationWorkflowSetting('task_upcoming_days');
$manual_access_summary = match ($manual_role) {
    'admin' => 'You can manage every record and use the administrator, audit, and backup tools.',
    'editor' => 'You can create and maintain records, tasks, financial closeouts, and engagement correspondence.',
    default => 'You have read-only access to records, exports, maps, calendars, and your own account.',
};
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('User Manual'), [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/help.min.css',
    ],
    'scripts' => ['assets/js/user-manual.min.js'],
]); ?>
<body class="user-manual-page">
<?php include 'templates/header.php'; ?>
<main class="container manual-container">
    <section class="manual-hero" aria-labelledby="manual-title">
        <span class="manual-eyebrow"><?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> Reference Guide</span>
        <section class="manual-hero-copy">
            <h1 id="manual-title">User Manual</h1>
            <p>Everything you need to plan engagements, keep relationship history, coordinate follow-up work, and protect the records entrusted to <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?>.</p>
        </section>
        <section class="manual-role-summary" aria-label="Your access">
            <span>Your Access</span>
            <strong><?php echo htmlspecialchars($manual_role_label, ENT_QUOTES, 'UTF-8'); ?></strong>
            <p><?php echo htmlspecialchars($manual_access_summary, ENT_QUOTES, 'UTF-8'); ?></p>
        </section>
        <form class="manual-search" role="search" data-manual-search-form>
            <label for="manual-search-input">Search the manual</label>
            <section class="manual-search-control">
                <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input type="search" id="manual-search-input" placeholder="Try “financial closeout”, “recovery codes”, or “archive”" autocomplete="off" data-manual-search>
                <button type="button" data-manual-clear hidden>Clear</button>
            </section>
            <p class="manual-search-hint"><span data-manual-status role="status" aria-live="polite">Showing all 12 chapters.</span><span>Press <kbd>/</kbd> to search</span></p>
        </form>
        <nav class="manual-quick-links" aria-label="Popular help topics">
            <a href="#engagements"><span>01</span><strong>Plan an Engagement</strong><small>Schedule, people, presentations</small></a>
            <a href="#work-queue"><span>02</span><strong>Manage Follow-Up</strong><small>Owners, due dates, reminders</small></a>
            <a href="#chron-mail"><span>03</span><strong>Build the Chron</strong><small>Notes and tracked email</small></a>
            <a href="#mattermost"><span>04</span><strong>Use Mattermost</strong><small>Cards, channel links, tasks, email</small></a>
        </nav>
    </section>

    <section class="manual-shell">
        <aside class="manual-toc" aria-label="Manual chapters">
            <span class="manual-toc-label">On This Page</span>
            <nav>
                <a href="#orientation" data-manual-toc><span>01</span>Getting Oriented</a>
                <a href="#roles" data-manual-toc><span>02</span>Roles and Access</a>
                <a href="#dashboard" data-manual-toc><span>03</span>Daily Dashboard</a>
                <a href="#engagements" data-manual-toc><span>04</span>Engagements</a>
                <a href="#organizations-contacts" data-manual-toc><span>05</span>Organizations and Contacts</a>
                <a href="#work-queue" data-manual-toc><span>06</span>Work Queue</a>
                <a href="#chron-mail" data-manual-toc><span>07</span>Chron and Email</a>
                <a href="#map-calendar" data-manual-toc><span>08</span>Map and Calendar</a>
                <a href="#profile-security" data-manual-toc><span>09</span>Profile and Security</a>
                <a href="#mattermost" data-manual-toc><span>10</span>Mattermost</a>
                <a href="#administration" data-manual-toc><span>11</span>Administration</a>
                <a href="#troubleshooting" data-manual-toc><span>12</span>Troubleshooting</a>
            </nav>
        </aside>

        <section class="manual-content" data-manual-content>
            <section class="manual-chapter" id="orientation" data-manual-section data-keywords="start navigation sidebar mobile theme light dark action icons archive delete keyboard getting around">
                <header class="manual-chapter-heading">
                    <span>Chapter 01</span>
                    <h2>Getting Oriented</h2>
                    <p><?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> keeps event planning, people, communication history, and next actions connected instead of scattering them across separate systems.</p>
                </header>

                <article class="manual-callout manual-callout-accent">
                    <span class="manual-callout-icon" aria-hidden="true">◇</span>
                    <div class="manual-callout-body">
                        <h3>The Record Model at a Glance</h3>
                        <p>An <strong>Organization</strong> contains its <strong>Contacts</strong>. An <strong>Engagement</strong> belongs to one organization and can assign those contacts to event-specific roles. Any of those records can carry <strong>Chron Log</strong> history and <strong>Follow-Up Work</strong>.</p>
                    </div>
                </article>

                <section class="manual-card-grid manual-card-grid-three">
                    <article class="manual-card">
                        <span class="manual-card-number">1</span>
                        <h3>Use the Sidebar</h3>
                        <p>The main areas are always in the left sidebar. On a narrow screen, use the menu button in the top bar and tap outside the panel or press <kbd>Esc</kbd> to close it.</p>
                    </article>
                    <article class="manual-card">
                        <span class="manual-card-number">2</span>
                        <h3>Switch the Theme</h3>
                        <p>Select <strong>Dark Theme</strong> or <strong>Light Theme</strong> near the bottom of the sidebar. <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> remembers the choice in this browser.</p>
                    </article>
                    <article class="manual-card">
                        <span class="manual-card-number">3</span>
                        <h3>Open Your Account</h3>
                        <p>Select your name and picture to edit your profile. Calendar, account security, this manual, and sign-out controls are grouped in the lower sidebar.</p>
                    </article>
                </section>

                <section class="manual-subsection">
                    <h3>A Practical First Session</h3>
                    <ol class="manual-steps">
                        <li><span>01</span><section><strong>Complete your profile.</strong><p>Add your name, verified email, phone, and picture in <a href="profile.php">My Profile</a>.</p></section></li>
                        <li><span>02</span><section><strong>Protect the account.</strong><p>Enroll an authenticator and store the one-time recovery codes from <a href="two_factor_settings.php">Account Security</a>.</p></section></li>
                        <li><span>03</span><section><strong>Check the daily view.</strong><p>Use the <a href="dashboard.php">Dashboard</a> to scan upcoming engagements, assigned work, and missing details.</p></section></li>
                        <li><span>04</span><section><strong>Find the relationship.</strong><p>Open the organization and relevant contacts before an event to review relationship details, financial history, Chron, and open work.</p></section></li>
                        <li><span>05</span><section><strong>Leave a clear next action.</strong><p>When a conversation creates a commitment, record the communication in Chron and create an owned, dated task.</p></section></li>
                    </ol>
                </section>

                <section class="manual-subsection">
                    <h3>Common Action Icons</h3>
                    <section class="manual-icon-legend">
                        <span><i class="manual-action-icon action-view" aria-hidden="true">◉</i><strong>View</strong><small>Open details</small></span>
                        <span><i class="manual-action-icon action-edit" aria-hidden="true">✎</i><strong>Edit</strong><small>Change a record</small></span>
                        <span><i class="manual-action-icon action-start" aria-hidden="true">▶</i><strong>Start</strong><small>Begin a task</small></span>
                        <span><i class="manual-action-icon action-complete" aria-hidden="true">✓</i><strong>Complete</strong><small>Finish a task</small></span>
                        <span><i class="manual-action-icon action-archive" aria-hidden="true">□</i><strong>Archive</strong><small>Hide reversibly</small></span>
                        <span><i class="manual-action-icon action-restore" aria-hidden="true">↺</i><strong>Restore</strong><small>Return to active</small></span>
                        <span><i class="manual-action-icon action-delete" aria-hidden="true">×</i><strong>Delete</strong><small>Remove permanently</small></span>
                    </section>
                    <p class="manual-note"><strong>Archive first.</strong> Archiving is reversible and keeps history. Permanent deletion is limited to administrators, requires freshly confirmed administrator access, and cannot be undone.</p>
                </section>
            </section>

            <section class="manual-chapter" id="roles" data-manual-section data-keywords="roles reviewer editor administrator admin permissions access read only manage create edit archive restore delete elevated email correspondence">
                <header class="manual-chapter-heading">
                    <span>Chapter 02</span>
                    <h2>Roles and Access</h2>
                    <p>Everyone can read the shared operational picture. Editing and destructive actions are deliberately narrower.</p>
                </header>

                <article class="manual-current-role">
                    <span>Signed In As</span>
                    <strong><?php echo htmlspecialchars($manual_role_label, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p><?php echo htmlspecialchars($manual_access_summary, ENT_QUOTES, 'UTF-8'); ?></p>
                </article>

                <section class="manual-table-wrap" tabindex="0" aria-label="Role permissions table">
                    <table class="manual-table manual-role-table">
                        <thead><tr><th>Capability</th><th>Reviewer</th><th>Editor</th><th>Administrator</th></tr></thead>
                        <tbody>
                            <tr><td>View records, Chron, work, map, and exports</td><td><span class="manual-yes">Yes</span></td><td><span class="manual-yes">Yes</span></td><td><span class="manual-yes">Yes</span></td></tr>
                            <tr><td>Manage own profile, calendar links, and security</td><td><span class="manual-yes">Yes</span></td><td><span class="manual-yes">Yes</span></td><td><span class="manual-yes">Yes</span></td></tr>
                            <tr><td>Create and edit engagements, organizations, and contacts</td><td><span class="manual-no">No</span></td><td><span class="manual-yes">Yes</span></td><td><span class="manual-yes">Yes</span></td></tr>
                            <tr><td>Manage tasks, Chron entries, closeouts, and engagement email</td><td><span class="manual-no">No</span></td><td><span class="manual-yes">Yes</span></td><td><span class="manual-yes">Yes</span></td></tr>
                            <tr><td>Archive and restore shared records</td><td><span class="manual-no">No</span></td><td><span class="manual-yes">Yes</span></td><td><span class="manual-yes">Yes</span></td></tr>
                            <tr><td>Manage users, audit history, backups, and permanent deletion</td><td><span class="manual-no">No</span></td><td><span class="manual-no">No</span></td><td><span class="manual-yes">Yes</span></td></tr>
                        </tbody>
                    </table>
                </section>

                <section class="manual-card-grid manual-card-grid-three">
                    <article class="manual-card<?php echo $manual_role === 'reviewer' ? ' is-current-role' : ''; ?>">
                        <span class="manual-role-badge role-reviewer">Reviewer</span>
                        <h3>Read and Export</h3>
                        <p>Reviewers can inspect all shared operational records, follow links between them, use search and filters, download engagement PDFs, copy engagement summaries, and subscribe to the calendar.</p>
                    </article>
                    <article class="manual-card<?php echo $manual_role === 'editor' ? ' is-current-role' : ''; ?>">
                        <span class="manual-role-badge role-editor">Editor</span>
                        <h3>Coordinate and Maintain</h3>
                        <p>Editors add reviewer capabilities plus record creation, editing, archive/restore, task management, Chron maintenance, financial closeout, engagement email, and inbound email review.</p>
                    </article>
                    <article class="manual-card<?php echo $manual_role === 'admin' ? ' is-current-role' : ''; ?>">
                        <span class="manual-role-badge role-admin">Administrator</span>
                        <h3>Govern and Recover</h3>
                        <p>Administrators add user lifecycle controls, audit inspection, encrypted backups, permanent deletion, and access to deployment signals. Sensitive actions require fresh password and 2FA confirmation.</p>
                    </article>
                </section>
            </section>

            <section class="manual-chapter" id="dashboard" data-manual-section data-keywords="dashboard today greeting summary cards upcoming engagements my work overdue needs attention readiness financial closeouts mail review">
                <header class="manual-chapter-heading">
                    <span>Chapter 03</span>
                    <h2>Daily Dashboard</h2>
                    <p>The Dashboard answers three questions: what is coming, what is yours, and what is incomplete.</p>
                </header>

                <section class="manual-card-grid manual-card-grid-two">
                    <article class="manual-card">
                        <span class="manual-kicker">Scan</span>
                        <h3>Daily Summary</h3>
                        <p>The top cards link to engagements in the next <?php echo $manual_dashboard_days; ?> days, your active and overdue work, open financial closeouts, and—when available to your role—inbound mail awaiting review.</p>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Act</span>
                        <h3>Primary Panels</h3>
                        <p><strong>Upcoming Engagements</strong> shows the next active events and any missing details. <strong>My Work</strong> orders assigned tasks by urgency and due date.</p>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Prepare</span>
                        <h3>Event Readiness</h3>
                        <p>Readiness flags an unconfirmed event, missing venue address, missing presentation, or missing event contact assignment. Open the event to complete the missing details.</p>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Close</span>
                        <h3>Financial Closeouts</h3>
                        <p>Ended events remain here until actual giving/income, lodging, and travel receipts are finalized. The age indicator shows how many days the report has been waiting.</p>
                    </article>
                </section>
                <p class="manual-open-area"><a href="dashboard.php">Open the Dashboard <span aria-hidden="true">→</span></a></p>
            </section>

            <section class="manual-chapter" id="engagements" data-manual-section data-keywords="engagement event search quote terms lifecycle active postponed canceled completed confirmation work in progress under review confirmed caller initial task owner creator schedule presentations speaker attendance contacts primary host travel materials logistics compensation closeout PDF slide deck upload QR code speaker notes website donations copy clipboard marker markdown archive restore save changes outbound correspondence send email template booking confirmation travel lodging reconfirmation thank you delivery retry event brief">
                <header class="manual-chapter-heading">
                    <span>Chapter 04</span>
                    <h2>Engagements</h2>
                    <p>An engagement is the operational record for one event: the schedule, assigned people, presentations, logistics, planning state, communication history, work, and final receipts.</p>
                </header>

                <section class="manual-subsection">
                    <h3>Find and Read an Engagement</h3>
                    <section class="manual-card-grid manual-card-grid-two">
                        <article class="manual-card">
                            <h4>Search Broadly</h4>
                            <p>The Engagements search checks event titles and descriptions, organizations, contacts, caller names, active Chron text, and Chron authors. Unquoted words are alternatives; words inside one quoted group must all match. Terms shorter than three characters are ignored.</p>
                            <p class="manual-example"><span>Example</span><code>conference "travel confirmed"</code></p>
                        </article>
                        <article class="manual-card">
                            <h4>Filter Precisely</h4>
                            <p>Switch between current and archived records, filter by lifecycle, then sort by organization, date, confirmation, or lifecycle. The summary cards show current lifecycle totals.</p>
                        </article>
                    </section>
                    <p>The table shows event dates, lifecycle, confirmation, and whether financial closeout is open, closed, or not applicable. Select the title or View icon for the full record.</p>
                </section>

                <section class="manual-subsection">
                    <h3>Create or Edit an Engagement</h3>
                    <ol class="manual-steps">
                        <li><span>01</span><section><strong>Choose the organization.</strong><p>The event must belong to one active organization. If it is new, create the organization first.</p></section></li>
                        <li><span>02</span><section><strong>Name and schedule the event.</strong><p>Enter a required event title, an optional description, a valid start and end date, and an event type. Use Other when the preset types do not fit.</p></section></li>
                        <li><span>03</span><section><strong>Assign event contacts.</strong><p>Select active contacts from the chosen organization and give each any applicable event roles: Primary host, On-site contact, Billing, Travel, or Materials.</p></section></li>
                        <li><span>04</span><section><strong>Add presentations.</strong><p>Every presentation needs a topic/title, a date within the event range, a time, and a duration in minutes. Speaker and expected attendance are optional; record actual attendance after the presentation, including zero when applicable. You can also attach a PDF slide deck and speaker QR codes. At least one presentation is required before the engagement can be Confirmed.</p></section></li>
                        <li><span>05</span><section><strong>Capture logistics.</strong><p>Record book-table and brochure permissions, travel coverage, planned compensation, travel/lodging estimates, lodging type, and the physical event location.</p></section></li>
                        <li><span>06</span><section><strong>Set planning states and ownership.</strong><p>Choose lifecycle and confirmation, then select the Caller when that person should own the initial standard checklist. If no Caller is selected, the checklist belongs to the user creating the engagement. Add an initial Chron entry when there is context worth preserving.</p></section></li>
                    </ol>
                    <?php if ($manual_can_manage): ?><p class="manual-open-area"><a href="index.php">Create a New Engagement <span aria-hidden="true">→</span></a></p><?php endif; ?>
                </section>

                <section class="manual-split">
                    <article class="manual-definition-panel">
                        <span class="manual-kicker">Operational State</span>
                        <h3>Lifecycle</h3>
                        <dl>
                            <div><dt>Active</dt><dd>The event is moving forward.</dd></div>
                            <div><dt>Postponed</dt><dd>The original is paused and may link to its replacement.</dd></div>
                            <div><dt>Canceled</dt><dd>A reason is required; open event tasks are canceled automatically.</dd></div>
                            <div><dt>Completed</dt><dd>The event has finished. Finalizing its financial report also sets this state.</dd></div>
                        </dl>
                    </article>
                    <article class="manual-definition-panel">
                        <span class="manual-kicker">Planning Confidence</span>
                        <h3>Confirmation</h3>
                        <dl>
                            <div><dt>Work in Progress</dt><dd>Details are still being assembled.</dd></div>
                            <div><dt>Under Review</dt><dd>The plan is ready for checking or decision.</dd></div>
                            <div><dt>Confirmed</dt><dd>The commitment is agreed and at least one complete presentation exists.</dd></div>
                        </dl>
                    </article>
                </section>
                <p class="manual-note"><strong>Lifecycle and confirmation are independent.</strong> A postponed event can retain its former confirmation state, while a completed event may reflect the confirmation state it reached during planning.</p>

                <section class="manual-subsection">
                    <h3>Presentation Files, QR Codes, and History</h3>
                    <section class="manual-card-grid manual-card-grid-two">
                        <article class="manual-card">
                            <h4>PDF Slide Deck</h4>
                            <p>Choose a PDF up to 100 MB for a presentation. After saving, select the PDF link to view the deck in a separate browser tab. From the edit page, you can replace the file or select <strong>Remove current PDF</strong>, then save the engagement.</p>
                        </article>
                        <article class="manual-card">
                            <h4>Speaker QR Codes</h4>
                            <p>Add separate QR images for the speaker’s notes download, website, and donation page. Choose or paste a JPEG, PNG, or WebP image up to 5 MB. Select a displayed QR code to copy the image to the clipboard for use in another application.</p>
                        </article>
                    </section>
                    <p>To paste a QR image, copy it in the source application and select <strong>Paste QR code</strong>. When direct clipboard reading is unavailable, leave that button focused and press <kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>V</kbd>. Save the engagement to store new, replaced, or removed assets.</p>
                    <p>Each saved presentation has a <strong>Save Changes</strong> button in its lower-left corner, so you can save without scrolling to the page-level button. Saved presentations can also be archived independently from the engagement. Restore archived presentations from the edit or view page; if an archived presentation no longer falls within the event dates, enter a valid date and time during restoration. Administrators can permanently delete presentations after fresh elevation.</p>
                    <p>When postponing or canceling an event, link it to a replacement from the same organization. <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> displays both “rescheduled as” and “rescheduled from” references and prevents circular links.</p>
                </section>

                <section class="manual-subsection">
                    <h3>Send and Track Event Email</h3>
                    <p>Editors and administrators can send email from an active engagement. Use <strong>Send email</strong> near the page heading or <strong>Send message</strong> in the Correspondence section. Every message remains easy to find there afterward.</p>
                    <ol class="manual-steps">
                        <li><span>01</span><section><strong>Choose a starting template.</strong><p>Use Booking confirmation, Travel and lodging request, Final-detail reconfirmation, Presentation schedule, Post-event thank-you, or Custom message. Template text is only a starting point; edit the subject and body before sending.</p></section></li>
                        <li><span>02</span><section><strong>Select event contacts.</strong><p>Use the Primary host, On-site contact, Billing, Travel, or Materials shortcuts, or select individual assigned contacts. Only active assigned contacts with an email address can receive the message. Every unique address receives a separate email, so recipients never see one another.</p></section></li>
                        <li><span>03</span><section><strong>Review the routing marker.</strong><p>The exact engagement marker is included in every built-in subject and is added automatically if removed. When inbound mail is enabled, replies return through the shared mailbox and the marker helps place them in the right engagement Chron.</p></section></li>
                        <li><span>04</span><section><strong>Optionally append the event brief.</strong><p>The share-safe brief includes the public event schedule, venue, description, and presentations. It deliberately excludes Chron, internal notes, compensation, giving, and financial-closeout information. Preview it before queuing.</p></section></li>
                        <li><span>05</span><section><strong>Send and check the result.</strong><p>Select <strong>Queue Email</strong>, then use the Correspondence list to see whether each recipient is waiting, sent, or failed. If a recipient remains failed, open the message and select <strong>Retry failed deliveries</strong>.</p></section></li>
                    </ol>
                    <section class="manual-card-grid manual-card-grid-two">
                        <article class="manual-card"><h4>One Place to Check</h4><p>Open the outbound message to review what was sent, who it was sent to, and the result for each address.</p></article>
                        <article class="manual-card"><h4>Automatic Chron History</h4><p>The message is added to the Engagement, Organization, and selected Contact Chron logs. Select <strong>View outbound message</strong> from a Chron entry to return to its delivery record.</p></article>
                    </section>
                </section>

                <section class="manual-subsection">
                    <h3>View, Share, and Close the Event</h3>
                    <p>The event detail page shows its unique email routing marker. The built-in outbound composer inserts it automatically. When using another mail application, select the copy icon immediately beside the marker and keep it in the email subject or plain-text body when that message should route to the engagement’s Chron log.</p>
                    <section class="manual-card-grid manual-card-grid-three">
                        <article class="manual-card"><h4>Copy Text</h4><p>Copies a plain-text event brief for messages or notes.</p></article>
                        <article class="manual-card"><h4>Copy MD</h4><p>Copies a Markdown-formatted brief for systems that support structured text.</p></article>
                        <article class="manual-card"><h4>Download PDF</h4><p>Downloads a formatted event summary for sharing or offline reference.</p></article>
                    </section>
                    <p>After the event, editors and administrators can open <strong>Financial Closeout</strong> and enter the actual giving/income, lodging, and travel received. Enter zero where nothing was received. The original planning estimates remain unchanged. Confirm that the amounts are final to complete the engagement; later corrections retain the original close date and record the update.</p>
                </section>

                <article class="manual-callout manual-callout-warning">
                    <span class="manual-callout-icon" aria-hidden="true">!</span>
                    <div class="manual-callout-body"><h3>Archive and Delete Carefully</h3><p>Archive removes the engagement from current views but keeps its history available for restoration. Permanent deletion removes the event and all event-specific information, including presentations, Chron, tasks, contact role assignments, and its financial report. Prefer archive unless removal is explicitly required.</p></div>
                </article>
                <p class="manual-open-area"><a href="engagements.php">Open Engagements <span aria-hidden="true">→</span></a></p>
            </section>

            <section class="manual-chapter" id="organizations-contacts" data-manual-section data-keywords="organization contact optional address country international flag state province Canada United States affiliation distinctives website phone fax email financial history giving photo role pastor admin other describe notes search archive dependencies move active">
                <header class="manual-chapter-heading">
                    <span>Chapter 05</span>
                    <h2>Organizations and Contacts</h2>
                    <p>Organization records hold the durable relationship; contacts identify the people within it. Engagements reuse both rather than duplicating them.</p>
                </header>

                <section class="manual-split">
                    <article class="manual-definition-panel">
                        <span class="manual-kicker">Organization</span>
                        <h3>Relationship Record</h3>
                        <p>Store the organization name, notes, affiliation, distinctives, website, phone, fax, and physical and mailing addresses. You can use one address for both or maintain them separately.</p>
                        <p>A new organization can create its first contact at the same time, and can add more contacts before saving.</p>
                        <?php if ($manual_can_manage): ?><a href="add_organization.php" class="manual-inline-link">Add an organization</a><?php endif; ?>
                    </article>
                    <article class="manual-definition-panel">
                        <span class="manual-kicker">Contact</span>
                        <h3>Person Record</h3>
                        <p>Store first and last name, optional organization, organization role, email entered twice to prevent mistyping, phone, incidental notes, and an optional JPEG, PNG, or WebP photo up to 5 MB. A contact can be saved without an organization.</p>
                        <p>The organization role is Pastor, Admin, or Other. When you choose Other, enter the custom description in <strong>Describe Other Role</strong> beside the Role field. Event-specific roles are assigned separately on each engagement.</p>
                        <?php if ($manual_can_manage): ?><a href="add_contact.php" class="manual-inline-link">Add a contact</a><?php endif; ?>
                    </article>
                </section>

                <section class="manual-subsection">
                    <h3>Enter Organization Addresses</h3>
                    <ul class="manual-check-list">
                        <li>Choose the country before the region. The Country picker includes countries worldwide and defaults to <strong>United States of America</strong> for a new address.</li>
                        <li>For a United States address, the region field is labeled <strong>State</strong> and provides a dropdown of state names.</li>
                        <li>For a Canadian address, the region field is labeled <strong>Province</strong> and provides a dropdown of province and territory names.</li>
                        <li>For every other country, the field is labeled <strong>State/Province</strong> and accepts typed regional information.</li>
                        <li>The same behavior applies to both physical and mailing addresses. Select <strong>Same as Physical Address</strong> when the mailing address does not differ.</li>
                    </ul>
                </section>

                <section class="manual-subsection">
                    <h3>Lists, Details, and Financial History</h3>
                    <ul class="manual-check-list">
                        <li>Search organizations by their details or active contacts; sort by name and switch between Active and Archived.</li>
                        <li>The organization list shows location, active contacts, last giving, and lifetime giving from finalized reports.</li>
                        <li>An organization detail page summarizes lifetime giving, last and average event giving, lodging, travel, and recent finalized reports.</li>
                        <li>Search contacts by person or organization, sort by last name or organization, and choose 20, 50, or 100 rows per page.</li>
                        <li>A contact detail page links back to its organization and shows that person’s Chron and open follow-up work.</li>
                    </ul>
                </section>

                <article class="manual-callout manual-callout-neutral">
                    <span class="manual-callout-icon" aria-hidden="true">i</span>
                    <div class="manual-callout-body"><h3>Organization Archive Rule</h3><p>An organization cannot be archived while it still has active contacts or engagements. Archive those child records first, or move them to another active organization. Restoring a contact or engagement also requires its organization to be active.</p></div>
                </article>

                <section class="manual-link-row">
                    <a href="organizations.php"><strong>Organizations</strong><span>Search and review relationship records →</span></a>
                    <a href="contacts.php"><strong>Contacts</strong><span>Find people and communication history →</span></a>
                </section>
            </section>

            <section class="manual-chapter" id="work-queue" data-manual-section data-keywords="work queue task follow up owner assigned caller creator engagement creation due overdue today next seven days waiting unassigned completed canceled priority low normal high urgent standard checklist digest reminder assign to me start complete reopen duplicate copy another event destination">
                <header class="manual-chapter-heading">
                    <span>Chapter 06</span>
                    <h2>Work Queue</h2>
                    <p>Tasks turn relationship context into an explicit next action with an owner, timing, priority, and state.</p>
                </header>

                <section class="manual-card-grid manual-card-grid-two">
                    <article class="manual-card">
                        <h3>My Reminders</h3>
                        <p>The reminder strip counts your overdue, due-today, next <?php echo $manual_task_days; ?> days, and waiting tasks. Editors and administrators also see financial closeouts. The sidebar badge is the combined reminder count.</p>
                    </article>
                    <article class="manual-card">
                        <h3>Queue Views</h3>
                        <p>Use My work, Overdue, Due today, Next <?php echo $manual_task_days; ?> days, Waiting, Unassigned, Completed, or All active. Search matches task content, related records, and assignees.</p>
                    </article>
                </section>

                <section class="manual-subsection">
                    <h3>Create a Useful Task</h3>
                    <ol class="manual-steps manual-steps-compact">
                        <li><span>01</span><section><strong>Write a concrete title.</strong><p>Use notes for supporting detail, not as a substitute for the next action.</p></section></li>
                        <li><span>02</span><section><strong>Link the right record.</strong><p>Search for an engagement, organization, or contact; choose <?php echo htmlspecialchars(applicationGeneralWorkLabel(), ENT_QUOTES, 'UTF-8'); ?> when no record applies.</p></section></li>
                        <li><span>03</span><section><strong>Name an owner and due date.</strong><p>Tasks may be unassigned or have no date, but explicit ownership keeps them out of limbo.</p></section></li>
                        <li><span>04</span><section><strong>Set priority and status.</strong><p>Choose Low, Normal, High, or Urgent and one of the workflow states below.</p></section></li>
                    </ol>
                </section>

                <section class="manual-table-wrap" tabindex="0" aria-label="Task status reference">
                    <table class="manual-table">
                        <thead><tr><th>Status</th><th>Use It When</th><th>What Happens Next</th></tr></thead>
                        <tbody>
                            <tr><td><span class="manual-status-pill status-open">Open</span></td><td>The task is known but not started.</td><td>Start it, complete it, or edit the owner/timing.</td></tr>
                            <tr><td><span class="manual-status-pill status-progress">In Progress</span></td><td>Someone is actively working it.</td><td>Complete it or move it to Waiting if blocked.</td></tr>
                            <tr><td><span class="manual-status-pill status-waiting">Waiting</span></td><td>Progress depends on a person, organization, or decision.</td><td>The “Waiting on” description becomes required.</td></tr>
                            <tr><td><span class="manual-status-pill status-completed">Completed</span></td><td>The action is finished.</td><td>It moves to Completed and can be reopened.</td></tr>
                            <tr><td><span class="manual-status-pill status-canceled">Canceled</span></td><td>The action is no longer needed.</td><td>It leaves active views and can be reopened.</td></tr>
                        </tbody>
                    </table>
                </section>

                <section class="manual-subsection">
                    <h3>Duplicate a Task to Another Event</h3>
                    <ol class="manual-steps manual-steps-compact">
                        <li><span>01</span><section><strong>Open the task for editing.</strong><p>Select <strong>Duplicate to another event</strong> at the bottom of the Edit Task page.</p></section></li>
                        <li><span>02</span><section><strong>Choose the destination event.</strong><p>Enter at least three characters in <strong>Search events</strong>, then select a different event from the results. The source event cannot be selected as its own destination.</p></section></li>
                        <li><span>03</span><section><strong>Review the copied work.</strong><p>The title, notes, assignee, due date, and priority are copied so you can adjust them before saving. The duplicate starts <strong>Open</strong>, and any “Waiting on” text is cleared.</p></section></li>
                        <li><span>04</span><section><strong>Create the duplicate.</strong><p>Select <strong>Duplicate task</strong> to add a new task to the destination event. The original task remains unchanged.</p></section></li>
                    </ol>
                </section>

                <section class="manual-subsection">
                    <h3>Fast Actions and Record-Level Work</h3>
                    <p>From the queue, editors and administrators can assign an unowned task to themselves, start, complete, reopen, edit, or—administrators only—delete it. Engagement, organization, and contact detail pages show their open tasks in <strong>Follow-Up Work</strong>, where you can add a task or open a filtered queue.</p>
                </section>

                <section class="manual-subsection">
                    <h3>Standard Event Tasks</h3>
                    <p>Standard tasks are reusable definitions copied when a new active engagement is first saved. Each has content, priority, display order, and a due date offset from event start or end.</p>
                    <p>If the new engagement has a selected <strong>Caller</strong>, every initial standard task is assigned to that Caller. If no Caller is selected, the tasks are assigned to the user creating the engagement. This ownership decision happens only once: changing the engagement’s Caller later does not reassign its initial tasks, which remain independently editable.</p>
                    <p>Changing an engagement’s dates reschedules its active generated tasks from the standard schedule. A task whose due date was manually overridden keeps that date. Editing a standard definition does not rewrite an existing task’s title, notes, priority, or current due date.</p>
                    <p>Editors and administrators manage reusable definitions from <strong>Standard Event Tasks</strong>. Use <strong>Add missing checklist tasks</strong> on an active engagement to generate any standard items that are absent; tasks added this way are assigned to the user performing the action. The built-in financial closeout reminder is fixed at one week after event end and cannot be edited, archived, or deleted.</p>
                    <p>Sending a booking, reconfirmation, or thank-you message from an engagement does not complete its related checklist task automatically. Check the Correspondence result, then complete the task when the follow-up itself is finished.</p>
                    <?php if ($manual_can_manage): ?><p><a href="standard_tasks.php" class="manual-inline-link">Manage Standard Event Tasks</a></p><?php endif; ?>
                </section>
                <p class="manual-open-area"><a href="tasks.php">Open the Work Queue <span aria-hidden="true">→</span></a></p>
            </section>

            <section class="manual-chapter" id="chron-mail" data-manual-section data-keywords="chron log communication call email meeting history archive restore outbound correspondence send template recipient delivery pending processing retry sent failed partial event brief inbound mail mailbox marker <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> routing approve reject processed source email purge Bcc Cc sender review attachment">
                <header class="manual-chapter-heading">
                    <span>Chapter 07</span>
                    <h2>Chron and Email</h2>
                    <p>Chron is the durable communication history. Add concise human notes directly, send tracked engagement correspondence, or route copied inbound email into the relevant records.</p>
                </header>

                <section class="manual-subsection">
                    <h3>Use the Chron Log</h3>
                    <ul class="manual-check-list">
                        <li>Contact Chron records communication with that person only; Organization Chron records organization-level history; Engagement Chron records event-specific planning.</li>
                        <li>Entries show created time and author, newest first. Edited entries also show the last update time and editor.</li>
                        <li>Editors and administrators add, edit, and archive entries from the record’s edit page. Administrators can permanently delete them.</li>
                        <li>Restore archived entries in batches from the Restore page. An archived parent record must be active before its Chron can be restored.</li>
                        <li>Email-generated entries link to their retained inbound or outbound source message while it exists.</li>
                    </ul>
                </section>

                <section class="manual-card-grid manual-card-grid-two">
                    <article class="manual-card">
                        <span class="manual-kicker">Send</span>
                        <h3>Start from an Engagement</h3>
                        <p>Open an active engagement and select <strong>Send message</strong>. Choose assigned contacts, review the message, and send. The engagement marker and related Chron history are handled for you. Check the Correspondence section for the result.</p>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Receive</span>
                        <h3>Route Outside Email</h3>
                        <p>When using another mail application, copy the inbound address and keep the exact engagement marker in the subject or plain-text body: <code><?php echo htmlspecialchars($manual_marker_example, ENT_QUOTES, 'UTF-8'); ?></code>. Use the copy icon beside the marker on the engagement page.</p>
                    </article>
                </section>

                <section class="manual-subsection">
                    <h3>How Inbound Email Finds Its Records</h3>
                    <p>One unique, valid signed engagement marker routes directly to that active engagement only when the sender also uniquely matches an active record. Unknown senders and unsigned legacy markers require review. When participant matching is uncertain, only the signed engagement receives the Chron entry.</p>
                </section>

                <section class="manual-card-grid manual-card-grid-two">
                    <article class="manual-card">
                        <h3>Address Matching</h3>
                        <p><?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> matches exact email addresses for active verified users, contacts, and organizations. A unique contact participant routes to the Contact and its Organization. A direct organization address routes there. <strong>Cc</strong> and <strong>Bcc</strong> delivery both work.</p>
                    </article>
                    <article class="manual-card">
                        <h3>What Inbound Mail Retains</h3>
                        <p>The Chron entry includes normalized headers, subject, timestamps, inert plain-text body, attachment names, and a source link. Attachment contents are not stored. Duplicate delivery of the same message ID is ignored.</p>
                    </article>
                </section>

                <section class="manual-subsection">
                    <h3>Review the Inbound Queue</h3>
                    <p>Editors and administrators see messages grouped as Needs review, Pending, Processing, Failed, Processed, or Rejected. Without a valid signed marker, every message requires review. Unknown or ambiguous senders, shared addresses, messages with no unique target, and invalid, conflicting, unknown, or archived engagement markers also require review.</p>
                    <ol class="manual-steps manual-steps-compact">
                        <li><span>01</span><section><strong>Inspect the source.</strong><p>Read the From, To, Cc, dates, attachment names, plain-text body, sender classification, suggested routes, and review reasons.</p></section></li>
                        <li><span>02</span><section><strong>Correct the targets.</strong><p>Select suggested Contact and Organization routes. Search any active engagement by marker, ID, title, or organization.</p></section></li>
                        <li><span>03</span><section><strong>Choose an outcome.</strong><p><strong>Approve selected routes</strong> writes Chron; <strong>Retry automatic routing</strong> checks again after record corrections; <strong>Reject</strong> preserves the source without changing Chron.</p></section></li>
                    </ol>
                    <?php if ($manual_can_manage): ?><p class="manual-open-area"><a href="inbound_mail.php">Open Inbound Mail <span aria-hidden="true">→</span></a></p><?php endif; ?>
                </section>

                <article class="manual-callout manual-callout-warning">
                    <span class="manual-callout-icon" aria-hidden="true">!</span>
                    <div class="manual-callout-body"><h3>Treat Address Matching as a Routing Aid</h3><p>An exact visible From address is not independent proof of identity. Respect the mailbox provider’s spam and SPF/DKIM/DMARC signals. Leave suspicious messages for review. Administrator purge removes the retained <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> mail card and source links, but preserves Chron entries and never deletes the original IMAP message.</p></div>
                </article>
            </section>

            <section class="manual-chapter" id="map-calendar" data-manual-section data-keywords="map location geocode pins lifecycle confirmation filters dates fit visible OpenStreetMap calendar month previous next today selector Events My Tasks All Tasks Everything color due date subscription private link webcal device revoke purge presentations one hour schedule privacy">
                <header class="manual-chapter-heading">
                    <span>Chapter 08</span>
                    <h2>Map and Calendar</h2>
                    <p>Use the map for geographic planning, the graphical month calendar for events and due work, and private subscription links for an external calendar application.</p>
                </header>

                <section class="manual-card-grid manual-card-grid-three">
                    <article class="manual-card">
                        <span class="manual-kicker">Explore</span>
                        <h3>Engagement Map</h3>
                        <p>The initial view shows active engagements in a bounded date window. Filter by lifecycle, confirmation, and date. Pin colors show confirmation; select a pin for the event, organization, dates, lifecycle, and a link to details.</p>
                        <p>Use <strong>Fit visible pins</strong> after filtering. A new or changed location may take time to appear; if it is missing, verify the address and current filters, then revisit the map.</p>
                        <a href="map.php" class="manual-inline-link">Open the map</a>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">View</span>
                        <h3>Monthly Calendar</h3>
                        <p>The Calendar page opens to the current month. Use <strong>Previous</strong> and <strong>Next</strong> to move one month at a time, or <strong>Today</strong> to return to the current month.</p>
                        <p>The current day has its own rose-colored cell and <strong>Today</strong> badge, distinct from every event and task color.</p>
                        <p>Select an event to open its details. Tasks with due dates link to task work; editors and administrators return to the same calendar view after editing.</p>
                        <a href="calendar_subscription.php#event-calendar" class="manual-inline-link">Open the month view</a>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Subscribe</span>
                        <h3>Private Calendar</h3>
                        <p>Create a separate subscription for each device or service. The secret URL is shown only once; copy it or open it directly in a calendar app. The feed contains all-day engagement blocks plus timed presentation entries using each presentation’s recorded duration.</p>
                        <p>Revoke one link without affecting the others. Revoked token records can be purged. Never share a subscription URL: it grants access to schedule data, though not contacts, Chron, travel, lodging, or compensation.</p>
                        <a href="calendar_subscription.php" class="manual-inline-link">Manage calendar links</a>
                    </article>
                </section>

                <section class="manual-subsection">
                    <h3>Choose What the Month Calendar Shows</h3>
                    <section class="manual-table-wrap" tabindex="0" aria-label="Calendar display selector reference">
                        <table class="manual-table">
                            <thead><tr><th>Selector</th><th>Calendar Content</th><th>Color Coding</th></tr></thead>
                            <tbody>
                                <tr><td><strong>Events</strong></td><td>Engagements that overlap the displayed dates.</td><td>Event color, with distinct border tones for tentative/postponed, canceled, and completed events.</td></tr>
                                <tr><td><strong>My Tasks</strong></td><td>Your active tasks that have a due date in the displayed calendar grid.</td><td>Your task accent color.</td></tr>
                                <tr><td><strong>All Tasks</strong></td><td>Every active due-dated task in the grid, including unassigned work.</td><td>Your tasks use the accent color; other or unassigned tasks use a contrasting color.</td></tr>
                                <tr><td><strong>Everything</strong></td><td>Events plus all active due-dated tasks.</td><td>All event and task colors appear together.</td></tr>
                            </tbody>
                        </table>
                    </section>
                    <p>The selected filter remains active as you move between months. The summary above the grid reports how many matching events and tasks fall in that month.</p>
                </section>
            </section>

            <section class="manual-chapter" id="profile-security" data-manual-section data-keywords="profile picture name email verified password recovery notification digest delivery time weekdays weekends phone security two factor 2FA authenticator QR setup key recovery codes change password disable login invitation reset theme">
                <header class="manual-chapter-heading">
                    <span>Chapter 09</span>
                    <h2>Profile and Security</h2>
                    <p>Your profile makes ownership recognizable; the security controls protect your identity and provide safe recovery paths.</p>
                </header>

                <section class="manual-card-grid manual-card-grid-two">
                    <article class="manual-card">
                        <h3>Profile and Notifications</h3>
                        <p>Add your name, phone, email, and optional profile picture. Changing email clears its verified state and pauses the daily digest until the new address is verified.</p>
                        <p>A verified email enables password recovery and a work digest of overdue, due-today, upcoming, and waiting tasks. The digest defaults to enabled on weekdays at 7:00 a.m.; disable it or choose a different time and set of days at any time. Editor/admin digests also include financial closeouts.</p>
                        <a href="profile.php" class="manual-inline-link">Open My Profile</a>
                    </article>
                    <article class="manual-card">
                        <h3>Password Rules</h3>
                        <p>Passwords must contain at least 12 characters and no more than 72 UTF-8 bytes. Changing your password signs out every other session. If an administrator gives you a temporary password, you must replace it before using other areas.</p>
                        <a href="two_factor_settings.php" class="manual-inline-link">Open Account Security</a>
                    </article>
                </section>

                <section class="manual-subsection">
                    <h3>Set Up Two-Factor Authentication</h3>
                    <ol class="manual-steps">
                        <li><span>01</span><section><strong>Confirm your password.</strong><p>If replacing an existing authenticator, also enter a current code.</p></section></li>
                        <li><span>02</span><section><strong>Add the account.</strong><p>Scan the QR code in your authenticator app or enter the manual setup key.</p></section></li>
                        <li><span>03</span><section><strong>Verify enrollment.</strong><p>Enter the six-digit code generated by the app.</p></section></li>
                        <li><span>04</span><section><strong>Save recovery codes.</strong><p>Each code works once and the page will not show them again. Store them in an approved password manager or another secure location.</p></section></li>
                    </ol>
                    <p>At sign-in, enter either the current six-digit authenticator code or one unused recovery code. Generating new recovery codes invalidates all old ones. Administrators must keep 2FA enabled; other roles may disable it after password and current-code confirmation.</p>
                </section>

                <section class="manual-subsection">
                    <h3>Recover Access</h3>
                    <ul class="manual-check-list">
                        <li><strong>Forgotten password:</strong> choose “Forgot your password?” on sign-in and enter the verified email on the active account. The single-use link lets you set a new password and invalidates existing sessions.</li>
                        <li><strong>Lost authenticator:</strong> use one saved recovery code at the 2FA prompt, then replace the authenticator and generate a fresh set of codes.</li>
                        <li><strong>No recovery factor:</strong> contact an administrator. They can issue a temporary password or reset another user’s 2FA after elevated confirmation; they cannot reveal an old password or recovery code.</li>
                        <li><strong>New account:</strong> open the invitation link within seven days, create a private password, and complete required authenticator enrollment.</li>
                    </ul>
                </section>
            </section>

            <section class="manual-chapter" id="mattermost" data-manual-section data-keywords="mattermost moed plugin slash command connect link account code today tasks dashboard event search show link-event unlink-event channel engagement indicator chain card routing marker copy context menu create task save chron send email template recipient post thread preview delivery reply notification button assign start complete reopen permission reviewer editor administrator open in moed privacy">
                <header class="manual-chapter-heading">
                    <span>Chapter 10</span>
                    <h2>Mattermost</h2>
                    <p>The MOED plugin brings daily summaries, engagement context, follow-up actions, and carefully reviewed engagement email into Mattermost while MOED remains the system of record.</p>
                </header>

                <article class="manual-callout manual-callout-accent">
                    <span class="manual-callout-icon" aria-hidden="true">◇</span>
                    <div class="manual-callout-body">
                        <h3>Link Your Account First</h3>
                        <p>Open <a href="mattermost.php">Mattermost</a> in MOED and generate a one-time code. The code is shown once, expires after 10 minutes, and becomes invalid after use. In Mattermost, run <code>/moed connect CODE</code>. Command responses are private to you. Never paste the code into an ordinary channel message.</p>
                    </div>
                </article>

                <section class="manual-subsection">
                    <h3>Commands</h3>
                    <div class="responsive-table-wrapper">
                        <table>
                            <thead><tr><th>Command</th><th>What It Does</th><th>Who Can Use It</th></tr></thead>
                            <tbody>
                                <tr><td><code>/moed help</code></td><td>Shows the command reference and account-linking reminder.</td><td>Everyone</td></tr>
                                <tr><td><code>/moed status</code></td><td>Checks the plugin connection and reports which MOED account is linked.</td><td>Everyone</td></tr>
                                <tr><td><code>/moed connect CODE</code></td><td>Consumes a one-time code from the MOED Mattermost page and links your identities.</td><td>Everyone</td></tr>
                                <tr><td><code>/moed today</code></td><td>Shows a private dashboard with Overdue, Due today, Next 7 days, and Waiting counts, active assigned tasks, and a link back to MOED.</td><td>Linked users</td></tr>
                                <tr><td><code>/moed tasks</code></td><td>Shows the same task dashboard with up to 15 active tasks assigned to you. Editors and administrators also receive permitted action buttons.</td><td>Linked users</td></tr>
                                <tr><td><code>/moed event search TEXT</code></td><td>Searches active and historical non-archived engagements by event or organization name.</td><td>Linked users</td></tr>
                                <tr><td><code>/moed event show ID</code></td><td>Shows a private engagement card with dates, status, location, work counts, and an email routing marker you can copy.</td><td>Linked users</td></tr>
                                <tr><td><code>/moed link-event ID</code></td><td>Binds the current channel to an engagement, adds its routing marker to the channel name, and posts its card.</td><td>Editors and administrators with native channel-management and posting permission</td></tr>
                                <tr><td><code>/moed unlink-event</code></td><td>Removes the channel’s engagement binding and its channel-name marker.</td><td>Editors and administrators with native channel-management and posting permission</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="manual-card-grid manual-card-grid-two">
                    <article class="manual-card">
                        <span class="manual-kicker">Channel Context</span>
                        <h3>Engagement Cards</h3>
                        <p>Run <code>/moed event show ID</code> for a private engagement card. Linking a channel posts the same useful engagement context for the channel. Both cards show the email routing marker with a <strong>Copy</strong> control so it can be kept in a related email subject or message. Use <strong>Open in MOED</strong> for the complete record.</p>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Follow-Up</span>
                        <h3>Task Buttons</h3>
                        <p>Editors and administrators can assign an unowned task to themselves, start it, complete it, or reopen it. Each button checks the task version before changing MOED; if someone already changed the task, refresh with <code>/moed tasks</code>. Waiting details, reassignment to someone else, editing, cancellation, and deletion remain in MOED.</p>
                    </article>
                </section>

                <section class="manual-subsection">
                    <h3>Recognize the Engagement Linked to a Channel</h3>
                    <p>On desktop and mobile, a linked channel’s sidebar label begins with a compact marker such as <code>[MOED#17]</code>, leaving more room for the channel title. The full signed routing marker remains available in engagement cards and email tools wherever it is needed for routing. The colored chain control provides the same link signal; select it, or choose <strong>MOED engagement</strong> from the channel menu, to see the engagement, open it in MOED, copy its routing marker, or begin an email. Unlinking removes the marker from the channel name.</p>
                </section>

                <section class="manual-subsection">
                    <h3>Send Engagement Email From Mattermost</h3>
                    <ol class="manual-steps">
                        <li><span>01</span><section><strong>Start from the right engagement.</strong><p>In a linked channel, select the MOED chain icon and choose <strong>Send MOED email</strong>. To include a particular conversation, open that post's <strong>Message actions</strong> and choose <strong>Send via MOED email</strong>.</p></section></li>
                        <li><span>02</span><section><strong>Choose a starting point.</strong><p>Select a MOED message template, then choose from the contacts already assigned to the engagement. Each unique address receives a separate message.</p></section></li>
                        <li><span>03</span><section><strong>Decide what to include.</strong><p>Edit the subject and message. You may append the share-safe event brief and, when you started from a post, the selected post or a short thread excerpt.</p></section></li>
                        <li><span>04</span><section><strong>Review before sending.</strong><p>Check the recipients, routing marker, subject, message, and any added context on the review screen. Nothing is sent until you choose <strong>Send and add to Chron</strong>.</p></section></li>
                        <li><span>05</span><section><strong>Confirm the result.</strong><p>The form shows whether each delivery is pending, sent, or failed and links to the full delivery record in MOED. The message is also recorded in the Engagement, Organization, and selected Contact Chron logs.</p></section></li>
                        <li><span>06</span><section><strong>Watch for replies.</strong><p>When a marked reply is routed back to the engagement, the MOED bot privately notifies the Mattermost user who most recently emailed that person about the engagement. Open the notification to continue in the engagement Chron.</p></section></li>
                    </ol>
                </section>

                <section class="manual-subsection">
                    <h3>Turn a Mattermost Post Into MOED Work</h3>
                    <ol class="manual-steps">
                        <li><span>01</span><section><strong>Link the channel.</strong><p>An editor or administrator runs <code>/moed link-event ID</code>. The link establishes which engagement receives work from that channel.</p></section></li>
                        <li><span>02</span><section><strong>Open Message actions.</strong><p>Hover over a channel post and choose <strong>Message actions</strong> using the grid/apps icon—not the three-dot menu. Then choose <strong>Add MOED task</strong>, <strong>Add to MOED Chron</strong>, or <strong>Send via MOED email</strong>.</p></section></li>
                        <li><span>03</span><section><strong>Review before saving or sending.</strong><p>Confirm the task, Chron entry, or email details in the form. Nothing changes in MOED and no email is sent until you submit the final review.</p></section></li>
                        <li><span>04</span><section><strong>Continue in MOED.</strong><p>The resulting task, Chron entry, or email delivery record appears on the linked engagement, with the selected Mattermost context when you chose to include it.</p></section></li>
                    </ol>
                </section>

                <section class="manual-card-grid manual-card-grid-two">
                    <article class="manual-card">
                        <span class="manual-kicker">Add MOED Task</span>
                        <h3>Create Follow-Up Work</h3>
                        <p><strong>Add MOED task</strong> creates follow-up work on the engagement linked to the channel. MOED suggests a title and notes from the post. Review them, choose a due date and priority if needed, then save. The task appears with the engagement's other work in MOED.</p>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Add to MOED Chron</span>
                        <h3>Preserve Context as History</h3>
                        <p><strong>Add to MOED Chron</strong> adds the post to the linked engagement's history. Review or edit the suggested entry, then choose <strong>Save to Chron</strong>. Use this for decisions, outcomes, commitments, and useful conversation context that should remain with the engagement.</p>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Send via MOED Email</span>
                        <h3>Continue the Conversation</h3>
                        <p><strong>Send via MOED email</strong> creates a reviewed message to assigned engagement contacts. You can include the selected post or a short thread excerpt, and MOED keeps the routing marker, delivery result, and Chron history together.</p>
                    </article>
                </section>

                <section class="manual-callout">
                    <span class="manual-callout-icon" aria-hidden="true">✓</span>
                    <div class="manual-callout-body">
                        <h3>Before You Save</h3>
                        <p>Task, Chron, and email actions all show what will be saved or sent. They are available in channels linked to a MOED engagement when your account has permission. Opening, reviewing, or canceling a form changes nothing.</p>
                    </div>
                </section>

                <section class="manual-subsection">
                    <h3>Permissions, Privacy, and Unlinking</h3>
                    <ul class="manual-check-list">
                        <li><strong>MOED decides access:</strong> linking does not grant a new role. Reviewers stay read only; editors and administrators receive only the task actions their MOED role permits.</li>
                        <li><strong>Private by default:</strong> personal summaries, searches, email forms, delivery results, reply alerts, errors, and action confirmations are visible only to the requesting user. <code>link-event</code> deliberately posts a channel-visible engagement card, including its routing marker and Copy control.</li>
                        <li><strong>MOED is authoritative:</strong> task, Chron, and email actions use MOED's permissions, delivery service, and records. The plugin does not connect to the MOED database or keep a second copy of engagement email.</li>
                        <li><strong>Post actions are scoped:</strong> the plugin verifies that you can read the selected post and obtains the engagement from the channel's link. MOED permits task, Chron, and email actions only for linked editors and administrators.</li>
                        <li><strong>Remove access:</strong> open <a href="mattermost.php">Mattermost</a> in MOED and choose <strong>Unlink</strong>. The Mattermost account immediately loses command access until it is linked again.</li>
                    </ul>
                </section>
            </section>

            <section class="manual-chapter" id="administration" data-manual-section data-keywords="administrator users invite activation deactivate reactivate reset password reset 2FA delete audit log retention prune backup database operations readiness migrations geocoding elevated five minutes">
                <header class="manual-chapter-heading">
                    <span>Chapter 11</span>
                    <h2>Administration</h2>
                    <p>Administrator tools control identity, accountability, continuity, and deployment health. Use them deliberately and keep a second administrator available.</p>
                </header>

                <?php if (!$manual_is_admin): ?>
                    <article class="manual-callout manual-callout-neutral">
                        <span class="manual-callout-icon" aria-hidden="true">i</span>
                        <div class="manual-callout-body"><h3>Administrator Access Required</h3><p>This chapter explains how <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> is governed, but its linked controls appear only to administrators.</p></div>
                    </article>
                <?php endif; ?>

                <section class="manual-subsection">
                    <h3>Unlock Sensitive Actions</h3>
                    <p>On Users—or when a destructive action redirects for confirmation—enter the administrator password plus a fresh authenticator or recovery code. Elevation lasts five minutes, and some lifecycle actions consume it immediately. Locked user controls remain hidden until confirmation succeeds.</p>
                </section>

                <section class="manual-card-grid manual-card-grid-two">
                    <article class="manual-card">
                        <span class="manual-kicker">Identity</span>
                        <h3>Users</h3>
                        <p>Invite a username, verified-on-acceptance email, and Reviewer, Editor, or Admin role. Review account status, profile, email verification, 2FA, password-change requirement, and activity timestamps.</p>
                        <p>Elevated actions can resend invitations, edit username, role, and daily work digest settings, set a temporary password, reset another user’s 2FA, deactivate/activate, or delete an invited or inactive account. Deactivation revokes sessions and calendar links and unassigns tasks; activation does not restore those links or assignments.</p>
                        <?php if ($manual_is_admin): ?><a href="users.php" class="manual-inline-link">Manage users</a><?php endif; ?>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Accountability</span>
                        <h3>Audit Log</h3>
                        <p>Inspect login, security, and database-change activity with actor, affected record, IP address, and local/UTC time. Filter by category, text, date range, and exact IP. Entries are newest first and append-only during normal use; individual records cannot be edited or deleted.</p>
                        <p>In the Retention panel, administrators choose how many days to keep and preview the exact UTC cutoff and affected count. Pruning requires freshly confirmed administrator access, the literal <code>PRUNE</code> phrase, and a final browser confirmation. Deployment operators can use <code>./scripts/prune_audit_log.sh DAYS</code> for the equivalent preview-first terminal workflow. Either path records the completed operation in the log.</p>
                        <?php if ($manual_is_admin): ?><a href="audit_log.php" class="manual-inline-link">Review the audit log</a><?php endif; ?>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Continuity</span>
                        <h3>Encrypted Backup</h3>
                        <p>Confirm the administrator password and a fresh factor, then choose and confirm a unique backup password of at least 16 characters. <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> downloads the encrypted snapshot as a <code>.dnrbackup</code> file.</p>
                        <p>Keep the file, its password, and the separate DNR 2FA encryption key securely backed up. None can be recovered from the others. Database restore is intentionally a deployment-host procedure, not a web action.</p>
                        <?php if ($manual_is_admin): ?><a href="database_maintenance.php" class="manual-inline-link">Export a backup</a><?php endif; ?>
                    </article>
                    <article class="manual-card">
                        <span class="manual-kicker">Health</span>
                        <h3>Operations</h3>
                        <p>The internal operations view summarizes task backlog, geocoding retries, inbound mail review/failures, recent authentication failures, migration readiness, and the last encrypted backup. Use it to identify operational work that needs attention before it becomes a user-facing problem.</p>
                        <?php if ($manual_is_admin): ?><a href="operations.php" class="manual-inline-link">Open operations</a><?php endif; ?>
                    </article>
                </section>

                <article class="manual-callout manual-callout-warning">
                    <span class="manual-callout-icon" aria-hidden="true">!</span>
                    <div class="manual-callout-body"><h3>Permanent Means Permanent</h3><p>Deleting an organization removes its contacts and engagements together with their Chron, tasks, presentations, contact assignments, and financial reports. Deleting an engagement removes the same event-specific data. Deleting a contact removes that contact’s Chron, tasks, and event role assignments. Active users must be deactivated before deletion; pending invitations can be deleted directly. Use archive for ordinary record retirement.</p></div>
                </article>
            </section>

            <section class="manual-chapter" id="troubleshooting" data-manual-section data-keywords="troubleshooting cannot edit missing button search no result map pin missing email did not route outbound failed waiting retry unavailable calendar item task owner caller checklist filter month refresh QR paste copy clipboard PDF upload logout session invalid token error help FAQ">
                <header class="manual-chapter-heading">
                    <span>Chapter 12</span>
                    <h2>Troubleshooting and Good Practice</h2>
                    <p>Most surprises come from role limits, archived parent records, delayed background work, or intentionally strict safety checks.</p>
                </header>

                <section class="manual-faq">
                    <details>
                        <summary><span>I Cannot See an Edit or Delete Button</span><i aria-hidden="true">+</i></summary>
                        <p>Reviewers are read only. Editors can edit and archive but cannot permanently delete. Administrators must freshly unlock sensitive access before many delete and user-management controls appear. Archived records are viewed separately and generally must be restored before editing.</p>
                    </details>
                    <details>
                        <summary><span>An Organization Will Not Archive</span><i aria-hidden="true">+</i></summary>
                        <p>Archive or move every active contact and engagement belonging to it first. <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> prevents archiving the parent while active child records would become stranded.</p>
                    </details>
                    <details>
                        <summary><span>Search Did Not Find an Engagement</span><i aria-hidden="true">+</i></summary>
                        <p>Use terms of at least three characters. Remove quotation marks to match any word; quote a group when all its words must match. Check the Archived view and the lifecycle filter. Search indexes active Chron entries, not archived ones.</p>
                    </details>
                    <details>
                        <summary><span>A New Address Has No Map Pin</span><i aria-hidden="true">+</i></summary>
                        <p>Confirm that the engagement contains a usable location and fits the current lifecycle/date filters. New addresses are processed asynchronously; keep the Map page open and the pin will appear automatically when the lookup finishes. If it remains pending, ask an administrator to check the geocoding queue and provider health.</p>
                    </details>
                    <details>
                        <summary><span>An Email Still Needs Review</span><i aria-hidden="true">+</i></summary>
                        <p>Confirm the sender or participant address exactly matches one active record. Correct missing contact/organization email data, preserve a single valid <code><?php echo htmlspecialchars($manual_marker_template, ENT_QUOTES, 'UTF-8'); ?></code> marker in the subject or plain-text body where applicable, then choose Retry automatic routing—or approve the intended routes manually.</p>
                    </details>
                    <details>
                        <summary><span>An Outbound Email Failed or Cannot Be Queued</span><i aria-hidden="true">+</i></summary>
                        <p>Confirm that the engagement has at least one assigned contact with a valid email address. Open the outbound message to see which recipient failed. Correct the address if needed, then select <strong>Retry failed deliveries</strong>. If sending is unavailable for every message, ask an administrator to check the mail setup.</p>
                    </details>
                    <details>
                        <summary><span>A Calendar Stopped Refreshing</span><i aria-hidden="true">+</i></summary>
                        <p>Check whether that device’s subscription is still Active. A revoked URL cannot be recovered; create a new device-specific link and replace the old subscription in the calendar application.</p>
                    </details>
                    <details>
                        <summary><span>An Event or Task Is Missing from the Month Calendar</span><i aria-hidden="true">+</i></summary>
                        <p>Confirm the displayed month and selector. <strong>Events</strong> does not include tasks, <strong>My Tasks</strong> includes only work assigned to you, and task views include only active tasks with due dates. Use <strong>Everything</strong> when you want events and all due-dated work together.</p>
                    </details>
                    <details>
                        <summary><span>Changing the Caller Did Not Move Existing Tasks</span><i aria-hidden="true">+</i></summary>
                        <p>The Caller is used only to choose the owner of the initial standard checklist when the engagement is created. Later Caller changes do not alter existing task ownership. If responsibility changes, reassign those tasks in the Work Queue.</p>
                    </details>
                    <details>
                        <summary><span>A QR Code Will Not Paste or Copy</span><i aria-hidden="true">+</i></summary>
                        <p>For paste, copy a supported JPEG, PNG, or WebP image, select <strong>Paste QR code</strong>, and—if prompted by the page—press <kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>V</kbd> while the button remains focused. For copy, select the QR preview. If the browser cannot write an image directly to the clipboard, <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> opens the image in a separate tab so you can copy it there.</p>
                    </details>
                    <details>
                        <summary><span>A Save Reports an Expired Request</span><i aria-hidden="true">+</i></summary>
                        <p>Reload the page and repeat the change. <?php echo htmlspecialchars($manual_brand, ENT_QUOTES, 'UTF-8'); ?> rejects stale or missing request tokens, and concurrent edits may require you to reopen the newest record before saving.</p>
                    </details>
                    <details>
                        <summary><span>I Was Signed Out Unexpectedly</span><i aria-hidden="true">+</i></summary>
                        <p>Password changes, password recovery, administrator resets, account changes, deactivation, and database restoration invalidate existing sessions by design. Sign in again with the current credentials or contact an administrator if the account is unavailable.</p>
                    </details>
                </section>

                <section class="manual-principles">
                    <article><span>01</span><h3>Record the Why</h3><p>Chron should preserve the decision and context a future teammate needs, not just “called.”</p></article>
                    <article><span>02</span><h3>Name the Next Action</h3><p>Turn commitments into tasks with an owner and date while the conversation is fresh.</p></article>
                    <article><span>03</span><h3>Archive Before Deleting</h3><p>Relationship history and event outcomes are usually more valuable than a perfectly tidy active list.</p></article>
                    <article><span>04</span><h3>Protect Private Links</h3><p>Calendar URLs, invitation links, verification links, recovery links, and recovery codes are credentials.</p></article>
                </section>
            </section>

            <section class="manual-empty" data-manual-empty hidden>
                <span aria-hidden="true">⌕</span>
                <h2>No Matching Chapter</h2>
                <p>Try a broader term such as “task,” “email,” “security,” or “archive.”</p>
                <button type="button" class="button-secondary" data-manual-empty-clear>Show the Full Manual</button>
            </section>
        </section>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
