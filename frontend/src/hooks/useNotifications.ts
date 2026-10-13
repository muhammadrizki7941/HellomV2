import { useState, useEffect, useCallback, useRef } from 'react';
import {
  getAdminNotifications,
  getAdminNotificationsUnreadCount,
  markAdminNotificationAsRead,
  markAllAdminNotificationsAsRead,
} from '@/lib/hellomApi';
import type { AdminNotificationQuery, OwnerNotification } from '@/lib/hellomApi';
import { subscribeLiveNotifications } from '@/lib/liveNotifications';

export type Notification = OwnerNotification;

export type AdminNotificationBadges = { pending: number; openWithdrawals: number };

type PageMeta = { current_page: number; per_page: number; total: number; last_page: number };

// Live: realtime "admins" room. Polling stays as the fallback (slow while the socket is up).
const POLL_LIVE_MS = 120000;
const POLL_OFFLINE_MS = 30000;

/**
 * Super admin notifications. `live: false` for secondary users of the hook (e.g. the full
 * page) so only the layout keeps the count fresh; they can still react to `lastEventAt`.
 */
export function useNotifications(options: { live?: boolean } = {}) {
  const live = options.live ?? true;
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [unreadCount, setUnreadCount] = useState(0);
  const [badges, setBadges] = useState<AdminNotificationBadges>({ pending: 0, openWithdrawals: 0 });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [connected, setConnected] = useState(false);
  const [lastEventAt, setLastEventAt] = useState(0);
  const requestSeq = useRef(0);

  const fetchNotifications = useCallback(async (query: AdminNotificationQuery = {}) => {
    const seq = ++requestSeq.current;
    setLoading(true);
    setError(null);
    try {
      const response = await getAdminNotifications({ per_page: 20, ...query });
      if (seq !== requestSeq.current) return;
      setNotifications(response.data);
      setMeta(response.meta);
    } catch (err) {
      if (seq === requestSeq.current) setError(err instanceof Error ? err.message : 'Gagal memuat notifikasi');
    } finally {
      if (seq === requestSeq.current) setLoading(false);
    }
  }, []);

  const fetchUnreadCount = useCallback(async () => {
    try {
      const response = await getAdminNotificationsUnreadCount();
      setUnreadCount(response.count);
      setBadges({ pending: response.pending ?? 0, openWithdrawals: response.open_withdrawals ?? 0 });
    } catch {
      // Keep the last known count; the next poll retries.
    }
  }, []);

  const markAsRead = useCallback(async (id: number) => {
    try {
      await markAdminNotificationAsRead(id);
      setNotifications(prev => prev.map(notif => (notif.id === id ? { ...notif, is_read: true } : notif)));
      setUnreadCount(prev => Math.max(0, prev - 1));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menandai dibaca');
    }
  }, []);

  const markAllAsRead = useCallback(async () => {
    try {
      await markAllAdminNotificationsAsRead();
      setNotifications(prev => prev.map(notif => ({ ...notif, is_read: true })));
      setUnreadCount(0);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menandai semua dibaca');
    }
  }, []);

  useEffect(() => {
    if (!live) return;
    return subscribeLiveNotifications('admin', () => {
      setLastEventAt(Date.now());
      void fetchUnreadCount();
    }, setConnected);
  }, [fetchUnreadCount, live]);

  useEffect(() => {
    if (!live) return;
    void fetchUnreadCount();
    const interval = window.setInterval(fetchUnreadCount, connected ? POLL_LIVE_MS : POLL_OFFLINE_MS);
    const onFocus = () => void fetchUnreadCount();
    window.addEventListener('focus', onFocus);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', onFocus);
    };
  }, [connected, fetchUnreadCount, live]);

  return {
    notifications,
    meta,
    unreadCount,
    badges,
    loading,
    error,
    connected,
    lastEventAt,
    fetchNotifications,
    fetchUnreadCount,
    markAsRead,
    markAllAsRead,
  };
}

export type AdminNotificationsState = ReturnType<typeof useNotifications>;
