import { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Copy, ReceiptText } from 'lucide-react';
import StatusTracker from '@/pages/pos/customer/StatusTracker';
import { useOrderTracking } from '@/hooks/useOrderTracking';
import { HELLOM_API_BASE } from '@/lib/hellomApi';

function formatCurrency(value: number) {
  return `Rp ${value.toLocaleString('id-ID')}`;
}

type PublicPaymentMethod = {
  key: string;
  label: string;
  info: string;
  bank_name?: string | null;
  account_number?: string | null;
  account_name?: string | null;
  number?: string | null;
  name?: string | null;
  deep_link?: string | null;
  qris_image?: string | null;
};

export default function SuccessPage() {
  const { organizationSlug, tableToken, orderNumber } = useParams<{ organizationSlug?: string; tableToken?: string; orderNumber: string }>();
  const { order, isLoading, isRefreshing, error, lastUpdated, refresh } = useOrderTracking(orderNumber);
  const [paymentMethods, setPaymentMethods] = useState<PublicPaymentMethod[]>([]);
  const backToMenu = tableToken
    ? organizationSlug
      ? `/customer/${organizationSlug}/order/${tableToken}`
      : `/customer/order/${tableToken}`
    : organizationSlug
      ? `/customer/${organizationSlug}`
      : '/';

  const copyOrderNumber = async () => {
    if (!orderNumber || !navigator.clipboard) {
      return;
    }

    await navigator.clipboard.writeText(orderNumber);
  };

  const copyText = async (value: string) => {
    if (!value || !navigator.clipboard) return;
    await navigator.clipboard.writeText(value);
  };

  useEffect(() => {
    if (!organizationSlug) return;

    const loadPaymentMethods = async () => {
      try {
        const response = await fetch(`${HELLOM_API_BASE}/pos/public/payment-methods/${organizationSlug}`, {
          headers: { Accept: 'application/json' },
        });
        const payload = await response.json();
        if (payload?.success) {
          setPaymentMethods(payload.data?.payment_methods || []);
        }
      } catch {
        setPaymentMethods([]);
      }
    };

    void loadPaymentMethods();
  }, [organizationSlug]);

  const selectedPaymentMethod = useMemo(
    () => paymentMethods.find((method) => method.key === order?.payment_method) || null,
    [order?.payment_method, paymentMethods]
  );

  return (
    <div className="min-h-screen bg-white text-[#1A1A1A]">
      <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        <Link
          to={backToMenu}
          className="inline-flex items-center gap-2 rounded-full border border-[#E6A800] bg-white px-4 py-2 text-sm font-medium text-[#1A1A1A] hover:bg-[#F9F9F9]"
        >
          <ArrowLeft className="w-4 h-4" />
          Kembali ke menu
        </Link>

        <div className="mt-6 rounded-[32px] border border-white/70 bg-white/90 p-6 shadow-[0_24px_90px_rgba(91,67,17,0.12)] backdrop-blur">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <p className="text-sm font-semibold uppercase tracking-[0.2em] text-[#F5C518]">Order berhasil dibuat</p>
              <h1 className="mt-2 text-3xl font-semibold">Your order is being prepared</h1>
              <p className="mt-3 max-w-2xl text-sm text-[#888888]">
                Simpan nomor order ini untuk memantau progres pesanan secara real-time.
              </p>
            </div>

            <button
              type="button"
              onClick={() => void copyOrderNumber()}
              className="inline-flex items-center justify-center gap-2 rounded-full bg-[#1A1A1A] px-4 py-2 text-sm font-medium text-[#F5C518] hover:bg-[#2D2D2D]"
            >
              <Copy className="w-4 h-4" />
              Copy nomor order
            </button>
          </div>

          <div className="mt-6 grid gap-6 lg:grid-cols-[1.3fr_0.9fr]">
            <div className="space-y-6">
              {isLoading ? (
                <div className="rounded-3xl border border-[#E6A800] bg-[#F9F9F9] p-5 text-sm text-[#888888]">
                  Memuat detail pesanan...
                </div>
              ) : error ? (
                <div className="rounded-3xl border border-[#E6A800] bg-[#F9F9F9] p-5 text-sm text-[#1A1A1A]">
                  {error}
                </div>
              ) : order ? (
                <>
                  <StatusTracker
                    status={order.status}
                    lastUpdated={lastUpdated}
                    isRefreshing={isRefreshing}
                    onRefresh={() => {
                      void refresh(true);
                    }}
                  />

                  <div className="rounded-3xl border border-[#E6A800] bg-white p-5">
                    <div className="flex items-center gap-2 text-[#1A1A1A]">
                      <ReceiptText className="w-5 h-5" />
                      <h2 className="text-lg font-semibold">Ringkasan item</h2>
                    </div>
                    <div className="mt-4 space-y-3">
                      {order.items.map((item) => (
                        <div key={item.id} className="flex items-start justify-between gap-4 border-b border-[#F9F9F9] pb-3 last:border-b-0">
                          <div>
                            <p className="font-medium text-[#1A1A1A]">{item.product_name}</p>
                            <p className="text-sm text-[#888888]">{item.quantity} item</p>
                          </div>
                          <p className="font-medium text-[#1A1A1A]">{formatCurrency(item.line_total)}</p>
                        </div>
                      ))}
                    </div>
                  </div>

                  {order?.payment_method === 'qris' && selectedPaymentMethod?.qris_image ? (
                    <div className="rounded-3xl border border-[#8B5CF6] bg-[#F7F5FF] p-5 text-center">
                      <p className="text-sm font-semibold uppercase tracking-[0.18em] text-[#8B5CF6]">Pembayaran QRIS</p>
                      <img src={selectedPaymentMethod.qris_image} alt="QRIS statis" className="mx-auto mt-4 h-56 w-56 rounded-2xl bg-white p-3 object-contain shadow-sm" />
                      <p className="mt-4 text-sm font-semibold text-[#1A1A1A]">Silakan lakukan pembayaran dan scan QRIS ini agar pesanan diterima.</p>
                      <p className="mt-2 text-lg font-bold text-[#1A1A1A]">{formatCurrency(order.final_amount || 0)}</p>
                    </div>
                  ) : null}

                  {order?.payment_method === 'transfer' && selectedPaymentMethod?.account_number ? (
                    <div className="rounded-3xl border border-[#E6A800] bg-white p-5">
                      <p className="text-sm font-semibold uppercase tracking-[0.18em] text-[#888888]">Transfer bank</p>
                      <p className="mt-3 text-lg font-bold text-[#1A1A1A]">{selectedPaymentMethod.account_number}</p>
                      <p className="mt-1 text-sm text-[#666666]">
                        {(selectedPaymentMethod.bank_name || 'Bank')} • a.n. {selectedPaymentMethod.account_name || '-'}
                      </p>
                      <button
                        type="button"
                        onClick={() => void copyText(selectedPaymentMethod.account_number || '')}
                        className="mt-4 rounded-full border border-[#E6A800] px-4 py-2 text-sm font-semibold text-[#1A1A1A]"
                      >
                        Salin rekening
                      </button>
                    </div>
                  ) : null}

                  {(order?.payment_method === 'gopay' || order?.payment_method === 'dana') && selectedPaymentMethod?.number ? (
                    <div className="rounded-3xl border border-[#E6A800] bg-white p-5">
                      <p className="text-sm font-semibold uppercase tracking-[0.18em] text-[#888888]">
                        {order?.payment_method === 'gopay' ? 'GoPay' : 'DANA'}
                      </p>
                      <p className="mt-3 text-lg font-bold text-[#1A1A1A]">{selectedPaymentMethod.number}</p>
                      <p className="mt-1 text-sm text-[#666666]">a.n. {selectedPaymentMethod.name || '-'}</p>
                      <button
                        type="button"
                        onClick={() => void copyText(selectedPaymentMethod.number || '')}
                        className="mt-4 rounded-full border border-[#E6A800] px-4 py-2 text-sm font-semibold text-[#1A1A1A]"
                      >
                        Salin nomor
                      </button>
                    </div>
                  ) : null}
                </>
              ) : null}
            </div>

            <div className="rounded-3xl border border-[#E6A800] bg-[#F9F9F9] p-5">
              <p className="text-sm font-semibold uppercase tracking-[0.18em] text-[#888888]">Detail order</p>
              <div className="mt-4 space-y-4 text-sm">
                <div className="flex justify-between gap-4">
                  <span className="text-[#888888]">Nomor order</span>
                  <span className="font-semibold text-[#1A1A1A]">{orderNumber}</span>
                </div>
                {order?.table_label && (
                  <div className="flex justify-between gap-4">
                    <span className="text-[#888888]">Meja</span>
                    <span className="font-semibold text-[#1A1A1A]">{order.table_label}</span>
                  </div>
                )}
                {order?.customer_name && (
                  <div className="flex justify-between gap-4">
                    <span className="text-[#888888]">Nama</span>
                    <span className="font-semibold text-[#1A1A1A]">{order.customer_name}</span>
                  </div>
                )}
                <div className="flex justify-between gap-4">
                  <span className="text-[#888888]">Pembayaran</span>
                  <span className="font-semibold capitalize text-[#1A1A1A]">{order?.payment_method || 'Belum dipilih'}</span>
                </div>
                <div className="flex justify-between gap-4">
                  <span className="text-[#888888]">Total</span>
                  <span className="font-semibold text-[#1A1A1A]">{formatCurrency(order?.final_amount || 0)}</span>
                </div>
                {order?.created_at && (
                  <div className="flex justify-between gap-4">
                    <span className="text-[#888888]">Dibuat</span>
                    <span className="font-semibold text-[#1A1A1A]">
                      {new Date(order.created_at).toLocaleString('id-ID')}
                    </span>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
