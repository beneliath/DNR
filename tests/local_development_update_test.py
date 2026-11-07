import hashlib
import io
import json
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'scripts'))
import update_local_development as update


class LocalDevelopmentUpdateTests(unittest.TestCase):
    def test_only_missing_migrations_are_pending(self):
        expected={'baseline.sql':'a','forward.sql':'b'}
        self.assertEqual(update.pending_migrations(expected,[['baseline.sql','a','applied']]),['forward.sql'])
        for rows in [[['baseline.sql','changed','applied']],[['baseline.sql','a','applying']],
                     [['baseline.sql','a','failed']],[['unknown.sql','x','applied']]]:
            with self.assertRaisesRegex(ValueError,'inconsistent'):
                update.pending_migrations(expected,rows)

    def test_manifest_requires_complete_unique_order(self):
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary); migrations=root/'migrations'; migrations.mkdir()
            (migrations/'baseline.sql').write_text('SELECT 1;')
            for order in ['','baseline.sql\nbaseline.sql\n','unknown.sql\n']:
                (migrations/'order.txt').write_text(order)
                with self.assertRaises(ValueError): update.manifest(root)
            (migrations/'order.txt').write_text('# canonical\nbaseline.sql\n')
            self.assertEqual(update.manifest(root),{'baseline.sql':hashlib.sha256(b'SELECT 1;').hexdigest()})

    def test_production_remote_and_different_source_deployments_are_refused(self):
        root=Path('/checkout')
        def container(url='http://localhost:8080/a/fixture',https='0',source='/host_mnt/checkout/src'):
            return {'Config':{'Env':['DNR_PUBLIC_BASE_URL='+url,'DNR_REQUIRE_HTTPS='+https]},
                    'Mounts':[{'Type':'bind','Destination':'/var/www/html','Source':source}]}
        self.assertTrue(update.local_source_web(container(),root))
        self.assertFalse(update.local_source_web(container(https='1'),root))
        self.assertFalse(update.local_source_web(container(url='https://production.example/a/fixture'),root))
        self.assertFalse(update.local_source_web(container(url='http://192.168.1.150/a/fixture'),root))
        self.assertFalse(update.local_source_web(container(source='/other/src'),root))

    def test_grant_source_and_live_permissions_both_invalidate_receipt(self):
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary); (root/'scripts').mkdir(); state=root/'state'; state.mkdir()
            (root/'scripts/configure_database_privileges.sh').write_text('canonical grants')
            source_hash=hashlib.sha256(b'canonical grants').hexdigest()
            receipt=state/'dnr.json'
            receipt.write_text(json.dumps({'grants_source_sha256':source_hash,'grants_sha256':'live'}))
            account={'project':'dnr','db':{'Id':'db'}}
            with patch.object(update,'ROOT',root),patch.object(update,'STATE',state),\
                 patch.object(update,'ledger',return_value=[['baseline.sql','a','applied']]),\
                 patch.object(update,'grant_fingerprint',return_value='live'):
                self.assertFalse(update.assess(account,{'baseline.sql':'a'})['refresh_grants'])
                (root/'scripts/configure_database_privileges.sh').write_text('new grants')
                self.assertTrue(update.assess(account,{'baseline.sql':'a'})['refresh_grants'])
                (root/'scripts/configure_database_privileges.sh').write_text('canonical grants')
                with patch.object(update,'grant_fingerprint',return_value='changed'):
                    self.assertTrue(update.assess(account,{'baseline.sql':'a'})['refresh_grants'])

    def test_unstarted_scratch_web_is_not_an_active_account(self):
        web={'Id':'web','Name':'/dnr-web-1','Config':{'Env':['DNR_PUBLIC_BASE_URL=http://localhost:8080','DNR_REQUIRE_HTTPS=0'],
            'Labels':{'com.docker.compose.project':'dnr','com.docker.compose.service':'web'}},
            'Mounts':[{'Type':'bind','Destination':'/var/www/html','Source':str(update.ROOT/'src')}],
            'State':{'Status':'running'},'NetworkSettings':{'Networks':{'backend':{}}}}
        scratch=dict(web,Id='scratch',Name='/dnr-scratch-preview',State={'Status':'created'},
            Config={'Env':['DNR_PUBLIC_BASE_URL=http://localhost:18086','DNR_REQUIRE_HTTPS=0'],
                    'Labels':web['Config']['Labels']})
        db={'Id':'db','Name':'/dnr-db-1','Config':{'Env':['MYSQL_DATABASE=dnr'],
            'Labels':{'com.docker.compose.project':'dnr','com.docker.compose.service':'db'}},
            'Mounts':[{'Type':'bind','Destination':target,'Source':str(source)} for target,source in [
                ('/opt/dnr/migrations',update.ROOT/'migrations'),('/opt/dnr/bin/migrate',update.ROOT/'scripts/migrate.sh'),
                ('/opt/dnr/bin/configure_database_privileges',update.ROOT/'scripts/configure_database_privileges.sh')]],
            'State':{'Status':'running'},'NetworkSettings':{'Networks':{'backend':{}}}}
        inventory = '\n'.join(json.dumps({'ID':name, 'Networks':network, 'Command':command}) for name,network,command in [
            ('web','backend','"apache2"'),('db','backend','"mysqld"'),('scratch','none','"apache2"'),
            ('backup-helper','none','"php /opt/dnr/bin/native_backup_crypto.php decrypt - -"')])
        with tempfile.TemporaryDirectory() as temporary,patch.object(update,'STATE',Path(temporary)),\
             patch.object(update,'inspect',side_effect=[[web],[web,db,scratch]]) as inspect,\
             patch.object(update.subprocess,'check_output',side_effect=[inventory,'network','[]']):
            self.assertEqual([a['project'] for a in update.discover('dnr')],['dnr'])
            self.assertEqual(inspect.call_args.args[0], ['web','db','scratch'])

    def run_update(self, backup_failure=None, changed_member=False):
        temporary=tempfile.TemporaryDirectory(); self.addCleanup(temporary.cleanup)
        root=Path(temporary.name); state=root/'var/deployment/local-development-state'
        archive=io.BytesIO()
        with tarfile.open(fileobj=archive,mode='w') as tar:
            version=tarfile.TarInfo('VERSION'); version.size=6; tar.addfile(version,io.BytesIO(b'2.0.0\n'))
        accounts=[{'project':name,'key':name,'db':{'Id':name+'-db','Image':'database-image'},
                   'web':{'Id':name+'-web','Image':'app-image','Config':{'Env':[]}},
                   'writers':[{'Id':name+'-web'}]} for name in ['primary','member']]
        events=[]
        def command(args,**kwargs):
            events.append(('command',args))
        def backup(kind, db, **kwargs):
            events.append((kind,db)); self.assertTrue(callable(kwargs.get('restore_verifier')))
            if kind == backup_failure: raise ValueError('backup verification failed')
            return {'restore_verified':True,'database_state':db}
        pending={'pending':['forward.sql'],'refresh_grants':True}
        current={'pending':[],'refresh_grants':False,'grants_source_sha256':'source','grants_sha256':'actual'}
        with patch.object(update,'ROOT',root),patch.object(update,'STATE',state),patch.object(update,'BACKUPS',root/'backups'),\
             patch.object(update,'assess',side_effect=[pending.copy(),pending.copy(),current.copy(),current.copy()]),\
             patch.object(update,'run',side_effect=command),patch.object(update,'can_snapshot_online',return_value=True),\
             patch.object(update,'create_online_backup',side_effect=lambda db,*a,**kw:backup('online',db,**kw)),\
             patch.object(update,'create_verified_backup',side_effect=lambda db,*a,**kw:backup('paused',db,**kw)),\
             patch.object(update,'fingerprint',side_effect=lambda db:'new-state' if changed_member and db=='member-db' else db),\
             patch.object(update,'migrate_database',side_effect=lambda a:events.append(('migrate',a['db']['Id']))),\
             patch.object(update.subprocess,'check_output',return_value=archive.getvalue()),\
             patch.object(update,'ledger',return_value=[['forward.sql','checksum','applied']]),\
             patch.object(update,'wait_for_health'):
            if backup_failure:
                with self.assertRaisesRegex(ValueError,'backup verification'):
                    update.synchronize(accounts,{'forward.sql':'checksum'},root/'password')
            else: update.synchronize(accounts,{'forward.sql':'checksum'},root/'password')
        return events,json.loads((state/'last-update.json').read_text())

    def test_all_backups_finish_before_any_live_migration(self):
        events,receipt=self.run_update()
        backups=[i for i,event in enumerate(events) if event[0]=='online']
        pause=next(i for i,event in enumerate(events) if event[0]=='command' and event[1][:2]==['docker','stop'])
        migrations=[i for i,event in enumerate(events) if event[0]=='migrate']
        self.assertEqual(len(backups),2); self.assertEqual(len(migrations),2)
        self.assertLess(max(backups),pause); self.assertLess(pause,min(migrations))
        self.assertNotIn('paused',[event[0] for event in events]); self.assertEqual(receipt['stage'],'complete')

    def test_online_backup_failure_leaves_writers_available(self):
        events,receipt=self.run_update(backup_failure='online')
        self.assertFalse(any(event[0] in ('command','migrate') for event in events))
        self.assertEqual(receipt['stage'],'failed')
        self.assertFalse(receipt['writers_paused'])

    def test_late_writes_require_fresh_qualified_backup_before_any_live_migration(self):
        events,receipt=self.run_update(changed_member=True)
        self.assertEqual([event for event in events if event[0]=='paused'],[('paused','member-db')])
        pause=next(i for i,event in enumerate(events) if event[0]=='command' and event[1][:2]==['docker','stop'])
        recapture=events.index(('paused','member-db'))
        self.assertLess(pause,recapture); self.assertLess(recapture,events.index(('migrate','primary-db')))
        self.assertEqual(receipt['stage'],'complete')

    def test_paused_backup_failure_restores_writers_without_live_ddl(self):
        events,receipt=self.run_update(backup_failure='paused',changed_member=True)
        self.assertFalse(any(event[0]=='migrate' for event in events))
        self.assertTrue(any(event[0]=='command' and event[1][:2]==['docker','start'] for event in events))
        self.assertEqual(receipt['stage'],'failed')
        self.assertFalse(receipt['writers_paused'])

    def test_migration_streams_current_scripts_instead_of_individual_file_mounts(self):
        with patch.object(update,'run') as command:
            update.migrate_database({'db':{'Id':'owned-db'}})
        calls=command.call_args_list
        self.assertEqual(len(calls),3)
        self.assertEqual(calls[0].kwargs['input'],(update.ROOT/'scripts/configure_database_privileges.sh').read_text())
        self.assertEqual(calls[1].kwargs['input'],(update.ROOT/'scripts/migrate.sh').read_text())
        self.assertIn('DNR_MIGRATION_DIRECTORY=/opt/dnr/migrations',calls[1].args[0])
        self.assertEqual(calls[2].args[0][0:5],['docker','exec','owned-db','rm','-f'])


if __name__=='__main__': unittest.main()
