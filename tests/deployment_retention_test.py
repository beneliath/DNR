from datetime import datetime, timedelta, timezone
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
from deployment_retention import (
    archive_set, capture_set, eligible_images, image_use, inspect_backup, local_candidates,
    prune_images, retire_local, rollback_local_sets, roots,
)
from deployment_retention_storage import DatabaseArchive, MANIFEST, json_bytes, metadata

IMAGE = 'sha256:' + '3' * 64
OTHER = 'sha256:' + '4' * 64
REFERENCE = 'ghcr.io/beneliath/dnr/database@sha256:' + '8' * 64


class MemoryNAS:
    def __init__(self):
        self.files = {}
        self.directories = set()
        self.root_entries = {}
        self.corrupt = False

    def mkdir(self, path):
        self.directories.add(path)

    def exists(self, path):
        return path in self.files or path in self.directories

    def read(self, path):
        return self.files[path]

    def verify(self, path, expected):
        if metadata(self.files[path]) != expected:
            raise ValueError('NAS read-back mismatch')

    def put_file(self, source, dest, expected):
        self.files[dest] = b'changed' if self.corrupt else source.read_bytes()
        self.verify(dest, expected)

    def put_bytes(self, contents, dest):
        self.files[dest] = contents
        return metadata(contents)

    def publish_directory(self, stage, final):
        self.directories.add(final)
        for name in list(self.files):
            if name.startswith(stage + '/'):
                self.files[final + name[len(stage):]] = self.files.pop(name)

    def list(self):
        return self.root_entries


class DeploymentRetentionTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.project = Path(temporary.name).resolve() / 'moed'
        self.deploy = self.project / '.git/dnr-deploy'
        self.recovery = Path(temporary.name) / 'recovery'
        self.recovery.mkdir()
        (self.recovery / 'replicas.json').write_text('[]')
        self.directory = self.deploy / 'backups/20261030T120000Z-0123abcd'
        self.directory.mkdir(parents=True)
        self.dump = self.directory / 'database.sql.gz.dnrenc'
        self.dump.write_bytes(b'encrypted fixture')
        self.receipt = {
            'format': 'native-sql-gzip-secretstream-v2', 'restore_verified': True,
            'database_image': IMAGE, 'backup_path': str(self.dump),
            'backup_sha256': hashlib.sha256(self.dump.read_bytes()).hexdigest(),
            'verified_at': '2026-10-30T12:01:00+00:00',
        }
        self.write_receipt()
        self.nas = MemoryNAS()
        self.archive = DatabaseArchive(self.nas, 'retention')
        path = f'retention/images/docker-save/{IMAGE[7:]}.tar.gz'
        self.nas.files[path] = b'verified image'
        record = {'image_id': IMAGE, 'format': 'docker-save', 'objects': {path: metadata(self.nas.files[path])}}
        self.proof = {'marker': f'retention/image-records/{IMAGE[7:]}.json', 'record': record}
        self.nas.files[self.proof['marker']] = json_bytes(record)

    def write_receipt(self):
        (self.directory / 'receipt.json').write_bytes(json_bytes(self.receipt))

    def test_only_aged_verified_backups_outside_active_rollback_are_selected(self):
        directories = roots(self.project)
        cutoff = datetime(2026, 11, 21, tzinfo=timezone.utc) - timedelta(days=14)
        self.assertEqual(list(local_candidates(directories, cutoff, set())), [('primary', self.directory)])
        active = [{'outcome': 'failed', 'phase': 'backup-verified', 'backup': self.receipt}]
        blocked = rollback_local_sets(self.deploy, [(Path('active'), active[0])], directories)
        self.assertFalse(list(local_candidates(directories, cutoff, blocked)))
        self.receipt['verified_at'] = cutoff.isoformat()
        self.write_receipt()
        self.assertFalse(list(local_candidates(directories, cutoff, set())))

    def test_corrupt_nas_copy_leaves_the_local_set_available(self):
        _, _, files, _, _ = inspect_backup('primary', self.directory, [])
        self.nas.corrupt = True
        with self.assertRaisesRegex(ValueError, 'NAS read-back'):
            archive_set(self.nas, 'retention', 'primary', self.directory, files, IMAGE, self.proof)
        self.assertTrue(self.dump.is_file())
        self.assertFalse(any(path.endswith('/archive-record.json') for path in self.nas.files))

    def test_retirement_requires_both_proofs_and_resumes_partial_local_deletion(self):
        _, source, files, _, _ = inspect_backup('primary', self.directory, [])
        entry = archive_set(self.nas, 'retention', 'primary', self.directory, files, IMAGE, self.proof)
        image_path = next(iter(self.proof['record']['objects']))
        self.nas.files[image_path] = b'corrupt image'
        with self.assertRaisesRegex(ValueError, 'NAS read-back'):
            retire_local(self.directory, entry, self.nas, self.proof, self.archive, source)
        self.assertTrue(self.dump.exists())
        self.nas.files[image_path] = b'verified image'
        retiring = self.directory.with_name('.' + self.directory.name + '.retiring')
        self.directory.rename(retiring)
        (retiring / 'receipt.json').unlink()
        retire_local(self.directory, entry, self.nas, self.proof, self.archive)
        self.assertFalse(retiring.exists())

    def test_retained_snapshot_and_stopped_container_protect_images(self):
        rows = [{'Id': IMAGE, 'RepoDigests': [REFERENCE]}, {'Id': OTHER, 'RepoDigests': [REFERENCE]}]
        name = '20261101T120000Z-abcd1234-0123456789abcdef'
        self.nas.root_entries[name] = ('D', 0)
        snapshot = json_bytes({'database_image': IMAGE})
        self.nas.files[name + '/receipt.json'] = snapshot
        (self.recovery / 'replicas.json').write_bytes(json_bytes(
            [{'remote_directory': name, 'files': {'receipt.json': metadata(snapshot)}}]))
        used = image_use(self.project, self.recovery, self.nas, rows,
                         [{'Image': OTHER, 'State': {'Status': 'exited'}}])
        self.assertEqual(used, {IMAGE, OTHER})
        self.assertFalse(list(eligible_images(rows, used)))
        self.assertEqual(list(eligible_images(rows, {IMAGE})), [(OTHER, REFERENCE)])
        self.nas.files[name + '/receipt.json'] = json_bytes({'database_image': OTHER})
        with self.assertRaisesRegex(ValueError, 'failed verification'):
            image_use(self.project, self.recovery, self.nas, rows, [])

    def test_pruning_preserves_image_proof_for_pending_backup_retirement(self):
        state = {'images': {IMAGE: self.proof}, 'backups': {'pending': {'marker': {'image_id': IMAGE}}}}
        with patch('deployment_retention.docker_output') as docker:
            self.assertEqual(prune_images(self.project, self.recovery, state, self.nas, self.archive,
                                          self.recovery / 'journal.json'), (0, 0))
        docker.assert_not_called()
        self.assertIn(IMAGE, state['images'])

    def test_oci_archive_verification_requires_exact_config_and_complete_layer_inventory(self):
        config = b'{"architecture":"amd64","os":"linux"}'
        image = 'sha256:' + metadata(config)['sha256']
        cfg = {'digest': image, 'size': len(config)}
        layer = b'compressed fixture layer'
        part = {'digest': 'sha256:' + metadata(layer)['sha256'], 'size': len(layer)}
        raw = json_bytes({'mediaType': MANIFEST, 'config': cfg, 'layers': [part]})
        desc = {'digest': 'sha256:' + metadata(raw)['sha256'], 'size': len(raw)}
        prefix = 'retention/images/blobs/sha256/'
        objects = {}
        for data in (config, layer, raw):
            name = prefix + metadata(data)['sha256']
            objects[name] = self.nas.put_bytes(data, name)
        record = {'format': 'oci', 'image_id': image, 'manifest': desc, 'objects': objects}
        marker = f'retention/image-records/{image[7:]}.json'
        self.nas.put_bytes(json_bytes(record), marker)
        self.archive.verify({'marker': marker, 'record': record})
        del objects[prefix + part['digest'][7:]]
        self.nas.put_bytes(json_bytes(record), marker)
        with self.assertRaisesRegex(ValueError, 'inventory is incomplete'):
            self.archive.verify({'marker': marker, 'record': record})


if __name__ == '__main__':
    unittest.main()
