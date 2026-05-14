/**
 * eBetStream — WebRTC Signaling Server
 *
 * Routes :
 *   Streamer → wss://host/stream/{id}?token=xxx
 *   Viewer   → wss://host/watch/{id}?token=xxx
 *   Health   → https://host/health
 *
 * Déploiement Railway :
 *   - PORT est injecté automatiquement par Railway
 *   - Variables d'env à configurer : LARAVEL_API_URL, LARAVEL_INTERNAL_TOKEN
 */

const WebSocket = require('ws');
const http      = require('http');
const https     = require('https');

const PORT            = process.env.PORT || 8080;
const LARAVEL_API_URL = process.env.LARAVEL_API_URL        || 'https://api.ebetstream.live/api';
const LARAVEL_TOKEN   = process.env.LARAVEL_INTERNAL_TOKEN || '';

const rooms = new Map();

function getOrCreateRoom(streamId) {
  if (!rooms.has(streamId)) rooms.set(streamId, { streamer: null, viewers: new Map() });
  return rooms.get(streamId);
}

function deleteRoomIfEmpty(streamId) {
  const room = rooms.get(streamId);
  if (room && !room.streamer && room.viewers.size === 0) {
    rooms.delete(streamId);
  }
}

function send(ws, obj) {
  if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
}

function genViewerId() {
  return Math.random().toString(36).slice(2, 10);
}

function verifyToken(token) {
  return new Promise((resolve) => {
    if (!token) { resolve(null); return; }

    const url     = `${LARAVEL_API_URL}/token/verify`;
    const isHttps = url.startsWith('https');
    const lib     = isHttps ? https : http;

    const req = lib.get(url, {
      headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' },
    }, (res) => {
      let data = '';
      res.on('data', (c) => { data += c; });
      res.on('end', () => {
        try {
          const json = JSON.parse(data);
          resolve(json?.id ?? ('guest_' + token.slice(0, 8)));
        } catch (e) {
          resolve('guest_' + token.slice(0, 8));
        }
      });
    });

    req.on('error', (err) => {
      console.error('[Auth] Erreur réseau:', err.message);
      resolve('guest_' + token.slice(0, 8));
    });

    req.setTimeout(5000, () => {
      req.destroy();
      resolve('guest_' + token.slice(0, 8));
    });
  });
}

function notifyViewerCount(streamId, count) {
  const url     = `${LARAVEL_API_URL}/internal/streams/${streamId}/viewer-count`;
  const isHttps = url.startsWith('https');
  const lib     = isHttps ? https : http;
  const body    = JSON.stringify({ count });

  try {
    const req = lib.request(url, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${LARAVEL_TOKEN}`,
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(body),
        'X-Internal-Request': '1',
      },
    }, (res) => { res.resume(); });
    req.on('error', () => {});
    req.write(body);
    req.end();
  } catch (e) {}
}

// ── HTTP server ───────────────────────────────────────────────────────────────
const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok', rooms: rooms.size }));
    return;
  }
  res.writeHead(200);
  res.end('eBetStream WebRTC Signaling Server');
});

// ── WebSocket server ──────────────────────────────────────────────────────────
const wss = new WebSocket.Server({ server, perMessageDeflate: false });

wss.on('connection', async (ws, req) => {
  const urlParts  = req.url.split('?');
  const pathParts = urlParts[0].split('/').filter(Boolean);
  const role      = pathParts[0];
  const streamId  = pathParts[1];
  const params    = new URLSearchParams(urlParts[1] || '');
  const token     = params.get('token');

  if (!streamId || isNaN(Number(streamId)) || !['stream', 'watch'].includes(role)) {
    ws.close(1008, 'Invalid URL');
    return;
  }

  if (role === 'stream' && !token) {
    ws.close(1008, 'Token required for streaming');
    return;
  }

  let userId;
  if (!token) {
    userId = 'guest_' + Math.random().toString(36).slice(2, 10);
  } else {
    userId = await verifyToken(token);
    if (!userId && role === 'stream') {
      ws.close(1008, 'Unauthorized');
      return;
    }
    if (!userId) {
      userId = 'guest_' + Math.random().toString(36).slice(2, 10);
    }
  }

  console.log(`[${role.toUpperCase()}] stream#${streamId} user:${userId}`);

  // ── STREAMER ────────────────────────────────────────────────────────────────
  if (role === 'stream') {
    const room = getOrCreateRoom(streamId);

    if (room.streamer && room.streamer !== ws) {
      room.streamer.close(1000, 'Replaced');
    }
    room.streamer = ws;

    send(ws, { type: 'ready', viewerCount: room.viewers.size });

    room.viewers.forEach((vws, viewerId) => {
      send(ws, { type: 'viewer-joined', viewerId, count: room.viewers.size });
    });

    ws.on('message', (raw) => {
      let msg;
      try { msg = JSON.parse(raw); } catch { return; }

      switch (msg.type) {
        case 'offer': {
          const v = room.viewers.get(msg.viewerId);
          if (v) send(v, { type: 'offer', sdp: msg.sdp });
          break;
        }
        case 'ice-candidate': {
          const v = room.viewers.get(msg.viewerId);
          if (v) send(v, { type: 'ice-candidate', candidate: msg.candidate });
          break;
        }
      }
    });

    ws.on('close', () => {
      console.log(`[STREAMER] déconnecté stream#${streamId}`);
      if (room.streamer === ws) room.streamer = null;
      room.viewers.forEach((vws) => send(vws, { type: 'stream-ended' }));
      deleteRoomIfEmpty(streamId);
    });

    ws.on('error', (err) => console.error(`[STREAMER] error stream#${streamId}:`, err.message));
  }

  // ── VIEWER ──────────────────────────────────────────────────────────────────
  else if (role === 'watch') {
    const room     = getOrCreateRoom(streamId);
    const viewerId = genViewerId();
    room.viewers.set(viewerId, ws);

    const count = room.viewers.size;
    notifyViewerCount(streamId, count);

    if (room.streamer) {
      send(room.streamer, { type: 'viewer-joined', viewerId, count });
      send(ws, { type: 'waiting', message: 'Connexion au streamer en cours...' });
    } else {
      send(ws, { type: 'waiting', message: "Le stream n'est pas encore démarré." });
    }

    ws.on('message', (raw) => {
      let msg;
      try { msg = JSON.parse(raw); } catch { return; }

      switch (msg.type) {
        case 'answer':
          if (room.streamer) {
            send(room.streamer, { type: 'answer', viewerId, sdp: msg.sdp });
          }
          break;

        case 'ice-candidate':
          if (room.streamer) {
            send(room.streamer, { type: 'ice-candidate', viewerId, candidate: msg.candidate });
          }
          break;

        case 'request-offer':
          if (room.streamer) {
            send(room.streamer, { type: 'viewer-joined', viewerId, count: room.viewers.size });
          }
          break;

        case 'chat-message':
          if (msg.text && msg.text.length <= 500) {
            const chatMsg = {
              type: 'chat-message',
              userId,
              username: msg.username || 'Guest',
              text: msg.text,
              ts: Date.now(),
            };
            room.viewers.forEach((vws, vid) => {
              if (vid !== viewerId) send(vws, chatMsg);
            });
            send(room.streamer, chatMsg);
          }
          break;
      }
    });

    ws.on('close', () => {
      room.viewers.delete(viewerId);
      const newCount = room.viewers.size;
      if (room.streamer) {
        send(room.streamer, { type: 'viewer-left', viewerId, count: newCount });
      }
      notifyViewerCount(streamId, newCount);
      deleteRoomIfEmpty(streamId);
    });

    ws.on('error', (err) => console.error(`[VIEWER] error stream#${streamId}:`, err.message));
  }
});

server.listen(PORT, () => {
  const host = process.env.RAILWAY_PUBLIC_DOMAIN
    ? `wss://${process.env.RAILWAY_PUBLIC_DOMAIN}`
    : `ws://localhost:${PORT}`;
  console.log(`✅ eBetStream Signaling Server → port ${PORT}`);
  console.log(`   Streamer : ${host}/stream/{id}?token=xxx`);
  console.log(`   Viewer   : ${host}/watch/{id}?token=xxx`);
  console.log(`   Health   : ${host.replace('wss://', 'https://').replace('ws://', 'http:/')}/health`);
});

process.on('SIGTERM', () => {
  rooms.forEach((room) => {
    room.streamer?.close();
    room.viewers.forEach((ws) => ws.close());
  });
  server.close(() => process.exit(0));
});
