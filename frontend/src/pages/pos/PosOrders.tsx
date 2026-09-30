import { useCallback, useEffect, useMemo, useState } from 'react';
import { Search, Clock, CheckCircle, XCircle, ShoppingCart, RotateCcw, Info, Wifi, WifiOff, Receipt } from 'lucide-react';
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
import ReceiptModal from '@/components/pos/ReceiptModal';
import PaymentModal from '@/components/pos/PaymentModal';
import CashDrawerButton from '@/components/pos/CashDrawerButton';
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
  const [orders, setOrders] = useState<PosOrderListItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchTerm, setSearchTerm] = useState('');
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
      const reason = window.prompt(`Alasan membatalkan pesanan #${order.order_number}?`);
      if (!reason || !reason.trim()) return;
      void runAction(order.id, () => cancelPosOrder(order.id, reason.trim()));
      return;
    }
    void runAction(order.id, () => updatePosOrderStatus(order.id, next));
  };

  const handleRefund = (order: PosOrderListItem) => {
    const reason = window.prompt(`Refund penuh ${rupiah(order.final_amount)} untuk #${order.order_number}. Alasan?`);
    if (!reason || !reason.trim()) return;
    void runAction(order.id, () => refundPosOrder(order.id, reason.trim()));
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

  return (
    <div className="min-h-screen bg-gray-50 p-2 sm:p-4 lg:p-6">
      <div className="mx-auto max-w-7xl space-y-4 sm:space-y-6">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">Daftar Pesanan</h1>
            <p className="mt-1 flex items-center gap-1.5 text-gray-600">
              {live ? <Wifi className="h-4 w-4 text-green-600" /> : <WifiOff className="h-4 w-4 text-gray-400" />}
              {live ? 'Live — pesanan baru masuk otomatis' : `Menyegarkan tiap ${POLL_FALLBACK_MS / 1000} detik`}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <CashDrawerButton />
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
                autoRefresh ? 'bg-amber-100 text-amber-900 hover:bg-amber-200' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
              )}
            >
              Auto: {autoRefresh ? 'ON' : 'OFF'}
            </button>
            <button
              onClick={() => setShowBills(!showBills)}
              className={cn(
                'flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm transition-colors',
                showBills ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
              )}
            >
              <Receipt className="h-4 w-4" />
              Tagihan Meja{openBills.length > 0 ? ` (${openBills.length})` : ''}
            </button>
            <button
              onClick={() => setShowNewOrderModal(true)}
              className="flex items-center gap-1.5 rounded-lg bg-amber-400 px-3 py-1.5 text-sm text-[#111111] transition-colors hover:bg-amber-500"
            >
              <ShoppingCart className="h-4 w-4" />
              Tambah Pesanan
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
            <option value="all">Semua status</option>
            {(Object.keys(POS_ORDER_STATUS_LABELS) as PosOrderStatus[]).map((status) => (
              <option key={status} value={status}>{POS_ORDER_STATUS_LABELS[status]}</option>
            ))}
          </select>
        </div>

        {error && (
          <div className="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
            <p className="text-red-800">{error}</p>
            <button onClick={() => void loadOrders()} className="mt-2 rounded bg-red-600 px-4 py-2 text-white hover:bg-red-700">
              Coba Lagi
            </button>
          </div>
        )}

        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
          <div className="border-b border-gray-100 bg-gray-50 px-4 py-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Daftar Pesanan</span>
          </div>

          {loading && (
            <div className="p-8 text-center">
              <div className="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-b-2 border-amber-400" />
              <p className="text-gray-600">Memuat pesanan...</p>
            </div>
          )}

          <div className="divide-y divide-gray-100">
            {filteredOrders.map((order) => {
              const allowed = order.allowed_next ?? [];
              const forward = allowed.filter((s) => s !== 'cancelled');
              const nextStep = forward[0] as PosOrderStatus | undefined;
              const isPaid = order.payment_status === 'paid';
              const busy = busyOrderId === order.id;

              return (
                <div key={order.id} className={cn('px-4 py-3 transition-colors hover:bg-gray-50/60', getRowBackgroundColor(order.status))}>
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
                  </div>

                  <div className="mt-2.5 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-2.5">
                    {!isPaid && order.payment_status !== 'refunded' && order.status !== 'cancelled' && (
                      <button
                        onClick={() => {
                          setCurrentPaymentOrder(order);
                          setShowPaymentModal(true);
                        }}
                        className="flex items-center gap-1.5 rounded-lg bg-green-600 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-green-700"
                      >
                        💳 Bayar
                      </button>
                    )}
                    {nextStep && (
                      <button
                        disabled={busy}
                        onClick={() => handleStatusUpdate(order, nextStep)}
                        className="rounded-lg bg-amber-400 px-3 py-1.5 text-xs font-bold text-[#111111] transition hover:bg-amber-500 disabled:opacity-50"
                      >
                        {NEXT_ACTION[nextStep] ?? POS_ORDER_STATUS_LABELS[nextStep]}
                      </button>
                    )}

                    <div className="ml-auto flex items-center gap-1.5">
                      <button
                        onClick={() => {
                          setReceiptOrderId(order.id);
                          setShowReceiptModal(true);
                        }}
                        title="Cetak kwitansi"
                        className="flex h-7 w-7 items-center justify-center rounded-lg bg-gray-100 text-sm transition hover:bg-gray-200"
                      >
                        🖨️
                      </button>
                      {forward.length > 1 && (
                        <select
                          value=""
                          disabled={busy}
                          onChange={(e) => e.target.value && handleStatusUpdate(order, e.target.value)}
                          className="rounded-lg border border-gray-200 bg-white py-1 pl-2 pr-6 text-xs text-gray-900"
                        >
                          <option value="">Lompat ke…</option>
                          {forward.slice(1).map((s) => (
                            <option key={s} value={s}>{POS_ORDER_STATUS_LABELS[s as PosOrderStatus] ?? s}</option>
                          ))}
                        </select>
                      )}
                      {allowed.includes('cancelled') && !isPaid && canPos('order_cancel') && (
                        <button
                          disabled={busy}
                          onClick={() => handleStatusUpdate(order, 'cancelled')}
                          className="rounded-lg border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"
                        >
                          Batalkan
                        </button>
                      )}
                      {isPaid && canPos('order_refund') && (
                        <button
                          disabled={busy}
                          onClick={() => handleRefund(order)}
                          className="rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                        >
                          Refund
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {!loading && filteredOrders.length === 0 && (
          <div className="py-12 text-center">
            <p className="text-gray-500">List order sedang bersih. Data lama tetap aman di laporan.</p>
          </div>
        )}

        <NewOrderModal isOpen={showNewOrderModal} onClose={() => setShowNewOrderModal(false)} onOrderCreated={announce} />

        <ReceiptModal isOpen={showReceiptModal} onClose={() => setShowReceiptModal(false)} orderId={receiptOrderId} />

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
