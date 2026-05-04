/**
 * eBetStream — WebRTC Signaling Server
 *
 * Routes :
 *   Streamer → ws://host/stream/{id}?token=xxx
 *   Viewer   → ws://host/watch/{id}?token=xxx
 *   Health   → http://host/health
 *
 * Déploiement o2switch :
 *   - Placer ce dossier dans le répertoire home de l'hébergement
 *   - Configurer via cPanel → "Setup Node.js App"
 *   - Application startup file : stream-server.js
 *   - PORT est injecté automatiquement par Phusion Passenger
 */

const WebSocket = require('ws');
const http      = require('http');
const https     = require('https');

// Passenger (o2switch) injecte process.env.PORT automatiquement
// STREAM_WS_PORT est le fallback pour un VPS classique (ex: port 8082)
const PORT            = process.env.PORT || process.env.STREAM_WS_PORT || 8082;
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
          if (json?.id) {
            resolve(json.id);
          } else {
            resolve('guest_' + token.slice(0, 8));
          }
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

// Disable Nginx proxy buffering/compression for WebSocket connections
wss.on('headers', (headers) => {
  headers.push('X-Accel-Buffering: no');
});

wss.on('connection', async (ws, req) => {
  const urlParts  = req.url.split('?');
  // Supprimer le préfixe /ws si présent (configuration cPanel : wss://api.ebetstream.live/ws)
  const rawPath   = urlParts[0].replace(/^\/ws/, '');
  const pathParts = rawPath.split('/').filter(Boolean);
  const role      = pathParts[0];
  const streamId  = pathParts[1];
  const params    = new URLSearchParams(urlParts[1] || '');
  const token     = params.get('token');

  if (!streamId || isNaN(Number(streamId)) || !['stream', 'watch'].includes(role)) {
    ws.close(1008, 'Invalid URL');
    return;
  }

  // Les streamers DOIVENT être authentifiés
  // Les viewers peuvent être non-connectés → guest ID
  if (role === 'stream' && !token) {
    ws.close(1008, 'Token required for streaming');
    return;
  }

  let userId;
  if (!token) {
    // Viewer non connecté : guest anonyme
    userId = 'guest_' + Math.random().toString(36).slice(2, 10);
  } else {
    userId = await verifyToken(token);
    if (!userId && role === 'stream') {
      ws.close(1008, 'Unauthorized');
      return;
    }
    if (!userId) {
      // Viewer avec token invalide → guest quand même
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

    if (room.viewers.size > 0) {
      room.viewers.forEach((vws, viewerId) => {
        send(ws, { type: 'viewer-joined', viewerId, count: room.viewers.size });
      });
    }

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

        // Broadcast d'un message de chat à tous les viewers de la room
        case 'chat-message':
          if (msg.text && msg.text.length <= 500) {
            const chatMsg = {
              type: 'chat-message',
              userId,
              username: msg.username || 'Guest',
              text: msg.text,
              ts: Date.now(),
            };
            // Envoyer à tous les viewers sauf l'expéditeur
            room.viewers.forEach((vws, vid) => {
              if (vid !== viewerId) send(vws, chatMsg);
            });
            // Envoyer aussi au streamer pour qu'il voie le chat
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
  console.log(`✅ eBetStream Signaling Server → port ${PORT}`);
  console.log(`   Streamer : wss://api.ebetstream.live/ws/stream/{id}?token=xxx`);
  console.log(`   Viewer   : wss://api.ebetstream.live/ws/watch/{id}?token=xxx`);
  console.log(`   Health   : https://api.ebetstream.live/ws/health`);
});

process.on('SIGTERM', () => {
  rooms.forEach((room) => {
    room.streamer?.close();
    room.viewers.forEach((ws) => ws.close());
  });
  server.close(() => process.exit(0));
});
