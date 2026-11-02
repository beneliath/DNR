<?php
declare(strict_types=1);

/** Parse native CSV/TXT or tab-separated clipboard data without guessing field types. */
function audienceParseImport(string $content): array
{
    if (strlen($content)>20*1024*1024) throw new InvalidArgumentException('Use a file of 20 MB or less. Split larger imports into separate files.');
    // A small byte payload can expand into millions of PHP cell values. Check
    // every candidate delimiter before native dialect detection or row parsing
    // allocates arrays. Quoted separators count toward this conservative budget.
    if(substr_count($content,',')+substr_count($content,"\t")+substr_count($content,';')>500000)
        throw new InvalidArgumentException('This import has too many separators. Split it into smaller files.');
    if (str_starts_with($content,"\xEF\xBB\xBF")) $content=substr($content,3);
    if (!mb_check_encoding($content,'UTF-8')||str_contains($content,"\0")) throw new InvalidArgumentException('Save the file as UTF-8 CSV or tab-separated text.');
    $first=strtok($content,"\r\n")?:'';$delimiter=',';$best=0;
    foreach([',',"\t",';']as$candidate){$count=count(str_getcsv($first,$candidate,'"',''));if($count>$best){$best=$count;$delimiter=$candidate;}}
    $stream=fopen('php://temp','w+');fwrite($stream,$content);rewind($stream);$headers=fgetcsv($stream,null,$delimiter,'"','');
    if(!$headers||count($headers)>200)throw new InvalidArgumentException('Include a header row with no more than 200 columns.');
    $headers=array_map(fn($v)=>trim((string)$v),$headers);foreach($headers as$i=>&$header){if($header==='')$header='Column '.($i+1);if(mb_strlen($header)>100)$header=mb_substr($header,0,100);}unset($header);
    $rows=[];while(($row=fgetcsv($stream,null,$delimiter,'"',''))!==false){if(count($row)>200)throw new InvalidArgumentException('Use no more than 200 columns in each row.');if(count($row)===1&&trim((string)$row[0])==='')continue;if(count($rows)>=50000)throw new InvalidArgumentException('Import up to 50,000 rows at a time.');$rows[]=array_map(fn($v)=>(string)$v,$row);}fclose($stream);
    if(!$rows)throw new InvalidArgumentException('The file contains no connections.');return ['headers'=>$headers,'rows'=>$rows];
}

/** Read the first worksheet through the bounded streaming XLSX parser. */
function audienceParseXlsx(string $path): array
{
    require_once __DIR__ . '/audience_xlsx.php';
    return audienceReadXlsx($path);
}
