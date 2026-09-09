<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/short_link_helpers.php';
startSecureSession();
requireLogin();
if (!hasRole(['admin', 'editor', 'reviewer'])) { http_response_code(403); exit; }
$conn = applicationDatabaseConnection();
$canEdit = hasRole(['admin', 'editor']);
$error = '';
$message = '';
$filters = [];
$where = ['1 = 1'];
foreach (['engagement_id', 'presentation_id', 'speaker_id', 'id'] as $column) {
    $id = \Dnr\Http\RequestInput::positiveInt($_GET, $column);
    if ($id !== null) { $filters[$column] = $id; $where[] = 'l.' . $column . ' = ' . $id; }
    elseif (isset($_GET[$column]) && $_GET[$column] !== '') { http_response_code(400); exit('Invalid filter.'); }
}
$type = \Dnr\Http\RequestInput::string($_GET, 'type');
if ($type !== '') {
    if (!isset(SHORT_LINK_TYPES[$type])) { http_response_code(400); exit('Invalid link type.'); }
    $where[] = "l.link_type = '" . $type . "'";
    $filters['type'] = $type;
}
$from = \Dnr\Http\RequestInput::string($_GET, 'from', gmdate('Y-m-d', strtotime('-29 days')));
$to = \Dnr\Http\RequestInput::string($_GET, 'to', gmdate('Y-m-d'));
try { [$start, $end] = shortLinkStatsDates($from, $to); }
catch (InvalidArgumentException $exception) { http_response_code(400); exit(htmlspecialchars($exception->getMessage())); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('Forbidden.'); }
    requireValidCsrfToken();
    try {
        if (isset($_POST['generate']) && isset($filters['presentation_id'])) {
            $conn->begin_transaction();
            $stmt = $conn->prepare('SELECT id FROM presentations WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $filters['presentation_id']);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) throw new InvalidArgumentException('Presentation not found.');
            ensurePresentationShortLinks($conn, $filters['presentation_id']);
            $conn->commit();
        } else {
            $id = \Dnr\Http\RequestInput::positiveInt($_POST, 'link_id');
            $version = \Dnr\Http\RequestInput::positiveInt($_POST, 'version');
            if ($id === null || $version === null) throw new InvalidArgumentException('Invalid link.');
            updateShortLink($conn, $id, $version, \Dnr\Http\RequestInput::string($_POST, 'target_url'), isset($_POST['is_enabled']));
        }
        header('Location: short_links.php?' . http_build_query($filters + ['from' => $from, 'to' => $to, 'saved' => 1]));
        exit;
    } catch (Throwable $exception) {
        $conn->rollback();
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to save this link. Try again.';
        if (!$exception instanceof InvalidArgumentException) applicationLog('error', 'Short link update failed', ['error' => $exception->getMessage()]);
    }
}
if (isset($_GET['saved'])) $message = 'Links saved. Existing QR codes remain valid.';
if (isset($filters['presentation_id']) && ($_SESSION['_presentation_stats_reset'] ?? null) === $filters['presentation_id']) {
    $message = 'Presentation statistics reset to zero. New visits will be counted from now on.';
    unset($_SESSION['_presentation_stats_reset']);
}
$whereSql = implode(' AND ', $where);
$stats = shortLinkStats($conn, $whereSql, $start, $end);
$report = shortLinkReportData($stats, $start, $end);
$isSingleLink = isset($filters['id']);
$reportTitle = $isSingleLink ? 'QR Code Statistics' : (isset($filters['presentation_id']) ? 'Presentation Statistics' : (isset($filters['engagement_id']) ? 'Event Statistics' : (isset($filters['speaker_id']) ? 'Speaker Statistics' : 'QR Codes & Statistics')));
$showPresentationLinks = $reportTitle !== 'Speaker Statistics';
$page = $isSingleLink ? 1 : max(1, min(1000000, (int) (\Dnr\Http\RequestInput::positiveInt($_GET, 'page') ?? 1)));
$offset = ($page - 1) * 50;
$linkCount = (int) $conn->query("SELECT COUNT(*) AS total FROM short_links l WHERE $whereSql")->fetch_assoc()['total'];
$links = [];
if ($showPresentationLinks) {
    $stmt = $conn->prepare("SELECT l.*, q.encoded_url AS qr_url, q.png AS qr_png,
    s.name AS speaker_name, p.topic_title, p.speaker_id AS current_speaker_id,
    e.event_title, (SELECT COALESCE(SUM(v.visits), 0) FROM short_link_stats v
        WHERE v.link_id = l.id AND v.visit_hour >= ? AND v.visit_hour < ?) AS visits,
    EXISTS(SELECT 1 FROM presentation_notes n WHERE n.presentation_id = l.presentation_id AND n.speaker_id = l.speaker_id AND n.pdf IS NOT NULL) AS has_notes
    FROM short_links l JOIN speakers s ON s.id = l.speaker_id JOIN presentations p ON p.id = l.presentation_id
    JOIN engagements e ON e.id = l.engagement_id LEFT JOIN short_link_qr_images q ON q.link_id = l.id
    WHERE $whereSql ORDER BY l.engagement_id DESC, l.presentation_id DESC, l.id LIMIT 50 OFFSET $offset");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$speakers = fetchSpeakerOptions($conn);
$selectedSpeaker = isset($filters['speaker_id']) ? fetchSpeaker($conn, $filters['speaker_id']) : null;
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$activeLink = $isSingleLink ? ($links[0] ?? null) : null;
$clearFiltersUrl = 'short_links.php' . ($isSingleLink ? '?id=' . $filters['id'] : '');
$context = '';
if ($activeLink !== null) {
    $reportTitle = shortLinkLabel($activeLink) . ' QR Code Statistics';
    $context = ($activeLink['topic_title'] ?: 'Untitled presentation') . ' · ' . $activeLink['speaker_name'];
} elseif (isset($filters['presentation_id'])) {
    $stmt = $conn->prepare('SELECT topic_title FROM presentations WHERE id = ?');
    $stmt->bind_param('i', $filters['presentation_id']); $stmt->execute();
    $context = (string) ($stmt->get_result()->fetch_assoc()['topic_title'] ?? '');
} elseif (isset($filters['engagement_id'])) {
    $stmt = $conn->prepare('SELECT event_title FROM engagements WHERE id = ?');
    $stmt->bind_param('i', $filters['engagement_id']); $stmt->execute();
    $context = (string) ($stmt->get_result()->fetch_assoc()['event_title'] ?? '');
} elseif (isset($filters['speaker_id'])) {
    $context = (string) ($speakers[$filters['speaker_id']]['name'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle($reportTitle), [
    'styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/short_links.min.css'],
    'scripts' => ['assets/js/short-link-stats.min.js'],
]); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container short-links-page">
    <?php if ($reportTitle === 'Speaker Statistics'): ?>
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="speakers.php">Speakers</a><span aria-hidden="true">/</span>
            <?php if ($context !== ''): ?>
                <a href="view_speaker.php?id=<?php echo (int) $filters['speaker_id']; ?>"><?php echo $h($context); ?></a><span aria-hidden="true">/</span>
            <?php endif; ?>
            <span aria-current="page">Speaker Statistics</span>
        </nav>
    <?php elseif ($activeLink !== null): ?>
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="engagements.php">Engagements</a><span aria-hidden="true">/</span>
            <a href="view_engagement.php?id=<?php echo (int) $activeLink['engagement_id']; ?>"><?php echo $h($activeLink['event_title']); ?></a><span aria-hidden="true">/</span>
            <a href="view_engagement.php?id=<?php echo (int) $activeLink['engagement_id']; ?>#engagement-presentations"><?php echo $h($activeLink['topic_title'] ?: 'Untitled presentation'); ?></a><span aria-hidden="true">/</span>
            <span aria-current="page"><?php echo $h($reportTitle); ?></span>
        </nav>
    <?php endif; ?>
    <div class="page-heading"><div><h1><?php echo $h($reportTitle); ?></h1><p class="page-intro"><?php echo $context !== '' ? $h($context) . ' · ' : ''; ?>Track presentation links and share speaker resources.</p></div></div>
    <?php if ($error): ?><p class="error" role="alert"><?php echo $h($error); ?></p><?php endif; ?>
    <?php if ($message): ?><p class="success" role="status"><?php echo $h($message); ?></p><?php endif; ?>
    <form method="get" class="short-link-filters" id="short-link-filters">
        <?php foreach (['engagement_id', 'presentation_id', 'id'] as $key): ?>
            <?php if (isset($filters[$key])): ?><input type="hidden" name="<?php echo $key; ?>" value="<?php echo (int) $filters[$key]; ?>"><?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$isSingleLink): ?>
        <div><label for="speaker_filter">Speaker</label><select name="speaker_id" id="speaker_filter"><option value="">All speakers</option>
            <?php foreach ($speakers as $speaker): ?><option value="<?php echo (int) $speaker['id']; ?>" <?php echo ($filters['speaker_id'] ?? null) === (int) $speaker['id'] ? 'selected' : ''; ?>><?php echo $h($speaker['name']); ?></option><?php endforeach; ?>
        </select></div>
        <div><label for="type_filter">Link Type</label><select name="type" id="type_filter"><option value="">All types</option><?php foreach (SHORT_LINK_TYPES as $key => $label): ?><option value="<?php echo $key; ?>" <?php echo $type === $key ? 'selected' : ''; ?>><?php echo $h($label); ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div><label for="from_filter">From (UTC)</label><input type="date" id="from_filter" name="from" value="<?php echo $h($from); ?>" required></div>
        <div><label for="to_filter">Through (UTC)</label><input type="date" id="to_filter" name="to" value="<?php echo $h($to); ?>" required></div>
        <div class="short-link-filter-actions"><button type="submit" class="button-primary">Apply Filters</button><a class="button-secondary" href="<?php echo $h($clearFiltersUrl); ?>">Clear Filters</a></div>
    </form>
    <p class="field-help">Speaker Notes counts link visits before the PDF opens, including cached delivery. Direct PDF links do not add visits. Earlier totals retain their original counting method.</p>
    <section class="short-link-report" aria-label="Traffic statistics">
        <div class="stats-overview">
            <div class="stats-summary">
                <?php if ($selectedSpeaker !== null): ?>
                    <img class="stats-speaker-avatar" src="speaker_photo.php?id=<?php echo (int) $selectedSpeaker['id']; ?>&amp;v=<?php echo (int) $selectedSpeaker['version']; ?>" alt="Photo or initials for <?php echo $h($selectedSpeaker['name']); ?>" width="64" height="64">
                <?php endif; ?>
                <div class="stats-summary-copy">
                    <h2 class="stats-headline"><strong><?php echo number_format($stats['total']); ?></strong> tracked visits in the selected period</h2>
                    <p class="stats-meta"><?php echo $h($from); ?> – <?php echo $h($to); ?> · <?php echo number_format($linkCount); ?> <?php echo $linkCount === 1 ? 'link' : 'links'; ?> · Updated at <?php echo gmdate('H:i'); ?> UTC</p>
                </div>
            </div>
            <nav class="stats-periods" aria-label="Statistics date range">
                <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $days => $label): ?>
                    <?php $rangeFrom = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days')); $rangeTo = gmdate('Y-m-d'); ?>
                    <a href="short_links.php?<?php echo $h(http_build_query($filters + ['from' => $rangeFrom, 'to' => $rangeTo])); ?>" <?php echo $from === $rangeFrom && $to === $rangeTo ? 'aria-current="true"' : ''; ?>><?php echo $label; ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <p id="stats-chart-help" class="stats-screen-reader">Hover or tap to inspect visits. On a focused chart use arrow keys to explore values. Exact values are also available in each data table.</p>
        <p id="stats-chart-error" class="field-help" hidden>Charts could not load. Visit counts are available in the data tables below.</p>
        <noscript><p class="field-help">Enable JavaScript for interactive charts, or open the data tables below.</p></noscript>
        <section class="stats-timeline" aria-label="Visits over time">
            <p class="stats-chart-caption"><?php echo $report['period'] === 'month' ? 'Monthly' : 'Daily'; ?> visits · UTC<?php echo $report['period'] === 'month' ? ' · First and last months include only the selected dates' : ''; ?></p>
            <div class="stats-canvas stats-canvas-timeline" id="stats-timeline-wrap" hidden><canvas id="stats-timeline" role="img" tabindex="0" aria-label="Tracked visits over time" aria-describedby="stats-chart-help"></canvas></div>
            <?php if (!$stats['total']): ?><p class="stats-empty">No tracked visits in this period</p><?php endif; ?>
            <?php $dimension = 'timeline'; $dimensionTitle = $report['period'] === 'month' ? 'Monthly visits' : 'Daily visits'; include 'templates/short_link_stats_table.php'; ?>
        </section>
        <div class="stats-grid">
            <section class="stats-panel" aria-labelledby="stats-referrers-heading">
                <h2 id="stats-referrers-heading">Referrers</h2>
                <div class="stats-referrers" id="stats-referrer-wrap" hidden>
                    <ul class="stats-legend" id="stats-referrer-legend" aria-label="Referrer breakdown"></ul>
                    <div class="stats-canvas stats-canvas-doughnut"><canvas id="stats-referrer" role="img" tabindex="0" aria-label="Visits by referrer" aria-describedby="stats-chart-help"></canvas><div class="stats-doughnut-total" aria-hidden="true"><strong><?php echo number_format($stats['total']); ?></strong><span>visits</span></div></div>
                </div>
                <?php if (!$stats['total']): ?><p class="stats-empty">No referrer data in this period</p><?php endif; ?>
                <p class="stats-chart-caption">Leading sources · Remaining sources grouped together</p>
                <?php $dimension = 'referrer'; $dimensionTitle = 'Referrers'; include 'templates/short_link_stats_table.php'; ?>
            </section>
            <section class="stats-panel" aria-labelledby="stats-browsers-heading">
                <h2 id="stats-browsers-heading">Browsers</h2>
                <div class="stats-canvas stats-canvas-bars" id="stats-browser-wrap" hidden><canvas id="stats-browser" role="img" tabindex="0" aria-label="Visits by browser" aria-describedby="stats-chart-help"></canvas></div>
                <?php if (!$stats['total']): ?><p class="stats-empty">No browser data in this period</p><?php endif; ?>
                <?php $dimension = 'browser'; $dimensionTitle = 'Browsers'; include 'templates/short_link_stats_table.php'; ?>
            </section>
            <section class="stats-panel" aria-labelledby="stats-countries-heading">
                <h2 id="stats-countries-heading">Countries</h2>
                <div class="stats-map" id="stats-map" hidden>
                    <svg id="stats-world" viewBox="0 0 800 420" role="group" aria-label="World map of tracked visits" aria-describedby="stats-map-help"></svg>
                    <div class="stats-map-tooltip" id="stats-map-tooltip" role="status" hidden></div>
                    <div class="stats-map-scale" aria-label="Map colour intensity"><span>Fewer visits</span><span class="stats-map-scale-gradient"></span><span>More visits</span></div>
                </div>
                <p class="stats-chart-caption" id="stats-map-help">Hover, tap or focus a country for visit counts</p>
                <p class="stats-chart-caption" id="stats-unmapped"></p>
                <?php if (!$stats['total']): ?><p class="stats-empty">No country data in this period</p><?php endif; ?>
                <?php $dimension = 'country'; $dimensionTitle = 'Countries'; include 'templates/short_link_stats_table.php'; ?>
            </section>
            <section class="stats-panel" aria-labelledby="stats-os-heading">
                <h2 id="stats-os-heading">Operating Systems</h2>
                <div class="stats-canvas stats-canvas-bars" id="stats-os-wrap" hidden><canvas id="stats-os" role="img" tabindex="0" aria-label="Visits by operating system" aria-describedby="stats-chart-help"></canvas></div>
                <?php if (!$stats['total']): ?><p class="stats-empty">No operating system data in this period</p><?php endif; ?>
                <?php $dimension = 'os'; $dimensionTitle = 'Operating systems'; include 'templates/short_link_stats_table.php'; ?>
            </section>
        </div>
        <p class="stats-footnote">Visits are not unique people or verified QR scans. Known bots, prefetches and HEAD requests are excluded. Referrers may be unavailable for camera scans. Countries are approximate; unknown locations appear separately.</p>
    </section>
    <script nonce="<?php echo $h(contentSecurityPolicyNonce()); ?>" type="application/json" id="short-link-stats-data"><?php echo json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <?php if ($showPresentationLinks): ?>
    <h2><?php echo $isSingleLink ? 'QR Code Details' : 'Presentation Links'; ?></h2>
    <?php if (!$isSingleLink && $canEdit && isset($filters['presentation_id'])): ?>
        <form method="post"><?php echo csrfInput(); ?><button type="submit" class="button-primary" name="generate" value="1">Generate Missing Links</button><p class="field-help">Add links for newly populated speaker URLs. Existing destinations stay unchanged.</p></form>
    <?php endif; ?>
    <?php if (!$links): ?><p>No links match these filters. Save a presentation to generate its codes.</p><?php endif; ?>
    <div class="short-link-cards">
    <?php foreach ($links as $link): ?>
        <?php if ($link['link_type'] === 'notes' && !$link['has_notes']) continue; ?>
        <?php $id = (int) $link['id']; $label = shortLinkLabel($link); $qr = 'short_link_qr.php?id=' . $id;
        $url = (string) ($link['qr_url'] ?? ''); ?>
        <article class="short-link-card">
            <div class="short-link-card-heading"><h3><?php echo $h($label); ?></h3><span><?php echo number_format((int) $link['visits']); ?> visits</span></div>
            <p><a href="view_engagement.php?id=<?php echo (int) $link['engagement_id']; ?>"><?php echo $h($link['event_title']); ?></a><br><?php echo $h($link['topic_title'] ?: 'Untitled presentation'); ?> · <?php echo $h($link['speaker_name']); ?></p>
            <?php if ((int) $link['speaker_id'] !== (int) $link['current_speaker_id']): ?><p class="field-help">Previous speaker · original attribution retained</p><?php endif; ?>
            <?php if ($url !== ''): ?>
                <img class="short-link-qr" src="data:image/png;base64,<?php echo base64_encode($link['qr_png']); ?>" alt="<?php echo $h($label); ?> QR code" width="180" height="180">
                <label for="short_url_<?php echo $id; ?>">Short URL</label><input id="short_url_<?php echo $id; ?>" type="text" readonly value="<?php echo $h($url); ?>">
                <p><a href="<?php echo $qr; ?>&amp;format=png&amp;download=1">Download PNG</a> · <a href="<?php echo $qr; ?>&amp;format=svg&amp;download=1">Download SVG</a></p>
            <?php else: ?><p class="error">QR images awaiting setup</p><?php endif; ?>
            <?php if (!$isSingleLink): ?><p><a href="short_links.php?<?php echo $h(http_build_query(['id' => $id, 'from' => $from, 'to' => $to])); ?>">Link Statistics</a> · <a href="short_links.php?presentation_id=<?php echo (int) $link['presentation_id']; ?>">Presentation Statistics</a> · <a href="short_links.php?engagement_id=<?php echo (int) $link['engagement_id']; ?>">Event Statistics</a></p><?php endif; ?>
            <?php if ($link['link_type'] === 'notes'): ?><p>Notes PDF ready</p><?php endif; ?>
            <?php if ($canEdit): ?>
            <form method="post" class="short-link-edit">
                <?php echo csrfInput(); ?><input type="hidden" name="link_id" value="<?php echo $id; ?>"><input type="hidden" name="version" value="<?php echo (int) $link['version']; ?>">
                <?php if ($link['link_type'] !== 'notes'): ?><label for="target_<?php echo $id; ?>">Destination</label><input id="target_<?php echo $id; ?>" type="url" name="target_url" value="<?php echo $h($link['target_url']); ?>" maxlength="2048" required><?php endif; ?>
                <label class="short-link-enabled"><input type="checkbox" name="is_enabled" value="1" <?php echo $link['is_enabled'] ? 'checked' : ''; ?>> Link enabled</label>
                <button type="submit" class="button-primary">Save Link</button>
            </form>
            <?php else: ?><p><?php echo $h($link['target_url'] ?? 'MOED notes download'); ?></p><p><?php echo $link['is_enabled'] ? 'Enabled' : 'Disabled'; ?></p><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
    <nav class="short-link-pagination" aria-label="Link pages">
        <?php if ($page > 1): ?><a href="short_links.php?<?php echo $h(http_build_query($filters + ['from' => $from, 'to' => $to, 'page' => $page - 1])); ?>">Previous</a><?php endif; ?>
        <span>Page <?php echo $page; ?> of <?php echo max(1, (int) ceil($linkCount / 50)); ?></span>
        <?php if ($offset + 50 < $linkCount): ?><a href="short_links.php?<?php echo $h(http_build_query($filters + ['from' => $from, 'to' => $to, 'page' => $page + 1])); ?>">Next</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
