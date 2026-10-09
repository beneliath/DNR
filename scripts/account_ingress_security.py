"""Trusted ingress policy for Accounts sharing one origin and /a/<key> paths.

The application cannot loosen these headers or replace the ingress's static JS.
This is defense in depth, not a claim that URL paths are browser origins.
"""
import hashlib
import re
from urllib.parse import urlsplit


def configuration(key, public_url, primary=False, stripped=True):
    if not re.fullmatch(r'[a-z][a-z0-9-]{2,63}', key):
        raise ValueError('Invalid Account key')
    url=urlsplit(public_url)
    if url.scheme not in ('https','http') or not url.netloc or url.path!='/a/'+key \
            or url.query or url.fragment or url.username or url.password \
            or re.search(r'[\s"<>\\]',public_url):
        raise ValueError('Invalid canonical Account URL')
    session='MOED_'+hashlib.sha256(key.encode()).hexdigest()[:16]
    preferences=r'dnr_(?:rows_per_page_[a-zA-Z0-9_-]+|engagement_lifecycle_[0-9]+|backup_[a-f0-9]{32}|download_[a-f0-9]{32}|sidebar_[a-f0-9]{12})'
    allowed='(?:'+session+'|'+preferences+')'
    assets=public_url+'/assets/'
    if primary: assets+=' '+url.scheme+'://'+url.netloc+'/assets/'
    lines=[
        '# Generated trusted Account boundary; never supplied by the app.',
        'ProxyPass "/assets/" "!"',
        'Alias "/assets/" "/opt/dnr/account-assets/"',
        '<Directory "/opt/dnr/account-assets">',
        '    Options -Indexes -ExecCGI', '    AllowOverride None', '    Require all granted',
        '    <FilesMatch "(?i)\\.php(?:/|$)">', '        Require all denied', '    </FilesMatch>',
        '</Directory>',
        # Only this Account's authentication cookie, non-auth UI preferences,
        # and bounded download/backup acknowledgements
        # reach its app/download pools. Legacy and sibling credentials are removed.
    ]
    if not stripped:
        lines += [f'ProxyPass "/a/{key}/assets/" "!"',
                  f'Alias "/a/{key}/assets/" "/opt/dnr/account-assets/"']
    location='<Location "/">' if stripped else f'<LocationMatch "^/(?:a/{key}(?:/|$)|(?!a/))">'
    lines += [location,
              '    RequestHeader edit* Cookie "(^|;)(?!\\s*'+allowed+'=)[^;]+" ""',
              '    RequestHeader edit Cookie "^;\\s*" ""']
    for table in ['onsuccess','always']:
        lines += [
            # Rewrite each Set-Cookie independently, including duplicate headers.
            f'    Header {table} edit Set-Cookie "^(?!{allowed}=).*" ""',
            f'    Header {table} edit* Set-Cookie "(?i);\\s*Domain=[^;]*" ""',
            f'    Header {table} edit Set-Cookie "(?i)(;\\s*Path=)[^;]*" "$1/a/{key}/"',
            f'    Header {table} unset Clear-Site-Data',
            f'    Header {table} unset Service-Worker-Allowed',
            f'    Header {table} unset Access-Control-Allow-Origin',
        ]
        if primary:
            # The primary cookie also serves the common login/recovery endpoints.
            lines.append(f'    Header {table} edit Set-Cookie "^({session}=[^;]*;(?i:.*?Path=))[^;]*(.*)$" "$1/$2"')
            if not stripped:
                # Legacy root pages must be able to read their non-auth download
                # acknowledgements. Canonical Account pages keep the scoped path.
                lines.append(f'    Header {table} edit Set-Cookie "^(dnr_(?:download|backup)_[a-f0-9]{{32}}=[^;]*;(?i:.*?Path=))[^;]*(.*)$" "$1/$2" "expr=%{{REQUEST_URI}} !~ m#^/a/#"')
    lines += [
        # This additional policy intersects the app's CSP. A compromised app
        # cannot authorize inline scripts/nonces or executable PHP responses.
        f'    Header always add Content-Security-Policy "script-src {assets}; script-src-attr \'none\'; worker-src {assets} blob:; object-src \'none\'; base-uri \'none\'; frame-ancestors \'none\'"',
        '</Location>' if stripped else '</LocationMatch>',
    ]
    return '\n'.join(lines)+'\n'
