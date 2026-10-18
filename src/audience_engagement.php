<?php
declare(strict_types=1);

/** Shared, server-controlled SQL for unique engagement and delivery evidence. */
function audienceEngagementColumns(string $bots='filtered'):string
{
    $bot=$bots==='filtered'?" AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.bot')),'false')<>'true'":'';
    $failed="COALESCE(MAX(e.source='provider' AND e.event_type IN ('hard-bounce','soft-bounce','rejected')),0)";
    $open="COALESCE(MAX(e.event_type IN ('open','click') $bot),0)";
    $click="COALESCE(MAX(e.event_type='click' $bot),0)";
    return "IF($failed=0,COALESCE(MAX(e.event_type='delivered' AND e.source='provider'),0),0) delivered,
        $failed failed,
        COALESCE(MAX(e.event_type='rejected' AND e.source='provider'),0) rejected,
        IF($failed=0,$open,0) opens,IF($failed=0,$click,0) clicks,
        IF($failed=0,GREATEST(COALESCE(SUM(e.event_type='open' $bot),0),$click),0) total_opens,
        IF($failed=0,COALESCE(SUM(e.event_type='click' $bot),0),0) total_clicks,
        COALESCE(MAX(e.event_type IN ('hard-bounce','soft-bounce') AND e.source='provider'),0) bounced,
        COALESCE(MAX(e.event_type='complaint' AND e.source='provider'),0) complaints,
        COALESCE(MAX(e.event_type IN ('send','delivered','delay','rejected','hard-bounce','soft-bounce','complaint') AND e.source='provider'),0) receipt,
        COALESCE(MAX(e.event_type IN ('open','click') AND JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.bot'))='true'),0) detected_bot";
}

function audienceEngagementExplanation():string
{
    return 'Open, click, unsubscribe and complaint rates use accepted messages minus recorded bounces or rejections. Pending receipts remain provisional. Clicks also count as opens. Known bot activity is excluded by default; privacy proxies and unidentified automation can remain.';
}
