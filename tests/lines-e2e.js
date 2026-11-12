// End-to-end test for Line Settings: the real plugin in a real WordPress, talking
// to the real clickhive-voice /line-settings Function. Only Google Sheets (and
// the rest of Twilio) are simulated, by clickhive-voice's own test harness.
//
// Needs: a local WordPress at WP_URL with this plugin active, pretty permalinks,
// and wp-cli.phar next to the WordPress folder (WP_PATH). The test sets up its
// own settings and users.  Run:  WP_PATH=../wp node tests/lines-e2e.js

const assert = require('node:assert/strict');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const VOICE = path.resolve(__dirname, '../../clickhive-voice');
const WP_URL = process.env.WP_URL || 'http://localhost:8099';
const WP_PATH = path.resolve(process.env.WP_PATH || path.join(__dirname, '../../wp'));
const MASTER = 'test-master-key-0123456789';
const nativeFetch = global.fetch;

const { makeWorld, sheet, row, HEADER } = require(path.join(VOICE, 'tests/harness.js'));
const lib = require(path.join(VOICE, 'functions/lib/config.js'));

const day = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);
const HOURS = JSON.stringify({
  timezone: 'America/Los_Angeles',
  hours: { mon: { open: '0.375', close: '0.708333333333333' }, tue: { open: '9am', close: '5pm' }, fri: { closed: true } },
  holidays: ['2099-12-25'],
});

sheet.rows = [
  HEADER,
  row({ number: '(360) 309-0950', name: 'Test Business', id: '', r1: '+13605211487', r2: '+13605211487', hours: HOURS, site: WP_URL }),
  row({ number: '(503) 555-0000', name: 'Other Client', id: '', site: 'https://other-client.com' }),
  row({ number: '(360) 803-7577', name: 'Test line', id: '', site: WP_URL }),
];
sheet.writes = [];
sheet.log = [];

// ---------- A fake Twilio runtime serving the real /line-settings ----------
function startServer() {
  const fn = path.join(VOICE, 'functions/line-settings.js');
  return new Promise((resolve) => {
    const server = http.createServer((req, res) => {
      let raw = '';
      req.on('data', (c) => (raw += c));
      req.on('end', () => {
        const event = Object.fromEntries(new URLSearchParams(raw));
        const { context } = makeWorld({ CALLS_MASTER_KEY: MASTER });
        delete require.cache[fn];
        require(fn).handler(context, event, (err, r) => {
          global.fetch = nativeFetch;
          if (err) {
            res.writeHead(500);
            return res.end(String(err));
          }
          for (const [k, v] of Object.entries(r.headers)) {
            if (Array.isArray(v)) {
              // Twilio itself fails a response like this; so does this test.
              res.writeHead(500);
              return res.end(`duplicate header ${k}`);
            }
          }
          res.writeHead(r.statusCode, r.headers);
          res.end(JSON.stringify(r.body));
        });
      });
    });
    server.listen(8100, () => resolve(server));
  });
}

// ---------- wp-cli and a minimal browser ----------
const wp = (...args) => execFileSync('php', [path.join(WP_PATH, '..', 'wp-cli.phar'), '--allow-root', `--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();

async function login(user, pass) {
  const jar = { wordpress_test_cookie: 'WP%20Cookie%20check' };
  const store = (res) => {
    for (const c of res.headers.getSetCookie()) {
      const [kv] = c.split(';');
      const i = kv.indexOf('=');
      jar[kv.slice(0, i)] = kv.slice(i + 1);
    }
  };
  const cookie = () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
  const res = await fetch(`${WP_URL}/wp-login.php`, {
    method: 'POST',
    redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookie() },
    body: new URLSearchParams({ log: user, pwd: pass, testcookie: '1', redirect_to: `${WP_URL}/wp-admin/` }),
  });
  store(res);
  assert.equal(res.status, 302, `login as ${user}`);
  return cookie;
}

const page = async (cookie, line = '') =>
  (await fetch(`${WP_URL}/wp-admin/admin.php?page=chc-lines${line ? `&line=${line}` : ''}`, { headers: { Cookie: cookie() } })).text();

async function save(cookie, fields, { line = '3603090950', nonce } = {}) {
  const html = nonce === undefined ? await page(cookie, line) : '';
  const n = nonce !== undefined ? nonce : /name="_wpnonce" value="([^"]+)"/.exec(html)[1];
  const res = await fetch(`${WP_URL}/wp-admin/admin.php?page=chc-lines&line=${line}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookie() },
    body: new URLSearchParams({ _wpnonce: n, _wp_http_referer: '/wp-admin/admin.php?page=chc-lines', chc_line: line, ...fields }),
  });
  return { status: res.status, html: await res.text() };
}

let passed = 0;
async function check(name, fn) {
  await fn();
  passed++;
  console.log(`ok ${passed} - ${name}`);
}

(async () => {
  const server = await startServer();
  try {
    // Settings and users, as Click Hive would set up a site.
    const key = lib.siteKey({ CALLS_MASTER_KEY: MASTER }, WP_URL);
    wp('option', 'update', 'chc_settings', JSON.stringify({ site_key: key, numbers: '(360) 309-0950\n(503) 555-0000\n(360) 803-7577', playback_url: 'http://localhost:8100/play', retention_days: 365 }), '--format=json');
    try { wp('role', 'create', 'client', 'Client'); } catch (e) { /* exists */ }
    for (const [u, role] of [['clara', 'client'], ['maria', 'chc_call_manager']]) {
      try { wp('user', 'create', u, `${u}@example.com`, `--role=${role}`, `--user_pass=${u}pass`); } catch (e) { /* exists */ }
    }
    wp('eval', 'do_action("admin_init");'); // grants the new abilities, as the first admin page load does

    const admin = await login('admin', 'admin');
    const clara = await login('clara', 'clarapass');
    const maria = await login('maria', 'mariapass');

    await check('the page lists a tab per Business number, with this site\'s line filled in', async () => {
      const html = await page(admin);
      assert.match(html, /<h1>Line Settings<\/h1>/);
      assert.equal((html.match(/class="nav-tab( nav-tab-active)?"/g) || []).length, 3);
      assert.match(html, /name="receiver_1" value="\(360\) 521-1487"/);
      assert.match(html, /name="receiver_2" value="\(360\) 521-1487"/);
      assert.match(html, /<th scope="row">Monday<\/th><td>9:00am to 5:00pm<\/td>/);
      assert.match(html, /<th scope="row">Friday<\/th><td>Closed<\/td>/);
      assert.match(html, /Times are Pacific Time\./);
      assert.match(html, /Friday, December 25, 2099/);
    });

    await check('another client\'s number and the test number show a message, not a form', async () => {
      const other = await page(admin, '5035550000');
      assert.match(other, /belongs to a different website/);
      assert.doesNotMatch(other, /name="receiver_1"/);
      const test = await page(admin, '3608037577');
      assert.match(test, /shared test number/);
      assert.doesNotMatch(test, /name="receiver_1"/);
    });

    await check('saving clears Caller 2 and sets vacation, in the sheet and the Change log', async () => {
      const r = await save(admin, { receiver_1: '360-555-0123', receiver_2: '', vacation_start: day(3), vacation_end: day(5) });
      assert.match(r.html, /Saved\. The next call to this line will use these settings\./);
      assert.equal(sheet.rows[1][4], '+13605550123');
      assert.equal(sheet.rows[1][5], '');
      assert.equal(sheet.rows[1][10], `${day(3)}/${day(5)}`);
      assert.equal(sheet.log.length, 1);
      assert.equal(sheet.log[0][1], 'localhost');
      assert.match(r.html, /This line is closed from .* through .*\./);
      assert.match(r.html, /name="receiver_1" value="\(360\) 555-0123"/);
      assert.match(r.html, /Remove these vacation dates/);
    });

    await check('a refused save shows the problem by the field and keeps what was typed', async () => {
      const before = JSON.stringify(sheet.rows[1]);
      const r = await save(admin, { receiver_1: '555-1234', receiver_2: '' });
      assert.match(r.html, /Not saved\. Fix the highlighted fields/);
      assert.match(r.html, /Caller 1 must be a 10-digit US phone number\./);
      assert.match(r.html, /name="receiver_1" value="555-1234"/);
      assert.equal(JSON.stringify(sheet.rows[1]), before);
    });

    await check('only one vacation day filled in is caught before anything is sent', async () => {
      const writes = sheet.writes.length;
      const r = await save(admin, { receiver_1: '3605550123', vacation_start: day(3), vacation_end: '' });
      assert.match(r.html, /Choose both a first and a last day/);
      assert.equal(sheet.writes.length, writes);
    });

    await check('removing vacation dates clears column K', async () => {
      const r = await save(admin, { receiver_1: '3605550123', vacation_start: day(3), vacation_end: day(5), clear_vacation: '1' });
      assert.match(r.html, /Saved\./);
      assert.equal(sheet.rows[1][10], '');
      assert.doesNotMatch(r.html, /This line is closed/);
    });

    await check('saving with nothing changed says so and writes nothing', async () => {
      const writes = sheet.writes.length;
      const r = await save(admin, { receiver_1: '(360) 555-0123', receiver_2: '' });
      assert.match(r.html, /Nothing changed\./);
      assert.equal(sheet.writes.length, writes);
    });

    await check('the Client role can use Line Settings', async () => {
      const r = await save(clara, { receiver_1: '3605550124', receiver_2: '3605550125' });
      assert.match(r.html, /Saved\./);
      assert.equal(sheet.rows[1][4], '+13605550124');
      assert.equal(sheet.rows[1][5], '+13605550125');
    });

    await check('a Call Manager can\'t open Line Settings or save', async () => {
      const html = await page(maria);
      assert.doesNotMatch(html, /name="receiver_1"/);
      const res = await fetch(`${WP_URL}/wp-admin/admin.php?page=chc-lines`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: maria() },
        body: new URLSearchParams({ chc_line: '3603090950', receiver_1: '3605550199' }),
      });
      assert.notEqual(sheet.rows[1][4], '+13605550199');
      assert.ok(res.status >= 400 || /not allowed|do not have access/i.test(await res.text()));
    });

    await check('a save without the form\'s security token is refused', async () => {
      const r = await save(admin, { receiver_1: '3605550188' }, { nonce: 'bad' });
      assert.notEqual(r.status, 200);
      assert.notEqual(sheet.rows[1][4], '+13605550188');
    });

    await check('a wrong site key shows a clear message and changes nothing', async () => {
      const s = JSON.parse(wp('option', 'get', 'chc_settings', '--format=json'));
      wp('option', 'update', 'chc_settings', JSON.stringify({ ...s, site_key: 'a'.repeat(64) }), '--format=json');
      const html = await page(admin);
      assert.match(html, /did not accept this site&#039;s key/);
      wp('option', 'update', 'chc_settings', JSON.stringify(s), '--format=json');
    });

    await check('the phone system being unreachable shows a calm message', async () => {
      server.close();
      await new Promise((r) => setTimeout(r, 200));
      const html = await page(admin);
      assert.match(html, /could not be reached\. Your lines are not affected/);
    });

    console.log(`1..${passed}\n# all ${passed} passed`);
  } catch (e) {
    console.error(`not ok ${passed + 1}`);
    console.error(e);
    process.exitCode = 1;
  } finally {
    server.close();
  }
})();
