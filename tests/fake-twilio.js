// A fake Twilio runtime that serves the real /play Function on port 8100.
// Used by e2e.js, and runnable on its own:  node tests/fake-twilio.js
const http = require('node:http');
const path = require('node:path');
const VOICE = path.resolve(__dirname, '../../clickhive-voice');
const { makeWorld, sheet, row, HEADER } = require(path.join(VOICE, 'tests/harness.js'));
const MASTER = 'test-master-key-0123456789';
const FAKE_MP3 = Buffer.from('ID3\x03\x00\x00\x00\x00\x00\x00fake-mp3-audio-bytes-for-test', 'binary');
const nativeFetch = global.fetch;
// ---------- A fake Twilio runtime serving the real /play Function ----------
function startPlayServer() {
  const playPath = path.join(VOICE, 'functions/play.js');
  return new Promise((resolve) => {
    const server = http.createServer(async (req, res) => {
      const url = new URL(req.url, 'http://localhost:8100');
      const event = Object.fromEntries(url.searchParams);
      const { context } = makeWorld({ CALLS_MASTER_KEY: MASTER, ACCOUNT_SID: 'ACtest', AUTH_TOKEN: 'tok' });
      const realClient = context.getTwilioClient();
      context.getTwilioClient = () => ({
        recordings: (sid) => ({ fetch: async () => ({ sid, callSid: sid.endsWith('ff') ? 'CAother' : 'CAmine' }) }),
        calls: (sid) => ({ fetch: async () => ({ sid, to: sid === 'CAother' ? '+15035550000' : '+13603090950' }) }),
        sync: realClient.sync,
      });
      global.fetch = async (u, o) =>
        String(u).startsWith('https://api.twilio.com/')
          ? { ok: true, status: 200, arrayBuffer: async () => FAKE_MP3 }
          : nativeFetch(u, o);
      delete require.cache[playPath];
      require(playPath).handler(context, event, (err, r) => {
        global.fetch = nativeFetch;
        if (err) {
          res.writeHead(500);
          return res.end(String(err));
        }
        res.writeHead(r.statusCode, r.headers);
        res.end(Buffer.isBuffer(r.body) ? r.body : JSON.stringify(r.body));
      });
    });
    server.listen(8100, () => resolve(server));
  });
}


module.exports = { startPlayServer, FAKE_MP3, MASTER };

if (require.main === module) {
  sheet.rows = [HEADER, row({ site: process.env.WP_URL || 'http://localhost:8099' })];
  startPlayServer().then(() => console.log('fake Twilio /play listening on 8100'));
}
