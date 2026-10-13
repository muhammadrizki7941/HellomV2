import { useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { Bell, CheckCheck, ChevronRight, ExternalLink, Volume2, VolumeX, X } from 'lucide-react';
import type { AdminNotificationsState, Notification } from '@/hooks/useNotifications';
import {
  executeOwnerNotificationAction,
  getOwnerNotificationDetail,
  ignoreOwnerNotificationAction,
} from '@/lib/hellomApi';
import { isNotificationSoundEnabled, setNotificationSoundEnabled } from '@/lib/liveNotifications';
import PushToggle from '@/components/notifications/PushToggle';
import {
  ADMIN_NOTIFICATION_GROUPS,
  adminActionLabel,
  adminNotificationMeta,
  formatNotificationAge,
  groupTypes,
  type AdminNotificationGroup,
} from './notificationTypes';

interface NotificationDropdownProps {
  state: AdminNotificationsState;
  group: AdminNotificationGroup;
  onGroupChange: (group: AdminNotificationGroup) => void;
  onClose: () => void;
}

export function ActionBadge({ notification }: { notification: Notification }) {
  if (notification.action_status === 'done') {
    return <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Selesai</span>;
  }
  if (notification.action_status === 'ignored') {
    return <span className="text-[11px] text-zinc-400">Diabaikan</span>;
  }
  if (notification.action_status === 'pending' && notification.action_type) {
    return (
      <span className="rounded-full bg-orange-100 px-2 py-0.5 text-[11px] font-semibold text-orange-700">
        {adminActionLabel(notification.action_type)}
      </span>
    );
  }
  return null;
}

const formatDateTime = (dateString?: string | null) => {
  if (!dateString) return '-';
  return new Date(dateString).toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
};

/** Detail + action dialog for one super admin notification (bell and full page). */
export function NotificationDetailDialog({
  notification,
  onClose,
  onChanged,
}: {
  notification: Notification;
  onClose: () => void;
  onChanged: () => Promise<void> | void;
}) {
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  const pending = notification.action_status === 'pending' && Boolean(notification.action_type);

  const openUrl = () => {
    const url = notification.action_url ?? '';
    if (url.startsWith('/')) {
      onClose();
      navigate(url);
    } else if (url) {
      window.open(url, '_blank', 'noopener');
    }
  };

  const run = async (action: 'execute' | 'ignore') => {
    setBusy(true);
    try {
      if (action === 'execute') await executeOwnerNotificationAction(String(notification.id));
      else await ignoreOwnerNotificationAction(String(notification.id));
      await onChanged();
      onClose();
    } finally {
      setBusy(false);
    }
  };

  const meta = adminNotificationMeta(notification.type);
  const Icon = meta.icon;

  // Portal: the bell's panel is a transformed box on mobile, which would trap "fixed".
  return createPortal(
    <div className="fixed inset-0 z-[60] flex items-center justify-center bg-zinc-950/45 p-4" onClick={onClose}>
      <div className="w-full max-w-lg rounded-2xl border border-zinc-200 bg-white shadow-2xl" onClick={(event) => event.stopPropagation()}>
        <div className="flex items-start justify-between gap-3 border-b border-zinc-100 px-5 py-4">
          <div className="flex items-start gap-3">
            <div className={`rounded-lg p-2 ${meta.tone}`}><Icon className="h-4 w-4" /></div>
            <div>
              <p className="text-xs font-medium uppercase tracking-wide text-zinc-500">{meta.label}</p>
              <h3 className="text-base font-semibold text-zinc-900">{notification.title}</h3>
              <p className="mt-0.5 text-xs text-zinc-500">{formatDateTime(notification.created_at)}</p>
            </div>
          </div>
          <button onClick={onClose} className="rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700" aria-label="Tutup">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="space-y-3 px-5 py-4">
          <p className="text-sm leading-6 text-zinc-700">{notification.message}</p>
          {notification.action_status === 'done' && (
            <div className="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
              Selesai pada {formatDateTime(notification.action_done_at)}
            </div>
          )}
        </div>

        <div className="flex flex-wrap justify-end gap-2 border-t border-zinc-100 px-5 py-4">
          {notification.action_url && (
            <button
              onClick={openUrl}
              className="inline-flex items-center gap-2 rounded-xl border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
            >
              Buka halaman <ExternalLink className="h-4 w-4" />
            </button>
          )}
          {pending ? (
            <>
              <button
                disabled={busy}
                onClick={() => void run('ignore')}
                className="rounded-xl border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-50 disabled:opacity-60"
              >
                Abaikan
              </button>
              <button
                disabled={busy}
                onClick={() => void run('execute')}
                className="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 disabled:opacity-60"
              >
                Tandai selesai
              </button>
            </>
          ) : (
            <button onClick={onClose} className="rounded-xl border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-50">
              Tutup
            </button>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}

export default function NotificationDropdown({ state, group, onGroupChange, onClose }: NotificationDropdownProps) {
  const navigate = useNavigate();
  const { notifications, loading, fetchNotifications, fetchUnreadCount, markAsRead, markAllAsRead, connected } = state;
  const [selected, setSelected] = useState<Notification | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [sound, setSound] = useState(isNotificationSoundEnabled);

  const refresh = async () => {
    const types = groupTypes(group);
    await Promise.all([
      fetchUnreadCount(),
      fetchNotifications({ per_page: 15, type: types.length ? types.join(',') : undefined }),
    ]);
  };

  const handleNotificationClick = async (notification: Notification) => {
    if (!notification.is_read) await markAsRead(notification.id);
    setDetailLoading(true);
    try {
      setSelected(await getOwnerNotificationDetail(String(notification.id)));
    } finally {
      setDetailLoading(false);
    }
  };

  const toggleSound = () => {
    setNotificationSoundEnabled(!sound);
    setSound(!sound);
  };

  return (
    <>
      <div className="w-full overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-lg">
        <div className="border-b border-zinc-100 p-4">
          <div className="mb-3 flex items-center justify-between gap-2">
            <h3 className="flex items-center gap-2 font-semibold text-zinc-900">
              <Bell className="h-5 w-5" />
              Notifikasi
              <span
                className={`h-2 w-2 rounded-full ${connected ? 'bg-emerald-500' : 'bg-zinc-300'}`}
                title={connected ? 'Langsung (realtime)' : 'Diperbarui berkala'}
              />
            </h3>
            <div className="flex items-center gap-1">
              <button
                onClick={toggleSound}
                className="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900"
                title={sound ? 'Matikan bunyi notifikasi' : 'Nyalakan bunyi notifikasi'}
                aria-label={sound ? 'Matikan bunyi notifikasi' : 'Nyalakan bunyi notifikasi'}
              >
                {sound ? <Volume2 className="h-4 w-4" /> : <VolumeX className="h-4 w-4" />}
              </button>
              {notifications.some(n => !n.is_read) && (
                <button onClick={() => void markAllAsRead()} className="flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900">
                  <CheckCheck className="h-4 w-4" />
                  Tandai semua dibaca
                </button>
              )}
            </div>
          </div>

          <div className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-0.5 [scrollbar-width:none]">
            {ADMIN_NOTIFICATION_GROUPS.map(({ key, label }) => (
              <button
                key={key}
                onClick={() => onGroupChange(key)}
                className={`shrink-0 rounded-full px-3 py-1 text-xs transition-colors ${
                  group === key ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100'
                }`}
              >
                {label}
              </button>
            ))}
          </div>
        </div>

        <PushToggle />

        <div className="max-h-96 overflow-y-auto">
          {loading && notifications.length === 0 ? (
            <div className="p-4 text-center text-sm text-zinc-500">
              <div className="mx-auto mb-2 h-5 w-5 animate-spin rounded-full border-2 border-zinc-300 border-t-zinc-600" />
              Memuat...
            </div>
          ) : notifications.length === 0 ? (
            <div className="p-8 text-center text-zinc-500">
              <Bell className="mx-auto mb-2 h-8 w-8 opacity-50" />
              <p className="text-sm">Tidak ada notifikasi</p>
            </div>
          ) : (
            <div className="divide-y divide-zinc-100">
              {notifications.slice(0, 15).map((notification) => {
                const meta = adminNotificationMeta(notification.type);
                const Icon = meta.icon;
                return (
                  <button
                    key={notification.id}
                    onClick={() => void handleNotificationClick(notification)}
                    className={`w-full p-4 text-left transition-colors hover:bg-zinc-50 ${!notification.is_read ? 'bg-blue-50/50' : ''}`}
                  >
                    <div className="flex items-start gap-3">
                      <div className={`rounded-lg p-2 ${meta.tone}`}>
                        <Icon className="h-4 w-4" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="mb-1 flex items-center gap-2">
                          <span className="text-xs font-medium uppercase tracking-wide text-zinc-500">{meta.label}</span>
                          {!notification.is_read && <span className="h-2 w-2 rounded-full bg-blue-500" />}
                          <span className="ml-auto"><ActionBadge notification={notification} /></span>
                        </div>
                        <h4 className="truncate text-sm font-semibold text-zinc-900">{notification.title}</h4>
                        <p className="line-clamp-2 text-sm text-zinc-600">{notification.message}</p>
                        <p className="mt-1 text-xs text-zinc-500">{formatNotificationAge(notification.created_at)}</p>
                      </div>
                      <ChevronRight className="h-4 w-4 text-zinc-400" />
                    </div>
                  </button>
                );
              })}
            </div>
          )}
        </div>

        <div className="border-t border-zinc-100 bg-zinc-50 p-3">
          <button
            onClick={() => {
              onClose();
              navigate('/admin/notifications');
            }}
            className="w-full text-sm font-medium text-zinc-600 hover:text-zinc-900"
          >
            Lihat semua notifikasi
          </button>
        </div>
      </div>

      {selected && (
        <NotificationDetailDialog notification={selected} onClose={() => setSelected(null)} onChanged={refresh} />
      )}
      {detailLoading && (
        <div className="fixed inset-0 z-[55] flex items-center justify-center bg-zinc-950/20">
          <div className="rounded-xl bg-white px-4 py-3 text-sm text-zinc-600 shadow-lg">Memuat detail...</div>
        </div>
      )}
    </>
  );
}
