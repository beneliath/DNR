<?php
declare(strict_types=1);

function audienceCollectionHasAddress(array $resource,array $fields):bool
{
    foreach($fields as$field)if($field['is_public']&&$field['field_type']==='address'&&in_array('custom:'.$field['id'],$resource['document']['fields']??[],true))return true;
    return false;
}

function audienceCollectionHasPhone(array $resource,array $fields):bool
{
    if(($resource['kind']??'')==='survey')foreach($resource['document']['questions']??[]as$question)if($question['type']==='contact')return true;
    foreach($fields as$field)if($field['is_public']&&$field['field_type']==='phone'&&in_array('custom:'.$field['id'],$resource['document']['fields']??[],true))return true;
    return false;
}

function audienceCollectionPhoneControl(string $name,string $label,mixed $value,bool $required,array $messages,string $help,bool $native):string
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $id='f-'.substr(hash('sha256',$name),0,12);
    if(is_array($value)){
        $country=is_scalar($value['country_code']??null)?(string)$value['country_code']:applicationDefaultPhoneCountryCode();
        $number=is_scalar($value['number']??null)?(string)$value['number']:'';
    }else [$country,$number]=phoneNumberInputParts(is_scalar($value)?(string)$value:'');
    $mark=$required?' <span aria-label="'.$h($messages['required']).'">*</span>':'';
    $html='<label for="'.$id.'">'.$h($label).$mark.'</label><div class="collection-phone phone-input-group" data-phone-input-group>';
    $html.=phoneCountryPicker($name.'[country_code]',$country,$label.' country code',$native);
    $html.='<input type="tel" id="'.$id.'" name="'.$h($name.'[number]').'" value="'.$h($number).'" maxlength="80" autocomplete="tel-national" inputmode="tel" data-phone-number'.($required?' required':'').($help!==''?' aria-describedby="'.$id.'-help"':'').'></div>';
    if($help!=='')$html.='<small class="collection-field-help" id="'.$id.'-help">'.$h($help).'</small>';
    return $html;
}

function audienceCollectionAddressControl(string $name,string $label,mixed $value,bool $required,array $messages,string $help):string
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $id='address-'.substr(hash('sha256',$name),0,12);$parts=audienceAddressParts($value);
    $mark=$required?' <span aria-label="'.$h($messages['required']).'">*</span>':'';
    $html='<fieldset class="collection-address address-section" aria-label="'.$h($label).'"'.($help!==''?' aria-describedby="'.$id.'-help"':'').'><div class="collection-address-grid">';
    $labels=['address1'=>'Address Line 1','address2'=>'Address Line 2','city'=>'City','region'=>'State / Province','postal_code'=>'Postal Code'];
    $autocomplete=['address1'=>'address-line1','address2'=>'address-line2','city'=>'address-level2','region'=>'address-level1','postal_code'=>'postal-code'];
    foreach($labels as$key=>$text){
        $wide=in_array($key,['address1','address2'],true);
        $partRequired=$required&&$key!=='address2';
        $html.='<div'.($wide?' class="collection-address-wide"':'').($key==='region'?' data-address-region-control data-address-region-for="'.$id.'" data-region-required="'.($required?'true':'false').'"':'').'>';
        $html.='<label for="'.$id.'-'.$key.'">'.$text.($partRequired?$mark:'').'</label><input type="text" id="'.$id.'-'.$key.'" name="'.$h($name.'['.$key.']').'" value="'.$h($parts[$key]).'" maxlength="300" autocomplete="section-'.$id.' '.$autocomplete[$key].'"'.($partRequired?' required':'').($key==='region'?' data-address-region-input':'').'></div>';
    }
    $html.='<div class="collection-address-wide"><label for="'.$id.'-country">Country'.$mark.'</label>'.addressCountryPicker($name.'[country]',$parts['country']?:applicationDefaultCountry(),$id,$required,true).'</div></div>';
    if($help!=='')$html.='<small class="collection-field-help" id="'.$id.'-help">'.$h($help).'</small>';
    return $html.'</fieldset>';
}

function audienceCollectionControl(string $name,string $label,string $type,mixed $value,bool $required=false,array $options=[],array $messages=[],string $help='',bool $nativePhone=false): string
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $id='f-'.substr(hash('sha256',$name),0,12); $need=$required?' required':'';
    $description=$help!==''?' aria-describedby="'.$id.'-help"':'';
    $hint=$help!==''?'<small class="collection-field-help" id="'.$id.'-help">'.$h($help).'</small>':'';
    $messages=array_replace(audienceCollectionMessages(),$messages);$mark=$required?' <span aria-label="'.$h($messages['required']).'">*</span>':''; $v=is_scalar($value)?(string)$value:'';
    if($type==='address'&&(!is_string($value)||substr_count($value,"\n")<=5))return audienceCollectionAddressControl($name,$label,$value,$required,$messages,$help);
    if($type==='phone')return audienceCollectionPhoneControl($name,$label,$value,$required,$messages,$help,$nativePhone);
    if (in_array($type,['radio','checkboxes'],true)) {
        $html='<fieldset'.$description.'><legend>'.$h($label).$mark.'</legend>';
        foreach ($options as $i=>$option) {
            $checked=$type==='checkboxes'?in_array($option,is_array($value)?$value:[],true):$option===$v;
            $html.='<label class="choice"><input type="'.($type==='checkboxes'?'checkbox':'radio').'" name="'.$h($name).($type==='checkboxes'?'[]':'').'" value="'.$h($option).'"'.($checked?' checked':'').($type==='radio'?$need:'').'>'.$h($option==='__message_monkey_other__'?$messages['other']:$option).'</label>';
        }
        return $html.$hint.'</fieldset>';
    }
    $html='<label for="'.$id.'">'.$h($label).$mark.'</label>';
    if ($type==='dropdown') {
        $html.='<select id="'.$id.'" name="'.$h($name).'"'.$need.$description.'><option value="">'.$h($messages['choose']).'</option>';
        foreach ($options as $o) $html.='<option'.($v===(string)$o?' selected':'').' value="'.$h($o).'">'.$h($o==='__message_monkey_other__'?$messages['other']:$o).'</option>';
        return $html.'</select>'.$hint;
    }
    if (in_array($type,['textarea','address'],true)) return $html.'<textarea id="'.$id.'" name="'.$h($name).'" rows="4" maxlength="5000"'.$need.$description.'>'.$h($v).'</textarea>'.$hint;
    $inputType=match($type){'email'=>'email','date'=>'date','number'=>'number','url'=>'url','phone'=>'tel',default=>'text'};
    return $html.'<input id="'.$id.'" name="'.$h($name).'" type="'.$inputType.'" value="'.$h($v).'" maxlength="2000"'.($type==='number'?' step="any"':'').($type==='birthday'?' placeholder="MM-DD" pattern="[0-9]{2}-[0-9]{2}"':'').$need.$description.'>'.$hint;
}

/** Shared branded document; preview controls use trusted application code without executing frame scripts. */
function audienceCollectionHtml(array $r,array $fields,array $groups,string $action,string $proof='',array $values=[],string|InvalidArgumentException $error='',string $success='',?array $person=null,bool $preview=false,string $unavailable='',string $previewNonce=''): string
{
    $d=array_replace(audienceCollectionDefaults($r['kind']==='confirm'||$r['kind']==='unsubscribe'?'hosted':$r['kind']),$r['document']); $messages=array_replace(audienceCollectionMessages(),$d['messages']); $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); $nonce=contentSecurityPolicyNonce();
    $publicErrorKey=$error instanceof AudienceRateLimitException&&in_array($error->publicMessageKey,['confirmation_limit','confirmation_wait','rate_limit'],true)?$error->publicMessageKey:'invalid';
    if($preview&&$previewNonce!==''){
        if(!preg_match('~^[a-zA-Z0-9+/=]{8,100}$~D',$previewNonce))throw new InvalidArgumentException('Reload the form preview.');
        $nonce=$previewNonce;
    }
    $embedded=$r['kind']==='embedded';$embedOptions=array_replace(audienceCollectionEmbedDefaults(),$d['embedded']);
    $hasAddress=audienceCollectionHasAddress($r,$fields);
    $hasPhone=audienceCollectionHasPhone($r,$fields);
    $nativeAddress=$embedded&&($embedOptions['disable_javascript']||$embedOptions['remove_css']);
    $control=static fn($name,$label,$type,$value,$required=false,$options=[],$help='')=>audienceCollectionControl($name,$label,$type,$value,$required,$options,$messages,$help,$nativeAddress);
    // All pages use their canonical content; historical variation receipts remain readable.
    ob_start();
    ?>
    <!doctype html><html<?= $nativeAddress?' data-address-inputs="native" data-phone-inputs="native"':'' ?> lang="<?= $h($d['language']) ?>" dir="<?= preg_match('/^(he|ar|fa|ur)(-|$)/',$d['language'])?'rtl':'ltr' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $h($d['title']) ?></title><meta name="description" content="<?= $h($d['description']) ?>"><meta property="og:title" content="<?= $h($d['title']) ?>"><meta property="og:description" content="<?= $h($d['description']) ?>"><?php if($d['favicon']): ?><link rel="icon" href="<?= $h($d['favicon']) ?>"><?php endif; ?>
    <style nonce="<?= $h($nonce) ?>">
    <?php if($d['web_font']!==''): ?>@font-face{font-family:MOEDCollectionBrandFont;src:url(<?= audienceDesignWebFont($d['web_font']) ?>);font-display:swap}<?php endif; ?>
    :root{--collection-accent:<?= $d['color'] ?>;--collection-background:<?= $d['background'] ?>;--collection-text:<?= $d['text_color'] ?>;--collection-font:<?= $d['web_font']!==''?'MOEDCollectionBrandFont,':'' ?><?= $d['font'] ?>;--collection-width:<?= $d['width']?(int)$d['width'].'px':'100%' ?><?php if($r['kind']==='landing'||!empty($d['logo_resized'])): ?>;--collection-logo-width:<?= (int)$d['logo_width'] ?>px;--collection-logo-height:none<?php endif; ?>}
    <?php if(!$embedded||!$embedOptions['remove_css']): ?>
    <?= file_get_contents(__DIR__.'/assets/css/pages/audience-collection-public.min.css') ?>
    <?php // Keep branded public pages self-contained; share only native-control normalization. ?>
    <?= file_get_contents(__DIR__.'/assets/css/pages/native-selects.min.css') ?>
    <?php endif; ?>
    <?php if($preview): ?>
    .collection-editable-block{position:relative;min-height:24px;cursor:pointer}
    .collection-editable-block:hover,.collection-editable-block:focus-within,.collection-editable-block.is-selected{outline:3px double #007c89;outline-offset:4px}
    button.collection-block-remove{position:absolute;z-index:2;top:0;right:0;display:flex;align-items:center;justify-content:center;width:32px;height:32px;min-height:32px;margin:0;padding:0;border:1px solid #007c89;border-radius:4px;background:#fff;color:#007c89;font:24px/1 Arial;cursor:pointer;opacity:0}
    .collection-editable-block:hover>.collection-block-remove,.collection-editable-block:focus-within>.collection-block-remove,.collection-editable-block.is-selected>.collection-block-remove{opacity:1}
    button.collection-block-remove:hover,button.collection-block-remove:focus-visible{background:#007c89;color:#fff;outline:2px solid #007c89;outline-offset:2px}
    .collection-image-block{max-width:100%}
    .collection-logo-block{margin:0 auto 32px}
    .collection-image-block>.collection-block-remove{top:-8px;left:-8px;right:auto}
    .collection-image-block>img{display:block;margin:0}
    button.collection-image-resize{position:absolute;z-index:3;bottom:-8px;right:-8px;display:flex;align-items:center;justify-content:center;width:26px;height:26px;min-height:26px;margin:0;padding:0;border:2px solid #fff;border-radius:4px;background:#007c89;color:#fff;font:20px/1 Arial;cursor:nwse-resize;touch-action:none;opacity:0}
    .collection-image-block:hover>.collection-image-resize,.collection-image-block:focus-within>.collection-image-resize,.collection-image-block.is-selected>.collection-image-resize{opacity:1}
    button.collection-image-resize:focus-visible{outline:3px solid #007c89;outline-offset:2px}
    <?php endif; ?>
    </style></head><body<?= $embedded?' class="collection-embedded"':'' ?>><main>
    <?php if($d['logo']): ?><img class="logo" src="<?= $h($d['logo']) ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
    <?php if($d['title']!==''&&(!$embedded||$embedOptions['show_title'])): ?><h1><?= $h($d['title']) ?></h1><?php endif; ?>
    <?php if($success!==''): ?><div class="success" role="status"><p><?= $h($success) ?></p></div>
    <?php else: ?>
    <?php if($d['intro']!==''): ?><p class="collection-intro"><?= $h($d['intro']) ?></p><?php endif; ?>
    <?php foreach($d['sections']as$sectionIndex=>$s): ?><?php if($preview): ?><div data-collection-section="<?= $sectionIndex ?>"><?php endif; ?><?php if($s['type']==='heading'): ?><h2><?= $h($s['text']) ?></h2><?php elseif($s['type']==='text'): ?><p><?= $h($s['text']) ?></p><?php elseif($s['type']==='image'&&$s['url']): ?><img class="section-image"<?php if(isset($s['width'])): ?> width="<?= (int)$s['width'] ?>"<?php endif; ?> src="<?= $h($s['url']) ?>" alt="<?= $h($s['text']) ?>" referrerpolicy="no-referrer"><?php elseif($s['type']==='button'&&$s['url']): ?><a class="button" href="<?= $h($s['url']) ?>" rel="noopener noreferrer"><?= $h($s['text']) ?></a><?php elseif($s['type']==='divider'): ?><hr><?php endif; ?><?php if($preview): ?></div><?php endif; ?><?php endforeach; ?>
    <?php if($error!==''): ?><div class="error" role="alert" tabindex="-1"><strong><?= $h($messages['error_title']) ?></strong><p><?= $h($messages[$publicErrorKey]) ?></p></div><?php endif; ?>
    <form method="post" action="<?= $h($action) ?>">
    <?php if($unavailable!==''): ?><div class="error" role="status"><p><?= $h($unavailable) ?></p></div><fieldset disabled><legend class="trap">Signup temporarily unavailable</legend><?php endif; ?>
    <input type="hidden" name="proof" value="<?= $h($proof) ?>"><div class="trap" hidden aria-hidden="true"><label>Leave this field empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <?php if($r['kind']==='unsubscribe'): ?><input type="hidden" name="unsubscribe" value="1">
    <?php elseif($r['kind']==='confirm'): ?><input type="hidden" name="csrf_token" value="<?= $h(generateCsrfToken()) ?>">
    <?php elseif($r['kind']==='survey'): ?>
    <?php foreach($d['questions']as$q): ?>
    <?php if($q['type']==='content'): ?><p><?= $h($q['label']) ?></p><?php elseif($q['type']==='contact'): ?><fieldset><legend><?= $h($q['label']) ?><?= $q['required']?' *':'' ?></legend><?php foreach(['first_name'=>'First name','last_name'=>'Last name','email'=>'Email address','phone'=>'Phone number']as$key=>$label)echo $control('answers['.$q['id'].']['.$key.']',$d['labels'][$key]??$label,$key==='email'?'email':($key==='phone'?'phone':'text'),$values['answers'][$q['id']][$key]??''); ?><small><?= $h($messages['contact_note']) ?></small></fieldset><?php else: $options=$q['type']==='range'?range(1,10):$q['options'];if(!empty($q['other']))$options[]='__message_monkey_other__';echo $control('answers['.$q['id'].']',$q['label'],match($q['type']){'range'=>'dropdown','text'=>'textarea',default=>$q['type']},$values['answers'][$q['id']]??'',$q['required'],$options);if(!empty($q['other']))echo $control('answers_other['.$q['id'].']',$messages['other_answer'],'text',$values['answers_other'][$q['id']]??'');endif; ?>
    <?php endforeach; ?>
    <?php else: ?>
    <?php if($r['kind']==='preferences'&&$person): ?><input type="hidden" name="version" value="<?= (int)$person['version'] ?>"><?php endif; ?>
    <div class="collection-fields">
    <?php if($embedded): ?><p class="collection-required-note"><span>*</span> indicates required</p><?php endif; ?>
    <?php foreach($d['fields']as$key): ?><div class="collection-field<?= in_array($key,['first_name','last_name'],true)?' collection-name-field':'' ?>">
    <?php if(in_array($key,['email','first_name','last_name'],true)): ?>
    <?php if($key==='email'&&$r['kind']==='preferences'&&$person): ?><p><strong><?= $h($d['labels']['email']??'Email address') ?></strong><br><?= $h($person['email']) ?></p><?php else: echo $control($key,$d['labels'][$key]??match($key){'email'=>'Email address','first_name'=>'First name',default=>'Last name'},$key==='email'?'email':'text',$values[$key]??$person[$key]??'',in_array($key,$d['required'],true),[],$d['help_text'][$key]??''); endif; ?>
    <?php else: foreach($fields as$f)if('custom:'.$f['id']===$key&&$f['is_public'])echo $control('custom['.$f['id'].']',$d['labels'][$key]??$f['label'],$f['field_type'],$values['custom'][$f['id']]??(isset($person['custom_json'])?(audienceJson($person['custom_json'])[$f['id']]??''):''),in_array($key,$d['required'],true),audienceJson($f['options_json']),$d['help_text'][$key]??''); endif; ?>
    </div><?php endforeach; ?></div>
    <?php foreach($groups as$g): if($g['display_type']==='hidden'||!in_array((int)$g['id'],$d['group_ids'],true))continue; $selected=$values['group_ids']??($person['group_ids']??[]); ?><fieldset><legend><?= $h($g['name']) ?></legend><?php if($g['display_type']==='dropdown'): ?><select name="group_pick[<?= (int)$g['id'] ?>]"><option value=""><?= $h($messages['choose']) ?></option><?php foreach($g['options']as$o): ?><option value="<?= (int)$o['id'] ?>"<?= in_array((int)$o['id'],(array)$selected,true)?' selected':'' ?>><?= $h($o['name']) ?></option><?php endforeach; ?></select><?php else: foreach($g['options']as$o): ?><label class="choice"><input type="<?= $g['display_type']==='radio'?'radio':'checkbox' ?>" name="<?= $g['display_type']==='radio'?'group_pick['.$g['id'].']':'group_ids[]' ?>" value="<?= (int)$o['id'] ?>"<?= in_array((int)$o['id'],(array)$selected,true)?' checked':'' ?>><?= $h($o['name']) ?></label><?php endforeach; endif; ?></fieldset><?php endforeach; ?>
    <?php if($embedded&&$embedOptions['show_format']): ?><fieldset class="collection-format"><legend>Email format</legend><?php foreach(['html'=>'HTML','text'=>'Plain text']as$format=>$label): ?><label class="choice"><input type="radio" name="email_format" value="<?= $format ?>"<?= ($values['email_format']??'html')===$format?' checked':'' ?>><?= $label ?></label><?php endforeach; ?></fieldset><?php endif; ?>
    <?php if($r['kind']!=='preferences'): ?><label class="choice"><input type="checkbox" name="permission" value="1" required<?= ($values['permission']??'')==='1'?' checked':'' ?>><?= $h($d['permission']) ?></label><?php foreach($d['permissions']as$permission): ?><label class="choice"><input type="checkbox" name="permissions[]" value="<?= $h($permission) ?>"<?= in_array($permission,(array)($values['permissions']??[]),true)?' checked':'' ?>><?= $h($permission) ?></label><?php endforeach; ?><?php if($d['quiz']): echo $control('quiz',$messages['quiz'],'radio',$values['quiz']??'',true,array_column($d['quiz'],'label')); endif; ?><?php else: ?><p class="collection-preferences-note"><?= $h($messages['preferences_note']) ?></p><?php endif; ?>
    <?php endif; ?>
    <button<?= $preview||$unavailable!==''?' disabled':'' ?>><?= $h($d['button']) ?></button>
    <?php if($unavailable!==''): ?></fieldset><?php endif; ?></form>
    <?php if($embedded): ?>
    <?php if($embedOptions['show_archive']&&($r['archive_url']??'')!==''): ?><p class="collection-archive"><a href="<?= $h($r['archive_url']) ?>" target="_blank" rel="noopener">View previous emails</a></p><?php endif; ?>
    <?php if($embedOptions['badge']): ?><div class="collection-referral collection-referral-<?= $h($embedOptions['badge_style']) ?>"><span aria-hidden="true">MM</span><strong>Made with<br>MessageMonkey</strong></div><?php endif; ?>
    <?php else: ?>
    <?php if($d['show_footer']): ?><small class="collection-footer-credit"><?= $h($messages['powered']) ?></small><?php endif; ?>
    <?php if($preview): ?><small>Preview — submissions are disabled.</small><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    </main>
    <?php if($hasAddress): ?>
    <script type="application/json" id="address-region-data" nonce="<?= $h($nonce) ?>"><?= json_encode(addressRegionClientData(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
    <?php if(!$preview&&!$nativeAddress): ?><script nonce="<?= $h($nonce) ?>" src="<?= $h(assetUrl('assets/js/address-inputs-public.min.js')) ?>" defer></script><?php endif; ?>
    <?php endif; ?>
    <?php if(!$preview&&!$nativeAddress&&!in_array($r['kind'],['confirm','unsubscribe'],true)): ?><script nonce="<?= $h($nonce) ?>" src="<?= $h(assetUrl('assets/js/audience-collection-public.min.js')) ?>" defer></script><?php endif; ?>
    <?php if($hasPhone&&!$preview&&!$nativeAddress): ?><script nonce="<?= $h($nonce) ?>" src="<?= $h(assetUrl('assets/js/phone-input.min.js')) ?>" defer></script><?php endif; ?>
    <?php if($embedded&&!$preview&&!$embedOptions['disable_javascript']&&isset($r['resize_script'])): ?>
    <script type="application/json" id="collection-resize-config"><?= json_encode(['key'=>$r['public_key'],'origins'=>$d['origins']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
    <script nonce="<?= $h($nonce) ?>" src="<?= $h($r['resize_script']) ?>" defer></script>
    <?php endif; ?>
    </body></html>
    <?php $screen=in_array($r['kind'],['confirm','unsubscribe'],true)?$r['kind']:($success!==''?'thanks':'signup');return audienceCollectionCustomPage((string)ob_get_clean(),$d,$screen,$nonce);
}
