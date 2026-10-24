<?php
declare(strict_types=1);
require __DIR__.'/../../src/functions.php';
$total=(int)($argv[1]??70);$page=(int)($argv[2]??2);$size=(int)($argv[3]??25);
$base=['view'=>'surveys','q'=>'Community feedback','trash'=>1,'type'=>'survey','direction'=>'asc','tags'=>['1','2'],'page'=>$page,'per_page'=>$size];
echo '<!doctype html><html lang="en">';renderPageHead('Pagination fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-ai.min.css'],'scripts'=>['assets/js/audience.min.js']]);
echo '<body class="audience-app" data-audience-key="fixture-account:31:1"><main style="padding:20px"><section class="collection-workspace" style="padding:0"><div class="audience-table-scroll"><table data-mm-paginated-table class="data-table"><tbody><tr><td>Community survey</td></tr></tbody></table></div>';
audiencePagination(['total'=>$total,'page'=>$page,'size'=>$size,'label'=>'Survey pages','url'=>static fn(array $p=[])=>'audience.php?'.http_build_query(array_replace($base,$p))]);
echo '</section><section style="max-width:320px;margin:40px auto"><div class="analytics-ai-table-scroll"><table data-mm-paginated-table><tbody><tr><td>Audience result</td></tr></tbody></table></div><div id="async-list"></div></section><div id="rows"></div></main><script type="module">import {renderAudiencePagination} from "/assets/js/audience-pagination.mjs";let page=2,pageSize=25;const draw=()=>{document.querySelector("#rows").textContent=Array.from({length:Math.min(pageSize,70-(page-1)*pageSize)},(_,i)=>"Record "+((page-1)*pageSize+i+1)).join(", ");renderAudiencePagination(document.querySelector("#async-list"),{total:70,page,pageSize,label:"Async result pages",onChange:next=>{page=next.page;pageSize=next.pageSize;draw();}});};draw();</script></body></html>';
