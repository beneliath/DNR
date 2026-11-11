<?php if($available && $canManage): ?>
<?php if($view==='collection-create'): ?>
<?php elseif($view==='form'): ?>
<a class="audience-button" href="<?= $h($url(['view'=>match($resource['kind']){'survey'=>'surveys','landing'=>'landing-pages','preferences'=>'preferences',default=>'forms'}])) ?>">Finish Later</a>
<button type="button" class="audience-history-action" data-collection-undo disabled aria-label="Undo" title="Undo"><?php audienceNavIcon('undo'); ?></button><button type="button" class="audience-history-action" data-collection-redo disabled aria-label="Redo" title="Redo"><?php audienceNavIcon('redo'); ?></button><button type="button" data-collection-focus-preview>Preview</button>
<button type="submit" form="collection-editor" data-collection-save aria-describedby="collection-unsaved-status">Save Draft</button>
<?php $publishClass='primary'.(!empty($collectionPublishPending)?' collection-publish-pending':'');$publishTitle=!empty($collectionPublishPending)?'Saved changes have not been published':'Publish current changes'; ?>
<?php if($resource['kind']==='survey'): ?><button class="primary" type="button" data-survey-next>Continue to Design</button><button class="<?= $publishClass ?>" title="<?= $publishTitle ?>" type="button" data-open-dialog="collection-publish" data-survey-publish hidden>Publish</button><?php else: ?><button class="<?= $publishClass ?>" title="<?= $publishTitle ?>" type="button" data-open-dialog="collection-publish">Publish</button><?php endif; ?>
<?php elseif($view==='collection-report'): ?>
<a class="audience-button" href="<?= $h($url(($reportQuery??['view'=>'collection-report','id'=>$id])+['export'=>'csv'])) ?>">Export Results</a><button type="button" data-collection-print>Print All Responses</button><?php $collectionEntry=$resource; require __DIR__.'/collection-lifecycle-actions.php'; ?>
<?php else: ?>
<a class="audience-button collection-settings-button" href="<?= $h($url(['view'=>'form-settings'])) ?>">Settings</a>
<?php if(in_array($view,['surveys','landing-pages','preferences','form-settings'],true)): ?><a class="audience-button primary" href="<?= $h($url(['view'=>'collection-create','kind'=>match($view){'surveys'=>'survey','landing-pages'=>'landing','preferences'=>'preferences',default=>'hosted'}])) ?>"><?= match($view){'surveys'=>'Create New Survey','landing-pages'=>'Create Landing Page','preferences'=>'Create Preference Center',default=>'Create Subscribe Form'} ?></a><?php else: ?><button class="primary" type="button" data-open-dialog="collection-create">Create Form</button><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
