<?php
declare(strict_types=1);

/** Keep uploaded logos as self-contained, static SVG graphics. */
function audienceSvgImage(string $image,int $maxBytes=2000000,?array $canvas=null):string
{
    if(!preg_match('~^data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)$~D',$image,$match))throw new InvalidArgumentException('Upload a valid SVG logo.');
    $source=base64_decode($match[1],true);
    if($source===false||strlen($source)>$maxBytes||preg_match('/<!DOCTYPE|<!ENTITY/i',$source))throw new InvalidArgumentException('Use a self-contained SVG logo up to 2 MB without document entities.');
    $document=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try{$loaded=$document->loadXML($source,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    $root=$document->documentElement;
    if(!$loaded||!$root||$root->localName!=='svg'||!in_array($root->namespaceURI,['http://www.w3.org/2000/svg',null,''],true))throw new InvalidArgumentException('Upload a valid SVG logo.');
    $number='[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?';
    $viewBox=preg_split('/[\s,]+/',trim($root->getAttribute('viewBox')),-1,PREG_SPLIT_NO_EMPTY);
    $validViewBox=count($viewBox)===4;foreach($viewBox as$value)if(!preg_match('/^'.$number.'$/D',$value)||!is_finite((float)$value)||abs((float)$value)>1e9)$validViewBox=false;
    if($validViewBox&&((float)$viewBox[2]<=0||(float)$viewBox[3]<=0))$validViewBox=false;
    $length=static function(string $value)use($number):float{
        if(!preg_match('/^('.$number.')(px|pt|pc|in|cm|mm)?$/D',trim($value),$parts))return 0;
        $pixels=(float)$parts[1]*match($parts[2]??''){'pt'=>96/72,'pc'=>16,'in'=>96,'cm'=>96/2.54,'mm'=>96/25.4,default=>1};
        return is_finite($pixels)&&$pixels>0&&$pixels<=1e9?$pixels:0;
    };
    $width=$length($root->getAttribute('width'));$height=$length($root->getAttribute('height'));
    if($validViewBox){if(!$width&&!$height){$width=(float)$viewBox[2];$height=(float)$viewBox[3];}elseif(!$width)$width=$height*(float)$viewBox[2]/(float)$viewBox[3];elseif(!$height)$height=$width*(float)$viewBox[3]/(float)$viewBox[2];}
    if(!$width||!$height)throw new InvalidArgumentException('The SVG logo needs a valid viewBox or width and height.');
    if(!$validViewBox)$viewBox=[0,0,$width,$height];
    $scale=min(1,2400/max($width,$height));$width=max(1,(int)round($width*$scale));$height=max(1,(int)round($height*$scale));
    if($canvas){[$width,$height]=$canvas;if($width<1||$height<1||$width*$height>16000000)throw new InvalidArgumentException('Choose a smaller logo canvas.');}
    $elements=array_flip(['svg','g','path','rect','circle','ellipse','line','polyline','polygon','defs','linearGradient','radialGradient','stop','clipPath','mask','pattern','use','symbol','title','desc','text','tspan','style','filter','feGaussianBlur','feOffset','feColorMatrix','feBlend','feComposite','feFlood','feMerge','feMergeNode','feDropShadow']);
    $attributes=array_flip(explode(' ','id class d fill fill-rule fill-opacity stroke stroke-width stroke-linecap stroke-linejoin stroke-miterlimit stroke-dasharray stroke-dashoffset stroke-opacity opacity transform x y x1 y1 x2 y2 width height rx ry cx cy r points viewBox preserveAspectRatio gradientUnits gradientTransform spreadMethod offset stop-color stop-opacity clip-path clip-rule mask maskUnits maskContentUnits patternUnits patternContentUnits patternTransform filter filterUnits primitiveUnits stdDeviation dx dy result in in2 mode operator k1 k2 k3 k4 type values flood-color flood-opacity font-family font-size font-weight font-style text-anchor dominant-baseline letter-spacing word-spacing textLength lengthAdjust vector-effect color display visibility'));
    $properties=array_flip(explode(' ','fill fill-rule fill-opacity stroke stroke-width stroke-linecap stroke-linejoin stroke-miterlimit stroke-dasharray stroke-dashoffset stroke-opacity opacity stop-color stop-opacity clip-path clip-rule mask filter flood-color flood-opacity font-family font-size font-weight font-style text-anchor dominant-baseline letter-spacing word-spacing vector-effect color display visibility'));
    $safeValue=static fn(string $value):bool=>!preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]|expression|javascript|@|\\\\/i',$value)&&(!preg_match('/url\s*\(/i',$value)||preg_match('/^url\(\s*[\'"]?#[A-Za-z_][A-Za-z0-9_.:-]*[\'"]?\s*\)$/D',$value));
    $css=static function(string $source)use($properties,$safeValue):string{
        $source=preg_replace('~/\*.*?\*/~s','',$source)??'';$clean=[];
        foreach(explode(';',$source)as$declaration){$parts=explode(':',$declaration,2);if(count($parts)!==2)continue;[$name,$value]=array_map('trim',$parts);if(isset($properties[$name])&&$safeValue($value))$clean[]=$name.':'.$value;}
        return implode(';',$clean);
    };
    $clean=new DOMDocument('1.0','UTF-8');$count=0;
    $copy=function(DOMElement $node,int $depth=0)use(&$copy,&$count,$clean,$elements,$attributes,$safeValue,$css):?DOMElement{
        if(++$count>10000||$depth>40)throw new InvalidArgumentException('Use a simpler SVG logo.');
        if(!isset($elements[$node->localName])||!in_array($node->namespaceURI,['http://www.w3.org/2000/svg',null,''],true))return null;
        $element=$clean->createElementNS('http://www.w3.org/2000/svg',$node->localName);
        foreach($node->attributes as$attribute){
            $name=$attribute->name;$value=$attribute->value;
            if(in_array($name,['href','xlink:href'],true)){if(preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/D',$value))$element->setAttribute('href',$value);continue;}
            if($name==='style'){if(($value=$css($value))!=='')$element->setAttribute('style',$value);continue;}
            if($attribute->namespaceURI===null&&isset($attributes[$name])&&$safeValue($value))$element->setAttribute($name,$value);
        }
        if($node->localName==='style'){
            preg_match_all('/([^{}]+)\{([^{}]*)\}/',$node->textContent,$rules,PREG_SET_ORDER);
            foreach($rules as$rule)if(preg_match('/^[.#A-Za-z_][.#A-Za-z0-9_\s,>:-]*$/D',trim($rule[1]))&&($body=$css($rule[2]))!=='')$element->appendChild($clean->createTextNode(trim($rule[1]).'{'.$body.'}'));
        }else foreach($node->childNodes as$child){if($child instanceof DOMElement){if($nested=$copy($child,$depth+1))$element->appendChild($nested);}elseif($child instanceof DOMText)$element->appendChild($clean->createTextNode($child->data));}
        return $element;
    };
    $result=$copy($root);$result->setAttribute('width',(string)$width);$result->setAttribute('height',(string)$height);$result->setAttribute('viewBox',implode(' ',$viewBox));
    if($canvas)$result->setAttribute('preserveAspectRatio','xMidYMid meet');
    $clean->appendChild($result);$xml=$clean->saveXML($result);
    if(strlen($xml)>$maxBytes)throw new InvalidArgumentException('Keep the SVG logo below 2 MB.');
    return 'data:image/svg+xml;base64,'.base64_encode($xml);
}

function audienceLogoDimensions(string $image):array
{
    $bytes=base64_decode(explode(',',$image,2)[1],true);
    if(str_starts_with($image,'data:image/svg+xml;')){$document=new DOMDocument();$document->loadXML($bytes,LIBXML_NONET);return [(int)$document->documentElement->getAttribute('width'),(int)$document->documentElement->getAttribute('height')];}
    $info=getimagesizefromstring($bytes);return [$info[0],$info[1]];
}
