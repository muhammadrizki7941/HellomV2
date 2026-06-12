import { useState, useEffect, useCallback } from 'react';
import {
  getConsumerNotifications,
  getConsumerNotificationsUnreadCount,
  markConsumerNotificationAsRead,
  markAllConsumerNotificationsAsRead,
} from '@/lib/hellomApi';

export interface ConsumerNotification {
  id: number;
  type: string;
  title: string;
  body: string;
  data: Record<string, unknown> | null;
  is_read: boolean;
  read_at: string | null;
  action_type: string | null;
  action_url: string | null;
  created_at: string;
  updated_at: string;
}

export function useConsumerNotifications() {
  const [notifications, setNotifications] = useState<ConsumerNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchNotifications = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await getConsumerNotifications();
      setNotifications(response.notifications);
      setUnreadCount(response.unread_count);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to fetch notifications');
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchUnreadCount = useCallback(async () => {
    try {
      const response = await getConsumerNotificationsUnreadCount();
      setUnreadCount(response.count);
    } catch (err) {
      console.error('Failed to fetch consumer unread count:', err);
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
      setError(err instanceof Error ? err.message : 'Failed to mark as read');
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
      setError(err instanceof Error ? err.message : 'Failed to mark all as read');
    }
  }, []);

  useEffect(() => {
    fetchUnreadCount();
    const interval = setInterval(fetchUnreadCount, 30000);
    return () => clearInterval(interval);
  }, [fetchUnreadCount]);

  return {
    notifications,
    unreadCount,
    loading,
    error,
    fetchNotifications,
    fetchUnreadCount,
    markAsRead,
    markAllAsRead,
  };
}
