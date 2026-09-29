import { useCallback, useEffect, useState } from 'react';
import { BadgeCheck, ExternalLink, RefreshCw, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  disableAdminLandingProduct,
  getAdminLandingProducts,
  getAdminLandingReports,
  getAdminLandingSellers,
  suspendAdminLandingSeller,
  updateAdminLandingReport,
  updateSellerFinanceSeller,
} from '@/lib/hellomApi';
import type { AdminLandingProduct, AdminReport, AdminSeller } from '@/lib/hellomApi';

// Super admin › Moderasi Toko (Hellom Page): reports, sellers (suspend, hold balance), products.
type Tab = 'reports' | 'sellers' | 'products';
const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const dateTime = (iso: string | null) => (iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-');

export default function LandingModeration() {
  const [tab, setTab] = useState<Tab>('reports');
  const [sellerFilter, setSellerFilter] = useState<number | null>(null);
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-zinc-900">Moderasi Toko</h1>
        <p className="text-sm text-zinc-500">Laporan pengunjung, penjual, dan produk Hellom Page.</p>
      </div>
      <div className="flex gap-1 overflow-x-auto border-b border-zinc-200">
        {([['reports', 'Laporan'], ['sellers', 'Penjual'], ['products', 'Produk']] as const).map(([key, label]) => (
          <button key={key} type="button" onClick={() => setTab(key)} className={cn('min-h-11 shrink-0 border-b-2 px-4 text-sm font-semibold', tab === key ? 'border-zinc-900 text-zinc-900' : 'border-transparent text-zinc-500')}>{label}</button>
        ))}
      </div>
      {tab === 'reports' && <ReportsTab />}
      {tab === 'sellers' && <SellersTab onProducts={(id) => { setSellerFilter(id); setTab('products'); }} />}
      {tab === 'products' && <ProductsTab organizationId={sellerFilter} onClearFilter={() => setSellerFilter(null)} />}
    </div>
  );
}

function Pager({ page, onPage }: { page: { current: number; last: number }; onPage: (p: number) => void }) {
  if (page.last <= 1) return null;
  return (
    <div className="flex items-center justify-end gap-2 text-sm">
      <button type="button" disabled={page.current <= 1} onClick={() => onPage(page.current - 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">‹</button>
      <span>{page.current} / {page.last}</span>
      <button type="button" disabled={page.current >= page.last} onClick={() => onPage(page.current + 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">›</button>
    </div>
  );
}

function ReportsTab() {
  const [status, setStatus] = useState('open');
  const [rows, setRows] = useState<AdminReport[]>([]);
  const [page, setPage] = useState({ current: 1, last: 1 });
  const [error, setError] = useState<string | null>(null);

  const load = useCallback((p = 1) => {
    getAdminLandingReports(status, p).then((r) => { setRows(r.data); setPage({ current: r.current_page, last: r.last_page }); setError(null); })
      .catch((err) => setError(err instanceof Error ? err.message : 'Gagal memuat'));
  }, [status]);
  useEffect(() => { load(1); }, [load]);

  const act = async (fn: () => Promise<unknown>) => {
    try {
      await fn();
      load(page.current);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Aksi gagal');
    }
  };
  const suspend = (r: AdminReport) => {
    if (!r.organization) return;
    const reason = window.prompt(`Alasan menonaktifkan toko ${r.organization.name}:`, 'Laporan: ' + r.reason_label);
    if (reason) void act(() => suspendAdminLandingSeller(r.organization!.id, true, reason));
  };
  const disable = (r: AdminReport) => {
    if (!r.product) return;
    const reason = window.prompt(`Alasan menonaktifkan produk ${r.product.name}:`, r.reason_label);
    if (reason) void act(() => disableAdminLandingProduct(r.product!.id, true, reason));
  };
  const close = (r: AdminReport, next: 'resolved' | 'dismissed' | 'reviewing') => {
    const note = next === 'reviewing' ? undefined : window.prompt('Catatan (opsional):') ?? undefined;
    void act(() => updateAdminLandingReport(r.id, next, note));
  };

  return (
    <div className="space-y-3">
      <select value={status} onChange={(e) => setStatus(e.target.value)} className="min-h-10 rounded-xl border border-zinc-200 bg-white px-3 text-sm">
        <option value="open">Baru</option>
        <option value="reviewing">Ditinjau</option>
        <option value="resolved">Selesai</option>
        <option value="dismissed">Diabaikan</option>
        <option value="all">Semua</option>
      </select>
      {error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}
      {rows.length === 0 && <p className="rounded-2xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500">Tidak ada laporan.</p>}
      <ul className="space-y-2">
        {rows.map((r) => (
          <li key={r.id} className="rounded-2xl border border-zinc-200 bg-white p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="font-semibold">{r.reason_label}</p>
                <p className="text-zinc-500">
                  {r.organization ? <>Toko <a className="underline" href={`/${r.organization.slug}`} target="_blank" rel="noopener noreferrer">{r.organization.name}</a>{r.organization.suspended && ' (nonaktif)'}</> : 'Toko tidak diketahui'}
                  {r.product && <> · produk {r.product.name}{r.product.disabled && ' (nonaktif)'}</>}
                </p>
                <p className="text-xs text-zinc-400">{dateTime(r.created_at)}{r.reporter_email ? ` · dari ${r.reporter_email}` : ''}</p>
              </div>
              <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-semibold">{r.status}</span>
            </div>
            {r.description && <p className="mt-2 whitespace-pre-wrap rounded-xl bg-zinc-50 p-3 text-zinc-700">{r.description}</p>}
            {r.page_url && <a href={r.page_url} target="_blank" rel="noopener noreferrer" className="mt-1 inline-flex items-center gap-1 text-xs text-zinc-500 underline"><ExternalLink className="h-3 w-3" /> {r.page_url}</a>}
            {r.resolution_note && <p className="mt-1 text-xs text-zinc-500">Catatan: {r.resolution_note}</p>}
            <div className="mt-3 flex flex-wrap gap-2">
              {r.status === 'open' && <button type="button" onClick={() => close(r, 'reviewing')} className="rounded-lg border px-3 py-1.5 text-xs font-semibold">Tinjau</button>}
              {r.organization && !r.organization.suspended && <button type="button" onClick={() => suspend(r)} className="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700">Nonaktifkan toko</button>}
              {r.product && !r.product.disabled && <button type="button" onClick={() => disable(r)} className="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700">Nonaktifkan produk</button>}
              {r.status !== 'resolved' && <button type="button" onClick={() => close(r, 'resolved')} className="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white">Selesai</button>}
              {r.status !== 'dismissed' && r.status !== 'resolved' && <button type="button" onClick={() => close(r, 'dismissed')} className="rounded-lg border px-3 py-1.5 text-xs font-semibold text-zinc-600">Abaikan</button>}
            </div>
          </li>
        ))}
      </ul>
      <Pager page={page} onPage={load} />
    </div>
  );
}

function SellersTab({ onProducts }: { onProducts: (organizationId: number) => void }) {
  const [filter, setFilter] = useState('all');
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const [rows, setRows] = useState<AdminSeller[]>([]);
  const [page, setPage] = useState({ current: 1, last: 1 });
  const [error, setError] = useState<string | null>(null);

  const load = useCallback((p = 1) => {
    getAdminLandingSellers({ filter, q: query || undefined, page: p }).then((r) => { setRows(r.data); setPage({ current: r.current_page, last: r.last_page }); setError(null); })
      .catch((err) => setError(err instanceof Error ? err.message : 'Gagal memuat'));
  }, [filter, query]);
  useEffect(() => { load(1); }, [load]);

  const act = async (fn: () => Promise<unknown>) => {
    try {
      await fn();
      load(page.current);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Aksi gagal');
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        <select value={filter} onChange={(e) => setFilter(e.target.value)} className="min-h-10 rounded-xl border border-zinc-200 bg-white px-3 text-sm">
          <option value="all">Semua penjual</option>
          <option value="reported">Ada laporan</option>
          <option value="suspended">Toko nonaktif</option>
          <option value="frozen">Saldo ditahan</option>
        </select>
        <form className="relative" onSubmit={(e) => { e.preventDefault(); setQuery(q.trim()); }}>
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nama / slug" className="min-h-10 rounded-xl border border-zinc-200 pl-9 pr-3 text-sm" />
        </form>
        <button type="button" onClick={() => load(page.current)} className="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 text-sm"><RefreshCw className="h-4 w-4" /> Muat ulang</button>
      </div>
      {error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}
      <div className="overflow-x-auto rounded-2xl border border-zinc-200 bg-white">
        <table className="w-full min-w-[820px] text-sm">
          <thead className="bg-zinc-50 text-left text-xs uppercase text-zinc-500">
            <tr><th className="px-4 py-3">Penjual</th><th className="px-4 py-3">Saldo</th><th className="px-4 py-3">Aktivitas</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Aksi</th></tr>
          </thead>
          <tbody className="divide-y divide-zinc-100">
            {rows.length === 0 && <tr><td colSpan={5} className="px-4 py-8 text-center text-zinc-500">Tidak ada penjual.</td></tr>}
            {rows.map((s) => (
              <tr key={s.id} className="align-top">
                <td className="px-4 py-3">
                  <a href={`/${s.slug}`} target="_blank" rel="noopener noreferrer" className="font-semibold underline">{s.name}</a>
                  {s.verified && <span className="ml-1 inline-flex items-center gap-0.5 text-xs text-emerald-700"><BadgeCheck className="h-3.5 w-3.5" /> Terverifikasi</span>}
                  <p className="text-xs text-zinc-400">/{s.slug}</p>
                </td>
                <td className="px-4 py-3">{rupiah(s.balance_available)}<p className="text-xs text-zinc-400">tertahan {rupiah(s.balance_pending)}</p></td>
                <td className="px-4 py-3">{s.products} produk · {s.paid_orders} terjual{s.open_reports > 0 && <p className="text-xs font-semibold text-rose-700">{s.open_reports} laporan baru</p>}</td>
                <td className="px-4 py-3">
                  {s.suspended ? <span className="text-rose-700">Toko nonaktif{s.suspended_reason ? `: ${s.suspended_reason}` : ''}</span> : 'Aktif'}
                  {s.balance_frozen && <p className="text-xs font-semibold text-amber-700">Saldo ditahan</p>}
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap justify-end gap-2">
                    <button type="button" onClick={() => onProducts(s.id)} className="rounded-lg border px-3 py-1.5 text-xs font-semibold">Produk</button>
                    <button
                      type="button"
                      onClick={() => {
                        if (s.suspended) { void act(() => suspendAdminLandingSeller(s.id, false)); return; }
                        const reason = window.prompt(`Alasan menonaktifkan toko ${s.name}:`);
                        if (reason) void act(() => suspendAdminLandingSeller(s.id, true, reason));
                      }}
                      className={cn('rounded-lg border px-3 py-1.5 text-xs font-semibold', s.suspended ? '' : 'border-rose-200 text-rose-700')}
                    >
                      {s.suspended ? 'Aktifkan toko' : 'Nonaktifkan toko'}
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        if (s.balance_frozen) { void act(() => updateSellerFinanceSeller(s.id, { is_frozen: false })); return; }
                        const reason = window.prompt(`Alasan menahan saldo ${s.name}:`);
                        if (reason) void act(() => updateSellerFinanceSeller(s.id, { is_frozen: true, frozen_reason: reason }));
                      }}
                      className={cn('rounded-lg border px-3 py-1.5 text-xs font-semibold', s.balance_frozen ? '' : 'border-amber-200 text-amber-800')}
                    >
                      {s.balance_frozen ? 'Lepas tahanan saldo' : 'Tahan saldo'}
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} onPage={load} />
    </div>
  );
}

function ProductsTab({ organizationId, onClearFilter }: { organizationId: number | null; onClearFilter: () => void }) {
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const [rows, setRows] = useState<AdminLandingProduct[]>([]);
  const [page, setPage] = useState({ current: 1, last: 1 });
  const [error, setError] = useState<string | null>(null);

  const load = useCallback((p = 1) => {
    getAdminLandingProducts({ organization_id: organizationId ?? undefined, q: query || undefined, page: p })
      .then((r) => { setRows(r.data); setPage({ current: r.current_page, last: r.last_page }); setError(null); })
      .catch((err) => setError(err instanceof Error ? err.message : 'Gagal memuat'));
  }, [organizationId, query]);
  useEffect(() => { load(1); }, [load]);

  const toggle = async (p: AdminLandingProduct) => {
    const reason = p.disabled ? undefined : window.prompt(`Alasan menonaktifkan ${p.name}:`);
    if (!p.disabled && !reason) return;
    try {
      await disableAdminLandingProduct(p.id, !p.disabled, reason ?? undefined);
      load(page.current);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Aksi gagal');
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <form className="relative" onSubmit={(e) => { e.preventDefault(); setQuery(q.trim()); }}>
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nama produk" className="min-h-10 rounded-xl border border-zinc-200 pl-9 pr-3 text-sm" />
        </form>
        {organizationId && <button type="button" onClick={onClearFilter} className="rounded-full bg-zinc-100 px-3 py-1.5 text-xs font-semibold">Penjual #{organizationId} ✕</button>}
      </div>
      {error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}
      <ul className="divide-y divide-zinc-100 rounded-2xl border border-zinc-200 bg-white">
        {rows.length === 0 && <li className="p-8 text-center text-sm text-zinc-500">Tidak ada produk.</li>}
        {rows.map((p) => (
          <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
            <div className="min-w-0">
              <a href={`/beli/${p.public_id}`} target="_blank" rel="noopener noreferrer" className="font-semibold underline">{p.name}</a>
              <p className="text-zinc-500">{p.organization?.name} · {p.type_label} · {rupiah(p.price)} · terjual {p.sold_count}{!p.is_active && ' · disembunyikan penjual'}</p>
              {p.disabled && <p className="text-xs text-rose-700">Dinonaktifkan: {p.disabled_reason}</p>}
            </div>
            <button type="button" onClick={() => void toggle(p)} className={cn('rounded-lg border px-3 py-1.5 text-xs font-semibold', p.disabled ? '' : 'border-rose-200 text-rose-700')}>
              {p.disabled ? 'Aktifkan' : 'Nonaktifkan'}
            </button>
          </li>
        ))}
      </ul>
      <Pager page={page} onPage={load} />
    </div>
  );
}
