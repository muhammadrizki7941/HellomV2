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
  pending: 'Menunggu Pembayaran',
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
        setManualMethods(((gatewayStatus as { manual_payment?: { methods?: ManualPaymentMethod[] } } | null)?.manual_payment?.methods) || []);
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
        <p className="text-sm text-zinc-600">Riwayat pembelian, metode pembayaran, dan akses produk digital kamu.</p>
      </div>

      {error && (
        <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">
          {error}
        </div>
      )}

      {loading ? (
        <div className="text-sm text-zinc-500">Memuat pembelian...</div>
      ) : (
        <div className="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
          <table className="w-full text-sm">
            <thead className="bg-zinc-50 text-zinc-600">
              <tr>
                <th className="px-4 py-3 text-left font-semibold">Produk</th>
                <th className="px-4 py-3 text-left font-semibold">Harga</th>
                <th className="px-4 py-3 text-left font-semibold">Metode</th>
                <th className="px-4 py-3 text-left font-semibold">Status</th>
                <th className="px-4 py-3 text-left font-semibold">Tanggal Beli</th>
                <th className="px-4 py-3 text-left font-semibold">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100">
              {items.map((item) => {
                const detailUrl = item.product?.slug ? `/dashboard/products/${item.product.slug}/checkout` : '';
                const manualUrl = manualConfirmationUrl(item);

                return (
                  <tr key={item.id}>
                    <td className="px-4 py-3">
                      <div className="font-semibold text-zinc-900">{item.product?.name || '-'}</div>
                      {item.transaction_code ? (
                        <div className="mt-1 text-xs text-zinc-500">Invoice: {item.transaction_code}</div>
                      ) : null}
                    </td>
                    <td className="px-4 py-3">Rp {Number(item.amount_paid || 0).toLocaleString('id-ID')}</td>
                    <td className="px-4 py-3 text-zinc-700">{paymentMethodLabel(item, manualMethods)}</td>
                    <td className="px-4 py-3">
                      <span className={`rounded-full px-2 py-1 text-xs font-semibold ${statusStyles[item.payment_status]}`}>
                        {statusLabels[item.payment_status]}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-zinc-500">{String(item.created_at || '').split('T')[0]}</td>
                    <td className="px-4 py-3">
                      {item.payment_status === 'paid' && detailUrl ? (
                        <Link to={detailUrl} className="text-sm font-semibold text-zinc-900 hover:text-zinc-700">
                          Buka produk
                        </Link>
                      ) : item.payment_status === 'pending' && item.payment_gateway === 'manual' ? (
                        manualUrl ? (
                          <a
                            href={manualUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="text-sm font-semibold text-zinc-900 hover:text-zinc-700"
                          >
                            Konfirmasi via WhatsApp
                          </a>
                        ) : (
                          <span className="text-xs text-amber-700">Nomor owner belum tersedia</span>
                        )
                      ) : item.payment_status === 'pending' && item.checkout_url ? (
                        <a
                          href={item.checkout_url}
                          target="_blank"
                          rel="noreferrer"
                          className="text-sm font-semibold text-zinc-900 hover:text-zinc-700"
                        >
                          Lanjut bayar
                        </a>
                      ) : detailUrl ? (
                        <Link to={detailUrl} className="text-sm font-semibold text-zinc-900 hover:text-zinc-700">
                          Lihat detail
                        </Link>
                      ) : (
                        <span className="text-xs text-zinc-500">-</span>
                      )}
                    </td>
                  </tr>
                );
              })}
              {items.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-zinc-500">
                    Belum ada pembelian.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
