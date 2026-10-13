// Live in-app notifications for the two bells (super admin + dashboard users).
// One socket per channel no matter how many bells are mounted (refcounted); a new
// notification shows one toast + one chime even if two bells listen. Polling in the
// hooks stays as the fallback — `onStatus(false)` means "poll faster".
import { getRealtimeToken } from '@/services/api/billing';
import { playOrderChime, subscribeRealtime } from '@/lib/realtime';

export type LiveChannel = 'admin' | 'user';

export type LiveNotification = {
  key: string;
  channel: LiveChannel;
  id: number;
  type: string;
  title: string;
  body: string;
  action_url: string | null;
};

type Listener = (notification: LiveNotification) => void;
type StatusListener = (connected: boolean) => void;

const EVENTS: Record<LiveChannel, string> = {
  admin: 'admin.notification.created',
  user: 'user.notification.created',
};

type ChannelState = {
  refs: number;
  generation: number;
  stop: (() => void) | null;
  connected: boolean;
  listeners: Set<Listener>;
  statusListeners: Set<StatusListener>;
};

const channels: Record<LiveChannel, ChannelState> = {
  admin: { refs: 0, generation: 0, stop: null, connected: false, listeners: new Set(), statusListeners: new Set() },
  user: { refs: 0, generation: 0, stop: null, connected: false, listeners: new Set(), statusListeners: new Set() },
};

const seen = new Set<string>();

function normalize(channel: LiveChannel, payload: unknown): LiveNotification | null {
  if (!payload || typeof payload !== 'object') return null;
  const data = payload as Record<string, unknown>;
  const id = Number(data.id);
  if (!Number.isFinite(id) || id <= 0) return null;

  return {
    key: `${channel}:${id}`,
    channel,
    id,
    type: String(data.type ?? ''),
    title: String(data.title ?? 'Notifikasi baru'),
    body: String(data.body ?? data.message ?? ''),
    action_url: typeof data.action_url === 'string' && data.action_url !== '' ? data.action_url : null,
  };
}

function handleIncoming(channel: LiveChannel, payload: unknown) {
  const notification = normalize(channel, payload);
  if (!notification) {
    // Unknown shape: still let the bells refresh their counts.
    channels[channel].listeners.forEach((listener) => listener({
      key: `${channel}:unknown:${Date.now()}`, channel, id: 0, type: '', title: '', body: '', action_url: null,
    }));
    return;
  }
  if (seen.has(notification.key)) return;
  seen.add(notification.key);
  if (seen.size > 200) seen.delete(seen.values().next().value as string);

  channels[channel].listeners.forEach((listener) => listener(notification));
  pushToast(notification);
  if (isNotificationSoundEnabled()) playOrderChime();
}

/** Listen to new notifications on a channel. Returns a cleanup function. */
export function subscribeLiveNotifications(channel: LiveChannel, onNew: Listener, onStatus?: StatusListener): () => void {
  const state = channels[channel];
  state.listeners.add(onNew);
  if (onStatus) {
    state.statusListeners.add(onStatus);
    onStatus(state.connected);
  }
  state.refs += 1;

  if (!state.stop) {
    // A stopped socket can still report "offline" late (e.g. React StrictMode remount): ignore it.
    const generation = ++state.generation;
    state.stop = subscribeRealtime({
      getToken: async () => {
        const result = await getRealtimeToken();
        return { token: result.token ?? null };
      },
      handlers: { [EVENTS[channel]]: (payload) => handleIncoming(channel, payload) },
      onStatus: (connected) => {
        if (generation !== state.generation) return;
        state.connected = connected;
        state.statusListeners.forEach((listener) => listener(connected));
      },
    });
  }

  return () => {
    state.listeners.delete(onNew);
    if (onStatus) state.statusListeners.delete(onStatus);
    state.refs -= 1;
    if (state.refs <= 0) {
      state.refs = 0;
      state.stop?.();
      state.stop = null;
      state.connected = false;
    }
  };
}

// ─── Sound preference ───

const SOUND_KEY = 'hellom.notificationSound';

export function isNotificationSoundEnabled(): boolean {
  try {
    return window.localStorage.getItem(SOUND_KEY) !== 'off';
  } catch {
    return true;
  }
}

export function setNotificationSoundEnabled(enabled: boolean): void {
  try {
    window.localStorage.setItem(SOUND_KEY, enabled ? 'on' : 'off');
  } catch {
    // Private mode — the toggle simply won't persist.
  }
}

// ─── Toasts ───

type ToastListener = (toasts: LiveNotification[]) => void;
let toasts: LiveNotification[] = [];
const toastListeners = new Set<ToastListener>();
const TOAST_MS = 7000;

function emitToasts() {
  toastListeners.forEach((listener) => listener(toasts));
}

function pushToast(notification: LiveNotification) {
  toasts = [notification, ...toasts.filter((toast) => toast.key !== notification.key)].slice(0, 3);
  emitToasts();
  window.setTimeout(() => dismissLiveToast(notification.key), TOAST_MS);
}

export function dismissLiveToast(key: string): void {
  const next = toasts.filter((toast) => toast.key !== key);
  if (next.length === toasts.length) return;
  toasts = next;
  emitToasts();
}

export function subscribeLiveToasts(listener: ToastListener): () => void {
  toastListeners.add(listener);
  listener(toasts);
  return () => {
    toastListeners.delete(listener);
  };
}

// ─── Unread count in the tab title ───

const TITLE_BADGE = /^\(\d+\+?\)\s/;
let titleCount = 0;
let titleObserver: MutationObserver | null = null;

function applyTitleBadge() {
  const base = document.title.replace(TITLE_BADGE, '');
  const next = titleCount > 0 ? `(${titleCount > 99 ? '99+' : titleCount}) ${base}` : base;
  if (document.title !== next) document.title = next;
}

/** Show "(3) " before the page title; pages that change the title keep the badge. */
export function setTitleBadge(count: number): void {
  if (typeof document === 'undefined') return;
  titleCount = Math.max(0, count);
  applyTitleBadge();

  const titleElement = document.querySelector('title');
  if (!titleObserver && titleElement) {
    titleObserver = new MutationObserver(() => applyTitleBadge());
    titleObserver.observe(titleElement, { childList: true, characterData: true, subtree: true });
  }
}
