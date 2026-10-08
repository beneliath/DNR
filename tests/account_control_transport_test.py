"""Opt-in, disposable HTTPS relay rehearsal. No production or preview data access.

python3 tests/account_control_transport_test.py --local-docker
Uses existing local images, two internal networks and a temporary test certificate.
"""
import argparse
import json
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import account_production as production
from integration_environment import allocate_test_networks


def run(args, **kwargs):
    result=subprocess.run(args,text=True,capture_output=True,**kwargs)
    if result.returncode: raise RuntimeError((result.stdout+result.stderr)[-3500:])
    return result.stdout.strip()


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-docker',action='store_true',required=True)
    parser.add_argument('--ingress-image',default='dnr-ingress:local')
    parser.add_argument('--app-image',default='dnr-app:local')
    args=parser.parse_args()
    prefix='dnr-test-control-'+secrets.token_hex(6)
    containers=[];networks=[]
    with tempfile.TemporaryDirectory(prefix=prefix) as temporary:
        directory=Path(temporary)
        try:
            existing=run(['docker','network','ls','-q']).split()
            subnets=allocate_test_networks(json.loads(run(['docker','network','inspect',*existing])))
            for key in ['backend','ingress']:
                name=prefix+'-'+key
                run(['docker','network','create','--internal','--subnet',str(subnets[key]),
                     '--label','org.dnr.disposable-test='+prefix,name]);networks.append(name)
            edge_ip=str(subnets['ingress'].network_address+2)
            config={'origin':'https://moed.test','traefik_ip':edge_ip}
            (directory/'control.conf').write_text(production.control_proxy_configuration(config,str(subnets['backend'])))
            for name in ['server','untrusted']:
                run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                     '-subj','/CN=moed.test','-addext','subjectAltName=DNS:moed.test',
                     '-keyout',str(directory/(name+'.key')),'-out',str(directory/(name+'.crt'))])
            (directory/'tls.conf').write_text('''ServerRoot /etc/apache2
ServerName moed.test
PidFile /tmp/tls.pid
Listen 443
IncludeOptional /etc/apache2/mods-enabled/*.load
IncludeOptional /etc/apache2/mods-enabled/*.conf
LoadModule ssl_module /usr/lib/apache2/modules/mod_ssl.so
User www-data
Group www-data
ErrorLog /proc/self/fd/2
<VirtualHost *:443>
SSLEngine on
SSLCertificateFile /fixture/server.crt
SSLCertificateKeyFile /fixture/server.key
DocumentRoot /fixture/www
<Directory /fixture/www>
Require all granted
</Directory>
</VirtualHost>
''')
            (directory/'www').mkdir();(directory/'www/index.html').write_text('account-control-ok')
            # Certificate fixtures only; never mount application/DB secrets.
            for path in directory.rglob('*'):
                path.chmod(0o755 if path.is_dir() else 0o644)
            upstream=prefix+'-tls';containers.append(upstream)
            run(['docker','run','-d','--name',upstream,'--network',networks[1],'--ip',edge_ip,
                 '--mount',f'type=bind,src={directory},dst=/fixture,readonly',
                 '--entrypoint','apache2-foreground',args.ingress_image,'-f','/fixture/tls.conf'])
            relay=prefix+'-proxy';containers.append(relay)
            service=production.control_proxy_service(config,directory/'control.conf',args.ingress_image)
            # Run the actual generated service definition through Compose.
            compose={'services':{'account-control':service},'networks':{
                'backend':{'external':True,'name':networks[0]},'edge':{'external':True,'name':networks[1]}}}
            compose['services']['account-control']['container_name']=relay
            (directory/'compose.json').write_text(json.dumps(compose))
            run(['docker','compose','-p',prefix,'-f',str(directory/'compose.json'),'up','-d','--wait','--wait-timeout','30'])
            php='''<?php
require_once '/var/www/html/functions.php';
putenv('DNR_PRIMARY_PUBLIC_URL=https://moed.test/a/primary-account');
putenv('DNR_ACCOUNT_CONTROL_PROXY=http://account-control:8080');
$url='https://moed.test/';
$request=function($url,$options) {
    $c=curl_init($url); curl_setopt_array($c,$options+[CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>4,CURLOPT_CAINFO=>'/fixture/server.crt']);
    $body=curl_exec($c);return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),
        'connect'=>curl_getinfo($c,CURLINFO_HTTP_CONNECTCODE),
        'errno'=>curl_errno($c),'body'=>$body];
};
$good=$request($url,accountControlCurlOptions($url));
$badCa=$request($url,[CURLOPT_CAINFO=>'/fixture/untrusted.crt']+accountControlCurlOptions($url));
$outside=$request('https://forbidden.test/',[CURLOPT_PROXY=>'http://account-control:8080',CURLOPT_HTTPPROXYTUNNEL=>true]);
$port=$request('https://moed.test:444/',[CURLOPT_PROXY=>'http://account-control:8080',CURLOPT_HTTPPROXYTUNNEL=>true]);
$plain=$request('http://moed.test/',[CURLOPT_PROXY=>'http://account-control:8080']);
$direct=$request($url,[CURLOPT_PROXY=>'']);
echo json_encode(compact('good','badCa','outside','port','plain','direct'));
'''
            client=['docker','run','--rm','-i','--network',networks[0],
                    '--mount',f'type=bind,src={ROOT}/src,dst=/var/www/html,readonly',
                    '--mount',f'type=bind,src={directory},dst=/fixture,readonly',
                    '--entrypoint','php',args.app_image]
            result=json.loads(run(client,input=php))
            assert result['good']['status']==200 and result['good']['body']=='account-control-ok',result
            assert result['badCa']['errno']==60,result
            assert result['outside']['connect']==403,result
            assert result['port']['connect']==403,result
            assert result['plain']['status']==403,result
            assert result['direct']['errno']!=0,result
            # A container on the shared edge cannot use another Account's relay.
            edge_client=['docker','run','--rm','--network',networks[1],'--entrypoint','curl',args.app_image,
                         '--silent','--show-error','--max-time','4','--proxy','http://'+relay+':8080','https://moed.test/']
            denied=subprocess.run(edge_client,text=True,capture_output=True)
            assert denied.returncode!=0 and '403' in denied.stderr,denied.stderr
            print('PASS: internal-only client reaches pinned TLS origin; CA verification, host/port/method and peer-network restrictions; no direct egress.')
        except BaseException:
            for container in containers:
                diagnostic=subprocess.run(['docker','logs','--tail','12',container],text=True,capture_output=True)
                print(diagnostic.stderr,file=sys.stderr)
            raise
        finally:
            for name in reversed(containers):subprocess.run(['docker','rm','-f',name],capture_output=True)
            for name in reversed(networks):subprocess.run(['docker','network','rm',name],capture_output=True)


if __name__=='__main__':main()
