import { useCallback, useEffect, useState } from 'react';
import { Bell, CheckCheck, Search } from 'lucide-react';
import { useNotifications, type Notification } from '@/hooks/useNotifications';
import { getOwnerNotificationDetail } from '@/lib/hellomApi';
import { subscribeLiveNotifications } from '@/lib/liveNotifications';
import { ActionBadge, NotificationDetailDialog } from '@/components/admin/NotificationDropdown';
import {
  ADMIN_NOTIFICATION_GROUPS,
  adminNotificationMeta,
  groupTypes,
  type AdminNotificationGroup,
} from '@/components/admin/notificationTypes';

const STATUS_FILTERS = [
  { key: '' as const, label: 'Semua status' },
  { key: 'unread' as const, label: 'Belum dibaca' },
  { key: 'pending' as const, label: 'Perlu tindakan' },
];

const formatTime = (dateString: string) =>
  new Date(dateString).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

export default function Notifications() {
  // The layout keeps the live count; this page only lists (and reloads on new events).
  const { notifications, meta, loading, error, fetchNotifications, markAsRead, markAllAsRead } = useNotifications({ live: false });
  const [group, setGroup] = useState<AdminNotificationGroup>('all');
  const [status, setStatus] = useState<'' | 'unread' | 'pending'>('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Notification | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const load = useCallback(() => {
    const types = groupTypes(group);
    return fetchNotifications({
      page,
      per_page: 20,
      type: types.length ? types.join(',') : undefined,
      status: status || undefined,
      q: search || undefined,
    });
  }, [fetchNotifications, group, page, search, status]);

  useEffect(() => {
    void load();
  }, [load, reloadKey]);

  // New notification while the page is open → reload (only page 1 changes order).
  useEffect(() => subscribeLiveNotifications('admin', () => setReloadKey((key) => key + 1)), []);

  const openDetail = async (notification: Notification) => {
    if (!notification.is_read) await markAsRead(notification.id);
    setSelected(await getOwnerNotificationDetail(String(notification.id)));
  };

  const handleMarkAllAsRead = async () => {
    if (confirm('Tandai semua notifikasi sebagai dibaca?')) {
      await markAllAsRead();
    }
  };

  const lastPage = meta?.last_page ?? 1;

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Notifikasi</h1>
          <p className="text-zinc-500">Dana masuk, penarikan, refund, KYC, dan aktivitas penting lain di platform.</p>
        </div>
        {notifications.some(n => !n.is_read) && (
          <button
            onClick={() => void handleMarkAllAsRead()}
            className="flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 font-bold text-white transition-colors hover:bg-zinc-800"
          >
            <CheckCheck className="h-4 w-4" />
            Tandai semua dibaca
          </button>
        )}
      </div>

      {error && <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      <div className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-2.5 h-4 w-4 text-zinc-400" />
            <input
              type="text"
              placeholder="Cari judul atau isi notifikasi..."
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              className="w-full rounded-lg border border-zinc-300 py-2 pl-9 pr-4 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
            />
          </div>
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as '' | 'unread' | 'pending');
              setPage(1);
            }}
            className="rounded-lg border border-zinc-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-zinc-900"
            aria-label="Filter status"
          >
            {STATUS_FILTERS.map(({ key, label }) => <option key={key} value={key}>{label}</option>)}
          </select>
        </div>
        <div className="flex flex-wrap gap-2">
          {ADMIN_NOTIFICATION_GROUPS.map(({ key, label }) => (
            <button
              key={key}
              onClick={() => {
                setGroup(key);
                setPage(1);
              }}
              className={`rounded-lg px-3 py-1.5 text-sm transition-colors ${
                group === key ? 'bg-zinc-900 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200'
              }`}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
        {loading && notifications.length === 0 ? (
          <div className="p-8 text-center">
            <div className="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-4 border-zinc-300 border-t-zinc-600" />
            <p className="text-zinc-500">Memuat notifikasi...</p>
          </div>
        ) : notifications.length === 0 ? (
          <div className="p-12 text-center">
            <Bell className="mx-auto mb-4 h-12 w-12 text-zinc-400" />
            <h3 className="mb-2 text-lg font-semibold text-zinc-900">Tidak ada notifikasi</h3>
            <p className="text-zinc-500">Belum ada notifikasi yang sesuai dengan filter.</p>
          </div>
        ) : (
          <div className="divide-y divide-zinc-100">
            {notifications.map((notification) => {
              const typeMeta = adminNotificationMeta(notification.type);
              const Icon = typeMeta.icon;
              return (
                <div
                  key={notification.id}
                  className={`flex items-start gap-4 px-4 py-4 transition-colors hover:bg-zinc-50 sm:px-6 ${!notification.is_read ? 'bg-blue-50/40' : ''}`}
                >
                  <div className={`rounded-lg p-2 ${typeMeta.tone}`}>
                    <Icon className="h-4 w-4" />
                  </div>
                  <button type="button" onClick={() => void openDetail(notification)} className="min-w-0 flex-1 text-left">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-xs font-medium uppercase tracking-wide text-zinc-500">{typeMeta.label}</span>
                      {!notification.is_read && <span className="h-2 w-2 rounded-full bg-blue-500" />}
                      <ActionBadge notification={notification} />
                    </div>
                    <p className="mt-0.5 text-sm font-semibold text-zinc-900">{notification.title}</p>
                    <p className="line-clamp-2 text-sm text-zinc-600">{notification.message}</p>
                    <p className="mt-1 text-xs text-zinc-500">{formatTime(notification.created_at)}</p>
                  </button>
                  {!notification.is_read && (
                    <button
                      onClick={() => void markAsRead(notification.id)}
                      className="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900"
                      title="Tandai dibaca"
                      aria-label="Tandai dibaca"
                    >
                      <CheckCheck className="h-4 w-4" />
                    </button>
                  )}
                </div>
              );
            })}
          </div>
        )}

        {meta && meta.total > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-6 py-4">
            <p className="text-sm text-zinc-700">
              {meta.total.toLocaleString('id-ID')} notifikasi · halaman {meta.current_page} dari {lastPage}
            </p>
            <div className="flex gap-2">
              <button
                onClick={() => setPage(prev => Math.max(1, prev - 1))}
                disabled={page <= 1 || loading}
                className="rounded border border-zinc-300 px-3 py-1 text-sm hover:bg-white disabled:opacity-50"
              >
                Sebelumnya
              </button>
              <button
                onClick={() => setPage(prev => Math.min(lastPage, prev + 1))}
                disabled={page >= lastPage || loading}
                className="rounded border border-zinc-300 px-3 py-1 text-sm hover:bg-white disabled:opacity-50"
              >
                Berikutnya
              </button>
            </div>
          </div>
        )}
      </div>

      {selected && <NotificationDetailDialog notification={selected} onClose={() => setSelected(null)} onChanged={load} />}
    </div>
  );
}
