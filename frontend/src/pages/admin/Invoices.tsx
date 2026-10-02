import { useCallback, useEffect, useState } from 'react';
import { Receipt, RefreshCw, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import AdminPager from '@/components/admin/AdminPager';
import { getAdminInvoices, type AdminInvoiceItem, type AdminPagination } from '@/lib/hellomApi';

const STATUS_STYLE: Record<string, { label: string; className: string }> = {
  paid: { label: 'Lunas', className: 'bg-green-100 text-green-800' },
  issued: { label: 'Diterbitkan', className: 'bg-amber-100 text-amber-800' },
  draft: { label: 'Draf', className: 'bg-zinc-100 text-zinc-700' },
  failed: { label: 'Gagal', className: 'bg-red-100 text-red-800' },
  expired: { label: 'Kedaluwarsa', className: 'bg-zinc-100 text-zinc-600' },
};

const rupiah = (value: number) => `Rp ${Number(value || 0).toLocaleString('id-ID')}`;
const formatDate = (value: string | null) => (value ? new Date(value).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

/** Super admin: every subscription invoice across organizations. */
export default function Invoices() {
  const [items, setItems] = useState<AdminInvoiceItem[]>([]);
  const [pagination, setPagination] = useState<AdminPagination | null>(null);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => { setSearch(searchInput.trim()); setPage(1); }, 300);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await getAdminInvoices({ search: search || undefined, status: status || undefined, page, limit: 30 });
      setItems(result.items || []);
      setPagination(result.pagination ?? null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat invoice.');
    } finally {
      setLoading(false);
    }
  }, [page, search, status]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-zinc-900"><Receipt className="h-6 w-6" /> Invoice</h1>
        <p className="mt-1 text-zinc-600">Invoice langganan aplikasi dari semua organisasi.</p>
      </div>

      <div className="flex flex-col gap-3 sm:flex-row">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
          <input
            type="search"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Cari nomor invoice atau nama organisasi…"
            className="w-full rounded-lg border border-zinc-200 py-2 pl-10 pr-4 outline-none focus:ring-2 focus:ring-yellow-400"
          />
        </div>
        <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="rounded-lg border border-zinc-200 px-4 py-2 outline-none focus:ring-2 focus:ring-yellow-400">
          <option value="">Semua status</option>
          {Object.entries(STATUS_STYLE).map(([value, { label }]) => <option key={value} value={value}>{label}</option>)}
        </select>
        <button onClick={() => void load()} className="rounded-lg bg-zinc-100 px-4 py-2 hover:bg-zinc-200" title="Muat ulang">
          <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} />
        </button>
      </div>

      {error && <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-zinc-50">
              <tr>
                {['Nomor', 'Organisasi', 'Status', 'Total', 'Metode', 'Terbit', 'Dibayar'].map((label) => (
                  <th key={label} className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{label}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-200">
              {loading && items.length === 0 ? (
                <tr><td colSpan={7} className="px-4 py-10 text-center text-zinc-500">Memuat invoice…</td></tr>
              ) : items.length === 0 ? (
                <tr><td colSpan={7} className="px-4 py-12 text-center text-zinc-500">{search || status ? 'Tidak ada invoice yang cocok.' : 'Belum ada invoice.'}</td></tr>
              ) : items.map((invoice) => {
                const style = STATUS_STYLE[invoice.status] ?? { label: invoice.status, className: 'bg-zinc-100 text-zinc-700' };
                return (
                  <tr key={invoice.id} className="hover:bg-zinc-50">
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-zinc-800">{invoice.invoice_number}</td>
                    <td className="px-4 py-3 text-zinc-700">{invoice.organization?.name ?? '—'}</td>
                    <td className="px-4 py-3"><span className={cn('inline-flex rounded-full px-2 py-1 text-xs font-medium', style.className)}>{style.label}</span></td>
                    <td className="whitespace-nowrap px-4 py-3 font-medium text-zinc-900">{rupiah(invoice.total)}</td>
                    <td className="px-4 py-3 text-zinc-600">{String(invoice.metadata?.payment_method ?? '—').replace(/_/g, ' ')}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-zinc-600">{formatDate(invoice.issued_at)}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-zinc-600">{formatDate(invoice.paid_at)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <AdminPager pagination={pagination} loading={loading} unit="invoice" onPage={setPage} />
      </div>
    </div>
  );
}
