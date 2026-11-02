import { useCallback, useEffect, useMemo, useState } from 'react';
import { Search, Clock, CheckCircle, XCircle, CirclePlus, RotateCcw, Info, Wifi, WifiOff, Receipt, LayoutGrid, List, PanelRight, UserRound, MapPin, CalendarDays, ChevronRight, MoreHorizontal, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  POS_ORDER_STATUS_LABELS,
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
import { setPosTransactionInProgress } from '@/lib/pwa';
import { getOfflineOrders, toPosOrderListItem } from '@/lib/posOfflineDb';
import { useBusinessProfile } from '@/hooks/useBusinessProfile';
import { usePermissions } from '@/hooks/usePermissions';
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
  const { can } = usePermissions();
  const { terminology, hasModule } = useBusinessProfile();
  const hasTablesSelfOrder = hasModule('tables_selforder');
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
  const [menuOpen, setMenuOpen] = useState(false);
  const [detailOpen, setDetailOpen] = useState(false);
  useEffect(() => {
    if (!toast) return undefined;
    const timer = window.setTimeout(() => setToast(null), 4000);
    return () => window.clearTimeout(timer);
  }, [toast]);

  useEffect(() => {
    const active =
      showNewOrderModal ||
      showReceiptModal ||
      showPaymentModal ||
      busyOrderId !== null ||
      payingBill !== null ||
      approval !== null ||
      managing !== null ||
      methodOrder !== null ||
      movingBill !== null;

    setPosTransactionInProgress(active);
    return () => setPosTransactionInProgress(false);
  }, [
    showNewOrderModal,
    showReceiptModal,
    showPaymentModal,
    busyOrderId,
    payingBill,
    approval,
    managing,
    methodOrder,
    movingBill,
  ]);

  useEffect(() => {
    const timer = setTimeout(() => setShowInfoBoxes(false), 5000);
    return () => clearTimeout(timer);
  }, []);

  const loadOrders = useCallback(async (quiet = false) => {
    let localOrders: PosOrderListItem[] = [];
    try {
      if (!quiet) setLoading(true);
      setError(null);
      localOrders = (await getOfflineOrders()).map(toPosOrderListItem);
    } catch (reason) {
      console.error('[POS offline] Failed to read local orders:', reason);
    }
    try {
      const [result, billResult] = await Promise.all([
        getPosOrders(statusFilter),
        hasTablesSelfOrder
          ? getPosTableBills().catch(() => ({ bills: [] as PosTableBill[] }))
          : Promise.resolve({ bills: [] as PosTableBill[] }),
      ]);
      setOrders([...(result.orders || []), ...localOrders].sort(
        (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime(),
      ));
      setBills(billResult.bills || []);
    } catch (reason) {
      setOrders(localOrders);
      setBills([]);
      setError(localOrders.length > 0
        ? null
        : reason instanceof Error ? reason.message : `Gagal memuat ${terminology.transaction.toLowerCase()}.`);
    } finally {
      setLoading(false);
    }
  }, [statusFilter, hasTablesSelfOrder, terminology.transaction]);

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

  const PaymentBadge = ({ method, status, paidManually }: { method?: string | null; status: string; paidManually?: boolean }) => {
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
    return (
      <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
        {m.icon} {m.label}{paidManually ? ' · Verifikasi manual' : ''}
      </span>
    );
  };

  const openBills = bills.filter((bill) => bill.status === 'open' && bill.unpaid_amount > 0);

  const renderOrderActions = (order: PosOrderListItem) => {
    const allowed = order.allowed_next ?? [];
    const forward = allowed.filter((status) => status !== 'cancelled');
    const nextStep = forward[0] as PosOrderStatus | undefined;
    const isPaid = order.payment_status === 'paid';
    const busy = busyOrderId === order.id;
    const isOfflineOrder = Boolean(order.offline_client_uuid);

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
        {nextStep && !isOfflineOrder && (
          <button
            type="button"
            disabled={busy}
            onClick={() => handleStatusUpdate(order, nextStep)}
            className="min-h-9 rounded-lg bg-amber-400 px-3 py-2 text-xs font-bold text-[#111111] transition hover:bg-amber-500 disabled:opacity-50"
          >
            {busy ? 'Memproses...' : NEXT_ACTION[nextStep] ?? POS_ORDER_STATUS_LABELS[nextStep]}
          </button>
        )}
        {!isOfflineOrder && !isPaid && order.payment_status !== 'refunded' && order.status !== 'cancelled' && (
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
        {isPaid && !isOfflineOrder && (
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
        {isOfflineOrder && (
          <span className="rounded-full bg-amber-100 px-2.5 py-2 text-[11px] font-semibold text-amber-800">
            Lokal · menunggu sinkronisasi
          </span>
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
        {allowed.includes('cancelled') && !isPaid && can('order_cancel') && (
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
        {isPaid && can('order_refund') && (
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

  const renderDetail = (order: PosOrderListItem, onClose?: () => void) => (
    <>
      <div className="flex items-start justify-between gap-3 border-b border-gray-100 p-4">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Detail {terminology.transaction}</p>
          <h2 className="mt-1 break-all font-mono text-sm font-bold text-gray-900">#{order.order_number}</h2>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <span className={cn('rounded-full border px-2.5 py-1 text-[11px] font-semibold', getStatusColor(order.status))}>
            {statusLabel(order)}
          </span>
          {onClose && (
            <button
              type="button"
              onClick={onClose}
              aria-label="Tutup detail"
              className="flex h-11 w-11 items-center justify-center rounded-xl text-gray-500 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
            >
              <X className="h-5 w-5" />
            </button>
          )}
        </div>
      </div>
      <div className="space-y-4 p-4">
        <div className="grid grid-cols-2 gap-3 text-sm">
          <div className="min-w-0">
            <p className="text-xs text-gray-400">{terminology.customer}</p>
            <p className="mt-1 flex items-center gap-1.5 font-semibold text-gray-800"><UserRound className="h-4 w-4 shrink-0 text-gray-400" /><span className="min-w-0 break-words">{order.customer_name || 'Walk-in'}</span></p>
          </div>
          <div className="min-w-0">
            <p className="text-xs text-gray-400">{hasTablesSelfOrder ? 'Meja / layanan' : 'Jenis transaksi'}</p>
            <p className="mt-1 flex items-center gap-1.5 font-semibold text-gray-800"><MapPin className="h-4 w-4 shrink-0 text-gray-400" /><span className="min-w-0 break-words">{hasTablesSelfOrder ? order.table_label || order.service_type || 'Bawa pulang' : order.service_type || 'Kasir'}</span></p>
          </div>
        </div>
        <div className="flex items-center justify-between border-b border-gray-100 pb-2">
          <h3 className="text-sm font-bold text-gray-900">Item transaksi</h3>
          <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">{order.items_count}</span>
        </div>
        <div className="max-h-64 space-y-3 overflow-y-auto">
          {order.items.map((item) => (
            <div key={item.id} className="flex items-start justify-between gap-3 text-sm">
              <div className="min-w-0">
                <p className="break-words font-medium text-gray-800">{item.product_name}</p>
                <p className="text-xs text-gray-500">{item.quantity} × {rupiah(item.unit_price)}</p>
                {item.options?.map((option) => <p key={option} className="text-xs text-gray-500">{option}</p>)}
              </div>
              <span className="shrink-0 font-semibold text-gray-700">{rupiah(item.line_total)}</span>
            </div>
          ))}
          {order.items.length === 0 && <p className="text-sm text-gray-500">Rincian item tidak tersedia.</p>}
        </div>
        <div className="space-y-2 border-t border-gray-100 pt-3 text-sm">
          <div className="flex justify-between text-gray-500"><span>Subtotal</span><span>{rupiah(order.subtotal_amount ?? order.total_amount)}</span></div>
          {(order.discount_amount ?? 0) > 0 && <div className="flex justify-between text-gray-500"><span>Diskon</span><span>-{rupiah(order.discount_amount)}</span></div>}
          {(order.service_amount ?? 0) > 0 && <div className="flex justify-between text-gray-500"><span>Service</span><span>{rupiah(order.service_amount ?? 0)}</span></div>}
          {(order.tax_amount ?? 0) > 0 && <div className="flex justify-between text-gray-500"><span>Pajak</span><span>{rupiah(order.tax_amount ?? 0)}</span></div>}
          <div className="flex justify-between border-t border-gray-100 pt-2 text-base font-bold text-gray-900"><span>Total</span><span>{rupiah(order.final_amount ?? order.total_amount)}</span></div>
        </div>
        <PaymentBadge method={order.payment_method} status={order.payment_status} paidManually={order.paid_manually} />
        {renderOrderActions(order)}
      </div>
    </>
  );

  const openDetail = (order: PosOrderListItem) => {
    setSelectedOrderId(order.id);
    setDetailOpen(true);
  };

  // Compact list actions: the next required step plus access to the full detail.
  const renderPrimaryActions = (order: PosOrderListItem) => {
    const forward = (order.allowed_next ?? []).filter((status) => status !== 'cancelled');
    const nextStep = forward[0] as PosOrderStatus | undefined;
    const isPaid = order.payment_status === 'paid';
    const busy = busyOrderId === order.id;
    const isOfflineOrder = Boolean(order.offline_client_uuid);
    const canPay = !isPaid && order.payment_status !== 'refunded' && order.status !== 'cancelled';

    return (
      <div className="flex flex-wrap items-center gap-2">
        {nextStep && !isOfflineOrder && (
          <button
            type="button"
            disabled={busy}
            onClick={() => handleStatusUpdate(order, nextStep)}
            className="min-h-11 flex-1 rounded-xl bg-amber-400 px-3 text-sm font-bold text-[#111111] transition hover:bg-amber-500 disabled:opacity-50"
          >
            {busy ? 'Memproses...' : NEXT_ACTION[nextStep] ?? POS_ORDER_STATUS_LABELS[nextStep]}
          </button>
        )}
        {canPay && (
          <button
            type="button"
            onClick={() => {
              setCurrentPaymentOrder(order);
              setShowPaymentModal(true);
            }}
            className="min-h-11 flex-1 rounded-xl bg-[#111111] px-3 text-sm font-bold text-amber-400 transition hover:bg-[#2a241d]"
          >
            Bayar
          </button>
        )}
        {isOfflineOrder && (
          <span className="rounded-full bg-amber-100 px-2.5 py-2 text-[11px] font-semibold text-amber-800">
            Lokal · menunggu sinkronisasi
          </span>
        )}
        <button
          type="button"
          onClick={() => openDetail(order)}
          aria-label={`Lihat detail ${terminology.transaction.toLowerCase()} ${order.order_number}`}
          className="flex min-h-11 items-center justify-center gap-1 rounded-xl border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
        >
          Detail
          <ChevronRight className="h-4 w-4" />
        </button>
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
            {order.offline_client_uuid && (
              <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">Lokal</span>
            )}
          </div>
          <p className="mt-1 truncate text-sm font-bold text-gray-900">{order.customer_name || `${terminology.customer} umum`}</p>
        </div>
        <span className={cn('shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-semibold', getStatusColor(order.status))}>
          {statusLabel(order)}
        </span>
      </div>
      <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
        <span className="inline-flex items-center gap-1">
          <MapPin className="h-3.5 w-3.5" />
          {hasTablesSelfOrder ? order.table_label || order.service_type || 'Bawa pulang' : order.service_type || 'Kasir'}
        </span>
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
        <PaymentBadge method={order.payment_method} status={order.payment_status} paidManually={order.paid_manually} />
        <span className="text-base font-bold text-gray-900">{rupiah(order.final_amount ?? order.total_amount)}</span>
      </div>
      {order.cancel_reason && <p className="mt-2 text-xs text-red-600">Alasan batal: {order.cancel_reason}</p>}
    </>
  );

  return (
    <div className="min-h-screen min-w-0 bg-[#F7F8FA] p-3 pb-28 [overflow-wrap:anywhere] sm:p-4 md:pb-4 lg:p-6">
      <div className="w-full space-y-4 sm:space-y-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h1 className="text-2xl font-bold text-gray-900 md:text-3xl">{terminology.transaction}</h1>
            <p className="mt-1 flex items-center gap-1.5 text-sm text-gray-600">
              {live ? <Wifi className="h-4 w-4 shrink-0 text-green-600" /> : <WifiOff className="h-4 w-4 shrink-0 text-gray-400" />}
              {live ? `${terminology.transaction} baru masuk otomatis` : `Menyegarkan tiap ${POLL_FALLBACK_MS / 1000} detik`}
            </p>
          </div>
          <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
            <button
              type="button"
              onClick={() => setShowNewOrderModal(true)}
              className="hidden min-h-11 items-center justify-center gap-2 rounded-xl bg-[#111111] px-5 text-base font-bold text-amber-400 shadow-sm transition hover:bg-[#2a241d] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2 md:order-last md:flex"
            >
              <CirclePlus className="h-5 w-5" />
              Tambah {terminology.transaction}
            </button>
            <div className="hidden items-center gap-2 md:flex">
              <button
                type="button"
                onClick={() => setAutoRefresh(!autoRefresh)}
                aria-pressed={autoRefresh}
                className={cn(
                  'min-h-11 rounded-full border px-3 text-sm font-medium transition-colors',
                  autoRefresh ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-gray-200 bg-gray-100 text-gray-700 hover:bg-gray-200'
                )}
              >
                Otomatis: {autoRefresh ? 'Aktif' : 'Nonaktif'}
              </button>
              <button
                type="button"
                onClick={() => void loadOrders()}
                className="min-h-11 rounded-lg border border-gray-200 bg-white px-3 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
              >
                Muat ulang
              </button>
            </div>
            <CashDrawerButton />
            <div className="relative">
              <button
                type="button"
                onClick={() => setMenuOpen((open) => !open)}
                aria-haspopup="menu"
                aria-expanded={menuOpen}
                aria-label="Menu tambahan"
                className="flex h-11 w-11 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
              >
                <MoreHorizontal className="h-5 w-5" />
              </button>
              {menuOpen && (
                <>
                  <div className="fixed inset-0 z-30" onClick={() => setMenuOpen(false)} aria-hidden="true" />
                  <div role="menu" className="absolute right-0 top-12 z-40 w-64 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg">
                    <div className="border-b border-gray-100 px-4 pb-2 pt-1 md:hidden" role="group" aria-label={`Tampilan ${terminology.transaction.toLowerCase()}`}>
                      <p className="py-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Tampilan</p>
                      {([['grid', 'Kartu'], ['list', 'Daftar'], ['split', 'Detail']] as const).map(([mode, label]) => (
                        <button
                          key={mode}
                          type="button"
                          role="menuitemradio"
                          aria-checked={viewMode === mode}
                          onClick={() => { setViewMode(mode); setMenuOpen(false); }}
                          className={cn('flex min-h-11 w-full items-center justify-between text-left text-sm hover:bg-gray-50', viewMode === mode ? 'font-semibold text-gray-900' : 'text-gray-700')}
                        >
                          {label}
                          {viewMode === mode && <CheckCircle className="h-4 w-4 text-amber-500" />}
                        </button>
                      ))}
                    </div>
                    <button
                      type="button"
                      role="menuitem"
                      onClick={() => { setAutoRefresh(!autoRefresh); setMenuOpen(false); }}
                      className="flex min-h-11 w-full items-center justify-between px-4 text-left text-sm text-gray-800 hover:bg-gray-50 md:hidden"
                    >
                      <span>Pembaruan otomatis</span>
                      <span className={cn('rounded-full px-2 py-0.5 text-xs font-semibold', autoRefresh ? 'bg-amber-100 text-amber-900' : 'bg-gray-100 text-gray-600')}>{autoRefresh ? 'Aktif' : 'Nonaktif'}</span>
                    </button>
                    <button
                      type="button"
                      role="menuitem"
                      onClick={() => { void loadOrders(); setMenuOpen(false); }}
                      className="flex min-h-11 w-full items-center px-4 text-left text-sm text-gray-800 hover:bg-gray-50 md:hidden"
                    >
                      Muat ulang
                    </button>
                    {hasTablesSelfOrder && (
                      <button
                        type="button"
                        role="menuitem"
                        onClick={() => { setShowBills(!showBills); setMenuOpen(false); }}
                        className="flex min-h-11 w-full items-center gap-2 px-4 text-left text-sm text-gray-800 hover:bg-gray-50"
                      >
                        <Receipt className="h-4 w-4" />
                        Tagihan {terminology.table}{openBills.length > 0 ? ` (${openBills.length})` : ''}
                      </button>
                    )}
                    <button
                      type="button"
                      role="menuitem"
                      onClick={() => { handleResetOrderList(); setMenuOpen(false); }}
                      title={`Bersihkan tampilan ${terminology.transaction.toLowerCase()}`}
                      className="flex min-h-11 w-full items-center gap-2 px-4 text-left text-sm text-gray-800 hover:bg-gray-50"
                    >
                      <RotateCcw className="h-4 w-4" />
                      Bersihkan Tampilan
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>

        {showInfoBoxes && (
          <div className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            <div className="flex items-start gap-2">
              <Info className="mt-0.5 h-4 w-4 flex-none" />
              <p>
                Reset hanya membersihkan tampilan {terminology.transaction.toLowerCase()}. Data tetap tersimpan dan dapat digunakan untuk laporan. Reset otomatis berjalan setiap hari pukul 06.00, terakhir pada {resetTimeLabel}.
              </p>
            </div>
          </div>
        )}

        {showInfoBoxes && unfinishedOrdersCount > 0 && (
          <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <Clock className="mt-0.5 h-5 w-5 text-amber-600" />
            <div className="flex-1">
              <h3 className="mb-1 text-sm font-semibold text-amber-900">{unfinishedOrdersCount} {terminology.transaction.toLowerCase()} belum selesai</h3>
              <p className="text-sm text-amber-800">Lanjutkan status transaksi sampai Selesai, atau batalkan dengan alasan.</p>
            </div>
          </div>
        )}

        {hasTablesSelfOrder && showBills && (
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
                    <div className="flex flex-wrap items-center gap-3">
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

        <div className="flex min-w-0 flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm md:flex-row md:items-center">
          <div className="relative min-w-0 flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 transform text-gray-400" />
            <input
              type="search"
              placeholder={`Cari nomor ${terminology.transaction.toLowerCase()} atau nama ${terminology.customer.toLowerCase()}...`}
              aria-label={`Cari ${terminology.transaction.toLowerCase()}`}
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="min-h-11 w-full rounded-xl border border-gray-200 bg-gray-50 py-2 pl-10 pr-4 text-base text-gray-900 placeholder-gray-400 focus:border-amber-300 focus:bg-white focus:ring-2 focus:ring-amber-300"
            />
          </div>
          <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 md:hidden" role="group" aria-label={`Filter status ${terminology.transaction.toLowerCase()}`}>
            {(['all', ...Object.keys(POS_ORDER_STATUS_LABELS)] as string[]).map((status) => (
              <button
                key={status}
                type="button"
                aria-pressed={statusFilter === status}
                onClick={() => setStatusFilter(status)}
                className={cn(
                  'min-h-11 shrink-0 rounded-full border px-4 text-sm font-medium',
                  statusFilter === status ? 'border-amber-300 bg-amber-100 text-amber-950' : 'border-gray-200 bg-white text-gray-600'
                )}
              >
                {status === 'all' ? 'Semua' : POS_ORDER_STATUS_LABELS[status as PosOrderStatus]}
              </button>
            ))}
          </div>
          <select
            value={statusFilter}
            onChange={(event) => setStatusFilter(event.target.value)}
            aria-label={`Filter status ${terminology.transaction.toLowerCase()}`}
            className="hidden min-h-11 rounded-xl border border-gray-200 bg-white px-3 text-sm text-gray-700 focus:border-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-200 md:block"
          >
            <option value="all">Semua status</option>
            {(Object.keys(POS_ORDER_STATUS_LABELS) as PosOrderStatus[]).map((status) => (
              <option key={status} value={status}>{POS_ORDER_STATUS_LABELS[status]}</option>
            ))}
          </select>
          <div className="hidden min-h-11 items-center gap-1 rounded-xl bg-gray-100 p-1 md:flex" role="group" aria-label={`Tampilan ${terminology.transaction.toLowerCase()}`}>
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
                  'flex min-h-9 min-w-0 items-center justify-center gap-1 rounded-lg px-2 text-xs font-semibold transition md:shrink-0 md:gap-1.5 md:px-3',
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
                <HellomLoading mode="inline" label={`Memuat ${terminology.transaction.toLowerCase()}...`} />
              </div>
            )}
            {filteredOrders.map((order) => (
              <article
                key={order.id}
                className="flex min-w-0 flex-col rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-amber-300 hover:shadow-md"
              >
                <button
                  type="button"
                  onClick={() => openDetail(order)}
                  className="min-w-0 rounded-xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                  aria-label={`Lihat detail ${terminology.transaction.toLowerCase()} ${order.order_number}`}
                >
                  {renderOrderSummary(order)}
                </button>
                <div className="mt-4 border-t border-gray-100 pt-3">{renderPrimaryActions(order)}</div>
              </article>
            ))}
          </div>
        ) : (
          <div className={cn(
            'grid min-w-0 grid-cols-1 gap-4',
            viewMode === 'list' ? 'lg:grid-cols-[220px_minmax(0,1fr)]' : 'xl:grid-cols-[minmax(0,1fr)_340px]'
          )}>
            {viewMode === 'list' && (
              <aside className="h-fit min-w-0 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm sm:p-4">
                <h2 className="mb-3 text-sm font-bold text-gray-900">Filter {terminology.transaction}</h2>
                <nav className="flex gap-2 overflow-x-auto lg:flex-col lg:overflow-visible" aria-label={`Filter status ${terminology.transaction.toLowerCase()}`}>
                  <button
                    type="button"
                    onClick={() => setStatusFilter('all')}
                    aria-pressed={statusFilter === 'all'}
                    className={cn(
                      'flex min-h-10 shrink-0 items-center justify-between gap-3 rounded-xl px-3 text-left text-sm font-medium',
                      statusFilter === 'all' ? 'bg-amber-100 text-amber-950' : 'text-gray-600 hover:bg-gray-50'
                    )}
                  >
                    <span>Semua {terminology.transaction}</span>
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
              Daftar {terminology.transaction}
            </span>
          </div>

          {loading && (
            <HellomLoading mode="inline" label={`Memuat ${terminology.transaction.toLowerCase()}...`} />
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
                  {viewMode === 'split' && selectedOrder?.id !== order.id && (
                    <button
                      type="button"
                      onClick={() => setSelectedOrderId(order.id)}
                      aria-label={`Pilih ${terminology.transaction.toLowerCase()} ${order.order_number}`}
                      className="sr-only xl:not-sr-only xl:mb-1 xl:block xl:min-h-6 xl:text-[11px] xl:font-semibold xl:text-gray-400 xl:hover:text-amber-800"
                    >
                      Tampilkan di panel
                    </button>
                  )}
                  <div className="flex flex-col items-start justify-between gap-2 sm:flex-row">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-baseline gap-1.5">
                        <span className="shrink-0 font-mono text-[10px] text-gray-400">#{order.order_number}</span>
                        <span className="break-words text-sm font-semibold text-gray-900">{order.customer_name || `${terminology.customer} umum`}</span>
                        {order.order_source === 'public_customer' && (
                          <span className="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700">Self-order</span>
                        )}
                      </div>
                      <p className="mt-0.5 truncate text-[11px] text-gray-500">
                        {hasTablesSelfOrder ? order.table_label || order.service_type : order.service_type || 'Kasir'}
                        {' · '}
                        {order.items_count} {terminology.product.toLowerCase()}
                        {' · '}
                        {new Date(order.created_at).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                      </p>
                      {order.cancel_reason && <p className="mt-0.5 text-[11px] text-red-600">Alasan batal: {order.cancel_reason}</p>}
                    </div>
                    <div className="min-w-0 text-left sm:max-w-[45%] sm:text-right">
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
                    <PaymentBadge method={order.payment_method} status={order.payment_status} paidManually={order.paid_manually} />
                    {(order.manual_discount_amount ?? 0) > 0 && (
                      <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800">Diskon kasir {rupiah(order.manual_discount_amount ?? 0)}</span>
                    )}
                    {(order.receipt_prints ?? 0) > 0 && (
                      <span className="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[11px] text-gray-500">Struk dicetak {order.receipt_prints}×</span>
                    )}
                    {order.split_from_order_id && <span className="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[11px] text-gray-500">Pisahan bill</span>}
                  </div>

                  <div className="mt-3">{renderPrimaryActions(order)}</div>
                </div>
              );
            })}
          </div>
        </div>
            {viewMode === 'split' && selectedOrder && (
              <aside className="hidden h-fit min-w-0 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm xl:sticky xl:top-6 xl:block">
                {renderDetail(selectedOrder)}
              </aside>
            )}
          </div>
        )}

        {detailOpen && selectedOrder && (
          <div className={cn('fixed inset-0 z-[45] flex items-end justify-center bg-black/40 md:items-center md:p-4', viewMode === 'split' && 'xl:hidden')} onClick={() => setDetailOpen(false)}>
            <div
              role="dialog"
              aria-modal="true"
              aria-label={`Detail ${terminology.transaction.toLowerCase()} ${selectedOrder.order_number}`}
              className="flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl md:max-w-lg md:rounded-2xl"
              onClick={(event) => event.stopPropagation()}
            >
              <div className="min-h-0 flex-1 overflow-y-auto">{renderDetail(selectedOrder, () => setDetailOpen(false))}</div>
            </div>
          </div>
        )}

        {!loading && filteredOrders.length === 0 && (
          <div className="py-12 text-center">
            <p className="text-gray-500">List order sedang bersih. Data lama tetap aman di laporan.</p>
          </div>
        )}

        <div
          className="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 px-4 pt-3 backdrop-blur md:hidden"
          style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom))' }}
        >
          <button
            type="button"
            onClick={() => setShowNewOrderModal(true)}
            className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#111111] text-base font-bold text-amber-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
          >
            <CirclePlus className="h-5 w-5" />
            Tambah {terminology.transaction}
          </button>
        </div>

        <NewOrderModal isOpen={showNewOrderModal} onClose={() => setShowNewOrderModal(false)} onOrderCreated={announce} />

        <ReceiptModal isOpen={showReceiptModal} onClose={() => setShowReceiptModal(false)} orderId={receiptOrderId} />
        {managing && <OrderManageDialog order={managing} onClose={() => setManaging(null)} onChanged={(m) => { setToast(m); announce(); }} />}
        {methodOrder && <PaymentMethodDialog order={methodOrder} onClose={() => setMethodOrder(null)} onChanged={(m) => { setToast(m); announce(); }} />}
        {hasTablesSelfOrder && movingBill && <MoveBillDialog bill={movingBill} onClose={() => setMovingBill(null)} onChanged={(m) => { setToast(m); announce(); }} />}
        {toast && (
          <div role="status" data-toast className="fixed inset-x-0 bottom-20 z-[70] mx-auto w-fit max-w-[90vw] rounded-2xl bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-lg sm:bottom-6">
            {toast}
          </div>
        )}
        {approval && (
          <OwnerApprovalDialog
            title={approval.kind === 'cancel'
              ? `Batalkan ${terminology.transaction.toLowerCase()}?`
              : `Kembalikan dana ${terminology.transaction.toLowerCase()}?`}
            summary={(
              <>
                <p className="font-semibold text-gray-900">#{approval.order.order_number} · {rupiah(approval.order.final_amount)}</p>
                <p className="mt-1">{approval.kind === 'cancel'
                  ? `${terminology.transaction} dibatalkan dan tidak bisa dikembalikan. Stok dan poin pelanggan dikembalikan.`
                  : `Seluruh pembayaran dikembalikan kepada ${terminology.customer.toLowerCase()}. Poin dari transaksi ini ditarik; penanganan stok mengikuti profil usaha.`}</p>
              </>
            )}
            reasonLabel={approval.kind === 'cancel' ? 'Alasan pembatalan' : 'Alasan refund'}
            reasonPlaceholder={approval.kind === 'cancel' ? `Mis. ${terminology.customer.toLowerCase()} membatalkan transaksi` : 'Mis. salah input produk'}
            confirmLabel={approval.kind === 'cancel' ? `Batalkan ${terminology.transaction.toLowerCase()}` : 'Kembalikan dana'}
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

        {hasTablesSelfOrder && payingBill && (
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
