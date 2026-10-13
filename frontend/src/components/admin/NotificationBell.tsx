import { useEffect, useState } from 'react';
import { Bell } from 'lucide-react';
import type { AdminNotificationsState } from '@/hooks/useNotifications';
import { setTitleBadge } from '@/lib/liveNotifications';
import NotificationDropdown from './NotificationDropdown';
import { groupTypes, type AdminNotificationGroup } from './notificationTypes';

declare global {
  interface Window {
    io?: (url: string, options?: Record<string, unknown>) => {
      on: (event: string, handler: (...args: unknown[]) => void) => void;
      off?: (event: string, handler?: (...args: unknown[]) => void) => void;
      disconnect: () => void;
    };
  }
}

/** Super admin bell. The state (count, realtime) lives in AdminLayout so the sidebar badges share it. */
export default function NotificationBell({ state }: { state: AdminNotificationsState }) {
  const { unreadCount, lastEventAt, fetchNotifications, fetchUnreadCount } = state;
  const [isOpen, setIsOpen] = useState(false);
  const [group, setGroup] = useState<AdminNotificationGroup>('all');

  useEffect(() => {
    setTitleBadge(unreadCount);
  }, [unreadCount]);

  useEffect(() => () => setTitleBadge(0), []);

  useEffect(() => {
    if (!isOpen) return;
    const types = groupTypes(group);
    void fetchNotifications({ per_page: 15, type: types.length ? types.join(',') : undefined });
  }, [fetchNotifications, group, isOpen, lastEventAt]);

  useEffect(() => {
    if (isOpen) void fetchUnreadCount();
  }, [fetchUnreadCount, isOpen]);

  return (
    <div className="relative">
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="relative p-2 text-zinc-600 hover:text-zinc-900 hover:bg-zinc-100 rounded-lg transition-colors"
        title="Notifikasi"
        aria-label={unreadCount > 0 ? `Notifikasi, ${unreadCount} belum dibaca` : 'Notifikasi'}
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
          <div className="fixed inset-0 z-40" onClick={() => setIsOpen(false)} />
          <div className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-50 w-[calc(100vw-2rem)] max-w-md lg:absolute lg:left-auto lg:top-12 lg:-translate-x-0 lg:-translate-y-0 lg:right-0 lg:w-[26rem]">
            <NotificationDropdown
              state={state}
              group={group}
              onGroupChange={setGroup}
              onClose={() => setIsOpen(false)}
            />
          </div>
        </>
      )}
    </div>
  );
}
