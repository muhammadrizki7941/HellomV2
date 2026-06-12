import { useState } from 'react';
import { Bell, User, CreditCard, Clock, CheckCheck, ChevronRight, ExternalLink, X } from 'lucide-react';
import { Notification } from '@/hooks/useNotifications';
import {
  executeOwnerNotificationAction,
  getOwnerNotificationDetail,
  ignoreOwnerNotificationAction,
} from '@/lib/hellomApi';

interface NotificationDropdownProps {
  notifications: Notification[];
  loading: boolean;
  refreshNotifications: () => Promise<void>;
  refreshUnreadCount: () => Promise<void>;
  markAsRead: (id: number) => Promise<void>;
  markAllAsRead: () => Promise<void>;
  onClose: () => void;
}

const typeIcons = {
  new_user: User,
  new_transaction: CreditCard,
  expiry_reminder: Clock,
};

const typeLabels = {
  new_user: 'Pendaftar',
  new_transaction: 'Transaksi',
  expiry_reminder: 'Tenggat',
};

export default function NotificationDropdown({
  notifications,
  loading,
  refreshNotifications,
  refreshUnreadCount,
  markAsRead,
  markAllAsRead,
  onClose,
}: NotificationDropdownProps) {
  const [filter, setFilter] = useState<'all' | 'new_user' | 'new_transaction' | 'expiry_reminder'>('all');
  const [selectedNotification, setSelectedNotification] = useState<Notification | null>(null);
  const [isDetailOpen, setIsDetailOpen] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);

  const filteredNotifications = notifications.filter(notif =>
    filter === 'all' || notif.type === filter
  );

  const handleNotificationClick = async (notification: Notification) => {
    if (!notification.is_read) {
      await markAsRead(notification.id);
    }

    setDetailLoading(true);
    try {
      const detail = await getOwnerNotificationDetail(String(notification.id));
      setSelectedNotification(detail);
      setIsDetailOpen(true);
      await refreshUnreadCount();
      await refreshNotifications();
    } finally {
      setDetailLoading(false);
    }
  };

  const handleMarkAllRead = async () => {
    await markAllAsRead();
  };

  const handleExecuteAction = async () => {
    if (!selectedNotification) {
      return;
    }

    await executeOwnerNotificationAction(String(selectedNotification.id));
    const detail = await getOwnerNotificationDetail(String(selectedNotification.id));
    setSelectedNotification(detail);
    await refreshUnreadCount();
    await refreshNotifications();
    setIsDetailOpen(false);
  };

  const handleIgnoreAction = async () => {
    if (!selectedNotification) {
      return;
    }

    await ignoreOwnerNotificationAction(String(selectedNotification.id));
    const detail = await getOwnerNotificationDetail(String(selectedNotification.id));
    setSelectedNotification(detail);
    await refreshUnreadCount();
    await refreshNotifications();
    setIsDetailOpen(false);
  };

  const formatTime = (dateString: string) => {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / (1000 * 60));
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Baru saja';
    if (diffMins < 60) return `${diffMins} menit lalu`;
    if (diffHours < 24) return `${diffHours} jam lalu`;
    return `${diffDays} hari lalu`;
  };

  const formatDateTime = (dateString?: string | null) => {
    if (!dateString) {
      return '-';
    }

    return new Date(dateString).toLocaleString('id-ID', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const renderActionBadge = (notification: Notification) => {
    if (notification.action_status === 'done') {
      return <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">✓ Selesai</span>;
    }

    if (notification.action_status === 'ignored') {
      return <span className="text-[11px] text-zinc-400">Diabaikan</span>;
    }

    if (notification.action_status === 'pending' && notification.action_type) {
      const map: Record<string, string> = {
        verify_payment: 'rounded-full bg-orange-100 px-2 py-0.5 text-[11px] font-semibold text-orange-700',
        open_access: 'rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700',
        process_refund: 'rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700',
        view_user: 'rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-semibold text-blue-700',
      };
      const labels: Record<string, string> = {
        verify_payment: 'Verifikasi',
        open_access: 'Buka Akses',
        process_refund: 'Proses Refund',
        view_user: 'Lihat User',
      };

      return (
        <span className={map[notification.action_type] || 'rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-700'}>
          {labels[notification.action_type] || notification.action_type}
        </span>
      );
    }

    return null;
  };

  return (
    <>
    <div className="w-96 bg-white border border-zinc-200 rounded-xl shadow-lg overflow-hidden">
      {/* Header */}
      <div className="p-4 border-b border-zinc-100">
        <div className="flex items-center justify-between mb-3">
          <h3 className="font-semibold text-zinc-900 flex items-center gap-2">
            <Bell className="w-5 h-5" />
            Notifikasi
          </h3>
          {notifications.some(n => !n.is_read) && (
            <button
              onClick={handleMarkAllRead}
              className="text-xs text-zinc-600 hover:text-zinc-900 flex items-center gap-1"
            >
              <CheckCheck className="w-4 h-4" />
              Tandai Semua Dibaca
            </button>
          )}
        </div>

        {/* Filter Tabs */}
        <div className="flex gap-1">
          {[
            { key: 'all' as const, label: 'Semua' },
            { key: 'new_user' as const, label: 'Pendaftar' },
            { key: 'new_transaction' as const, label: 'Transaksi' },
            { key: 'expiry_reminder' as const, label: 'Tenggat' },
          ].map(({ key, label }) => (
            <button
              key={key}
              onClick={() => setFilter(key)}
              className={`px-3 py-1 text-xs rounded-full transition-colors ${
                filter === key
                  ? 'bg-zinc-900 text-white'
                  : 'text-zinc-600 hover:bg-zinc-100'
              }`}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {/* Notifications List */}
      <div className="max-h-96 overflow-y-auto">
        {loading ? (
          <div className="p-4 text-center text-zinc-500">
            <div className="animate-spin w-5 h-5 border-2 border-zinc-300 border-t-zinc-600 rounded-full mx-auto mb-2"></div>
            Memuat...
          </div>
        ) : filteredNotifications.length === 0 ? (
          <div className="p-8 text-center text-zinc-500">
            <Bell className="w-8 h-8 mx-auto mb-2 opacity-50" />
            <p className="text-sm">Tidak ada notifikasi</p>
          </div>
        ) : (
          <div className="divide-y divide-zinc-100">
            {filteredNotifications.slice(0, 10).map((notification) => {
              const Icon = typeIcons[notification.type];
              return (
                <button
                  key={notification.id}
                  onClick={() => handleNotificationClick(notification)}
                  className={`w-full p-4 text-left hover:bg-zinc-50 transition-colors ${
                    !notification.is_read ? 'bg-blue-50/50' : ''
                  }`}
                >
                  <div className="flex items-start gap-3">
                    <div className={`p-2 rounded-lg ${
                      notification.type === 'new_user' ? 'bg-green-100 text-green-600' :
                      notification.type === 'new_transaction' ? 'bg-blue-100 text-blue-600' :
                      'bg-amber-100 text-amber-600'
                    }`}>
                      <Icon className="w-4 h-4" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-1">
                        <span className="text-xs font-medium text-zinc-500 uppercase tracking-wide">
                          {typeLabels[notification.type]}
                        </span>
                        {!notification.is_read && (
                          <div className="w-2 h-2 bg-blue-500 rounded-full"></div>
                        )}
                      </div>
                      <h4 className="text-sm font-semibold text-zinc-900 truncate">
                        {notification.title}
                      </h4>
                      <div className="mt-1">
                        {renderActionBadge(notification)}
                      </div>
                      <p className="text-sm text-zinc-600 line-clamp-2">
                        {notification.message}
                      </p>
                      <p className="text-xs text-zinc-500 mt-1">
                        {formatTime(notification.created_at)}
                      </p>
                    </div>
                    <ChevronRight className="w-4 h-4 text-zinc-400" />
                  </div>
                </button>
              );
            })}
          </div>
        )}
      </div>

      {/* Footer */}
      {filteredNotifications.length > 0 && (
        <div className="p-3 border-t border-zinc-100 bg-zinc-50">
          <button
            onClick={onClose}
            className="w-full text-sm text-zinc-600 hover:text-zinc-900 font-medium"
          >
            Lihat Semua Notifikasi
          </button>
        </div>
      )}
    </div>
    {isDetailOpen && selectedNotification && (
      <div className="fixed inset-0 z-[60] flex items-center justify-center bg-zinc-950/45 p-4">
        <div className="w-full max-w-lg rounded-2xl border border-zinc-200 bg-white shadow-2xl">
          <div className="flex items-start justify-between border-b border-zinc-100 px-5 py-4">
            <div>
              <h3 className="text-lg font-semibold text-zinc-900">{selectedNotification.title}</h3>
              <p className="mt-1 text-xs text-zinc-500">{formatDateTime(selectedNotification.created_at)}</p>
            </div>
            <button
              onClick={() => setIsDetailOpen(false)}
              className="rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700"
            >
              <X className="h-4 w-4" />
            </button>
          </div>

          <div className="space-y-4 px-5 py-4">
            <p className="text-sm leading-6 text-zinc-700">{selectedNotification.message}</p>

            {selectedNotification.reference_type && selectedNotification.reference_id && (
              <div className="rounded-xl bg-zinc-50 px-4 py-3 text-sm text-zinc-600">
                Referensi: {selectedNotification.reference_type} #{selectedNotification.reference_id}
              </div>
            )}

            {selectedNotification.action_status === 'done' && (
              <div className="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                Selesai pada {formatDateTime(selectedNotification.action_done_at)}
              </div>
            )}
          </div>

          <div className="flex flex-wrap justify-end gap-3 border-t border-zinc-100 px-5 py-4">
            {selectedNotification.action_status === 'pending' && selectedNotification.action_type ? (
              <>
                {selectedNotification.action_url && (
                  <button
                    onClick={() => window.open(selectedNotification.action_url ?? '', '_blank')}
                    className="inline-flex items-center gap-2 rounded-xl border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
                  >
                    Buka Detail <ExternalLink className="h-4 w-4" />
                  </button>
                )}
                <button
                  onClick={() => void handleExecuteAction()}
                  className="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800"
                >
                  ✓ Tandai Selesai
                </button>
                <button
                  onClick={() => void handleIgnoreAction()}
                  className="rounded-xl border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-50"
                >
                  Abaikan
                </button>
              </>
            ) : null}
            {(selectedNotification.action_status !== 'pending' || !selectedNotification.action_type) && (
              <button
                onClick={() => setIsDetailOpen(false)}
                className="rounded-xl border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-50"
              >
                Tutup
              </button>
            )}
          </div>
        </div>
      </div>
    )}
    {detailLoading && (
      <div className="fixed inset-0 z-[55] flex items-center justify-center bg-zinc-950/20">
        <div className="rounded-xl bg-white px-4 py-3 text-sm text-zinc-600 shadow-lg">Memuat detail...</div>
      </div>
    )}
    </>
  );
}
