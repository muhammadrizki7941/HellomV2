import { useEffect, useMemo, useState } from 'react';
import { Search, Clock, CheckCircle, XCircle, ShoppingCart, RotateCcw, Info } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getPosOrders, updatePosOrderStatus } from '@/lib/hellomApi';
import NewOrderModal from '@/components/pos/NewOrderModal';
import ReceiptModal from '@/components/pos/ReceiptModal';
import PaymentModal from '@/components/pos/PaymentModal';
import {
  ensurePosOrderListResetAt,
  getPosOrderListResetEventName,
  isOrderVisibleAfterReset,
  resetPosOrderListNow,
} from '@/lib/posOrderListReset';

const ACTIVE_ORDER_STATUSES = ['new', 'accepted', 'preparing', 'prepared'];

type PosOrder = {
  id: number;
  order_number: string;
  customer_name: string;
  table_label: string;
  service_type: string;
  status: string;
  payment_status: string;
  payment_method?: string;
  total_amount: number;
  final_amount?: number;
  created_at: string;
  items_count: number;
};

export default function PosOrders() {
  const [orders, setOrders] = useState<PosOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [showNewOrderModal, setShowNewOrderModal] = useState(false);
  const [receiptOrderId, setReceiptOrderId] = useState<number | null>(null);
  const [showReceiptModal, setShowReceiptModal] = useState(false);
  const [showPaymentModal, setShowPaymentModal] = useState(false);
  const [currentPaymentOrder, setCurrentPaymentOrder] = useState<any>(null);
  const [listResetAt, setListResetAt] = useState(() => ensurePosOrderListResetAt());

  useEffect(() => {
    void loadOrders();
  }, []);

  useEffect(() => {
    const syncResetAt = () => {
      setListResetAt(ensurePosOrderListResetAt());
    };

    syncResetAt();

    const interval = window.setInterval(syncResetAt, 60000);
    window.addEventListener(getPosOrderListResetEventName(), syncResetAt);

    return () => {
      window.clearInterval(interval);
      window.removeEventListener(getPosOrderListResetEventName(), syncResetAt);
    };
  }, []);

  useEffect(() => {
    if (!autoRefresh) return;

    const interval = window.setInterval(() => {
      void loadOrders();
    }, 30000);

    return () => window.clearInterval(interval);
  }, [autoRefresh, statusFilter]);

  const loadOrders = async () => {
    try {
      setLoading(true);
      setError(null);
      const result = await getPosOrders(statusFilter);
      setOrders(result.orders || []);
      window.dispatchEvent(new CustomEvent('pos-orders-updated'));
    } catch {
      setError('Gagal memuat pesanan');
    } finally {
      setLoading(false);
    }
  };

  const handleStatusUpdate = async (orderId: number, newStatus: string) => {
    try {
      await updatePosOrderStatus(orderId, newStatus);
      await loadOrders();
      window.dispatchEvent(new CustomEvent('pos-orders-updated'));
    } catch {
      alert('Gagal memperbarui status pesanan');
    }
  };

  const handleResetOrderList = () => {
    const resetAt = resetPosOrderListNow();
    setListResetAt(resetAt);
    window.dispatchEvent(new CustomEvent('pos-orders-updated'));
  };

  const handlePaymentSuccess = (paymentData: any) => {
    const paidOrderId = currentPaymentOrder?.id ?? paymentData?.order?.id ?? null;

    setShowPaymentModal(false);
    setCurrentPaymentOrder(null);

    // Tampilkan info kembalian jika ada
    if (paymentData.change_amount > 0) {
      alert(
        `✅ Pembayaran berhasil!\n\n` +
        `Kembalian: Rp ${paymentData.change_amount.toLocaleString('id-ID')}`
      );
    }

    // Refresh list orders
    void loadOrders();

    // Auto buka kwitansi
    if (paidOrderId) {
      setReceiptOrderId(paidOrderId);
      setShowReceiptModal(true);
    }
  };

  const visibleOrders = useMemo(
    () => orders.filter((order) => isOrderVisibleAfterReset(order.created_at, listResetAt)),
    [orders, listResetAt]
  );

  const filteredOrders = visibleOrders.filter((order) => {
    const matchesStatus = statusFilter === 'all' || order.status === statusFilter;
    const matchesSearch =
      order.order_number.includes(searchTerm) ||
      (order.customer_name || '').toLowerCase().includes(searchTerm.toLowerCase());

    return matchesStatus && matchesSearch;
  });

  const unfinishedOrdersCount = visibleOrders.filter((order) =>
    ACTIVE_ORDER_STATUSES.includes(order.status)
  ).length;

  const resetTimeLabel = listResetAt.toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'completed': return 'bg-green-100 text-green-900 border-green-200';
      case 'prepared': return 'bg-orange-100 text-orange-800 border-orange-200';
      case 'preparing': return 'bg-orange-100 text-orange-800 border-orange-200';
      case 'accepted': return 'bg-orange-100 text-orange-800 border-orange-200';
      case 'new': return 'bg-white text-gray-900 border-gray-200';
      case 'cancelled': return 'bg-red-100 text-red-800 border-red-200';
      default: return 'bg-gray-100 text-gray-800 border-gray-200';
    }
  };

  const getRowBackgroundColor = (status: string) => {
    switch (status) {
      case 'completed': return 'bg-green-50/50';
      case 'prepared': return 'bg-orange-50/50';
      case 'preparing': return 'bg-orange-50/50';
      case 'accepted': return 'bg-orange-50/50';
      case 'new': return 'bg-white';
      case 'cancelled': return 'bg-red-50/50';
      default: return 'bg-white';
    }
  };

  const getStatusText = (status: string) => {
    switch (status) {
      case 'completed': return 'Completed';
      case 'prepared': return 'Ready';
      case 'preparing': return 'Processing';
      case 'accepted': return 'Accepted';
      case 'new': return 'New Order';
      case 'cancelled': return 'Cancelled';
      default: return status;
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'completed': return <CheckCircle className="w-4 h-4 text-green-600" />;
      case 'prepared': return <CheckCircle className="w-4 h-4 text-orange-600" />;
      case 'preparing': return <Clock className="w-4 h-4 text-orange-600" />;
      case 'accepted': return <Clock className="w-4 h-4 text-orange-600" />;
      case 'new': return <Clock className="w-4 h-4 text-gray-600" />;
      case 'cancelled': return <XCircle className="w-4 h-4 text-red-600" />;
      default: return <Clock className="w-4 h-4 text-gray-600" />;
    }
  };

  const PaymentBadge = ({ method, status }: { method?: string; status: string }) => {
    if (status !== 'paid') return (
      <span className="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-medium">
        Belum Bayar
      </span>
    );

    const map: Record<string, { icon: string; label: string; color: string }> = {
      cash: { icon: '💵', label: 'Tunai', color: 'green' },
      transfer: { icon: '🏦', label: 'Transfer', color: 'blue' },
      qris: { icon: '📱', label: 'QRIS', color: 'purple' },
      other: { icon: '💳', label: 'Lainnya', color: 'gray' },
    };
    const m = map[method || ''] || map.other;

    return (
      <span className={`text-xs bg-${m.color}-100 text-${m.color}-700 px-2 py-0.5 rounded-full font-medium`}>
        {m.icon} {m.label}
      </span>
    );
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="mx-auto max-w-7xl space-y-6">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Order List</h1>
            <p className="mt-1 text-gray-600">Pantau dan atur semua pesanan resto ya</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <button
              onClick={() => void loadOrders()}
              className="rounded-lg bg-gray-100 px-3 py-1.5 text-sm text-gray-700 transition-colors hover:bg-gray-200"
            >
              Refresh
            </button>
            <button
              onClick={() => setAutoRefresh(!autoRefresh)}
              className={cn(
                'rounded-lg px-3 py-1.5 text-sm transition-colors',
                autoRefresh
                  ? 'bg-amber-100 text-amber-900 hover:bg-amber-200'
                  : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
              )}
            >
              Auto: {autoRefresh ? 'ON' : 'OFF'}
            </button>
            <button
              onClick={() => setShowNewOrderModal(true)}
              className="flex items-center gap-1.5 rounded-lg bg-amber-400 px-3 py-1.5 text-sm text-[#111111] transition-colors hover:bg-amber-500"
            >
              <ShoppingCart className="h-4 w-4" />
              Add Order
            </button>
            <button
              onClick={handleResetOrderList}
              className="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 transition-colors hover:bg-gray-50"
              title="Bersihkan list order di tampilan frontend"
            >
              <RotateCcw className="h-4 w-4" />
              Reset List
            </button>
          </div>
        </div>

        <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
          <div className="flex items-start gap-2">
            <Info className="mt-0.5 h-4 w-4 flex-none" />
            <p>
              Reset list hanya membersihkan tampilan order di frontend. Data order tetap tersimpan dan tetap bisa dipakai untuk laporan. Reset otomatis berjalan setiap hari pukul 06.00, dan reset terakhir tercatat pada {resetTimeLabel}.
            </p>
          </div>
        </div>

        {unfinishedOrdersCount > 0 && (
          <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 animate-pulse">
            <div className="mt-0.5 text-amber-600">
              <Clock className="h-5 w-5" />
            </div>
            <div className="flex-1">
              <h3 className="mb-1 text-sm font-semibold text-amber-900">
                REMINDER: {unfinishedOrdersCount} orders pending completion!
              </h3>
              <p className="text-sm text-amber-800">
                Segera proses pesanan ini ya kasir! Ubah status menjadi "Selesaikan" atau "Batalkan" sesuai keadaan.
              </p>
            </div>
            <button
              onClick={() => setStatusFilter('all')}
              className="rounded bg-amber-100 px-3 py-1 text-sm font-medium text-amber-900 transition-colors hover:bg-amber-200"
            >
              Lihat Semua
            </button>
          </div>
        )}

        <div className="flex flex-col gap-4 sm:flex-row">
          <div className="relative flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 transform text-gray-400" />
            <input
              type="text"
              placeholder="Cari berdasarkan nomor atau nama..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full rounded-lg border border-gray-200 bg-white py-2 pl-10 pr-4 text-gray-900 placeholder-gray-400 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
            />
          </div>
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-lg border border-gray-200 bg-white px-4 py-2 text-gray-900 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
          >
            <option value="all">Filter by status pesanan</option>
            <option value="new">Baru Masuk</option>
            <option value="accepted">Udah Diterima</option>
            <option value="preparing">Lagi Diproses</option>
            <option value="prepared">Siap Disajikan</option>
            <option value="completed">Udah Selesai</option>
            <option value="cancelled">Dibatalkan</option>
          </select>
        </div>

        {error && (
          <div className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
            <p className="text-red-800">{error}</p>
            <button
              onClick={() => void loadOrders()}
              className="mt-2 rounded bg-red-600 px-4 py-2 text-white hover:bg-red-700"
            >
              Coba Lagi
            </button>
          </div>
        )}

        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
          {loading && (
            <div className="p-8 text-center">
              <div className="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-b-2 border-amber-400"></div>
              <p className="text-gray-600">Memuat pesanan...</p>
            </div>
          )}
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50">
                <tr>
                  <th className="hidden px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 md:px-4 md:py-3">
                    Order ID
                  </th>
                  <th className="px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 md:px-4 md:py-3">
                    Pelanggan
                  </th>
                  <th className="hidden px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 sm:table-cell md:px-4 md:py-3">
                    Meja
                  </th>
                  <th className="hidden px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 md:table-cell md:px-4 md:py-3">
                    Items
                  </th>
                  <th className="px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 md:px-4 md:py-3">
                    Total
                  </th>
                  <th className="px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 md:px-4 md:py-3">
                    Status
                  </th>
                  <th className="hidden px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 lg:table-cell md:px-4 md:py-3">
                    Waktu
                  </th>
                  <th className="px-2 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500 md:px-4 md:py-3">
                    Aksi
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {filteredOrders.map((order) => (
                  <tr key={order.id} className={cn('hover:bg-gray-50', getRowBackgroundColor(order.status))}>
                    <td className="hidden whitespace-nowrap px-2 py-3 text-sm font-medium text-gray-900 md:px-4 md:py-4">
                      <div className="font-mono text-sm">#{order.order_number}</div>
                      <div className="text-xs text-gray-500 sm:hidden">
                        {order.table_label || order.service_type}
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-2 py-3 text-xs text-gray-900 md:px-4 md:py-4 md:text-sm">
                      {order.customer_name || 'Walk-in'}
                    </td>
                    <td className="hidden whitespace-nowrap px-2 py-3 text-sm text-gray-700 sm:table-cell md:px-4 md:py-4">
                      {order.table_label || order.service_type}
                    </td>
                    <td className="hidden whitespace-nowrap px-2 py-3 text-sm text-gray-700 md:table-cell md:px-4 md:py-4">
                      {order.items_count} menu
                    </td>
                    <td className="whitespace-nowrap px-2 py-3 text-sm font-semibold text-gray-900 md:px-4 md:py-4">
                      Rp {order.total_amount.toLocaleString('id-ID')}
                    </td>
                    <td className="whitespace-nowrap px-2 py-3 md:px-4 md:py-4">
                      <div className="flex flex-col gap-1">
                        <span className={cn('inline-flex items-center rounded-full border px-2 py-1 text-xs font-medium', getStatusColor(order.status))}>
                          {getStatusIcon(order.status)}
                          <span className="ml-1">{getStatusText(order.status)}</span>
                        </span>
                        <PaymentBadge method={order.payment_method} status={order.payment_status} />
                      </div>
                    </td>
                    <td className="hidden whitespace-nowrap px-2 py-3 text-sm text-gray-500 lg:table-cell md:px-4 md:py-4">
                      {new Date(order.created_at).toLocaleString('id-ID', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                      })}
                    </td>
                    <td className="whitespace-nowrap px-2 py-3 text-sm text-gray-500 md:px-4 md:py-4">
                      <div className="flex items-center gap-1">
                        {order.payment_status !== 'paid' && (
                          <button
                            onClick={() => {
                              setCurrentPaymentOrder(order);
                              setShowPaymentModal(true);
                            }}
                            className="flex items-center gap-1 px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm rounded-lg font-semibold transition"
                          >
                            💳 Bayar
                          </button>
                        )}
                        <button
                          onClick={() => {
                            setReceiptOrderId(order.id);
                            setShowReceiptModal(true);
                          }}
                          className="flex h-7 w-12 items-center justify-center rounded-lg bg-gray-100 text-xs text-gray-700 transition hover:bg-gray-200"
                          title="Cetak Kwitansi"
                        >
                          Print
                        </button>
                        <select
                          value={order.status}
                          onChange={(e) => void handleStatusUpdate(order.id, e.target.value)}
                          className="rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-900 focus:border-amber-300 focus:ring-2 focus:ring-amber-300"
                        >
                          <option value="new">New Order</option>
                          <option value="accepted">Accept Order</option>
                          <option value="preparing">Start Processing</option>
                          <option value="prepared">Mark Ready</option>
                          <option value="completed">Complete</option>
                          <option value="cancelled">Cancel</option>
                        </select>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {!loading && filteredOrders.length === 0 && (
          <div className="py-12 text-center">
            <p className="text-gray-500">List order sedang bersih. Data lama tetap aman di laporan.</p>
          </div>
        )}

        <NewOrderModal
          isOpen={showNewOrderModal}
          onClose={() => setShowNewOrderModal(false)}
          onOrderCreated={() => void loadOrders()}
        />

        <ReceiptModal
          isOpen={showReceiptModal}
          onClose={() => setShowReceiptModal(false)}
          orderId={receiptOrderId}
        />

        <PaymentModal
          isOpen={showPaymentModal}
          onClose={() => setShowPaymentModal(false)}
          onSuccess={handlePaymentSuccess}
          order={currentPaymentOrder}
        />
      </div>
    </div>
  );
}
