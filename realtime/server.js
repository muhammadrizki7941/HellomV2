import express from 'express';
import http from 'http';
import cors from 'cors';
import { Server as SocketIOServer } from 'socket.io';

const PORT = process.env.PORT ? Number(process.env.PORT) : 3001;
const HOST = process.env.HOST || '0.0.0.0';
// Shared secret must match backend REALTIME_SERVER_SECRET.
// Defaulting to 'change-me' keeps local/dev working out-of-the-box.
const SECRET = process.env.REALTIME_SERVER_SECRET || 'change-me';

const app = express();
app.use(express.json({ limit: '256kb' }));
app.use(cors({ origin: true, credentials: false }));

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

  if (!event || typeof event !== 'string') {
    return res.status(422).json({ ok: false, error: 'event required' });
  }

  if (tenantId && typeof tenantId === 'number') {
    // Emit to tenant-specific room
    io.to(`tenant_${tenantId}`).emit(event, data ?? {});
  } else {
    // Emit to all (for global events, if any)
    io.emit(event, data ?? {});
  }

  return res.json({ ok: true });
});

const server = http.createServer(app);
const io = new SocketIOServer(server, {
  cors: {
    origin: true,
    methods: ['GET', 'POST']
  }
});

io.on('connection', (socket) => {
  socket.emit('server.hello', { time: new Date().toISOString() });

  // Handle client joining tenant-specific room
  socket.on('join', (room) => {
    if (room && typeof room === 'string' && room.startsWith('tenant_')) {
      socket.join(room);
    }
  });
});

server.listen(PORT, HOST, () => {
  // eslint-disable-next-line no-console
  console.log(`[realtime] listening on http://${HOST}:${PORT}`);
});
