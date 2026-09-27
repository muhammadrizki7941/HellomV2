import { useState, useEffect, useCallback } from 'react';
import {
  getAdminNotifications,
  getAdminNotificationsUnreadCount,
  markAdminNotificationAsRead,
  markAllAdminNotificationsAsRead,
} from '@/lib/hellomApi';

export interface Notification {
  id: number;
  type: 'new_user' | 'new_transaction' | 'expiry_reminder';
  title: string;
  message: string;
  data: Record<string, unknown>;
  is_read: boolean;
  action_type?: string | null;
  action_url?: string | null;
  action_status?: 'pending' | 'done' | 'ignored' | null;
  action_done_at?: string | null;
  reference_id?: number | null;
  reference_type?: string | null;
  created_at: string;
  updated_at: string;
}

export function useNotifications() {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchNotifications = useCallback(async (page = 1, type?: string) => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      if (type) params.append('type', type);
      params.append('per_page', '50');

      const response = await getAdminNotifications(`?${params.toString()}`);
      setNotifications(response.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to fetch notifications');
    } finally {
      setLoading(false);
    }
  }, []);

  const fetchUnreadCount = useCallback(async () => {
    try {
      const response = await getAdminNotificationsUnreadCount();
      setUnreadCount(response.count);
    } catch (err) {
      console.error('Failed to fetch unread count:', err);
    }
  }, []);

  const markAsRead = useCallback(async (id: number) => {
    try {
      await markAdminNotificationAsRead(id);
      setNotifications(prev =>
        prev.map(notif =>
          notif.id === id ? { ...notif, is_read: true } : notif
        )
      );
      setUnreadCount(prev => Math.max(0, prev - 1));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to mark as read');
    }
  }, []);

  const markAllAsRead = useCallback(async () => {
    try {
      await markAllAdminNotificationsAsRead();
      setNotifications(prev =>
        prev.map(notif => ({ ...notif, is_read: true }))
      );
      setUnreadCount(0);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to mark all as read');
    }
  }, []);

  useEffect(() => {
    fetchUnreadCount();
    const interval = setInterval(fetchUnreadCount, 30000); // every 30 seconds
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
