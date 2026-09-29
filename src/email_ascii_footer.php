<?php
declare(strict_types=1);

function renderEmailAsciiFooter(): string
{
    return <<<'HTML'
<pre aria-label="ASCII Art Cat" style="display:inline-block;margin:0;color:#667085;font-family:Menlo,Consolas,'Courier New',monospace;font-size:8px;line-height:1.35;text-align:left;white-space:pre;opacity:0.35;filter:alpha(opacity=35);mso-line-height-rule:exactly;">     (&quot;`-''-/&quot;).___..--''&quot;`-.
     `6_ 6  )   `-.  (     ).`-.__.`)
     (_Y_.)'  ._   )  `._ `. ``-..-'
   _..`--'_..-_/  /--'_.' ,'
  (il),-''  (li),'  ((!.-'</pre>
                            <div style="margin-top:6px;color:#667085;font-family:Menlo,Consolas,'Courier New',monospace;font-size:8px;line-height:1.35;text-align:center;opacity:0.35;filter:alpha(opacity=35);mso-line-height-rule:exactly;">Genesis 49:9,10 ... Revelation 5:5<br>Do you see Him?</div>
HTML;
}

function emailAsciiFooterText(): string
{
    $html = preg_replace('~</pre>\s*<div[^>]*>~', "\n\n", renderEmailAsciiFooter());
    return html_entity_decode(strip_tags(str_replace('<br>', "\n", $html)), ENT_QUOTES, 'UTF-8');
}
