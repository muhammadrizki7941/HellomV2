import { useEffect, useState } from 'react';
import { CheckCircle, RotateCcw } from 'lucide-react';
import { approveProductPurchase, getAdminProductPurchases, refundProductPurchase } from '@/lib/hellomApi';

type Purchase = {
  id: number;
  transaction_code?: string | null;
  amount_paid: number;
  payment_status: 'pending' | 'paid' | 'failed' | 'refunded';
  payment_method?: string | null;
  payment_gateway?: string | null;
  created_at: string;
  user?: { name?: string; email?: string } | null;
  product?: { name?: string; slug?: string } | null;
};

export default function AdminProductPurchases() {
  const [items, setItems] = useState<Purchase[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadPurchases = async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await getAdminProductPurchases();
      const payload = response as { data?: Purchase[] };
      setItems(payload.data || []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat pembelian');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadPurchases();
  }, []);

  const handleApprove = async (purchase: Purchase) => {
    try {
      await approveProductPurchase(purchase.id);
      await loadPurchases();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal approve');
    }
  };

  const handleRefund = async (purchase: Purchase) => {
    if (!window.confirm('Refund pembelian ini?')) return;
    try {
      await refundProductPurchase(purchase.id);
      await loadPurchases();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal refund');
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Pembelian Produk</h1>
        <p className="text-sm text-zinc-600">Pantau transaksi produk digital. Pembayaran manual dikonfirmasi super admin, gateway akan aktif otomatis lewat webhook.</p>
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600">
          {error}
        </div>
      )}

      <div className="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-zinc-50 text-zinc-600">
            <tr>
              <th className="text-left font-semibold px-4 py-3">User</th>
              <th className="text-left font-semibold px-4 py-3">Produk</th>
              <th className="text-left font-semibold px-4 py-3">Amount</th>
              <th className="text-left font-semibold px-4 py-3">Metode</th>
              <th className="text-left font-semibold px-4 py-3">Status</th>
              <th className="text-left font-semibold px-4 py-3">Tanggal</th>
              <th className="text-left font-semibold px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100">
            {loading ? (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-zinc-500">Memuat data...</td>
              </tr>
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-zinc-500">Belum ada pembelian.</td>
              </tr>
            ) : (
              items.map((item) => (
                <tr key={item.id}>
                  <td className="px-4 py-3">
                    <div className="font-semibold text-zinc-900">{item.user?.name || '-'}</div>
                    <div className="text-xs text-zinc-500">{item.user?.email || '-'}</div>
                  </td>
                  <td className="px-4 py-3 text-zinc-600">
                    <div className="font-medium text-zinc-900">{item.product?.name || '-'}</div>
                    <div className="text-xs text-zinc-500">{item.transaction_code || '-'}</div>
                  </td>
                  <td className="px-4 py-3 text-zinc-600">Rp {Number(item.amount_paid || 0).toLocaleString('id-ID')}</td>
                  <td className="px-4 py-3 text-zinc-600">
                    <div className="font-medium capitalize">{item.payment_gateway || '-'}</div>
                    <div className="text-xs text-zinc-500 capitalize">{item.payment_method || '-'}</div>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-1 rounded-full text-xs font-semibold ${
                      item.payment_status === 'paid'
                        ? 'bg-emerald-100 text-emerald-700'
                        : item.payment_status === 'pending'
                          ? 'bg-amber-100 text-amber-700'
                          : item.payment_status === 'failed'
                            ? 'bg-red-100 text-red-700'
                            : 'bg-zinc-100 text-zinc-600'
                    }`}>
                      {item.payment_status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-zinc-500">{String(item.created_at || '').split('T')[0]}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-2">
                      {item.payment_status === 'pending' && item.payment_gateway === 'manual' && (
                        <button
                          onClick={() => void handleApprove(item)}
                          className="text-xs font-semibold text-emerald-700 inline-flex items-center gap-1"
                        >
                          <CheckCircle className="w-3 h-3" /> Approve
                        </button>
                      )}
                      {item.payment_status === 'pending' && item.payment_gateway !== 'manual' && (
                        <span className="text-xs font-semibold text-amber-700">
                          Menunggu webhook gateway
                        </span>
                      )}
                      {item.payment_status === 'paid' && (
                        <button
                          onClick={() => void handleRefund(item)}
                          className="text-xs font-semibold text-red-600 inline-flex items-center gap-1"
                        >
                          <RotateCcw className="w-3 h-3" /> Refund
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
