import crypto from 'crypto';
import express from 'express';
import http from 'http';
import cors from 'cors';
import { Server as SocketIOServer } from 'socket.io';

const PORT = process.env.PORT ? Number(process.env.PORT) : 3001;
const HOST = process.env.HOST || '0.0.0.0';
// Shared secret must match backend REALTIME_SERVER_SECRET.
// Defaulting to 'change-me' keeps local/dev working out-of-the-box.
const SECRET = process.env.REALTIME_SERVER_SECRET || 'change-me';

// Comma-separated browser origins allowed to connect. Empty = allow any origin
// (previous behaviour). Production: https://hellomspace.com
const ALLOWED_ORIGINS = String(process.env.REALTIME_ALLOWED_ORIGINS || '')
  .split(',')
  .map((origin) => origin.trim())
  .filter(Boolean);
const CORS_ORIGIN = ALLOWED_ORIGINS.length > 0 ? ALLOWED_ORIGINS : true;

// When true, sockets without a valid token (issued by Laravel) are rejected.
// Anonymous sockets can join no room either way; they only receive global events.
const REQUIRE_AUTH = String(process.env.REALTIME_REQUIRE_AUTH || 'false').toLowerCase() === 'true';

if (SECRET === 'change-me') {
  // eslint-disable-next-line no-console
  console.warn('[realtime] WARNING: REALTIME_SERVER_SECRET is not set; using the public default "change-me". Set it in production.');
}

/**
 * Verify a token of the form base64url(payload).base64url(HMAC-SHA256(payloadPart, SECRET)).
 * Returns the payload ({ sub, rooms, exp }) or null.
 */
function verifyToken(token) {
  if (typeof token !== 'string' || !token.includes('.')) return null;

  const [payloadPart, signaturePart] = token.split('.', 2);
  const expected = crypto.createHmac('sha256', SECRET).update(payloadPart).digest();
  const given = Buffer.from(signaturePart, 'base64url');
  if (given.length !== expected.length || !crypto.timingSafeEqual(given, expected)) return null;

  let payload;
  try {
    payload = JSON.parse(Buffer.from(payloadPart, 'base64url').toString('utf8'));
  } catch {
    return null;
  }

  if (!payload || typeof payload.exp !== 'number' || payload.exp * 1000 < Date.now()) return null;
  if (!Array.isArray(payload.rooms)) return null;

  return payload;
}

const app = express();
app.use(express.json({ limit: '256kb' }));
app.use(cors({ origin: CORS_ORIGIN, credentials: false }));

app.get('/health', (_req, res) => {
  res.json({ ok: true, time: new Date().toISOString() });
});

app.post('/emit', (req, res) => {
  const headerSecret = String(req.header('X-RT-SECRET') || '');
  if (!SECRET || headerSecret !== SECRET) {
    return res.status(401).json({ ok: false });
  }

  const event = String(req.body?.event || '');
  const data = req.body?.data;
  const tenantId = req.body?.tenant_id;
  const room = req.body?.room;

  if (!event || typeof event !== 'string') {
    return res.status(422).json({ ok: false, error: 'event required' });
  }

  if (room && typeof room === 'string') {
    // Private room (e.g. "admins", "user_12"): only authenticated sockets are in it.
    io.to(room).emit(event, data ?? {});
  } else if (tenantId && typeof tenantId === 'number') {
    // Emit to tenant-specific room
    io.to(`tenant_${tenantId}`).emit(event, data ?? {});
  } else {
    // Emit to all (legacy global events)
    io.emit(event, data ?? {});
  }

  return res.json({ ok: true });
});

const server = http.createServer(app);
const io = new SocketIOServer(server, {
  cors: {
    origin: CORS_ORIGIN,
    methods: ['GET', 'POST']
  }
});

// Handshake: a token grants its private rooms; a bad token is always rejected.
io.use((socket, next) => {
  const token = socket.handshake.auth?.token;

  if (token) {
    const payload = verifyToken(token);
    if (!payload) {
      return next(new Error('unauthorized'));
    }
    socket.data.userId = payload.sub;
    socket.data.rooms = payload.rooms.filter((room) => typeof room === 'string');
    return next();
  }

  if (REQUIRE_AUTH) {
    return next(new Error('unauthorized'));
  }

  socket.data.rooms = [];
  return next();
});

io.on('connection', (socket) => {
  for (const room of socket.data.rooms || []) {
    socket.join(room);
  }

  socket.emit('server.hello', { time: new Date().toISOString() });

  // Rooms are only ever joined through a verified token above (POS outlet rooms
  // "tenant:{slug}:outlet:{id}", guest table rooms "table:{id}", user_{id}, admins).
  // The old anonymous 'join' of tenant_* rooms (Blade era) is gone.
});

server.listen(PORT, HOST, () => {
  // eslint-disable-next-line no-console
  console.log(`[realtime] listening on http://${HOST}:${PORT} (require_auth=${REQUIRE_AUTH})`);
});
