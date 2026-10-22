<?php
$d=array_replace(audienceCollectionDefaults('embedded'),$resource['draft']);
$d['embedded']=array_replace(audienceCollectionEmbedDefaults(),$d['embedded']);
$fieldLabels=['email'=>'Email address','first_name'=>'First name','last_name'=>'Last name'];
$lockedFields=['email'];
foreach($fields as$f)if($f['is_public']){$fieldLabels['custom:'.$f['id']]=$f['label'];if($f['required'])$lockedFields[]='custom:'.$f['id'];}
$orderedFields=array_values(array_unique([...$d['fields'],...array_keys($fieldLabels)]));
?>
<div class="embedded-workspace" data-embedded-workspace>
<form method="post" id="collection-editor" class="embedded-controls">
<?= $csrf ?><?php audienceInputs(['action'=>'collection_save','id'=>$id,'version'=>$resource['version']]); ?><input type="hidden" name="document_json" data-collection-document>
<nav class="embedded-menu" aria-label="Embedded form editor" data-embedded-menu>
<?php foreach(['fields'=>'Form Fields','settings'=>'Settings','tags'=>'Tags','badge'=>'Referral Badge']as$key=>$label): ?>
<button type="button" data-embedded-open="<?= $key ?>"<?= $key==='fields'?' class="selected"':'' ?>><span><?= $label ?></span><span aria-hidden="true">›</span></button>
<?php endforeach; ?>
</nav>
<?php foreach(['fields'=>'Form Fields','settings'=>'Settings','tags'=>'Tags','badge'=>'Referral Badge']as$key=>$label): ?>
<section class="embedded-panel" data-embedded-panel="<?= $key ?>" hidden>
<header><button type="button" data-embedded-home aria-label="Back to form editor">‹</button><h2><?= $label ?></h2></header>
<fieldset<?= !$canManage?' disabled':'' ?>>
<?php if($key==='fields'): ?>
<p>Customize or reorder form fields. Manage hidden fields and create new ones in your <a href="<?= $h($url(['view'=>'fields'])) ?>" target="_blank" rel="noopener">audience fields settings ↗</a>.</p>
<div class="embedded-fields" data-embedded-fields>
<?php foreach($orderedFields as$field): if(!isset($fieldLabels[$field]))continue;$label=$d['labels'][$field]??$fieldLabels[$field];$locked=in_array($field,$lockedFields,true); ?>
<div class="embedded-field-row" data-embedded-field="<?= $h($field) ?>" draggable="<?= $canManage?'true':'false' ?>">
<span class="embedded-drag" aria-hidden="true">⠿</span>
<label class="embedded-switch"><input type="checkbox" role="switch" data-embedded-visible aria-label="Show <?= $h($fieldLabels[$field]) ?>"<?= in_array($field,$d['fields'],true)?' checked':'' ?><?= $locked?' disabled':'' ?>><span aria-hidden="true"></span></label>
<button type="button" data-embedded-edit="<?= $h($field) ?>"><span data-embedded-field-label><?= $h($label) ?></span><span class="embedded-required" data-embedded-required-mark<?= !in_array($field,$d['required'],true)?' hidden':'' ?>>*</span><span aria-hidden="true">›</span></button>
<div class="embedded-field-move"><button type="button" data-embedded-move="up" aria-label="Move <?= $h($fieldLabels[$field]) ?> up">↑</button><button type="button" data-embedded-move="down" aria-label="Move <?= $h($fieldLabels[$field]) ?> down">↓</button></div>
</div>
<?php endforeach; ?>
</div>
<?php if($groups): ?><details><summary>Interest groups</summary><?php foreach($groups as$g):if($g['display_type']==='hidden')continue; ?><label class="embedded-check"><input type="checkbox" data-doc-array="group_ids" value="<?= (int)$g['id'] ?>"<?= in_array((int)$g['id'],$d['group_ids'],true)?' checked':'' ?>><?= $h($g['name']) ?></label><?php endforeach; ?></details><?php endif; ?>
<?php elseif($key==='settings'): ?>
<label>Form name<input name="name" maxlength="100" required value="<?= $h($resource['name']) ?>"></label>
<div class="embedded-label-switch"><label for="embedded-title">Form Title</label><label class="embedded-switch"><input type="checkbox" role="switch" data-embedded-option="show_title" aria-label="Show form title"<?= $d['embedded']['show_title']?' checked':'' ?>><span aria-hidden="true"></span></label></div>
<input id="embedded-title" data-doc="title" maxlength="200" required value="<?= $h($d['title']) ?>">
<label>Width<div class="embedded-unit"><input type="number" data-doc="width" aria-describedby="embedded-width-help" min="320" max="1200" placeholder="Automatic" value="<?= $d['width']?(int)$d['width']:'' ?>"><span>pixels</span></div></label><small id="embedded-width-help">Leave blank to use the width of the area where the form is placed.</small>
<div class="embedded-label-switch"><span>Remove CSS styles</span><label class="embedded-switch"><input type="checkbox" role="switch" data-embedded-option="remove_css" aria-label="Remove CSS styles"<?= $d['embedded']['remove_css']?' checked':'' ?>><span aria-hidden="true"></span></label></div>
<label class="embedded-check"><input type="checkbox" data-embedded-option="disable_javascript"<?= $d['embedded']['disable_javascript']?' checked':'' ?>>Disable all JavaScript</label>
<label class="embedded-check"><input type="checkbox" data-embedded-option="show_format"<?= $d['embedded']['show_format']?' checked':'' ?>>Show format options</label>
<label class="embedded-check"><input type="checkbox" data-embedded-option="show_archive"<?= $d['embedded']['show_archive']?' checked':'' ?>>Show archive link</label>
<details><summary>Content and style</summary>
<label>Introduction<textarea data-doc="intro" rows="3" maxlength="5000"><?= $h($d['intro']) ?></textarea></label>
<label>Button text<input data-doc="button" maxlength="80" required value="<?= $h($d['button']) ?>"></label>
<div class="embedded-colors"><label>Button color<input type="color" data-doc="color" value="<?= $h($d['color']) ?>"></label><label>Text color<input type="color" data-doc="text_color" value="<?= $h($d['text_color']) ?>"></label><label>Background<input type="color" data-doc="background" value="<?= $h($d['background']) ?>"></label></div>
<label>Font<select data-doc="font"><?php foreach(['Arial','Georgia','Verdana']as$font): ?><option<?= $font===$d['font']?' selected':'' ?>><?= $font ?></option><?php endforeach; ?></select></label>
</details>
<details><summary>Signup and messages</summary>
<label>Email opt-in<select data-doc="opt_in"><option value="double"<?= $d['opt_in']==='double'?' selected':'' ?>>Double opt-in</option><option value="single"<?= $d['opt_in']==='single'?' selected':'' ?>>Single opt-in</option></select></label>
<label>Permission statement<textarea data-doc="permission" maxlength="1000" required><?= $h($d['permission']) ?></textarea></label>
<label>Thank-you message<textarea data-doc="thanks" maxlength="2000" required><?= $h($d['thanks']) ?></textarea></label>
<label>Confirmation message<textarea data-doc="confirmation" maxlength="2000"><?= $h($d['confirmation']) ?></textarea></label>
<label>Language code<input data-doc="language" maxlength="12" required value="<?= $h($d['language']) ?>"></label>
<?php foreach($d['messages']as$messageKey=>$value): ?><label><?= $h(ucfirst(str_replace('_',' ',$messageKey))) ?><input data-doc-message="<?= $h($messageKey) ?>" maxlength="1000" required value="<?= $h($value) ?>"></label><?php endforeach; ?>
</details>
<?php if($canManage): ?><details><summary>Manage form</summary>
<p><a href="<?= $h($url(['view'=>'collection-report','id'=>$id])) ?>">View results</a></p>
<div class="embedded-management-actions"><button type="button" data-embedded-operation="pause">Pause collection</button><button type="button" data-embedded-operation="replicate">Duplicate form</button></div>
<label>Type REMOVE to remove this form<input data-embedded-remove-confirm maxlength="6" autocomplete="off"></label><button type="button" data-embedded-operation="remove">Remove form</button><p class="audience-help">Removing stops the public form and retains its existing results.</p>
</details><?php endif; ?>
<?php elseif($key==='tags'): ?>
<h3>Tag contacts</h3><p>Any tags you choose here will be applied to new contacts who sign up through this form.</p>
<label class="sr-only" for="embedded-tag-search">Find a tag</label><input id="embedded-tag-search" type="search" placeholder="Search tags" data-embedded-tag-search>
<div class="embedded-tag-list" data-embedded-tags><?php foreach($tags as$tag): ?><label class="embedded-check"><input type="checkbox" data-doc-array="tag_ids" value="<?= (int)$tag['id'] ?>"<?= in_array((int)$tag['id'],$d['tag_ids'],true)?' checked':'' ?>><?= $h($tag['name']) ?></label><?php endforeach; ?></div>
<p class="audience-help" data-embedded-no-tags<?= $tags?' hidden':'' ?>>No tags yet. Create one below.</p>
<label>New tag<input data-embedded-tag-name maxlength="100" placeholder="Enter a tag name"></label><button type="button" data-embedded-tag-create>Create tag</button><p role="status" data-embedded-tag-status></p>
<?php else: ?>
<h3>MessageMonkey Referral badge</h3><p>Add a MessageMonkey badge beneath your signup form.</p>
<fieldset class="embedded-badge-styles"><legend>Badge style</legend><?php foreach(['light'=>'Light','dark'=>'Dark']as$style=>$label): ?><label class="embedded-check"><input type="radio" name="badge_style" data-embedded-option="badge_style" value="<?= $style ?>"<?= $d['embedded']['badge_style']===$style?' checked':'' ?>><?= $label ?></label><?php endforeach; ?></fieldset>
<div class="embedded-label-switch"><span>Show Referral badge</span><label class="embedded-switch"><input type="checkbox" role="switch" data-embedded-option="badge" aria-label="Show Referral badge"<?= $d['embedded']['badge']?' checked':'' ?>><span aria-hidden="true"></span></label></div>
<?php endif; ?>
</fieldset></section>
<?php endforeach; ?>
<?php foreach($orderedFields as$field):if(!isset($fieldLabels[$field]))continue; ?>
<section class="embedded-panel" data-embedded-field-editor="<?= $h($field) ?>" hidden><header><button type="button" data-embedded-open="fields" aria-label="Back to Form Fields">‹</button><h2><?= $h($fieldLabels[$field]) ?></h2></header><fieldset<?= !$canManage?' disabled':'' ?>>
<label>Display name<input data-doc-label="<?= $h($field) ?>" maxlength="200" required value="<?= $h($d['labels'][$field]??$fieldLabels[$field]) ?>"></label>
<label>Help text<textarea data-doc-help="<?= $h($field) ?>" rows="3" maxlength="1000"><?= $h($d['help_text'][$field]??'') ?></textarea></label>
<label class="embedded-check"><input type="checkbox" data-embedded-required="<?= $h($field) ?>"<?= in_array($field,$d['required'],true)?' checked':'' ?><?= in_array($field,$lockedFields,true)?' disabled':'' ?>>Required field</label>
<?php if(in_array($field,$lockedFields,true)): ?><p class="audience-help">This field is required by your audience and must stay visible.</p><?php endif; ?>
</fieldset></section>
<?php endforeach; ?>
<section class="embedded-panel embedded-code-panel" data-embedded-code-panel hidden>
<button type="button" data-embedded-home>← Back to editing</button><h2>Your embedded form is ready</h2><p>Publish your saved design, then copy the code into your website where you want the form to appear.</p>
<fieldset<?= !$canManage?' disabled':'' ?>><label>Website origins<textarea data-doc-lines="origins" aria-describedby="embedded-origins-help" rows="3" placeholder="https://example.com"><?= $h(implode("\n",$d['origins'])) ?></textarea></label><small id="embedded-origins-help">Enter each HTTPS website that will display the form, one per line.</small>
<button type="button" class="primary" data-embedded-publish>Publish form</button></fieldset>
<p role="status" data-embedded-publish-status><?= $resource['state']==='published'?'Published form available. Publish any draft changes before copying.':'Save and publish before installing this form.' ?></p>
<label>Embed code<textarea readonly rows="5" data-embedded-code spellcheck="false"><?= $h(audienceCollectionEmbedCode($publicUrl,$d)) ?></textarea></label>
<button type="button" data-embedded-copy disabled>Copy Code</button><p role="status" data-embedded-copy-status></p>
<p class="audience-help"><?= $resource['installed_at']?'Last installed '. $h($resource['installed_at']).' UTC':'Installation is confirmed when your website loads the published form.' ?></p>
</section>
<p class="embedded-feedback" role="status" data-embedded-status></p>
</form>
<div class="embedded-preview-area"><div class="embedded-preview-stage"><iframe title="Form preview" sandbox="allow-same-origin" referrerpolicy="no-referrer" data-collection-preview<?= isset($collectionPreviewHtml)?' srcdoc="'.$h($collectionPreviewHtml).'"':'' ?>></iframe></div>
<aside class="embedded-preview-notice"><span aria-hidden="true">ⓘ</span><p>Preview updates as you customize your form. Language and messages are available in Settings.</p><button type="button" data-embedded-dismiss-notice aria-label="Dismiss preview tip">×</button></aside></div>
</div>
<script type="application/json" id="collection-config"><?= json_encode(['document'=>$d,'kind'=>'embedded','id'=>$id,'canManage'=>$canManage,'publicUrl'=>$publicUrl,'exitUrl'=>$url(['view'=>'forms']),'formUrl'=>$url(['view'=>'form']),'state'=>$resource['state'],'previewOnly'=>isset($collectionPreviewHtml),'previewNonce'=>contentSecurityPolicyNonce()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
