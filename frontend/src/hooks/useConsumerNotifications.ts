import { useState, useEffect, useCallback } from 'react';
import {
  getConsumerNotifications,
  getConsumerNotificationsUnreadCount,
  markConsumerNotificationAsRead,
  markAllConsumerNotificationsAsRead,
} from '@/lib/hellomApi';
import type { ConsumerNotification } from '@/lib/hellomApi';
import { subscribeLiveNotifications } from '@/lib/liveNotifications';

export type { ConsumerNotification };

// Live: realtime room user_{id}. Polling stays as the fallback (slow while the socket is up).
const POLL_LIVE_MS = 120000;
const POLL_OFFLINE_MS = 30000;

export function useConsumerNotifications() {
  const [notifications, setNotifications] = useState<ConsumerNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [connected, setConnected] = useState(false);
  const [lastEventAt, setLastEventAt] = useState(0);

  const fetchNotifications = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await getConsumerNotifications();
      setNotifications(response.notifications);
      setUnreadCount(response.unread_count);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat notifikasi');
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchUnreadCount = useCallback(async () => {
    try {
      const response = await getConsumerNotificationsUnreadCount();
      setUnreadCount(response.count);
    } catch {
      // Keep the last known count; the next poll retries.
    }
  }, []);

  const markAsRead = useCallback(async (id: number) => {
    try {
      await markConsumerNotificationAsRead(String(id));
      setNotifications(prev =>
        prev.map(notification =>
          notification.id === id
            ? { ...notification, is_read: true, read_at: new Date().toISOString() }
            : notification
        )
      );
      setUnreadCount(prev => Math.max(0, prev - 1));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menandai dibaca');
    }
  }, []);

  const markAllAsRead = useCallback(async () => {
    try {
      await markAllConsumerNotificationsAsRead();
      const readAt = new Date().toISOString();
      setNotifications(prev =>
        prev.map(notification => ({
          ...notification,
          is_read: true,
          read_at: notification.read_at ?? readAt,
        }))
      );
      setUnreadCount(0);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menandai semua dibaca');
    }
  }, []);

  useEffect(() => subscribeLiveNotifications('user', () => {
    setLastEventAt(Date.now());
    void fetchUnreadCount();
  }, setConnected), [fetchUnreadCount]);

  useEffect(() => {
    void fetchUnreadCount();
    const interval = window.setInterval(fetchUnreadCount, connected ? POLL_LIVE_MS : POLL_OFFLINE_MS);
    const onFocus = () => void fetchUnreadCount();
    window.addEventListener('focus', onFocus);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', onFocus);
    };
  }, [connected, fetchUnreadCount]);

  return {
    notifications,
    unreadCount,
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

export type ConsumerNotificationsState = ReturnType<typeof useConsumerNotifications>;
