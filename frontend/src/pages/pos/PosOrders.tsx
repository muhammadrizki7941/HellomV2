import { useCallback, useEffect, useMemo, useState } from 'react';
import { Search, Clock, CheckCircle, XCircle, CirclePlus, RotateCcw, Info, Wifi, WifiOff, Receipt, LayoutGrid, List, PanelRight, UserRound, MapPin, CalendarDays, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  POS_ORDER_STATUS_LABELS,
  canPos,
  cancelPosOrder,
  getPosOrders,
  getPosTableBills,
  payPosTableBill,
  refundPosOrder,
  updatePosOrderStatus,
} from '@/lib/hellomApi';
import type { PosOrderListItem, PosOrderStatus, PosTableBill } from '@/lib/hellomApi';
import NewOrderModal from '@/components/pos/NewOrderModal';
import HellomLoading from '@/components/HellomLoading';
import ReceiptModal from '@/components/pos/ReceiptModal';
import PaymentModal from '@/components/pos/PaymentModal';
import CashDrawerButton from '@/components/pos/CashDrawerButton';
import { OwnerApprovalDialog } from '@/components/pos/OwnerPin';
import { MoveBillDialog, OrderManageDialog, PaymentMethodDialog } from '@/components/pos/OrderAdjustDialog';
import { getPosRealtimeEventName, isPosRealtimeConnected } from '@/lib/realtime';
import {
  ensurePosOrderListResetAt,
  getPosOrderListResetEventName,
  isOrderVisibleAfterReset,
  resetPosOrderListNow,
} from '@/lib/posOrderListReset';

const ACTIVE_ORDER_STATUSES = ['new', 'accepted', 'preparing', 'prepared'];
// Poll fast only when the live socket is down; the socket pushes changes otherwise.
const POLL_LIVE_MS = 60000;
const POLL_FALLBACK_MS = 15000;

const statusLabel = (order: PosOrderListItem) =>
  order.status_label || POS_ORDER_STATUS_LABELS[order.status as PosOrderStatus] || order.status;

// Primary button text for the next forward step.
const NEXT_ACTION: Partial<Record<PosOrderStatus, string>> = {
  accepted: 'Konfirmasi',
  preparing: 'Proses',
  prepared: 'Siap',
  completed: 'Selesai',
};

const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;

export default function PosOrders() {
  const [viewMode, setViewMode] = useState<'grid' | 'list' | 'split'>('split');
  const [orders, setOrders] = useState<PosOrderListItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedOrderId, setSelectedOrderId] = useState<number | null>(null);
  const [autoRefresh, setAutoRefresh] = useState(true);
  const [live, setLive] = useState(isPosRealtimeConnected());
  const [showNewOrderModal, setShowNewOrderModal] = useState(false);
  const [receiptOrderId, setReceiptOrderId] = useState<number | null>(null);
  const [showReceiptModal, setShowReceiptModal] = useState(false);
  const [showPaymentModal, setShowPaymentModal] = useState(false);
  const [currentPaymentOrder, setCurrentPaymentOrder] = useState<PosOrderListItem | null>(null);
  const [listResetAt, setListResetAt] = useState(() => ensurePosOrderListResetAt());
  const [showInfoBoxes, setShowInfoBoxes] = useState(true);
  const [busyOrderId, setBusyOrderId] = useState<number | null>(null);
  const [bills, setBills] = useState<PosTableBill[]>([]);
  const [showBills, setShowBills] = useState(false);
  const [payingBill, setPayingBill] = useState<PosTableBill | null>(null);
  // Void / refund wait for the owner's PIN in a dialog (no browser prompt).
  const [approval, setApproval] = useState<{ kind: 'cancel' | 'refund'; order: PosOrderListItem } | null>(null);
  // Changes after the order was placed (items, discount, split, table) and paid-order method fix.
  const [managing, setManaging] = useState<PosOrderListItem | null>(null);
  const [methodOrder, setMethodOrder] = useState<PosOrderListItem | null>(null);
  const [movingBill, setMovingBill] = useState<PosTableBill | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  useEffect(() => {
    if (!toast) return undefined;
    const timer = window.setTimeout(() => setToast(null), 4000);
    return () => window.clearTimeout(timer);
  }, [toast]);

  useEffect(() => {
    const timer = setTimeout(() => setShowInfoBoxes(false), 5000);
    return () => clearTimeout(timer);
  }, []);

  const loadOrders = useCallback(async (quiet = false) => {
    try {
      if (!quiet) setLoading(true);
      setError(null);
      const [result, billResult] = await Promise.all([
        getPosOrders(statusFilter),
        getPosTableBills().catch(() => ({ bills: [] as PosTableBill[] })),
      ]);
      setOrders(result.orders || []);
      setBills(billResult.bills || []);
    } catch {
      setError('Gagal memuat pesanan');
    } finally {
      setLoading(false);
    }
  }, [statusFilter]);

  useEffect(() => {
    void loadOrders();
  }, [loadOrders]);

  // Socket events (from PosLayout) and other screens announce changes with this event.
  useEffect(() => {
    const onUpdated = () => void loadOrders(true);
    const onLive = (event: Event) => setLive(Boolean((event as CustomEvent<{ connected: boolean }>).detail?.connected));
    window.addEventListener('pos-orders-updated', onUpdated);
    window.addEventListener(getPosRealtimeEventName(), onLive);
    return () => {
      window.removeEventListener('pos-orders-updated', onUpdated);
      window.removeEventListener(getPosRealtimeEventName(), onLive);
    };
  }, [loadOrders]);

  useEffect(() => {
    const syncResetAt = () => setListResetAt(ensurePosOrderListResetAt());
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
    const interval = window.setInterval(() => void loadOrders(true), live ? POLL_LIVE_MS : POLL_FALLBACK_MS);
    return () => window.clearInterval(interval);
  }, [autoRefresh, live, loadOrders]);

  const announce = () => window.dispatchEvent(new CustomEvent('pos-orders-updated'));

  const runAction = async (orderId: number, action: () => Promise<unknown>) => {
    setBusyOrderId(orderId);
    try {
      await action();
      announce();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Aksi gagal');
    } finally {
      setBusyOrderId(null);
    }
  };

  const handleStatusUpdate = (order: PosOrderListItem, next: string) => {
    if (next === 'cancelled') {
      setApproval({ kind: 'cancel', order });
      return;
    }
    void runAction(order.id, () => updatePosOrderStatus(order.id, next));
  };

  const handleRefund = (order: PosOrderListItem) => setApproval({ kind: 'refund', order });

  const submitApproval = async (reason: string, pin: string) => {
    if (!approval) return;
    const { kind, order } = approval;
    setBusyOrderId(order.id);
    try {
      await (kind === 'cancel' ? cancelPosOrder(order.id, reason, pin) : refundPosOrder(order.id, reason, pin));
      setApproval(null);
      announce();
    } finally {
      setBusyOrderId(null);
    }
  };

  const handleResetOrderList = () => {
    setListResetAt(resetPosOrderListNow());
    announce();
  };

  const handlePaymentSuccess = (paymentData: { change_amount?: number; order?: { id?: number } }) => {
    const paidOrderId = currentPaymentOrder?.id ?? paymentData?.order?.id ?? null;
    setShowPaymentModal(false);
    setCurrentPaymentOrder(null);
    if ((paymentData.change_amount ?? 0) > 0) {
      alert(`✅ Pembayaran berhasil!\n\nKembalian: ${rupiah(paymentData.change_amount ?? 0)}`);
    }
    announce();
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

  const unfinishedOrdersCount = visibleOrders.filter((order) => ACTIVE_ORDER_STATUSES.includes(order.status)).length;
  const selectedOrder = filteredOrders.find((order) => order.id === selectedOrderId) ?? filteredOrders[0] ?? null;

  useEffect(() => {
    if (selectedOrder?.id !== selectedOrderId) {
      setSelectedOrderId(selectedOrder?.id ?? null);
    }
  }, [selectedOrder, selectedOrderId]);

  const resetTimeLabel = listResetAt.toLocaleString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'completed': return 'bg-green-100 text-green-900 border-green-200';
      case 'prepared':
      case 'preparing':
      case 'accepted': return 'bg-orange-100 text-orange-800 border-orange-200';
      case 'new': return 'bg-sky-50 text-sky-900 border-sky-200';
      case 'cancelled': return 'bg-red-100 text-red-800 border-red-200';
      default: return 'bg-gray-100 text-gray-800 border-gray-200';
    }
  };

  const getRowBackgroundColor = (status: string) => {
    switch (status) {
      case 'completed': return 'bg-green-50/50';
      case 'prepared':
      case 'preparing':
      case 'accepted': return 'bg-orange-50/50';
      case 'cancelled': return 'bg-red-50/50';
      default: return 'bg-white';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'completed': return <CheckCircle className="h-4 w-4 text-green-600" />;
      case 'prepared': return <CheckCircle className="h-4 w-4 text-orange-600" />;
      case 'cancelled': return <XCircle className="h-4 w-4 text-red-600" />;
      default: return <Clock className="h-4 w-4 text-gray-600" />;
    }
  };

  const PaymentBadge = ({ method, status }: { method?: string | null; status: string }) => {
    if (status === 'refunded') {
      return <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700">↩︎ Direfund</span>;
    }
    if (status !== 'paid') {
      return <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-600">Belum Bayar</span>;
    }
    const map: Record<string, { icon: string; label: string }> = {
      cash: { icon: '💵', label: 'Tunai' },
      transfer: { icon: '🏦', label: 'Transfer' },
      qris: { icon: '📱', label: 'QRIS' },
      gopay: { icon: '📱', label: 'GoPay' },
      dana: { icon: '📱', label: 'DANA' },
      other: { icon: '💳', label: 'Lainnya' },
    };
    const m = map[method || ''] || map.other;
    return <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">{m.icon} {m.label}</span>;
  };

  const openBills = bills.filter((bill) => bill.status === 'open' && bill.unpaid_amount > 0);

  const renderOrderActions = (order: PosOrderListItem) => {
    const allowed = order.allowed_next ?? [];
    const forward = allowed.filter((status) => status !== 'cancelled');
    const nextStep = forward[0] as PosOrderStatus | undefined;
    const isPaid = order.payment_status === 'paid';
    const busy = busyOrderId === order.id;

    return (
      <div className="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
        {!isPaid && order.payment_status !== 'refunded' && order.status !== 'cancelled' && (
          <button
            type="button"
            onClick={() => {
              setCurrentPaymentOrder(order);
              setShowPaymentModal(true);
            }}
            className="flex min-h-9 items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-emerald-700"
          >
            Bayar
          </button>
        )}
        {nextStep && (
          <button
            type="button"
            disabled={busy}
            onClick={() => handleStatusUpdate(order, nextStep)}
            className="min-h-9 rounded-lg bg-amber-400 px-3 py-2 text-xs font-bold text-[#111111] transition hover:bg-amber-500 disabled:opacity-50"
          >
            {busy ? 'Memproses...' : NEXT_ACTION[nextStep] ?? POS_ORDER_STATUS_LABELS[nextStep]}
          </button>
        )}
        {!isPaid && order.payment_status !== 'refunded' && order.status !== 'cancelled' && (
          <button
            type="button"
            disabled={busy}
            onClick={() => setManaging(order)}
            data-manage-order={order.id}
            className="min-h-9 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            Atur
          </button>
        )}
        {isPaid && (
          <button
            type="button"
            disabled={busy}
            onClick={() => setMethodOrder(order)}
            data-change-method={order.id}
            className="min-h-9 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
          >
            Ganti metode bayar
          </button>
        )}
        <button
          type="button"
          onClick={() => {
            setReceiptOrderId(order.id);
            setShowReceiptModal(true);
          }}
          title="Cetak kwitansi"
          aria-label={`Cetak kwitansi ${order.order_number}`}
          className="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100 text-sm transition hover:bg-gray-200"
        >
          🖨️
        </button>
        {forward.length > 1 && (
          <select
            aria-label={`Ubah status ${order.order_number}`}
            value=""
            disabled={busy}
            onChange={(event) => event.target.value && handleStatusUpdate(order, event.target.value)}
            className="min-h-9 rounded-lg border border-gray-200 bg-white py-1 pl-2 pr-6 text-xs text-gray-900"
          >
            <option value="">Lompat ke...</option>
            {forward.slice(1).map((status) => (
              <option key={status} value={status}>{POS_ORDER_STATUS_LABELS[status as PosOrderStatus] ?? status}</option>
            ))}
          </select>
        )}
        {allowed.includes('cancelled') && !isPaid && canPos('order_cancel') && (
          <button
            type="button"
            disabled={busy}
            onClick={() => handleStatusUpdate(order, 'cancelled')}
            data-cancel-order={order.id}
            className="min-h-9 rounded-lg border border-red-200 px-2.5 py-2 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"
          >
            Batalkan
          </button>
        )}
        {isPaid && canPos('order_refund') && (
          <button
            type="button"
            disabled={busy}
            onClick={() => handleRefund(order)}
            data-refund-order={order.id}
            className="min-h-9 rounded-lg border border-gray-200 px-2.5 py-2 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
          >
            Refund
          </button>
        )}
      </div>
    );
  };

  const renderOrderSummary = (order: PosOrderListItem, compact = false) => (
    <>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-mono text-xs font-semibold text-gray-500">#{order.order_number}</span>
            {order.order_source === 'public_customer' && (
              <span className="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold text-violet-700">Self-order</span>
            )}
          </div>
          <p className="mt-1 truncate text-sm font-bold text-gray-900">{order.customer_name || 'Walk-in'}</p>
        </div>
        <span className={cn('shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-semibold', getStatusColor(order.status))}>
          {statusLabel(order)}
        </span>
      </div>
      <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
        <span className="inline-flex items-center gap-1"><MapPin className="h-3.5 w-3.5" />{order.table_label || order.service_type || 'Takeaway'}</span>
        <span className="inline-flex items-center gap-1"><CalendarDays className="h-3.5 w-3.5" />{new Date(order.created_at).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
        <span>{order.items_count} item</span>
      </div>
      {!compact && (
        <div className="mt-3 space-y-2 border-t border-gray-100 pt-3">
          {order.items.slice(0, 3).map((item) => (
            <div key={item.id} className="flex items-center justify-between gap-3 text-xs">
              <span className="min-w-0 truncate text-gray-700">{item.quantity}× {item.product_name}</span>
              <span className="shrink-0 font-medium text-gray-600">{rupiah(item.line_total)}</span>
            </div>
          ))}
          {order.items.length > 3 && (
            <p className="text-[11px] text-gray-400">+{order.items.length - 3} item lainnya</p>
          )}
        </div>
      )}
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
        <PaymentBadge method={order.payment_method} status={order.payment_status} />
        <span className="text-base font-bold text-gray-900">{rupiah(order.final_amount ?? order.total_amount)}</span>
      </div>
      {order.cancel_reason && <p className="mt-2 text-xs text-red-600">Alasan batal: {order.cancel_reason}</p>}
    </>
  );

  return (
    <div className="min-h-screen bg-gray-50 p-2 sm:p-4 lg:p-6">
      <div className="w-full space-y-4 sm:space-y-6">
        <div className="flex flex-col justify-between gap-4 xl:flex-row xl:items-center">
          <div className="min-w-0">
            <h1 className="text-2xl font-bold text-gray-900">Daftar Pesanan</h1>
            <p className="mt-1 flex items-center gap-1.5 text-gray-600">
              {live ? <Wifi className="h-4 w-4 text-green-600" /> : <WifiOff className="h-4 w-4 text-gray-400" />}
              {live ? 'Live — pesanan baru masuk otomatis' : `Menyegarkan tiap ${POLL_FALLBACK_MS / 1000} detik`}
            </p>
          </div>
          <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:flex-wrap xl:w-auto xl:justify-end">
            <button
              type="button"
              onClick={() => setShowNewOrderModal(true)}
              className="order-first col-span-2 flex min-h-12 items-center justify-center gap-2 rounded-xl bg-amber-400 px-5 py-3 text-base font-bold text-[#111111] shadow-sm transition hover:bg-amber-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 sm:order-last sm:col-span-1 sm:min-h-11 sm:px-5"
            >
              <CirclePlus className="h-5 w-5" />
              Tambah Pesanan
            </button>
            <CashDrawerButton />
            <button
              onClick={() => void loadOrders()}
              className="min-h-11 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
            >
              Refresh
            </button>
            <button
              onClick={() => setAutoRefresh(!autoRefresh)}
              className={cn(
                'min-h-11 rounded-lg border px-3 py-2 text-sm font-medium transition-colors',
                autoRefresh ? 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' : 'border-gray-200 bg-gray-100 text-gray-700 hover:bg-gray-200'
              )}
            >
              Auto: {autoRefresh ? 'ON' : 'OFF'}
            </button>
            <button
              onClick={() => setShowBills(!showBills)}
              className={cn(
                'flex min-h-11 items-center justify-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition-colors',
                showBills ? 'border-gray-300 bg-gray-100 text-gray-900' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
              )}
            >
              <Receipt className="h-4 w-4" />
              Tagihan Meja{openBills.length > 0 ? ` (${openBills.length})` : ''}
            </button>
            <button
              onClick={handleResetOrderList}
              className="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
              title="Bersihkan list order di tampilan frontend"
            >
              <RotateCcw className="h-4 w-4" />
              Reset List
            </button>
          </div>
        </div>

        {showInfoBoxes && (
          <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            <div className="flex items-start gap-2">
              <Info className="mt-0.5 h-4 w-4 flex-none" />
              <p>
                Reset list hanya membersihkan tampilan order di frontend. Data order tetap tersimpan dan tetap bisa dipakai untuk laporan. Reset otomatis berjalan setiap hari pukul 06.00, dan reset terakhir tercatat pada {resetTimeLabel}.
              </p>
            </div>
          </div>
        )}

        {showInfoBoxes && unfinishedOrdersCount > 0 && (
          <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <Clock className="mt-0.5 h-5 w-5 text-amber-600" />
            <div className="flex-1">
              <h3 className="mb-1 text-sm font-semibold text-amber-900">{unfinishedOrdersCount} pesanan belum selesai</h3>
              <p className="text-sm text-amber-800">Lanjutkan status pesanan sampai Selesai, atau batalkan dengan alasan.</p>
            </div>
          </div>
        )}

        {showBills && (
          <div className="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div className="border-b border-gray-100 bg-gray-50 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400">
              Tagihan meja terbuka
            </div>
            {openBills.length === 0 ? (
              <p className="px-4 py-6 text-center text-sm text-gray-500">Tidak ada tagihan meja yang belum dibayar.</p>
            ) : (
              <div className="divide-y divide-gray-100">
                {openBills.map((bill) => (
                  <div key={bill.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div>
                      <p className="text-sm font-semibold text-gray-900">{bill.table_label || `Meja #${bill.dining_table_id}`}</p>
                      <p className="text-xs text-gray-500">
                        {bill.orders_count} pesanan · {bill.orders.map((o) => `#${o.order_number}`).join(', ')}
                      </p>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className="text-sm font-semibold text-gray-900">{rupiah(bill.unpaid_amount)}</span>
                      <button
                        onClick={() => setMovingBill(bill)}
                        data-move-bill={bill.id}
                        className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                      >
                        Pindah / gabung
                      </button>
                      <button
                        onClick={() => setPayingBill(bill)}
                        className="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700"
                      >
                        Bayar semua
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        <div className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm sm:flex-row sm:items-center">
          <div className="relative min-w-0 flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 transform text-gray-400" />
            <input
              type="text"
              placeholder="Cari berdasarkan nomor atau nama..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="min-h-11 w-full rounded-xl border border-gray-200 bg-gray-50 py-2 pl-10 pr-4 text-gray-900 placeholder-gray-400 focus:border-amber-300 focus:bg-white focus:ring-2 focus:ring-amber-300"
            />
          </div>
          <select
            value={statusFilter}
            onChange={(event) => setStatusFilter(event.target.value)}
            aria-label="Filter status pesanan"
            className="min-h-11 rounded-xl border border-gray-200 bg-white px-3 text-sm text-gray-700 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-200"
          >
            <option value="all">Semua status</option>
            {(Object.keys(POS_ORDER_STATUS_LABELS) as PosOrderStatus[]).map((status) => (
              <option key={status} value={status}>{POS_ORDER_STATUS_LABELS[status]}</option>
            ))}
          </select>
          <div className="flex min-h-11 items-center gap-1 overflow-x-auto rounded-xl bg-gray-100 p-1" role="group" aria-label="Tampilan pesanan">
            {([
              ['grid', 'Kartu', LayoutGrid],
              ['list', 'Daftar', List],
              ['split', 'Detail', PanelRight],
            ] as const).map(([mode, label, Icon]) => (
              <button
                key={mode}
                type="button"
                aria-pressed={viewMode === mode}
                onClick={() => setViewMode(mode)}
                className={cn(
                  'flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg px-3 text-xs font-semibold transition',
                  viewMode === mode ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-900'
                )}
              >
                <Icon className="h-4 w-4" />
                <span>{label}</span>
              </button>
            ))}
          </div>
        </div>

        {error && (
          <div className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
            <p className="text-red-800">{error}</p>
            <button onClick={() => void loadOrders()} className="mt-2 rounded bg-red-600 px-4 py-2 text-white hover:bg-red-700">
              Coba Lagi
            </button>
          </div>
        )}

        {viewMode === 'grid' ? (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
            {loading && (
              <div className="col-span-full rounded-2xl border border-gray-200 bg-white">
                <HellomLoading mode="inline" label="Memuat pesanan..." />
              </div>
            )}
            {filteredOrders.map((order) => (
              <article
                key={order.id}
                className="flex min-w-0 flex-col rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-amber-300 hover:shadow-md"
              >
                <button
                  type="button"
                  onClick={() => {
                    setSelectedOrderId(order.id);
                    setViewMode('split');
                  }}
                  className="min-w-0 rounded-xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                  aria-label={`Lihat detail pesanan ${order.order_number}`}
                >
                  {renderOrderSummary(order)}
                </button>
                <div className="mt-4">{renderOrderActions(order)}</div>
              </article>
            ))}
          </div>
        ) : (
          <div className={cn(
            'grid min-w-0 gap-4',
            viewMode === 'list' ? 'lg:grid-cols-[220px_minmax(0,1fr)]' : 'xl:grid-cols-[minmax(0,1fr)_340px]'
          )}>
            {viewMode === 'list' && (
              <aside className="h-fit rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <h2 className="mb-3 text-sm font-bold text-gray-900">Filter Pesanan</h2>
                <nav className="flex gap-2 overflow-x-auto lg:flex-col lg:overflow-visible" aria-label="Filter status pesanan">
                  <button
                    type="button"
                    onClick={() => setStatusFilter('all')}
                    aria-pressed={statusFilter === 'all'}
                    className={cn(
                      'flex min-h-10 shrink-0 items-center justify-between gap-3 rounded-xl px-3 text-left text-sm font-medium',
                      statusFilter === 'all' ? 'bg-amber-100 text-amber-950' : 'text-gray-600 hover:bg-gray-50'
                    )}
                  >
                    <span>Semua Pesanan</span>
                  </button>
                  {(Object.keys(POS_ORDER_STATUS_LABELS) as PosOrderStatus[]).map((status) => (
                    <button
                      key={status}
                      type="button"
                      onClick={() => setStatusFilter(status)}
                      aria-pressed={statusFilter === status}
                      className={cn(
                        'flex min-h-10 shrink-0 items-center justify-between gap-3 rounded-xl px-3 text-left text-sm font-medium',
                        statusFilter === status ? 'bg-amber-100 text-amber-950' : 'text-gray-600 hover:bg-gray-50'
                      )}
                    >
                      <span>{POS_ORDER_STATUS_LABELS[status]}</span>
                    </button>
                  ))}
                </nav>
              </aside>
            )}
            <div className="min-w-0 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
          <div className="border-b border-gray-100 bg-gray-50 px-4 py-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
              {viewMode === 'split' ? 'Pesanan · pilih untuk melihat detail' : 'Daftar Pesanan'}
            </span>
          </div>

          {loading && (
            <HellomLoading mode="inline" label="Memuat pesanan..." />
          )}

          <div className="divide-y divide-gray-100">
            {filteredOrders.map((order) => {
              return (
                <div
                  key={order.id}
                  className={cn(
                    'px-4 py-3 transition-colors hover:bg-gray-50/60',
                    getRowBackgroundColor(order.status),
                    viewMode === 'split' && selectedOrder?.id === order.id && 'bg-amber-50/70 ring-1 ring-inset ring-amber-300'
                  )}
                >
                  {viewMode === 'split' && (
                    <button
                      type="button"
                      onClick={() => setSelectedOrderId(order.id)}
                      aria-pressed={selectedOrder?.id === order.id}
                      className="mb-2 flex min-h-8 w-full items-center justify-between gap-2 text-left"
                    >
                      <span className="text-[11px] font-semibold text-amber-800">
                        {selectedOrder?.id === order.id ? 'Detail pesanan dipilih' : 'Pilih untuk lihat detail'}
                      </span>
                      <ChevronRight className="h-4 w-4 text-gray-400" />
                    </button>
                  )}
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-baseline gap-1.5">
                        <span className="shrink-0 font-mono text-[10px] text-gray-400">#{order.order_number}</span>
                        <span className="break-words text-sm font-semibold text-gray-900">{order.customer_name || 'Walk-in'}</span>
                        {order.order_source === 'public_customer' && (
                          <span className="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700">Self-order</span>
                        )}
                      </div>
                      <p className="mt-0.5 truncate text-[11px] text-gray-500">
                        {order.table_label || order.service_type}
                        {' · '}
                        {order.items_count} menu
                        {' · '}
                        {new Date(order.created_at).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                      </p>
                      {order.cancel_reason && <p className="mt-0.5 text-[11px] text-red-600">Alasan batal: {order.cancel_reason}</p>}
                    </div>
                    <div className="shrink-0 text-right">
                      <span className="text-xs font-semibold text-gray-700">{rupiah(order.final_amount ?? order.total_amount)}</span>
                      {((order.tax_amount ?? 0) > 0 || (order.service_amount ?? 0) > 0) && (
                        <p className="text-[10px] text-gray-400">
                          termasuk{(order.service_amount ?? 0) > 0 ? ` service ${rupiah(order.service_amount ?? 0)}` : ''}
                          {(order.tax_amount ?? 0) > 0 ? ` pajak ${rupiah(order.tax_amount ?? 0)}` : ''}
                        </p>
                      )}
                    </div>
                  </div>

                  <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <span className={cn('inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium', getStatusColor(order.status))}>
                      {getStatusIcon(order.status)}
                      {statusLabel(order)}
                    </span>
                    <PaymentBadge method={order.payment_method} status={order.payment_status} />
                    {(order.manual_discount_amount ?? 0) > 0 && (
                      <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800">Diskon kasir {rupiah(order.manual_discount_amount ?? 0)}</span>
                    )}
                    {(order.receipt_prints ?? 0) > 0 && (
                      <span className="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[11px] text-gray-500">Struk dicetak {order.receipt_prints}×</span>
                    )}
                    {order.split_from_order_id && <span className="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[11px] text-gray-500">Pisahan bill</span>}
                  </div>

                  <div className="mt-3">{renderOrderActions(order)}</div>
                </div>
              );
            })}
          </div>
        </div>
            {viewMode === 'split' && selectedOrder && (
              <aside className="h-fit min-w-0 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm xl:sticky xl:top-6">
                <div className="flex items-start justify-between gap-3 border-b border-gray-100 p-4">
                  <div className="min-w-0">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Detail Pesanan</p>
                    <h2 className="mt-1 break-all font-mono text-sm font-bold text-gray-900">#{selectedOrder.order_number}</h2>
                  </div>
                  <span className={cn('shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-semibold', getStatusColor(selectedOrder.status))}>
                    {statusLabel(selectedOrder)}
                  </span>
                </div>
                <div className="space-y-4 p-4">
                  <div className="grid grid-cols-2 gap-3 text-sm">
                    <div className="min-w-0">
                      <p className="text-xs text-gray-400">Pelanggan</p>
                      <p className="mt-1 flex items-center gap-1.5 truncate font-semibold text-gray-800"><UserRound className="h-4 w-4 shrink-0 text-gray-400" />{selectedOrder.customer_name || 'Walk-in'}</p>
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs text-gray-400">Meja / layanan</p>
                      <p className="mt-1 flex items-center gap-1.5 truncate font-semibold text-gray-800"><MapPin className="h-4 w-4 shrink-0 text-gray-400" />{selectedOrder.table_label || selectedOrder.service_type || 'Takeaway'}</p>
                    </div>
                  </div>
                  <div className="flex items-center justify-between border-b border-gray-100 pb-2">
                    <h3 className="text-sm font-bold text-gray-900">Item Pesanan</h3>
                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">{selectedOrder.items_count}</span>
                  </div>
                  <div className="max-h-64 space-y-3 overflow-y-auto">
                    {selectedOrder.items.map((item) => (
                      <div key={item.id} className="flex items-start justify-between gap-3 text-sm">
                        <div className="min-w-0">
                          <p className="break-words font-medium text-gray-800">{item.product_name}</p>
                          <p className="text-xs text-gray-400">{item.quantity} × {rupiah(item.unit_price)}</p>
                          {item.options?.map((option) => <p key={option} className="text-xs text-gray-400">{option}</p>)}
                        </div>
                        <span className="shrink-0 font-semibold text-gray-700">{rupiah(item.line_total)}</span>
                      </div>
                    ))}
                    {selectedOrder.items.length === 0 && <p className="text-sm text-gray-500">Rincian item tidak tersedia.</p>}
                  </div>
                  <div className="space-y-2 border-t border-gray-100 pt-3 text-sm">
                    <div className="flex justify-between text-gray-500"><span>Subtotal</span><span>{rupiah(selectedOrder.subtotal_amount ?? selectedOrder.total_amount)}</span></div>
                    {(selectedOrder.discount_amount ?? 0) > 0 && <div className="flex justify-between text-gray-500"><span>Diskon</span><span>-{rupiah(selectedOrder.discount_amount)}</span></div>}
                    {(selectedOrder.service_amount ?? 0) > 0 && <div className="flex justify-between text-gray-500"><span>Service</span><span>{rupiah(selectedOrder.service_amount ?? 0)}</span></div>}
                    {(selectedOrder.tax_amount ?? 0) > 0 && <div className="flex justify-between text-gray-500"><span>Pajak</span><span>{rupiah(selectedOrder.tax_amount ?? 0)}</span></div>}
                    <div className="flex justify-between border-t border-gray-100 pt-2 text-base font-bold text-gray-900"><span>Total</span><span>{rupiah(selectedOrder.final_amount ?? selectedOrder.total_amount)}</span></div>
                  </div>
                  <PaymentBadge method={selectedOrder.payment_method} status={selectedOrder.payment_status} />
                  {renderOrderActions(selectedOrder)}
                </div>
              </aside>
            )}
          </div>
        )}

        {!loading && filteredOrders.length === 0 && (
          <div className="py-12 text-center">
            <p className="text-gray-500">List order sedang bersih. Data lama tetap aman di laporan.</p>
          </div>
        )}

        <NewOrderModal isOpen={showNewOrderModal} onClose={() => setShowNewOrderModal(false)} onOrderCreated={announce} />

        <ReceiptModal isOpen={showReceiptModal} onClose={() => setShowReceiptModal(false)} orderId={receiptOrderId} />
        {managing && <OrderManageDialog order={managing} onClose={() => setManaging(null)} onChanged={(m) => { setToast(m); announce(); }} />}
        {methodOrder && <PaymentMethodDialog order={methodOrder} onClose={() => setMethodOrder(null)} onChanged={(m) => { setToast(m); announce(); }} />}
        {movingBill && <MoveBillDialog bill={movingBill} onClose={() => setMovingBill(null)} onChanged={(m) => { setToast(m); announce(); }} />}
        {toast && (
          <div role="status" data-toast className="fixed inset-x-0 bottom-20 z-[70] mx-auto w-fit max-w-[90vw] rounded-2xl bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-lg sm:bottom-6">
            {toast}
          </div>
        )}
        {approval && (
          <OwnerApprovalDialog
            title={approval.kind === 'cancel' ? 'Batalkan pesanan?' : 'Refund pesanan?'}
            summary={(
              <>
                <p className="font-semibold text-gray-900">#{approval.order.order_number} · {rupiah(approval.order.final_amount)}</p>
                <p className="mt-1">{approval.kind === 'cancel'
                  ? 'Pesanan dibatalkan dan tidak bisa dikembalikan. Stok dan poin member dikembalikan.'
                  : 'Seluruh pembayaran dikembalikan ke pelanggan. Poin member dari pesanan ini ditarik.'}</p>
              </>
            )}
            reasonLabel={approval.kind === 'cancel' ? 'Alasan pembatalan' : 'Alasan refund'}
            reasonPlaceholder={approval.kind === 'cancel' ? 'Mis. pelanggan batal pesan' : 'Mis. salah input menu'}
            confirmLabel={approval.kind === 'cancel' ? 'Batalkan pesanan' : 'Refund'}
            onSubmit={submitApproval}
            onClose={() => setApproval(null)}
          />
        )}

        <PaymentModal
          isOpen={showPaymentModal}
          onClose={() => setShowPaymentModal(false)}
          onSuccess={handlePaymentSuccess}
          order={currentPaymentOrder}
        />

        {payingBill && (
          <TableBillPayDialog
            bill={payingBill}
            onClose={() => setPayingBill(null)}
            onPaid={(change) => {
              setPayingBill(null);
              if (change > 0) alert(`✅ Tagihan lunas.\n\nKembalian: ${rupiah(change)}`);
              announce();
            }}
          />
        )}
      </div>
    </div>
  );
}

function TableBillPayDialog({ bill, onClose, onPaid }: { bill: PosTableBill; onClose: () => void; onPaid: (change: number) => void }) {
  const [method, setMethod] = useState('cash');
  const [amount, setAmount] = useState(String(bill.unpaid_amount));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const amountNum = Number(amount.replace(/\D/g, '')) || 0;

  const submit = async () => {
    setSaving(true);
    setError(null);
    try {
      const result = await payPosTableBill(bill.id, { payment_method: method, payment_amount: method === 'cash' ? amountNum : bill.unpaid_amount });
      onPaid(result.change_amount);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Pembayaran gagal');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div className="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <h3 className="text-lg font-bold text-gray-900">Bayar tagihan {bill.table_label || `meja #${bill.dining_table_id}`}</h3>
        <p className="mt-1 text-sm text-gray-500">{bill.orders_count} pesanan · total {rupiah(bill.unpaid_amount)}</p>
        <div className="mt-4 grid grid-cols-3 gap-2">
          {[['cash', 'Tunai'], ['qris', 'QRIS'], ['transfer', 'Transfer']].map(([key, label]) => (
            <button
              key={key}
              onClick={() => setMethod(key)}
              className={cn('rounded-lg border px-2 py-2 text-sm', method === key ? 'border-amber-400 bg-amber-50 font-semibold' : 'border-gray-200')}
            >
              {label}
            </button>
          ))}
        </div>
        {method === 'cash' && (
          <label className="mt-4 block text-sm text-gray-700">
            Uang diterima
            <input
              inputMode="numeric"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2"
            />
            {amountNum >= bill.unpaid_amount && (
              <span className="mt-1 block text-xs text-green-700">Kembalian {rupiah(amountNum - bill.unpaid_amount)}</span>
            )}
          </label>
        )}
        {error && <p className="mt-3 text-sm text-red-600">{error}</p>}
        <div className="mt-5 flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Batal</button>
          <button
            disabled={saving || (method === 'cash' && amountNum < bill.unpaid_amount)}
            onClick={() => void submit()}
            className="rounded-lg bg-green-600 px-4 py-2 text-sm font-bold text-white hover:bg-green-700 disabled:opacity-50"
          >
            {saving ? 'Memproses…' : 'Bayar'}
          </button>
        </div>
      </div>
    </div>
  );
}
