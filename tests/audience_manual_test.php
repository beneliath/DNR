<?php
declare(strict_types=1);
require __DIR__.'/../src/audience_search.php';
$checks=0;
function manualExpect(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
foreach(['reviewer','editor','admin'] as $role){
    $pipes=[];
    $process=proc_open([PHP_BINARY,__DIR__.'/browser/audience-manual-fixture.php',$role],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $html=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    manualExpect(proc_close($process)===0&&$errors==='',"$role renders without PHP errors: $errors");
    $doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($doc);
    $chapters=$xpath->query('//*[@data-manual-section]');
    manualExpect($chapters->length===14,'All fourteen chapters are available to each role');
    $ids=[];foreach($xpath->query('//*[@id]') as $node){$id=$node->getAttribute('id');manualExpect(!isset($ids[$id]),"Unique ID: $id");$ids[$id]=true;}
    foreach($xpath->query('//a[starts-with(@href,"#manual-")]') as $link)manualExpect(isset($ids[substr($link->getAttribute('href'),1)]),'Every guide link has a destination');
    manualExpect($xpath->query('//*[@data-manual-toc]')->length===$chapters->length,'Each chapter has a table-of-contents entry');
    manualExpect($xpath->query('//aside[@id="audience-sidebar"]//a[contains(@class,"selected")]')->length===1,'Only the manual is selected in the sidebar');
    $manualLink=$xpath->query('//aside[@id="audience-sidebar"]//a[@aria-label="User Manual"]')->item(0);
    manualExpect($manualLink?->getAttribute('aria-current')==='page','Manual link identifies the active page');
    manualExpect($manualLink?->getAttribute('href')==='audience.php?view=manual','Sidebar opens the dedicated manual route');
    $previous=$xpath->query('preceding-sibling::a[1]',$manualLink)->item(0);
    manualExpect($previous?->getAttribute('aria-label')===($role==='reviewer'?'Ask Analytics AI':'Write with AI'),'Manual follows the writing link when available');
    manualExpect($xpath->query('//*[@data-manual-search and @type="search"]')->length===1,'Manual search has a search input');
    manualExpect($xpath->query('//*[@data-manual-status and @aria-live="polite"]')->length===1,'Search exposes accessible result status');
    manualExpect($xpath->query('//*[@data-manual-empty-clear]')->length===1,'An empty search can be cleared');
    manualExpect($xpath->query('//figure[contains(@class,"manual-visual")]')->length===9,'Nine workflows have visual summaries');
    manualExpect($xpath->query('//figure[contains(@class,"manual-action-legend")]//li')->length===6,'The guide illustrates the six common actions');
    manualExpect($xpath->query('//figure[contains(@class,"manual-action-legend")]//svg')->length===6,'Every illustrated action has its real icon');
    manualExpect($xpath->query('//*[contains(@class,"is-current-role")]')->length===1,'Role cards highlight the current role');
    manualExpect($xpath->query('//ol[contains(@class,"manual-steps")]')->length>=8,'Procedures use accessible numbered step trails');
    foreach(['assets/css/pages/audience-manual.min.css','assets/js/user-manual.min.js'] as $asset)manualExpect(str_contains($html,$asset),'Production manual assets are included');
    $pdfLink=$xpath->query('//a[contains(@href,"assets/docs/message-monkey-comprehensive-user-guide.pdf")]');
    manualExpect($pdfLink->length===1&&$pdfLink->item(0)->getAttribute('target')==='_blank','Every role can open the comprehensive PDF guide in a new tab');
    $guideLinks=$xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," manual-screen-links ")]//a');
    $views=[];foreach($guideLinks as $link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);$views[]=$params['view'];}
    manualExpect(in_array('inbox',$views,true)===($role!=='reviewer'),'Inbox links follow role access');
    manualExpect(in_array('import',$views,true)===($role!=='reviewer'),'Import links follow role access');
    manualExpect(in_array('delivery',$views,true)===($role==='admin'),'Delivery setup links require administrator access');
    $catalog=audienceSearchCatalog($role!=='reviewer',$role==='admin');
    manualExpect(in_array('audience.php?view=manual',array_column($catalog['pages'],'href'),true),'Global search includes the manual for all roles');
}
$manualIcon=new DOMDocument();$manualIcon->load(__DIR__.'/../src/assets/icons/audience-nav/manual.svg');
$activeIcon=new DOMDocument();$activeIcon->load(__DIR__.'/../src/assets/icons/audience-nav/manual-active.svg');
$paths=$manualIcon->getElementsByTagName('path');$activePaths=$activeIcon->getElementsByTagName('path');
manualExpect($paths->length===2&&$activePaths->length===2,'The book has two pages in both states');
for($i=0;$i<2;$i++)manualExpect($paths->item($i)->getAttribute('d')===$activePaths->item($i)->getAttribute('d'),'Hover preserves the book geometry');
$moedHeader=file_get_contents(__DIR__.'/../src/templates/header.php');
foreach($paths as $path)manualExpect(str_contains($moedHeader,$path->getAttribute('d')),'The book uses the same paths as the MOED User Manual link');
manualExpect($activePaths->item(0)->hasAttribute('fill')&&!$paths->item(0)->hasAttribute('fill'),'Hover fills one page of the outlined book');
echo "MessageMonkey online manual: $checks checks passed.\n";
