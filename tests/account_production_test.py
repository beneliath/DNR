import copy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'scripts'))
import account_production as production


def config():
    value=json.loads((production.ROOT/'deploy/account-production.example.json').read_text())
    value['commit']='a'*40
    value['images']={k:'ghcr.io/example/'+k+'@sha256:'+'b'*64 for k in ['app','database','ingress']}
    return value


class ProductionAccountTest(unittest.TestCase):
    def test_refuse_unqualified_releases_and_plaintext(self):
        for field,value in [('origin','http://moed.example.com'),('origin','https://moed.example.com/a/primary'),('primary_key','../primary'),('commit','main')]:
            candidate=config();candidate[field]=value
            with self.assertRaises(ValueError):production.validate_config(candidate)
        candidate=config();candidate['images']['app']='dnr-app:latest'
        with self.assertRaises(ValueError):production.validate_config(candidate)
        production.validate_config(config())

    def test_member_path_boundary_and_no_root_alias(self):
        labels=production.labels(config(),'test-account')
        rule=labels['traefik.http.routers.account-test-account.rule']
        self.assertIn('Path(`/a/test-account`)',rule)
        self.assertIn('PathPrefix(`/a/test-account/`)',rule)
        self.assertNotIn('PathPrefix(`/a/test-account`)',rule)
        self.assertEqual(labels['traefik.http.middlewares.account-test-account-strip.stripprefix.prefixes'],'/a/test-account')
        self.assertEqual(labels['traefik.http.routers.account-test-account.tls'],'true')
        self.assertIn('$${1}',labels['traefik.http.middlewares.account-test-account-slash.redirectregex.replacement'])

    def test_primary_common_and_legacy_routes_are_explicit(self):
        overlay=production.primary_overlay(config());labels=overlay['services']['ingress']['labels']
        self.assertIn('/a/shalom-in-messiah/',labels['traefik.http.routers.dnr.rule'])
        public=labels['traefik.http.routers.account-shalom-in-messiah-public.rule']
        for path in ['/calendar.php','/surls/','/accept_invitation.php','/verify_email.php','/recover_password.php','/login.php','/health.php','/api/v1/mattermost.php']:
            self.assertIn(path,public)
        for path in ['/dashboard.php','/users.php','/database_maintenance.php']:
            self.assertNotIn(path,public)
        self.assertEqual(labels['traefik.http.middlewares.account-shalom-in-messiah-entry.redirectregex.replacement'],config()['origin']+'/login.php')

    def test_default_is_a_plan_without_runtime_calls(self):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'config.json';path.write_text(json.dumps(config()))
            with patch.object(sys,'argv',['account_production.py',str(path)]),patch.object(production,'run',side_effect=AssertionError('Plan touched Docker')),patch('builtins.print') as out:
                production.main()
                self.assertIn('primary_overlay',json.loads(out.call_args.args[0]))

    def test_primary_lifecycle_always_protected(self):
        with patch.object(production,'assert_primary',side_effect=AssertionError('Touched deployment')):
            for state in ['archiving','restoring','deleting']:
                with self.assertRaises(ValueError):production.lifecycle(config(),{'account_key':'shalom-in-messiah','state':state})

    def test_failed_identity_probe_cannot_mark_ready(self):
        with patch.object(production,'control',side_effect=RuntimeError('identity mismatch')) as control:
            with self.assertRaises(RuntimeError):production.ready_probe(config(),'test-account')
            self.assertEqual(control.call_args.args[1],'probe')

    def test_health_and_worker_resume_configuration(self):
        environment=production.account_environment(config(),'test-account')
        self.assertEqual(environment['DNR_REQUIRE_HTTPS'],'1')
        self.assertTrue(environment['DNR_PRIMARY_INTERNAL_URL'].startswith('https://'))
        self.assertEqual(environment['DNR_ACCOUNT_MODE'],'member')
        self.assertNotIn('db',production.ACTIVE_SERVICES)

    def test_primary_trust_and_private_control_transport(self):
        value=config(); overlay=production.primary_overlay(value)
        env=overlay['services']['web']['environment']
        self.assertEqual(env['DNR_TRUSTED_PROXY_IPS'].split(','),[value['traefik_ip'],value['primary_ingress_ip']])
        self.assertEqual(env['DNR_ACCOUNT_CONTROL_PROXY'],'http://account-control:8080')
        relay=overlay['services']['account-control']
        self.assertEqual(relay['networks'],['backend','edge'])
        self.assertNotIn('ports',relay); self.assertNotIn('secrets',relay)
        self.assertEqual(relay['extra_hosts'],{'moed.beneliath.com':value['traefik_ip']})
        for service in ['web','backup','downloads']:
            self.assertNotIn('networks',overlay['services'][service])
        invalid=config(); invalid['primary_ingress_ip']='10.1.1.1'
        with self.assertRaises(ValueError):production.validate_config(invalid)

    def test_preparation_is_persistent_and_does_not_apply(self):
        with tempfile.TemporaryDirectory() as folder, patch.object(production,'run',side_effect=AssertionError('Touched Docker')):
            directory=Path(folder)
            production.prepare_primary(config(),directory)
            initial=json.loads((directory/'primary.compose.json').read_text())
            next_config=config();next_config['commit']='c'*40
            production.prepare_primary(next_config,directory)
            self.assertEqual(initial,json.loads((directory/'primary.compose.json').read_text()))
            self.assertEqual(json.loads((directory/'config.json').read_text())['commit'],'c'*40)
            self.assertIn('Require method CONNECT',(directory/'control.conf').read_text())

    def test_primary_requires_qualified_cookie_and_script_policy(self):
        value=config();policy='/etc/apache2/conf-enabled/zy-dnr-account-security.conf'
        primary={'Config':{'Image':value['images']['app'],
                 'Env':[k+'='+v for k,v in production.account_environment(value,value['primary_key'],True).items()],
                 'Labels':{'com.docker.compose.project':'dnr'}}}
        ingress={'Config':{'Image':value['images']['ingress'],'Labels':production.labels(value,value['primary_key'],True)},
                 'Mounts':[{'Destination':policy,'RW':False}], 'NetworkSettings':{'Ports':{}}}
        relay={'Config':{'Image':value['images']['ingress']},'State':{'Health':{'Status':'healthy'}}}
        expected=production.ingress_security_configuration(value['primary_key'],value['origin']+'/a/'+value['primary_key'],True)
        checksum=production.hashlib.sha256(expected.encode()).hexdigest()
        for variant in ['valid','writable','missing','wrong-image','changed']:
            with self.subTest(variant=variant):
                candidate=copy.deepcopy(ingress)
                if variant=='writable':candidate['Mounts'][0]['RW']=True
                if variant=='missing':candidate['Mounts']=[]
                if variant=='wrong-image':candidate['Config']['Image']='unqualified'
                output='0'*64 if variant=='changed' else checksum
                with patch.object(production,'inspect',side_effect=[primary,candidate,relay]), \
                     patch.object(production,'run',return_value=SimpleNamespace(stdout=output+'  '+policy)):
                    if variant=='valid':self.assertEqual(production.assert_primary(value),primary)
                    else:
                        with self.assertRaises(ValueError):production.assert_primary(value)

    def test_restore_uses_frozen_runtime_without_migrations_after_primary_upgrade(self):
        value=config()
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);directory=root/'var/accounts/test-account';directory.mkdir(parents=True)
            old={'services':{s:{'image':'old-'+s} for s in ['web','db','ingress']},'x-moed-active-services':['web','ingress'], 'x-moed-frozen-configuration':{}}
            (directory/'runtime.compose.json').write_text(json.dumps(old))
            metadata={'mode':'production','project':'moed-account-test-account','commit':'1'*40,
                      'runtime_sha256':production.sha(directory/'runtime.compose.json')}
            (directory/'deployment.json').write_text(json.dumps(metadata))
            def control(*args):
                return [{'account_key':'test-account','state':'restoring'}] if args[1]=='lifecycle' else None
            with patch.object(production,'ROOT',root),patch.object(production,'assert_primary'), \
                 patch.object(production,'verify_images') as verify,patch.object(production,'ready_probe'), \
                 patch.object(production,'control',side_effect=control), \
                 patch.object(production,'run',return_value=SimpleNamespace(stdout='')) as run:
                production.lifecycle(value,{'account_key':'test-account','state':'restoring'})
            self.assertEqual(verify.call_args.args[0]['commit'],'1'*40)
            starts=[call.args[0] for call in run.call_args_list if 'up' in call.args[0]]
            self.assertEqual(len(starts),2)
            self.assertEqual(starts[-1][-2:],['web','ingress'])
            for command in starts:
                self.assertIn('--no-deps',command)
                self.assertIn(str(directory/'runtime.compose.json'),command)
                self.assertNotIn(str(root/'docker-compose.yaml'),command)
                self.assertNotIn('migrator',command)
            (directory/'runtime.compose.json').write_text('{}')
            with self.assertRaises(ValueError):production.saved_compose(directory,metadata['project'],metadata)

    def test_configuration_survives_checkout_change_and_detects_tampering(self):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);member=root/'member';member.mkdir()
            checkout=root/'checkout';checkout.mkdir()
            (checkout/'apache.conf').write_text('qualified configuration')
            (checkout/'migrations').mkdir();(checkout/'migrations/old.sql').write_text('qualified schema')
            document={'services':{'backup':{'build':'.','volumes':[
                {'type':'bind','source':str(checkout/'apache.conf'),'target':'/etc/apache.conf','read_only':True},
                {'type':'bind','source':str(checkout/'migrations'),'target':'/opt/migrations','read_only':True},
                {'type':'bind','source':str(checkout/'notice'),'target':'/run/dnr/deployment','read_only':True}]}}}
            production.freeze_configuration(member,document)
            (checkout/'apache.conf').write_text('later release')
            (checkout/'migrations/new.sql').write_text('later migration')
            production.verify_frozen_configuration(member,document)
            frozen=Path(document['services']['backup']['volumes'][0]['source'])
            self.assertEqual(frozen.read_text(),'qualified configuration')
            self.assertNotIn('build',document['services']['backup'])
            frozen.write_text('tampered')
            with self.assertRaises(ValueError):production.verify_frozen_configuration(member,document)

    def test_missing_profile_service_fails_before_saving_runtime(self):
        with tempfile.TemporaryDirectory() as folder:
            directory=Path(folder)
            with patch.object(production,'run',return_value=SimpleNamespace(stdout='{"services":{"web":{},"ingress":{}}}')):
                with self.assertRaises(ValueError):production.save_runtime(directory,'synthetic',{})
            self.assertFalse((directory/'runtime.compose.json').exists())

    def test_resume_provisioning_keeps_old_runtime_after_primary_upgrade(self):
        value=config()
        for checksum_saved in [True,False]:
            with self.subTest(checksum_saved=checksum_saved), tempfile.TemporaryDirectory() as folder:
                root=Path(folder);directory=root/'var/accounts/test-account';directory.mkdir(parents=True)
                document={'services':{s:{'image':'old-'+s} for s in ['web','db','ingress','migrator']},
                          'x-moed-active-services':['web','ingress'],'x-moed-frozen-configuration':{}}
                document['services']['web']['environment']={'DNR_PUBLIC_BASE_URL':config()['origin']+'/a/test-account'}
                runtime=directory/'runtime.compose.json';runtime.write_text(json.dumps(document))
                state={'mode':'production','account_key':'test-account','project':'moed-account-test-account','commit':'1'*40}
                if checksum_saved:state['runtime_sha256']=production.sha(runtime)
                (directory/'deployment.json').write_text(json.dumps(state))
                def run(command,**kwargs):
                    return SimpleNamespace(stdout='test-account' if 'SELECT account_key' in kwargs.get('input','') else '')
                with patch.object(production,'ROOT',root),patch.object(production,'assert_primary') as primary, \
                     patch.object(production,'verify_images') as verify,patch.object(production,'ready_probe'), \
                     patch.object(production,'project_resources',return_value=[]), \
                     patch.object(production,'prepare_member_runtime',side_effect=AssertionError('Rebuilt saved release')), \
                     patch.object(production,'control',return_value={'name':'Synthetic'}) as control, \
                     patch.object(production,'run',side_effect=run) as commands:
                    production.provision(value,'test-account')
                primary.assert_called_once_with(value)
                self.assertTrue(all(c.args[0]['commit']=='1'*40 for c in verify.call_args_list))
                for call in commands.call_args_list:
                    if call.args[0][:2]==['docker','compose']:
                        self.assertIn(str(runtime),call.args[0])
                self.assertEqual(json.loads((directory/'deployment.json').read_text())['commit'],'1'*40)
                self.assertEqual(control.call_args.args[1:3],('ready','test-account'))

    def test_pre_runtime_retry_requires_no_existing_resources_and_preserves_secrets(self):
        value=config()
        for has_resources in [False,True]:
            with self.subTest(has_resources=has_resources), tempfile.TemporaryDirectory() as folder:
                root=Path(folder);directory=root/'var/accounts/test-account';directory.mkdir(parents=True)
                state={'mode':'production','account_key':'test-account','project':'moed-account-test-account','commit':'1'*40}
                (directory/'deployment.json').write_text(json.dumps(state));(directory/'secret').write_text('preserve-me')
                def prepare(config,key,account,path,state):
                    self.assertEqual(state['commit'],config['commit'])
                    self.assertEqual((path/'secret').read_text(),'preserve-me')
                    raise RuntimeError('Verified fresh preparation entry')
                with patch.object(production,'ROOT',root),patch.object(production,'assert_primary'), \
                     patch.object(production,'verify_images'),patch.object(production,'control',return_value={'name':'Synthetic'}), \
                     patch.object(production,'project_resources',return_value=['existing'] if has_resources else []), \
                     patch.object(production,'prepare_member_runtime',side_effect=prepare) as prepared:
                    with self.assertRaisesRegex((ValueError if has_resources else RuntimeError),
                                                'require their saved runtime' if has_resources else 'Verified fresh preparation'):
                        production.provision(value,'test-account')
                self.assertEqual(prepared.call_count,0 if has_resources else 1)


if __name__=='__main__':unittest.main()
