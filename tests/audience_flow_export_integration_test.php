<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TARGET') !== 'disposable' || getenv('DNR_INTEGRATION_TEST') !== '1') {
    exit("Flow export tests skipped (disposable environment required).\n");
}
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/two_factor_helpers.php';
require_once $source.'/audience_store.php';
$suffix=bin2hex(random_bytes(4));
$username='export_review_'.$suffix;$password='Synthetic-export-password-42';$plainSecret=generateTotpSecret();
$conn->execute_query("INSERT INTO users(username,password,role,two_factor_enabled,totp_secret_encrypted)
    VALUES (?,?,'admin',1,?)",[$username,password_hash($password,PASSWORD_DEFAULT),encryptTwoFactorSecret($plainSecret)]);
$user=(int)$conn->insert_id;
$store=new AudienceStore($conn,1,$user,'Synthetic export reviewer');
$person=$store->savePerson(['email'=>'export-'.$suffix.'@example.test','email_status'=>'non-subscribed']);
$document=audienceFlowDefault();$document['trigger']=['type'=>'manual'];
$document['start']='finish';$document['nodes']=[['id'=>'finish','type'=>'exit']];
$flow=$store->saveFlow('Synthetic export review',$document);
$document=$store->flow($flow)['document'];
$conn->execute_query('INSERT INTO marketing_flow_revisions(audience_id,flow_id,document_json,actor_label) VALUES (1,?,?,?)',
    [$flow,json_encode($document,JSON_THROW_ON_ERROR),'Synthetic export reviewer']);
$revision=(int)$conn->insert_id;
$conn->begin_transaction();
for($i=0;$i<4001;$i++)$conn->execute_query("INSERT INTO marketing_flow_runs
    (audience_id,flow_id,revision_id,contact_id,entry_key,node_key,state,due_at,ended_at)
    VALUES (1,?,?,?,?,'finish','completed',UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$flow,$revision,$person,'synthetic-'.$i]);
$conn->commit();
require_once $source.'/audience_flow_controller.php';
$audience=$conn->query('SELECT * FROM marketing_audiences WHERE id=1')->fetch_assoc();
$results=[];
foreach([10,25] as$size){
    $_GET=['view'=>'flow-report','id'=>(string)$flow,'download'=>'csv','per_page'=>(string)$size];
    $_SERVER['REQUEST_METHOD']='GET';
    ob_start();
    renderAudienceFlowWorkspace($store,$audience,'flow-report',$flow,'','',true,true,'Synthetic export reviewer',$user);
    $body=(string)ob_get_clean();
    if(!str_starts_with($body,'Execution,'))throw new RuntimeException('The actual controller did not return CSV.');
    $stream=fopen('php://temp','w+');fwrite($stream,$body);rewind($stream);$rows=0;
    while(($row=fgetcsv($stream,null,',','"',''))!==false)$rows++;
    fclose($stream);
    if($rows-1!==4001)throw new RuntimeException('The full export must be independent of screen page size.');
    $results[]=['page_size'=>$size,'controller_returned_csv'=>true,'data_rows_exported'=>$rows-1];
}
$generator=$store->flowExportRuns($flow,['state'=>'completed']);
$generator->rewind();$generator->current();
$writer=new mysqli((string)(getenv('DB_HOST')?:'db'),(string)(getenv('MYSQL_USER')?:'dnruser'),configurationSecret('MYSQL_PASSWORD'),(string)(getenv('MYSQL_DATABASE')?:'dnr'));
$writer->execute_query("UPDATE marketing_flow_runs SET state='failed' WHERE flow_id=? AND entry_key='synthetic-0'",[$flow]);
$writer->execute_query("INSERT INTO marketing_flow_runs(audience_id,flow_id,revision_id,contact_id,entry_key,node_key,state,due_at) VALUES (1,?,?,?,'new-after-export','finish','completed',UTC_TIMESTAMP())",[$flow,$revision,$person]);
$count=0;
foreach($generator as$row){$count++;if($row['state']!=='completed')throw new RuntimeException('Export membership changed during capture.');}
$writer->close();
if($count!==4001)throw new RuntimeException('Concurrent changes must not alter the export snapshot.');
echo "Flow CSV completeness, page-size independence, and consistent snapshot tests passed.\n";
