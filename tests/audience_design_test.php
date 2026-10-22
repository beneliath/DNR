<?php
require_once __DIR__.'/../src/audience_design.php';
$checks=0;
function designExpect(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
$starters=audienceDesignStarters();
foreach($starters as$starter){$d=$starter['design'];designExpect(audienceDesign($d)===$d,'Canonical document round trip');$html=audienceDesignHtml($d);designExpect(str_contains($html,'*|UNSUB|*')&&str_contains($html,'*|LIST:ADDRESS|*'),'Required footer retained');designExpect(str_contains($html,'@media(max-width:600px)'),'Responsive columns');}
$d=$starters['blank']['design'];$d['rows'][0]['columns'][0][0]['text']='<script>alert(1)</script> & "quote"';$html=audienceDesignHtml($d);designExpect(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'Authored HTML remains literal text');
foreach(['javascript:alert(1)','data:text/html,hello','https://x.test/" onclick="bad','//example.test']as$url){$bad=$d;$bad['rows'][0]['columns'][0][0]['url']=$url;$failed=false;try{audienceDesign($bad);}catch(InvalidArgumentException){$failed=true;}designExpect($failed,'Reject unsafe URL '.$url);}
$d['rows'][0]['columns'][0][0]['url']='http://example.test';designExpect(audienceDesign($d)['rows'][0]['columns'][0][0]['url']==='http://example.test','Authored HTTP links are retained');
foreach([['padding',-1],['size',1000],['color','red;position:fixed'],['image','data:image/svg+xml;base64,PHN2Zz4=']]as[$key,$value]){$bad=$d;$bad['rows'][0]['columns'][0][0]['type']='image';$bad['rows'][0]['columns'][0][0][$key]=$value;$failed=false;try{audienceDesign($bad);}catch(InvalidArgumentException){$failed=true;}designExpect($failed,'Reject invalid style or image '.$key);}
$d['rows'][0]['columns'][0][0]['url']='*|UNSUB|*';designExpect(audienceDesign($d)['rows'][0]['columns'][0][0]['url']==='*|UNSUB|*','Supported merge-tag link');
$rich=audienceDesignRichText('<p onclick="bad()">Hello <strong>friend</strong><script>bad()</script><img src=x onerror=bad()><a href="javascript:bad()">click</a><ul><li>News</li></ul></p>');
designExpect(str_contains($rich,'<strong>friend</strong>')&&str_contains($rich,'<li>News</li>'),'Formatting and lists retained');
designExpect(!preg_match('/onclick|script|onerror|javascript|<img/',$rich),'Rich text removes executable markup');
$preview=audienceDesignPreviewHtml($starters['newsletter']['design'],'previewNonce123456');
designExpect(!str_contains($preview,' style="')&&str_contains($preview,'<style nonce="previewNonce123456">'),'Preview uses nonce CSS without inline style attributes');
echo "Audience email design: $checks checks passed.\n";
