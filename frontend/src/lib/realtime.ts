// Shared Socket.IO connection for POS and self-order screens.
// The client script is served by the realtime server itself; rooms are granted only
// by a signed token from Laravel (outlet room for cashiers, table room for guests).
// Callers keep a polling fallback: `onStatus(false)` means "poll faster".
import { HELLOM_REALTIME_PUBLIC_URL } from '@/services/api/client';

type RealtimeTokenResult = { enabled?: boolean; token: string | null };

type Handlers = Record<string, (payload: unknown) => void>;

let scriptPromise: Promise<boolean> | null = null;

function loadSocketIo(): Promise<boolean> {
  if (typeof window === 'undefined') return Promise.resolve(false);
  if (window.io) return Promise.resolve(true);
  if (scriptPromise) return scriptPromise;

  const scriptUrl = `${HELLOM_REALTIME_PUBLIC_URL.replace(/\/$/, '')}/socket.io/socket.io.js`;
  scriptPromise = new Promise<boolean>((resolve) => {
    const existing = document.querySelector<HTMLScriptElement>(`script[data-socket-io="${scriptUrl}"]`);
    if (existing) {
      existing.addEventListener('load', () => resolve(Boolean(window.io)), { once: true });
      existing.addEventListener('error', () => resolve(false), { once: true });
      return;
    }
    const script = document.createElement('script');
    script.src = scriptUrl;
    script.async = true;
    script.dataset.socketIo = scriptUrl;
    script.onload = () => resolve(Boolean(window.io));
    script.onerror = () => {
      scriptPromise = null; // allow a retry on the next screen
      resolve(false);
    };
    document.head.appendChild(script);
  });

  return scriptPromise;
}

/**
 * Connect and listen. Returns a cleanup function.
 * `getToken` is called on every (re)connect so an expired token is replaced.
 */
export function subscribeRealtime(options: {
  getToken: () => Promise<RealtimeTokenResult>;
  handlers: Handlers;
  onStatus?: (connected: boolean) => void;
}): () => void {
  let cancelled = false;
  let socket: ReturnType<NonNullable<Window['io']>> | null = null;
  const onConnect = () => options.onStatus?.(true);
  const onDisconnect = () => options.onStatus?.(false);

  void (async () => {
    const first = await options.getToken().catch(() => null);
    if (cancelled || !first?.token || first.enabled === false) {
      options.onStatus?.(false);
      return;
    }
    const ready = await loadSocketIo();
    if (cancelled || !ready || !window.io) {
      options.onStatus?.(false);
      return;
    }

    let firstToken: string | null = first.token;
    socket = window.io(HELLOM_REALTIME_PUBLIC_URL, {
      transports: ['websocket', 'polling'],
      timeout: 4000,
      auth: (cb: (data: { token?: string }) => void) => {
        if (firstToken) {
          cb({ token: firstToken });
          firstToken = null;
          return;
        }
        options.getToken()
          .then((result) => cb(result.token ? { token: result.token } : {}))
          .catch(() => cb({}));
      },
    });
    socket.on('connect', onConnect);
    socket.on('disconnect', onDisconnect);
    socket.on('connect_error', onDisconnect);
    for (const [event, handler] of Object.entries(options.handlers)) {
      socket.on(event, (...args: unknown[]) => handler(args[0]));
    }
  })();

  return () => {
    cancelled = true;
    socket?.disconnect();
    socket = null;
  };
}

// POS screens share one outlet socket (opened by PosLayout). Pages poll slower while it is live.
const POS_LIVE_EVENT = 'pos-realtime-status';
let posLive = false;

export function setPosRealtimeConnected(connected: boolean): void {
  if (posLive === connected) return;
  posLive = connected;
  window.dispatchEvent(new CustomEvent(POS_LIVE_EVENT, { detail: { connected } }));
}

export function isPosRealtimeConnected(): boolean {
  return posLive;
}

export function getPosRealtimeEventName(): string {
  return POS_LIVE_EVENT;
}

/** Short two-tone chime for a new order (Web Audio, no asset). Silently skipped if audio is blocked. */
export function playOrderChime(): void {
  try {
    const AudioCtx = window.AudioContext || (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    const now = ctx.currentTime;
    [880, 1318.5].forEach((frequency, i) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = frequency;
      gain.gain.setValueAtTime(0.0001, now + i * 0.18);
      gain.gain.exponentialRampToValueAtTime(0.25, now + i * 0.18 + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.18 + 0.35);
      osc.connect(gain).connect(ctx.destination);
      osc.start(now + i * 0.18);
      osc.stop(now + i * 0.18 + 0.4);
    });
    window.setTimeout(() => void ctx.close(), 1000);
  } catch {
    // Audio not available — the badge still updates.
  }
}
