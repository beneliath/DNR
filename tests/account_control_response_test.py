"""Finite synthetic loopback responses; no application service, DB or real credential."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import subprocess
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
LIMIT = 131072


class ResponseLimitTest(unittest.TestCase):
    def check_response(self, framing, body, accepted, status=200, caller='member'):
        sent = [0]
        class Handler(BaseHTTPRequestHandler):
            protocol_version = 'HTTP/1.1'
            def do_POST(self):
                self.rfile.read(int(self.headers['Content-Length']))
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Connection', 'close')
                if framing == 'length': self.send_header('Content-Length', str(len(body)))
                if framing == 'chunked': self.send_header('Transfer-Encoding', 'chunked')
                self.end_headers()
                try:
                    for offset in range(0, len(body), 16384):
                        chunk = body[offset:offset+16384]
                        self.wfile.write((('%x\r\n' % len(chunk)).encode()+chunk+b'\r\n') if framing == 'chunked' else chunk)
                        sent[0] += len(chunk)
                    if framing == 'chunked': self.wfile.write(b'0\r\n\r\n')
                except (BrokenPipeError, ConnectionResetError): pass
                self.close_connection = True
            def log_message(self, *args): pass
        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
        url = 'http://127.0.0.1:%s/a/test-account' % server.server_port
        program = '''<?php
        putenv('DNR_2FA_ENCRYPTION_KEY='.base64_encode(str_repeat('K',32)));
        putenv('DNR_ACCOUNTS_ENABLED=1'); putenv('DNR_ACCOUNT_GATEWAY_ENABLED=1');
        putenv('DNR_ACCOUNT_MODE=__MODE__'); putenv('DNR_REQUIRE_HTTPS=0');
        putenv('DNR_PLATFORM_API_KEY='.str_repeat('S',64));
        putenv('DNR_PRIMARY_INTERNAL_URL=__URL__');
        putenv('DNR_PRIMARY_PUBLIC_URL=__URL__');
        require 'SOURCE';
        // Configuration uses only synthetic environment secrets in this fixture.
        function configurationSecret($name) { return (string)getenv($name); }
        $account=['account_key'=>'test-account','public_url'=>'__URL__',
            'api_key_encrypted'=>\\Dnr\\Security\\ApplicationKey::seal(str_repeat('S',64))];
        $before=memory_get_peak_usage(true);
        try { RESULT; $accepted=true; } catch (Throwable $e) { $accepted=false; fwrite(STDERR,$e->getMessage()); }
        echo json_encode(['accepted'=>$accepted,'growth'=>memory_get_peak_usage(true)-$before]);
        '''.replace('__URL__', url).replace('__MODE__', 'primary' if caller == 'member' else 'member').replace('SOURCE', str(ROOT/'src/functions.php')).replace('RESULT',
            "platformMemberCall($account,'directory_profile',[])" if caller == 'member' else "platformCall('directory',[])")
        env = {k:v for k,v in os.environ.items() if not k.startswith('DNR_')}
        try:
            result = subprocess.run(['php','-d','memory_limit=32M'], input=program, text=True,
                                    capture_output=True, env=env, timeout=15, check=True)
            data = json.loads(result.stdout)
            self.assertEqual(data['accepted'], accepted, result.stderr)
            self.assertLess(data['growth'], 4*1024*1024)
            if len(body) > LIMIT: self.assertLess(sent[0], len(body), 'Must abort during transfer')
        finally:
            server.shutdown(); server.server_close(); thread.join()

    def test_both_control_directions(self):
        for caller in ['member','primary']:
            for framing in ['length','chunked','close']:
                with self.subTest(caller=caller, framing=framing):
                    self.check_response(framing,b'{"ok":true}',True,caller=caller)
                    self.check_response(framing,b'x'*(16*1024*1024),False,caller=caller)

    def test_boundary_and_invalid_responses(self):
        self.check_response('length',b'{"pad":"'+b'a'*(LIMIT-10)+b'"}',True)
        self.check_response('length',b'not json',False)
        self.check_response('length',b'{"ok":true}',False,status=503)


if __name__ == '__main__': unittest.main()
