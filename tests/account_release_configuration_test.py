"""Persistent Account configuration survives ordinary release invocations."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import account_production
import deploy_release_host


class AccountReleaseConfigurationTest(unittest.TestCase):
    def test_enabled_platform_cannot_fall_back_to_naked_configuration(self):
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ,{},clear=True):
            root=Path(temporary)
            self.assertIsNone(deploy_release_host.account_release_configuration(root,[]))
            with self.assertRaises(ValueError):
                deploy_release_host.account_release_configuration(root,['DNR_ACCOUNTS_ENABLED=1'])
            directory=root/'var/deployment/account-production';directory.mkdir(parents=True)
            (directory/'primary.compose.json').write_text('{}')
            with self.assertRaises(ValueError):deploy_release_host.account_release_configuration(root,[])
            (directory/'config.json').write_text('{"primary_key":"shalom-in-messiah"}')
            (directory/'control.conf').write_text('fixture')
            saved=deploy_release_host.account_release_configuration(root,['DNR_ACCOUNTS_ENABLED=1'])
            self.assertEqual(saved['config']['primary_key'],'shalom-in-messiah')

    def test_second_release_keeps_primary_and_optional_cache_overlays_last(self):
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary);(root/'scripts').mkdir();(root/'bin').mkdir()
            wrapper=root/'scripts/compose_with_provenance.sh'
            shutil.copyfile(ROOT/'scripts/compose_with_provenance.sh',wrapper)
            docker=root/'bin/docker'
            docker.write_text('#!'+sys.executable+'\nimport json,sys\nif "--environment" not in sys.argv: print(json.dumps(sys.argv[1:]))\n')
            docker.chmod(0o700)
            directory=root/'var/deployment/account-production'
            config=json.loads((ROOT/'deploy/account-production.example.json').read_text())
            config.update(commit='a'*40,images={k:'ghcr.io/example/'+k+'@sha256:'+'b'*64 for k in ['app','database','ingress']})
            account_production.prepare_primary(config,directory)
            token=root/'token';token.write_text('synthetic');zone=root/'zone';zone.write_text('synthetic')
            env={**os.environ,'PATH':str(root/'bin')+os.pathsep+os.environ['PATH'],
                 'DNR_BUILD_COMMIT':'a'*40,'DNR_BUILD_TIMESTAMP':'2026-10-08T00:00:00Z',
                 'DNR_GEOCODER_PROVIDER':'nominatim','DNR_MAP_PROVIDER':'openstreetmap',
                 'DNR_AI_COACH_SSH_KEY_FILE':str(root/'absent'),
                 'DNR_CLOUDFLARE_PURGE_TOKEN_FILE':str(token),'DNR_CLOUDFLARE_ZONE_ID_FILE':str(zone)}
            env.pop('DNR_ACCOUNT_PRIMARY_OVERLAY_FILE',None)
            for commit in ['a'*40,'c'*40]:
                env['DNR_BUILD_COMMIT']=commit
                result=subprocess.run(['sh',str(wrapper),'production-ubuntu-proton-mattermost','config','--services'],
                                      check=True,capture_output=True,text=True,env=env)
                args=json.loads(result.stdout.splitlines()[-1])
                files=[args[i+1] for i,arg in enumerate(args) if arg=='-f']
                self.assertEqual(files[-2:],[str(directory/'primary.compose.json'),str(directory/'notes-cache.compose.json')])
            env['DNR_ACCOUNT_PRIMARY_OVERLAY_FILE']=str(root/'missing')
            result=subprocess.run(['sh',str(wrapper),'production','config'],env=env,capture_output=True,text=True)
            self.assertNotEqual(result.returncode,0)


if __name__=='__main__':unittest.main()
