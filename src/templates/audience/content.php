<?php
$contentForm=static function(string $action,array $values=[])use($csrf):void{echo $csrf;audienceInputs(['action'=>$action]+$values);};
$contentFolders=$contentContext['settings']['content_folders']??[];
?>
<nav class="campaign-tabs" aria-label="Content views"><a href="<?= $h($url(['view'=>'content'])) ?>"<?= $view==='content'?' aria-current="page"':'' ?>>My files</a><a href="<?= $h($url(['view'=>'brand'])) ?>"<?= $view==='brand'?' aria-current="page"':'' ?>>Brand Kit</a></nav>
<?php if($view==='brand'):
$canEditBrand=$canDelete;
$brandIdentity=audienceBrandIdentity($contentContext['settings'],(string)$audience['name']);
$brandValues=($campaignRecovery['action']??'')==='brand_save'?$campaignRecovery:($brand['styles']??[])+['organization_name'=>$brandIdentity['LIST:COMPANY'],'mailing_address'=>$brandIdentity['LIST:ADDRESS'],'logo'=>$brand['logo']??'','secondary_logo'=>$brand['secondary_logo']??'','web_font'=>$brand['styles']['webFont']??'','web_font_name'=>$brand['web_font_name']??''];
$brandValues['web_font_name']=audienceFontName((string)($brandValues['web_font']??''))?:($brandValues['web_font_name']??'');
?>
<div class="content-brand-layout">
<section class="audience-panel content-brand-settings">
<h2>Your brand</h2>
<p>Set your logos, fonts, colors and button styles. Apply them to a saved template when you are ready.</p>
<form method="post" data-brand-form>
<?php $contentForm('brand_save',['version'=>$contentContext['version']]); ?>
<fieldset<?= !$canEditBrand?' disabled':'' ?>>
<div class="content-brand-sections">
<section class="content-brand-section content-brand-wide" aria-labelledby="brand-organization-title">
<h3 id="brand-organization-title">Organization details</h3>
<p class="audience-help">Used for your email footer and preview. Enter the organization name and physical mailing address recipients should see.</p>
<div class="content-brand-fields content-brand-identity-fields">
<label>Organization name<input name="organization_name" maxlength="150" value="<?= $h($brandValues['organization_name']??'') ?>" autocomplete="organization"></label>
<label>Mailing address<textarea name="mailing_address" rows="3" maxlength="1000" autocomplete="street-address"><?= $h($brandValues['mailing_address']??'') ?></textarea></label>
</div>
</section>
<section class="content-brand-section content-brand-wide" aria-labelledby="brand-logos-title">
<h3 id="brand-logos-title">Logos</h3>
<p class="audience-help">Both logos use the default logo’s pixel dimensions. The secondary logo is centered with transparent padding to preserve its proportions.</p>
<div class="content-brand-logo-grid">
<?php foreach(['logo'=>'Default logo','secondary_logo'=>'Secondary logo']as$key=>$label): ?>
<div class="content-brand-upload">
<?php $uploadId='brand-'.$key.'-file';$uploadLabel=$label;$uploadKey=$key;$uploadAccept='image/svg+xml,.svg,image/png,image/jpeg,image/webp';$uploadHelp='SVG up to 2 MB stays vector · PNG, JPEG or WebP up to 20 MB';$uploadTypes='Choose an SVG, PNG, JPEG or WebP logo.';include __DIR__.'/file-drop.php'; ?>
<input type="hidden" name="<?= $key ?>" value="<?= $h($brandValues[$key]??'') ?>">
<div class="content-brand-logo-actions">
<img class="content-logo-preview" alt="<?= $label ?> preview" data-content-preview="<?= $key ?>" src="<?= $h($brandValues[$key]??'') ?>"<?= empty($brandValues[$key])?' hidden':'' ?>>
<span class="audience-help" data-brand-logo-dimensions="<?= $key ?>"></span>
<button type="button" data-content-clear="<?= $key ?>">Remove <?= strtolower($label) ?></button>
</div>
</div>
<?php endforeach; ?>
</div>
</section>
<section class="content-brand-section content-brand-type-section" aria-labelledby="brand-fonts-title">
<h3 id="brand-fonts-title">Fonts and text</h3>
<div class="content-brand-fields content-brand-type-fields">
<label>Default font and email fallback<select name="font"><?php foreach(['Arial','Georgia','Verdana','Tahoma','Times New Roman']as$font): ?><option<?= ($brandValues['font']??'Arial')===$font?' selected':'' ?>><?= $font ?></option><?php endforeach; ?></select></label>
<label>Paragraph size<input type="number" name="fontSize" min="12" max="30" value="<?= (int)($brandValues['fontSize']??16) ?>"></label>
</div>
<div class="content-brand-upload content-brand-font-upload">
<?php $uploadId='brand-font-file';$uploadLabel='Upload a brand font';$uploadKey='web_font';$uploadAccept='.woff,.woff2,.ttf';$uploadHelp='WOFF, WOFF2 or TTF · up to 500 KB';$uploadTypes='Choose a WOFF, WOFF2 or TTF font.';$uploadStatus=empty($brandValues['web_font'])?'No file selected.':(!empty($brandValues['web_font_name'])?'Uploaded font: '.$brandValues['web_font_name']:'Uploaded brand font.');include __DIR__.'/file-drop.php';unset($uploadStatus); ?>
<input type="hidden" name="web_font" value="<?= $h($brandValues['web_font']??'') ?>">
<input type="hidden" name="web_font_name" value="<?= $h($brandValues['web_font_name']??'') ?>">
<label class="audience-check"><input type="checkbox" name="font_license" value="1"<?= !empty($brandValues['web_font'])?' checked':'' ?>>My font license permits embedding this font in emails</label>
<button type="button" data-content-clear="web_font">Remove uploaded font</button>
<p class="audience-help">Email clients that do not support embedded fonts use your selected fallback.</p>
</div>
</section>
<section class="content-brand-section content-brand-color-section" aria-labelledby="brand-colors-title">
<h3 id="brand-colors-title">Colors and buttons</h3>
<?php $brandColorGroups=[
    ['background'=>['Email background','#f4f4f4'],'body'=>['Content background','#ffffff'],'text'=>['Text','#24292d']],
    ['link'=>['Links','#007c89'],'button'=>['Button','#007c89'],'buttonText'=>['Button text','#ffffff']]
];foreach($brandColorGroups as$colorRow=>$colors): ?>
<?php if($colorRow===0): ?><div class="content-color-grid content-brand-primary-colors"><?php else: ?><div class="content-brand-secondary-colors"><div class="content-color-grid"><?php endif; ?>
<?php foreach($colors as$key=>[$label,$default]): ?>
<label><?= $label ?><input type="color" name="<?= $key ?>" value="<?= $h($brandValues[$key]??$default) ?>"></label>
<?php endforeach; ?>
</div>
<?php if($colorRow===1): ?><label class="content-brand-radius">Button corner radius<input type="number" name="buttonRadius" min="0" max="50" value="<?= (int)($brandValues['buttonRadius']??4) ?>"></label></div><?php endif; ?>
<?php endforeach; ?>
</section>
</div>
<p role="status" data-content-status></p>
<div class="dialog-actions"><button class="primary">Save brand settings</button></div>
</fieldset>
</form>
<?php if($canManage): ?>
<section class="content-brand-section content-brand-apply" aria-labelledby="brand-apply-title">
<h3 id="brand-apply-title">Apply to a template</h3>
<form method="post" data-brand-apply>
<?php $contentForm('brand_apply',['version'=>$brandTemplates[0]['version']??0]); ?>
<div class="content-brand-apply-row">
<label>Saved template<select name="template_id" required><?php foreach($brandTemplates as$template): ?><option value="<?= $template['id'] ?>" data-version="<?= $template['version'] ?>"><?= $h($template['name']) ?></option><?php endforeach; ?></select></label>
<button<?= !$brandTemplates?' disabled':'' ?>>Apply Brand Kit</button>
</div>
<label class="audience-check"><input type="checkbox" name="include_logo" value="1">Add the default logo to the top of a visual template</label>
<p class="audience-help">Uses your saved brand settings. Explicit block styles and existing campaign snapshots are preserved; a template revision records the change.</p>
</form>
</section>
<?php endif; ?>
</section>
<aside class="audience-panel"><h2>Brand preview</h2><iframe class="campaign-email-frame" title="Brand preview" sandbox="allow-same-origin" scrolling="no" data-preview-autosize data-email-preview="<?= $h(audienceDesignPreviewHtml($brandPreview,contentSecurityPolicyNonce(),$brandIdentity)) ?>"></iframe></aside>
</div>
<?php else: $library=$contentLibrary;$itemValues=($campaignRecovery['action']??'')==='content_save'?$campaignRecovery:($contentItem?['id'=>$contentItem['id'],'version'=>$contentItem['version'],'name'=>$contentItem['name']]+$contentItem['data']:[]); ?>
<?php if($canManage): ?><p><a class="audience-button" href="<?= $h($url(['view'=>'assistant'])) ?>">Write with AI</a></p><?php endif; ?>
<div class="section-title"><p>Store images, PDF documents and reusable sections for your emails.</p><div class="audience-actions"><?php if($canManage): ?><button type="button" data-open-dialog="content-folder">Manage folders</button><button type="button" class="primary" data-content-new>Upload</button><?php endif; ?><a href="<?= $h($contentUrl(['trash'=>$library['trash']?'0':'1','page'=>1])) ?>"><?= $library['trash']?'Back to files':'Trash' ?></a></div></div>
<form method="get" class="content-filters"><?php audienceInputs(['view'=>'content','trash'=>$library['trash']?'1':'0']); ?><label class="campaign-search"><?php audienceNavIcon('search'); ?><input type="search" autocomplete="off" list="content-search-options" name="search" value="<?= $h($library['search']) ?>" placeholder="Search files" aria-label="Search files"></label><label>Folder<select name="folder"><option value="">All folders</option><option value="unfiled"<?= $library['folder']==='unfiled'?' selected':'' ?>>Unfiled</option><?php foreach($contentFolders as$folder): ?><option<?= $library['folder']===$folder?' selected':'' ?>><?= $h($folder) ?></option><?php endforeach; ?></select></label><label>Type<select name="type"><option value="">All files</option><?php foreach(['image'=>'Images','file'=>'PDF documents','section'=>'Saved sections']as$key=>$label): ?><option value="<?= $key ?>"<?= $library['type']===$key?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><label>Sort by<select name="sort"><?php foreach(['updated_at'=>'Date modified','created_at'=>'Date added','name'=>'File name','size'=>'File size']as$key=>$label): ?><option value="<?= $key ?>"<?= $library['sort']===$key?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><label>Order<select name="direction"><option value="desc"<?= $library['direction']==='desc'?' selected':'' ?>>Descending</option><option value="asc"<?= $library['direction']==='asc'?' selected':'' ?>>Ascending</option></select></label><label>View<select name="layout"><option value="grid"<?= $library['layout']==='grid'?' selected':'' ?>>Grid</option><option value="list"<?= $library['layout']==='list'?' selected':'' ?>>List</option></select></label><label>Per page<select name="per_page"><?php foreach([10,25,50,100]as$size): ?><option<?= $library['per_page']===$size?' selected':'' ?>><?= $size ?></option><?php endforeach; ?></select></label><button>Apply</button></form>
<form method="post" data-content-selection><?php $contentForm('content_operation',['confirm_delete'=>'']); ?><?php if($canManage): ?><div class="content-bulk"><label class="audience-check"><input type="checkbox" data-content-all>Select this page</label><span data-content-count>0 selected</span><label>Move to<select name="folder"><option value="">Unfiled</option><?php foreach($contentFolders as$folder): ?><option><?= $h($folder) ?></option><?php endforeach; ?></select></label><?php if(!$library['trash']): ?><button name="operation" value="move">Move</button><?php if($canDelete): ?><button name="operation" value="delete" data-admin-unlock-required>Move to Trash</button><?php endif; ?><?php else: ?><button name="operation" value="restore">Restore</button><?php if($canDelete): ?><button name="operation" value="purge" data-admin-unlock-required>Delete permanently</button><?php endif; ?><?php endif; ?></div><?php endif; ?>
<p class="audience-help">Files in Trash can be restored. Administrators can permanently delete selected files after Admin Unlock. Existing emails keep their inserted copies.</p><div class="content-library <?= $library['layout']==='list'?'content-library-list':'' ?>"><?php foreach($library['items']as$item): ?><article><?php if($canManage): ?><label class="audience-check"><input type="checkbox" name="selected[]" value="<?= $item['id'] ?>" aria-label="Select <?= $h($item['name']) ?>" data-content-select><span class="sr-only"><?= $h($item['name']) ?></span></label><input type="hidden" name="versions[<?= $item['id'] ?>]" value="<?= $item['version'] ?>"><?php endif; ?><?php if(!$library['trash']): ?><a href="<?= $h($contentUrl(['item'=>$item['id']])) ?>" class="content-thumbnail"><?php if($item['item_type']==='image'): ?><img src="<?= $h($item['data']['image']) ?>" alt="<?= $h($item['data']['alt']??'') ?>"><?php else: ?><span aria-label="<?= $item['item_type']==='file'?'PDF document':'Saved section' ?>">▤</span><?php endif; ?></a><?php endif; ?><div><strong><?= $h($item['name']) ?></strong><?php if($fileSize=audienceContentFileSize($item)): ?><small data-content-file-size><?= $h($fileSize) ?></small><?php endif; ?><small><?= $h($item['data']['folder']??'Unfiled') ?> · <?= $h(substr($item['updated_at'],0,16)) ?></small><small>Private to this account</small></div></article><?php endforeach; ?><?php if(!$library['items']): ?><p class="audience-empty">No files found.</p><?php endif; ?></div></form>
<nav class="campaign-pagination" aria-label="Content pages"><span><?= number_format($library['total']) ?> files</span><div><?php if($library['page']>1): ?><a href="<?= $h($contentUrl(['page'=>$library['page']-1])) ?>">Previous</a><?php endif; ?><span>Page <?= $library['page'] ?> of <?= $library['pages'] ?></span><?php if($library['page']<$library['pages']): ?><a href="<?= $h($contentUrl(['page'=>$library['page']+1])) ?>">Next</a><?php endif; ?></div></nav>
<?php if($library['trash']&&$canDelete): ?>
<dialog id="content-delete-confirm" aria-labelledby="content-delete-title" aria-describedby="content-delete-message">
<form method="dialog">
<h2 id="content-delete-title">Permanently delete files?</h2>
<p id="content-delete-message" data-content-delete-message>This cannot be undone. Existing emails keep their inserted copies.</p>
<div class="dialog-actions"><button value="cancel" autofocus>Cancel</button><button value="delete" class="primary">Delete permanently</button></div>
</form>
</dialog>
<?php endif; ?>
<?php if($canManage): ?><dialog id="content-folder" aria-labelledby="content-folder-title"><header class="dialog-heading"><h2 id="content-folder-title">Manage folders</h2><button type="button" data-close-dialog aria-label="Close folders">×</button></header><form method="post"><?php $contentForm('content_folder',['operation'=>'create','version'=>$contentContext['version']]); ?><label>New folder name<input name="name" maxlength="100" required></label><button class="primary">Create folder</button></form><?php if($contentFolders): ?><hr><form method="post"><?php $contentForm('content_folder',['version'=>$contentContext['version']]); ?><label>Folder<select name="name"><?php foreach($contentFolders as$folder): ?><option><?= $h($folder) ?></option><?php endforeach; ?></select></label><label>New name<input name="new_name" maxlength="100"></label><p class="audience-help">Only empty folders can be deleted. Files in Trash also count.</p><div class="dialog-actions"><button name="operation" value="rename">Rename</button><button name="operation" value="delete">Delete folder</button></div></form><?php endif; ?></dialog><?php endif; ?>
<?php if(!$contentItem||in_array($contentItem['item_type'],['image','file'],true)): ?><dialog id="content-edit" class="content-editor" aria-labelledby="content-edit-title"<?= $contentItem||($campaignRecovery['action']??'')==='content_save'?' data-reopen-dialog':'' ?>><header class="dialog-heading"><h2 id="content-edit-title"><?= $contentItem?'Edit file':'Upload file' ?></h2><button type="button" data-close-dialog aria-label="Close file">×</button></header><?php if(($campaignRecovery['action']??'')==='content_save'): ?><p class="audience-notice error" role="alert"><?= $h($error) ?></p><?php endif; ?><form method="post" action="<?= $h($contentUrl($contentItem?['item'=>$contentItem['id']]:[])) ?>" enctype="multipart/form-data" data-content-editor><?php $contentForm('content_save',['id'=>$itemValues['id']??0,'version'=>$itemValues['version']??0]); ?><fieldset<?= !$canManage?' disabled':'' ?>><?php $uploadId='content-upload-file';$uploadLabel='Upload or replace file';$uploadKey='image';$uploadAccept='image/png,image/jpeg,image/webp,image/gif,application/pdf';$uploadHelp='PNG, JPEG, WebP, GIF or PDF';$uploadTypes='Choose a PNG, JPEG, WebP, GIF or PDF file.';include __DIR__.'/file-drop.php'; ?><input type="hidden" name="file_kind" value="<?= $h($contentItem['item_type']??'image') ?>"><p class="audience-help">PNG, JPEG and WebP images up to 20 MB are automatically resized to 1 MB or less. Animated images up to 1 MB; PDF documents up to 10 MB. Cropping or rotating an animation keeps only its first frame.</p><?php if(($contentItem['item_type']??'')==='file'): ?><p><a href="<?= $h($url(['view'=>'content','download'=>'file','id'=>$contentItem['id']])) ?>">Download PDF</a></p><?php endif; ?><input type="hidden" name="image" value="<?= $h($itemValues['image']??'') ?>"><img class="content-image-preview" data-content-preview="image" alt="File preview" src="<?= $h($itemValues['image']??'') ?>"<?= empty($itemValues['image'])?' hidden':'' ?>><p data-content-dimensions></p><label>File name<input name="name" maxlength="100" required value="<?= $h($itemValues['name']??'') ?>"></label><label>Alt text<input name="alt" maxlength="300" value="<?= $h($itemValues['alt']??'') ?>"></label><label>Image link<input name="link" maxlength="2048" value="<?= $h($itemValues['url']??$itemValues['link']??'') ?>" placeholder="https://"></label><label>Folder<select name="folder"><option value="">Unfiled</option><?php foreach($contentFolders as$folder): ?><option<?= ($itemValues['folder']??'')===$folder?' selected':'' ?>><?= $h($folder) ?></option><?php endforeach; ?></select></label>
<details class="content-crop"><summary>Edit image</summary><p>Crop and resize a copy. Existing emails keep the image that was inserted.</p><div class="form-grid"><?php foreach(['x'=>'Left','y'=>'Top','width'=>'Crop width','height'=>'Crop height','output_width'=>'Output width','output_height'=>'Output height']as$key=>$label): ?><label><?= $label ?><input type="number" min="<?= in_array($key,['x','y'],true)?0:1 ?>" max="16000" data-content-crop="<?= $key ?>"></label><?php endforeach; ?></div><button type="button" data-content-crop-apply>Apply image edits</button><button type="button" data-content-rotate>Rotate 90°</button></details>
<?php if($contentItem): ?><p class="audience-help"><?= (int)$contentItem['usage'] ?> campaign or template snapshots use this image. Replacing or deleting this file does not change those snapshots.</p><?php endif; ?><p role="status" data-content-status></p><div class="dialog-actions"><button type="button" data-close-dialog>Cancel</button><button class="primary">Save file</button></div></fieldset></form></dialog><?php else: ?><section class="audience-panel"><h2><?= $h($contentItem['name']) ?></h2><p>Saved section · <?= count($contentItem['data']['rows']??[]) ?> layout</p><p>Insert this section from Saved sections in the visual designer.</p><?php if($canManage): ?><form method="post"><?php $contentForm('content_rename',['id'=>$contentItem['id'],'version'=>$contentItem['version']]); ?><label>Section name<input name="name" maxlength="100" required value="<?= $h($contentItem['name']) ?>"></label><button class="primary">Rename section</button></form><?php endif; ?></section><?php endif; ?>
<?php endif; ?>

<datalist id="content-search-options"><?= audienceSearchOptions(array_column($contentLibrary['items']??[],'name')) ?></datalist>
