<?php
declare(strict_types=1);
require_once __DIR__.'/../src/functions.php';
require_once __DIR__.'/../src/audience_pagination.php';
$checks=0;
function paginationExpect(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
foreach([10,25,50,100]as$size)paginationExpect(audiencePageSize(['per_page'=>(string)$size])===$size,'Standard sizes are accepted');
foreach([0,-1,20,101,'25x',[],null]as$invalid)paginationExpect(audiencePageSize(['per_page'=>$invalid])===25,'Invalid size falls back safely');
$base=['view'=>'contact','id'=>7,'activity_type'=>'email_click','activity_days'=>90,'tags'=>['1','2'],'trash'=>'1','activity_page'=>2,'activity_size'=>25];
foreach([0,1,70]as$total)foreach([1,2,999]as$page){
 ob_start();audiencePagination(['total'=>$total,'page'=>$page,'size'=>25,'page_key'=>'activity_page','size_key'=>'activity_size','label'=>'Connection activity pages','url'=>static fn(array $p=[])=>'audience.php?'.http_build_query(array_replace($base,$p))]);$html=ob_get_clean();
 $doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($doc);$pages=max(1,(int)ceil($total/25));$current=min($pages,$page);
 paginationExpect($xpath->query('//select/option')->length===4,'One standard size selector');
 paginationExpect($xpath->query('//input[@name="activity_page"]')->item(0)->getAttribute('value')==='1','Size changes reset the correct page parameter');
 paginationExpect($xpath->query('//input[@name="tags[1]"]')->item(0)->getAttribute('value')==='2','Nested filters are retained');
 paginationExpect(trim($xpath->query('//*[@class="mm-pagination-page"]')->item(0)->textContent)==="$current / $pages",'Page is clamped to the available results');
 foreach($xpath->query('//a')as$link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);paginationExpect($params['tags']===['1','2']&&$params['id']==='7'&&$params['trash']==='1'&&$params['activity_size']==='25','Navigation retains scope and filters');}
 paginationExpect($xpath->query('//button[@disabled]')->length===($current===1?1:0)+($current===$pages?1:0),'Boundaries use disabled controls');
}
ob_start();audiencePagination(['total'=>1,'label'=>'<script>unsafe</script>','url'=>static fn(array $p=[])=>'audience.php?'.http_build_query(['q'=>'"><script>unsafe</script>']+$p)]);$html=ob_get_clean();paginationExpect(!str_contains($html,'<script>'),'Labels and hidden filters are escaped');
$_COOKIE=[];
paginationExpect(audienceRememberPageSizes([],'contacts',31)===[],'Unchosen defaults are not saved');
audienceRememberPageSizes(['per_page'=>'25'],'contacts',31);
audienceRememberPageSizes(['segment_size'=>'10'],'segments',31);
paginationExpect(audienceRememberPageSizes([],'contacts',31)['per_page']===25,'Connections restores its own choice');
paginationExpect(audienceRememberPageSizes([],'segments',31)['segment_size']===10,'Segments restores a separate choice');
paginationExpect(audienceRememberPageSizes([],'segments',32)===[],'Users do not share list preferences');
audienceRememberPageSizes(['activity_size'=>'50'],'contact',31);
audienceRememberPageSizes(['per_page'=>'10'],'contact',31);
$remembered=audienceRememberPageSizes(['id'=>7],'contact',31);paginationExpect($remembered['per_page']===10&&$remembered['activity_size']===50&&$remembered['id']===7,'Independent pagers and request scope are retained');
audienceRememberPageSizes(['kind'=>'import','history_size'=>'10'],'jobs',31);
audienceRememberPageSizes(['kind'=>'export','history_size'=>'100'],'jobs',31);
paginationExpect(audienceRememberPageSizes(['kind'=>'import'],'jobs',31)['history_size']===10&&audienceRememberPageSizes(['kind'=>'export'],'jobs',31)['history_size']===100,'Import and export history remember separate choices');
foreach([[],0,20,500,'bad']as$invalid)paginationExpect(audienceRememberPageSizes(['segment_size'=>$invalid],'segments',31)['segment_size']===10,'Invalid inputs cannot replace a remembered size');
paginationExpect(audienceRememberPageSizes(['per_page'=>100],'contacts',0)===['per_page'=>100],'Anonymous requests do not persist a choice');
echo "MessageMonkey pagination: $checks checks passed.\n";
