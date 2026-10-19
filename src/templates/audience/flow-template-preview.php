<?php
$t=audienceFlowTemplates()[$flowPreview];$document=audienceFlowDefault($flowPreview);$graph=audienceFlowPreviewGraph($document);$positions=array_column($graph['cards'],null,'id');
?>
<section class="flow-preview-intro"><div><h2><?= $h($t['name']) ?></h2><p><?= $h($t['description']) ?></p><h3>This flow will:</h3><ul><?php foreach($t['summary']as$summary): ?><li><?= $h($summary) ?></li><?php endforeach; ?></ul></div>
<aside><dl><dt>Connections are added when</dt><dd><?php foreach([$document['trigger'],...$document['additional_triggers']]as$trigger): ?><span><?= $h(audienceFlowTriggerLabel($trigger)) ?></span><?php endforeach; ?></dd><dt>You’ll need</dt><dd><ul><?php foreach($t['requirements']as$requirement): ?><li><?= $h($requirement) ?></li><?php endforeach; ?></ul></dd><dt>Flow objective</dt><dd><?= $h($t['objective']) ?></dd></dl></aside></section>
<section class="flow-preview-map-stage" aria-label="Template flow map">
    <div class="flow-preview-map-scroll" tabindex="0" aria-label="Scrollable template flow map"><div data-flow-static-map class="flow-preview-map-canvas">
    <svg class="flow-preview-graph" width="<?= $graph['width'] ?>" height="<?= $graph['height'] ?>" viewBox="0 0 <?= $graph['width'] ?> <?= $graph['height'] ?>" role="img" aria-labelledby="flow-preview-graph-title"><title id="flow-preview-graph-title"><?= $h($t['name']) ?>: starting triggers, actions and exit paths</title>
    <?php foreach($graph['edges']as$edge): $a=$positions[$edge['from']];$b=$positions[$edge['to']];$x1=$a['x']+150;$y1=$a['y']+68;$x2=$b['x']+150;$y2=$b['y'];$middle=($y1+$y2)/2; ?>
    <path class="flow-preview-connector" d="M<?= $x1 ?> <?= $y1 ?> C<?= $x1 ?> <?= $middle ?>,<?= $x2 ?> <?= $middle ?>,<?= $x2 ?> <?= $y2 ?>"/>
    <?php if($edge['label']): ?><text class="flow-preview-edge-label" x="<?= ($x1+$x2)/2+8 ?>" y="<?= $middle-7 ?>"><?= $h($edge['label']) ?></text><?php endif; ?>
    <?php endforeach; ?>
    <?php foreach($graph['cards']as$card): $icon=audienceFlowIconPaths()[$card['kind']]??audienceFlowIconPaths()['trigger']; ?>
    <g class="flow-preview-graph-node kind-<?= $h($card['kind']) ?>" transform="translate(<?= $card['x'] ?> <?= $card['y'] ?>)"><title><?= $h($card['category'].': '.$card['label']) ?></title><rect width="300" height="68" rx="2"/><path class="flow-preview-stripe" d="M2 1v66"/><svg x="15" y="23" width="22" height="22" viewBox="0 0 24 24" class="flow-symbol" aria-hidden="true"><path d="<?= $icon ?>"/></svg><text class="flow-preview-kind" x="53" y="24"><?= $h($card['category']) ?></text><text class="flow-preview-node-label" x="53" y="47"><?= $h(mb_strimwidth($card['label'],0,33,'…')) ?></text></g>
    <?php endforeach; ?></svg></div></div>
    <div class="flow-static-controls"><button type="button" data-static-flow-zoom="in" aria-label="Zoom in">+</button><output data-flow-preview-zoom aria-live="polite">100%</output><button type="button" data-static-flow-zoom="out" aria-label="Zoom out">−</button><button type="button" data-static-flow-zoom="reset" aria-label="Reset view">↺</button></div>
</section>
<p class="flow-preview-draft-note"><?= audienceFlowIcon('status') ?> Use this flow creates a draft with these actual branches and timing. Review its emails, tags, filters and delivery readiness before activation.</p>
<section class="flow-template-section"><h2>More flow templates to help bring your strategy to life</h2><div class="flow-template-grid flow-featured-grid"><?php $related=0;foreach(audienceFlowTemplates()as$key=>$template)if($key!==$flowPreview&&($template['family']===$t['family']||$template['recommended'])){audienceFlowTemplateCard($key,$template,$url,true);if(++$related===3)break;} ?></div></section>
