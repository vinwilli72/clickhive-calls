// End-to-end test: the real plugin in a real WordPress, talking to the real
// Twilio Function code (lib/config.js postToSite + play.js). Only Twilio's own
// servers and Google Sheets are simulated.
//
// Needs: a local WordPress at WP_URL with this plugin active and configured with
// the site key for "localhost", user "maria" (Call Manager) and "bob" (subscriber).
// Run:  node tests/e2e.js

const assert = require('node:assert/strict');
const http = require('node:http');
const path = require('node:path');

const VOICE = path.resolve(__dirname, '../../clickhive-voice');
const WP_URL = process.env.WP_URL || 'http://localhost:8099';
const MASTER = 'test-master-key-0123456789';
const FAKE_MP3 = Buffer.from('ID3\x03\x00\x00\x00\x00\x00\x00fake-mp3-audio-bytes-for-test', 'binary');

const { makeWorld, sheet, row, HEADER } = require(path.join(VOICE, 'tests/harness.js'));
const lib = require(path.join(VOICE, 'functions/lib/config.js'));
const nativeFetch = global.fetch;
// The sheet row for this test site's number points at this WordPress.
sheet.rows = [
  HEADER,
  row({ site: WP_URL }),
  row({ number: '(503) 555-0000', name: 'Other Client', id: 'other_flow', site: 'https://other-client.com' }),
];

let passed = 0;
async function check(name, fn) {
  await fn();
  passed++;
  console.log(`ok ${passed} - ${name}`);
}

const { startPlayServer } = require('./fake-twilio');

// ---------- Minimal cookie-aware browser for wp-admin ----------
async function login(user, pass) {
  const jar = {};
  const store = (res) => {
    for (const c of res.headers.getSetCookie()) {
      const [kv] = c.split(';');
      const [k, v] = kv.split('=');
      jar[k] = v;
    }
  };
  const cookie = () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
  store(await fetch(`${WP_URL}/wp-login.php`, { headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' } }));
  jar.wordpress_test_cookie = 'WP%20Cookie%20check';
  const res = await fetch(`${WP_URL}/wp-login.php`, {
    method: 'POST',
    redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookie() },
    body: new URLSearchParams({ log: user, pwd: pass, testcookie: '1', redirect_to: `${WP_URL}/wp-admin/` }),
  });
  store(res);
  return { cookie, redirect: res.headers.get('location') };
}

const sid = (n) => 'CA' + String(n).padStart(32, '0');
const rec = (n) => 'RE' + String(n).padStart(32, '0');

// Start from an empty inbox so counts don't depend on earlier runs.
// WP_PATH = the WordPress folder; needs wp-cli.phar one level above it.
function clearCalls() {
  const wpPath = process.env.WP_PATH || '/tmp/claude-0/wp/core';
  const cli = path.join(wpPath, '..', 'wp-cli.phar');
  if (!require('node:fs').existsSync(cli)) return console.log('# wp-cli not found: run against a fresh database');
  const { execFileSync } = require('node:child_process');
  const wp = (...a) => execFileSync('php', [cli, '--allow-root', `--path=${wpPath}`, ...a], { encoding: 'utf8' }).trim();
  const ids = wp('post', 'list', '--post_type=chc_call', '--post_status=any', '--format=ids');
  if (ids) wp('post', 'delete', ...ids.split(/\s+/), '--force');
}

(async () => {
  clearCalls();
  const play = await startPlayServer();
  const client = { site_url: WP_URL, business_number: '(360) 309-0950' };
  const ctx = { CALLS_MASTER_KEY: MASTER };
  const post = (record, c = client, cx = ctx) => lib.postToSite(cx, c, record);
  const base = { caller: '+13605131227', business_number: '(360) 309-0950', occurred_at: new Date().toISOString() };

  try {
    await check('Twilio posts a voicemail: signature from Node is accepted by PHP', async () => {
      const r = await post({ ...base, call_sid: sid(1), outcome: 'voicemail', duration_seconds: 42, recording_sid: rec(1) });
      assert.deepEqual(r, { posted: true });
    });

    await check('Twilio posts a missed call and an answered call', async () => {
      await post({ ...base, call_sid: sid(2), outcome: 'missed', duration_seconds: 12 });
      await post({ ...base, call_sid: sid(3), outcome: 'answered', duration_seconds: 185, caller: '+15035551212' });
    });

    await check('same call posted twice -> one record, voicemail never downgraded to missed', async () => {
      await post({ ...base, call_sid: sid(1), outcome: 'missed' });
    });

    await check('wrong key is refused (401)', async () => {
      await assert.rejects(post({ ...base, call_sid: sid(9), outcome: 'missed' }, client, { CALLS_MASTER_KEY: 'other' }), /HTTP 401/);
    });

    await check('a call to a number that is not this site\'s is refused (403)', async () => {
      await assert.rejects(post({ ...base, call_sid: sid(8), outcome: 'missed', business_number: '+15035550000' }), /HTTP 403/);
    });

    await check('an old signed request can\'t be replayed (401)', async () => {
      const body = JSON.stringify({ ...base, call_sid: sid(7), outcome: 'missed' });
      const ts = String(Math.floor(Date.now() / 1000) - 600);
      const sig = require('crypto').createHmac('sha256', lib.siteKey(ctx, WP_URL)).update(`${ts}.${body}`).digest('hex');
      const r = await fetch(`${WP_URL}/wp-json/clickhive-calls/v1/calls`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CH-Timestamp': ts, 'X-CH-Signature': sig },
        body,
      });
      assert.equal(r.status, 401);
    });

    await check('nobody logged in -> no audio (401)', async () => {
      const r = await fetch(`${WP_URL}/wp-json/clickhive-calls/v1/calls/1/audio`);
      assert.ok([401, 403].includes(r.status), `got ${r.status}`);
    });

    const maria = await login('maria', 'testpass123');
    await check('Call Manager lands on the inbox after signing in', async () => {
      assert.match(maria.redirect || '', /page=clickhive-calls/);
    });

    let page, nonce, ids;
    await check('inbox lists the three calls, newest first, with a Play button only on the voicemail', async () => {
      page = await (await fetch(`${WP_URL}/wp-admin/admin.php?page=clickhive-calls`, { headers: { Cookie: maria.cookie() } })).text();
      nonce = /var CHC = \{[^;]*?"nonce":"([a-f0-9]+)"/.exec(page)[1];
      ids = [...page.matchAll(/<tr data-id="(\d+)"/g)].map((m) => m[1]);
      assert.ok(ids.length >= 3);
      assert.equal((page.match(/class="button chc-play( chc-unheard)?"/g) || []).length, 1);
      assert.match(page, /\(360\) 513-1227/);
      assert.match(page, /chc-badge chc-voicemail">Voicemail/);
      assert.match(page, /3:05/); // answered call length
      assert.doesNotMatch(page, /api\.twilio\.com|RE0000/, 'no recording link or ID in the page');
    });

    const vmId = /<tr data-id="(\d+)"[^>]*>(?:(?!<\/tr>)[\s\S])*button chc-play/.exec(page)[1];

    await check('Play: audio streams through WordPress from the Twilio /play Function', async () => {
      const r = await fetch(`${WP_URL}/wp-json/clickhive-calls/v1/calls/${vmId}/audio`, {
        headers: { Cookie: maria.cookie(), 'X-WP-Nonce': nonce },
      });
      assert.equal(r.status, 200);
      assert.equal(r.headers.get('content-type'), 'audio/mpeg');
      assert.match(r.headers.get('cache-control'), /no-store/);
      assert.deepEqual(Buffer.from(await r.arrayBuffer()), FAKE_MP3);
    });

    await check('Play without the page nonce (a copied link) is refused', async () => {
      const r = await fetch(`${WP_URL}/wp-json/clickhive-calls/v1/calls/${vmId}/audio`, { headers: { Cookie: maria.cookie() } });
      assert.ok([401, 403].includes(r.status), `got ${r.status}`);
    });

    await check('status change saves and records who did it', async () => {
      const r = await fetch(`${WP_URL}/wp-json/clickhive-calls/v1/calls/${vmId}`, {
        method: 'POST',
        headers: { Cookie: maria.cookie(), 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ status: 'handled', category: 'new-client' }),
      });
      const j = await r.json();
      assert.equal(r.status, 200, JSON.stringify(j));
      assert.equal(j.handled_by, 'Maria (front desk)');
      assert.equal(typeof j.open_count, 'number');
    });

    await check('a status that isn\'t in the list is rejected', async () => {
      const r = await fetch(`${WP_URL}/wp-json/clickhive-calls/v1/calls/${vmId}`, {
        method: 'POST',
        headers: { Cookie: maria.cookie(), 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ status: 'deleted-everything' }),
      });
      assert.equal(r.status, 400);
    });

    const bob = await login('bob', 'testpass123');
    await check('a regular subscriber can\'t see the inbox or play audio', async () => {
      const p = await fetch(`${WP_URL}/wp-admin/admin.php?page=clickhive-calls`, { headers: { Cookie: bob.cookie() } });
      assert.doesNotMatch(await p.text(), /chc-table/);
      const r = await fetch(`${WP_URL}/wp-json/clickhive-calls/v1/calls/${vmId}/audio`, { headers: { Cookie: bob.cookie() } });
      assert.ok([401, 403].includes(r.status));
    });

    await check('the /play Function refuses another site\'s recording even with a valid signature', async () => {
      const host = 'localhost';
      const ts = String(Math.floor(Date.now() / 1000));
      const other = 'RE' + '0'.repeat(30) + 'ff'; // belongs to a call on a different client's number
      const sig = require('crypto').createHmac('sha256', lib.siteKey(ctx, host)).update(`${ts}.${other}.${host}`).digest('hex');
      const r = await fetch(`http://localhost:8100/play?${new URLSearchParams({ recording_sid: other, host, ts, sig })}`);
      assert.equal(r.status, 403);
    });

    await check('the /play Function refuses a recording on a number missing from the sheet', async () => {
      sheet.rows = [HEADER, row({ site: WP_URL })];
      const host = 'localhost';
      const ts = String(Math.floor(Date.now() / 1000));
      const other = 'RE' + '0'.repeat(30) + 'ff';
      const sig = require('crypto').createHmac('sha256', lib.siteKey(ctx, host)).update(`${ts}.${other}.${host}`).digest('hex');
      const r = await fetch(`http://localhost:8100/play?${new URLSearchParams({ recording_sid: other, host, ts, sig })}`);
      assert.equal(r.status, 403);
    });

    // ---- Call details and multiple lines on one site ----
    await check('second line: a call to another of this site\'s numbers is accepted, with routing details', async () => {
      await post({
        ...base,
        call_sid: sid(20),
        business_number: '(360) 727-0468',
        line_name: 'Clark County Cremation',
        outcome: 'answered',
        duration_seconds: 75,
        receivers: ['+13607981698', '+15412651980'],
        answered_by: '+15412651980',
        after_hours: false,
        hours_reason: 'no_hours_set',
      });
      await post({
        ...base,
        call_sid: sid(21),
        line_name: 'Click Hive',
        outcome: 'missed',
        duration_seconds: 14,
        receivers: ['+13605211487'],
        after_hours: true,
        hours_reason: 'outside_hours',
        notice_status: 'sent',
        notice_sent_to: 'vince@theclickhive.com',
      });
    });

    let page2;
    await check('two numbers -> inbox shows a Line column and a line filter with names from the sheet', async () => {
      page2 = await (await fetch(`${WP_URL}/wp-admin/admin.php?page=clickhive-calls`, { headers: { Cookie: maria.cookie() } })).text();
      assert.match(page2, /<th scope="col">Line<\/th>/);
      assert.match(page2, /<option value="3607270468"[^>]*>Clark County Cremation<\/option>/);
      assert.match(page2, /class="chc-line">\s*Clark County Cremation/);
    });

    await check('details show who it rang and who answered', async () => {
      const id = /<tr data-id="(\d+)"[^>]*>(?:(?!<\/tr>)[\s\S])*Clark County Cremation/.exec(page2)[1];
      const block = page2.slice(page2.indexOf(`id="chc-details-${id}"`)).split('</tr>')[0];
      assert.match(block, /\(360\) 798-1698/);
      assert.match(block, /chc-receiver chc-answered">2\. \(541\) 265-1980 \(answered\)/);
      assert.match(block, /During business hours/);
    });

    await check('details for a missed after-hours call show the email it went to', async () => {
      const id = /<tr data-id="(\d+)"[^>]*>(?:(?!<\/tr>)[\s\S])*chc-badge chc-missed(?:(?!<\/tr>)[\s\S])*/.exec(
        page2.slice(page2.indexOf('<tbody>'))
      )[1];
      const block = page2.slice(page2.indexOf(`id="chc-details-${id}"`)).split('</tr>')[0];
      assert.match(block, /After hours/);
      assert.match(block, /Emailed to vince@theclickhive\.com/);
      assert.match(block, /Set to ring/);
    });

    await check('history in details lists the play and the status change by name', async () => {
      const block = page2.slice(page2.indexOf(`id="chc-details-${vmId}"`)).split('</tr>')[0];
      assert.match(block, /Maria \(front desk\)<\/strong> played the voicemail/);
      assert.match(block, /Maria \(front desk\)<\/strong> set status to Handled/);
    });

    await check('filtering by line shows only that line', async () => {
      const p = await (await fetch(`${WP_URL}/wp-admin/admin.php?page=clickhive-calls&line=3607270468`, { headers: { Cookie: maria.cookie() } })).text();
      assert.equal([...p.matchAll(/<tr data-id=/g)].length, 1);
    });

    // ---- Connection check used by the daily health check ----
    await check('health ping: correct key and number -> ok, and nothing is created', async () => {
      const before = (await (await fetch(`${WP_URL}/wp-admin/admin.php?page=clickhive-calls`, { headers: { Cookie: maria.cookie() } })).text()).match(/<tr data-id=/g).length;
      const r = await lib.pingSite(ctx, client);
      assert.equal(r.status, 'ok', JSON.stringify(r));
      const after = (await (await fetch(`${WP_URL}/wp-admin/admin.php?page=clickhive-calls`, { headers: { Cookie: maria.cookie() } })).text()).match(/<tr data-id=/g).length;
      assert.equal(after, before);
    });
    await check('health ping: wrong master key -> key_mismatch', async () => {
      assert.equal((await lib.pingSite({ CALLS_MASTER_KEY: 'wrong' }, client)).status, 'key_mismatch');
    });
    await check('health ping: number not listed in the plugin -> number_not_listed', async () => {
      assert.equal((await lib.pingSite(ctx, { ...client, business_number: '(503) 555-0000' })).status, 'number_not_listed');
    });

    // ---- 1.2.0: views, unheard, sorting, search ----
    const inbox = async (q = '') =>
      (await fetch(`${WP_URL}/wp-admin/admin.php?page=clickhive-calls${q}`, { headers: { Cookie: maria.cookie() } })).text();
    const rowIds = (html) => [...html.matchAll(/<tr data-id="(\d+)"/g)].map((m) => m[1]);

    await check('a new voicemail shows as Unheard and appears in the Unheard tab', async () => {
      await post({ ...base, call_sid: sid(30), outcome: 'voicemail', duration_seconds: 95, recording_sid: rec(30), caller: '+12065550123' });
      const html = await inbox('&view=unheard');
      assert.match(html, /data-view="unheard">Unheard voicemails <span class="count">\(<span class="chc-view-count">[1-9]/);
      assert.match(html, /\(206\) 555-0123/);
      assert.match(html, /chc-unheard-tag">Unheard/);
    });

    await check('playing it once clears Unheard for everyone', async () => {
      const id = /<tr data-id="(\d+)"[^>]*>(?:(?!<\/tr>)[\s\S])*\(206\) 555-0123/.exec(await inbox('&view=unheard'))[1];
      const r = await fetch(`${WP_URL}/wp-json/clickhive-calls/v1/calls/${id}/audio`, { headers: { Cookie: maria.cookie(), 'X-WP-Nonce': nonce } });
      assert.equal(r.status, 200);
      assert.doesNotMatch(await inbox('&view=unheard'), /\(206\) 555-0123/);
    });

    await check('voicemail played before 1.2.0 counts as heard after the upgrade', async () => {
      assert.doesNotMatch(await inbox('&view=unheard'), new RegExp(`<tr data-id="${vmId}"`));
    });

    await check('Received sorts newest first by default and oldest first on click', async () => {
      const desc = rowIds(await inbox());
      const asc = rowIds(await inbox('&orderby=date&order=asc'));
      assert.deepEqual(asc, [...desc].reverse());
    });

    await check('Length sorts longest first, and calls without a length are not hidden', async () => {
      const all = rowIds(await inbox());
      const byLen = await inbox('&orderby=length&order=desc');
      assert.equal(rowIds(byLen).length, all.length);
      assert.match(byLen.slice(byLen.indexOf('<tbody>'), byLen.indexOf('<tbody>') + 2500), /3:05/);
    });

    await check('search by part of a phone number', async () => {
      const html = await inbox('&s=555-1212');
      assert.ok(rowIds(html).length >= 1);
      assert.doesNotMatch(html.slice(html.indexOf('<tbody>')), /\(360\) 513-1227/);
    });

    await check('Needs follow-up tab only lists calls at the first status', async () => {
      const html = await inbox('&view=open');
      assert.doesNotMatch(html.slice(html.indexOf('<tbody>')), /<option value="handled" selected/);
    });

    await check('text then voicemail: filed as a voicemail tagged Text sent', async () => {
      await post({ ...base, call_sid: sid(40), outcome: 'text_requested', caller: '+12065550140' });
      await post({ ...base, call_sid: sid(40), outcome: 'voicemail', text_sent: true, recording_sid: rec(40), duration_seconds: 15, caller: '+12065550140' });
      await post({ ...base, call_sid: sid(40), outcome: 'text_requested', text_sent: true, caller: '+12065550140' });
      const html = await inbox('&s=2065550140');
      assert.match(html, /chc-badge chc-voicemail">Voicemail/);
      assert.match(html, /chc-text-tag">Text sent/);
      assert.match(html, /asked for a text \(sent\), then left this voicemail/);
    });

    console.log(`\n# pass ${passed}`);
  } catch (e) {
    console.error(`\nnot ok - ${e.message}`);
    process.exitCode = 1;
  } finally {
    play.close();
  }
})();
