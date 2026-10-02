import { useCallback, useEffect, useState } from 'react';
import { RefreshCw, ScrollText } from 'lucide-react';
import { cn } from '@/lib/utils';
import AdminPager from '@/components/admin/AdminPager';
import { getAdminAuditLogs, type AdminAuditLogItem, type AdminPagination } from '@/lib/hellomApi';

// Prefixes of the actions written by the backend (AuditLog::record / SuperAdminController::audit).
const ACTION_GROUPS = [
  { value: '', label: 'Semua aksi' },
  { value: 'user.', label: 'Pengguna' },
  { value: 'organization.', label: 'Organisasi' },
  { value: 'entitlement.', label: 'Akses aplikasi' },
  { value: 'plan.', label: 'Paket' },
  { value: 'billing.', label: 'Pembayaran manual' },
  { value: 'seller_finance.', label: 'Keuangan penjual' },
  { value: 'landing.', label: 'Moderasi & refund toko' },
  { value: 'admin.', label: 'Pengaturan admin' },
  { value: 'promo_campaign.', label: 'Promo' },
];

const formatDateTime = (value: string) => new Date(value).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

function Changes({ item }: { item: AdminAuditLogItem }) {
  const before = item.old_values ?? {};
  const after = item.new_values ?? {};
  const keys = Array.from(new Set([...Object.keys(before), ...Object.keys(after)]));
  if (keys.length === 0) return <span className="text-zinc-400">—</span>;

  const show = (value: unknown) => (value === null || value === undefined || value === '' ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value));

  return (
    <ul className="space-y-0.5">
      {keys.slice(0, 6).map((key) => (
        <li key={key} className="break-all">
          <span className="text-zinc-500">{key}:</span>{' '}
          {key in before && <><span className="text-red-600 line-through">{show(before[key])}</span>{' → '}</>}
          <span className="text-emerald-700">{show(after[key])}</span>
        </li>
      ))}
      {keys.length > 6 && <li className="text-zinc-400">+{keys.length - 6} lainnya</li>}
    </ul>
  );
}

/** Super admin: who changed what (users, organizations, access, plans, payments, settings). */
export default function AuditLog() {
  const [items, setItems] = useState<AdminAuditLogItem[]>([]);
  const [pagination, setPagination] = useState<AdminPagination | null>(null);
  const [page, setPage] = useState(1);
  const [action, setAction] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await getAdminAuditLogs({ action: action || undefined, page, limit: 30 });
      setItems(result.items || []);
      setPagination(result.pagination ?? null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat log audit.');
    } finally {
      setLoading(false);
    }
  }, [action, page]);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-zinc-900"><ScrollText className="h-6 w-6" /> Log Audit</h1>
          <p className="mt-1 text-zinc-600">Jejak perubahan penting oleh admin: siapa, kapan, apa yang berubah.</p>
        </div>
        <div className="flex gap-2">
          <select value={action} onChange={(e) => { setAction(e.target.value); setPage(1); }} className="rounded-lg border border-zinc-200 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-yellow-400">
            {ACTION_GROUPS.map((group) => <option key={group.value} value={group.value}>{group.label}</option>)}
          </select>
          <button onClick={() => void load()} className="rounded-lg bg-zinc-100 px-3 py-2 hover:bg-zinc-200" title="Muat ulang">
            <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} />
          </button>
        </div>
      </div>

      {error && <div className="rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      <div className="overflow-hidden rounded-xl border border-zinc-200 bg-white">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-zinc-50">
              <tr>
                {['Waktu', 'Admin', 'Aksi', 'Organisasi', 'Perubahan'].map((label) => (
                  <th key={label} className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500">{label}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-200 align-top">
              {loading && items.length === 0 ? (
                <tr><td colSpan={5} className="px-4 py-10 text-center text-zinc-500">Memuat log…</td></tr>
              ) : items.length === 0 ? (
                <tr><td colSpan={5} className="px-4 py-12 text-center text-zinc-500">Belum ada catatan untuk filter ini.</td></tr>
              ) : items.map((item) => (
                <tr key={item.id} className="hover:bg-zinc-50">
                  <td className="whitespace-nowrap px-4 py-3 text-zinc-600">{formatDateTime(item.created_at)}</td>
                  <td className="px-4 py-3">
                    <p className="font-medium text-zinc-900">{item.user?.name ?? 'Sistem'}</p>
                    {item.user?.email && <p className="text-xs text-zinc-500">{item.user.email}</p>}
                  </td>
                  <td className="px-4 py-3">
                    <code className="rounded bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-800">{item.action}</code>
                    {item.entity_type && <p className="mt-1 text-xs text-zinc-500">{item.entity_type} #{item.entity_id}</p>}
                  </td>
                  <td className="px-4 py-3 text-zinc-700">{item.organization?.name ?? '—'}</td>
                  <td className="max-w-md px-4 py-3 text-xs"><Changes item={item} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <AdminPager pagination={pagination} loading={loading} unit="catatan" onPage={setPage} />
      </div>
    </div>
  );
}
