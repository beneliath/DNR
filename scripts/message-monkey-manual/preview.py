#!/usr/bin/env python3
"""Prepare a separate, labelled screenshot preview; never reuse account volumes."""
import base64
import ipaddress
import json
from pathlib import Path
import secrets
import subprocess

ROOT = Path(__file__).resolve().parents[2]
WORK = ROOT / 'tmp/pdfs/message-monkey-manual'
PROJECT = 'dnr-mm-guide-20261026'
LABEL = {'org.dnr.disposable-test': PROJECT}

def docker(*args):
    return subprocess.check_output(['docker', *args], text=True)

if docker('ps', '-aq', '--filter', 'label=com.docker.compose.project='+PROJECT).strip():
    raise SystemExit('The guide preview already exists; inspect it before reusing it.')
for kind in ['volume', 'network']:
    if docker(kind, 'ls', '-q', '--filter', 'label=com.docker.compose.project='+PROJECT).strip():
        raise SystemExit('Existing guide '+kind+' found; inspect and clean up the old disposable preview first.')
networks = json.loads(docker('network', 'inspect', *docker('network', 'ls', '-q').split()))
used = [ipaddress.ip_network(c['Subnet']) for n in networks for c in (n.get('IPAM', {}).get('Config') or []) if c.get('Subnet')]
# No shared networks or static addresses. Reserve a distinct Docker-only /24.
subnet = next(ipaddress.ip_network(f'10.252.{i}.0/24') for i in range(210, 250)
              if not any(ipaddress.ip_network(f'10.252.{i}.0/24').overlaps(n) for n in used))
WORK.mkdir(parents=True, exist_ok=True)
for name in ['db-password', '2fa-key']:
    value = base64.b64encode(secrets.token_bytes(32)).decode() if name == '2fa-key' else secrets.token_hex(32)
    (WORK/name).write_text(value); (WORK/name).chmod(0o444)
shared = [f'{WORK}:/run/manual:ro', f'{ROOT}/scripts:/opt/dnr/bin:ro', f'{ROOT}/migrations:/opt/dnr/migrations:ro']
db_env = {name:'/run/manual/db-password' for name in ['MYSQL_PASSWORD_FILE','MYSQL_ROOT_PASSWORD_FILE','MYSQL_BACKUP_PASSWORD_FILE','MYSQL_MAINTENANCE_PASSWORD_FILE','MYSQL_GEOCODER_PASSWORD_FILE','MYSQL_MAIL_INGEST_PASSWORD_FILE','MYSQL_MAIL_DISPATCH_PASSWORD_FILE']}
db_env.update(MYSQL_DATABASE='dnr', MYSQL_USER='dnruser')
web_env = dict(DB_HOST='db',MYSQL_DATABASE='dnr',MYSQL_USER='dnruser',MYSQL_PASSWORD_FILE='/run/manual/db-password',
    DNR_2FA_ENCRYPTION_KEY_FILE='/run/manual/2fa-key',DNR_INBOUND_ROUTING_KEY_FILE='/run/manual/2fa-key',
    DNR_REQUIRE_HTTPS='0',DNR_MAIL_TRANSPORT='log',DNR_REQUIRE_STORAGE_MONITOR='0',
    DNR_PUBLIC_BASE_URL='http://127.0.0.1:18146',DNR_PRIMARY_PUBLIC_URL='https://community.example.test',
    DNR_TIMEZONE='America/Chicago',DNR_MANUAL_FIXTURE='disposable',DNR_MANUAL_USER='avery.guide',
    DNR_ACCOUNTS_ENABLED='0',DNR_ACCOUNT_MODE='legacy',DNR_ACCOUNT_KEY='message-monkey-guide',
    DNR_FILE_STORAGE_PATH='/var/lib/dnr/files',DNR_CONFIG_FILE='/run/dnr/config/application.yaml',
    DNR_AI_COACH_ENABLED='0',DNR_AUDIENCE_AI_URL='http://127.0.0.1:9')
compose = {'services':{
    'db':{'image':'dnr-database:local','labels':LABEL,'environment':db_env,
          'volumes':shared+['database:/var/lib/mysql','keyring:/var/lib/mysql-keyring'],
          'healthcheck':{'test':['CMD','mysqladmin','ping','-h','localhost'],'interval':'2s','timeout':'3s','retries':50}},
    'web':{'image':'dnr-app:local','labels':LABEL,'environment':web_env,'ports':['127.0.0.1:18146:80'],
           'tmpfs':['/var/lib/php/sessions:uid=33,gid=33,mode=0700'],
           'depends_on':{'db':{'condition':'service_healthy'}},'healthcheck':{'disable':True},
           'volumes':shared+[f'{ROOT}/src:/var/www/html:ro',f'{ROOT}/src:/opt/dnr/src:ro',f'{ROOT}/vendor:/opt/dnr/vendor:ro',
              f'{ROOT}/tests:/opt/dnr/tests:ro',f'{ROOT}/VERSION:/opt/dnr/VERSION:ro',
              f'{ROOT}/docker/php-development.ini:/usr/local/etc/php/conf.d/zz-dnr-development.ini:ro',
              f'{ROOT}/deployments/moed/application.yaml:/run/dnr/config/application.yaml:ro','files:/var/lib/dnr/files']}},
    'volumes':{name:{'labels':LABEL} for name in ['database','keyring','files']},
    'networks':{'default':{'internal':False,'labels':LABEL,'ipam':{'config':[{'subnet':str(subnet)}]}}}}
(WORK/'compose.json').write_text(json.dumps(compose,indent=2)+'\n')
(WORK/'network-check.json').write_text(json.dumps({'subnet':str(subnet),'checked_docker_subnets':[str(n) for n in used],'static_addresses':[],'shared_networks':[]},indent=2)+'\n')
print(f'Prepared {PROJECT}; separate subnet {subnet}; loopback port 18146; new labelled volumes.')
