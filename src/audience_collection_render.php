<?php
declare(strict_types=1);

function audienceCollectionControl(string $name,string $label,string $type,mixed $value,bool $required=false,array $options=[],array $messages=[]): string
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $id='f-'.substr(hash('sha256',$name),0,12); $need=$required?' required':'';
    $messages=array_replace(audienceCollectionMessages(),$messages);$mark=$required?' <span aria-label="'.$h($messages['required']).'">*</span>':''; $v=is_scalar($value)?(string)$value:'';
    if (in_array($type,['radio','checkboxes'],true)) {
        $html='<fieldset><legend>'.$h($label).$mark.'</legend>';
        foreach ($options as $i=>$option) {
            $checked=$type==='checkboxes'?in_array($option,is_array($value)?$value:[],true):$option===$v;
            $html.='<label class="choice"><input type="'.($type==='checkboxes'?'checkbox':'radio').'" name="'.$h($name).($type==='checkboxes'?'[]':'').'" value="'.$h($option).'"'.($checked?' checked':'').($type==='radio'?$need:'').'>'.$h($option==='__message_monkey_other__'?$messages['other']:$option).'</label>';
        }
        return $html.'</fieldset>';
    }
    $html='<label for="'.$id.'">'.$h($label).$mark.'</label>';
    if ($type==='dropdown') {
        $html.='<select id="'.$id.'" name="'.$h($name).'"'.$need.'><option value="">'.$h($messages['choose']).'</option>';
        foreach ($options as $o) $html.='<option'.($v===(string)$o?' selected':'').' value="'.$h($o).'">'.$h($o==='__message_monkey_other__'?$messages['other']:$o).'</option>';
        return $html.'</select>';
    }
    if (in_array($type,['textarea','address'],true)) return $html.'<textarea id="'.$id.'" name="'.$h($name).'" rows="4" maxlength="5000"'.$need.'>'.$h($v).'</textarea>';
    $inputType=match($type){'email'=>'email','date'=>'date','number'=>'number','url'=>'url','phone'=>'tel',default=>'text'};
    return $html.'<input id="'.$id.'" name="'.$h($name).'" type="'.$inputType.'" value="'.$h($v).'" maxlength="2000"'.($type==='number'?' step="any"':'').($type==='birthday'?' placeholder="MM-DD" pattern="[0-9]{2}-[0-9]{2}"':'').$need.'>';
}

/** A complete script-free document, shared by authenticated preview and public rendering. */
function audienceCollectionHtml(array $r,array $fields,array $groups,string $action,string $proof='',array $values=[],string $error='',string $success='',?array $person=null,bool $preview=false,string $unavailable=''): string
{
    $d=array_replace(audienceCollectionDefaults($r['kind']==='confirm'||$r['kind']==='unsubscribe'?'hosted':$r['kind']),$r['document']); $messages=array_replace(audienceCollectionMessages(),$d['messages']); $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); $nonce=contentSecurityPolicyNonce();
    $control=static fn($name,$label,$type,$value,$required=false,$options=[])=>audienceCollectionControl($name,$label,$type,$value,$required,$options,$messages);
    // All pages use their canonical content; historical variation receipts remain readable.
    ob_start();
    ?>
    <!doctype html><html lang="<?= $h($d['language']) ?>" dir="<?= preg_match('/^(he|ar|fa|ur)(-|$)/',$d['language'])?'rtl':'ltr' ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $h($d['title']) ?></title><meta name="description" content="<?= $h($d['description']) ?>"><meta property="og:title" content="<?= $h($d['title']) ?>"><meta property="og:description" content="<?= $h($d['description']) ?>"><?php if($d['favicon']): ?><link rel="icon" href="<?= $h($d['favicon']) ?>"><?php endif; ?>
    <style nonce="<?= $h($nonce) ?>">
    *{box-sizing:border-box}body{margin:0;background:<?= $d['background'] ?>;color:<?= $d['text_color'] ?>;font:17px/1.5 <?= $d['font'] ?>,sans-serif}main{width:calc(100% - 32px);max-width:<?= (int)$d['width'] ?>px;margin:32px auto;padding:32px;background:#fff;border-radius:8px;overflow-wrap:anywhere}h1{font-size:32px;line-height:1.2;margin:0 0 20px}h2{font-size:25px}p{white-space:pre-line;margin:18px 0 26px}.logo{display:block;max-width:240px;max-height:180px;margin:0 auto 28px}label,legend{font-weight:600;display:block;margin:20px 0 8px}input:not([type=checkbox]):not([type=radio]),textarea,select{font:inherit;color:#241c15;background:#fff;width:100%;padding:12px;border:1px solid #747879;border-radius:4px}input[type=checkbox],input[type=radio]{width:20px;height:20px;flex-shrink:0;accent-color:<?= $d['color'] ?>}.choice{display:flex;gap:10px;align-items:flex-start;font-weight:400;margin:16px 0}button,.button{display:inline-block;font:inherit;font-weight:600;padding:13px 24px;border:0;border-radius:4px;background:<?= $d['color'] ?>;color:#fff;cursor:pointer;margin:24px 0 4px}button:hover,.button:hover{filter:brightness(.9);text-decoration:none}button:disabled{cursor:default;opacity:.55}a{color:<?= $d['color'] ?>}fieldset{border:0;padding:0;margin:0}legend{padding:0}.error{background:#fff0ed;border:1px solid #b95c45;padding:16px;color:#6c2718}.success{padding:16px;background:#eff8f0;color:#184e2e;border:1px solid #aacaad}.trap{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden}.section-image{max-width:100%;height:auto}small{display:block;color:#5d6264;margin-top:24px}hr{border:0;border-top:1px solid #d9d9d9;margin:28px 0}input:focus-visible,textarea:focus-visible,select:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #007c89;outline-offset:3px}@media(max-width:500px){main{padding:22px;margin:16px auto}h1{font-size:28px}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto}}
    </style></head><body><main>
    <?php if($d['logo']): ?><img class="logo" src="<?= $h($d['logo']) ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
    <h1><?= $h($d['title']) ?></h1>
    <?php if($success!==''): ?><div class="success" role="status"><p><?= $h($success) ?></p></div>
    <?php else: ?>
    <p><?= $h($d['intro']) ?></p>
    <?php foreach($d['sections']as$s): ?><?php if($s['type']==='heading'): ?><h2><?= $h($s['text']) ?></h2><?php elseif($s['type']==='text'): ?><p><?= $h($s['text']) ?></p><?php elseif($s['type']==='image'&&$s['url']): ?><img class="section-image" src="<?= $h($s['url']) ?>" alt="<?= $h($s['text']) ?>" referrerpolicy="no-referrer"><?php elseif($s['type']==='button'&&$s['url']): ?><a class="button" href="<?= $h($s['url']) ?>" rel="noopener noreferrer"><?= $h($s['text']) ?></a><?php elseif($s['type']==='divider'): ?><hr><?php endif; ?><?php endforeach; ?>
    <?php if($error!==''): ?><div class="error" role="alert" tabindex="-1"><strong><?= $h($messages['error_title']) ?></strong><p><?= $h($messages['invalid']) ?></p></div><?php endif; ?>
    <form method="post" action="<?= $h($action) ?>">
    <?php if($unavailable!==''): ?><div class="error" role="status"><p><?= $h($unavailable) ?></p></div><fieldset disabled><legend class="trap">Signup temporarily unavailable</legend><?php endif; ?>
    <input type="hidden" name="proof" value="<?= $h($proof) ?>"><div class="trap" aria-hidden="true"><label>Leave this field empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <?php if($r['kind']==='unsubscribe'): ?><input type="hidden" name="unsubscribe" value="1">
    <?php elseif($r['kind']==='confirm'): ?><input type="hidden" name="csrf_token" value="<?= $h(generateCsrfToken()) ?>">
    <?php elseif($r['kind']==='survey'): ?>
    <?php foreach($d['questions']as$q): ?>
    <?php if($q['type']==='content'): ?><p><?= $h($q['label']) ?></p><?php elseif($q['type']==='contact'): ?><fieldset><legend><?= $h($q['label']) ?><?= $q['required']?' *':'' ?></legend><?php foreach(['first_name'=>'First name','last_name'=>'Last name','email'=>'Email address','phone'=>'Phone number']as$key=>$label)echo $control('answers['.$q['id'].']['.$key.']',$d['labels'][$key]??$label,$key==='email'?'email':($key==='phone'?'phone':'text'),$values['answers'][$q['id']][$key]??''); ?><small><?= $h($messages['contact_note']) ?></small></fieldset><?php else: $options=$q['type']==='range'?range(1,10):$q['options'];if(!empty($q['other']))$options[]='__message_monkey_other__';echo $control('answers['.$q['id'].']',$q['label'],match($q['type']){'range'=>'dropdown','text'=>'textarea',default=>$q['type']},$values['answers'][$q['id']]??'',$q['required'],$options);if(!empty($q['other']))echo $control('answers_other['.$q['id'].']',$messages['other_answer'],'text',$values['answers_other'][$q['id']]??'');endif; ?>
    <?php endforeach; ?>
    <?php else: ?>
    <?php if($r['kind']==='preferences'&&$person): ?><input type="hidden" name="version" value="<?= (int)$person['version'] ?>"><?php endif; ?>
    <?php foreach($d['fields']as$key): ?>
    <?php if(in_array($key,['email','first_name','last_name'],true)): ?>
    <?php if($key==='email'&&$r['kind']==='preferences'&&$person): ?><p><strong><?= $h($d['labels']['email']??'Email address') ?></strong><br><?= $h($person['email']) ?></p><?php else: echo $control($key,$d['labels'][$key]??match($key){'email'=>'Email address','first_name'=>'First name',default=>'Last name'},$key==='email'?'email':'text',$values[$key]??$person[$key]??'',in_array($key,$d['required'],true)); endif; ?>
    <?php else: foreach($fields as$f)if('custom:'.$f['id']===$key&&$f['is_public'])echo $control('custom['.$f['id'].']',$d['labels'][$key]??$f['label'],$f['field_type'],$values['custom'][$f['id']]??(isset($person['custom_json'])?(audienceJson($person['custom_json'])[$f['id']]??''):''),in_array($key,$d['required'],true),audienceJson($f['options_json'])); endif; ?>
    <?php endforeach; ?>
    <?php foreach($groups as$g): if($g['display_type']==='hidden'||!in_array((int)$g['id'],$d['group_ids'],true))continue; $selected=$values['group_ids']??($person['group_ids']??[]); ?><fieldset><legend><?= $h($g['name']) ?></legend><?php if($g['display_type']==='dropdown'): ?><select name="group_pick[<?= (int)$g['id'] ?>]"><option value=""><?= $h($messages['choose']) ?></option><?php foreach($g['options']as$o): ?><option value="<?= (int)$o['id'] ?>"<?= in_array((int)$o['id'],(array)$selected,true)?' selected':'' ?>><?= $h($o['name']) ?></option><?php endforeach; ?></select><?php else: foreach($g['options']as$o): ?><label class="choice"><input type="<?= $g['display_type']==='radio'?'radio':'checkbox' ?>" name="<?= $g['display_type']==='radio'?'group_pick['.$g['id'].']':'group_ids[]' ?>" value="<?= (int)$o['id'] ?>"<?= in_array((int)$o['id'],(array)$selected,true)?' checked':'' ?>><?= $h($o['name']) ?></label><?php endforeach; endif; ?></fieldset><?php endforeach; ?>
    <?php if($r['kind']!=='preferences'): ?><label class="choice"><input type="checkbox" name="permission" value="1" required<?= ($values['permission']??'')==='1'?' checked':'' ?>><?= $h($d['permission']) ?></label><?php foreach($d['permissions']as$permission): ?><label class="choice"><input type="checkbox" name="permissions[]" value="<?= $h($permission) ?>"<?= in_array($permission,(array)($values['permissions']??[]),true)?' checked':'' ?>><?= $h($permission) ?></label><?php endforeach; ?><?php if($d['quiz']): echo $control('quiz',$messages['quiz'],'radio',$values['quiz']??'',true,array_column($d['quiz'],'label')); endif; ?><?php else: ?><p><?= $h($messages['preferences_note']) ?></p><?php endif; ?>
    <?php endif; ?>
    <button<?= $preview||$unavailable!==''?' disabled':'' ?>><?= $h($d['button']) ?></button>
    <?php if($unavailable!==''): ?></fieldset><?php endif; ?></form><small><?= $preview?'Preview — submissions are disabled.':$messages['powered'] ?></small>
    <?php endif; ?>
    </main></body></html>
    <?php $screen=in_array($r['kind'],['confirm','unsubscribe'],true)?$r['kind']:($success!==''?'thanks':'signup');return audienceCollectionCustomPage((string)ob_get_clean(),$d,$screen);
}
