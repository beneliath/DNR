# MOED UI/UX review

Reviewed September 5, 2026 (America/Chicago) · Checkout **1.11.16 / 8ccb5f6**.

MOED has a strong visual foundation and useful operational concepts. The largest improvement is to make recurring actions behave consistently and keep users close to their work. Preserve the existing brand, role-aware controls, readable record headings, next-action/readiness panels, reusable checklists, and linked record history.

This review contains **28 recommendations, including 7 P1 items**. P1 means a risk to user choices, drafts, record reachability, or keyboard access. P2 means recurring friction or a coherence improvement. Effort is relative: S = localized change; M = shared interaction or several screens; L = workflow/data-model expansion. These are sequencing estimates, not delivery commitments.

## Evidence and coverage

A read-only browser walkthrough covered the dashboard, sign-in, booking board, inquiry detail, engagement list/detail, task editor, organization detail, contact detail, calendar, work queue, and empty inbound-mail queue. After reauthentication, the deployment footer matched the checkout at 1.11.16 / 8ccb5f6. The initially cached dashboard had shown 1.11.15; conclusions use the current source and refreshed session.

The task relationship-search issue was reproduced in the live editor. The form was canceled without saving, and the task remained linked to its engagement. The conversion selection issue was reproduced by executing its exact request-selection block with synthetic task IDs, without database writes. Other behavior findings are source-confirmed unless explicitly marked observed. Three independent source passes covered workflows, information architecture, and accessibility; high-priority claims were checked again for counterevidence.

Mobile layout, keyboard drawer behavior, dark-theme screens, populated mail triage, conversion submission, closeout, long forms, account administration, and recovery flows were reviewed primarily from templates, CSS, JavaScript, and relevant tests. They were not all exercised live. No formal assistive-technology audit, user interviews, usability timing study, or complete responsive-device test was performed. No application code, saved records, or settings were changed.

## Recommended sequence

1. **Protect choices and continuity:** fix 01, 02, 04, 05, 06, and 11; add explicit next-action reconciliation (03). These should precede cosmetic work.
2. **Make daily work coherent:** simplify dashboard/queue scope (07, 26); align navigation (08); introduce scoped Chron Log Entries and reliable return destinations (09, 10); fix field names and date formatting (16, 27).
3. **Standardize shared UI:** labels/errors/selectors (20–22), readable secondary text (23), primary/mobile actions (24), conditional settings (25), and coherent stages/status (28). Apply through shared components rather than page-by-page exceptions.
4. **Improve deeper workflows:** lightweight intake and pickers (12, 13), relationship-centered organization pages (17), read-first mail and actionable map exceptions (18, 19). Agree closeout policy and draft needs with operators before 14 and 15.

## Proposed common structure

Use a stable record header with the record name, organization, dates, clearly named status, one primary action, and a secondary More menu. Below it, keep a concise overview and next-action/readiness summary. Put frequently repeated work in **Activity, Correspondence, Tasks**, adding record-specific sections such as Presentations, Contacts, Logistics, and Financials. Let long descriptions expand so they do not bury useful work.

Navigation can use four groups without increasing nesting: **Work** (Dashboard, My Work, Booking Pipeline, Inbox); **Schedule** (Engagements, Calendar, Map); **Relationships** (Organizations, Contacts); **Administration** (Users, Database). Utilities hold profile, notifications/security, integrations, help, and appearance. Calendar retains its richer tasks/birthdays scope; coordination with event list/map should preserve relevant event filters without pretending the views contain identical data.

Use **Activity** for the history surface, **Add Chron Log Entry** for the action, **Task** for a commitment, **Owner** for responsibility, **Due date** for the task deadline, and **Event dates** for scheduling. Preserve **Chron Log Entry** in the field labels, save actions, and messages, as requested during preview review. Treat inquiries and engagements as distinct record types with an explicit booking handoff.

Use one shared primary/secondary/destructive action system, one field/error/selection pattern, one date formatter, one status badge vocabulary, and one list-filter/return-context pattern. Preserve the useful distinctions between confirmation, event lifecycle, task status, and financial finalization.

## Findings

### 01. Keep a task’s selected record when searching

**P1 · Workflow · Effort S · Reproduced in browser**

In Edit Task, typing a query with no matching records changed the existing engagement selection to “General MOED work,” without choosing a replacement. The form was canceled; no task was saved.

**User impact:** A user who searches and then saves another edit can unintentionally detach the task from its event.

**Recommendation:** Keep the selected record separate from search suggestions. Change it only after an explicit result selection or a labeled Clear relationship action.

**Acceptance:** Search for a nonmatching record, clear the query, and encounter a failed request: the original relationship remains selected in every case.

Evidence: [src/assets/js/task-form.js:64](/Users/dgilmore/DNR/src/assets/js/task-form.js:64), [src/follow_up_task_helpers.php:360](/Users/dgilmore/DNR/src/follow_up_task_helpers.php:360), [src/edit_task.php:84](/Users/dgilmore/DNR/src/edit_task.php:84).

### 02. Respect “move no tasks” during booking

**P1 · Workflow · Effort S · Source + isolated reproduction**

When every conversion checkbox is cleared, the browser omits task_ids. The request handler then takes its default select-all branch. The exact handler block reproduced this with two synthetic IDs, without database writes.

**User impact:** The final conversion contradicts the user’s explicit task-transfer choice and the review screen’s promise.

**Recommendation:** Apply default selection only on GET; treat an empty POST selection as zero. Show “Book engagement · move 0 tasks” or an equivalent explicit summary.

**Acceptance:** Test none, one, and all selected through the HTTP form handler; only the selected tasks move.

Evidence: [src/convert_inquiry.php:44](/Users/dgilmore/DNR/src/convert_inquiry.php:44), [src/convert_inquiry.php:117](/Users/dgilmore/DNR/src/convert_inquiry.php:117).

### 03. Reconcile the next action when an inquiry becomes an engagement

**P1 · Workflow · Effort M · Source confirmed**

Conversion clears the inquiry’s next-action text and date. It does not automatically make a task or copy that commitment into the conversion note/history. Engagement Next Action instead derives from an open task.

**User impact:** An outstanding promise can disappear from daily work at the moment of booking.

**Recommendation:** During booking, explicitly resolve or carry forward the action. Longer term, use a designated task as Next Action across inquiries and engagements.

**Acceptance:** An outstanding next action remains visible on the engagement, or the user explicitly resolves it and the decision is recorded.

Evidence: [src/templates/booking_inquiry_form.php:119](/Users/dgilmore/DNR/src/templates/booking_inquiry_form.php:119), [src/booking_inquiry_helpers.php:813](/Users/dgilmore/DNR/src/booking_inquiry_helpers.php:813), [src/booking_inquiry_helpers.php:865](/Users/dgilmore/DNR/src/booking_inquiry_helpers.php:865), [src/view_engagement.php:294](/Users/dgilmore/DNR/src/view_engagement.php:294).

### 04. Make every inbound message reachable

**P1 · Mail · Effort M · Source confirmed**

Each inbox view fetches the newest 100 messages. Counts cover all messages, but there is no search, pagination, or visible limit. The live inbox was empty, so this volume condition was not exercised.

**User impact:** Older unresolved messages can fall out of the navigable queue while still contributing to its count.

**Recommendation:** Add search, pagination, displayed/total counts, and oldest-first triage for Needs review.

**Acceptance:** With more than 100 unresolved messages, a user can locate and open the oldest one from the interface.

Evidence: [src/inbound_mail.php:118](/Users/dgilmore/DNR/src/inbound_mail.php:118), [src/inbound_mail.php:127](/Users/dgilmore/DNR/src/inbound_mail.php:127), [src/inbound_mail.php:215](/Users/dgilmore/DNR/src/inbound_mail.php:215).

### 05. Preserve profile edits when resending verification

**P1 · Forms · Effort S · Source confirmed**

Resend email verification appears beside Save Changes but submits a separate hidden form and redirects. Unsaved main-form values are not posted.

**User impact:** Name, phone, picture, and notification edits can be discarded by an action that appears to belong to the same form.

**Recommendation:** Resend inline while keeping the draft, or separate independently saved sections and protect unsaved changes before navigation.

**Acceptance:** Change profile fields, resend verification, and confirm all unsaved values and the chosen picture remain available.

Evidence: [src/profile.php:89](/Users/dgilmore/DNR/src/profile.php:89), [src/profile.php:429](/Users/dgilmore/DNR/src/profile.php:429), [src/profile.php:440](/Users/dgilmore/DNR/src/profile.php:440).

### 06. Make mobile navigation behave as a real drawer

**P1 · Accessibility · Effort M · Source confirmed**

Below 860px, the sidebar is translated offscreen without being hidden or inert. Opening and closing only toggles classes and aria-expanded; it does not manage keyboard focus.

**User impact:** Keyboard users can tab into invisible links or behind the open overlay, and lose their place when it closes.

**Recommendation:** Remove closed navigation from focus order, move focus inside on open, keep modal focus inside, provide an internal Close button, and restore focus to the opener. Add Skip to main content.

**Acceptance:** At 390px and 860px, closed links are skipped; open navigation contains focus; Escape restores the opener; desktop resizing restores ordinary navigation.

Evidence: [src/assets/css/modern.css:4588](/Users/dgilmore/DNR/src/assets/css/modern.css:4588), [src/assets/js/app-shell.js:12](/Users/dgilmore/DNR/src/assets/js/app-shell.js:12), [src/templates/header.php:124](/Users/dgilmore/DNR/src/templates/header.php:124).

### 07. Put daily work ahead of empty dashboard panels

**P2 · Navigation · Effort S · Observed + source**

The dashboard gives the first large row to inquiry actions and pipeline health even when both contain no actionable work. Upcoming engagements and personal tasks appear below it.

**User impact:** Users scan empty panels and repeated zero counts before reaching the records they need.

**Recommendation:** Lead with My work and upcoming engagements, plus an exception summary. Keep a stable layout; collapse clear/zero sections into compact reassurance with an expand option.

**Acceptance:** On both quiet and busy days, users can find their next action and next engagement in the first main content region.

Evidence: [src/dashboard.php:130](/Users/dgilmore/DNR/src/dashboard.php:130), [src/dashboard.php:185](/Users/dgilmore/DNR/src/dashboard.php:185), [src/dashboard.php:221](/Users/dgilmore/DNR/src/dashboard.php:221).

### 08. Group navigation around daily work

**P2 · Navigation · Effort S · Observed + source**

Calendar is separated from Engagements and Map in Account and application; Users and Database sit directly in the primary list. Role-preview controls remain prominent for administrators.

**User impact:** Core planning views are harder to discover, while occasional administrative controls compete with routine navigation.

**Recommendation:** Group Work (Dashboard, My Work, Booking, Inbox), Schedule (Engagements, Calendar, Map), Relationships (Organizations, Contacts), and Administration. Keep profile, security, integrations, help, and theme in utilities. Retain a conspicuous banner whenever role preview is active.

**Acceptance:** A new user can locate the calendar from the scheduling group and distinguish operational tools from account and administrator settings.

Evidence: [src/templates/header.php:129](/Users/dgilmore/DNR/src/templates/header.php:129), [src/templates/header.php:169](/Users/dgilmore/DNR/src/templates/header.php:169), [src/templates/header.php:186](/Users/dgilmore/DNR/src/templates/header.php:186).

### 09. Use the same local Add Chron Log Entry action on every record

**P2 · Workflow · Effort M · Observed + source**

Inquiry activity has an inline composer for Chron Log Entries. The engagement action opens the full engagement editor, where Add Entry submits other event edits too. Contacts instruct users to edit the contact to log a conversation.

**User impact:** A simple Chron Log Entry requires navigation and can be blocked by unrelated validation or save unrelated changes.

**Recommendation:** Provide a scoped Add Chron Log Entry form in Activity on inquiries, engagements, organizations, and contacts. Keep stable profile notes separate from chronological communication.

**Acceptance:** Add a Chron Log Entry without opening a record editor; success stays in Activity and changes only that entry.

Evidence: [src/view_inquiry.php:230](/Users/dgilmore/DNR/src/view_inquiry.php:230), [src/view_engagement.php:462](/Users/dgilmore/DNR/src/view_engagement.php:462), [src/edit_engagement.php:977](/Users/dgilmore/DNR/src/edit_engagement.php:977), [src/view_contact.php:168](/Users/dgilmore/DNR/src/view_contact.php:168).

### 10. Preserve record, tab, and list context

**P2 · Navigation · Effort M · Observed links + source**

Engagement Save and Cancel return to the engagement list even when editing from a record section. Contact/organization actions retain archive status but lose search/sort/page context. Pagination offers First and Next, not Previous.

**User impact:** People repeatedly re-find records and lose their place while processing a queue.

**Recommendation:** Use a validated return destination throughout detail/edit/actions, including record tab and list filters. Add Previous and actual displayed counts. Reuse the task editor’s better return behavior.

**Acceptance:** Open a filtered page, edit a record, and return to the same results and position. Section edits return to that section.

Evidence: [src/edit_engagement.php:555](/Users/dgilmore/DNR/src/edit_engagement.php:555), [src/edit_engagement.php:1050](/Users/dgilmore/DNR/src/edit_engagement.php:1050), [src/contacts.php:73](/Users/dgilmore/DNR/src/contacts.php:73), [src/contacts.php:436](/Users/dgilmore/DNR/src/contacts.php:436), [src/organizations.php:371](/Users/dgilmore/DNR/src/organizations.php:371).

### 11. Keep contact drafts when creating a missing organization

**P1 · Forms · Effort M · Source confirmed**

Add New Organization leaves the contact form. Successful organization creation redirects to another blank organization form rather than returning and selecting the new record.

**User impact:** Users abandon or re-enter a partially completed contact simply because a related organization does not exist yet.

**Recommendation:** Use an inline organization creator or a draft-preserving return flow. Select the created organization automatically. Offer Create another as a separate choice.

**Acceptance:** Enter a contact draft, create its organization, and return with every contact value intact and the new organization selected.

Evidence: [src/add_contact.php:218](/Users/dgilmore/DNR/src/add_contact.php:218), [src/add_organization.php:169](/Users/dgilmore/DNR/src/add_organization.php:169).

### 12. Allow lightweight relationship capture

**P2 · Forms · Effort M · Source; validate product policy**

Organization creation requires a complete physical address and presents extensive profile/address/contact fields. Contact creation asks for email twice.

**User impact:** Early intake asks for information that may only become known later, encouraging placeholders or delayed capture.

**Recommendation:** Start with the organization name and known contact details. Put optional details in clear sections; require address completeness when a workflow needs it. Validate whether duplicate email entry prevents enough errors to keep it.

**Acceptance:** A caller can be captured with known information; missing operational details appear as explicit follow-up work.

Evidence: [src/add_organization.php:217](/Users/dgilmore/DNR/src/add_organization.php:217), [src/add_organization.php:268](/Users/dgilmore/DNR/src/add_organization.php:268), [src/add_contact.php:247](/Users/dgilmore/DNR/src/add_contact.php:247).

### 13. Use one searchable organization/contact picker

**P2 · Forms · Effort M · Source confirmed**

Inquiry forms list all organizations and contacts independently. A selected organization does not narrow contacts; an incompatible selection is rejected after submission.

**User impact:** Users must scan long lists and discover relationship errors late.

**Recommendation:** Use searchable selectors with an explicit selected value, filter compatible contacts, explain standalone contacts, and support inline creation without losing the draft.

**Acceptance:** Choosing an organization immediately limits or clearly identifies valid contacts; keyboard search and create-return preserve the draft.

Evidence: [src/templates/booking_inquiry_form.php:25](/Users/dgilmore/DNR/src/templates/booking_inquiry_form.php:25), [src/add_inquiry.php:92](/Users/dgilmore/DNR/src/add_inquiry.php:92), [src/booking_inquiry_helpers.php:205](/Users/dgilmore/DNR/src/booking_inquiry_helpers.php:205).

### 14. Explain the canceled-task exception in closeout

**P2 · Workflow · Effort S · Documented policy; UX decision**

The documented and tested rule requires even canceled tasks due on/before the final dated presentation to be Completed before the first closeout. Elsewhere Canceled means no longer needed.

**User impact:** Operators may feel required to record work as completed when it was deliberately canceled.

**Recommendation:** Explain the consequence when canceling affected tasks. If the business process permits, introduce an explicit waiver with a reason; do not silently relax the current rule.

**Acceptance:** A user can understand why a canceled task blocks closeout and resolve it truthfully under an agreed policy.

Evidence: [src/financial_report_helpers.php:75](/Users/dgilmore/DNR/src/financial_report_helpers.php:75), [src/help.php:356](/Users/dgilmore/DNR/src/help.php:356), [src/help.php:467](/Users/dgilmore/DNR/src/help.php:467), [tests/financial_tracking_integration_test.php:133](/Users/dgilmore/DNR/tests/financial_tracking_integration_test.php:133).

### 15. Separate collecting receipts from finalizing closeout

**P2 · Workflow · Effort L · Source; validate operator need**

The receipt form is hidden while prerequisites block closeout. When available, values default to 0.00 and the primary save finalizes the report; there is no draft amount state.

**User impact:** Partial information must be kept elsewhere, and an untouched zero can look like a deliberately confirmed zero.

**Recommendation:** Allow Save draft before finalization, distinguish not entered from confirmed zero, show totals, and keep finalization rules explicit.

**Acceptance:** Partial receipts can be saved, reopened, and completed; finalization requires explicit confirmation of every amount and current prerequisites.

Evidence: [src/close_engagement.php:66](/Users/dgilmore/DNR/src/close_engagement.php:66), [src/close_engagement.php:322](/Users/dgilmore/DNR/src/close_engagement.php:322), [src/close_engagement.php:348](/Users/dgilmore/DNR/src/close_engagement.php:348).

### 16. Name date filters for the date they actually use

**P2 · Language · Effort S · Observed + source**

Pipeline Target Date filters next_action_due_date, while inquiry readiness uses Target Date Identified for the preferred event dates.

**User impact:** The same phrase means either the event date or a follow-up deadline, producing misleading search expectations.

**Recommendation:** Use Event dates for the proposed engagement and Next action due for the work deadline. Rename No Target Date to No action due date.

**Acceptance:** The filter label and returned records refer to the same date field; preferred event dates remain separately labeled.

Evidence: [src/inquiries.php:140](/Users/dgilmore/DNR/src/inquiries.php:140), [src/inquiries.php:287](/Users/dgilmore/DNR/src/inquiries.php:287), [src/view_inquiry.php:316](/Users/dgilmore/DNR/src/view_inquiry.php:316).

### 17. Put relationships before financial history

**P2 · Navigation · Effort M · Observed + source**

Organization details start with five financial metrics even with no closed events; profile, notes, contacts, and tasks follow. Related events are represented only through finalized financial history.

**User impact:** Finding the right person or upcoming engagement requires scrolling through unrelated history.

**Recommendation:** Lead with primary contacts, next event, and next action. Add Overview, Activity, Contacts, Engagements, Tasks, and Financials sections using the newer record layout.

**Acceptance:** A user can identify whom to call and open any related upcoming or past engagement without going through financial reports.

Evidence: [src/view_organization.php:108](/Users/dgilmore/DNR/src/view_organization.php:108), [src/view_organization.php:159](/Users/dgilmore/DNR/src/view_organization.php:159), [src/view_organization.php:180](/Users/dgilmore/DNR/src/view_organization.php:180), [src/view_organization.php:251](/Users/dgilmore/DNR/src/view_organization.php:251).

### 18. Let users read an email before routing it

**P2 · Mail · Effort M · Source; live queue empty**

Selected-message markup puts classification, technical routing information, destination groups, and actions before the message body.

**User impact:** Users must scroll past filing controls to read the content needed for their routing decision.

**Recommendation:** Place sender, subject, and message body first, with a concise Save communication to panel alongside it. Put routing markers and diagnostics under Details. Make the empty inbox a single clear state.

**Acceptance:** For a long email, the content and selected destination are visible together before approval; technical detail remains available on demand.

Evidence: [src/inbound_mail.php:289](/Users/dgilmore/DNR/src/inbound_mail.php:289), [src/inbound_mail.php:313](/Users/dgilmore/DNR/src/inbound_mail.php:313), [src/inbound_mail.php:337](/Users/dgilmore/DNR/src/inbound_mail.php:337), [src/inbound_mail.php:363](/Users/dgilmore/DNR/src/inbound_mail.php:363).

### 19. Make unmapped events actionable

**P2 · Navigation · Effort M · Source confirmed**

Map status describes missing addresses, lookup failures, and display limits as text counts. The legend lists confirmation colors even when lifecycle filters include other states.

**User impact:** Users learn that records are missing but cannot identify and fix them from the map.

**Recommendation:** Add a companion list with On map, Needs address, and Location not found filters, each linking to the relevant event/editor. Match the legend to the current lifecycle scope.

**Acceptance:** Every excluded event can be found from an exception count; displayed pins and legend use matching labels.

Evidence: [src/assets/js/map.js:78](/Users/dgilmore/DNR/src/assets/js/map.js:78), [src/map.php:293](/Users/dgilmore/DNR/src/map.php:293), [src/map.php:301](/Users/dgilmore/DNR/src/map.php:301).

### 20. Associate visible form labels with their controls

**P2 · Accessibility · Effort S · Source confirmed**

Engagement Event Title and Event Type use div text as labels. Travel/lodging numeric fields have labels without for/id association.

**User impact:** Assistive technology may announce unnamed controls, and clicking the visible label does not reliably focus its field.

**Recommendation:** Use label/for/id consistently and fieldset/legend for grouped choices. Give the relationship search its own clear accessible name.

**Acceptance:** Every input exposes its visible label as its accessible name and label activation focuses the intended control.

Evidence: [src/edit_engagement.php:745](/Users/dgilmore/DNR/src/edit_engagement.php:745), [src/edit_engagement.php:835](/Users/dgilmore/DNR/src/edit_engagement.php:835), [src/templates/follow_up_task_form.php:42](/Users/dgilmore/DNR/src/templates/follow_up_task_form.php:42).

### 21. Make form errors lead directly to recovery

**P2 · Accessibility · Effort M · Source confirmed**

Several login/security/profile/contact forms render errors as plain paragraphs with no focused summary or connection to fields. This is not universal: some newer pages already use alert/status roles.

**User impact:** Users may miss the failure or have to search a long form for its cause.

**Recommendation:** Standardize a focused error summary, field links, inline messages, aria-invalid and aria-describedby. Preserve nonsecret values and provide consistent successful-save feedback.

**Acceptance:** Invalid submission announces what failed, places focus at the summary, links to the affected field, and preserves entered values.

Evidence: [src/login.php:116](/Users/dgilmore/DNR/src/login.php:116), [src/verify_2fa.php:114](/Users/dgilmore/DNR/src/verify_2fa.php:114), [src/edit_contact.php:450](/Users/dgilmore/DNR/src/edit_contact.php:450), [src/profile.php:314](/Users/dgilmore/DNR/src/profile.php:314).

### 22. Give country selectors one keyboard behavior

**P2 · Accessibility · Effort M · Source confirmed**

Phone country choices are individually tabbable buttons with Escape handling; address country choices already implement arrows/Home/End and selected-option focus.

**User impact:** The same kind of control requires different navigation and can add dozens of Tab stops.

**Recommendation:** Use one native select or tested searchable combobox pattern across country selectors, with predictable selection, typeahead, Escape, and Tab exit.

**Acceptance:** Opening places focus appropriately; arrows/typeahead select predictably; one Tab leaves the control instead of traversing every country.

Evidence: [src/functions.php:760](/Users/dgilmore/DNR/src/functions.php:760), [src/assets/js/phone-input.js:89](/Users/dgilmore/DNR/src/assets/js/phone-input.js:89), [src/assets/js/phone-input.js:159](/Users/dgilmore/DNR/src/assets/js/phone-input.js:159), [src/assets/js/page-actions.js:110](/Users/dgilmore/DNR/src/assets/js/page-actions.js:110).

### 23. Increase light-theme secondary text contrast

**P2 · Visual system · Effort S · Source + contrast calculation**

The light subtle-text token #8992a3 has contrast 3.13:1 on white and 2.93:1 on the app background. It is used for small metadata and placeholders. The equivalent dark-theme token passes ordinary-text contrast on its main surface.

**User impact:** Useful secondary information is harder to read, especially for people with reduced vision.

**Recommendation:** Darken informative secondary text to meet 4.5:1 on its actual backgrounds; use spacing, size, and weight for hierarchy instead of excessive faintness.

**Acceptance:** Small text and placeholders reach 4.5:1 in their actual light/dark states; focus and disabled states are checked separately.

Evidence: [src/assets/css/modern.css:28](/Users/dgilmore/DNR/src/assets/css/modern.css:28), [src/assets/css/modern.css:2073](/Users/dgilmore/DNR/src/assets/css/modern.css:2073), [src/assets/css/modern.css:3288](/Users/dgilmore/DNR/src/assets/css/modern.css:3288). Basis: [W3C contrast guidance](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).

### 24. Give frequent actions visible labels

**P2 · Visual system · Effort M · Desktop observed; mobile source**

Lists use icon-only action clusters. They have accessible names, but tooltips are deliberately disabled on mobile. New Inquiry has a filled primary treatment while New Engagement, New Task, and many Save actions appear visually secondary.

**User impact:** Touch users must infer unfamiliar icons, and the main action is harder to identify consistently.

**Recommendation:** Use one filled primary action per context, clear secondary actions, and a labeled More menu for rare/destructive actions. On mobile show labels for frequent actions. Standardize casing and verb choice.

**Acceptance:** A user can identify the primary action and understand mobile actions before tapping; destructive choices are visually distinct and secondary.

Evidence: [src/contacts.php:383](/Users/dgilmore/DNR/src/contacts.php:383), [src/tasks.php:513](/Users/dgilmore/DNR/src/tasks.php:513), [src/assets/css/modern.css:5615](/Users/dgilmore/DNR/src/assets/css/modern.css:5615), [src/inquiries.php:279](/Users/dgilmore/DNR/src/inquiries.php:279), [src/tasks.php:387](/Users/dgilmore/DNR/src/tasks.php:387).

### 25. Stop validating a disabled digest schedule

**P2 · Forms · Effort S · Source confirmed**

Unchecking Daily work digest leaves schedule inputs active. Client and server still require a valid day selection.

**User impact:** A feature the user turned off can block an unrelated profile save.

**Recommendation:** Disable/collapse schedule controls while off, preserve the previous schedule, and validate it when delivery is enabled.

**Acceptance:** Clear days, turn the digest off, and save successfully; re-enabling requires a valid schedule.

Evidence: [src/assets/js/profile.js:17](/Users/dgilmore/DNR/src/assets/js/profile.js:17), [src/profile.php:128](/Users/dgilmore/DNR/src/profile.php:128), [src/profile.php:397](/Users/dgilmore/DNR/src/profile.php:397).

### 26. Make My work versus Everyone’s work explicit

**P2 · Navigation · Effort M · Observed + source**

Work Queue shows My Reminders beside larger summary cards with similar labels but broader counts. In the observed default view, Next 7 days was 0 in one and 2 in the other. Source also shows that Today/Upcoming/Waiting cards keep global counts with owner=me while their result lists honor that personal filter. A personal-scope explanation already exists below the controls.

**User impact:** Counts can look contradictory and, in personal scope, some summary cards can disagree with their own destination lists.

**Recommendation:** Use one visible scope selector—Mine / Everyone / Unassigned—and one corresponding set of due/status filters. Show the active owner scope in the result heading and preserve it through navigation.

**Acceptance:** All visible counts and destination lists use the selected scope; changing views never silently widens Mine to Everyone.

Evidence: [src/tasks.php:153](/Users/dgilmore/DNR/src/tasks.php:153), [src/tasks.php:221](/Users/dgilmore/DNR/src/tasks.php:221), [src/tasks.php:398](/Users/dgilmore/DNR/src/tasks.php:398), [src/tasks.php:417](/Users/dgilmore/DNR/src/tasks.php:417), [src/tasks.php:449](/Users/dgilmore/DNR/src/tasks.php:449).

### 27. Use one readable date and time convention

**P2 · Language · Effort S · Observed + source**

The walkthrough showed dotted dates in engagement lists, ISO due dates in engagement details, abbreviated dates in inquiries, and raw UTC stage-history timestamps. Inquiry Created extracts the UTC date without local conversion, while nearby Chron entries use local time.

**User impact:** Users must decode formats, and a record can appear to have been created on a different day from its local activity.

**Recommendation:** Use the application timezone consistently for operational timestamps, with timezone labels and exact timestamps on demand. Use a shared readable date-range formatter; retain event-local scheduling rules where required.

**Acceptance:** A record created near local midnight has consistent local dates across overview and history; event dates are never shifted by timestamp conversion.

Evidence: [src/view_inquiry.php:224](/Users/dgilmore/DNR/src/view_inquiry.php:224), [src/view_inquiry.php:299](/Users/dgilmore/DNR/src/view_inquiry.php:299), [src/engagements.php:269](/Users/dgilmore/DNR/src/engagements.php:269), [src/view_engagement.php:713](/Users/dgilmore/DNR/src/view_engagement.php:713), [src/chron_log_helpers.php:14](/Users/dgilmore/DNR/src/chron_log_helpers.php:14).

### 28. Keep stage and status models consistent between views

**P2 · Workflow · Effort M · Observed structure + source**

The inquiry detail shows Qualified as a distinct step, but the default board groups Qualified into Awaiting Details. The engagement progress strip combines confirmation with completion despite the separate confirmation/lifecycle badges above it. Postponed/canceled events already receive an explicit exception treatment.

**User impact:** An inquiry can appear under a different stage name between views. The engagement strip asks users to reconcile a linear progress display with two independent status concepts.

**Recommendation:** Either show Qualified as its own board column or clearly label the grouped column. Keep the engagement progress strip aligned with its existing two-part confirmation/lifecycle model, including the current exception treatment.

**Acceptance:** The board and detail communicate the same current stage. Postponed/canceled events retain understandable confirmation and lifecycle labels.

Evidence: [src/inquiries.php:236](/Users/dgilmore/DNR/src/inquiries.php:236), [src/view_inquiry.php:208](/Users/dgilmore/DNR/src/view_inquiry.php:208), [src/engagement_view_helpers.php:15](/Users/dgilmore/DNR/src/engagement_view_helpers.php:15), [src/view_engagement.php:320](/Users/dgilmore/DNR/src/view_engagement.php:320).

## Validation plan for implementation

Use a disposable environment with synthetic records for mutation tests. Keep the current production data unchanged during the review.

- **Booking:** capture an inquiry, add a next action and several tasks, select none/some/all at conversion, and confirm exactly what carries forward. Include conflict review, validation errors, and read-only booked history.
- **Record continuity:** from filtered page 2, open a record, add a Chron Log Entry, edit a section, save/cancel, and return. Confirm draft preservation when creating missing related records.
- **Task selection:** type unrelated and zero-result queries, clear search, and simulate a failed request; the selected relationship must remain unchanged until explicitly replaced.
- **Mail:** seed over 100 unresolved messages; find the oldest, read it, select a destination, and verify processing/error feedback and the next triage step.
- **Closeout:** include completed, canceled, undated, and later tasks, plus partial receipts; evaluate the agreed policy and distinguish unknown values from confirmed zero.
- **Keyboard/mobile:** test 320px, 390px, 860px, desktop, and 200% zoom. Check focus order, mobile drawer open/close, skip link, selectors, tab panels, dialogs, action labels, and unintended horizontal overflow.
- **Forms:** verify accessible labels, focused summaries, linked inline errors, value retention, duplicate-submit prevention, and a disabled digest schedule.
- **Readability:** measure real text/background combinations in light/dark themes and all relevant states. Check date formatting near local midnight and exact event date preservation.
- **Roles:** repeat representative paths as administrator, editor, and reviewer; verify hidden write controls and clear read-only presentation.
- **Assistive technology:** test navigation, selectors, forms, and record tables with VoiceOver/Safari and NVDA/Chrome before describing the accessibility work as complete.

Use five representative user tasks to compare before and after: identify the next action, capture an incomplete inquiry, log a conversation, find a host for an upcoming event, and finalize a closeout. Record unassisted completion, wrong turns, time to first correct action, and lost/re-entered inputs. Establish a baseline before setting numerical improvement targets.

## Standards used

Recommendations for modal focus containment and restoration follow the [W3C dialog pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/). Form error recommendations follow [W3C user-notification guidance](https://www.w3.org/WAI/tutorials/forms/notifications/). Searchable selectors should use an established interaction pattern such as the [W3C combobox pattern](https://www.w3.org/WAI/ARIA/apg/patterns/combobox/). Ordinary informative text should reach 4.5:1 contrast under [W3C contrast guidance](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html). This review does not constitute a WCAG conformance claim.
