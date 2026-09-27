import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  Bell,
  CheckCheck,
  CheckCircle,
  Clock,
  CreditCard,
  Gift,
  RotateCcw,
  Wrench,
} from 'lucide-react';
import {
  ConsumerNotification,
  useConsumerNotifications,
} from '@/hooks/useConsumerNotifications';

const typeIcons: Record<string, typeof Bell> = {
  transaction: CreditCard,
  refund: RotateCcw,
  access: CheckCircle,
  expiry: Clock,
  promo: Gift,
  maintenance: Wrench,
};

function timeAgo(dateString: string) {
  const date = new Date(dateString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMins = Math.floor(diffMs / (1000 * 60));
  const diffHours = Math.floor(diffMins / 60);
  const diffDays = Math.floor(diffHours / 24);

  if (diffMins < 1) return 'Baru saja';
  if (diffMins < 60) return `${diffMins} menit lalu`;
  if (diffHours < 24) return `${diffHours} jam lalu`;
  if (diffDays < 7) return `${diffDays} hari lalu`;

  return date.toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  });
}

export default function NotificationBell() {
  const navigate = useNavigate();
  const {
    notifications,
    unreadCount,
    loading,
    fetchNotifications,
    markAsRead,
    markAllAsRead,
  } = useConsumerNotifications();
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    void fetchNotifications();
  }, [fetchNotifications, isOpen]);

  const handleItemClick = async (notification: ConsumerNotification) => {
    if (!notification.is_read) {
      await markAsRead(notification.id);
    }

    if (notification.action_url) {
      navigate(notification.action_url);
    }

    setIsOpen(false);
  };

  const handleMarkAllRead = async () => {
    await markAllAsRead();
  };

  return (
    <div className="relative">
      <button
        onClick={() => setIsOpen(current => !current)}
        className="relative rounded-xl border border-zinc-200 bg-white p-2 text-zinc-600 transition-colors hover:border-zinc-300 hover:text-zinc-900"
        title="Notifikasi"
      >
        <Bell className="h-5 w-5" />
        {unreadCount > 0 && (
          <span className="absolute -right-1 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-blue-600 px-1 text-[11px] font-semibold text-white">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {isOpen && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setIsOpen(false)} />
          <div className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-50 w-[calc(100vw-2rem)] max-w-md md:w-96 md:fixed md:left-auto md:top-12 md:-translate-x-0 md:-translate-y-0 md:right-0 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xl md:absolute">
            <div className="border-b border-zinc-100 p-4">
              <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="flex items-center gap-2 font-semibold text-zinc-900">
                  <Bell className="h-5 w-5" />
                  Notifikasi
                </h3>
                {notifications.some(notification => !notification.is_read) && (
                  <button
                    onClick={handleMarkAllRead}
                    className="flex items-center gap-1 text-xs text-zinc-600 hover:text-zinc-900"
                  >
                    <CheckCheck className="h-4 w-4" />
                    Tandai semua dibaca
                  </button>
                )}
              </div>
            </div>

            <div className="max-h-96 overflow-y-auto">
              {loading ? (
                <div className="p-6 text-center text-sm text-zinc-500">Memuat...</div>
              ) : notifications.length === 0 ? (
                <div className="p-8 text-center text-sm text-zinc-500">
                  <Bell className="mx-auto mb-2 h-8 w-8 opacity-50" />
                  Tidak ada notifikasi
                </div>
              ) : (
                <div className="divide-y divide-zinc-100">
                  {notifications.map(notification => {
                    const Icon = typeIcons[notification.type] || Bell;
                    return (
                      <button
                        key={notification.id}
                        onClick={() => void handleItemClick(notification)}
                        className={`w-full px-4 py-4 text-left transition-colors hover:bg-zinc-50 ${
                          notification.is_read ? 'bg-white' : 'bg-[#EFF6FF]'
                        }`}
                      >
                        <div className="flex items-start gap-3">
                          <div className="rounded-xl bg-zinc-100 p-2 text-zinc-700">
                            <Icon className="h-4 w-4" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <div className="mb-1 flex items-center gap-2">
                              <h4 className="truncate text-sm font-semibold text-zinc-900">
                                {notification.title}
                              </h4>
                              {!notification.is_read && (
                                <span className="h-2 w-2 rounded-full bg-blue-500" />
                              )}
                            </div>
                            <p className="line-clamp-2 text-sm text-zinc-600">
                              {notification.body}
                            </p>
                            <p className="mt-1 text-xs text-zinc-500">
                              {timeAgo(notification.created_at)}
                            </p>
                          </div>
                        </div>
                      </button>
                    );
                  })}
                </div>
              )}
            </div>

            <div className="border-t border-zinc-100 bg-zinc-50 p-3">
              <Link
                to="/dashboard/my-purchases"
                onClick={() => setIsOpen(false)}
                className="block w-full text-center text-sm font-medium text-zinc-600 hover:text-zinc-900"
              >
                Buka produk saya
              </Link>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
