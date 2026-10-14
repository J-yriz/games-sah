/**
 * WSS multi-client verification (staging only).
 *
 * Two independent sessions subscribe to private rooms.* and poker-hands.*
 * channels over real WSS, then the script waits for a push triggered by an
 * external actor. A third non-member session must be rejected at auth.
 *
 * Usage: node scripts/wss-multiclient.js <setup.json path> <ready-file> <result-file>
 * Env: BASE_URL (https://localhost), WS_URL (wss://localhost:8443),
 *      REVERB_KEY, STAGING_PASSWORD.
 */

const fs = await import('node:fs');

const [, , setupPath, readyFile, resultFile] = process.argv;
const setup = JSON.parse(fs.readFileSync(setupPath, 'utf8'));
const BASE = process.env.BASE_URL;
const WS = process.env.WS_URL;
const KEY = process.env.REVERB_KEY;
const PASSWORD = process.env.STAGING_PASSWORD;

const jar = {};
function cookies(res, who) {
  const set = res.headers.getSetCookie?.() ?? [];
  for (const c of set) {
    const [pair] = c.split(';');
    const [k, v] = pair.split('=');
    jar[who] = jar[who] || {};
    jar[who][k.trim()] = (v ?? '').trim();
  }
}
function cookieHeader(who) {
  return Object.entries(jar[who] || {}).map(([k, v]) => `${k}=${v}`).join('; ');
}

async function login(who, email) {
  // Prime cookies (XSRF-TOKEN) then POST login.
  const prime = await fetch(`${BASE}/login`, { redirect: 'manual' });
  cookies(prime, who);
  const xsrf = jar[who]?.['XSRF-TOKEN'];
  const body = new URLSearchParams({ email, password: PASSWORD });
  const res = await fetch(`${BASE}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookieHeader(who), 'X-XSRF-TOKEN': decodeURIComponent(xsrf ?? '') },
    body,
    redirect: 'manual',
  });
  cookies(res, who);
  if (res.status !== 302) throw new Error(`${who} login -> ${res.status}`);
}

async function authChannel(who, socketId, channel) {
  const res = await fetch(`${BASE}/broadcasting/auth`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      Cookie: cookieHeader(who),
      'X-XSRF-TOKEN': decodeURIComponent(jar[who]?.['XSRF-TOKEN'] ?? ''),
      Accept: 'application/json',
    },
    body: new URLSearchParams({ socket_id: socketId, channel_name: channel }),
  });
  const text = await res.text();
  return { status: res.status, body: text };
}

function connect(url) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(url);
    // Buffer every message from construction: connection_established can
    // arrive while we are still awaiting another socket's handshake.
    ws._buf = [];
    ws.addEventListener('message', (ev) => ws._buf.push(ev.data));
    ws.addEventListener('open', () => resolve(ws), { once: true });
    ws.addEventListener('error', (e) => reject(e), { once: true });
  });
}

const out = { pushes: [], authStranger: null, errors: [] };

try {
  await login('a', setup.users.a.email);
  await login('b', setup.users.b.email);
  await login('c', setup.users.c.email);

  const wsUrl = `${WS}/app/${KEY}?protocol=7&client=js&version=8.4.0&flash=false`;
  const wsA = await connect(wsUrl);
  const wsB = await connect(wsUrl);

  async function socketIdOf(ws) {
    for (const raw of ws._buf.splice(0)) {
      try {
        const msg = JSON.parse(raw);
        if (msg.event === 'pusher:connection_established') return JSON.parse(msg.data).socket_id;
      } catch { /* keep waiting */ }
    }
    return new Promise((resolve, reject) => {
      const t = setTimeout(() => reject(new Error('no connection_established')), 10000);
      const handler = (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.event === 'pusher:connection_established') {
          clearTimeout(t);
          ws.removeEventListener('message', handler);
          resolve(JSON.parse(msg.data).socket_id);
        }
      };
      ws.addEventListener('message', handler);
    });
  }

  // Auth tokens bind to the connection's own socket id: capture per socket.
  const [socketA, socketB] = await Promise.all([socketIdOf(wsA), socketIdOf(wsB)]);
  const socketId = socketA;

  // Non-member auth must be rejected (proves server-side authorization).
  const stranger = await authChannel('c', socketId, `private-rooms.${setup.room_id}`);
  out.authStranger = stranger.status;

  async function subscribe(ws, who, socket, channel) {
    const auth = await authChannel(who, socket, channel);
    if (auth.status !== 200) throw new Error(`${who} auth ${channel} -> ${auth.status}: ${auth.body}`);
    const { auth: token } = JSON.parse(auth.body);
    ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: token, channel } }));
    return new Promise((resolve, reject) => {
      const t = setTimeout(() => reject(new Error(`no subscribed ${channel}`)), 10000);
      const handler = (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.event === 'pusher_internal:subscription_succeeded' && JSON.parse(msg.data ?? '{}').channel === undefined) {
          // Generic success; resolve on first one then rely on event flow.
        }
        if (msg.event === 'pusher_internal:subscription_succeeded') {
          clearTimeout(t);
          ws.removeEventListener('message', handler);
          resolve(true);
        }
        if (msg.event === 'pusher:subscription_error') {
          clearTimeout(t);
          reject(new Error(`sub error ${channel}: ${ev.data}`));
        }
      };
      ws.addEventListener('message', handler);
    });
  }

  const seen = [];
  wsB.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.event === 'room.updated' || msg.event === 'poker.hand.updated') {
      seen.push({ event: msg.event, at: Date.now() });
    }
  });

  await subscribe(wsA, 'a', socketA, `private-rooms.${setup.room_id}`);
  await subscribe(wsB, 'b', socketB, `private-rooms.${setup.room_id}`);
  await subscribe(wsA, 'a', socketA, `private-poker-hands.${setup.hand_id}`);
  await subscribe(wsB, 'b', socketB, `private-poker-hands.${setup.hand_id}`);

  fs.writeFileSync(readyFile, JSON.stringify({ ready: true }));

  // Wait for the external poker action to push to B.
  const t0 = Date.now();
  while (seen.length === 0 && Date.now() - t0 < 45000) {
    await new Promise((r) => setTimeout(r, 500));
  }
  out.pushes = seen;
  out.waitMs = Date.now() - t0;
  wsA.close();
  wsB.close();
} catch (e) {
  out.errors.push(String(e?.message ?? e));
}

fs.writeFileSync(resultFile, JSON.stringify(out, null, 2));
console.log(JSON.stringify(out));
process.exit(out.errors.length === 0 && out.pushes.length > 0 && out.authStranger === 403 ? 0 : 1);
