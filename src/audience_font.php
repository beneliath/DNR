<?php
declare(strict_types=1);

/** Recover the display name of uploads saved before filename metadata existed. */
function audienceFontName(string $data):string
{
    if(strlen($data)>700000||!preg_match('~^data:font/(ttf|woff);base64,([A-Za-z0-9+/=]+)$~D',$data,$match))return '';
    $bytes=base64_decode($match[2],true);
    if($bytes===false||strlen($bytes)<44||strlen($bytes)>500000)return '';
    $woff=$match[1]==='woff';
    if(substr($bytes,0,4)!==($woff?'wOFF':"\x00\x01\x00\x00"))return '';
    $count=unpack('n',substr($bytes,$woff?12:4,2))[1];
    $start=$woff?44:12;$stride=$woff?20:16;
    if($start+$count*$stride>strlen($bytes))return '';
    $table='';
    for($i=0;$i<$count;$i++){
        $entry=substr($bytes,$start+$i*$stride,$stride);
        if(substr($entry,0,4)!=='name')continue;
        $record=unpack($woff?'Noffset/Nlength/Noriginal':'Nchecksum/Noffset/Nlength',substr($entry,4,12));
        if($record['offset']<$start+$count*$stride||$record['length']>500000||$record['offset']+$record['length']>strlen($bytes))return '';
        $table=substr($bytes,$record['offset'],$record['length']);
        if($woff){
            if($record['original']>500000||$record['length']>$record['original'])return '';
            if($record['length']<$record['original'])$table=@gzuncompress($table,$record['original']);
            if(!is_string($table)||strlen($table)!==$record['original'])return '';
        }
        break;
    }
    // OpenType name records: https://learn.microsoft.com/typography/opentype/spec/name
    if(strlen($table)<6)return '';
    $header=unpack('nversion/ncount/nstorage',substr($table,0,6));
    if($header['version']>1||6+$header['count']*12>$header['storage']||$header['storage']>strlen($table))return '';
    $best='';$bestScore=PHP_INT_MAX;
    for($i=0;$i<$header['count'];$i++){
        $record=unpack('nplatform/nencoding/nlanguage/nname/nlength/noffset',substr($table,6+$i*12,12));
        $priority=array_search($record['name'],[4,16,1],true);
        $offset=$header['storage']+$record['offset'];
        if($priority===false||$record['length']===0||$record['length']>2048||$offset+$record['length']>strlen($table))continue;
        $raw=substr($table,$offset,$record['length']);
        if($record['platform']===0||$record['platform']===3&&in_array($record['encoding'],[0,1,10],true)){
            if(!mb_check_encoding($raw,'UTF-16BE'))continue;
            $name=mb_convert_encoding($raw,'UTF-8','UTF-16BE');
        }elseif($record['platform']===1&&$record['encoding']===0&&function_exists('iconv'))$name=@iconv('MACINTOSH','UTF-8',$raw);
        else continue;
        if(!is_string($name))continue;
        $name=trim($name);
        if($name===''||mb_strlen($name)>255||preg_match('/[\x00-\x1f\x7f]/',$name))continue;
        $score=$priority*10+($record['platform']===3&&$record['language']===0x409?0:($record['platform']===0?1:2));
        if($score<$bestScore){$best=$name;$bestScore=$score;}
    }
    return $best;
}

function audienceBrandFont(array $brand):array
{
    $data=(string)($brand['styles']['webFont']??'');
    return ['data'=>$data,'name'=>audienceFontName($data)?:((string)($brand['web_font_name']??'')?:'Brand Kit font')];
}
