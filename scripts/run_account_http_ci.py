#!/usr/bin/env python3
"""Fresh multi-Account HTTP suites on an ephemeral GitHub runner only.

Refuses existing named projects, directories or a listening localhost:8080.
Tests and local provisioner share the same synthetic, loopback-only deployment.
"""
import base64
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
from types import SimpleNamespace
from integration_environment import allocate_test_networks
from provision_local_account import ROOT, run, private_file, provision, clean_environment, control


def main():
    if os.environ.get('GITHUB_ACTIONS') != 'true' or os.environ.get('DNR_ACCOUNT_CI_DISPOSABLE') != '1':
        raise ValueError('Requires an explicitly disposable GitHub runner')
    existing=run(['docker','ps','-aq','--filter','label=com.docker.compose.project=dnr'],capture_output=True).stdout.strip()
    if existing or (ROOT/'var/accounts').exists() or (ROOT/'var/deployment/accounts-local.compose.json').exists():
        raise ValueError('Refusing an existing Account deployment')
    with socket.socket() as probe:
        if probe.connect_ex(('127.0.0.1',8080))==0:raise ValueError('Port 8080 is already in use')
    secret_dir=ROOT/'secrets';secret_dir.mkdir(exist_ok=True)
    values={'DNR_PUBLIC_BASE_URL':'http://localhost:8080/a/shalom-in-messiah','DNR_REQUIRE_HTTPS':'0',
            'DNR_ACCOUNTS_ENABLED':'1','DNR_ACCOUNT_GATEWAY_ENABLED':'1','DNR_ACCOUNT_MAIL_ENABLED':'1',
            'DNR_ACCOUNT_KEY':'shalom-in-messiah','DNR_ACCOUNT_MODE':'primary','DNR_MAIL_TRANSPORT':'log',
            'DNR_PRIMARY_PUBLIC_URL':'http://localhost:8080/a/shalom-in-messiah',
            'DNR_GATEWAY_INTERNAL_URL':'http://moed-primary','DNR_INBOUND_ADDRESS':'moed@beneliath.com','DNR_MAIL_FROM':'moed@beneliath.com',
            'DNR_APP_IMAGE':'dnr-app:local','DNR_DATABASE_IMAGE':'dnr-database:local','DNR_INGRESS_IMAGE':'dnr-ingress:local'}
    # Compose inputs below contain host paths. Keep them out of the container
    # environment, which already references mounted /run/secrets files.
    runtime_values=values.copy()
    for role in ['ROOT','APP','BACKUP','MAINTENANCE','GEOCODER','MAIL_INGEST','MAIL_DISPATCH']:
        path=secret_dir/('mysql_'+role.lower()+'_password');private_file(path,secrets.token_hex(32));path.chmod(0o444)
        values['DNR_MYSQL_'+role+'_PASSWORD_FILE']=str(path)
    for var,name in [('DNR_2FA_KEY_FILE','dnr_2fa_encryption_key'),('DNR_INBOUND_ROUTING_KEY_FILE','dnr_inbound_routing_key'),('DNR_BACKUP_PASSWORD_FILE','backup_password')]:
        path=secret_dir/name;private_file(path,base64.b64encode(secrets.token_bytes(32)).decode());path.chmod(0o444);values[var]=str(path)
    private_file(secret_dir/'deployment_backup_password',secrets.token_hex(32))
    private_file(secret_dir/'smtp_password','unused');(secret_dir/'smtp_password').chmod(0o444)
    ids=run(['docker','network','ls','-q'],capture_output=True).stdout.split()
    used=json.loads(run(['docker','network','inspect',*ids],capture_output=True).stdout) if ids else []
    networks=allocate_test_networks(used)
    values.update(DNR_BACKEND_SUBNET=str(networks['backend']),DNR_INGRESS_PROXY_IP=str(networks['backend'].network_address+254))
    private_file(ROOT/'.env',''.join(k+'='+v+'\n' for k,v in values.items()))
    output=ROOT/'var/deployment';output.mkdir(parents=True,exist_ok=True)
    overlay={'services':{},'networks':{k:{'ipam':{'config':[{'subnet':str(v)}]}} for k,v in networks.items()}}
    overlay['networks']['account_control']={'external':True,'name':'moed-account-control'}
    for svc in ['web','downloads','backup','geocoder','mail-dispatch']:
        overlay['services'][svc]={'environment':runtime_values.copy(),'mem_limit':'512m','cpus':1}
    overlay['services']['db']={'mem_limit':'512m','cpus':1}
    overlay['services']['ingress']={'networks':{'account_control':{'aliases':['moed-primary']}},'mem_limit':'128m'}
    path=output/'accounts-local.compose.json';private_file(path,json.dumps(overlay))
    run(['docker','network','create','--internal','moed-account-control'],capture_output=True)
    dc=['docker','compose','-p','dnr','-f',str(ROOT/'docker-compose.yaml'),'-f',str(ROOT/'docker-compose.dev.yaml'),'-f',str(ROOT/'docker-compose.smtp.yaml'),'-f',str(path)]
    env=clean_environment()
    os.environ['DNR_ACCOUNT_CI_LIMITS']='1'
    run(dc+['build','db','web','ingress'],env=env)
    run([sys.executable,str(ROOT/'tests/account_control_transport_test.py'),'--local-docker'])
    run([sys.executable,str(ROOT/'tests/account_ingress_http_test.py'),'--local-docker'])
    run([sys.executable,str(ROOT/'tests/account_production_compose_test.py'),'--local-docker'])
    run(dc+['up','-d','--no-build','--wait','db'],env=env)
    run(dc+['run','--rm','migrator'],env=env)
    run(dc+['up','-d','--no-build','--wait','web','downloads','backup','ingress','geocoder','mail-dispatch'],env=env)
    sys.path.insert(0,str(ROOT/'tests'))
    from account_isolation_http_test import fixture, cleanup
    seed=fixture('dnr-web-1')
    from account_mail_http_test import php
    for key in ['test-account','account-isolation-preview']:
        php('dnr',f"platformCreateAccount($conn,'{key}','{key}',{seed['users']['superadmin']['id']});echo '{{}}';")
    args=SimpleNamespace(primary_web='dnr-web-1',primary_db='dnr-db-1',primary_ingress='dnr-ingress-1',primary_port=8080,platform_network='moed-account-control')
    for key in ['test-account','account-isolation-preview']:provision(args,key)
    run(['docker','exec','-i','-e','DNR_ACCOUNT_CAPACITY_TEST=1',
         '-e','DNR_ACCOUNT_TEST_SOURCE_ROOT=/var/www/html','dnr-web-1','php','-d','memory_limit=64M'],
        input=(ROOT/'tests/account_mail_capacity_test.php').read_text())
    for script in ['account_isolation_http_test.py','account_identity_http_test.py','account_creation_http_test.py','account_consistency_http_test.py','account_mail_http_test.py','account_invitation_http_test.py']:
        run([sys.executable,str(ROOT/'tests'/script),'--local-preview'])
    lifecycle=fixture('dnr-web-1');key='lifecycle-check-'+secrets.token_hex(3)
    php('dnr',f"platformCreateAccount($conn,'Disposable Lifecycle Check','{key}',{lifecycle['users']['superadmin']['id']});echo '{{}}';")
    provision(args,key)
    fixture_path=output/'ci-lifecycle.json';private_file(fixture_path,json.dumps({'key':key,'primary':lifecycle}))
    run([sys.executable,str(ROOT/'tests/account_lifecycle_http_test.py'),'--local-preview','--fixture-file',str(fixture_path)])
    print('All Account HTTP suites passed on the disposable CI deployment.')
    # Runner teardown owns the remaining synthetic preview volumes. No generic
    # docker prune/down command can touch unrelated resources on a developer host.


if __name__=='__main__':main()
