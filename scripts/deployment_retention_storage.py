"""Verified SMB archives for expired MOED deployment backups and their database images."""

import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tarfile
import tempfile
from urllib import error, parse, request

from replicate_verified_backup import SMB, sha

IMAGE_ID = re.compile(r'sha256:[0-9a-f]{64}')
PACKAGE = re.compile(
    r'(ghcr\.io/beneliath/dnr/database|(?:docker\.io/)?(?:library/)?mysql)'
    r'(?::[0-9A-Za-z._-]+)?@(sha256:[0-9a-f]{64})'
)
PRIVATE_PACKAGE = re.compile(r'ghcr\.io/beneliath/dnr/database@sha256:[0-9a-f]{64}')
FILE_COMPONENT = re.compile(r'[0-9A-Za-z._-]+')
MISSING = (b'NT_STATUS_OBJECT_NAME_NOT_FOUND', b'NT_STATUS_OBJECT_PATH_NOT_FOUND',
           b'NT_STATUS_NO_SUCH_FILE')
INDEX = 'application/vnd.oci.image.index.v1+json'
MANIFEST = 'application/vnd.oci.image.manifest.v1+json'
DOCKER_INDEX = 'application/vnd.docker.distribution.manifest.list.v2+json'
DOCKER_MANIFEST = 'application/vnd.docker.distribution.manifest.v2+json'


def checked_path(path):
    parts = str(path).removeprefix('/').split('/')
    if any(not FILE_COMPONENT.fullmatch(part) or part in ('.', '..') for part in parts):
        raise ValueError(f'Unexpected archive path: {path}')
    return str(path)


def json_bytes(data):
    return (json.dumps(data, indent=2, sort_keys=True) + '\n').encode()


def metadata(contents):
    return {'sha256': hashlib.sha256(contents).hexdigest(), 'bytes': len(contents)}


def file_metadata(path):
    return {'sha256': sha(path), 'bytes': path.stat().st_size}


def image_receipt_metadata(proof):
    return proof.get('marker_hash') or metadata(json_bytes(proof['record']))


class ArchiveSMB(SMB):
    """Keep archive publication on encrypted SMB3 and verify bytes by reading them back."""

    def __init__(self, share, credentials):
        super().__init__(share, credentials)
        self.command += ['--timeout=60']
        self.verified = {}
        self.directories = set()

    def execute(self, command, *, missing_ok=False, fd=None):
        result = subprocess.run(self.command + ['-c', command], stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=900,
                                pass_fds=() if fd is None else (fd,))
        output = result.stdout + result.stderr
        if missing_ok and any(code in output for code in MISSING):
            return None
        if result.returncode or b'NT_STATUS' in output:
            raise RuntimeError(f'Encrypted SMB command failed: {command}')
        return result.stdout

    def read(self, path, *, missing_ok=False):
        # A successful binary get may contain arbitrary bytes, including Samba error text.
        result = subprocess.run(self.command + ['-c', 'get ' + checked_path(path) + ' -'],
                                capture_output=True, timeout=90)
        if not result.returncode:
            return result.stdout
        if missing_ok and any(code in result.stdout + result.stderr for code in MISSING):
            return None
        raise RuntimeError(f'NAS metadata read failed: {path}')

    def mkdir(self, path):
        if path in self.directories:
            return
        path = checked_path(path)
        parent, _, _ = path.rpartition('/')
        if parent:
            self.mkdir(parent)
        try:
            self.execute('mkdir ' + path)
        except RuntimeError:
            self.execute('cd ' + path)
        self.directories.add(path)

    def list(self, path=None):
        command = 'ls' if path is None else 'ls ' + checked_path(path) + '/*'
        output = self.execute(command).decode()
        listing = re.findall(r'^\s*([0-9A-Za-z._-]+)[ \t]+([ADHRS]+)[ \t]+(\d+)[ \t]+', output, re.M)
        return {name: (attributes, int(length)) for name, attributes, length in listing
                if name not in ('.', '..')}

    def exists(self, path):
        return self.execute('allinfo ' + checked_path(path), missing_ok=True) is not None

    def verify(self, path, expected, *, fresh=False):
        if not fresh and self.verified.get(path) == expected:
            return
        command = 'get ' + checked_path(path) + ' -'
        with subprocess.Popen(self.command + ['-c', command], stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL) as process:
            actual = hashlib.sha256()
            size = 0
            while block := process.stdout.read(1024 * 1024):
                actual.update(block)
                size += len(block)
            if process.wait():
                raise RuntimeError(f'NAS read-back failed: {path}')
        if {'sha256': actual.hexdigest(), 'bytes': size} != expected:
            raise ValueError(f'NAS read-back checksum or length mismatch: {path}')
        self.verified[path] = expected

    def put_file(self, local, remote, expected):
        if self.exists(remote):
            # An existing published object must match. Preserve discrepant NAS artifacts for review.
            self.verify(remote, expected)
            return
        self.mkdir(remote.rpartition('/')[0])
        pending = checked_path(remote + '.pending')
        self.execute(f'put {checked_path(local)} {pending}')
        self.finish_upload(pending, remote, expected)

    def put_stream(self, handle, remote, expected):
        if self.exists(remote):
            self.verify(remote, expected)
            return
        self.mkdir(remote.rpartition('/')[0])
        handle.seek(0)
        fd = handle.fileno()
        pending = checked_path(remote + '.pending')
        self.execute(f'put /proc/self/fd/{fd} {pending}', fd=fd)
        self.finish_upload(pending, remote, expected)

    def finish_upload(self, pending, remote, expected):
        self.verify(pending, expected, fresh=True)
        self.execute(f'rename {pending} {checked_path(remote)}')
        self.verified[remote] = self.verified.pop(pending)

    def put_bytes(self, contents, remote):
        expected = metadata(contents)
        with tempfile.TemporaryFile() as handle:
            handle.write(contents)
            self.put_stream(handle, remote, expected)
        return expected

    def publish_directory(self, stage, final):
        self.execute(f'rename {checked_path(stage)} {checked_path(final)}')
        self.verified.update({final + path[len(stage):]: result
                              for path, result in list(self.verified.items())
                              if path.startswith(stage + '/')})


class Registry:
    """Read pinned amd64 image manifests and content from the deployment registries."""

    def __init__(self):
        self.tokens = {}

    def get(self, host, repo, part, digest):
        if not IMAGE_ID.fullmatch(digest):
            raise ValueError('Invalid registry object digest')
        key = (host, repo)
        for attempt in range(2):
            if key not in self.tokens:
                realm = 'https://ghcr.io/token' if host == 'ghcr.io' else 'https://auth.docker.io/token'
                service = 'ghcr.io' if host == 'ghcr.io' else 'registry.docker.io'
                query = parse.urlencode({'service': service, 'scope': f'repository:{repo}:pull'})
                with request.urlopen(f'{realm}?{query}', timeout=60) as reply:
                    self.tokens[key] = json.load(reply)['token']
            req = request.Request(f'https://{host}/v2/{repo}/{part}/{digest}', headers={
                'Accept': ', '.join((MANIFEST, DOCKER_MANIFEST, INDEX, DOCKER_INDEX)),
            })
            # The registry may redirect blobs to a CDN. Keep the token on the original request.
            req.add_unredirected_header('Authorization', 'Bearer ' + self.tokens[key])
            try:
                return request.urlopen(req, timeout=60)
            except error.HTTPError as exc:
                if exc.code != 401 or attempt:
                    raise
                self.tokens.pop(key)

    def manifest(self, host, repo, digest):
        with self.get(host, repo, 'manifests', digest) as reply:
            raw = reply.read(8 * 2**20)
        if metadata(raw)['sha256'] != digest.removeprefix('sha256:'):
            raise ValueError('Registry manifest checksum mismatch')
        return raw, json.loads(raw)

    def resolve(self, reference, image):
        match = PACKAGE.fullmatch(reference)
        if not match:
            raise ValueError('Missing pinned database registry reference')
        package, digest = match.groups()
        host, repo = ('ghcr.io', 'beneliath/dnr/database') if package.startswith('ghcr.io/') else (
            'registry-1.docker.io', 'library/mysql')
        raw, manifest = self.manifest(host, repo, digest)
        if manifest.get('mediaType') in (INDEX, DOCKER_INDEX):
            for child in manifest['manifests']:
                platform = child.get('platform') or {}
                if (platform.get('os'), platform.get('architecture')) != ('linux', 'amd64'):
                    continue
                content, candidate = self.manifest(host, repo, child['digest'])
                if (candidate.get('config') or {}).get('digest') == image:
                    if len(content) != child['size']:
                        raise ValueError('Registry image manifest size mismatch')
                    raw, manifest = content, candidate
                    break
        if manifest.get('mediaType') not in (MANIFEST, DOCKER_MANIFEST) or manifest['config']['digest'] != image:
            raise ValueError('Registry image config does not match the backup receipt')
        return host, repo, raw, manifest


def json_object(nas, path):
    raw = nas.read(path)
    if len(raw) > 32 * 2**20:
        raise ValueError(f'Unexpected archive metadata size: {path}')
    return json.loads(raw)


class DatabaseArchive:
    def __init__(self, nas, root):
        self.nas = nas
        self.root = root
        self.registry = Registry()

    def archive(self, image, refs, embedded=None):
        if not IMAGE_ID.fullmatch(image):
            raise ValueError('Invalid database image ID')
        marker = f'{self.root}/image-records/{image[7:]}.json'
        if self.nas.exists(marker):
            proof = self.read_proof(marker, image)
            self.verify(proof)
            return proof

        for reference in sorted(refs):
            try:
                source = self.registry.resolve(reference, image)
            except (OSError, ValueError, KeyError) as exc:
                print(f'Pinned image source did not resolve for {image[:19]}: {type(exc).__name__}', flush=True)
                continue
            data = self.copy_oci(image, reference, *source)
            break
        else:
            data = self.copy_docker(image, embedded)
        proof = {'marker': marker, 'record': data}
        self.nas.put_bytes(json_bytes(data), marker)
        self.verify(proof)
        return proof

    def read_proof(self, marker, image):
        value = json_object(self.nas, marker)
        if value.get('image_id') != image or not value.get('objects'):
            raise ValueError('NAS image receipt does not match the expected image')
        return {'marker': marker, 'record': value}

    def verify(self, proof):
        self.nas.verify(proof['marker'], image_receipt_metadata(proof))
        record = proof['record']
        image = record['image_id']
        if not IMAGE_ID.fullmatch(image) or not record['objects']:
            raise ValueError('Invalid image archive proof')
        if record['format'] == 'oci':
            descriptor = record['manifest']
            prefix, _, _ = proof['marker'].rpartition('/image-records/')
            if proof.get('marker_hash'):
                prefix = proof['marker'].rpartition('/')[0]
            prefix += '/images/blobs/sha256/'
            manifest_id = descriptor['digest']
            if not IMAGE_ID.fullmatch(manifest_id):
                raise ValueError('Invalid archived database image manifest digest')
            manifest_path = prefix + manifest_id[7:]
            raw = self.nas.read(manifest_path)
            manifest = json.loads(raw)
            if (metadata(raw) != {'sha256': manifest_id[7:], 'bytes': descriptor['size']}
                    or manifest['config']['digest'] != image):
                raise ValueError('Archived database image manifest does not match the receipt')
            objects = {manifest_path: metadata(raw)}
            for item in [manifest['config'], *manifest['layers']]:
                if not IMAGE_ID.fullmatch(item['digest']):
                    raise ValueError('Invalid archived database image object digest')
                objects[prefix + item['digest'][7:]] = {'sha256': item['digest'][7:], 'bytes': item['size']}
            if objects != record['objects']:
                raise ValueError('Archived database image object inventory is incomplete')
        elif record['format'] != 'docker-save' or len(record['objects']) != 1:
            raise ValueError('Unsupported database image archive proof')
        for path, expected in record['objects'].items():
            self.nas.verify(path, expected)

    def copy_oci(self, image, reference, host, repo, raw, manifest):
        prefix = self.root + '/images/blobs/sha256/'
        archive_manifest = metadata(raw)
        manifest_path = prefix + archive_manifest['sha256']
        objects = {manifest_path: self.nas.put_bytes(raw, manifest_path)}
        for descriptor in [manifest['config'], *manifest['layers']]:
            digest = descriptor['digest']
            if not IMAGE_ID.fullmatch(digest) or type(descriptor['size']) is not int or descriptor['size'] < 0:
                raise ValueError('Invalid registry image object')
            expected = {'sha256': digest[7:], 'bytes': descriptor['size']}
            dest = prefix + digest[7:]
            if self.nas.exists(dest):
                self.nas.verify(dest, expected)
            else:
                with tempfile.TemporaryFile() as handle:
                    with self.registry.get(host, repo, 'blobs', digest) as source:
                        shutil.copyfileobj(source, handle)
                    handle.seek(0)
                    if handle.seek(0, os.SEEK_END) != expected['bytes']:
                        raise ValueError('Registry image blob size mismatch')
                    handle.seek(0)
                    if hashlib.file_digest(handle, 'sha256').hexdigest() != expected['sha256']:
                        raise ValueError('Registry image blob checksum mismatch')
                    self.nas.put_stream(handle, dest, expected)
            objects[dest] = expected
        config = json_object(self.nas, prefix + image[7:])
        if (config.get('os'), config.get('architecture')) != ('linux', 'amd64'):
            raise ValueError('Database image config has an unexpected platform')
        if len(config['rootfs']['diff_ids']) != len(manifest['layers']):
            raise ValueError('Database image config and manifest disagree on layer count')
        descriptor = {'mediaType': manifest['mediaType'], 'digest': 'sha256:' + archive_manifest['sha256'],
                      'size': archive_manifest['bytes'],
                      'annotations': {'org.opencontainers.image.ref.name': 'db-' + image[7:]}}
        return {'image_id': image, 'source_reference': reference, 'format': 'oci',
                'manifest': descriptor, 'objects': objects}

    def copy_docker(self, image, embedded):
        if embedded is not None:
            return self.upload_docker(image, embedded)
        # An exact local image can be saved without pulling, tagging, or running anything on s1.
        with tempfile.TemporaryDirectory(prefix='moed-image-') as working:
            path = Path(working) / 'image.tar.gz'
            with path.open('wb') as file, gzip.GzipFile(filename='', fileobj=file, mode='wb',
                                                       compresslevel=1, mtime=0) as output:
                with subprocess.Popen(['docker', 'image', 'save', image], stdout=subprocess.PIPE) as process:
                    shutil.copyfileobj(process.stdout, output)
                    if process.wait():
                        raise RuntimeError(f'Exact database image unavailable: {image}')
            return self.upload_docker(image, path)

    def upload_docker(self, image, local):
        with tarfile.open(local, 'r:gz') as archive:
            for item in json.load(archive.extractfile('manifest.json')):
                with archive.extractfile(item['Config']) as config:
                    if hashlib.file_digest(config, 'sha256').hexdigest() == image[7:]:
                        if not all(archive.getmember(layer).isfile() for layer in item['Layers']):
                            raise ValueError('Docker image archive is missing a layer')
                        break
            else:
                raise ValueError('Docker image archive does not contain the exact database image')
        expected = file_metadata(local)
        path = f'{self.root}/images/docker-save/{image[7:]}-{expected["sha256"]}.tar.gz'
        self.nas.put_file(local, path, expected)
        return {'image_id': image, 'format': 'docker-save', 'objects': {path: expected}}
