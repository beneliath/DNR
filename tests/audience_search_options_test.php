<?php
require_once __DIR__.'/../src/audience_search.php';
$checks=0;
function searchOptionsExpect(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}

// Generic query names and unrelated MOED/browser history must never supply options.
$_GET=['q'=>'Rooted'];$_POST=['q'=>'Rural leaders roundtable'];
searchOptionsExpect(audienceSearchOptions([])==='','An empty MessageMonkey view proposes nothing');
$options=audienceSearchOptions(['Newsletter',' Newsletter ','NEWSLETTER','Event feedback',null,'',str_repeat('x',321)]);
searchOptionsExpect(substr_count($options,'<option ')===2,'Only bounded, unique record names become suggestions');
searchOptionsExpect(!str_contains($options,'Rooted')&&!str_contains($options,'Rural leaders'),'Shared form history is not a source');
searchOptionsExpect(audienceSearchOptions(['Other account survey'])==='<option value="Other account survey"></option>','Each render uses only its supplied account rows');
searchOptionsExpect(!str_contains(audienceSearchOptions(['Newsletter']),'Other account'),'Suggestions do not leak between renders');
$unsafe=audienceSearchOptions(['"><script>alert(1)</script>']);
searchOptionsExpect(!str_contains($unsafe,'<script>')&&str_contains($unsafe,'&quot;&gt;&lt;script&gt;'),'Record labels cannot inject suggestion markup');
searchOptionsExpect(substr_count(audienceSearchOptions(array_map(strval(...),range(1,150))),'<option ')===100,'Suggestion payload remains bounded');
echo "MessageMonkey search suggestions: $checks checks passed.\n";
