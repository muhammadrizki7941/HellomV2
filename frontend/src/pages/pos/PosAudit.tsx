import { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertTriangle, ChevronDown, KeyRound, Loader2, Lock, Search, ShieldAlert, ShieldCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  ApiError,
  changePosOwnerPin,
  getPosActivity,
  getPosAnomalies,
  getPosLogIntegrity,
  getPosOwnerPinStatus,
  requestPosOwnerPinReset,
  unlockPosAudit,
} from '@/lib/hellomApi';
import type { PosActivityFilters, PosActivityLog, PosActivityPage, PosAnomalyReport, PosLogIntegrity, PosOwnerPinStatus } from '@/lib/hellomApi';
import { PinField } from '@/components/pos/OwnerPin';

// POS › Log Audit (owner only). Everything behind a fresh owner-PIN unlock (15 minutes, kept in
// memory only). Backend: PosActivityController + App\Services\Pos\Audit. Action keys/labels come
// from the server (App\Support\Pos\ActivityActions).

type Tab = 'activity' | 'alerts' | 'security';

const ROLE_LABELS: Record<string, string> = {
  owner: 'Pemilik', admin: 'Admin', cashier: 'Kasir', pos_admin: 'Admin POS', system: 'Sistem', super_admin: 'Super admin', member: 'Anggota', user: 'Pengguna',
};
const FIELD_LABELS: Record<string, string> = {
  status: 'Status', payment_status: 'Status bayar', payment_method: 'Metode bayar', subtotal_amount: 'Subtotal', discount_amount: 'Diskon',
  points_discount_amount: 'Diskon poin', final_amount: 'Total', payment_amount: 'Dibayar', payment_change: 'Kembalian', refund_amount: 'Refund',
  table_label: 'Meja', table_bill_id: 'Tagihan meja', items: 'Item', tendered: 'Uang diterima', print_no: 'Cetakan ke-', kind: 'Jenis struk',
  opening_cash: 'Kas awal', counted_cash: 'Uang dihitung', expected_cash: 'Seharusnya', difference_cash: 'Selisih', cash_sales: 'Penjualan tunai',
  transactions: 'Transaksi', blind: 'Tutup kas buta', name: 'Nama', price: 'Harga', stock: 'Stok', track_stock: 'Pakai stok', is_available: 'Tersedia',
  category_id: 'Kategori', role: 'Peran', employment_status: 'Status kerja', permissions: 'Hak akses', linked_user_id: 'Akun login', total: 'Total',
  change: 'Kembalian', order_numbers: 'Pesanan', order_ids: 'ID pesanan', sent_to: 'Dikirim ke', staff_id: 'ID staf', started_at: 'Dibuka',
};
const MONEY_KEYS = /amount|cash|price|total|tendered|change|sales/;
const VALUE_LABELS: Record<string, string> = {
  paid: 'Lunas', unpaid: 'Belum bayar', refunded: 'Direfund', cancelled: 'Dibatalkan', new: 'Baru', accepted: 'Dikonfirmasi', preparing: 'Diproses',
  prepared: 'Siap', completed: 'Selesai', draft: 'Draf (belum lunas)', final: 'Final', cash: 'Tunai', qris: 'QRIS', transfer: 'Transfer',
};

const rupiah = (v: number) => `Rp ${Math.round(v).toLocaleString('id-ID')}`;
const today = () => new Date().toISOString().slice(0, 10);
const daysAgo = (n: number) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);
const timeLabel = (iso: string) => {
  const d = new Date(iso);
  const ms = String(d.getMilliseconds()).padStart(3, '0');
  return `${d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })} ${d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}.${ms}`;
};

function formatValue(key: string, value: unknown): string {
  if (value === null || value === undefined || value === '') return '–';
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
  if (typeof value === 'number') return MONEY_KEYS.test(key) ? rupiah(value) : value.toLocaleString('id-ID');
  if (typeof value === 'string') return VALUE_LABELS[value] ?? value;
  if (Array.isArray(value)) {
    if (key === 'items') {
      return (value as Array<Record<string, unknown>>).map((i) => `${i.qty}× ${i.name} (${rupiah(Number(i.line_total ?? 0))})`).join(', ') || '–';
    }
    return value.map((v) => formatValue(key, v)).join(', ');
  }
  if (typeof value === 'object') {
    if (key === 'permissions') {
      const on = Object.entries(value as Record<string, boolean>).filter(([, v]) => v).map(([k]) => k);
      return on.length ? on.join(', ') : 'tidak ada';
    }
    return Object.entries(value as Record<string, unknown>).map(([k, v]) => `${FIELD_LABELS[k] ?? k}: ${formatValue(k, v)}`).join('; ');
  }
  return String(value);
}

function SeverityBadge({ severity }: { severity: string }) {
  const map: Record<string, [string, string]> = {
    critical: ['Kritis', 'bg-red-100 text-red-800 border-red-200'],
    warning: ['Perlu dicek', 'bg-amber-100 text-amber-800 border-amber-200'],
    info: ['Info', 'bg-gray-100 text-gray-700 border-gray-200'],
  };
  const [label, cls] = map[severity] ?? map.info;
  return <span className={cn('inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-semibold', cls)}>{label}</span>;
}

export default function PosAudit() {
  const [status, setStatus] = useState<PosOwnerPinStatus | null>(null);
  const [statusError, setStatusError] = useState<string | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [expiresAt, setExpiresAt] = useState<number | null>(null);
  const [tab, setTab] = useState<Tab>('activity');

  useEffect(() => {
    getPosOwnerPinStatus().then(setStatus).catch((err) => setStatusError(err instanceof Error ? err.message : 'Gagal memuat.'));
  }, []);

  // Lock again when the unlock expires.
  useEffect(() => {
    if (!expiresAt) return undefined;
    const timer = window.setTimeout(() => { setToken(null); setExpiresAt(null); }, Math.max(0, expiresAt - Date.now()));
    return () => window.clearTimeout(timer);
  }, [expiresAt]);

  const lock = useCallback(() => { setToken(null); setExpiresAt(null); }, []);
  const onApiError = useCallback((err: unknown) => {
    if (err instanceof ApiError && err.status === 401) lock();
  }, [lock]);

  if (statusError) return <div className="p-6 text-sm text-red-700">{statusError}</div>;
  if (!status) return <div className="flex justify-center p-10"><Loader2 className="h-6 w-6 animate-spin text-gray-400" /></div>;
  if (!status.is_owner) {
    return (
      <div className="mx-auto max-w-md p-6 text-center">
        <Lock className="mx-auto h-10 w-10 text-gray-400" />
        <h1 className="mt-3 text-xl font-bold text-gray-900">Khusus pemilik toko</h1>
        <p className="mt-1 text-sm text-gray-600">Log audit hanya bisa dibuka oleh pemilik toko dengan PIN pemilik.</p>
      </div>
    );
  }
  if (!token) {
    return <UnlockScreen status={status} onUnlocked={(t, exp) => { setToken(t); setExpiresAt(new Date(exp).getTime()); }} />;
  }

  return (
    <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6" data-audit-unlocked>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Log Audit</h1>
          <p className="text-sm text-gray-600">Jejak setiap aksi penting di kasir. Catatan tidak bisa diubah atau dihapus, termasuk oleh admin.</p>
        </div>
        <button type="button" onClick={lock} className="flex min-h-10 items-center gap-2 rounded-xl border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
          <Lock className="h-4 w-4" /> Kunci
        </button>
      </div>

      <div role="tablist" className="flex gap-1 overflow-x-auto rounded-2xl bg-gray-100 p-1">
        {([['activity', 'Aktivitas'], ['alerts', 'Peringatan kecurangan'], ['security', 'PIN & keamanan']] as Array<[Tab, string]>).map(([key, label]) => (
          <button key={key} role="tab" aria-selected={tab === key} type="button" onClick={() => setTab(key)}
            className={cn('min-h-10 flex-1 whitespace-nowrap rounded-xl px-3 text-sm font-semibold', tab === key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900')}>
            {label}
          </button>
        ))}
      </div>

      {tab === 'activity' && <ActivityTab token={token} onApiError={onApiError} />}
      {tab === 'alerts' && <AlertsTab token={token} onApiError={onApiError} />}
      {tab === 'security' && <SecurityTab token={token} status={status} onApiError={onApiError} />}
    </div>
  );
}

function UnlockScreen({ status, onUnlocked }: { status: PosOwnerPinStatus; onUnlocked: (token: string, expiresAt: string) => void }) {
  const [pin, setPin] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [resetNote, setResetNote] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (pin.length !== 6) { setError('PIN pemilik 6 angka.'); return; }
    setBusy(true);
    setError(null);
    try {
      const result = await unlockPosAudit(pin);
      onUnlocked(result.token, result.expires_at);
    } catch (err) {
      setError(err instanceof ApiError ? (err.fieldErrors.pin?.[0] ?? err.message) : 'Belum bisa dibuka. Coba lagi.');
      setPin('');
    } finally {
      setBusy(false);
    }
  };

  const forgot = async () => {
    setResetNote(null);
    try {
      const { sent_to: sentTo } = await requestPosOwnerPinReset();
      setResetNote(`Link untuk membuat PIN baru sudah dikirim ke ${sentTo}. Berlaku 30 menit.`);
    } catch (err) {
      setResetNote(err instanceof Error ? err.message : 'Link reset belum terkirim.');
    }
  };

  return (
    <div className="mx-auto max-w-sm p-6">
      <form onSubmit={(e) => void submit(e)} noValidate className="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm" data-audit-unlock>
        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-gray-900 text-white"><ShieldCheck className="h-6 w-6" /></span>
        <h1 className="mt-3 text-xl font-bold text-gray-900">Log Audit</h1>
        <p className="mt-1 text-sm text-gray-600">Masukkan PIN pemilik untuk membuka log. Terkunci lagi otomatis setelah 15 menit.</p>
        {!status.pin_set ? (
          <p className="mt-4 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">Kamu belum membuat PIN. Muat ulang halaman POS untuk membuatnya.</p>
        ) : (
          <>
            <div className="mt-4">
              <PinField label="PIN pemilik" name="pin" value={pin} onChange={setPin} error={error} autoFocus />
            </div>
            <button type="submit" disabled={busy} className="mt-4 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gray-900 text-base font-bold text-white disabled:opacity-60">
              {busy && <Loader2 className="h-5 w-5 animate-spin" />} Buka log
            </button>
            <button type="button" onClick={() => void forgot()} className="mt-3 w-full text-center text-sm font-medium text-gray-600 underline-offset-2 hover:underline">
              Lupa PIN?
            </button>
            {resetNote && <p role="status" className="mt-3 rounded-xl bg-gray-50 px-3 py-2 text-sm text-gray-700">{resetNote}</p>}
          </>
        )}
      </form>
    </div>
  );
}

function ActivityTab({ token, onApiError }: { token: string; onApiError: (err: unknown) => void }) {
  const [filters, setFilters] = useState<PosActivityFilters>({ from: daysAgo(6), to: today(), page: 1 });
  const [search, setSearch] = useState('');
  const [data, setData] = useState<PosActivityPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<number | null>(null);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    getPosActivity(token, filters)
      .then((page) => { if (alive) { setData(page); setError(null); } })
      .catch((err) => { if (alive) { setError(err instanceof Error ? err.message : 'Gagal memuat log.'); onApiError(err); } })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [token, filters, onApiError]);

  const set = (patch: Partial<PosActivityFilters>) => setFilters((f) => ({ ...f, ...patch, page: patch.page ?? 1 }));
  const select = 'min-h-10 rounded-xl border border-gray-300 bg-white px-3 text-sm text-gray-800';

  return (
    <section className="space-y-3">
      <div className="grid grid-cols-2 gap-2 rounded-2xl border border-gray-200 bg-white p-3 sm:grid-cols-3 lg:grid-cols-6">
        <label className="text-xs text-gray-500">Dari<input type="date" value={filters.from ?? ''} onChange={(e) => set({ from: e.target.value })} className={cn(select, 'mt-1 w-full')} /></label>
        <label className="text-xs text-gray-500">Sampai<input type="date" value={filters.to ?? ''} onChange={(e) => set({ to: e.target.value })} className={cn(select, 'mt-1 w-full')} /></label>
        <label className="text-xs text-gray-500">Aksi
          <select value={filters.action ?? ''} onChange={(e) => set({ action: e.target.value || undefined })} className={cn(select, 'mt-1 w-full')} data-filter-action>
            <option value="">Semua aksi</option>
            {data?.actions.map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500">Tingkat
          <select value={filters.severity ?? ''} onChange={(e) => set({ severity: e.target.value || undefined })} className={cn(select, 'mt-1 w-full')}>
            <option value="">Semua</option><option value="critical">Kritis</option><option value="warning">Perlu dicek</option><option value="info">Info</option>
          </select>
        </label>
        <label className="text-xs text-gray-500">Orang
          <select value={filters.actor ?? ''} onChange={(e) => set({ actor: e.target.value || undefined })} className={cn(select, 'mt-1 w-full')}>
            <option value="">Semua orang</option>
            {data?.actors.map((a) => <option key={a.key} value={a.key}>{a.name}{a.role ? ` (${ROLE_LABELS[a.role] ?? a.role})` : ''}</option>)}
          </select>
        </label>
        {(data?.outlets.length ?? 0) > 1 ? (
          <label className="text-xs text-gray-500">Outlet
            <select value={filters.outlet_id ?? ''} onChange={(e) => set({ outlet_id: e.target.value || undefined })} className={cn(select, 'mt-1 w-full')}>
              <option value="">Semua outlet</option>
              {data?.outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </select>
          </label>
        ) : <span className="hidden lg:block" />}
        <form className="col-span-2 flex gap-2 sm:col-span-3 lg:col-span-6" onSubmit={(e) => { e.preventDefault(); set({ q: search.trim() || undefined }); }}>
          <div className="flex min-h-10 flex-1 items-center gap-2 rounded-xl border border-gray-300 bg-white px-3">
            <Search className="h-4 w-4 text-gray-400" />
            <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari no. pesanan, produk, atau staf" className="min-w-0 flex-1 bg-transparent text-sm outline-none" />
          </div>
          <button type="submit" className="min-h-10 rounded-xl bg-gray-900 px-4 text-sm font-semibold text-white">Cari</button>
        </form>
      </div>

      {error && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
      {loading && !data ? (
        <div className="flex justify-center p-10"><Loader2 className="h-6 w-6 animate-spin text-gray-400" /></div>
      ) : data && data.logs.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">Belum ada aktivitas untuk filter ini.</p>
      ) : (
        <ul className={cn('space-y-2', loading && 'opacity-60')} data-audit-list>
          {data?.logs.map((log) => (
            <LogRow key={log.id} log={log} open={open === log.id} onToggle={() => setOpen(open === log.id ? null : log.id)} />
          ))}
        </ul>
      )}

      {data && data.pagination.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-gray-600">
          <span>{data.pagination.total.toLocaleString('id-ID')} catatan</span>
          <div className="flex gap-2">
            <button type="button" disabled={data.pagination.page <= 1} onClick={() => set({ page: data.pagination.page - 1 })} className="min-h-10 rounded-xl border border-gray-300 bg-white px-3 disabled:opacity-40">Sebelumnya</button>
            <span className="self-center">{data.pagination.page} / {data.pagination.last_page}</span>
            <button type="button" disabled={data.pagination.page >= data.pagination.last_page} onClick={() => set({ page: data.pagination.page + 1 })} className="min-h-10 rounded-xl border border-gray-300 bg-white px-3 disabled:opacity-40">Berikutnya</button>
          </div>
        </div>
      )}
    </section>
  );
}

function LogRow({ log, open, onToggle }: { log: PosActivityLog; open: boolean; onToggle: () => void }) {
  const keys = useMemo(() => Array.from(new Set([...Object.keys(log.before ?? {}), ...Object.keys(log.after ?? {})])), [log]);
  return (
    <li className={cn('rounded-2xl border bg-white', log.severity === 'critical' ? 'border-red-200' : log.severity === 'warning' ? 'border-amber-200' : 'border-gray-200')} data-audit-row={log.action}>
      <button type="button" onClick={onToggle} aria-expanded={open} className="flex w-full items-start gap-3 p-3 text-left sm:p-4">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <SeverityBadge severity={log.severity} />
            <span className="font-semibold text-gray-900">{log.action_label}</span>
            {log.target.label && <span className="text-sm text-gray-600">{log.target.label}</span>}
          </div>
          <p className="mt-1 text-sm text-gray-600">
            {log.actor.name ?? 'Sistem'}{log.actor.role && ` · ${ROLE_LABELS[log.actor.role] ?? log.actor.role}`}
            {log.approver && <> · disetujui <strong className="text-gray-800">{log.approver.name}</strong> (PIN)</>}
            {log.outlet?.name && ` · ${log.outlet.name}`}
          </p>
          {log.reason && <p className="mt-1 text-sm text-gray-800">“{log.reason}”</p>}
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          <time className="text-xs tabular-nums text-gray-500" dateTime={log.occurred_at}>{timeLabel(log.occurred_at)}</time>
          <ChevronDown className={cn('h-4 w-4 text-gray-400 transition-transform', open && 'rotate-180')} />
        </div>
      </button>
      {open && (
        <div className="border-t border-gray-100 px-3 pb-3 pt-2 text-sm sm:px-4">
          {keys.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[420px] text-left">
                <thead><tr className="text-xs text-gray-500"><th className="py-1 pr-3 font-medium">Data</th><th className="py-1 pr-3 font-medium">Sebelum</th><th className="py-1 font-medium">Sesudah</th></tr></thead>
                <tbody>
                  {keys.map((key) => {
                    const before = log.before?.[key];
                    const after = log.after?.[key];
                    const inBoth = !!log.before && !!log.after && key in log.before && key in log.after;
                    const changed = inBoth && JSON.stringify(before) !== JSON.stringify(after);
                    return (
                      <tr key={key} className={cn('border-t border-gray-100 align-top', changed && 'bg-amber-50')}>
                        <td className="py-1.5 pr-3 text-gray-500">{FIELD_LABELS[key] ?? key}</td>
                        <td className="py-1.5 pr-3 text-gray-700">{log.before && key in log.before ? formatValue(key, before) : '–'}</td>
                        <td className="py-1.5 font-medium text-gray-900">{log.after && key in log.after ? formatValue(key, after) : log.after && log.before ? 'tetap' : '–'}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
          <dl className="mt-3 grid gap-x-4 gap-y-1 text-xs text-gray-500 sm:grid-cols-2">
            <div><dt className="inline">IP: </dt><dd className="inline text-gray-700">{log.ip_address ?? '–'}</dd></div>
            <div><dt className="inline">Perangkat: </dt><dd className="inline text-gray-700">{log.device_id ?? '–'}</dd></div>
            <div><dt className="inline">Sesi: </dt><dd className="inline text-gray-700">{log.session_ref ?? '–'}</dd></div>
            <div><dt className="inline">Sidik log: </dt><dd className="inline font-mono text-gray-700">{log.hash}…</dd></div>
            {log.user_agent && <div className="sm:col-span-2"><dt className="inline">Browser: </dt><dd className="inline break-all text-gray-700">{log.user_agent}</dd></div>}
          </dl>
        </div>
      )}
    </li>
  );
}

function AlertsTab({ token, onApiError }: { token: string; onApiError: (err: unknown) => void }) {
  const [range, setRange] = useState({ from: daysAgo(6), to: today() });
  const [report, setReport] = useState<PosAnomalyReport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    getPosAnomalies(token, range)
      .then((r) => { if (alive) { setReport(r); setError(null); } })
      .catch((err) => { if (alive) { setError(err instanceof Error ? err.message : 'Gagal memuat.'); onApiError(err); } })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [token, range, onApiError]);

  const pct = (v: number) => `${Math.round(v * 100)}%`;
  const flagged = report?.cashiers.filter((c) => c.flags.length > 0) ?? [];

  return (
    <section className="space-y-3" data-audit-alerts>
      <div className="flex flex-wrap items-end gap-2 rounded-2xl border border-gray-200 bg-white p-3">
        <label className="text-xs text-gray-500">Dari<input type="date" value={range.from} onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))} className="mt-1 block min-h-10 rounded-xl border border-gray-300 px-3 text-sm" /></label>
        <label className="text-xs text-gray-500">Sampai<input type="date" value={range.to} onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))} className="mt-1 block min-h-10 rounded-xl border border-gray-300 px-3 text-sm" /></label>
        {report && (
          <p className="text-xs text-gray-500 sm:ml-auto">
            Rata-rata toko: void {pct(report.average.voids)} · refund {pct(report.average.refunds)} · cetak ulang {pct(report.average.reprints)} dari transaksi
          </p>
        )}
      </div>

      {error && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
      {loading && !report ? (
        <div className="flex justify-center p-10"><Loader2 className="h-6 w-6 animate-spin text-gray-400" /></div>
      ) : report && (
        <>
          {flagged.length === 0 ? (
            <p className="flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
              <ShieldCheck className="h-5 w-5" /> Tidak ada pola mencurigakan di periode ini.
            </p>
          ) : (
            <ul className="space-y-2">
              {flagged.map((c) => (
                <li key={c.name + c.role} className="rounded-2xl border border-red-200 bg-white p-4" data-alert-cashier={c.name}>
                  <p className="flex items-center gap-2 font-semibold text-gray-900"><ShieldAlert className="h-5 w-5 text-red-600" />{c.name} <span className="text-sm font-normal text-gray-500">{ROLE_LABELS[c.role ?? ''] ?? c.role}</span></p>
                  <ul className="mt-2 space-y-1">
                    {c.flags.map((f) => (
                      <li key={f.type} className={cn('flex items-start gap-2 text-sm', f.severity === 'critical' ? 'text-red-700' : 'text-amber-800')}>
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />{f.message}
                      </li>
                    ))}
                  </ul>
                </li>
              ))}
            </ul>
          )}

          <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
            <table className="w-full min-w-[640px] text-left text-sm text-gray-800">
              <thead className="bg-gray-50 text-xs text-gray-500">
                <tr>
                  <th className="px-3 py-2 font-medium">Staf</th><th className="px-3 py-2 font-medium">Transaksi</th><th className="px-3 py-2 font-medium">Void</th>
                  <th className="px-3 py-2 font-medium">Refund</th><th className="px-3 py-2 font-medium">Cetak ulang</th><th className="px-3 py-2 font-medium">Kas kurang</th>
                  <th className="px-3 py-2 font-medium">PIN salah</th><th className="px-3 py-2 font-medium">Per hari</th>
                </tr>
              </thead>
              <tbody>
                {report.cashiers.length === 0 && <tr><td colSpan={8} className="px-3 py-6 text-center text-gray-500">Belum ada transaksi tercatat di periode ini.</td></tr>}
                {report.cashiers.map((c) => (
                  <tr key={c.name + c.role} className={cn('border-t border-gray-100', c.flags.length > 0 && 'bg-red-50/40')}>
                    <td className="px-3 py-2 font-medium text-gray-900">{c.name}</td>
                    <td className="px-3 py-2">{c.transactions}</td>
                    <td className="px-3 py-2">{c.voids} <span className="text-xs text-gray-500">({pct(c.rates.voids)})</span></td>
                    <td className="px-3 py-2">{c.refunds}</td>
                    <td className="px-3 py-2">{c.reprints}</td>
                    <td className={cn('px-3 py-2', c.cash_short > 0 && 'font-semibold text-red-700')}>{c.cash_short > 0 ? rupiah(c.cash_short) : '–'}</td>
                    <td className="px-3 py-2">{c.pin_failed || '–'}</td>
                    <td className="px-3 py-2 text-xs text-gray-600">{c.per_day.transactions} trx · {c.per_day.voids} void · {c.per_day.reprints} cetak ulang</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="text-xs text-gray-500">
            Ditandai kalau rasio void/refund/cetak ulang seorang staf jauh di atas rata-rata toko (2× rata-rata, minimal 5–10%), ada kekurangan kas saat tutup kas, atau PIN salah berulang.
            Tanda ini bahan pengecekan, bukan bukti kecurangan.
          </p>
        </>
      )}
    </section>
  );
}

function SecurityTab({ token, status, onApiError }: { token: string; status: PosOwnerPinStatus; onApiError: (err: unknown) => void }) {
  const [current, setCurrent] = useState('');
  const [pin, setPin] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<{ current?: string; pin?: string; confirm?: string }>({});
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);
  const [integrity, setIntegrity] = useState<PosLogIntegrity | null>(null);
  const [checking, setChecking] = useState(false);

  const change = async (e: React.FormEvent) => {
    e.preventDefault();
    const next: typeof errors = {};
    if (current.length !== 6) next.current = 'Masukkan PIN lama.';
    if (pin.length !== 6) next.pin = 'PIN baru harus 6 angka.';
    else if (confirm !== pin) next.confirm = 'Konfirmasi PIN belum sama.';
    setErrors(next);
    if (Object.keys(next).length) return;
    setBusy(true);
    setNote(null);
    try {
      await changePosOwnerPin(current, pin, confirm);
      setCurrent(''); setPin(''); setConfirm('');
      setNote('PIN diganti. Pemberitahuan dikirim ke email pemilik utama.');
    } catch (err) {
      const fields = err instanceof ApiError ? err.fieldErrors : {};
      setErrors({ current: fields.current_pin?.[0], pin: fields.pin?.[0] });
      if (!fields.current_pin && !fields.pin) setNote(err instanceof Error ? err.message : 'PIN belum diganti.');
    } finally {
      setBusy(false);
    }
  };

  const check = async () => {
    setChecking(true);
    try {
      setIntegrity(await getPosLogIntegrity(token));
    } catch (err) {
      onApiError(err);
    } finally {
      setChecking(false);
    }
  };

  return (
    <section className="grid gap-4 lg:grid-cols-2">
      <form onSubmit={(e) => void change(e)} noValidate className="rounded-2xl border border-gray-200 bg-white p-4">
        <h2 className="flex items-center gap-2 text-lg font-bold text-gray-900"><KeyRound className="h-5 w-5" /> Ganti PIN pemilik</h2>
        <div className="mt-3 space-y-3">
          <PinField label="PIN lama" name="current_pin" value={current} onChange={setCurrent} error={errors.current} />
          <PinField label="PIN baru" name="pin" value={pin} onChange={setPin} error={errors.pin} />
          <PinField label="Ulangi PIN baru" name="pin_confirmation" value={confirm} onChange={setConfirm} error={errors.confirm} />
        </div>
        {note && <p role="status" className="mt-3 rounded-xl bg-gray-50 px-3 py-2 text-sm text-gray-700">{note}</p>}
        <button type="submit" disabled={busy} className="mt-4 flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-gray-900 font-bold text-white disabled:opacity-60">
          {busy && <Loader2 className="h-5 w-5 animate-spin" />} Simpan PIN baru
        </button>
        {status.reset_email && <p className="mt-3 text-xs text-gray-500">Lupa PIN? Link reset hanya dikirim ke email pemilik utama ({status.reset_email}).</p>}
      </form>

      <div className="rounded-2xl border border-gray-200 bg-white p-4">
        <h2 className="flex items-center gap-2 text-lg font-bold text-gray-900"><ShieldCheck className="h-5 w-5" /> Keutuhan log</h2>
        <p className="mt-1 text-sm text-gray-600">
          Setiap catatan dikunci dengan sidik digital yang tersambung ke catatan sebelumnya. Kalau ada catatan yang diubah atau dihapus langsung di database, pengecekan ini akan gagal.
        </p>
        <button type="button" onClick={() => void check()} disabled={checking} className="mt-3 flex min-h-11 items-center gap-2 rounded-xl border border-gray-300 px-4 text-sm font-semibold text-gray-800 hover:bg-gray-50 disabled:opacity-60">
          {checking && <Loader2 className="h-4 w-4 animate-spin" />} Periksa sekarang
        </button>
        {integrity && (
          <p role="status" data-integrity={integrity.ok ? 'ok' : 'broken'} className={cn('mt-3 rounded-xl px-3 py-2 text-sm', integrity.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800')}>
            {integrity.ok
              ? `Utuh — ${integrity.checked.toLocaleString('id-ID')} catatan diperiksa, tidak ada yang berubah.`
              : `Ada masalah di catatan #${integrity.broken_id}: ${integrity.problem}`}
          </p>
        )}
      </div>
    </section>
  );
}
