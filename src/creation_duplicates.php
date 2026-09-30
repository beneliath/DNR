<?php
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/record_workspace_helpers.php';
require_once __DIR__.'/duplicate_warning_helpers.php';
$conn=applicationDatabaseConnection(); startSecureSession(); requireLogin();
if (!hasRole(['admin','editor'])) { http_response_code(403); exit; }
header('Cache-Control: no-store'); header('Content-Type: application/json');
$kind=\Dnr\Http\RequestInput::enum($_GET,'kind',['contact','organization'],'contact');
$warning=creationDuplicateWarning($conn,$kind,$_GET);
$return=safeRecordReturnUrl($_GET['return_to']??null,'');
foreach($warning['matches'] as &$match) $match['url']=creationDuplicateDestination($kind,(int)$match['id'],$return);
echo json_encode($warning,JSON_THROW_ON_ERROR);
