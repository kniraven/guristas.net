"""Run real community routes in an isolated copy with fake identities, never production SSO."""
import base64
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('GURI_TEST_PHP', 'php')
EXT = os.environ.get('GURI_TEST_FILEINFO', '')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None

with tempfile.TemporaryDirectory(prefix='guri-community-http-') as temporary:
    root = Path(temporary)
    for folder in ['app/views/partials', 'public/community', 'public/operations', 'public/admin/community']:
        shutil.copytree(ROOT / folder, root / folder)
    (root / 'app/services').mkdir(parents=True)
    for name in ['CommunityNetwork.php', 'CommunityImages.php', 'CommunityWeb.php', 'SiteTheme.php']:
        shutil.copyfile(ROOT / 'app/services' / name, root / 'app/services' / name)
    for group, names in [('css', ['structure', 'themes', 'auth', 'public-tools', 'site-usability']), ('js', ['themes', 'site', 'auth', 'public-tools', 'community-forms'])]:
        target = root / 'public/assets' / group
        target.mkdir(parents=True)
        for name in names:
            shutil.copyfile(ROOT / 'public/assets' / group / (name + '.' + group), target / (name + '.' + group))
    # Stub identity/roles only inside the temporary test copy. Real routes still enforce CSRF and ownership.
    (root / 'app/services/EveAuth.php').write_text('''<?php
function eve_session(){if(session_status()!==PHP_SESSION_ACTIVE)session_start();}
function eve_current_user(){eve_session();$id=(int)($_COOKIE['test_actor']??0);return $id>0?['character_id'=>$id,'character_name'=>'Pilot'.$id]:null;}
function eve_require_user(){$u=eve_current_user();if(!$u){header('Location: /test-login');exit;}return $u;}
function eve_csrf(){return 'test-token';}
function eve_require_csrf($t){if($t!==eve_csrf()){http_response_code(403);exit('Invalid request token.');}}
function eve_e($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
''')
    (root / 'app/services/TicketService.php').write_text("<?php require_once __DIR__.'/EveAuth.php'; function tickets_staff($id){return $id===20;}")
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    args = [PHP, '-n', '-d', 'session.save_path=' + temporary]
    if EXT:
        args += ['-d', 'extension=' + EXT]
    log = open(root / 'server.log', 'w+')
    server = subprocess.Popen(args + ['-S', '127.0.0.1:' + str(port), '-t', str(root / 'public')], stdout=log, stderr=log)
    client = urllib.request.build_opener(NoRedirect)
    def request(path, actor=0, fields=None, files=None):
        headers = {'Cookie': 'test_actor=' + str(actor)} if actor else {}
        body = None
        if fields is not None:
            boundary = 'GuriTestBoundary987654'
            pieces = []
            for key, value in fields.items():
                pieces += [('--' + boundary + '\r\nContent-Disposition: form-data; name="' + key + '"\r\n\r\n' + str(value) + '\r\n').encode()]
            for key, content in files or []:
                pieces += [('--' + boundary + '\r\nContent-Disposition: form-data; name="' + key + '"; filename="page.png"\r\nContent-Type: image/png\r\n\r\n').encode(), content, b'\r\n']
            pieces += [('--' + boundary + '--\r\n').encode()]
            body = b''.join(pieces)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        req = urllib.request.Request('http://127.0.0.1:' + str(port) + path, data=body, headers=headers)
        try:
            result = client.open(req, timeout=10)
        except urllib.error.HTTPError as error:
            result = error
        content = result.read()
        assert b'Warning:' not in content and b'Fatal error' not in content and b'Parse error' not in content, (path, content)
        return result.code, result.headers, content
    def ledger():
        return json.loads((root / 'storage/community/ledger.json').read_text())
    def post(path, actor, fields, files=None):
        fields = dict(fields)
        fields.setdefault('csrf', 'test-token')
        fields.setdefault('revision', ledger()['revision'] if (root / 'storage/community/ledger.json').exists() else 0)
        return request(path, actor, fields, files)
    try:
        for _ in range(40):
            try:
                request('/community/')
                break
            except urllib.error.URLError:
                time.sleep(.05)
        assert request('/community/submit.php')[0] == 302
        assert request('/admin/community/', 10)[0] == 403
        png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jf1sAAAAASUVORK5CYII=')
        media = {'type': 'comic', 'title': 'Test episode', 'body': 'Test story', 'credit': 'Test creator', 'rights': 'yes', 'alternatives[0]': 'Page ONE transcript', 'alternatives[1]': 'Page TWO transcript'}
        assert post('/community/submit.php', 10, dict(media, csrf='wrong'), [('images[0]', png)])[0] == 403
        assert post('/community/submit.php', 10, media, [('images[0]', png), ('images[1]', png)])[0] == 303
        data = ledger()
        comic = next(iter(data['entries']))
        images = data['entries'][comic]['images']
        image = images[0]['id']
        assert request('/community/media.php?id=' + image)[0] == 404, request('/community/media.php?id=' + image)
        assert request('/community/read.php?id=' + comic + '&preview=1', 30)[0] == 404
        assert request('/community/read.php?id=' + comic + '&preview=1', 10)[0] == 200
        assert b'Test episode' not in request('/community/')[2]
        publish = {'action': 'save', 'id': comic, 'type': 'comic', 'status': 'published', 'title': 'Test episode', 'body': 'Test story', 'credit': 'Test creator', 'rights': 'yes'}
        for i, asset in enumerate(images):
            publish['positions[' + asset['id'] + ']'] = 2 - i
            publish['alternatives[' + asset['id'] + ']'] = asset['alt']
        assert post('/admin/community/', 20, publish)[0] == 303
        assert b'Page TWO transcript' in request('/community/read.php?id=' + comic)[2]
        code, headers, content = request('/community/media.php?id=' + image)
        assert code == 200 and content == png and headers['Content-Type'] == 'image/png'
        assert headers['X-Content-Type-Options'] == 'nosniff' and 'sandbox' in headers['Content-Security-Policy']
        assert request('/community/read.php?id=' + comic + '&page=999')[0] == 404
        assert b'Test creator' in request('/community/')[2]
        code, headers, _ = request('/community/media.php?id=' + image + '&download=1')
        assert code == 200 and headers['Content-Disposition'].startswith('attachment')
        before = len(list((root / 'storage/community/images').iterdir()))
        assert post('/community/submit.php', 10, dict(media, revision=0), [('images[0]', png)])[0] == 200
        assert len(list((root / 'storage/community/images').iterdir())) == before, 'Failed ledger save must clean staged images'
        job = {'action': 'save', 'type': 'supply', 'status': 'published', 'title': 'Test order', 'body': 'Confirm with officer', 'item': 'Worm', 'quantity': 10, 'reward': '0', 'terms': 'Voluntary donation', 'contact': 'Officer', 'location': 'The Fulcrum'}
        assert post('/admin/community/', 20, job)[0] == 303
        job_id = list(ledger()['entries'])[-1]
        assert post('/community/delivery.php', 10, {'action': 'reserve', 'id': job_id, 'quantity': 7})[0] == 303
        claim_id = next(iter(ledger()['claims']))
        assert post('/community/delivery.php', 30, {'action': 'reserve', 'id': job_id, 'quantity': 4})[0] == 400
        assert post('/community/delivery.php', 30, {'action': 'submit', 'reservation': claim_id, 'evidence': 'Not mine'})[0] == 400
        assert post('/community/delivery.php', 10, {'action': 'submit', 'reservation': claim_id, 'evidence': 'PRIVATE contract evidence XYZ'})[0] == 303
        assert b'PRIVATE contract evidence XYZ' not in request('/operations/')[2]
        assert b'PRIVATE contract evidence XYZ' in request('/operations/', 10)[2]
        assert b'PRIVATE contract evidence XYZ' not in request('/operations/', 30)[2]
        assert post('/admin/community/', 20, {'action': 'review', 'id': claim_id, 'status': 'approved', 'note': 'Verified seven Worms'})[0] == 303
        assert b'7 / 10 units' in request('/operations/')[2]
        fleet = {'action': 'save', 'type': 'fleet', 'status': 'published', 'title': 'Test fleet', 'body': 'Beginners welcome; contact the FC before undocking.', 'contact': 'FC', 'location': 'The Fulcrum', 'when': '2030-01-01T20:00'}
        assert post('/admin/community/', 20, fleet)[0] == 303
        fleet_id = list(ledger()['entries'])[-1]
        report = {'action': 'save', 'type': 'report', 'status': 'published', 'title': 'Test report', 'body': 'Organizer supplied account.', 'fleet': fleet_id, 'campaign': 'Historical test campaign', 'campaign_url': 'https://example.com/campaign'}
        assert post('/admin/community/', 20, report)[0] == 303
        report_id = list(ledger()['entries'])[-1]
        assert b'Related fleet: Test fleet' in request('/operations/entry.php?id=' + report_id)[2]
        assert b'Test report' in request('/operations/entry.php?id=' + fleet_id)[2]
        publish['status'] = 'archived'
        assert post('/admin/community/', 20, publish)[0] == 303
        assert request('/community/media.php?id=' + image)[0] == 404, request('/community/media.php?id=' + image)
        assert request('/community/media.php?id=' + image, 10)[0] == 200
        print('Real-route upload, moderation, comic ordering, image privacy, reservations, CSRF and reviewed progress passed.')
    finally:
        server.terminate()
        server.wait(timeout=5)
        log.close()
