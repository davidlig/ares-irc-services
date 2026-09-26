import importlib.machinery
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
import contextlib
import io

PATH = Path(__file__).resolve().parents[1] / 'submit-deploy'

def load():
    loader = importlib.machinery.SourceFileLoader('submit_deploy', str(PATH))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module

class TransportTests(unittest.TestCase):
    def test_request_validation(self):
        m = load()
        self.assertTrue(m.request(['/runner-state/_work/a.tar.gz', 'a'*64, 'b'*40]).endswith(b'\n'))
        for args in ([], ['/tmp/a', 'bad', 'b'*40], ['x'*5000, 'a'*64, 'b'*40]):
            with self.assertRaises(ValueError):
                m.request(args)

    def test_response_status(self):
        m = load()
        self.assertEqual(0, m.status(b'logs\n__ARES_DEPLOY_STATUS__:0\n'))
        self.assertEqual(64, m.status(b'__ARES_DEPLOY_STATUS__:64\n'))
        for response in (b'', b'__ARES_DEPLOY_STATUS__:abc', b'__ARES_DEPLOY_STATUS__:0\nextra', b'__ARES_DEPLOY_STATUS__:256'):
            with self.assertRaises(ValueError):
                m.status(response)

    def test_main_sanitizes_server_logs(self):
        m = load()
        class Connection:
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def settimeout(self, timeout): pass
            def connect(self, path): pass
            def sendall(self, payload): pass
            def shutdown(self, mode): pass
            responses = iter([b'password-secret\n__ARES_DEPLOY_STATUS__:0\n', b''])
            def recv(self, size): return next(self.responses)
        output = io.StringIO()
        with patch.object(m.socket, 'socket', return_value=Connection()), patch.object(m.sys, 'argv', ['submit', '/runner-state/_work/archive', 'a'*64, 'b'*40]), contextlib.redirect_stdout(output):
            self.assertEqual(0, m.main())
        self.assertNotIn('password-secret', output.getvalue())

    def test_main_rejects_oversized_response(self):
        m = load()
        class Connection:
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def settimeout(self, timeout): pass
            def connect(self, path): pass
            def sendall(self, payload): pass
            def shutdown(self, mode): pass
            def recv(self, size): return b'x' * (m.MAX_RESPONSE + 1)
        with patch.object(m.socket, 'socket', return_value=Connection()), patch.object(m.sys, 'argv', ['submit', '/runner-state/_work/archive', 'a'*64, 'b'*40]), contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(64, m.main())
