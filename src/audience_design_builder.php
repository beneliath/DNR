<?php
declare(strict_types=1);

/** Brand Kit owns new footer identity; older accounts retain their existing settings until saved. */
function audienceBrandIdentity(array $settings,string $audienceName=''):array
{
    $brand=is_array($settings['brand']??null)?$settings['brand']:[];
    $name=trim((string)($brand['name']??''));if($name==='')$name=trim((string)($settings['from_name']??''))?:$audienceName;
    $address=(string)(array_key_exists('mailing_address',$brand)?$brand['mailing_address']:($settings['mailing_address']??''));
    return ['LIST:COMPANY'=>$name,'LIST:ADDRESS'=>$address,'LIST:ADDRESSLINE'=>$address];
}

/** Named regions and typography remain part of the existing versioned JSON document. */
function audienceDesignBuilderSettings(array $input):array
{
    $regions=$input['regions']??[];$typography=$input['typography']??[];$preheader=$input['preheader']??[];$badge=$input['badge']??[];
    if(!is_array($regions)||!is_array($typography)||!is_array($preheader)||!is_array($badge))throw new InvalidArgumentException('Invalid email region styles.');
    $out=['regions'=>[],'typography'=>[],'badge'=>['enabled'=>!empty($badge['enabled']),'align'=>in_array($badge['align']??'', ['left','center','right'],true)?$badge['align']:'center'],'preheader'=>['text'=>audienceDesignString($preheader['text']??'',300),'showArchive'=>!empty($preheader['showArchive'])]];
    foreach(['page','preheader','header','body','footer']as$key)$out['regions'][$key]=audienceDesignStyle($regions[$key]??[]);
    foreach(['paragraph','heading1','heading2','heading3','heading4']as$key)$out['typography'][$key]=audienceDesignStyle($typography[$key]??[]);
    return $out;
}
function audienceDesignShare(array $platforms,?array $context):string
{
    $archive=$context['ARCHIVE']??'';$hasArchive=is_string($archive)&&str_starts_with($archive,'https://')&&audienceDesignUrl($archive);
    $url=$hasArchive?rawurlencode($archive):'';$out='';
    foreach($platforms as$platform){[$label,$target]=match($platform){'facebook'=>['Facebook','https://www.facebook.com/sharer/sharer.php?u='.$url],'x'=>['X','https://twitter.com/intent/tweet?url='.$url],'linkedin'=>['LinkedIn','https://www.linkedin.com/sharing/share-offsite/?url='.$url],default=>['Email','mailto:?body='.$url]};$text='Share on '.$label;
        $out.=$hasArchive?'<a href="'.htmlspecialchars($target,ENT_QUOTES,'UTF-8').'" style="display:inline-block;margin:8px">'.$text.'</a>':'<span style="display:inline-block;margin:8px">'.$text.'</span>';
    }return $out;
}
/** A preview-only color adaptation: photographs and their pixels are never filtered. */
function audienceDesignDarkCss(string $css):string
{
    return preg_replace_callback('/((?:^|[;{])\s*)(background(?:-color)?|color|border(?:-top|-bottom|-left|-right)?-color)\s*:\s*(#[a-f0-9]{6}\b|#[a-f0-9]{3}\b)/i',static function($m):string{
        $hex=substr($m[3],1);if(strlen($hex)===3)$hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];$rgb=[hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];$luma=(.2126*$rgb[0]+.7152*$rgb[1]+.0722*$rgb[2])/255;
        $background=str_starts_with(strtolower($m[2]),'background');$color=$m[3];
        if($background&&$luma>.48)$color=$luma>.9?'#171d27':'#303b49';
        elseif(!$background&&$luma<.6){if(max($rgb)-min($rgb)<45)$color='#e4e9f0';else{$light=array_map(static fn($v)=>(int)round($v+(255-$v)*.6),$rgb);$color=sprintf('#%02x%02x%02x',...$light);}}
        return $m[1].$m[2].':'.$color;
    },$css)??$css;
}
