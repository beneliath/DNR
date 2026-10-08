#!/usr/bin/env python3
"""Production Account control. Default: render a plan; --apply is operator-only.

Uses immutable release images, private Compose stacks and HTTPS Traefik routes.
Never enables Accounts or changes the primary deployment automatically.
"""
from __future__ import annotations
import argparse
import base64
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import time
from urllib.parse import urlsplit

from provision_local_account import ROOT, clean_environment, control, run, SERVICES
from integration_environment import allocate_test_networks
from account_lifecycle_local import inspect, project_resources, backup_configuration
from deployment_backup import create_verified_backup, root_command, sha
from account_ingress_security import configuration as ingress_security_configuration
from account_map_configuration import primary_amazon_map, with_amazon_map

ACTIVE_SERVICES = ['web', 'downloads', 'backup', 'ingress', 'geocoder', 'mail-dispatch',
                   'receipt-previews', 'file-monitor', 'data-maintenance', 'account-control']

PRIMARY_DIRECTORY = Path('var/deployment/account-production')

def private_file(path, content):
    temporary=path.with_name(path.name+'.'+secrets.token_hex(6)+'.tmp')
    try:
        fd=os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        with os.fdopen(fd,'w') as handle:
            handle.write(content);handle.flush();os.fsync(handle.fileno())
        temporary.replace(path)
        directory=os.open(path.parent,os.O_RDONLY)
        try:os.fsync(directory)
        finally:os.close(directory)
    finally:
        temporary.unlink(missing_ok=True)


def validate_config(config):
    origin = config['origin']
    if not re.fullmatch(r'https://[a-z0-9.-]+(?::[0-9]+)?', origin):
        raise ValueError('A canonical HTTPS origin without a path is required')
    for name in ['primary_key', 'project_prefix', 'edge_network', 'entrypoint', 'certresolver', 'primary_router']:
        if not re.fullmatch(r'[a-z][a-z0-9_.-]{2,63}' if name in ['edge_network','primary_router'] else r'[a-z][a-z0-9-]{2,63}', config[name]): raise ValueError('Invalid ' + name)
    for name in ['primary_web', 'primary_db', 'primary_ingress']:
        if not re.fullmatch(r'[a-z0-9][a-z0-9_.-]{2,100}', config[name]): raise ValueError('Invalid container name')
    if not re.fullmatch(r'[a-f0-9]{40}', config['commit']): raise ValueError('An exact release commit is required')
    if set(config['images']) != {'app', 'database', 'ingress'}: raise ValueError('Three release images are required')
    for image in config['images'].values():
        if not re.fullmatch(r'ghcr.io/[a-z0-9._/-]+@sha256:[a-f0-9]{64}', image): raise ValueError('Use qualified immutable release images')
    ipaddress.ip_address(config['traefik_ip'])
    ipaddress.ip_address(config['cloudflared_ip'])
    if config.get('primary_control_edge_ip'):
        address=ipaddress.ip_address(config['primary_control_edge_ip'])
        if address.version!=4 or address==ipaddress.ip_address(config['traefik_ip']):
            raise ValueError('Primary control requires a distinct IPv4 edge address')
    primary_subnet = ipaddress.ip_network(config['primary_backend_subnet'])
    if ipaddress.ip_address(config['primary_ingress_ip']) not in primary_subnet:
        raise ValueError('Primary ingress must belong to the primary backend network')
    for name in ['smtp_password_file', 'backup_password_file']:
        if not Path(config[name]).is_absolute(): raise ValueError('Secret paths must be absolute')
    smtp = config['smtp']
    if set(smtp) != {'host', 'port', 'encryption', 'username', 'from'} or smtp['encryption'] not in ['starttls', 'tls']:
        raise ValueError('Explicit TLS SMTP configuration is required')
    if any('\n' in str(v) or '\r' in str(v) for v in smtp.values()): raise ValueError('Invalid SMTP configuration')
    return config


def labels(config, key, primary=False):
    """Exact path boundary. Root routes are a public allowlist, never an Account alias."""
    name = 'account-' + key
    host = urlsplit(config['origin']).hostname
    prefix = '/a/' + key
    router = 'traefik.http.routers.' + name
    service = 'traefik.http.services.' + name
    result = {'traefik.enable': 'true', 'traefik.docker.network': config['edge_network'],
              router+'.rule': f'Host(`{host}`) && (Path(`{prefix}`) || PathPrefix(`{prefix}/`))',
              router+'.priority': '200', router+'.entrypoints': config['entrypoint'], router+'.tls': 'true',
              router+'.tls.certresolver': config['certresolver'], router+'.service': name,
              router+'.middlewares': name+'-slash,'+name+'-strip',
              'traefik.http.middlewares.'+name+'-slash.redirectregex.regex': '^https?://([^/]+)'+prefix+'$',
              'traefik.http.middlewares.'+name+'-slash.redirectregex.replacement': 'https://$${1}'+prefix+'/',
              'traefik.http.middlewares.'+name+'-strip.stripprefix.prefixes': prefix,
              service+'.loadbalancer.server.port': '80',
              service+'.loadbalancer.healthcheck.path': '/ready.php',
              service+'.loadbalancer.healthcheck.interval': '10s'}
    if primary:
        result['traefik.http.routers.'+config['primary_router']+'.rule'] = result[router+'.rule']
        result['traefik.http.routers.'+config['primary_router']+'.service'] = name
        common = name+'-public'
        public_paths = ['/login.php', '/recover_password.php', '/accept_invitation.php', '/verify_email.php',
                        '/calendar.php', '/short_link.php', '/presentation_asset.php', '/ready.php',
                        '/health.php', '/api/v1/mattermost.php', '/deployment_status.php']
        rule = ' || '.join('Path(`'+p+'`)' for p in public_paths)
        result.update({f'traefik.http.routers.{common}.rule': f'Host(`{host}`) && ({rule} || PathPrefix(`/assets/`) || PathPrefix(`/surls/`))',
                       f'traefik.http.routers.{common}.priority': '100',
                       f'traefik.http.routers.{common}.entrypoints': config['entrypoint'],
                       f'traefik.http.routers.{common}.tls': 'true',
                       f'traefik.http.routers.{common}.tls.certresolver': config['certresolver'],
                       f'traefik.http.routers.{common}.service': name})
        # Unknown Account paths must not fall through to a primary Account page.
        catch = name+'-entry'
        result.update({f'traefik.http.routers.{catch}.rule': f'Host(`{host}`)',
                       f'traefik.http.routers.{catch}.priority': '1',
                       f'traefik.http.routers.{catch}.entrypoints': config['entrypoint'],
                       f'traefik.http.routers.{catch}.tls': 'true',
                       f'traefik.http.routers.{catch}.tls.certresolver': config['certresolver'],
                       f'traefik.http.routers.{catch}.service': name,
                       f'traefik.http.routers.{catch}.middlewares': catch,
                       f'traefik.http.middlewares.{catch}.redirectregex.regex': '^https?://[^/]+/.*$',
                       f'traefik.http.middlewares.{catch}.redirectregex.replacement': config['origin']+'/login.php'})
    return result


def account_environment(config, key, primary=False):
    return {'DNR_ACCOUNTS_ENABLED':'1', 'DNR_ACCOUNT_GATEWAY_ENABLED':'1', 'DNR_ACCOUNT_MAIL_ENABLED':'1',
            'DNR_ACCOUNT_MODE':'primary' if primary else 'member', 'DNR_ACCOUNT_KEY':key,
            'DNR_REQUIRE_HTTPS':'1', 'DNR_PUBLIC_BASE_URL':config['origin']+'/a/'+key,
            'DNR_PRIMARY_PUBLIC_URL':config['origin']+'/a/'+config['primary_key'],
            'DNR_PRIMARY_INTERNAL_URL':config['origin']+'/a/'+config['primary_key'],
            'DNR_GATEWAY_INTERNAL_URL':config['origin'], 'DNR_INBOUND_ADDRESS':config['smtp']['from'],
            'DNR_MAIL_FROM':config['smtp']['from'],
            'DNR_ACCOUNT_CONTROL_PROXY':'http://account-control:8080',
            'DNR_TRUSTED_PROXY_IPS':config['traefik_ip'] + (','+config['primary_ingress_ip'] if primary else ''),
            'DNR_TRUSTED_CLOUDFLARE_PROXY_IPS':config['cloudflared_ip']}


def control_proxy_configuration(config, subnet):
    """A TLS tunnel to ONE pinned edge origin, accessible only from this backend.

    TLS/SNI/certificate verification remains end-to-end in the PHP client. This
    container has no application secrets, shared database network or public port.
    """
    origin = urlsplit(config['origin'])
    host, port = origin.hostname, origin.port or 443
    subnet = str(ipaddress.ip_network(subnet))
    return f'''ServerRoot /etc/apache2
ServerName account-control
PidFile /tmp/account-control.pid
Listen 8080
IncludeOptional /etc/apache2/mods-enabled/*.load
IncludeOptional /etc/apache2/mods-enabled/*.conf
<IfModule mpm_prefork_module>
    StartServers 1
    MinSpareServers 1
    MaxSpareServers 2
    ServerLimit 8
    MaxRequestWorkers 8
    MaxConnectionsPerChild 1000
</IfModule>
<IfModule !proxy_connect_module>
LoadModule proxy_connect_module /usr/lib/apache2/modules/mod_proxy_connect.so
</IfModule>
User www-data
Group www-data
ServerTokens Prod
ServerSignature Off
ErrorLog /proc/self/fd/2
LogLevel warn
Timeout 30
ProxyTimeout 30
ProxyRequests On
AllowCONNECT {port}
<Directory />
    Require all denied
</Directory>
<Proxy "*">
    Require all denied
</Proxy>
<ProxyMatch "^{re.escape(host)}:{port}$">
    <RequireAll>
        Require ip {subnet}
        Require method CONNECT
    </RequireAll>
</ProxyMatch>
'''


def control_proxy_service(config, configuration, image):
    return {'image':image, 'entrypoint':['apache2-foreground', '-f', '/run/dnr/account-control.conf'],
            'restart':'unless-stopped', 'read_only':True, 'mem_limit':'128m', 'pids_limit':64,
            'cap_drop':['ALL'], 'cap_add':['CHOWN','DAC_OVERRIDE','SETGID','SETUID'],
            'security_opt':['no-new-privileges:true'],
            'tmpfs':['/tmp:rw,noexec,nosuid,size=16m', '/var/run/apache2:rw,noexec,nosuid,size=1m',
                     '/var/lock/apache2:rw,noexec,nosuid,size=1m'],
            'volumes':[str(configuration)+':/run/dnr/account-control.conf:ro'],
            'networks':['backend','edge'],
            'extra_hosts':{urlsplit(config['origin']).hostname:config['traefik_ip']},
            'labels':{'traefik.enable':'false','com.centurylinklabs.watchtower.enable':'false'},
            'healthcheck':{'test':['CMD','php','-r', '$$s=@fsockopen("127.0.0.1",8080);exit($$s?0:1);'],
                           'interval':'10s','timeout':'3s','retries':3}}


def primary_overlay(config, directory=None):
    directory = directory or ROOT/PRIMARY_DIRECTORY
    environment = account_environment(config, config['primary_key'], True)
    relay=control_proxy_service(config, directory/'control.conf', '${DNR_INGRESS_IMAGE:?Qualified ingress image required}')
    if config.get('primary_control_edge_ip'):
        relay['networks']={'backend':{},'edge':{'ipv4_address':config['primary_control_edge_ip']}}
    return {'networks':{'edge':{'external':True,'name':config['edge_network']}},
            'services': {**{s:{'environment':environment} for s in SERVICES},
            'mail-ingest':{'environment':environment},
            'account-control':relay,
            'ingress': {'networks':{'backend':{'ipv4_address':config['primary_ingress_ip']}},
                        'volumes':[str(directory/'ingress-security.conf')+':/etc/apache2/conf-enabled/zy-dnr-account-security.conf:ro'],
                        'labels': labels(config, config['primary_key'], True)}}}


def prepare_primary(config, directory=None):
    """Prepare persistent configuration only. Never apply it to running services."""
    directory = directory or ROOT/PRIMARY_DIRECTORY
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    private_file(directory/'config.json', json.dumps(config, indent=2))
    private_file(directory/'control.conf', control_proxy_configuration(config, config['primary_backend_subnet']))
    private_file(directory/'ingress-security.conf', ingress_security_configuration(config['primary_key'],config['origin']+'/a/'+config['primary_key'],True))
    private_file(directory/'primary.compose.json', json.dumps(primary_overlay(config, directory), indent=2))
    private_file(directory/'notes-cache.compose.json', json.dumps({'services':{'notes-cache':{
        'environment':account_environment(config, config['primary_key'], True)}}}, indent=2))


def retain_primary_control_address(config):
    """Keep the existing edge allocation when release images recreate the relay."""
    if config.get('primary_control_edge_ip'):
        return config
    primary=inspect(config['primary_web'])
    project=primary['Config']['Labels']['com.docker.compose.project']
    containers=run(['docker','ps','-aq','--filter','label=com.docker.compose.project='+project,
                    '--filter','label=com.docker.compose.service=account-control'],capture_output=True).stdout.split()
    if not containers:
        return config
    if len(containers)!=1:
        raise ValueError('Cannot identify the primary Account control relay')
    address=inspect(containers[0])['NetworkSettings']['Networks'][config['edge_network']]['IPAddress']
    if not address:
        raise ValueError('Primary Account control relay has no reserved edge address')
    return validate_config({**config,'primary_control_edge_ip':address})


def compose(directory, project):
    return ['docker','compose','--env-file',str(directory/'account.env'),'-p',project,
            '-f',str(ROOT/'docker-compose.yaml'),'-f',str(ROOT/'docker-compose.smtp.yaml'),
            '-f',str(directory/'compose.json'),'-f',str(directory/'edge.yaml')]


def save_runtime(directory, project, state):
    if (directory/'runtime.compose.json').exists():
        saved_compose(directory, project, state)
        return
    rendered = run(compose(directory, project)+['--profile','*','config','--format','json'],
                   env=clean_environment(), capture_output=True).stdout
    # Freeze resolved images, environment, paths and service definitions. Lifecycle
    # must not consult a later checkout, .env or changed Compose defaults.
    document = json.loads(rendered)
    document['x-moed-active-services'] = list(ACTIVE_SERVICES)
    validate_active_services(document)
    freeze_configuration(directory, document)
    private_file(directory/'runtime.compose.json', json.dumps(document, indent=2))
    state['runtime_sha256'] = sha(directory/'runtime.compose.json')
    private_file(directory/'deployment.json', json.dumps(state, indent=2))


def saved_compose(directory, project, metadata):
    runtime = directory/'runtime.compose.json'
    if not runtime.is_file() or sha(runtime) != metadata.get('runtime_sha256'):
        raise ValueError('Saved qualified Account runtime is missing or changed')
    document = json.loads(runtime.read_text())
    qualify_runtime(directory,document,metadata)
    return ['docker','compose','-p',project,'-f',str(runtime)]


def qualify_runtime(directory,document,metadata):
    validate_active_services(document)
    verify_frozen_configuration(directory, document)
    images = {kind:document['services'][service]['image']
              for kind,service in [('app','web'),('database','db'),('ingress','ingress')]}
    verify_images({'images':images, 'commit':metadata['commit']})


def saved_active_services(directory):
    document = json.loads((directory/'runtime.compose.json').read_text())
    return validate_active_services(document)


def validate_active_services(document):
    active = document.get('x-moed-active-services')
    if not isinstance(active, list) or not {'web','ingress'}.issubset(active) \
            or any(not isinstance(s,str) or s not in document['services'] for s in active) \
            or set(active) & {'db','migrator','file-migrator','maintenance','key-rotation'}:
        raise ValueError('Saved active Account service list is invalid')
    return active


def configuration_digest(path):
    """Hash file bytes, tree membership and permissions; never follow symlinks."""
    entries = []
    for item in [path, *sorted(path.rglob('*'))] if path.is_dir() else [path]:
        if item.is_symlink() or not (item.is_file() or item.is_dir()):
            raise ValueError('Unsafe or missing frozen Account configuration')
        entries.append([str(item.relative_to(path)), item.stat().st_mode & 0o777,
                        sha(item) if item.is_file() else 'directory'])
    return hashlib.sha256(json.dumps(entries).encode()).hexdigest()


def freeze_configuration(directory, document):
    # Only these operational inputs intentionally track live host state. Secrets
    # remain separately managed/rotatable Compose secrets, never checkout config.
    live_targets = {'/run/dnr/deployment', '/backups'}
    frozen = directory/'runtime-config'
    frozen.mkdir(exist_ok=True, mode=0o700)
    copies = {}; manifest = {}; live = []
    for service in document['services'].values():
        service.pop('build', None)  # A saved release must never rebuild a checkout.
        for volume in service.get('volumes', []):
            if volume['type'] != 'bind': continue
            source = Path(volume['source'])
            if volume['target'] in live_targets:
                if not volume.get('read_only'): raise ValueError('Live operational inputs must be read-only')
                live.append({'source':str(source), 'target':volume['target']})
                continue
            if not volume.get('read_only'): raise ValueError('Unexpected writable host bind in Account runtime')
            if str(source) not in copies:
                configuration_digest(source)  # Reject missing files and symlinks before copying.
                target = frozen/(str(len(copies))+'-'+source.name)
                if target.exists():
                    if target.is_dir(): shutil.rmtree(target)
                    else: target.unlink()
                if source.is_dir(): shutil.copytree(source, target)
                else: shutil.copy2(source, target)
                copies[str(source)] = target
                manifest[str(target.relative_to(directory))] = configuration_digest(target)
            volume['source'] = str(copies[str(source)])
    document['x-moed-frozen-configuration'] = manifest
    document['x-moed-live-binds'] = live


def verify_frozen_configuration(directory, document):
    manifest = document.get('x-moed-frozen-configuration')
    if not isinstance(manifest, dict): raise ValueError('Missing frozen Account configuration manifest')
    for relative, digest in manifest.items():
        path = directory/relative
        if '..' in Path(relative).parts or Path(relative).is_absolute() or not relative.startswith('runtime-config/') \
                or configuration_digest(path) != digest:
            raise ValueError('Frozen Account configuration is missing or changed')
    live = document.get('x-moed-live-binds', [])
    for service in document['services'].values():
        for volume in service.get('volumes', []):
            if volume['type'] != 'bind': continue
            if {'source':volume['source'], 'target':volume['target']} in live \
                    and volume['target'] in {'/run/dnr/deployment', '/backups'} and volume.get('read_only'):
                continue
            source = Path(volume['source'])
            if not source.is_relative_to(directory) or str(source.relative_to(directory)) not in manifest:
                raise ValueError('Unqualified configuration bind in saved Account runtime')


def assert_primary(config):
    primary = inspect(config['primary_web'])
    if primary['Config']['Image'] != config['images']['app']:
        raise ValueError('Primary image does not match its current qualified release')
    env = dict(v.split('=',1) for v in primary['Config']['Env'])
    expected = account_environment(config, config['primary_key'], True)
    for key in ['DNR_REQUIRE_HTTPS','DNR_PUBLIC_BASE_URL','DNR_ACCOUNT_KEY','DNR_ACCOUNT_MODE',
                'DNR_ACCOUNT_GATEWAY_ENABLED','DNR_TRUSTED_PROXY_IPS','DNR_ACCOUNT_CONTROL_PROXY']:
        if env.get(key) != expected[key]: raise ValueError('Primary is not qualified for Account control: '+key)
    if primary['Config']['Labels'].get('com.docker.compose.project') == config['project_prefix']:
        raise ValueError('Member project prefix must differ from primary')
    ingress = inspect(config['primary_ingress'])
    policy_path='/etc/apache2/conf-enabled/zy-dnr-account-security.conf'
    if ingress['Config']['Image'] != config['images']['ingress'] or not any(
            mount['Destination']==policy_path and mount.get('RW') is False
            for mount in ingress.get('Mounts',[])):
        raise ValueError('Trusted primary Account ingress policy is not active')
    expected_policy=ingress_security_configuration(config['primary_key'],config['origin']+'/a/'+config['primary_key'],True)
    actual_hash=run(['docker','exec',config['primary_ingress'],'sha256sum',policy_path],capture_output=True).stdout.split()[0]
    if actual_hash != hashlib.sha256(expected_policy.encode()).hexdigest():
        raise ValueError('Primary Account ingress policy differs from qualified configuration')
    expected_labels = labels(config, config['primary_key'], True)
    for label in ['traefik.http.routers.'+config['primary_router']+'.rule',
                  'traefik.http.routers.account-'+config['primary_key']+'.rule',
                  'traefik.http.middlewares.account-'+config['primary_key']+'-strip.stripprefix.prefixes']:
        if ingress['Config']['Labels'].get(label) != expected_labels[label]:
            raise ValueError('Reviewed primary gateway overlay is not active')
    if any(ingress['NetworkSettings'].get('Ports', {}).values()):
        raise ValueError('Production ingress must not publish a host port')
    relay = inspect(primary['Config']['Labels']['com.docker.compose.project']+'-account-control-1')
    if relay['Config']['Image'] != config['images']['ingress'] or relay['State'].get('Health',{}).get('Status') != 'healthy':
        raise ValueError('Account control relay is not qualified and healthy')
    return primary


def verify_images(config):
    for image in config['images'].values():
        metadata = json.loads(run(['docker','image','inspect',image],capture_output=True).stdout)[0]
        if metadata['Config']['Labels'].get('org.opencontainers.image.revision') != config['commit']:
            raise ValueError('Release image provenance mismatch')


def ready_probe(config, key, public_url=None):
    # The response is HMAC authenticated and names the private database identity.
    control(config['primary_web'], 'probe', key, public_url or config['origin']+'/a/'+key)


def provision(config, key):
    if not re.fullmatch(r'[a-z][a-z0-9-]{2,63}',key) or key == config['primary_key']: raise ValueError('Invalid member')
    # Current-primary qualification is independent of the member's saved release.
    assert_primary(config)
    account = control(config['primary_web'],'claim',key)
    directory = ROOT/'var/accounts'/key
    if directory.is_symlink(): raise ValueError('Unsafe Account directory')
    directory.mkdir(parents=True,exist_ok=True,mode=0o700)
    statefile = directory/'deployment.json'
    if statefile.exists():
        state = json.loads(statefile.read_text())
        if state.get('mode') != 'production' or state.get('account_key') != key \
                or state.get('project') != config['project_prefix']+'-'+key:
            raise ValueError('Refusing another deployment')
    else:
        ids = run(['docker','network','ls','-q'],capture_output=True).stdout.split()
        networks = json.loads(run(['docker','network','inspect',*ids],capture_output=True).stdout) if ids else []
        state = {'project':config['project_prefix']+'-'+key, 'mode':'production', 'account_key':key,
                 'commit':config['commit'], 'networks':{k:str(v) for k,v in allocate_test_networks(networks).items()}}
        private_file(statefile,json.dumps(state,indent=2))
    project = state['project']
    runtime = directory/'runtime.compose.json'
    if runtime.exists() and not state.get('runtime_sha256'):
        # Recover a crash between the two atomic file writes. No resource may
        # have started without the metadata commit, and all content/image checks
        # must still pass before recording the original snapshot's checksum.
        if any(project_resources(project, kind) for kind in ['container','volume','network']):
            raise ValueError('Existing Account resources require qualified runtime metadata')
        qualify_runtime(directory,json.loads(runtime.read_text()),state)
        state['runtime_sha256']=sha(runtime)
        private_file(statefile,json.dumps(state,indent=2))
    if not runtime.exists():
        # Runtime is committed before the first Docker resource is created. A
        # pre-runtime interruption has no database/schema to upgrade; preserve
        # secrets and qualify the still-unstarted preparation at the current release.
        # Never reconstruct missing runtime metadata for an existing deployment.
        if state.get('runtime_sha256') or any(project_resources(project, kind) for kind in ['container','volume','network']):
            raise ValueError('Existing Account resources require their saved runtime')
        verify_images(config)
        state['commit'] = config['commit']
        private_file(statefile,json.dumps(state,indent=2))
        prepare_member_runtime(config,key,account,directory,state)
    dc=saved_compose(directory,project,state)
    document=json.loads(runtime.read_text())
    public_url=document['services']['web']['environment']['DNR_PUBLIC_BASE_URL']
    def call(*args): return run(dc+list(args),env=clean_environment(),cwd=ROOT,capture_output=True)
    call('up','-d','--no-build','--wait','db')
    # A retry runs only the original member's migrator/configuration. The primary
    # may have advanced, but no newer image or checkout enters this transaction.
    call('run','--rm','--no-deps','migrator')
    sql="""SET @fresh=((SELECT account_key FROM account_profile WHERE id=1)='shalom-in-messiah'
      AND (SELECT COUNT(*) FROM users)=0 AND (SELECT COUNT(*) FROM organizations)=0 AND (SELECT COUNT(*) FROM engagements)=0);
DELETE FROM speakers WHERE @fresh;
UPDATE account_profile SET account_key='%s',name=CONVERT(0x%s USING utf8mb4) WHERE id=1 AND @fresh;
SELECT account_key FROM account_profile WHERE id=1;""" % (key,account['name'].encode().hex())
    initialized=run(root_command(project+'-db-1','mysql','-uroot','dnr','-N'),input=sql,capture_output=True).stdout.strip()
    if initialized != key: raise ValueError('Private database identity mismatch')
    call('up','-d','--no-build','--wait',*saved_active_services(directory))
    for attempt in range(12):
        try: ready_probe(config,key,public_url);break
        except Exception:
            if attempt==11: raise
            time.sleep(5)
    control(config['primary_web'],'ready',key,public_url)


def prepare_member_runtime(config,key,account,directory,state):
    """Prepare and freeze an unstarted member. Never start Docker resources here."""
    project=state['project']
    values = account_environment(config,key)
    values.update({'DNR_APP_IMAGE':config['images']['app'], 'DNR_DATABASE_IMAGE':config['images']['database'],
                   'DNR_INGRESS_IMAGE':config['images']['ingress'], 'DNR_CONFIG_FILE_HOST':str(directory/'application.yaml'),
                   'DNR_BACKEND_SUBNET':state['networks']['backend'], 'DNR_MAIL_TRANSPORT':'smtp',
                   'DNR_SMTP_PASSWORD_SECRET_FILE':config['smtp_password_file']})
    for name in ['host','port','encryption','username']: values['DNR_SMTP_'+name.upper()]=str(config['smtp'][name])
    values['DNR_INGRESS_PROXY_IP']=str(ipaddress.ip_network(values['DNR_BACKEND_SUBNET']).network_address+254)
    values['DNR_TRUSTED_PROXY_IPS']+=','+values['DNR_INGRESS_PROXY_IP']
    for role in ['ROOT','APP','BACKUP','MAINTENANCE','GEOCODER','MAIL_INGEST','MAIL_DISPATCH']:
        path=directory/('mysql_'+role.lower())
        if not path.exists(): private_file(path,secrets.token_hex(32))
        path.chmod(0o444); values['DNR_MYSQL_'+role+'_PASSWORD_FILE']=str(path)
    for name in ['DNR_2FA_KEY_FILE','DNR_INBOUND_ROUTING_KEY_FILE','DNR_BACKUP_PASSWORD_FILE']:
        path=directory/name.lower()
        if not path.exists(): private_file(path,base64.b64encode(secrets.token_bytes(32)).decode())
        path.chmod(0o444);values[name]=str(path)
    secret=directory/'platform_api_key'; private_file(secret,account['api_key']);secret.chmod(0o444)
    profile=directory/'application.yaml'
    if not profile.exists(): private_file(profile,json.dumps({'brand':{'display_name':'MOED','native_name':'','mail_name':'MOED','totp_issuer':'MOED','calendar_name':account['name']+' Events'},'defaults':{'speaker':'Unassigned Speaker','timezone':'America/Chicago'}}))
    profile.chmod(0o444)
    private_file(directory/'account.env',''.join(k+'='+v+'\n' for k,v in values.items()))
    overlay={'services':{},'networks':{'edge':{'external':True,'name':config['edge_network']}},'secrets':{'platform_api_key':{'file':str(secret)}}}
    member_environment = account_environment(config, key)
    member_environment['DNR_TRUSTED_PROXY_IPS'] = values['DNR_TRUSTED_PROXY_IPS']
    member_environment['DNR_PLATFORM_API_KEY_FILE'] = '/run/secrets/platform_api_key'
    for service in SERVICES:
        overlay['services'][service]={'environment':member_environment,'secrets':['platform_api_key']}
    for name,subnet in state['networks'].items(): overlay['networks'][name]={'ipam':{'config':[{'subnet':subnet}]}}
    private_file(directory/'ingress-security.conf',ingress_security_configuration(key,values['DNR_PUBLIC_BASE_URL']))
    overlay['services']['ingress']={'networks':{'edge':{}},'labels':labels(config,key),
        'volumes':[str(directory/'ingress-security.conf')+':/etc/apache2/conf-enabled/zy-dnr-account-security.conf:ro']}
    private_file(directory/'control.conf', control_proxy_configuration(config, values['DNR_BACKEND_SUBNET']))
    overlay['services']['account-control']=control_proxy_service(config, directory/'control.conf', config['images']['ingress'])
    # No dev overlay, source bind, host port, shared DB, sessions, files or Account key.
    overlay=with_amazon_map(overlay,primary_amazon_map(config))
    private_file(directory/'compose.json',json.dumps(overlay,indent=2))
    private_file(directory/'edge.yaml','services:\n  ingress:\n    ports: !reset []\n')
    dc=compose(directory,project)
    def call(*args,**kwargs): return run(dc+list(args),env=clean_environment(),cwd=ROOT,capture_output=True,**kwargs)
    call('config','--quiet')
    save_runtime(directory, project, state)


def lifecycle(config,account):
    key,state=account['account_key'],account['state']
    if not re.fullmatch(r'[a-z][a-z0-9-]{2,63}',key) or key==config['primary_key']: raise ValueError('Primary protected')
    assert_primary(config)
    directory=ROOT/'var/accounts'/key
    project=config['project_prefix']+'-'+key
    recovery=ROOT/'var/backups/account-deletion'/key
    receipt_path=recovery/'deletion-receipt.json'
    if directory.exists():
        metadata=json.loads((directory/'deployment.json').read_text())
        if directory.is_symlink() or metadata.get('mode')!='production' or metadata['project']!=project: raise ValueError('Account ownership mismatch')
    elif state!='deleting' or not receipt_path.exists(): raise ValueError('Account deployment is missing')
    current=next((a for a in control(config['primary_web'],'lifecycle') if a['account_key']==key),None)
    if not current or current['state']!=state: raise ValueError('Lifecycle request changed')
    dc=saved_compose(directory,project,metadata) if directory.exists() else None
    if state in ['archiving','deleting']:
        containers=project_resources(project,'container')
        if containers: run(['docker','stop',*containers],capture_output=True)
        # Stopping ingress withdraws its route from Traefik. Stored volumes survive archive.
    if state=='restoring':
        run(dc+['up','-d','--no-build','--no-deps','--wait','db'],env=clean_environment(),capture_output=True)
        run(root_command(project+'-db-1','mysql','-uroot','dnr'),input='UPDATE users SET auth_version=auth_version+1; DELETE FROM account_login_handoffs;',capture_output=True)
        # Restart only the saved services. In particular, do not run migrator or
        # file-migrator dependencies against the archived database/files.
        run(dc+['up','-d','--no-build','--no-deps','--wait',*saved_active_services(directory)],env=clean_environment(),capture_output=True)
        ready_probe(config,key)
    elif state=='deleting':
        recovery.mkdir(parents=True,exist_ok=True,mode=0o700)
        if receipt_path.exists(): receipt=json.loads(receipt_path.read_text())
        else:
            run(dc+['up','-d','--no-build','--no-deps','--wait','db'],env=clean_environment(),capture_output=True)
            try:
                actual=run(root_command(project+'-db-1','mysql','-uroot','dnr','-NBe','SELECT account_key FROM account_profile WHERE id=1'),capture_output=True).stdout.strip()
                if actual!=key: raise ValueError('Backup identity mismatch')
                app=inspect(project+'-web-1')['Image'];db=inspect(project+'-db-1')['Image']
                receipt=create_verified_backup(project+'-db-1',db,app,metadata['commit'],'account-delete',Path(config['backup_password_file']),recovery)
                receipt['configuration']=backup_configuration(directory,receipt,app,Path(config['backup_password_file']))
                receipt.update(account_key=key,project=project)
                private_file(receipt_path,json.dumps(receipt,indent=2))
            finally: run(['docker','stop',project+'-db-1'],capture_output=True)
        if receipt.get('account_key')!=key or receipt.get('project')!=project or not receipt.get('restore_verified'): raise ValueError('Invalid recovery receipt')
        for item in [receipt,receipt['configuration'],receipt.get('persistent_files')]:
            if item and sha(Path(item['backup_path']))!=item['backup_sha256']: raise ValueError('Backup checksum changed')
        for kind in ['container','volume','network']:
            ids=project_resources(project,kind)
            if ids: run((['docker','rm','-f'] if kind=='container' else ['docker',kind,'rm'])+ids,capture_output=True)
        # Keep encrypted recovery copy; remove only this member's secret directory.
        if directory.exists():
            import shutil
            shutil.rmtree(directory)
    elif state!='archiving': raise ValueError('Invalid lifecycle state')
    control(config['primary_web'],'lifecycle_complete',key,state)


def cycle(config):
    assert_primary(config)
    # Share the normal primary release lock: no provisioning, mail or lifecycle
    # operation can race its writer pause, backup, migrations or image change.
    lockdir=ROOT/'.git/dnr-deploy';lockdir.mkdir(parents=True,exist_ok=True)
    with (lockdir/'deploy.lock').open('a') as release_lock:
        try: fcntl.flock(release_lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: return
        failed=False
        for script in ['reconcile_account_directory.php','deliver_account_mail.php']:
            try: run(['docker','exec','-i',config['primary_web'],'php'],input=(ROOT/'scripts'/script).read_text(),capture_output=True)
            except subprocess.CalledProcessError:
                failed=True
                print('Account synchronization or mail delivery is pending.',flush=True)
        for account in control(config['primary_web'],'lifecycle'):
            try: lifecycle(config,account)
            except Exception:
                control(config['primary_web'],'lifecycle_failed',account['account_key'])
                failed=True
                print('Account lifecycle requires operator attention.',flush=True)
        for account in control(config['primary_web'],'pending'):
            try: provision(config,account['account_key'])
            except Exception:
                failed=True
                print('Account preparation will retry.',flush=True)
        if failed: raise RuntimeError('Account operations pending')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('config',type=Path)
    parser.add_argument('--apply',action='store_true')
    parser.add_argument('--watch',action='store_true')
    parser.add_argument('--prepare-primary',action='store_true')
    parser.add_argument('--release-manifest',type=Path)
    args=parser.parse_args()
    config=validate_config(json.loads(args.config.read_text()))
    if args.release_manifest:
        if not args.prepare_primary: raise ValueError('Release manifest requires configuration preparation')
        release=json.loads(args.release_manifest.read_text())
        config=validate_config({**config,'commit':release['commit'],
                                'images':{k:release['images'][k] for k in ['app','database','ingress']}})
        config=retain_primary_control_address(config)
    if args.prepare_primary:
        if args.apply or args.watch: raise ValueError('Preparation cannot apply a deployment')
        prepare_primary(config)
        return
    if not args.apply:
        print(json.dumps({'primary_overlay':primary_overlay(config),'worker_services':ACTIVE_SERVICES,
                          'actions':['directory reconciliation','shared recovery','mail delivery','pending provisioning','requested lifecycle']},indent=2));return
    lockdir=ROOT/'var/deployment';lockdir.mkdir(exist_ok=True)
    with (lockdir/'account-production.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        while True:
            try:
                config=validate_config(json.loads(args.config.read_text()))
                cycle(config)
                private_file(lockdir/'account-production-health.json',json.dumps({'checked_at':time.time(),'ok':True}))
            except Exception as error:
                private_file(lockdir/'account-production-health.json',json.dumps({'checked_at':time.time(),'ok':False,'error':type(error).__name__}))
                print('Account worker needs attention: '+type(error).__name__,flush=True)
                if not args.watch: raise
            if not args.watch:return
            time.sleep(5)


if __name__=='__main__': main()
