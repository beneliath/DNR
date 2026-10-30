<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/application_runtime.php';

use Dnr\MessageMonkey\WorkspaceRequest;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$request = static fn(array $query = [], array $post = []): WorkspaceRequest => WorkspaceRequest::fromInput($query, $post);

foreach (['manual'=>'manual', 'assistant'=>'assistant', 'home'=>'analytics', 'inbox'=>'inbox',
    'flow-report'=>'flows', 'form-settings'=>'collection', 'designer'=>'design', 'campaign-send'=>'campaigns',
    'contact'=>'contacts', 'jobs'=>'contacts'] as $view=>$controller) {
    $expect($request(['view'=>$view])->controller() === $controller, 'Wrong workspace for ' . $view);
}
$expect($request(['view'=>['delivery'], 'id'=>['3']])->view === 'contacts'
    && $request(['view'=>'unknown', 'id'=>'-1'])->id === 0,
    'Malformed query values must retain the existing safe defaults.');
$expect($request(['id'=>'7'], ['id'=>'9'])->id === 9
    && $request(['id'=>'7'], ['id'=>null])->id === 0,
    'POST identifiers take precedence, including invalid submitted values.');
$expect($request(['view'=>'manual'], ['action'=>'inbox_reply'])->controller() === 'manual'
    && $request(['view'=>'home'], ['action'=>'flow_save'])->controller() === 'analytics'
    && $request(['view'=>'contacts'], ['action'=>'inbox_reply'])->controller() === 'inbox'
    && $request(['view'=>'forms'], ['action'=>'flow_save'])->controller() === 'flows'
    && $request(['view'=>'forms'], ['action'=>'campaign_save'])->controller() === 'collection',
    'Action dispatch must preserve the established workspace precedence.');
$expect($request(['view'=>'delivery'])->requiresDeliveryAdmin()
    && $request(['view'=>'manual'], ['action'=>'delivery_save'])->requiresDeliveryAdmin()
    && !$request(['view'=>'campaign-delivery'])->requiresDeliveryAdmin(),
    'Delivery configuration requires administrator access even when posted through another workspace.');
foreach ([['download'=>'1'], ['export'=>'1'], ['template_export'=>'1'], ['api'=>'job'],
    ['view'=>'collection-report', 'chart'=>'responses'], ['view'=>'import'], ['view'=>'jobs']] as $query) {
    $expect($request($query)->requiresTransferAccess(), 'A transfer route lost its access gate.');
}
foreach (['import_upload','configure_import','start_import','undo_import','queue_export','process_job','resume_job','cancel_job','template_package_import'] as $action) {
    $expect($request(['view'=>'designer'], ['action'=>$action])->requiresTransferAccess(), 'A transfer action lost its access gate.');
}
$expect(!$request(['view'=>'contacts', 'chart'=>'responses'])->requiresTransferAccess()
    && !$request([], ['action'=>['delivery_save']])->requiresDeliveryAdmin(),
    'Unrelated requests must not acquire a different route or permission requirement.');
echo "MessageMonkey workspace routing and access tests passed.\n";
