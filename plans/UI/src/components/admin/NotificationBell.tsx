import { useEffect, useState } from 'react';
import { Bell } from 'lucide-react';
import { useNotifications } from '@/hooks/useNotifications';
import { HELLOM_REALTIME_PUBLIC_URL } from '@/lib/hellomApi';
import NotificationDropdown from './NotificationDropdown';

declare global {
  interface Window {
    io?: (url: string, options?: Record<string, unknown>) => {
      on: (event: string, handler: (...args: unknown[]) => void) => void;
      off?: (event: string, handler?: (...args: unknown[]) => void) => void;
      disconnect: () => void;
    };
  }
}

export default function NotificationBell() {
  const {
    notifications,
    unreadCount,
    loading,
    fetchNotifications,
    fetchUnreadCount,
    markAsRead,
    markAllAsRead,
  } = useNotifications();
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    fetchUnreadCount();
    fetchNotifications();
  }, [fetchNotifications, fetchUnreadCount, isOpen]);

  useEffect(() => {
    let cancelled = false;
    let socket: {
      on: (event: string, handler: (...args: unknown[]) => void) => void;
      off?: (event: string, handler?: (...args: unknown[]) => void) => void;
      disconnect: () => void;
    } | null = null;

    const handleNotificationCreated = () => {
      void fetchUnreadCount();
      void fetchNotifications();
    };

    const ensureSocketIoScript = async () => {
      if (window.io) {
        return true;
      }

      const scriptUrl = `${HELLOM_REALTIME_PUBLIC_URL.replace(/\/$/, '')}/socket.io/socket.io.js`;

      return await new Promise<boolean>((resolve) => {
        const existingScript = document.querySelector<HTMLScriptElement>(`script[data-socket-io="${scriptUrl}"]`);
        if (existingScript) {
          if (window.io) {
            resolve(true);
            return;
          }

          existingScript.addEventListener('load', () => resolve(true), { once: true });
          existingScript.addEventListener('error', () => resolve(false), { once: true });
          return;
        }

        const script = document.createElement('script');
        script.src = scriptUrl;
        script.async = true;
        script.dataset.socketIo = scriptUrl;
        script.onload = () => resolve(true);
        script.onerror = () => resolve(false);
        document.head.appendChild(script);
      });
    };

    const connectRealtime = async () => {
      const ready = await ensureSocketIoScript();
      if (!ready || cancelled || !window.io) {
        return;
      }

      socket = window.io(HELLOM_REALTIME_PUBLIC_URL, {
        transports: ['websocket', 'polling'],
        timeout: 2000,
      });

      socket.on('connect', handleNotificationCreated);
      socket.on('admin.notification.created', handleNotificationCreated);
    };

    void connectRealtime();

    return () => {
      cancelled = true;

      if (socket?.off) {
        socket.off('connect', handleNotificationCreated);
        socket.off('admin.notification.created', handleNotificationCreated);
      }

      socket?.disconnect();
    };
  }, [fetchNotifications, fetchUnreadCount]);

  return (
    <div className="relative">
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="relative p-2 text-zinc-600 hover:text-zinc-900 hover:bg-zinc-100 rounded-lg transition-colors"
        title="Notifications"
      >
        <Bell className="w-5 h-5" />
        {unreadCount > 0 && (
          <span className="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {isOpen && (
        <>
          {/* Backdrop */}
          <div
            className="fixed inset-0 z-40"
            onClick={() => setIsOpen(false)}
          />
          {/* Dropdown */}
          <div className="absolute right-0 top-12 z-50">
            <NotificationDropdown
              notifications={notifications}
              loading={loading}
              refreshNotifications={fetchNotifications}
              refreshUnreadCount={fetchUnreadCount}
              markAsRead={markAsRead}
              markAllAsRead={markAllAsRead}
              onClose={() => setIsOpen(false)}
            />
          </div>
        </>
      )}
    </div>
  );
}
