import { ApiError, getActiveOutletEventName, getSessionEventName, isPosOfflineSessionExpired } from '@/services/api/client';
import { isPosRealtimeConnected } from '@/lib/realtime';
import { pullPosSyncChanges, uploadPosSyncOrders, type PosSyncOrderPayload } from '@/services/api/posSync';
import {
  applyPosMasterDataPull,
  getDueOfflineOrders,
  getOfflineOrders,
  getPosOfflineScope,
  getPosSyncCounts,
  getPosSyncCursor,
  recoverStaleOfflineOrders,
  retryOfflineOrder,
  updateOfflineOrderSync,
  type OfflineOrder,
} from '@/lib/posOfflineDb';

export type PosSyncSnapshot = {
  online: boolean;
  running: boolean;
  pending: number;
  syncing: number;
  failed: number;
  lastSyncedAt: string | null;
  message: string | null;
};

const RETRY_DELAYS_MS = [5000, 15000, 60000, 300000, 900000];
const SYNC_BATCH_SIZE = 20;
const SYNC_INTERVAL_MS = 30000;
const SYNC_LEASE_MS = 300000;
const LOCK_NAME = 'hellom-pos-offline-sync';
const CHANNEL_NAME = 'hellom-pos-sync-state';
const FALLBACK_LOCK_KEY = 'hellom_pos_sync_lock';

let snapshot: PosSyncSnapshot = {
  online: typeof navigator === 'undefined' || navigator.onLine,
  running: false,
  pending: 0,
  syncing: 0,
  failed: 0,
  lastSyncedAt: null,
  message: null,
};
let started = false;
let running = false;
let blocked = false;
let intervalId: number | null = null;
let channel: BroadcastChannel | null = null;
const listeners = new Set<() => void>();

function publish(patch: Partial<PosSyncSnapshot>) {
  snapshot = { ...snapshot, ...patch };
  listeners.forEach((listener) => listener());
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('pos-sync-state-changed', { detail: snapshot }));
  }
  channel?.postMessage({ type: 'state', snapshot });
}

export function getPosSyncSnapshot(): PosSyncSnapshot {
  return snapshot;
}

export function subscribePosSync(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

async function refreshCounts() {
  const counts = await getPosSyncCounts();
  publish(counts);
}

function retryDelay(attempts: number): number {
  return RETRY_DELAYS_MS[Math.min(Math.max(attempts - 1, 0), RETRY_DELAYS_MS.length - 1)];
}

function isAccessFailure(error: unknown): boolean {
  if (!(error instanceof ApiError)) return false;
  return error.status === 401 || error.status === 403;
}

function errorText(error: unknown): string {
  if (error instanceof ApiError && error.status === 401) {
    return 'Sesi tidak lagi dapat digunakan. Order tetap tersimpan; login kembali dengan akun asal.';
  }
  if (error instanceof ApiError && error.status === 403) {
    return error.message || 'Akses akun/outlet berubah. Hubungi Admin Utama; order lokal tetap tersimpan.';
  }
  return error instanceof Error ? error.message : 'Sinkronisasi gagal. Data lokal tetap tersimpan.';
}

function toSyncPayload(order: OfflineOrder): PosSyncOrderPayload {
  return {
    ...order.draft,
    client_uuid: order.client_uuid,
    created_at_client: order.created_at_client,
    created_offline: order.created_offline,
    outlet_id: Number(order.outlet_id),
    payment_method: order.payment_method,
    payment_amount: order.payment_amount,
    payment_note: order.payment_note,
    paid_manually: order.paid_manually,
    paid_at_client: order.paid_at_client,
  };
}

async function setAccessBlocked(message: string) {
  blocked = true;
  const allOrders = await getOfflineOrders();
  const targets = allOrders.filter((order) => order.sync_status === 'pending' || order.sync_status === 'syncing');
  for (const order of targets) {
    await updateOfflineOrderSync(order.client_uuid, { status: 'failed', lastError: message });
  }
  publish({ message });
  await refreshCounts();
}

async function processBatch(orders: OfflineOrder[]) {
  for (const order of orders) {
    await updateOfflineOrderSync(order.client_uuid, {
      status: 'syncing',
      lastError: null,
      incrementAttempts: true,
      nextRetryAt: new Date(Date.now() + SYNC_LEASE_MS).toISOString(),
    });
  }

  try {
    const response = await uploadPosSyncOrders(orders.map(toSyncPayload));
    const resultsByUuid = new Map(response.results.map((result) => [result.client_uuid, result]));
    if (orders.some((order) => {
      const result = resultsByUuid.get(order.client_uuid);
      return !result || ((result.status === 'ok' || result.status === 'duplicate') && !result.order);
    })) {
      throw new Error('Respons sinkronisasi tidak lengkap; antrean akan dicoba ulang dengan kunci idempotensi yang sama.');
    }
    for (const order of orders) {
      const result = resultsByUuid.get(order.client_uuid);
      if (!result) continue;
      if (result.status === 'ok' || result.status === 'duplicate') {
        if (!result.order) continue;
        await updateOfflineOrderSync(order.client_uuid, {
          status: 'synced',
          serverOrderId: result.order.id,
          finalInvoiceNo: result.order.order_number,
        });
        window.dispatchEvent(new CustomEvent('pos-orders-updated'));
        continue;
      }

      const failure = `${result.code ?? 'SYNC_REJECTED'}: ${result.reason ?? 'Transaksi ditolak server.'}`;
      await updateOfflineOrderSync(order.client_uuid, { status: 'failed', lastError: failure });
      if (['POS_ACCOUNT_INACTIVE', 'POS_NO_OUTLET_ASSIGNMENT', 'POS_OUTLET_NOT_ASSIGNED', 'POS_PERMISSION_DENIED'].includes(result.code ?? '')) {
        await setAccessBlocked(failure);
        return;
      }
    }
    publish({ lastSyncedAt: response.server_time, message: null });
  } catch (error) {
    const message = errorText(error);
    if (isAccessFailure(error)) {
      await setAccessBlocked(message);
      return;
    }
    const isRetryable = !(error instanceof ApiError) || error.status === 429 || error.status >= 500;
    for (const order of orders) {
      const nextAttempt = order.attempts + 1;
      await updateOfflineOrderSync(order.client_uuid, {
        status: isRetryable ? 'pending' : 'failed',
        lastError: message,
        nextRetryAt: isRetryable ? new Date(Date.now() + retryDelay(nextAttempt)).toISOString() : null,
      });
    }
    publish({ message });
  }
  await refreshCounts();
}

async function pullMasterData() {
  const since = await getPosSyncCursor();
  const changes = await pullPosSyncChanges(since);
  await applyPosMasterDataPull(changes);
  publish({ lastSyncedAt: changes.server_time });
}

async function runUnlocked(retryClientUuid?: string) {
  if (running || blocked || !navigator.onLine || isPosOfflineSessionExpired() || !getPosOfflineScope()) return;
  running = true;
  publish({ running: true, online: navigator.onLine, message: null });
  try {
    await recoverStaleOfflineOrders();
    if (retryClientUuid) {
      await retryOfflineOrder(retryClientUuid);
    }
    while (!blocked && navigator.onLine) {
      const orders = await getDueOfflineOrders(SYNC_BATCH_SIZE);
      if (orders.length === 0) break;
      await processBatch(orders);
    }
    if (!blocked) {
      try {
        await pullMasterData();
      } catch (error) {
        if (isAccessFailure(error)) {
          blocked = true;
          publish({ message: errorText(error) });
        } else {
          console.warn('[POS sync] Master data pull failed; it will be retried.', error);
          publish({ message: errorText(error) });
        }
      }
    }
  } finally {
    running = false;
    publish({ running: false, online: navigator.onLine });
    await refreshCounts();
  }
}

async function withCrossTabLock(run: () => Promise<void>) {
  if (navigator.locks) {
    await navigator.locks.request(LOCK_NAME, { ifAvailable: true }, async (lock) => {
      if (lock) await run();
    });
    return;
  }

  const lockId = crypto.randomUUID();
  const current = (() => {
    try {
      const raw = localStorage.getItem(FALLBACK_LOCK_KEY);
      return raw ? JSON.parse(raw) as { id: string; expiresAt: number } : null;
    } catch (error) {
      console.warn('[POS sync] Could not read the cross-tab lock.', error);
      return null;
    }
  })();
  if (current && current.expiresAt > Date.now()) return;
  try {
    localStorage.setItem(FALLBACK_LOCK_KEY, JSON.stringify({ id: lockId, expiresAt: Date.now() + SYNC_LEASE_MS }));
    await new Promise((resolve) => window.setTimeout(resolve, 40));
    const owner = JSON.parse(localStorage.getItem(FALLBACK_LOCK_KEY) ?? 'null') as { id?: string } | null;
    if (owner?.id !== lockId) return;
    await run();
  } catch (error) {
    console.error('[POS sync] Cross-tab coordination failed.', error);
  } finally {
    try {
      const owner = JSON.parse(localStorage.getItem(FALLBACK_LOCK_KEY) ?? 'null') as { id?: string } | null;
      if (owner?.id === lockId) localStorage.removeItem(FALLBACK_LOCK_KEY);
    } catch (error) {
      console.warn('[POS sync] Could not release the cross-tab lock.', error);
    }
  }
}

export async function syncPosOrders(): Promise<void> {
  await withCrossTabLock(() => runUnlocked());
}

export async function retryPosOrderSync(clientUuid: string): Promise<void> {
  blocked = false;
  await withCrossTabLock(() => runUnlocked(clientUuid));
}

async function periodicSync() {
  const counts = await getPosSyncCounts();
  if ((counts.pending > 0 || counts.syncing > 0 || !isPosRealtimeConnected()) && !blocked) await syncPosOrders();
  else await refreshCounts();
}

function onOnline() {
  if (!navigator.onLine) {
    onOffline();
    return;
  }
  blocked = false;
  publish({ online: true });
  void syncPosOrders();
}

function onOffline() {
  publish({ online: false, message: 'Offline. Transaksi tersimpan di perangkat dan menunggu koneksi.' });
}

function onSessionChanged() {
  blocked = false;
  void refreshCounts();
  void syncPosOrders();
}

function onQueueChanged() {
  void refreshCounts();
  if (navigator.onLine && !blocked) void syncPosOrders();
}

function onVisibilityChange() {
  if (document.visibilityState === 'visible') {
    if (navigator.onLine) onOnline();
    else onOffline();
  }
}

function onRealtimeChanges() {
  if (navigator.onLine && !blocked) void syncPosOrders();
}

export function startPosSyncEngine(): () => void {
  if (started || typeof window === 'undefined') return () => undefined;
  started = true;
  channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(CHANNEL_NAME);
  channel?.addEventListener('message', (event: MessageEvent<{ type?: string; snapshot?: PosSyncSnapshot }>) => {
    if (event.data?.type === 'state' && event.data.snapshot) {
      snapshot = { ...snapshot, ...event.data.snapshot };
      listeners.forEach((listener) => listener());
    }
  });
  window.addEventListener('online', onOnline);
  window.addEventListener('offline', onOffline);
  window.addEventListener('focus', onOnline);
  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener(getSessionEventName(), onSessionChanged);
  window.addEventListener(getActiveOutletEventName(), onSessionChanged);
  window.addEventListener('pos-orders-updated', onRealtimeChanges);
  window.addEventListener('pos-sync-queue-updated', onQueueChanged);
  intervalId = window.setInterval(() => void periodicSync(), SYNC_INTERVAL_MS);
  void refreshCounts();
  if (navigator.onLine) void syncPosOrders();

  return () => {
    if (!started) return;
    started = false;
    if (intervalId !== null) window.clearInterval(intervalId);
    intervalId = null;
    window.removeEventListener('online', onOnline);
    window.removeEventListener('offline', onOffline);
    window.removeEventListener('focus', onOnline);
    document.removeEventListener('visibilitychange', onVisibilityChange);
    window.removeEventListener(getSessionEventName(), onSessionChanged);
    window.removeEventListener(getActiveOutletEventName(), onSessionChanged);
    window.removeEventListener('pos-orders-updated', onRealtimeChanges);
    window.removeEventListener('pos-sync-queue-updated', onQueueChanged);
    channel?.close();
    channel = null;
  };
}
