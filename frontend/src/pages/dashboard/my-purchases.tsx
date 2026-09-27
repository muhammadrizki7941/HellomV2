import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { getMyPurchases, getPaymentGatewayStatus } from '@/lib/hellomApi';
import useBrand from '@/hooks/useBrand';

type PurchaseStatus = 'paid' | 'pending' | 'failed' | 'refunded';

type ManualPaymentMethod = {
  key: string;
  label: string;
};

type Purchase = {
  id: number;
  transaction_code?: string | null;
  amount_paid: number;
  payment_status: PurchaseStatus;
  payment_method?: string | null;
  payment_gateway?: string | null;
  checkout_url?: string | null;
  created_at: string;
  product?: { slug: string; name: string } | null;
};

const statusStyles: Record<PurchaseStatus, string> = {
  paid: 'bg-emerald-100 text-emerald-700',
  pending: 'bg-amber-100 text-amber-700',
  failed: 'bg-red-100 text-red-700',
  refunded: 'bg-zinc-100 text-zinc-600',
};

const statusLabels: Record<PurchaseStatus, string> = {
  paid: 'Aktif',
  pending: 'Belum Dibayar',
  failed: 'Gagal',
  refunded: 'Refund',
};

const normalizeWhatsAppNumber = (value?: string | null) => {
  const digits = String(value || '').replace(/\D/g, '');
  if (!digits) return '';
  if (digits.startsWith('62')) return digits;
  if (digits.startsWith('0')) return `62${digits.slice(1)}`;
  return digits;
};

const gatewayLabel = (provider?: string | null) => {
  if (provider === 'ipaymu') return 'iPaymu';
  if (provider === 'doku') return 'DOKU';
  if (provider === 'xendit') return 'Xendit';
  if (provider === 'manual') return 'Manual';
  if (provider === 'free') return 'Gratis';
  return '-';
};

const paymentMethodLabel = (purchase: Purchase, methods: ManualPaymentMethod[]) => {
  if (purchase.payment_gateway === 'manual') {
    const method = methods.find((item) => item.key === purchase.payment_method);
    if (method) return method.label;
    if (purchase.payment_method === 'bank_transfer') return 'Transfer Bank';
    if (purchase.payment_method === 'qris') return 'QRIS';
    if (purchase.payment_method === 'gopay') return 'GoPay';
    if (purchase.payment_method === 'dana') return 'DANA';
  }
  return gatewayLabel(purchase.payment_gateway);
};

const ActionLink = ({
  item,
  manualMethods,
  manualUrl,
  detailUrl,
}: {
  item: Purchase;
  manualMethods: ManualPaymentMethod[];
  manualUrl: string;
  detailUrl: string;
}) => {
  if (item.payment_status === 'paid' && detailUrl) {
    return (
      <Link
        to={detailUrl}
        className="inline-flex items-center gap-1 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 transition-colors"
      >
        Buka Produk
      </Link>
    );
  }
  if (item.payment_status === 'pending' && item.payment_gateway === 'manual') {
    return manualUrl ? (
      <a
        href={manualUrl}
        target="_blank"
        rel="noreferrer"
        className="inline-flex items-center gap-1 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-50 transition-colors"
      >
        Konfirmasi via WhatsApp
      </a>
    ) : (
      <span className="text-xs text-amber-700">Nomor owner belum tersedia</span>
    );
  }
  if (item.payment_status === 'pending' && item.checkout_url) {
    return (
      <a
        href={item.checkout_url}
        target="_blank"
        rel="noreferrer"
        className="inline-flex items-center gap-1 rounded-lg bg-amber-400 px-3 py-1.5 text-xs font-semibold text-gray-900 hover:bg-amber-500 transition-colors"
      >
        Lanjut Bayar
      </a>
    );
  }
  if (detailUrl) {
    return (
      <Link
        to={detailUrl}
        className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 transition-colors"
      >
        Lihat Detail
      </Link>
    );
  }
  return <span className="text-xs text-zinc-400">-</span>;
};

export default function MyPurchases() {
  const { brand } = useBrand();
  const [items, setItems] = useState<Purchase[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [manualMethods, setManualMethods] = useState<ManualPaymentMethod[]>([]);

  useEffect(() => {
    const load = async () => {
      setLoading(true);
      setError(null);
      try {
        const [response, gatewayStatus] = await Promise.all([
          getMyPurchases(),
          getPaymentGatewayStatus().catch(() => null),
        ]);
        setItems((response || []) as Purchase[]);
        setManualMethods(
          ((gatewayStatus as { manual_payment?: { methods?: ManualPaymentMethod[] } } | null)
            ?.manual_payment?.methods) || []
        );
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Gagal memuat pembelian');
      } finally {
        setLoading(false);
      }
    };
    void load();
  }, []);

  const whatsappPhone = useMemo(
    () => normalizeWhatsAppNumber(brand.support_phone),
    [brand.support_phone]
  );

  const manualConfirmationUrl = (item: Purchase) => {
    if (!whatsappPhone || !item.product?.name) return '';
    const message = [
      `Halo owner ${brand.business_name || brand.app_name},`,
      `Saya ingin konfirmasi pembayaran produk ${item.product.name}.`,
      `Invoice: ${item.transaction_code || '-'}`,
      `Metode pembayaran: ${paymentMethodLabel(item, manualMethods)}`,
      `Nominal: Rp ${Number(item.amount_paid || 0).toLocaleString('id-ID')}`,
      'Mohon dibantu cek status pembayaran saya. Terima kasih.',
    ].join('\n');
    return `https://wa.me/${whatsappPhone}?text=${encodeURIComponent(message)}`;
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Produk Saya</h1>
        <p className="text-sm text-zinc-500">Riwayat pembelian dan akses produk digital kamu.</p>
      </div>

      {error && (
        <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">
          {error}
        </div>
      )}

      {loading ? (
        <div className="py-10 text-center text-sm text-zinc-400">Memuat data pembelian...</div>
      ) : items.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 px-6 py-12 text-center">
          <p className="text-sm font-medium text-zinc-500">Belum ada produk yang dibeli.</p>
          <Link
            to="/dashboard/products"
            className="mt-3 inline-block text-sm font-semibold text-zinc-900 underline underline-offset-4 hover:text-zinc-700"
          >
            Lihat katalog produk
          </Link>
        </div>
      ) : (
        <>
          {/* Mobile card list */}
          <div className="space-y-3 md:hidden">
            {items.map((item) => {
              const detailUrl = item.product?.slug
                ? `/dashboard/products/${item.product.slug}/checkout`
                : '';
              const manualUrl = manualConfirmationUrl(item);
              return (
                <div
                  key={item.id}
                  className="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm"
                >
                  <div className="p-4">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold text-zinc-900">
                          {item.product?.name || '-'}
                        </p>
                        {item.transaction_code ? (
                          <p className="mt-0.5 text-xs text-zinc-400">
                            #{item.transaction_code}
                          </p>
                        ) : null}
                      </div>
                      <span
                        className={`flex-shrink-0 rounded-full px-2 py-1 text-xs font-semibold ${statusStyles[item.payment_status]}`}
                      >
                        {statusLabels[item.payment_status]}
                      </span>
                    </div>

                    <div className="mt-2.5 flex flex-wrap items-center gap-1.5 text-xs text-zinc-500">
                      <span className="font-semibold text-zinc-800">
                        Rp {Number(item.amount_paid || 0).toLocaleString('id-ID')}
                      </span>
                      <span className="text-zinc-300">·</span>
                      <span>{paymentMethodLabel(item, manualMethods)}</span>
                      <span className="text-zinc-300">·</span>
                      <span>{String(item.created_at || '').split('T')[0]}</span>
                    </div>
                  </div>

                  <div className="border-t border-zinc-100 px-4 py-3">
                    <ActionLink
                      item={item}
                      manualMethods={manualMethods}
                      manualUrl={manualUrl}
                      detailUrl={detailUrl}
                    />
                  </div>
                </div>
              );
            })}
          </div>

          {/* Desktop table */}
          <div className="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm md:block">
            <table className="w-full text-sm">
              <thead className="bg-zinc-50">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                  <th className="px-5 py-3">Produk</th>
                  <th className="px-5 py-3">Harga</th>
                  <th className="px-5 py-3">Metode</th>
                  <th className="px-5 py-3">Status</th>
                  <th className="px-5 py-3">Tanggal</th>
                  <th className="px-5 py-3">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-100">
                {items.map((item) => {
                  const detailUrl = item.product?.slug
                    ? `/dashboard/products/${item.product.slug}/checkout`
                    : '';
                  const manualUrl = manualConfirmationUrl(item);
                  return (
                    <tr key={item.id} className="hover:bg-zinc-50/60">
                      <td className="px-5 py-3.5">
                        <div className="font-semibold text-zinc-900">
                          {item.product?.name || '-'}
                        </div>
                        {item.transaction_code ? (
                          <div className="mt-0.5 text-xs text-zinc-400">
                            #{item.transaction_code}
                          </div>
                        ) : null}
                      </td>
                      <td className="px-5 py-3.5 font-medium text-zinc-800">
                        Rp {Number(item.amount_paid || 0).toLocaleString('id-ID')}
                      </td>
                      <td className="px-5 py-3.5 text-zinc-600">
                        {paymentMethodLabel(item, manualMethods)}
                      </td>
                      <td className="px-5 py-3.5">
                        <span
                          className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusStyles[item.payment_status]}`}
                        >
                          {statusLabels[item.payment_status]}
                        </span>
                      </td>
                      <td className="px-5 py-3.5 text-zinc-500">
                        {String(item.created_at || '').split('T')[0]}
                      </td>
                      <td className="px-5 py-3.5">
                        <ActionLink
                          item={item}
                          manualMethods={manualMethods}
                          manualUrl={manualUrl}
                          detailUrl={detailUrl}
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
