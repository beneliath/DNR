<?php if($available && $canManage): ?>
<?php if($view==='collection-create'): ?>
<?php elseif($view==='form'&&$resource['kind']==='embedded'): ?>
<button type="button" data-embedded-finish>Finish later</button><button type="button" class="primary" data-embedded-continue>Continue</button>
<?php elseif($view==='form'): ?>
<a class="audience-button" href="<?= $h($url(['view'=>match($resource['kind']){'survey'=>'surveys','landing'=>'landing-pages','preferences'=>'preferences',default=>'forms'}])) ?>">Finish later</a>
<button type="button" class="audience-history-action" data-collection-undo disabled aria-label="Undo" title="Undo"><?php audienceNavIcon('undo'); ?></button><button type="button" class="audience-history-action" data-collection-redo disabled aria-label="Redo" title="Redo"><?php audienceNavIcon('redo'); ?></button><button type="button" data-collection-focus-preview>Preview</button>
<button type="submit" form="collection-editor" data-collection-save aria-describedby="collection-unsaved-status">Save draft</button>
<?php $publishClass='primary'.(!empty($collectionPublishPending)?' collection-publish-pending':'');$publishTitle=!empty($collectionPublishPending)?'Saved changes have not been published':'Publish current changes'; ?>
<?php if($resource['kind']==='survey'): ?><button class="primary" type="button" data-survey-next>Continue to design</button><button class="<?= $publishClass ?>" title="<?= $publishTitle ?>" type="button" data-open-dialog="collection-publish" data-survey-publish hidden>Publish</button><?php else: ?><button class="<?= $publishClass ?>" title="<?= $publishTitle ?>" type="button" data-open-dialog="collection-publish">Publish</button><?php endif; ?>
<?php elseif($view==='collection-report'): ?>
<a class="audience-button" href="<?= $h($url(($reportQuery??['view'=>'collection-report','id'=>$id])+['export'=>'csv'])) ?>">Export results</a><button type="button" data-collection-print>Print all responses</button><?php $collectionEntry=$resource; require __DIR__.'/collection-lifecycle-actions.php'; ?>
<?php else: ?>
<a class="audience-button collection-settings-button" href="<?= $h($url(['view'=>'form-settings'])) ?>">Settings</a>
<?php if(in_array($view,['surveys','landing-pages','preferences','form-settings'],true)): ?><a class="audience-button primary" href="<?= $h($url(['view'=>'collection-create','kind'=>match($view){'surveys'=>'survey','landing-pages'=>'landing','preferences'=>'preferences',default=>'hosted'}])) ?>"><?= match($view){'surveys'=>'Create new survey','landing-pages'=>'Create landing page','preferences'=>'Create preference center',default=>'Create subscribe form'} ?></a><?php else: ?><button class="primary" type="button" data-open-dialog="collection-create">Create form</button><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
