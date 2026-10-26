<?php
declare(strict_types=1);
// Exercise the production renderer with synthetic session data; no database access.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
require __DIR__.'/../../src/audience_manual_controller.php';
$role=$argv[1]??'editor';
$_SESSION=['user_id'=>11,'username'=>'manual-fixture','role'=>$role,'_csrf_token'=>'fixture-token'];
$_SERVER['PHP_SELF']='audience.php';
$_SERVER['QUERY_STRING']='view=manual';
$_GET=['view'=>'manual'];
renderAudienceManual(['id'=>1,'name'=>'Example audience'],in_array($role,['admin','editor'],true),$role==='admin','Example User',11);
