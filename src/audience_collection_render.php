<?php
declare(strict_types=1);

function audienceCollectionControl(string $name,string $label,string $type,mixed $value,bool $required=false,array $options=[],array $messages=[],string $help=''): string
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $id='f-'.substr(hash('sha256',$name),0,12); $need=$required?' required':'';
    $description=$help!==''?' aria-describedby="'.$id.'-help"':'';
    $hint=$help!==''?'<small class="collection-field-help" id="'.$id.'-help">'.$h($help).'</small>':'';
    $messages=array_replace(audienceCollectionMessages(),$messages);$mark=$required?' <span aria-label="'.$h($messages['required']).'">*</span>':''; $v=is_scalar($value)?(string)$value:'';
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

/** A complete script-free document, shared by authenticated preview and public rendering. */
function audienceCollectionHtml(array $r,array $fields,array $groups,string $action,string $proof='',array $values=[],string $error='',string $success='',?array $person=null,bool $preview=false,string $unavailable='',string $previewNonce=''): string
{
    $d=array_replace(audienceCollectionDefaults($r['kind']==='confirm'||$r['kind']==='unsubscribe'?'hosted':$r['kind']),$r['document']); $messages=array_replace(audienceCollectionMessages(),$d['messages']); $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); $nonce=contentSecurityPolicyNonce();
    if($preview&&$previewNonce!==''){
        if(!preg_match('~^[a-zA-Z0-9+/=]{8,100}$~D',$previewNonce))throw new InvalidArgumentException('Reload the form preview.');
        $nonce=$previewNonce;
    }
    $embedded=$r['kind']==='embedded';$embedOptions=array_replace(audienceCollectionEmbedDefaults(),$d['embedded']);
    $control=static fn($name,$label,$type,$value,$required=false,$options=[],$help='')=>audienceCollectionControl($name,$label,$type,$value,$required,$options,$messages,$help);
    // All pages use their canonical content; historical variation receipts remain readable.
    ob_start();
    ?>
    <!doctype html><html lang="<?= $h($d['language']) ?>" dir="<?= preg_match('/^(he|ar|fa|ur)(-|$)/',$d['language'])?'rtl':'ltr' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $h($d['title']) ?></title><meta name="description" content="<?= $h($d['description']) ?>"><meta property="og:title" content="<?= $h($d['title']) ?>"><meta property="og:description" content="<?= $h($d['description']) ?>"><?php if($d['favicon']): ?><link rel="icon" href="<?= $h($d['favicon']) ?>"><?php endif; ?>
    <style nonce="<?= $h($nonce) ?>">
    :root{--collection-accent:<?= $d['color'] ?>;--collection-background:<?= $d['background'] ?>;--collection-text:<?= $d['text_color'] ?>;--collection-font:<?= $d['font'] ?>;--collection-width:<?= $d['width']?(int)$d['width'].'px':'100%' ?>}
    <?php if(!$embedded||!$embedOptions['remove_css']): ?>
    <?= file_get_contents(__DIR__.'/assets/css/pages/audience-collection-public.min.css') ?>
    <?php // Keep branded public pages self-contained; share only native-control normalization. ?>
    <?= file_get_contents(__DIR__.'/assets/css/pages/native-selects.min.css') ?>
    <?php endif; ?>
    </style></head><body<?= $embedded?' class="collection-embedded"':'' ?>><main>
    <?php if($d['logo']): ?><img class="logo" src="<?= $h($d['logo']) ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
    <?php if(!$embedded||$embedOptions['show_title']): ?><h1><?= $h($d['title']) ?></h1><?php endif; ?>
    <?php if($success!==''): ?><div class="success" role="status"><p><?= $h($success) ?></p></div>
    <?php else: ?>
    <?php if($d['intro']!==''): ?><p class="collection-intro"><?= $h($d['intro']) ?></p><?php endif; ?>
    <?php foreach($d['sections']as$s): ?><?php if($s['type']==='heading'): ?><h2><?= $h($s['text']) ?></h2><?php elseif($s['type']==='text'): ?><p><?= $h($s['text']) ?></p><?php elseif($s['type']==='image'&&$s['url']): ?><img class="section-image" src="<?= $h($s['url']) ?>" alt="<?= $h($s['text']) ?>" referrerpolicy="no-referrer"><?php elseif($s['type']==='button'&&$s['url']): ?><a class="button" href="<?= $h($s['url']) ?>" rel="noopener noreferrer"><?= $h($s['text']) ?></a><?php elseif($s['type']==='divider'): ?><hr><?php endif; ?><?php endforeach; ?>
    <?php if($error!==''): ?><div class="error" role="alert" tabindex="-1"><strong><?= $h($messages['error_title']) ?></strong><p><?= $h($messages['invalid']) ?></p></div><?php endif; ?>
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
    <?php if($r['kind']!=='preferences'): ?><label class="choice"><input type="checkbox" name="permission" value="1" required<?= ($values['permission']??'')==='1'?' checked':'' ?>><?= $h($d['permission']) ?></label><?php foreach($d['permissions']as$permission): ?><label class="choice"><input type="checkbox" name="permissions[]" value="<?= $h($permission) ?>"<?= in_array($permission,(array)($values['permissions']??[]),true)?' checked':'' ?>><?= $h($permission) ?></label><?php endforeach; ?><?php if($d['quiz']): echo $control('quiz',$messages['quiz'],'radio',$values['quiz']??'',true,array_column($d['quiz'],'label')); endif; ?><?php else: ?><p><?= $h($messages['preferences_note']) ?></p><?php endif; ?>
    <?php endif; ?>
    <button<?= $preview||$unavailable!==''?' disabled':'' ?>><?= $h($d['button']) ?></button>
    <?php if($unavailable!==''): ?></fieldset><?php endif; ?></form>
    <?php if($embedded): ?>
    <?php if($embedOptions['show_archive']&&($r['archive_url']??'')!==''): ?><p class="collection-archive"><a href="<?= $h($r['archive_url']) ?>" target="_blank" rel="noopener">View previous emails</a></p><?php endif; ?>
    <?php if($embedOptions['badge']): ?><div class="collection-referral collection-referral-<?= $h($embedOptions['badge_style']) ?>"><span aria-hidden="true">MM</span><strong>Made with<br>MessageMonkey</strong></div><?php endif; ?>
    <?php else: ?><small><?= $preview?'Preview — submissions are disabled.':$messages['powered'] ?></small><?php endif; ?>
    <?php endif; ?>
    </main>
    <?php if($embedded&&!$preview&&!$embedOptions['disable_javascript']&&isset($r['resize_script'])): ?>
    <script type="application/json" id="collection-resize-config"><?= json_encode(['key'=>$r['public_key'],'origins'=>$d['origins']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
    <script nonce="<?= $h($nonce) ?>" src="<?= $h($r['resize_script']) ?>" defer></script>
    <?php endif; ?>
    </body></html>
    <?php $screen=in_array($r['kind'],['confirm','unsubscribe'],true)?$r['kind']:($success!==''?'thanks':'signup');return audienceCollectionCustomPage((string)ob_get_clean(),$d,$screen,$nonce);
}
