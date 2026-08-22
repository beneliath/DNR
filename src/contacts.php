<?php
require_once __DIR__ . '/bootstrap.php';
include 'contact_photo_helpers.php';
include 'two_factor_helpers.php';
startSecureSession();
requireLogin();

$user_role = $_SESSION['role'] ?? '';
$allowed_page_sizes = [20, 50, 100];

$list_status = ($_POST['list_status'] ?? $_GET['status'] ?? '') === 'archived'
    ? 'archived'
    : 'active';
$show_archived = $list_status === 'archived';

// Handle archive, restore, and permanent deletion through authenticated POST requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $contact_id = filter_input(INPUT_POST, 'contact_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $action_succeeded = false;

    if (in_array($action, ['archive', 'restore'], true) && !canArchiveEntries($user_role)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($action === 'delete' && !canDeleteEntries($user_role)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($action === 'delete') {
        requireRecentAdminElevation('contacts.php?' . http_build_query(['status' => $list_status]));
    }

    if ($contact_id && $action === 'archive') {
        $action_succeeded = archiveEntity($conn, 'contact', $contact_id);
        $action_message = 'Contact archived.';
    } elseif ($contact_id && $action === 'restore') {
        $action_succeeded = restoreEntity($conn, 'contact', $contact_id);
        $action_message = 'Contact restored.';
    } elseif ($contact_id && $action === 'delete') {
        $action_succeeded = permanentlyDeleteEntity($conn, 'contact', $contact_id);
        $action_message = 'Contact permanently deleted.';
    } else {
        http_response_code(400);
        exit('Invalid contact action.');
    }

    if ($action_succeeded) {
        $_SESSION['contact_action_message'] = $action_message;
    } else {
        $_SESSION['contact_action_error'] = 'Unable to update the contact. Please try again.';
    }

    header('Location: contacts.php?' . http_build_query(['status' => $list_status]));
    exit();
}

$action_message = $_SESSION['contact_action_message'] ?? '';
$action_error = $_SESSION['contact_action_error'] ?? '';
unset($_SESSION['contact_action_message'], $_SESSION['contact_action_error']);

$requested_page_size = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
$page_size = in_array($requested_page_size, $allowed_page_sizes, true)
    ? $requested_page_size
    : 20;

$legacy_sort = $_GET['sort'] ?? '';
$last_name_sort = strtolower($_GET['last_name_sort'] ?? $legacy_sort) === 'desc' ? 'desc' : 'asc';
$organization_sort = strtolower($_GET['organization_sort'] ?? '') === 'desc' ? 'desc' : 'asc';
$sort_column = in_array($_GET['sort_by'] ?? '', ['last_name', 'organization'], true)
    ? $_GET['sort_by']
    : 'last_name';
$search = trim($_GET['q'] ?? '');
$search = trim(substr($search, 0, 256));
$fulltext_query = fulltextSearchQuery($search);
if ($fulltext_query === '') {
    $search = '';
}

if ($sort_column === 'organization') {
    $order_direction = $organization_sort === 'desc' ? 'DESC' : 'ASC';
    $order_clause = "o.organization_name {$order_direction},
                     c.contact_last_name {$order_direction},
                     c.contact_first_name {$order_direction},
                     c.id {$order_direction}";
} else {
    $order_direction = $last_name_sort === 'desc' ? 'DESC' : 'ASC';
    $order_clause = "c.contact_last_name {$order_direction},
                     c.contact_first_name {$order_direction},
                     c.id {$order_direction}";
}

$requested_page = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$requested_page = $requested_page ?: 1;

$archive_value = $show_archived ? 1 : 0;
$active_organization_filter = '';
$search_filter = $fulltext_query === ''
    ? ''
    : " AND (
        MATCH(
            c.contact_first_name, c.contact_last_name, c.contact_email,
            c.contact_phone, c.contact_role_other, c.contact_notes
        ) AGAINST (? IN BOOLEAN MODE)
        OR MATCH(
            o.organization_name, o.notes, o.affiliation, o.distinctives,
            o.email, o.phone, o.physical_city, o.physical_state,
            o.mailing_city, o.mailing_state
        ) AGAINST (? IN BOOLEAN MODE)
    )";
$count_query = "SELECT COUNT(*) AS contact_count
                FROM contacts c
                INNER JOIN organizations o ON c.organization_id = o.id
                WHERE c.is_deleted = {$archive_value}{$active_organization_filter}{$search_filter}";
$count_stmt = null;
if ($fulltext_query !== '') {
    $count_stmt = $conn->prepare($count_query);
    if (!$count_stmt) {
        abortApplication(503, 'Contacts are temporarily unavailable.', ['error' => $conn->error]);
    }
    $count_stmt->bind_param('ss', $fulltext_query, $fulltext_query);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
} else {
    $count_result = $conn->query($count_query);
}
if (!$count_result) {
    abortApplication(503, 'Contacts are temporarily unavailable.', ['error' => $conn->error]);
}

$total_contacts = (int) $count_result->fetch_assoc()['contact_count'];
if ($count_stmt) {
    $count_stmt->close();
}
$total_pages = max(1, (int) ceil($total_contacts / $page_size));
$current_page = min($requested_page, $total_pages);
$offset = ($current_page - 1) * $page_size;

$contact_query = "SELECT
                    c.id,
                    c.organization_id,
                    c.contact_first_name,
                    c.contact_last_name,
                    c.contact_phone,
                    c.contact_email,
                    c.contact_photo_mime,
                    c.contact_photo_updated_at,
                    o.organization_name,
                    o.is_deleted AS organization_is_archived
                  FROM contacts c
                  INNER JOIN organizations o ON c.organization_id = o.id
                  WHERE c.is_deleted = {$archive_value}{$active_organization_filter}{$search_filter}
                  ORDER BY {$order_clause}
                  LIMIT ? OFFSET ?";
$contact_stmt = $conn->prepare($contact_query);
if (!$contact_stmt) {
    abortApplication(503, 'Contacts are temporarily unavailable.', ['error' => $conn->error]);
}

if ($fulltext_query !== '') {
    $contact_stmt->bind_param(
        'ssii',
        $fulltext_query,
        $fulltext_query,
        $page_size,
        $offset
    );
} else {
    $contact_stmt->bind_param('ii', $page_size, $offset);
}
if (!$contact_stmt->execute()) {
    abortApplication(503, 'Contacts are temporarily unavailable.', ['error' => $conn->error]);
}
$contacts_result = $contact_stmt->get_result();

function contactsPageUrl(
    $page,
    $page_size,
    $sort_column,
    $last_name_sort,
    $organization_sort,
    $list_status,
    $search = ''
) {
    $parameters = [
        'page' => $page,
        'per_page' => $page_size,
        'sort_by' => $sort_column,
        'last_name_sort' => $last_name_sort,
        'organization_sort' => $organization_sort,
        'status' => $list_status,
    ];
    if ($search !== '') {
        $parameters['q'] = $search;
    }
    return 'contacts.php?' . http_build_query($parameters);
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Contacts - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/contacts.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="page-heading">
        <div><h1><?php echo $show_archived ? 'Archived Contacts' : 'Contacts'; ?></h1><p class="page-intro">Find the people connected to every organization and engagement.</p></div>
        <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
            <a href="add_contact.php" class="button-add">+ New contact</a>
        <?php endif; ?>
    </div>

    <?php if ($action_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($action_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="list-controls">
        <form method="get" action="contacts.php" class="list-search-form" role="search">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($list_status, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="per_page" value="<?php echo $page_size; ?>">
            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_column, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="last_name_sort" value="<?php echo htmlspecialchars($last_name_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="organization_sort" value="<?php echo htmlspecialchars($organization_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="visually-hidden" for="contact-search">Search contacts</label>
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="contact-search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search contacts">
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars(contactsPageUrl(1, $page_size, $sort_column, $last_name_sort, $organization_sort, $list_status), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Contact archive status">
            <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $page_size, $sort_column, $last_name_sort, $organization_sort, 'active', $search), ENT_QUOTES, 'UTF-8'); ?>"
               class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Active</a>
            <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $page_size, $sort_column, $last_name_sort, $organization_sort, 'archived', $search), ENT_QUOTES, 'UTF-8'); ?>"
               class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived</a>
        </div>

        <div class="control-group" aria-label="Contact sort order">
            <span class="control-label">Sort:</span>
            <div class="sort-buttons">
                <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $page_size, 'last_name', $last_name_sort === 'asc' ? 'desc' : 'asc', $organization_sort, $list_status, $search), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button sort-selection<?php echo $sort_column === 'last_name' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'last_name' ? 'aria-current="true"' : ''; ?>>
                    Last Name <?php echo $last_name_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
                <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $page_size, 'organization', $last_name_sort, $organization_sort === 'asc' ? 'desc' : 'asc', $list_status, $search), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button sort-selection<?php echo $sort_column === 'organization' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'organization' ? 'aria-current="true"' : ''; ?>>
                    Organization <?php echo $organization_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
            </div>
        </div>

    </div>

    <?php if ($search !== ''): ?>
        <p class="result-context"><?php echo $total_contacts; ?> result<?php echo $total_contacts === 1 ? '' : 's'; ?> for “<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>”.</p>
    <?php endif; ?>

    <div class="contact-table-wrapper">
        <table class="contact-table">
            <thead>
                <tr>
                    <th>Contact</th>
                    <th>Organization</th>
                    <th>Phone number</th>
                    <th>Email address</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($contacts_result->num_rows === 0): ?>
                    <tr>
                        <td colspan="5" class="empty-state">No contacts match the current view.</td>
                    </tr>
                <?php else: ?>
                    <?php while ($contact = $contacts_result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <span class="contact-name-cell">
                                    <?php if (!empty($contact['contact_photo_mime'])): ?>
                                        <img class="contact-list-avatar" src="contact_photo.php?id=<?php echo (int) $contact['id']; ?>&amp;v=<?php echo strtotime((string) ($contact['contact_photo_updated_at'] ?? '')) ?: 0; ?>" alt="">
                                    <?php else: ?>
                                        <span class="contact-list-avatar" aria-hidden="true"><?php echo htmlspecialchars(contactInitials($contact), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                    <a class="record-link" href="view_contact.php?id=<?php echo (int) $contact['id']; ?>"><?php echo htmlspecialchars(
                                        $contact['contact_last_name'] . ', ' . $contact['contact_first_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?></a>
                                </span>
                            </td>
                            <td>
                                <a href="view_organization.php?id=<?php echo (int) $contact['organization_id']; ?>">
                                    <?php echo htmlspecialchars($contact['organization_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php if (!empty($contact['organization_is_archived'])): ?>
                                    <span class="archive-status">Archived</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($contact['contact_phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($contact['contact_phone'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars(formatPhoneNumberForDisplay($contact['contact_phone']), ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($contact['contact_email'])): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view_contact.php?id=<?php echo (int) $contact['id']; ?>" class="action-button action-icon-button view-button" aria-label="View contact" title="View" data-tooltip="View"><?php echo actionIconSvg('view'); ?></a>
                                    <?php if (!$show_archived && empty($contact['organization_is_archived']) && ($user_role === 'admin' || $user_role === 'editor')): ?>
                                        <a href="edit_contact.php?id=<?php echo (int) $contact['id']; ?>" class="action-button action-icon-button edit-button" aria-label="Edit contact" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                                    <?php endif; ?>
                                    <?php if (canArchiveEntries($user_role)): ?>
                                        <?php if ($show_archived): ?>
                                            <form method="post" action="contacts.php">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="contact_id" value="<?php echo (int) $contact['id']; ?>">
                                                <input type="hidden" name="list_status" value="archived">
                                                <input type="hidden" name="action" value="restore">
                                                <button type="submit" class="action-button action-icon-button restore-button" aria-label="Restore contact" title="Restore" data-tooltip="Restore"><?php echo actionIconSvg('restore'); ?></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="contacts.php">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="contact_id" value="<?php echo (int) $contact['id']; ?>">
                                                <input type="hidden" name="list_status" value="active">
                                                <input type="hidden" name="action" value="archive">
                                                <button type="submit" class="action-button action-icon-button archive-button" aria-label="Archive contact" title="Archive" data-tooltip="Archive"><?php echo actionIconSvg('archive'); ?></button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (canDeleteEntries($user_role)): ?>
                                        <form method="post" action="contacts.php"
                                              data-delete-confirmation="Permanently delete this contact?"
                                              <?php if ($show_archived): ?>data-archive-button-label="Keep archived"<?php else: ?>data-archive-action="archive"<?php endif; ?>>
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="contact_id" value="<?php echo (int) $contact['id']; ?>">
                                            <input type="hidden" name="list_status" value="<?php echo $list_status; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete contact" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_contacts > 0): ?>
        <nav class="pagination pagination-with-size" aria-label="Contact pages">
            <div class="page-size-selector" aria-label="Contacts per page">
                <span class="page-size-label">Rows per page:</span>
                <?php foreach ($allowed_page_sizes as $allowed_page_size): ?>
                    <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $allowed_page_size, $sort_column, $last_name_sort, $organization_sort, $list_status, $search), ENT_QUOTES, 'UTF-8'); ?>"
                       class="sort-button page-size-button<?php echo $page_size === $allowed_page_size ? ' active' : ''; ?>"
                       <?php echo $page_size === $allowed_page_size ? 'aria-current="true"' : ''; ?>><?php echo $allowed_page_size; ?></a>
                <?php endforeach; ?>
            </div>
            <span class="pagination-status">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> · <?php echo $total_contacts; ?> contacts</span>
            <div class="pagination-actions">
                <?php if ($current_page > 1): ?>
                    <a href="<?php echo htmlspecialchars(contactsPageUrl($current_page - 1, $page_size, $sort_column, $last_name_sort, $organization_sort, $list_status, $search), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button">Previous</a>
                <?php endif; ?>
                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo htmlspecialchars(contactsPageUrl($current_page + 1, $page_size, $sort_column, $last_name_sort, $organization_sort, $list_status, $search), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button">Next</a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
<?php $contact_stmt->close(); ?>
