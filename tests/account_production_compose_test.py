"""Generate primary/member production plans and parse them; never start services."""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import account_production as production


def render(command):
    result=subprocess.run(command,capture_output=True,text=True,env=production.clean_environment())
    if result.returncode: raise RuntimeError(result.stderr[-2500:])
    return result


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-docker',action='store_true',required=True);parser.parse_args()
    config=json.loads((ROOT/'deploy/account-production.example.json').read_text())
    config.update(commit='a'*40,images={k:'ghcr.io/example/'+k+'@sha256:'+'b'*64 for k in ['app','database','ingress']})
    with tempfile.TemporaryDirectory(prefix='account-compose-contract-') as temporary:
        root=Path(temporary)
        map_key=root/'amazon-map-key';map_key.write_text('v1.public.synthetic')
        map_settings={'environment':{'DNR_MAP_PROVIDER':'amazon','DNR_MAP_AMAZON_REGION':'us-east-2',
            'DNR_MAP_AMAZON_STYLE':'Standard','DNR_MAP_AMAZON_API_KEY_FILE':'/run/secrets/dnr_map_amazon_api_key'},
            'secret_file':str(map_key)}
        for name in ['docker-compose.yaml','docker-compose.smtp.yaml']:(root/name).symlink_to(ROOT/name)
        config['smtp_password_file']=str(root/'smtp');(root/'smtp').write_text('synthetic')
        def mock_run(command,**kwargs):
            if command[:3]==['docker','network','ls']:return SimpleNamespace(stdout='')
            if 'config' in command:
                # These are the only Docker operations allowed in this test.
                command=[str(ROOT/Path(arg).name) if arg in [str(root/'docker-compose.yaml'),str(root/'docker-compose.smtp.yaml')] else arg for arg in command]
                return render(command)
            if 'SELECT account_key' in kwargs.get('input',''):return SimpleNamespace(stdout='test-account')
            return SimpleNamespace(stdout='')
        with patch.object(production,'ROOT',root),patch.object(production,'assert_primary'), \
             patch.object(production,'primary_amazon_map',return_value=map_settings), \
             patch.object(production,'project_resources',return_value=[]), \
             patch.object(production,'verify_images'),patch.object(production,'ready_probe'), \
             patch.object(production,'control',return_value={'api_key':'x'*64,'name':'Synthetic'}), \
             patch.object(production,'run',side_effect=mock_run):
            production.provision(config,'test-account')
        directory=root/'var/accounts/test-account'
        metadata=json.loads((directory/'deployment.json').read_text())
        with patch.object(production,'verify_images'):
            production.saved_compose(directory,metadata['project'],metadata)
        assert production.saved_active_services(directory)==production.ACTIVE_SERVICES
        frozen=json.loads(render(['docker','compose','-p','review-member','-f',str(directory/'runtime.compose.json'),'config','--format','json']).stdout)
        complete=json.loads((directory/'runtime.compose.json').read_text())
        expected=production.account_environment(config,'test-account')
        for name in production.SERVICES:
            environment=complete['services'][name]['environment']
            for key,value in expected.items():
                if key=='DNR_TRUSTED_PROXY_IPS':assert set(value.split(','))<=set(environment[key].split(','))
                else:assert environment[key]==value,(name,key)
            assert environment['DNR_PLATFORM_API_KEY_FILE']=='/run/secrets/platform_api_key'
        assert complete['services']['backup']['environment']['MYSQL_USER']=='dnruser'
        assert complete['services']['geocoder']['environment']['MYSQL_USER']=='dnrgeocoder'
        for name in ['backup','downloads','db','migrator','account-control']:
            for volume in complete['services'][name].get('volumes',[]):
                if volume['type']=='bind' and volume['target'] not in ['/run/dnr/deployment','/backups']:
                    assert volume['source'].startswith(str(directory/'runtime-config')+'/')
        assert 'receipt-previews' in complete['services']
        assert list(frozen['services']['web']['networks'])==['backend']
        assert list(frozen['services']['backup']['networks'])==['backend']
        assert frozen['networks']['backend']['internal']
        assert not frozen['services']['ingress'].get('ports')
        assert not frozen['services']['account-control'].get('ports')
        assert frozen['services']['account-control']['extra_hosts']==['moed.beneliath.com=172.29.255.2']
        assert frozen['services']['web']['environment']['DNR_ACCOUNT_CONTROL_PROXY']=='http://account-control:8080'
        assert frozen['services']['web']['image']==config['images']['app']
        assert frozen['services']['web']['environment']['DNR_MAP_PROVIDER']=='amazon'
        assert frozen['secrets']['dnr_map_amazon_api_key']['file']==str(map_key)
        for name,service in frozen['services'].items():
            uses_map_key=any(s['source']=='dnr_map_amazon_api_key' for s in service.get('secrets',[]))
            assert uses_map_key==(name=='web'),name
        dispatch=frozen['services']['mail-dispatch']
        for setting in ['host','port','encryption','username']:
            assert dispatch['environment']['DNR_SMTP_'+setting.upper()]==str(config['smtp'][setting])
        assert dispatch['environment']['DNR_MAIL_FROM']==config['smtp']['from']
        assert frozen['secrets']['dnr_smtp_password']['file']==config['smtp_password_file']
        assert 'mail-ingest' not in frozen['services']
        assert 'dnr_imap_password' not in frozen.get('secrets',{})
        for name,service in frozen['services'].items():
            assert not any(k.startswith('DNR_IMAP_') for k in service.get('environment',{})),name
            uses_smtp_key=any(s['source']=='dnr_smtp_password' for s in service.get('secrets',[]))
            assert uses_smtp_key==(name=='mail-dispatch'),name
        assert frozen['services']['web']['environment']['DNR_INBOUND_ADDRESS']==config['smtp']['from']
        assert frozen['services']['web']['environment']['DNR_ACCOUNT_MAIL_ENABLED']=='1'
        config['primary_control_edge_ip']='172.29.255.4'
        primary=root/'primary';production.prepare_primary(config,primary)
        # Supply no real secrets and no active project name; Compose parsing only.
        envfile=root/'primary.env';envfile.write_text('DNR_INGRESS_IMAGE='+config['images']['ingress']+'\n')
        parsed=json.loads(render(['docker','compose','--env-file',str(envfile),'-p','review-primary',
            '-f',str(ROOT/'docker-compose.yaml'),'-f',str(ROOT/'docker-compose.ubuntu.yaml'),
            '-f',str(ROOT/'docker-compose.smtp.yaml'),
            '-f',str(ROOT/'docker-compose.mail.yaml'),'-f',str(ROOT/'docker-compose.cloudflare.yaml'),
            '-f',str(primary/'primary.compose.json'),'-f',str(primary/'notes-cache.compose.json'),
            'config','--format','json']).stdout)
        web=parsed['services']['web'];relay=parsed['services']['account-control']
        assert list(web['networks'])==['backend']
        assert config['primary_ingress_ip'] in web['environment']['DNR_TRUSTED_PROXY_IPS'].split(',')
        assert parsed['services']['ingress']['networks']['backend']['ipv4_address']==config['primary_ingress_ip']
        assert parsed['services']['notes-cache']['environment']['DNR_PUBLIC_BASE_URL']==config['origin']+'/a/'+config['primary_key']
        assert parsed['networks']['edge']['name']==config['edge_network']
        assert relay['image']==config['images']['ingress']
        assert relay['networks']['edge']['ipv4_address']==config['primary_control_edge_ip']
        print('PASS: real primary/member Compose parsing, full service profiles, per-service Account environment, saved lifecycle validation, frozen configuration, private networks, relay and proxy trust. No services started.')


if __name__=='__main__':main()
