"""Shared browser-map settings for production and local Account provisioning."""
import copy
import json
from pathlib import Path
import re

from provision_local_account import run
from account_lifecycle_local import inspect

MAP_SECRET = 'dnr_map_amazon_api_key'


def primary_amazon_map(config):
    """Read effective browser-map settings, never the key, from the primary app."""
    result = run(['docker', 'exec', config['primary_web'], 'php', '-r', '''
require '/var/www/html/application_runtime.php';
$c = deploymentConfig();
echo json_encode(['provider'=>$c->string('map.provider'),
    'region'=>$c->string('map.amazon_region'), 'style'=>$c->string('map.amazon_style'),
    'maximum_zoom'=>$c->integer('map.maximum_zoom'),
    'key_file'=>getenv('DNR_MAP_AMAZON_API_KEY_FILE') ?: ''], JSON_THROW_ON_ERROR);
'''], capture_output=True)
    settings = json.loads(result.stdout)
    if settings['provider'] != 'amazon':
        return None
    if not re.fullmatch(r'[a-z]{2}-[a-z]+-[0-9]', settings['region']) \
            or settings['style'] not in ['Standard', 'Monochrome', 'Hybrid', 'Satellite'] \
            or type(settings['maximum_zoom']) is not int or not 1 <= settings['maximum_zoom'] <= 24:
        raise ValueError('Invalid primary Amazon map settings')
    # Share only the browser-restricted map key. Account data/encryption secrets
    # and the rest of the primary container environment are never inherited.
    primary = inspect(config['primary_web'])
    mount = next((m for m in primary.get('Mounts', [])
                  if m['Destination'] == settings['key_file'] and m['Type'] == 'bind' and not m['RW']), None)
    if mount is None:
        raise ValueError('Amazon maps requires a read-only file-backed browser key')
    source = Path(mount['Source'])
    if not source.is_absolute() or not source.is_file() or source.stat().st_size > 16384:
        raise ValueError('Amazon map key file is unavailable')
    key = source.read_text().strip()
    if not key.startswith('v1.public.') or re.search(r'\s', key):
        raise ValueError('Amazon maps requires a browser API key')
    return {'environment': {'DNR_MAP_PROVIDER': 'amazon',
                            'DNR_MAP_AMAZON_REGION': settings['region'],
                            'DNR_MAP_AMAZON_STYLE': settings['style'],
                            'DNR_MAP_MAXIMUM_ZOOM': str(settings['maximum_zoom']),
                            'DNR_MAP_AMAZON_API_KEY_FILE': '/run/secrets/' + MAP_SECRET},
            'secret_file': str(source)}


def with_amazon_map(document, settings):
    """Add browser rendering to web only; keep every other service untouched."""
    result = copy.deepcopy(document)
    if settings is None:
        return result
    web = result['services']['web']
    environment = web.setdefault('environment', {})
    environment.pop('DNR_MAP_AMAZON_API_KEY', None)
    environment.update(settings['environment'])
    references = web.setdefault('secrets', [])
    references[:] = [s for s in references if (s if isinstance(s, str) else s.get('target', s['source'])) != MAP_SECRET]
    references.append({'source': MAP_SECRET, 'target': MAP_SECRET})
    result.setdefault('secrets', {})[MAP_SECRET] = {'file': settings['secret_file']}
    return result

