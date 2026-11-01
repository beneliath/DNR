<?php
$locationReport=$interactionLocations??['pins'=>[],'observations'=>0,'unmapped'=>0];
$locationPins=$locationReport['pins'];
$mappedObservations=$locationReport['observations']-$locationReport['unmapped'];
$scrollLocations=count($locationPins)>10;
?>
<section class="analytics-card analytics-interaction-locations" aria-labelledby="interaction-locations-title">
    <header class="analytics-interaction-map-heading">
        <div><h2 id="interaction-locations-title">Interaction Locations</h2>
            <p>Approximate locations of email and subscriber interactions observed during the selected report period.</p></div>
        <button type="button" id="fit-interaction-pins" class="audience-button"<?= $locationPins?'':' disabled' ?>>Fit pins</button>
    </header>
    <p id="interaction-map-feedback" class="analytics-interaction-map-feedback" role="status"><?= number_format(count($locationPins)) ?> <?= count($locationPins)===1?'location':'locations' ?> · <?= number_format($mappedObservations) ?> <?= $mappedObservations===1?'interaction':'interactions' ?> with a mapped location<?php if($locationReport['unmapped']): ?> · <?= number_format($locationReport['unmapped']) ?> without a mappable country<?php endif; ?></p>
    <?php if($interactionMapProvider!==null): ?>
        <div id="interaction-location-map" class="analytics-interaction-map" role="region" aria-label="Approximate interaction location map">
            <?php if(!$locationPins): ?><p class="analytics-interaction-map-empty">No interactions with a mappable location were found in this date range.</p><?php endif; ?>
        </div>
        <script type="application/json" id="interaction-location-map-data"><?= json_encode(['pins'=>$locationPins,'mapProvider'=>$interactionMapProvider],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR) ?></script>
        <?php renderScript('assets/js/audience-interaction-map.min.js',true,true); ?>
    <?php else: ?><p class="analytics-empty">The map is unavailable right now.</p><?php endif; ?>
    <p class="analytics-interaction-map-note">Pins show an approximate city position when a city matches the offline map reference. Other interactions appear at a country label point. IP location can be inaccurate.</p>
    <?php if($locationPins): ?><h3 id="interaction-location-table-title" class="analytics-interaction-list-title">Interactions by mapped location</h3><div class="analytics-table-scroll<?= $scrollLocations?' analytics-interaction-table-scroll':'' ?>"<?= $scrollLocations?' role="region" aria-labelledby="interaction-location-table-title" tabindex="0"':'' ?>><table class="data-table analytics-interaction-table" aria-labelledby="interaction-location-table-title"><thead><tr><th scope="col">Location</th><th scope="col">Interactions</th><th scope="col">Types</th></tr></thead><tbody>
        <?php foreach($locationPins as$index=>$pin): ?>
            <tr<?= $interactionMapProvider!==null?' data-interaction-pin="'.$index.'"':'' ?>>
                <th scope="row">
                    <?php if($interactionMapProvider!==null): ?><a class="analytics-interaction-location-action" href="#interaction-location-map" aria-label="Show <?= $h($pin['label']) ?> on map"><?= $h($pin['label']) ?></a><?php else: ?><?= $h($pin['label']) ?><?php endif; ?>
                    <?php if($pin['precision']==='country'&&$pin['reported_places']): ?><small>Reported: <?= $h(implode(', ',array_slice($pin['reported_places'],0,3))) ?><?= count($pin['reported_places'])>3?' and more':'' ?></small><?php endif; ?>
                </th>
                <td><?= number_format($pin['observations']) ?></td>
                <td><?= $h(implode(' · ',array_map(static fn($label,$count):string=>$label.' '.number_format($count),array_keys($pin['types']),array_values($pin['types'])))) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
