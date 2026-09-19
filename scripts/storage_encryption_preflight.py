"""Production uploads require an OS-encrypted backing block device, checked before downtime."""
import json
import subprocess
from pathlib import PurePosixPath


def contains_encryption(devices):
    return any(d.get('type') == 'crypt' or contains_encryption(d.get('children', [])) for d in devices)


def require_encrypted_upload_storage(container):
    state = json.loads(subprocess.check_output(['docker', 'inspect', container]))[0]
    mounts = [m for m in state['Mounts'] if m['Destination'] == '/var/lib/dnr/files']
    if len(mounts) > 1:
        raise ValueError('Cannot identify the production uploaded-files mount')
    if mounts:
        mount = mounts[0]
    else:
        # Before the first filesystem migration, operators can provision this
        # Compose volume on encrypted storage without changing the running app.
        project = state['Config']['Labels']['com.docker.compose.project']
        mount = {'Type': 'volume', 'Name': project + '_uploaded_files'}
    if mount['Type'] == 'volume':
        volume = json.loads(subprocess.check_output(['docker', 'volume', 'inspect', mount['Name']]))[0]
        options = volume.get('Options') or {}
        path = options.get('device') if options.get('type') == 'none' else volume['Mountpoint']
        if not path or not path.startswith('/'):
            raise ValueError('Unsupported production volume backing device')
    elif mount['Type'] == 'bind':
        path = mount['Source']
    else:
        raise ValueError('Production uploaded files need persistent encrypted storage')
    # Read mount topology from /proc without needing root access to file contents.
    topology = json.loads(subprocess.check_output(['findmnt', '--json', '--list', '--output', 'TARGET,SOURCE']))
    parents = [m for m in topology['filesystems'] if PurePosixPath(path).is_relative_to(m['target'])]
    if not parents: raise ValueError('Cannot establish uploaded-files backing filesystem')
    source = max(parents, key=lambda m: len(m['target']))['source'].split('[')[0]
    devices = json.loads(subprocess.check_output(['lsblk', '--json', '--inverse', '--output', 'NAME,TYPE', source]))
    if not contains_encryption(devices['blockdevices']):
        raise ValueError('Production uploaded_files is not on a verified encrypted block device. '
                         'Provision encrypted storage and migrate/verify the volume before deployment; existing writers remain running.')
    return dict(mount=path, source=source, encryption='dm-crypt')
