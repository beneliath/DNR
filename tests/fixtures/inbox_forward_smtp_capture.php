<?php
declare(strict_types=1);
// Loopback-only SMTP receiver for the disposable forwarding integration test.
if (PHP_SAPI!=='cli' || getenv('DNR_INTEGRATION_TARGET')!=='disposable') exit(1);
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if (!$server) throw new RuntimeException('Unable to open the fixture relay.');
echo stream_socket_get_name($server,false),"\n";flush();
foreach (array_slice($argv,1) as $outcome) {
    $client=stream_socket_accept($server,10);
    if (!$client) throw new RuntimeException('Expected an SMTP connection.');
    stream_set_timeout($client,10);
    fwrite($client,"220 fixture ESMTP\r\n");
    $envelope=[];$data='';
    while (($line=fgets($client))!==false) {
        $command=rtrim($line,"\r\n");
        if ($command==='DATA') {
            fwrite($client,"354 End with a dot\r\n");
            while (($line=fgets($client))!==false && $line!==".\r\n") $data.=$line;
            if ($line===false) throw new RuntimeException('Incomplete fixture DATA.');
            if ($outcome==='disconnect') break;
            fwrite($client,"250 Accepted\r\n");
        } elseif ($command==='QUIT') {
            fwrite($client,"221 Bye\r\n");break;
        } else {
            $envelope[]=$command;
            fwrite($client,"250 OK\r\n");
        }
    }
    fclose($client);
    echo json_encode(['envelope'=>$envelope,'data'=>$data],JSON_THROW_ON_ERROR),"\n";flush();
}
fclose($server);
