import { useCallback, useEffect, useMemo, useState, useSyncExternalStore } from 'react';
import { AlertTriangle, CheckCircle2, Clock3, RefreshCw, Trash2, Wifi, WifiOff } from 'lucide-react';
import {
  getPosSyncQueueOrders,
  removeFailedOfflineOrderFromQueue,
  type OfflineOrder,
  type PosSyncQueueItem,
} from '@/lib/posOfflineDb';
import {
  getPosSyncSnapshot,
  retryPosOrderSync,
  subscribePosSync,
  syncPosOrders,
} from '@/lib/posSyncEngine';
import { usePermissions } from '@/hooks/usePermissions';

type QueueEntry = { order: OfflineOrder; queue: PosSyncQueueItem };

const formatCurrency = (amount: number) => new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
}).format(amount);

const formatDate = (value: string) => new Date(value).toLocaleString('id-ID', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

export default function PosSyncQueue() {
  const snapshot = useSyncExternalStore(subscribePosSync, getPosSyncSnapshot, getPosSyncSnapshot);
  const { isCashier, isOutletAdmin, isOrganizationAdmin } = usePermissions();
  const canRemoveFailed = isOrganizationAdmin || isOutletAdmin;
  const [entries, setEntries] = useState<QueueEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [actionUuid, setActionUuid] = useState<string | null>(null);
  const [now, setNow] = useState(Date.now());

  const refresh = useCallback(async () => {
    try {
      setEntries(await getPosSyncQueueOrders());
    } catch (error) {
      console.error('[POS sync] Failed to load the local queue.', error);
      window.alert(error instanceof Error ? error.message : 'Antrean sinkronisasi gagal dimuat.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh();
    const timer = window.setInterval(() => setNow(Date.now()), 60000);
    const changed = () => void refresh();
    window.addEventListener('pos-sync-state-changed', changed);
    window.addEventListener('pos-orders-updated', changed);
    window.addEventListener('pos-sync-queue-updated', changed);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('pos-sync-state-changed', changed);
      window.removeEventListener('pos-orders-updated', changed);
      window.removeEventListener('pos-sync-queue-updated', changed);
    };
  }, [refresh]);

  const oldEntries = useMemo(() => entries.filter(({ order }) =>
    now - new Date(order.created_at_client).getTime() >= 24 * 60 * 60 * 1000,
  ), [entries, now]);

  const handleSync = async () => {
    setActionUuid('all');
    try {
      await syncPosOrders();
      await refresh();
    } finally {
      setActionUuid(null);
    }
  };

  const handleRetry = async (clientUuid: string) => {
    setActionUuid(clientUuid);
    try {
      await retryPosOrderSync(clientUuid);
      await refresh();
    } finally {
      setActionUuid(null);
    }
  };

  const handleRemove = async (entry: QueueEntry) => {
    if (!canRemoveFailed || entry.queue.status !== 'failed') return;
    const confirmed = window.confirm(
      `Hapus ${entry.order.local_invoice_no} dari antrean? Transaksi lokal tetap disimpan untuk catatan, tetapi tidak akan dicoba kirim lagi.`,
    );
    if (!confirmed) return;
    setActionUuid(entry.order.client_uuid);
    try {
      await removeFailedOfflineOrderFromQueue(entry.order.client_uuid);
      await refresh();
    } catch (error) {
      window.alert(error instanceof Error ? error.message : 'Transaksi gagal dihapus dari antrean.');
    } finally {
      setActionUuid(null);
    }
  };

  const pendingCount = snapshot.pending + snapshot.syncing;
  const unsyncedCount = pendingCount + snapshot.failed;
  const status = !snapshot.online
    ? { text: `🔴 Offline (${unsyncedCount} tertunda)`, className: 'border-red-200 bg-red-50 text-red-800' }
    : snapshot.failed > 0
      ? { text: `🟠 Ada yang gagal (${snapshot.failed})`, className: 'border-orange-200 bg-orange-50 text-orange-800' }
      : snapshot.running || pendingCount > 0
        ? { text: `🟡 Menyinkronkan (${pendingCount})`, className: 'border-amber-200 bg-amber-50 text-amber-900' }
        : { text: '🟢 Online & tersinkron', className: 'border-emerald-200 bg-emerald-50 text-emerald-800' };

  return (
    <section className="mx-auto max-w-4xl space-y-5">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold text-gray-950">Antrean Sinkronisasi</h1>
          <p className="mt-1 text-sm text-gray-600">Transaksi tetap tersimpan di perangkat sampai berhasil dikirim.</p>
        </div>
        <button
          type="button"
          onClick={() => void handleSync()}
          disabled={!snapshot.online || snapshot.running || actionUuid !== null}
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-lime-400 px-4 py-2 text-sm font-bold text-gray-950 hover:bg-lime-300 disabled:cursor-not-allowed disabled:opacity-50"
        >
          <RefreshCw className={`h-4 w-4 ${snapshot.running ? 'animate-spin' : ''}`} />
          Sinkronkan Sekarang
        </button>
      </header>

      <div className={`flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold ${status.className}`}>
        {!snapshot.online ? <WifiOff className="h-4 w-4 shrink-0" /> : <Wifi className="h-4 w-4 shrink-0" />}
        <span>{status.text}</span>
        {snapshot.lastSyncedAt && (
          <span className="ml-auto hidden text-xs font-normal sm:inline">
            Sinkron terakhir {formatDate(snapshot.lastSyncedAt)}
          </span>
        )}
      </div>

      {oldEntries.length > 0 && (
        <div role="status" className="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
          <AlertTriangle className="h-5 w-5 shrink-0" />
          <p>
            Ada {oldEntries.length} transaksi yang belum tersinkron lebih dari 24 jam.
            Pastikan koneksi internet tersedia dan jangan hapus data aplikasi.
          </p>
        </div>
      )}

      {snapshot.message && (
        <div role="alert" className="rounded-xl border border-orange-200 bg-orange-50 p-4 text-sm text-orange-900">
          {snapshot.message}
        </div>
      )}

      {loading ? (
        <div className="rounded-xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-600">Memuat antrean…</div>
      ) : entries.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-white p-8 text-center">
          <CheckCircle2 className="mx-auto h-9 w-9 text-emerald-600" />
          <p className="mt-3 font-semibold text-gray-900">Tidak ada transaksi dalam antrean.</p>
          <p className="mt-1 text-sm text-gray-600">Transaksi lokal yang sudah tersinkron tetap tercatat di daftar transaksi.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {entries.map(({ order, queue }) => {
            const failed = queue.status === 'failed';
            const working = queue.status === 'syncing' || actionUuid === order.client_uuid;
            return (
              <article key={order.client_uuid} className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="font-bold text-gray-950">{order.local_invoice_no}</h2>
                      <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${failed ? 'bg-red-100 text-red-800' : queue.status === 'syncing' ? 'bg-amber-100 text-amber-900' : 'bg-yellow-100 text-yellow-900'}`}>
                        {failed ? 'Gagal' : queue.status === 'syncing' ? 'Sedang dikirim' : 'Menunggu'}
                      </span>
                      {order.paid_manually && (
                        <span className="rounded-full bg-purple-100 px-2.5 py-1 text-xs font-semibold text-purple-800">
                          Dibayar manual · perlu verifikasi
                        </span>
                      )}
                    </div>
                    <p className="mt-1 text-sm text-gray-600">
                      {formatDate(order.created_at_client)} · {order.items.length} item
                    </p>
                    <p className="mt-2 text-base font-semibold text-gray-900">{formatCurrency(order.final_amount)}</p>
                    {queue.last_error && (
                      <details className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-900">
                        <summary className="cursor-pointer font-semibold">Lihat detail kegagalan</summary>
                        <p className="mt-2 break-words">{queue.last_error}</p>
                      </details>
                    )}
                  </div>
                  <div className="flex shrink-0 flex-wrap gap-2">
                    {failed && (
                      <button
                        type="button"
                        onClick={() => void handleRetry(order.client_uuid)}
                        disabled={!snapshot.online || actionUuid !== null}
                        className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-gray-300 px-3 text-sm font-semibold text-gray-800 hover:bg-gray-50 disabled:opacity-50"
                      >
                        <RefreshCw className={`h-4 w-4 ${working ? 'animate-spin' : ''}`} />
                        Coba Lagi
                      </button>
                    )}
                    {failed && canRemoveFailed && (
                      <button
                        type="button"
                        onClick={() => void handleRemove({ order, queue })}
                        disabled={actionUuid !== null}
                        className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-red-200 px-3 text-sm font-semibold text-red-800 hover:bg-red-50 disabled:opacity-50"
                      >
                        <Trash2 className="h-4 w-4" />
                        Hapus dari antrean
                      </button>
                    )}
                  </div>
                </div>
              </article>
            );
          })}
        </div>
      )}
      <p className="flex items-start gap-2 text-xs text-gray-500">
        <Clock3 className="mt-0.5 h-4 w-4 shrink-0" />
        Kasir dan Admin Outlet dapat melihat antrean serta mencoba ulang. Hanya Admin Outlet dan Admin Utama yang dapat menghapus item gagal dari antrean; data transaksi lokal tetap disimpan.
        {isCashier ? ' Hubungi admin bila transaksi ditolak server.' : ''}
      </p>
    </section>
  );
}
