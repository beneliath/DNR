<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/audience_store.php';
sendApplicationSecurityHeaders();
$conn=applicationDatabaseConnection();
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$key=\Dnr\Http\RequestInput::string($_GET,isset($_GET['archive'])?'archive':'key');
if(!preg_match('/^[a-f0-9]{64}$/D',$key)){http_response_code(404);exit('This email is unavailable.');}
$publication=$conn->execute_query("SELECT p.*,c.sent_at FROM marketing_campaign_publications p JOIN marketing_campaigns c ON c.id=p.campaign_id AND c.audience_id=p.audience_id WHERE p.public_key=? AND p.enabled=1 AND c.status='sent' AND c.deleted_at IS NULL",[$key])->fetch_assoc();
if(!$publication){http_response_code(404);exit('This email is unavailable.');}
if(isset($_GET['image'])){
    if(!preg_match('~^data:(image/(?:png|jpeg|webp));base64,([a-zA-Z0-9+/=]+)$~D',$publication['image_data'],$image)){http_response_code(404);exit;}
    header('Content-Type: '.$image[1]);header('X-Content-Type-Options: nosniff');echo base64_decode($image[2],true);exit;
}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$nonce=contentSecurityPolicyNonce();
$archive=isset($_GET['archive']);$entries=[];$page=1;$pages=1;
if($archive){
    $archiveWhere="p.audience_id=? AND p.enabled=1 AND p.archive_listed=1 AND c.status='sent' AND c.deleted_at IS NULL";
    $total=(int)$conn->execute_query("SELECT COUNT(*) n FROM marketing_campaign_publications p JOIN marketing_campaigns c ON c.id=p.campaign_id AND c.audience_id=p.audience_id WHERE $archiveWhere",[$publication['audience_id']])->fetch_assoc()['n'];
    $pages=max(1,(int)ceil($total/25));$page=max(1,min($pages,\Dnr\Http\RequestInput::positiveInt($_GET,'page')??1));
    $entries=$conn->execute_query("SELECT p.public_key,p.title,c.sent_at FROM marketing_campaign_publications p JOIN marketing_campaigns c ON c.id=p.campaign_id AND c.audience_id=p.audience_id WHERE $archiveWhere ORDER BY c.sent_at DESC,p.id DESC LIMIT 25 OFFSET ?",[$publication['audience_id'],($page-1)*25])->fetch_all(MYSQLI_ASSOC);
}
?><!doctype html><html lang="en">
<?php
ob_start();renderPageHead($archive?'Email Archive':$publication['title'],['styles'=>['assets/css/pages/audience.min.css']]);$head=(string)ob_get_clean();
$meta='<meta property="og:type" content="article"><meta property="og:title" content="'.$h($publication['title']).'"><meta property="og:description" content="'.$h($publication['description']).'"><meta name="description" content="'.$h($publication['description']).'">';
try{$publicBase=accountPrimaryPublicUrl();}catch(InvalidArgumentException){$publicBase='';}
if($publicBase!==''&&!$archive){$meta.='<meta property="og:url" content="'.$h($publicBase.'/audience_campaign_public.php?key='.$key).'">';if($publication['image_data']!=='')$meta.='<meta property="og:image" content="'.$h($publicBase.'/audience_campaign_public.php?key='.$key.'&image=1').'">';}
echo str_replace('</head>',$meta.'</head>',$head);
?>
<body class="audience-app campaign-public"><main>
<?php if($archive): ?><h1>Email Archive</h1><?php foreach($entries as$item): ?><article><time><?= $h(substr($item['sent_at']??'',0,10)) ?></time><h2><a href="?key=<?= $h($item['public_key']) ?>"><?= $h($item['title']) ?></a></h2></article><?php endforeach; ?><?php if(!$entries): ?><p>No published emails in this archive.</p><?php endif; ?><nav class="campaign-pagination" aria-label="Archive pages"><?php if($page>1): ?><a href="?archive=<?= $h($key) ?>&amp;page=<?= $page-1 ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if($page<$pages): ?><a href="?archive=<?= $h($key) ?>&amp;page=<?= $page+1 ?>">Next</a><?php endif; ?></nav>
<?php else: ?><header><h1><?= $h($publication['title']) ?></h1><a href="?archive=<?= $h($key) ?>">Past emails</a></header><p><?= $h($publication['description']) ?></p><iframe title="Email browser version" sandbox="" referrerpolicy="no-referrer" srcdoc="<?= $h(audienceDesignPreviewSource($publication['html'],$nonce)) ?>"></iframe><p class="audience-help">To manage your subscription, use the links in the email you received.</p><?php endif; ?>
</main></body></html>
