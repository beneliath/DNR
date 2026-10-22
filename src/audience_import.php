<?php
declare(strict_types=1);

/** Parse native CSV/TXT or tab-separated clipboard data without guessing field types. */
function audienceParseImport(string $content): array
{
    if (strlen($content)>20*1024*1024) throw new InvalidArgumentException('Use a file of 20 MB or less. Split larger imports into separate files.');
    if (str_starts_with($content,"\xEF\xBB\xBF")) $content=substr($content,3);
    if (!mb_check_encoding($content,'UTF-8')||str_contains($content,"\0")) throw new InvalidArgumentException('Save the file as UTF-8 CSV or tab-separated text.');
    $first=strtok($content,"\r\n")?:'';$delimiter=',';$best=0;
    foreach([',',"\t",';']as$candidate){$count=count(str_getcsv($first,$candidate,'"',''));if($count>$best){$best=$count;$delimiter=$candidate;}}
    $stream=fopen('php://temp','w+');fwrite($stream,$content);rewind($stream);$headers=fgetcsv($stream,null,$delimiter,'"','');
    if(!$headers||count($headers)>200)throw new InvalidArgumentException('Include a header row with no more than 200 columns.');
    $headers=array_map(fn($v)=>trim((string)$v),$headers);foreach($headers as$i=>&$header){if($header==='')$header='Column '.($i+1);if(mb_strlen($header)>100)$header=mb_substr($header,0,100);}unset($header);
    $rows=[];while(($row=fgetcsv($stream,null,$delimiter,'"',''))!==false){if(count($row)===1&&trim((string)$row[0])==='')continue;if(count($rows)>=50000)throw new InvalidArgumentException('Import up to 50,000 rows at a time.');$rows[]=array_map(fn($v)=>(string)$v,$row);}fclose($stream);
    if(!$rows)throw new InvalidArgumentException('The file contains no connections.');return ['headers'=>$headers,'rows'=>$rows];
}

/** Read the first XLSX worksheet; reject entities, external links and expansion bombs. */
function audienceParseXlsx(string $path): array
{
    if(!class_exists('ZipArchive')||!function_exists('simplexml_load_string'))throw new InvalidArgumentException('XLSX is unavailable on this server. Save the file as CSV.');
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException('This is not a valid XLSX workbook.');
    try{
        $total=0;for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);$total+=(int)$stat['size'];if($total>80*1024*1024)throw new InvalidArgumentException('The expanded workbook exceeds 80 MB. Save the worksheet as CSV.');}
        $xml=function(string $name)use($zip):SimpleXMLElement{$raw=$zip->getFromName($name);if($raw===false||stripos($raw,'<!DOCTYPE')!==false||stripos($raw,'<!ENTITY')!==false)throw new InvalidArgumentException('The workbook contains an unsupported XML document.');$old=libxml_use_internal_errors(true);try{$parsed=simplexml_load_string($raw,SimpleXMLElement::class,LIBXML_NONET);if($parsed===false)throw new InvalidArgumentException('The workbook contains invalid XML.');return $parsed;}finally{libxml_clear_errors();libxml_use_internal_errors($old);}};
        $workbook=$xml('xl/workbook.xml');$sheet=$workbook->sheets->sheet[0];$rid=(string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;$target='';
        foreach($xml('xl/_rels/workbook.xml.rels')->Relationship as$rel)if((string)$rel['Id']===$rid&&(string)$rel['TargetMode']!=='External')$target=(string)$rel['Target'];
        if(str_contains($target,'..')||!preg_match('~^(?:/?xl/)?worksheets/[A-Za-z0-9_.-]+\.xml$~D',$target))throw new InvalidArgumentException('The first worksheet could not be read. Save it as CSV.');
        $target=str_starts_with(ltrim($target,'/'),'xl/')?ltrim($target,'/'):'xl/'.$target;
        $shared=[];if($zip->locateName('xl/sharedStrings.xml')!==false)foreach($xml('xl/sharedStrings.xml')->si as$si){$str=(string)$si->t;foreach($si->r as$r)$str.=(string)$r->t;$shared[]=$str;}
        $records=[];foreach($xml($target)->sheetData->row as$row){$values=[];foreach($row->c as$cell){preg_match('/^([A-Z]+)\d+$/',(string)$cell['r'],$match);$column=0;foreach(str_split($match[1]??'A')as$letter)$column=$column*26+ord($letter)-64;if($column>200)throw new InvalidArgumentException('Use no more than 200 columns.');$value=(string)$cell->v;$kind=(string)$cell['t'];if($kind==='s')$value=$shared[(int)$value]??'';elseif($kind==='inlineStr'){$value=(string)$cell->is->t;foreach($cell->is->r as$r)$value.=(string)$r->t;}$values[$column-1]=$value;}
            if(!$values)continue;$record=array_fill(0,max(array_keys($values))+1,'');foreach($values as$col=>$value)$record[$col]=$value;$records[]=$record;if(count($records)>50001)throw new InvalidArgumentException('Import up to 50,000 rows at a time.');}
        if(count($records)<2)throw new InvalidArgumentException('Include headers and at least one connection.');$headers=array_shift($records);$width=count($headers);$records=array_map(fn($row)=>array_pad($row,$width,''),$records);
        $stream=fopen('php://temp','w+');foreach([$headers,...$records]as$row)fputcsv($stream,$row,',','"','');rewind($stream);$content=stream_get_contents($stream);fclose($stream);return audienceParseImport($content);
    }finally{$zip->close();}
}
