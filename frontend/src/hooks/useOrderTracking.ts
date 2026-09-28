import { useCallback, useEffect, useState } from 'react';
import { getCustomerOrderStatus, getCustomerRealtimeToken, type PosOrderPayload } from '@/lib/hellomApi';
import { subscribeRealtime } from '@/lib/realtime';
import { isOrderPending } from '@/lib/pos/orderStatus';

export function useOrderTracking(orderNumber: string | undefined, tableToken: string | undefined, intervalMs = 5000) {
  const [order, setOrder] = useState<PosOrderPayload | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lastUpdated, setLastUpdated] = useState<string | null>(null);

  const refresh = useCallback(async (background = false) => {
    if (!orderNumber || !tableToken) {
      setError('Nomor order tidak ditemukan.');
      setIsLoading(false);
      return;
    }

    if (background) {
      setIsRefreshing(true);
    } else {
      setIsLoading(true);
    }

    try {
      const data = await getCustomerOrderStatus(orderNumber, tableToken);
      setOrder(data.order);
      setLastUpdated(new Date().toISOString());
      setError(null);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : 'Gagal memuat status pesanan.');
    } finally {
      setIsLoading(false);
      setIsRefreshing(false);
    }
  }, [orderNumber, tableToken]);

  useEffect(() => {
    void refresh(false);
  }, [refresh]);

  // Live updates for this table; polling stays as the fallback (slower while the socket is up).
  const [live, setLive] = useState(false);
  useEffect(() => {
    if (!orderNumber || !tableToken) return undefined;
    return subscribeRealtime({
      getToken: () => getCustomerRealtimeToken(tableToken),
      onStatus: setLive,
      handlers: {
        'customer.order': (payload) => {
          const changed = (payload as { order?: { order_number?: string } } | null)?.order?.order_number;
          if (!changed || changed === orderNumber) void refresh(true);
        },
      },
    });
  }, [orderNumber, tableToken, refresh]);

  useEffect(() => {
    if (!orderNumber || !isOrderPending(order?.status)) {
      return;
    }

    const timer = window.setInterval(() => {
      void refresh(true);
    }, live ? Math.max(intervalMs, 30000) : intervalMs);

    return () => {
      window.clearInterval(timer);
    };
  }, [intervalMs, live, order?.status, orderNumber, refresh]);

  return {
    order,
    isLoading,
    isRefreshing,
    error,
    lastUpdated,
    refresh,
  };
}
