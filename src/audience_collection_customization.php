<?php
declare(strict_types=1);

function audienceCollectionMessages(): array
{
    return ['choose'=>'Choose an option','required'=>'Required','error_title'=>'Please check your response','invalid'=>'Check the required fields and try again.','contact_note'=>'Providing contact information here does not subscribe you to emails from this audience.','preferences_note'=>'Your subscription status stays unchanged. To unsubscribe, use the unsubscribe link in your email.','quiz'=>'Choose what interests you','other'=>'Other','other_answer'=>'Please specify','powered'=>'Powered by MOED MessageMonkey'];
}

/** Source editing never replaces the native consent form or its submission checks. */
function audienceCollectionAdvancedHtml(string $source): string
{
    if(strlen($source)>60000)throw new InvalidArgumentException('Keep each custom page below 60,000 bytes.');
    if(trim($source)==='')return '';
    $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
    try{$doc->loadHTML('<?xml encoding="UTF-8"><body>'.$source.'</body>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    $walk=static function(DOMNode $node)use(&$walk):string {
        $h=static fn($v)=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if($node instanceof DOMText)return $h($node->textContent);
        if(!$node instanceof DOMElement)return '';
        $tag=strtolower($node->tagName);
        if(in_array($tag,['script','style','iframe','object','embed','svg','math','form','input','textarea','select','button','link','meta','base','template'],true))return '';
        $children='';foreach($node->childNodes as$child)$children.=$walk($child);
        if(!in_array($tag,['div','section','article','header','footer','aside','p','h1','h2','h3','h4','strong','em','b','i','u','s','span','br','hr','ul','ol','li','a','img','table','thead','tbody','tr','th','td','blockquote'],true))return $children;
        $attrs='';
        foreach(['title','alt']as$key)if($node->hasAttribute($key))$attrs.=' '.$key.'="'.$h(mb_substr($node->getAttribute($key),0,300)).'"';
        $style=audienceDesignSafeCss($node->getAttribute('style'),false);
        if($style!=='')$attrs.=' style="'.$h($style).'"';
        if($tag==='a'||$tag==='img'){
            $key=$tag==='a'?'href':'src';$url=$node->getAttribute($key);
            try{$url=audienceCollectionUrl($url,$tag==='img');}catch(InvalidArgumentException){$url='';}
            if($url==='')return $tag==='a'?$children:'';
            $attrs.=' '.$key.'="'.$h($url).'"'.($tag==='a'?' rel="noopener noreferrer"':' referrerpolicy="no-referrer"');
        }
        return '<'.$tag.$attrs.'>'.(in_array($tag,['img','br','hr'],true)?'':$children.'</'.$tag.'>');
    };
    $html='';$body=$doc->getElementsByTagName('body')->item(0);if($body)foreach($body->childNodes as$child)$html.=$walk($child);
    $content=substr_count($html,'{{content}}');$form=substr_count($html,'{{form}}');
    if($content+$form!==1||($form===1&&substr_count($html,'{{message}}')!==1))throw new InvalidArgumentException('Include {{content}} once, or include {{form}} and {{message}} once each.');
    if(preg_match('/<[^>]*\{\{/',$html))throw new InvalidArgumentException('Place page placeholders between HTML tags.');
    return $html;
}

function audienceCollectionCustomPage(string $html,array $d,string $screen): string
{
    $source=$d['advanced']['pages'][$screen]??'';
    if(empty($d['advanced']['enabled'])||$source==='')return $html;
    $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
    try{$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    $main=$doc->getElementsByTagName('main')->item(0);if(!$main)return $html;
    $content='';$form='';$message='';$logo='';
    foreach($main->childNodes as$child){$part=$doc->saveHTML($child);$content.=$part;if($child instanceof DOMElement){if($child->tagName==='form')$form=$part;if(in_array($child->getAttribute('class'),['success','error'],true))$message.=$part;if($child->getAttribute('class')==='logo')$logo=$part;}}
    $h=static fn($v)=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $custom=strtr($source,['{{content}}'=>$content,'{{form}}'=>$form,'{{message}}'=>$message,'{{logo}}'=>$logo,'{{title}}'=>$h($d['title']),'{{intro}}'=>$h($d['intro'])]);
    $html=preg_replace_callback('~(<main>).*?(</main>)~s',static fn($m)=>$m[1].$custom.$m[2],$html,1);
    return audienceDesignPreviewSource($html,contentSecurityPolicyNonce());
}
