/**
 * eBetStream Arena — Real-time Game Server
 *
 * Gère les matchs 5v5 Arena en temps réel :
 *  - Zones A, B, C : contrôle par majorité de joueurs présents
 *  - Score : +1 pt/seconde par zone contrôlée
 *  - Victoire : 100 points OU fin du timer (8 min)
 *  - Rapport automatique du résultat à Laravel
 *
 * Routes WS :
 *   Joueur     → ws://host/arena/{matchId}?token=xxx
 *   Spectateur → ws://host/arena/{matchId}/watch
 *   Health     → http://host/health
 */

const WebSocket = require('ws');
const http      = require('http');
const https     = require('https');

const PORT            = process.env.PORT || process.env.ARENA_PORT || 8081;
const LARAVEL_API_URL = process.env.LARAVEL_API_URL   || 'http://localhost:8000/api';
const LARAVEL_TOKEN   = process.env.LARAVEL_INTERNAL_TOKEN || '';

const WIN_SCORE              = 100;
const MATCH_DURATION_SECONDS = 480; // 8 minutes
const TICK_MS                = 1000; // 1 seconde par tick
const COUNTDOWN_SECONDS      = 5;

// ─────────────────────────────────────────────────────────────────────────────
// ArenaRoom
// ─────────────────────────────────────────────────────────────────────────────
class ArenaRoom {
  constructor(matchId) {
    this.matchId    = matchId;
    this.status     = 'waiting'; // waiting | countdown | live | ended
    this.players    = new Map(); // userId → { ws, team, username, currentZone }
    this.spectators = new Map(); // specId → ws
    this.scores     = { team1: 0, team2: 0 };
    this.zones      = {
      A: { controller: null, occupants: { team1: new Set(), team2: new Set() } },
      B: { controller: null, occupants: { team1: new Set(), team2: new Set() } },
      C: { controller: null, occupants: { team1: new Set(), team2: new Set() } },
    };
    this.timeRemaining  = MATCH_DURATION_SECONDS;
    this.tickTimer      = null;
    this.countdownTimer = null;
  }

  publicState() {
    const zones = {};
    for (const [name, z] of Object.entries(this.zones)) {
      zones[name] = {
        controller: z.controller,
        occupants : { team1: z.occupants.team1.size, team2: z.occupants.team2.size },
      };
    }
    const players = {};
    for (const [uid, p] of this.players) {
      players[uid] = { team: p.team, username: p.username, zone: p.currentZone };
    }
    return {
      matchId      : this.matchId,
      status       : this.status,
      scores       : { ...this.scores },
      zones,
      timeRemaining: this.timeRemaining,
      players,
    };
  }
}

const arenaRooms = new Map();

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────
function send(ws, obj) {
  if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
}

function broadcast(room, obj, excludeUserId = null) {
  for (const [uid, p] of room.players) {
    if (uid !== excludeUserId) send(p.ws, obj);
  }
  for (const [, ws] of room.spectators) send(ws, obj);
}

function genId() {
  return Math.random().toString(36).slice(2, 10);
}

// ─────────────────────────────────────────────────────────────────────────────
// Laravel API
// ─────────────────────────────────────────────────────────────────────────────
function apiRequest(method, path, body = null) {
  return new Promise((resolve) => {
    const url     = `${LARAVEL_API_URL}${path}`;
    const isHttps = url.startsWith('https');
    const lib     = isHttps ? https : http;
    const bodyStr = body ? JSON.stringify(body) : null;

    const options = {
      method,
      headers: {
        'Authorization'     : `Bearer ${LARAVEL_TOKEN}`,
        'Content-Type'      : 'application/json',
        'Accept'            : 'application/json',
        'X-Internal-Request': '1',
        ...(bodyStr ? { 'Content-Length': Buffer.byteLength(bodyStr) } : {}),
      },
    };

    const req = lib.request(url, options, (res) => {
      let data = '';
      res.on('data', (c) => { data += c; });
      res.on('end', () => { try { resolve(JSON.parse(data)); } catch { resolve(null); } });
    });
    req.on('error', (e) => { console.error(`[API] ${method} ${path}:`, e.message); resolve(null); });
    req.setTimeout(5000, () => { req.destroy(); resolve(null); });
    if (bodyStr) req.write(bodyStr);
    req.end();
  });
}

async function verifyToken(token) {
  if (!token) return null;
  const url     = `${LARAVEL_API_URL}/token/verify`;
  const isHttps = url.startsWith('https');
  const lib     = isHttps ? https : http;
  return new Promise((resolve) => {
    const req = lib.get(url, {
      headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' },
    }, (res) => {
      let data = '';
      res.on('data', (c) => { data += c; });
      res.on('end', () => {
        try {
          const json = JSON.parse(data);
          resolve(json?.id ? { id: String(json.id), username: json.username || `user_${json.id}` } : null);
        } catch { resolve(null); }
      });
    });
    req.on('error', () => resolve(null));
    req.setTimeout(5000, () => { req.destroy(); resolve(null); });
  });
}

async function getPlayerTeam(matchId, userId) {
  const data = await apiRequest('GET', `/internal/arena/${matchId}/player/${userId}`);
  return data?.team ?? null;
}

async function reportMatchResult(matchId, winner, scores) {
  const res = await apiRequest('POST', `/internal/arena/${matchId}/result`, {
    winner_team : winner,
    team1_score : scores.team1,
    team2_score : scores.team2,
  });
  if (res?.success) {
    console.log(`[ARENA] Match ${matchId} result reported to Laravel ✓`);
  } else {
    console.warn(`[ARENA] Match ${matchId} result report failed:`, res);
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Game Logic
// ─────────────────────────────────────────────────────────────────────────────
function resolveZoneController(zone) {
  const t1 = zone.occupants.team1.size;
  const t2 = zone.occupants.team2.size;
  if (t1 > t2) return 'team1';
  if (t2 > t1) return 'team2';
  return zone.controller; // égalité → conserve le contrôleur actuel
}

function tick(room) {
  if (room.status !== 'live') return;

  room.timeRemaining--;
  const zoneUpdates = [];

  for (const [zoneName, zone] of Object.entries(room.zones)) {
    const prev = zone.controller;
    zone.controller = resolveZoneController(zone);

    if (zone.controller !== prev) {
      zoneUpdates.push({ zone: zoneName, team: zone.controller });
    }

    if (zone.controller === 'team1') room.scores.team1++;
    else if (zone.controller === 'team2') room.scores.team2++;
  }

  // Notifier les changements de contrôle
  for (const ev of zoneUpdates) {
    broadcast(room, { type: 'zone_captured', zone: ev.zone, team: ev.team, scores: { ...room.scores } });
  }

  // State update global chaque seconde
  broadcast(room, {
    type         : 'state_update',
    scores       : { ...room.scores },
    timeRemaining: room.timeRemaining,
    zones        : Object.fromEntries(
      Object.entries(room.zones).map(([n, z]) => [n, {
        controller: z.controller,
        occupants : { team1: z.occupants.team1.size, team2: z.occupants.team2.size },
      }])
    ),
  });

  // Conditions de victoire
  if (room.scores.team1 >= WIN_SCORE || room.scores.team2 >= WIN_SCORE || room.timeRemaining <= 0) {
    endMatch(room);
  }
}

function startMatch(room) {
  room.status = 'live';
  broadcast(room, { type: 'match_start', matchState: room.publicState() });
  room.tickTimer = setInterval(() => tick(room), TICK_MS);
  console.log(`[ARENA] Match #${room.matchId} STARTED`);
}

function endMatch(room) {
  if (room.status === 'ended') return;
  room.status = 'ended';

  clearInterval(room.tickTimer);
  room.tickTimer = null;

  let winner;
  if (room.scores.team1 > room.scores.team2)      winner = 'team1';
  else if (room.scores.team2 > room.scores.team1) winner = 'team2';
  else                                              winner = 'draw';

  broadcast(room, {
    type  : 'match_end',
    winner,
    scores: { ...room.scores },
    reason: room.timeRemaining <= 0 ? 'time' : 'score',
  });

  console.log(`[ARENA] Match #${room.matchId} ENDED — winner: ${winner} | ${room.scores.team1}–${room.scores.team2}`);

  reportMatchResult(room.matchId, winner, room.scores).catch(() => {});

  // Nettoyer la room après 30s
  setTimeout(() => arenaRooms.delete(room.matchId), 30_000);
}

function tryAutoStart(room) {
  if (room.status !== 'waiting') return;

  const hasTeam1 = [...room.players.values()].some(p => p.team === 'team1');
  const hasTeam2 = [...room.players.values()].some(p => p.team === 'team2');
  if (!hasTeam1 || !hasTeam2) return;

  room.status = 'countdown';
  let secs = COUNTDOWN_SECONDS;
  broadcast(room, { type: 'countdown', seconds: secs });

  room.countdownTimer = setInterval(() => {
    secs--;
    if (secs <= 0) {
      clearInterval(room.countdownTimer);
      startMatch(room);
    } else {
      broadcast(room, { type: 'countdown', seconds: secs });
    }
  }, TICK_MS);
}

function removePlayerFromZone(room, userId, team) {
  for (const zone of Object.values(room.zones)) {
    zone.occupants[team]?.delete(userId);
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// HTTP + WebSocket Server
// ─────────────────────────────────────────────────────────────────────────────
const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    const totalPlayers = [...arenaRooms.values()].reduce((s, r) => s + r.players.size, 0);
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok', activeMatches: arenaRooms.size, totalPlayers }));
    return;
  }
  res.writeHead(200);
  res.end('eBetStream Arena Game Server');
});

const wss = new WebSocket.Server({ server, perMessageDeflate: false });

wss.on('connection', async (ws, req) => {
  const [rawPath, rawQuery] = req.url.split('?');
  const parts   = rawPath.split('/').filter(Boolean); // ['arena', '{matchId}'] ou ['arena', '{matchId}', 'watch']
  const params  = new URLSearchParams(rawQuery || '');
  const token   = params.get('token');

  const section     = parts[0]; // 'arena'
  const matchId     = parts[1]; // numérique
  const isSpectator = parts[2] === 'watch';

  if (section !== 'arena' || !matchId || isNaN(Number(matchId))) {
    ws.close(1008, 'URL invalide. Utilise /arena/{matchId}?token=xxx');
    return;
  }

  // ── SPECTATEUR ────────────────────────────────────────────────────────────
  if (isSpectator) {
    if (!arenaRooms.has(matchId)) arenaRooms.set(matchId, new ArenaRoom(matchId));
    const room   = arenaRooms.get(matchId);
    const specId = genId();
    room.spectators.set(specId, ws);

    send(ws, { type: 'spectator_joined', matchState: room.publicState() });
    console.log(`[SPECTATOR] match#${matchId} (${room.spectators.size} spectateurs)`);

    ws.on('close', () => {
      room.spectators.delete(specId);
      if (room.players.size === 0 && room.spectators.size === 0 && room.status === 'waiting') {
        arenaRooms.delete(matchId);
      }
    });
    ws.on('error', (e) => console.error(`[SPECTATOR] match#${matchId}:`, e.message));
    return;
  }

  // ── JOUEUR ────────────────────────────────────────────────────────────────
  if (!token) { ws.close(1008, 'Token requis'); return; }

  const userInfo = await verifyToken(token);
  if (!userInfo) { ws.close(1008, 'Token invalide'); return; }

  const userId   = userInfo.id;
  const username = userInfo.username;

  const team = await getPlayerTeam(matchId, userId);
  if (!team) {
    ws.close(1008, `Joueur ${userId} non inscrit au match #${matchId}`);
    return;
  }

  if (!arenaRooms.has(matchId)) arenaRooms.set(matchId, new ArenaRoom(matchId));
  const room = arenaRooms.get(matchId);

  if (room.status === 'ended') { ws.close(1000, 'Match terminé'); return; }

  // Déconnecter l'ancienne session si reconnexion
  if (room.players.has(userId)) {
    const old = room.players.get(userId);
    removePlayerFromZone(room, userId, team);
    old.ws.close(1000, 'Reconnexion');
  }

  room.players.set(userId, { ws, team, username, currentZone: null });
  console.log(`[PLAYER] ${username}(${userId}) → match#${matchId} / ${team}`);

  broadcast(room, { type: 'player_joined', userId, username, team, playerCount: room.players.size }, userId);

  send(ws, { type: 'joined', userId, team, username, matchState: room.publicState() });

  tryAutoStart(room);

  // ── Messages joueur → serveur ─────────────────────────────────────────────
  ws.on('message', (raw) => {
    let msg;
    try { msg = JSON.parse(raw); } catch { return; }

    const player = room.players.get(userId);
    if (!player) return;

    switch (msg.type) {

      case 'zone_enter': {
        const zone = String(msg.zone || '').toUpperCase();
        if (!['A', 'B', 'C'].includes(zone)) break;

        // Quitter l'ancienne zone
        if (player.currentZone && player.currentZone !== zone) {
          room.zones[player.currentZone]?.occupants[player.team].delete(userId);
        }

        player.currentZone = zone;
        room.zones[zone].occupants[player.team].add(userId);

        broadcast(room, { type: 'player_zone_update', userId, username, team: player.team, zone });
        break;
      }

      case 'zone_leave': {
        if (player.currentZone) {
          room.zones[player.currentZone]?.occupants[player.team].delete(userId);
          player.currentZone = null;
          broadcast(room, { type: 'player_zone_update', userId, username, team: player.team, zone: null });
        }
        break;
      }

      case 'chat': {
        const text = String(msg.text || '').trim().slice(0, 200);
        if (text) {
          broadcast(room, { type: 'chat', userId, username, team: player.team, text, ts: Date.now() });
        }
        break;
      }

      case 'ping':
        send(ws, { type: 'pong', ts: Date.now() });
        break;
    }
  });

  ws.on('close', () => {
    removePlayerFromZone(room, userId, team);
    room.players.delete(userId);
    console.log(`[PLAYER] ${username} quitté match#${matchId} (${room.players.size} restants)`);

    broadcast(room, { type: 'player_left', userId, username, team, playerCount: room.players.size });

    if (room.status === 'live' && room.players.size === 0) endMatch(room);
    if (room.status === 'waiting' && room.players.size === 0 && room.spectators.size === 0) {
      arenaRooms.delete(matchId);
    }
  });

  ws.on('error', (e) => console.error(`[PLAYER] match#${matchId} user:${userId}:`, e.message));
});

server.listen(PORT, () => {
  const host = process.env.RAILWAY_PUBLIC_DOMAIN
    ? `wss://${process.env.RAILWAY_PUBLIC_DOMAIN}`
    : `ws://localhost:${PORT}`;
  console.log(`✅ eBetStream Arena Game Server → port ${PORT}`);
  console.log(`   Joueur     : ${host}/arena/{matchId}?token=xxx`);
  console.log(`   Spectateur : ${host}/arena/{matchId}/watch`);
  console.log(`   Health     : http://localhost:${PORT}/health`);
  console.log(`   Win score  : ${WIN_SCORE} pts  |  Durée : ${MATCH_DURATION_SECONDS}s`);
});

process.on('SIGTERM', () => {
  for (const room of arenaRooms.values()) {
    if (room.status === 'live') endMatch(room);
  }
  server.close(() => process.exit(0));
});
