<?php
$rangeStartLabel=(new DateTimeImmutable($window['start'],new DateTimeZone('UTC')))->format('M j, Y');
$rangeEndLabel=(new DateTimeImmutable($window['end'],new DateTimeZone('UTC')))->format('M j, Y');
?>
<p id="analytics-range-summary" class="analytics-range-summary<?= $view==='home'?' analytics-home-range-summary':'' ?>">Report period: <time datetime="<?= $h($window['start']) ?>"><?= $h($rangeStartLabel) ?></time> – <time datetime="<?= $h($window['end']) ?>"><?= $h($rangeEndLabel) ?></time></p>
