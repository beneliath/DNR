"""Disposable Apache/backend fixtures. No application DB, secrets or deployment."""
import argparse
import hashlib
import http.client
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import uuid

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'scripts'))
from account_ingress_security import configuration


def run(*args):
    result=subprocess.run(['docker',*args],capture_output=True,text=True)
    if result.returncode: raise RuntimeError(result.stderr[-2000:])
    return result.stdout.strip()


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-docker',action='store_true',required=True)
    parser.add_argument('--image',default='dnr-ingress:local')
    parser.add_argument('--browser-review',action='store_true',help='Keep synthetic page available until Enter is pressed')
    options=parser.parse_args()
    name='account-ingress-check-'+uuid.uuid4().hex[:10]
    key='test-account'; own='MOED_'+hashlib.sha256(key.encode()).hexdigest()[:16]
    sibling='MOED_'+hashlib.sha256(b'primary-account').hexdigest()[:16]
    with tempfile.TemporaryDirectory(prefix=name+'-') as folder:
        root=Path(folder); root.chmod(0o755)
        (root/'assets').mkdir(); (root/'assets/test.js').write_text('/* trusted fixture */')
        (root/'assets/browser-check.js').write_text('''
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('trusted').textContent = 'PASS: trusted ingress script loaded';
  document.getElementById('inline').textContent = window.inlineExecuted ? 'FAIL: inline script ran' : 'PASS: inline script blocked';
  document.getElementById('external').textContent = window.untrustedExecuted ? 'FAIL: backend script ran' : 'PASS: backend script blocked';
});
''')
        (root/'assets/denied.php').write_text('<?php echo "not executable";')
        (root/'backend.php').write_text('''<?php
if (str_contains($_SERVER['REQUEST_URI'], '/untrusted.php')) {
    header('Content-Type: application/javascript'); echo 'window.untrustedExecuted=true;'; exit;
}
if (str_contains($_SERVER['REQUEST_URI'], '/browser.php')) {
    header("Content-Security-Policy: script-src * 'unsafe-inline'");
    echo '<!doctype html><title>Account ingress security fixture</title><h1>Account ingress security fixture</h1>';
    echo '<p id="trusted">Waiting for trusted script</p><p id="inline">Checking inline script</p><p id="external">Checking backend script</p>';
    echo '<script src="assets/browser-check.js"></script><script>window.inlineExecuted=true;</script><script src="untrusted.php"></script>';
    exit;
}
header('Content-Type: application/json');
header('Set-Cookie: SIBLING=overwrite; Path=/; Domain=localhost', false);
header('Set-Cookie: OWN=valid; Path=/; Domain=localhost; HttpOnly; SameSite=Lax', false);
header('Set-Cookie: OWN=deleted; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0', false);
header('Set-Cookie: dnr_rows_per_page_tasks=25; Path=/; Domain=localhost', false);
header('Clear-Site-Data: "cookies", "storage"');
header('Service-Worker-Allowed: /');
header('Access-Control-Allow-Origin: *');
header("Content-Security-Policy: script-src * 'unsafe-inline'");
echo json_encode(['cookie'=>$_SERVER['HTTP_COOKIE'] ?? '', 'uri'=>$_SERVER['REQUEST_URI']]);
'''.replace('SIBLING',sibling).replace('OWN',own))
        try:
            # Publish only on loopback; Docker internal networks omit published
            # ports. This disposable bridge contains synthetic fixtures only.
            run('network','create',name)
            run('run','-d','--name',name+'-backend','--network',name,'--network-alias','fixture-backend',
                '--mount','type=bind,src='+str(root)+',dst=/fixture,readonly','--entrypoint','php',options.image,
                '-S','0.0.0.0:80','/fixture/backend.php')
            (root/'proxy.conf').write_text('ProxyRequests Off\nProxyPass "/" "http://fixture-backend/"\n')
            (root/'security.conf').write_text(configuration(key,'http://localhost/a/'+key))
            run('run','-d','--name',name,'--network',name,'-p','127.0.0.1::80',
                '--mount','type=bind,src='+str(root/'proxy.conf')+',dst=/etc/apache2/conf-enabled/zz-dnr-ingress.conf,readonly',
                '--mount','type=bind,src='+str(root/'security.conf')+',dst=/etc/apache2/conf-enabled/zy-dnr-account-security.conf,readonly',
                '--mount','type=bind,src='+str(root/'assets')+',dst=/opt/dnr/account-assets,readonly',options.image)
            port=int(run('port',name,'80/tcp').rsplit(':',1)[1])
            def request(path='/',cookie=''):
                connection=http.client.HTTPConnection('127.0.0.1',port,timeout=5)
                connection.request('GET',path,headers={'Cookie':cookie})
                response=connection.getresponse(); body=response.read(); headers=response.getheaders()
                connection.close(); return response.status,headers,body
            for attempt in range(30):
                try:
                    if request()[0]==200: break
                except OSError: pass
                time.sleep(.2)
            else: raise AssertionError(run('logs',name))
            for cookie in [sibling+'=private; '+own+'=allowed; PHPSESSID=legacy',
                           own+'=allowed; '+sibling+'=private; PHPSESSID=legacy',
                           'PHPSESSID=legacy; '+sibling+'=private; '+own+'=allowed']:
                status,headers,body=request(cookie=cookie)
                assert status==200
                assert json.loads(body)['cookie']==own+'=allowed',json.loads(body)
            status,headers,body=request(cookie=own+'=allowed; dnr_rows_per_page_tasks=25; unknown=hidden')
            assert 'dnr_rows_per_page_tasks=25' in json.loads(body)['cookie']
            cookies=[v for k,v in headers if k.lower()=='set-cookie' and v]
            assert len(cookies)==3,cookies
            assert all(sibling not in v and 'Domain=' not in v for v in cookies),cookies
            assert all('Path=/a/'+key+'/' in v for v in cookies),cookies
            assert any('Max-Age=0' in v and 'Expires=' in v for v in cookies),cookies
            assert not any(k.lower() in ['clear-site-data','service-worker-allowed','access-control-allow-origin'] for k,v in headers)
            policies=[v for k,v in headers if k.lower()=='content-security-policy']
            assert len(policies)==2,policies
            assert any("script-src http://localhost/a/test-account/assets/;" in p and "script-src-attr 'none'" in p for p in policies),policies
            assert request('/assets/test.js')[2]==b'/* trusted fixture */'
            assert request('/assets/denied.php')[0]==403
            # Local primary ingress sees the complete URL. It must filter common
            # entry points and its own prefix, but leave member routing untouched.
            (root/'security.conf').write_text(configuration(key,'http://localhost/a/'+key,True,False))
            run('exec',name,'apachectl','-k','graceful'); time.sleep(.5)
            for path in ['/login.php','/a/test-account/profile.php']:
                _,headers,body=request(path,cookie=sibling+'=private; '+own+'=allowed')
                assert json.loads(body)['cookie']==own+'=allowed'
                cookies=[v for k,v in headers if k.lower()=='set-cookie' and v.startswith(own+'=')]
                assert len(cookies)==2 and all('Path=/;' in v for v in cookies),cookies
            assert sibling in json.loads(request('/a/other-account/profile.php',cookie=sibling+'=private')[2])['cookie']
            assert request('/a/test-account/assets/test.js')[2]==b'/* trusted fixture */'
            print('PASS: cookie request/response isolation, logout, trusted assets, intersecting CSP, and primary/member path routing')
            if options.browser_review:
                url='http://127.0.0.1:'+str(port)+'/a/'+key
                (root/'security.conf').write_text(configuration(key,url,True,False))
                run('exec',name,'apachectl','-k','graceful')
                print('Synthetic browser fixture: '+url+'/browser.php',flush=True)
                input('Press Enter after browser verification to remove the fixture: ')
        except Exception:
            print(subprocess.run(['docker','logs',name],capture_output=True,text=True).stderr[-2500:])
            raise
        finally:
            for resource in [name,name+'-backend']:
                subprocess.run(['docker','rm','-f',resource],capture_output=True)
            subprocess.run(['docker','network','rm',name],capture_output=True)


if __name__=='__main__': main()
