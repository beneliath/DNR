"""Exercise ClamD framing and fail-closed responses using a local synthetic server."""
import datetime
import json
from pathlib import Path
import socket
import subprocess
import threading
import unittest
ROOT=Path(__file__).resolve().parent.parent

class ScannerProtocolTests(unittest.TestCase):
    def run_scan(self, response, age_days=0, disconnect=False):
        server=socket.socket(); server.bind(('127.0.0.1',0)); server.listen(2); server.settimeout(10)
        port=server.getsockname()[1]; captured=[]; errors=[]
        def until_zero(connection):
            result=b''
            while not result.endswith(b'\0'):
                part=connection.recv(1)
                if not part: raise RuntimeError('Incomplete command')
                result+=part
            return result
        def serve():
            try:
                with server.accept()[0] as connection:
                    captured.append(until_zero(connection))
                    date=datetime.datetime.now(datetime.timezone.utc)-datetime.timedelta(days=age_days)
                    connection.sendall(('ClamAV fixture/1/'+date.strftime('%a %b %d %H:%M:%S %Y')).encode()+b'\0')
                if age_days>3: return
                with server.accept()[0] as connection:
                    captured.append(until_zero(connection))
                    if disconnect: return
                    stream=connection.makefile('rb')
                    while True:
                        header=stream.read(4)
                        if len(header)!=4: raise RuntimeError('Missing frame')
                        size=int.from_bytes(header,'big')
                        if not size: break
                        captured.append(stream.read(size))
                    connection.sendall(response+b'\0')
            except Exception as error: errors.append(error)
            finally: server.close()
        thread=threading.Thread(target=serve); thread.start()
        script='''require %s; putenv('DNR_CLAMD_HOST=127.0.0.1'); putenv('DNR_CLAMD_PORT=%d');
$f=fopen('php://temp','w+b'); fwrite($f,'synthetic document'); rewind($f);
try { echo scanDocumentStream($f,18)?'clean':'rejected'; } catch (Throwable $e) { echo 'deferred'; }''' % (json.dumps(str(ROOT/'src/document_scanning_helpers.php')),port)
        result=subprocess.run(['php','-r',script],capture_output=True,text=True,timeout=15)
        thread.join(timeout=10)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(errors,[])
        self.assertEqual(captured[0],b'zVERSION\0')
        if age_days<=3: self.assertEqual(captured[1],b'zINSTREAM\0')
        return result.stdout,captured
    def test_clean_and_detection(self):
        result,frames=self.run_scan(b'stream: OK'); self.assertEqual(result,'clean'); self.assertIn(b'synthetic document',frames)
        self.assertEqual(self.run_scan(b'stream: Eicar-Test-Signature FOUND')[0],'rejected')
    def test_scanner_error_stale_signatures_and_disconnect_never_approve(self):
        self.assertEqual(self.run_scan(b'INSTREAM size limit exceeded. ERROR')[0],'deferred')
        self.assertEqual(self.run_scan(b'stream: OK',age_days=4)[0],'deferred')
        self.assertEqual(self.run_scan(b'',disconnect=True)[0],'deferred')
if __name__=='__main__': unittest.main()
