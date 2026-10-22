<?php
$confirmation=\Dnr\Http\RequestInput::string($_GET,'confirm');$confirmationDone=false;$confirmationError='';
startSecureSession();
if($_SERVER['REQUEST_METHOD']==='POST'){requireValidCsrfToken();$publicStore=new AudienceStore($db,1,0,'Email recipient');try{$confirmationDone=$publicStore->confirmSubscription($confirmation);}catch(InvalidArgumentException){$confirmationDone=false;}if(!$confirmationDone)$confirmationError='This link has expired, was already used, or no longer matches an eligible address.';}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$publicStore=new AudienceStore($db,1,0,'Email recipient');
if($system=audienceCollectionSystem($publicStore,'confirm')){
    require_once __DIR__.'/../../audience_collection_render.php';$nonce=contentSecurityPolicyNonce();
    header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; font-src data:; img-src 'self' data: https:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    echo audienceCollectionHtml($system,[],[],'?confirm='.rawurlencode($confirmation),'',[],$confirmationError,$confirmationDone?$system['done']:'');return;
}
?><!doctype html><html lang="en"><?php renderPageHead('Confirm Subscription',['styles'=>['assets/css/pages/audience.min.css']]); ?><body class="audience-app campaign-public"><main><section class="subscription-panel"><h1><?= $confirmationDone?'You’re subscribed':'Confirm your subscription' ?></h1><?php if($confirmationDone): ?><p>Your email subscription is confirmed.</p><?php else: ?><p>Confirm that you want to receive emails from this audience.</p><?php if($confirmationError): ?><p class="audience-notice error" role="alert"><?= $h($confirmationError) ?></p><?php endif; ?><form method="post"><?= csrfInput() ?><button class="primary">Yes, subscribe me</button></form><p class="audience-help">Opening this page does not subscribe you. Confirmation links expire after 24 hours.</p><?php endif; ?></section></main></body></html>
