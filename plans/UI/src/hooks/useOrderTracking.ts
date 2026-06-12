import { useCallback, useEffect, useState } from 'react';
import { getCustomerOrderStatus, type PosOrderPayload } from '@/lib/pos/posApi';
import { isOrderPending } from '@/lib/pos/orderStatus';

export function useOrderTracking(orderNumber: string | undefined, intervalMs = 5000) {
  const [order, setOrder] = useState<PosOrderPayload | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [lastUpdated, setLastUpdated] = useState<string | null>(null);

  const refresh = useCallback(async (background = false) => {
    if (!orderNumber) {
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
      const data = await getCustomerOrderStatus(orderNumber);
      setOrder(data.order);
      setLastUpdated(new Date().toISOString());
      setError(null);
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : 'Gagal memuat status pesanan.');
    } finally {
      setIsLoading(false);
      setIsRefreshing(false);
    }
  }, [orderNumber]);

  useEffect(() => {
    void refresh(false);
  }, [refresh]);

  useEffect(() => {
    if (!orderNumber || !isOrderPending(order?.status)) {
      return;
    }

    const timer = window.setInterval(() => {
      void refresh(true);
    }, intervalMs);

    return () => {
      window.clearInterval(timer);
    };
  }, [intervalMs, order?.status, orderNumber, refresh]);

  return {
    order,
    isLoading,
    isRefreshing,
    error,
    lastUpdated,
    refresh,
  };
}
