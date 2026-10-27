<h3>Embed settings</h3>
<label>Content width (pixels)<input type="number" data-doc="width" min="320" max="1200" placeholder="Automatic" value="<?= $d['width']?(int)$d['width']:'' ?>" aria-describedby="collection-embed-width-help"></label>
<p id="collection-embed-width-help" class="audience-help">Leave blank to use the width of the area where the form is placed.</p>
<?php foreach(['show_title'=>'Show form title','remove_css'=>'Remove CSS styles','disable_javascript'=>'Disable all JavaScript','show_format'=>'Show email format options','show_archive'=>'Show archive link','badge'=>'Show MessageMonkey badge']as$key=>$label): ?>
<label class="audience-check"><input type="checkbox" data-embed-option="<?= $key ?>"<?= $d['embedded'][$key]?' checked':'' ?>><?= $label ?></label>
<?php endforeach; ?>
<p class="audience-help">Removing CSS leaves native controls inside the iframe. Your website’s stylesheet does not cross the iframe boundary. Disabling JavaScript uses a fixed-height iframe installation.</p>
<label>Badge style<select data-embed-option="badge_style"><?php foreach(['light'=>'Light','dark'=>'Dark']as$key=>$label): ?><option value="<?= $key ?>"<?= $d['embedded']['badge_style']===$key?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<details><summary>Language and messages</summary>
<label>Language code<input data-doc="language" maxlength="12" required value="<?= $h($d['language']) ?>"></label>
<?php foreach($d['messages']as$key=>$value): ?><label><?= $h(ucfirst(str_replace('_',' ',$key))) ?><input data-doc-message="<?= $h($key) ?>" maxlength="1000" required value="<?= $h($value) ?>"></label><?php endforeach; ?>
</details>
