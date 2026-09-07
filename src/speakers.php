<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/speaker_helpers.php';
require_once __DIR__ . '/record_workspace_helpers.php';
$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();
$can_manage_speakers = hasRole(['admin', 'editor']);
generateCsrfToken();
releaseApplicationSessionLock();
$page_size = paginationPageSizePreference('speakers', $_GET['per_page'] ?? null, 20, [20, 50, 100]);
$search = \Dnr\Http\RequestInput::string($_GET, 'q', '', 255);
$name_sort = \Dnr\Http\RequestInput::enum($_GET, 'name_sort', ['asc', 'desc'], 'asc');
$direction = $name_sort === 'desc' ? 'DESC' : 'ASC';
$from = 'FROM speakers WHERE LOCATE(?, name) > 0 OR LOCATE(?, email) > 0 OR LOCATE(?, phone) > 0';
$pagination = queryPagination($conn, $from, 'sss', [$search, $search, $search], $page_size, $_GET['page'] ?? null);
$total_speakers = $pagination['total'];
$current_page = $pagination['page'];
$offset = $pagination['offset'];
$stmt = $conn->prepare("SELECT id, name, email, phone, photo_mime, version {$from} ORDER BY name {$direction}, id {$direction} LIMIT ? OFFSET ?");
$stmt->bind_param('sssii', $search, $search, $search, $page_size, $offset);
$stmt->execute();
$speakers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$list_current_url = 'speakers.php?' . http_build_query(['page' => $current_page, 'per_page' => $page_size,
    'name_sort' => $name_sort, 'q' => $search]);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Speakers'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/contacts.min.css', 'assets/css/pages/record_workspace.min.css', 'assets/css/pages/speakers.min.css']]); ?>
<body class="contacts-body">
<?php include 'templates/header.php'; ?>
<main class="container contacts-page">
    <div class="page-heading contacts-heading">
        <div><h1>Speakers</h1><p class="page-intro">Find the speakers connected to your presentations.</p></div>
        <?php if ($can_manage_speakers): ?><a class="button-add" href="edit_speaker.php?return_to=<?php echo urlencode($list_current_url); ?>">+ New Speaker</a><?php endif; ?>
    </div>
    <div class="list-controls">
        <form method="get" action="speakers.php" class="list-search-form" role="search">
            <input type="hidden" name="per_page" value="<?php echo $page_size; ?>">
            <input type="hidden" name="name_sort" value="<?php echo $name_sort; ?>">
            <label class="visually-hidden" for="speaker-search">Search speakers</label>
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="speaker-search" name="q" maxlength="255" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search speakers">
            <?php if ($search !== ''): ?><a class="clear-search" href="<?php echo htmlspecialchars(recordUrlWithQuery($list_current_url, ['q' => null, 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Speaker sort order">
            <span class="control-label">Sort:</span>
            <div class="sort-buttons">
                <a class="sort-button sort-selection active" aria-current="true" href="<?php echo htmlspecialchars(recordUrlWithQuery($list_current_url, ['page' => 1, 'name_sort' => $name_sort === 'asc' ? 'desc' : 'asc']), ENT_QUOTES, 'UTF-8'); ?>">Name <?php echo $name_sort === 'asc' ? '↑' : '↓'; ?></a>
            </div>
        </div>
    </div>
    <?php if ($search !== ''): ?><p class="result-context">Showing speakers matching “<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>”.</p><?php endif; ?>
    <?php renderPagination($total_speakers, $current_page, $page_size, $list_current_url, 'speakers', 'Speaker pages'); ?>
    <div class="contact-table-wrapper">
        <table class="contact-table speaker-table data-table">
            <thead><tr><th scope="col">Speaker</th><th scope="col">Phone number</th><th scope="col">Email address</th><th scope="col">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($speakers as $speaker): ?>
                <tr>
                    <td><span class="contact-name-cell">
                        <?php if (!empty($speaker['photo_mime'])): ?><img class="contact-list-avatar" src="speaker_photo.php?id=<?php echo (int) $speaker['id']; ?>&amp;v=<?php echo (int) $speaker['version']; ?>" alt="" loading="lazy" width="40" height="40">
                        <?php else: ?><span class="contact-list-avatar" aria-hidden="true"><?php echo htmlspecialchars(speakerInitials($speaker), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                        <a class="record-link" href="view_speaker.php?id=<?php echo (int) $speaker['id']; ?>&amp;return_to=<?php echo urlencode($list_current_url); ?>"><?php echo htmlspecialchars($speaker['name'], ENT_QUOTES, 'UTF-8'); ?></a>
                    </span></td>
                    <td><a class="contact-phone-link" href="tel:<?php echo htmlspecialchars($speaker['phone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(formatPhoneNumberForDisplay($speaker['phone']), ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td><a href="mailto:<?php echo htmlspecialchars($speaker['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($speaker['email'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td><div class="action-buttons">
                        <a href="view_speaker.php?id=<?php echo (int) $speaker['id']; ?>&amp;return_to=<?php echo urlencode($list_current_url); ?>" class="action-button action-icon-button view-button" aria-label="View speaker" title="View" data-tooltip="View"><?php echo actionIconSvg('view'); ?></a>
                        <?php if ($can_manage_speakers): ?><a href="edit_speaker.php?id=<?php echo (int) $speaker['id']; ?>&amp;return_to=<?php echo urlencode($list_current_url); ?>" class="action-button action-icon-button edit-button" aria-label="Edit speaker" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($speakers === []): ?><tr><td colspan="4" class="empty-state">No speakers match the current view.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php renderPagination($total_speakers, $current_page, $page_size, $list_current_url, 'speakers', 'Speaker pages'); ?>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
