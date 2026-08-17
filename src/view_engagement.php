<?php
include 'config.php';
include 'functions.php';
include 'engagement_export_helpers.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Check if ID is provided and is numeric
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: engagements.php");
    exit();
}

$engagement_id = intval($_GET['id']);

// Fetch engagement details with organization name and contacts
$query = "SELECT e.*, o.organization_name, o.id as org_id
          FROM engagements e
          LEFT JOIN organizations o ON e.organization_id = o.id
          WHERE e.id = ?";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $engagement_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: engagements.php");
    exit();
}

$engagement = $result->fetch_assoc();
$is_archived = !empty($engagement['is_deleted']);

// Fetch contacts for the organization
$contact_query = "SELECT * FROM contacts
                  WHERE organization_id = ? AND is_deleted = 0
                  ORDER BY contact_last_name, contact_first_name";
$contact_stmt = $conn->prepare($contact_query);
if ($contact_stmt === false) {
    die("Error preparing contacts statement: " . $conn->error);
}

$contact_stmt->bind_param("i", $engagement['org_id']);
$contact_stmt->execute();
$contacts_result = $contact_stmt->get_result();
$contacts = $contacts_result->fetch_all(MYSQLI_ASSOC);

// Fetch presentations associated with this engagement.
$presentation_stmt = $conn->prepare(
    "SELECT topic_title, presentation_date, presentation_time, speaker_name, expected_attendance
     FROM presentations
     WHERE engagement_id = ?
     ORDER BY presentation_date, presentation_time, id"
);
if ($presentation_stmt === false) {
    die("Error preparing presentations statement: " . $conn->error);
}
$presentation_stmt->bind_param("i", $engagement_id);
$presentation_stmt->execute();
$presentations_result = $presentation_stmt->get_result();
$presentations = $presentations_result->fetch_all(MYSQLI_ASSOC);

$event_address_parts = [];
foreach (['event_address_line_1', 'event_address_line_2'] as $address_field) {
    if (!empty($engagement[$address_field])) {
        $event_address_parts[] = $engagement[$address_field];
    }
}
$event_city_line = trim(implode(', ', array_filter([
    $engagement['event_city'] ?? '',
    $engagement['event_state'] ?? ''
])));
if (!empty($engagement['event_zipcode'])) {
    $event_city_line = trim($event_city_line . ' ' . $engagement['event_zipcode']);
}
if ($event_city_line !== '') {
    $event_address_parts[] = $event_city_line;
}
if (!empty($engagement['event_country'])) {
    $event_address_parts[] = $engagement['event_country'];
}

$engagement_export = buildEngagementExport($engagement, $contacts, $presentations);
$engagement_plain_text = renderEngagementPlainText($engagement_export);
$engagement_markdown = renderEngagementMarkdown($engagement_export);

// Close statements
$stmt->close();
$contact_stmt->close();
$presentation_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View Engagement - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.15">
    <style>
        .view-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .detail-group {
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 15px;
        }
        .detail-group:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: var(--font-weight-bold);
            margin-bottom: 5px;
            color: var(--text-color);
        }
        .detail-value {
            margin-bottom: 10px;
            color: var(--text-color);
        }
        .action-buttons {
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .primary-actions,
        .export-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .export-actions {
            margin-left: auto;
        }
        .action-button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: white;
        }
        .back-button {
            background-color: var(--button-neutral-color);
        }
        .back-button:hover {
            background-color: var(--button-hover-color);
        }
        .edit-button {
            background-color: var(--button-edit-color);
        }
        .edit-button:hover {
            background-color: var(--button-hover-color);
        }
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        /* Contact styles */
        .contacts-list {
            margin-top: 10px;
            margin-bottom: 20px;
        }
        .contact-item {
            background-color: var(--light-bg-color);
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .presentation-item {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .dark-mode .presentation-item {
            border-color: #444;
        }
        .dark-mode .contact-item {
            background-color: var(--dark-input-bg);
            border-color: #333;
        }
        .contact-title {
            color: #666;
            font-style: italic;
            margin: 5px 0;
        }
        .dark-mode .contact-title {
            color: #aaa;
        }
        .contact-notes {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #eee;
            font-size: var(--font-size-small);
        }
        .dark-mode .contact-notes {
            border-top-color: #333;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="view-container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="engagements.php<?php echo $is_archived ? '?status=archived' : ''; ?>">Engagements</a><span aria-hidden="true">/</span><span>Engagement details</span></nav>
    <div class="page-heading record-page-heading">
        <div><h1><?php echo htmlspecialchars($engagement['event_title'] ?: $engagement['organization_name']); ?><?php if ($is_archived): ?><span class="archive-status">Archived</span><?php endif; ?></h1><p class="page-intro"><?php echo htmlspecialchars($engagement['organization_name']); ?> · <?php echo htmlspecialchars(ucwords($engagement['event_type'])); ?></p></div>
        <?php if (!$is_archived && ($user_role === 'admin' || $user_role === 'editor')): ?><a href="edit_engagement.php?id=<?php echo $engagement_id; ?>" class="button-add">Edit engagement</a><?php endif; ?>
    </div>

    <div class="detail-group">
        <?php if (!empty($engagement['event_title'])): ?>
        <div class="detail-label">Event Title</div>
        <div class="detail-value"><?php echo htmlspecialchars($engagement['event_title']); ?></div>
        <?php endif; ?>

        <div class="detail-label">Organization</div>
        <div class="detail-value"><?php echo htmlspecialchars($engagement['organization_name']); ?></div>

        <?php if ($contacts): ?>
        <div class="detail-label">Contacts</div>
        <div class="detail-value contacts-list">
            <?php foreach ($contacts as $contact): ?>
            <div class="contact-item">
                <div><strong><?php echo htmlspecialchars(
                    trim($contact['contact_first_name'] . ' ' . $contact['contact_last_name']),
                    ENT_QUOTES,
                    'UTF-8'
                ); ?></strong></div>
                <?php if (!empty($contact['contact_role'])): ?>
                <div class="contact-title">
                    <?php
                    echo htmlspecialchars(
                        $contact['contact_role'] === 'other' && !empty($contact['contact_role_other'])
                        ? $contact['contact_role_other']
                        : ucfirst($contact['contact_role'])
                    );
                    ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($contact['contact_email'])): ?>
                <div>Email: <a href="mailto:<?php echo htmlspecialchars($contact['contact_email']); ?>"><?php echo htmlspecialchars($contact['contact_email']); ?></a></div>
                <?php endif; ?>
                <?php if (!empty($contact['contact_phone'])): ?>
                <div>Phone: <?php echo htmlspecialchars($contact['contact_phone']); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="detail-label">Event Type</div>
        <div class="detail-value"><?php echo htmlspecialchars($engagement['event_type']); ?></div>

        <div class="detail-label">Event Dates</div>
        <div class="detail-value">
            <?php echo htmlspecialchars($engagement['event_start_date'] . ' to ' . $engagement['event_end_date']); ?>
        </div>
    </div>

    <div class="detail-group">
        <div class="detail-label">Status</div>
        <div class="detail-value">
            <?php
            $status = $engagement['confirmation_status'];
            $status_class = 'status-' . str_replace('_', '-', $status);
            $display_status = str_replace('_', ' ', $status);
            echo "<span class='{$status_class}'>" . htmlspecialchars($display_status) . "</span>";
            ?>
        </div>
    </div>

    <?php if (!empty($engagement['engagement_notes'])): ?>
    <div class="detail-group">
        <div class="detail-label">Chron</div>
        <div class="detail-value"><?php echo nl2br(htmlspecialchars($engagement['engagement_notes'])); ?></div>
    </div>
    <?php endif; ?>

    <div class="detail-group">
        <div class="detail-label">Event Details</div>
        <div class="detail-value">
            <div><strong>Book Table Provided:</strong> <?php echo !empty($engagement['book_table']) ? 'Yes' : 'No'; ?></div>
            <div><strong>Brochures Permitted:</strong> <?php echo !empty($engagement['brochures']) ? 'Yes' : 'No'; ?></div>
            <div><strong>All Travel Covered:</strong> <?php echo htmlspecialchars(ucfirst($engagement['travel_covered'] ?? 'unknown')); ?></div>
            <?php if (!empty($engagement['caller_name'])): ?>
            <div><strong>Caller:</strong> <?php echo htmlspecialchars($engagement['caller_name']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($presentations): ?>
    <div class="detail-group">
        <div class="detail-label">Presentations</div>
        <div class="detail-value">
            <?php foreach ($presentations as $presentation): ?>
            <div class="presentation-item">
                <strong><?php echo htmlspecialchars($presentation['topic_title']); ?></strong>
                <?php if (!empty($presentation['speaker_name'])): ?>
                <div>Speaker: <?php echo htmlspecialchars($presentation['speaker_name']); ?></div>
                <?php endif; ?>
                <?php if (!empty($presentation['presentation_date']) || !empty($presentation['presentation_time'])): ?>
                <div>
                    <?php echo htmlspecialchars(trim(($presentation['presentation_date'] ?? '') . ' ' . ($presentation['presentation_time'] ?? ''))); ?>
                </div>
                <?php endif; ?>
                <?php if ($presentation['expected_attendance'] !== null): ?>
                <div>Expected attendance: <?php echo (int) $presentation['expected_attendance']; ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($engagement['compensation_type'])): ?>
    <div class="detail-group">
        <div class="detail-label">Compensation</div>
        <div class="detail-value">
            <div><strong>Type:</strong> <?php echo htmlspecialchars($engagement['compensation_type']); ?></div>
            <?php if (!empty($engagement['other_compensation'])): ?>
            <div><strong>Details:</strong> <?php echo htmlspecialchars($engagement['other_compensation']); ?></div>
            <?php endif; ?>
            <?php if (!empty($engagement['travel_amount'])): ?>
            <div><strong>Travel Amount:</strong> $<?php echo number_format((float) $engagement['travel_amount'], 2); ?></div>
            <?php endif; ?>
            <?php if (!empty($engagement['housing_amount'])): ?>
            <div><strong>Lodging Amount:</strong> $<?php echo number_format((float) $engagement['housing_amount'], 2); ?></div>
            <?php endif; ?>
            <?php if (!empty($engagement['housing_type'])): ?>
            <div><strong>Lodging Type:</strong> <?php echo htmlspecialchars($engagement['housing_type']); ?></div>
            <?php endif; ?>
            <?php if (!empty($engagement['other_housing'])): ?>
            <div><strong>Lodging Details:</strong> <?php echo htmlspecialchars($engagement['other_housing']); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($event_address_parts)): ?>
    <div class="detail-group">
        <div class="detail-label">Location</div>
        <div class="detail-value">
            <?php echo implode('<br>', array_map('htmlspecialchars', $event_address_parts)); ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="action-buttons">
        <div class="primary-actions">
            <a href="engagements.php<?php echo $is_archived ? '?status=archived' : ''; ?>" class="action-button back-button">Back to List</a>
        </div>
        <div class="export-actions" aria-label="Export engagement">
            <button type="button" class="action-button export-button" data-copy-format="text">Copy Text</button>
            <button type="button" class="action-button export-button" data-copy-format="markdown">Copy MD</button>
            <a href="download_engagement_pdf.php?id=<?php echo $engagement_id; ?>" class="action-button export-button">Download PDF</a>
        </div>
        <span id="copy-status" class="visually-hidden" role="status" aria-live="polite"></span>
    </div>
</div>
<script>
(function () {
    const engagementExports = <?php echo json_encode([
        'text' => $engagement_plain_text,
        'markdown' => $engagement_markdown,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const status = document.getElementById('copy-status');

    function copyWithFallback(value) {
        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        const copied = document.execCommand('copy');
        textarea.remove();
        if (!copied) {
            throw new Error('The browser declined the copy command.');
        }
    }

    async function copyEngagement(button) {
        const format = button.dataset.copyFormat;
        const value = engagementExports[format];
        const originalLabel = button.textContent;

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
            } else {
                copyWithFallback(value);
            }
            button.textContent = 'Copied!';
            status.textContent = originalLabel + ' copied to the clipboard.';
        } catch (error) {
            button.textContent = 'Copy failed';
            status.textContent = originalLabel + ' could not be copied.';
        }

        window.setTimeout(function () {
            button.textContent = originalLabel;
        }, 1800);
    }

    document.querySelectorAll('[data-copy-format]').forEach(function (button) {
        button.addEventListener('click', function () {
            copyEngagement(button);
        });
    });
}());
</script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
