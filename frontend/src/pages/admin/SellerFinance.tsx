import { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, CheckCircle2, Download, FileText, Loader2, RefreshCw, Save } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  approveSellerWithdrawal,
  downloadSellerWithdrawalProof,
  exportSellerFinance,
  getAdminReconciliation,
  getAdminSellerFinanceSummary,
  getAdminSellerWithdrawals,
  getAdminWebhookLogs,
  getSellerFinanceSettings,
  markSellerWithdrawalFailed,
  markSellerWithdrawalPaid,
  updateSellerFinanceSettings,
} from '@/lib/hellomApi';
import RefundsQueue from './RefundsQueue';
import type {
  AdminReconciliation,
  AdminSellerFinanceSummary,
  AdminWebhookLog,
  AdminWithdrawalRow,
  SellerFinanceSettings,
} from '@/lib/hellomApi';

// Super admin: landing-page sales money (balances, withdrawals, webhooks, reconciliation).
type Tab = 'summary' | 'withdrawals' | 'refunds' | 'webhooks' | 'reconciliation' | 'settings';

const TABS: Array<[Tab, string]> = [
  ['summary', 'Ringkasan'],
  ['withdrawals', 'Penarikan'],
  ['refunds', 'Refund'],
  ['webhooks', 'Webhook'],
  ['reconciliation', 'Rekonsiliasi'],
  ['settings', 'Pengaturan'],
];

const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '-';

function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export default function SellerFinance() {
  const [tab, setTab] = useState<Tab>('summary');
  const [exporting, setExporting] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const doExport = async (type: 'withdrawals' | 'ledger' | 'orders') => {
    setExporting(type);
    try {
      saveBlob(await exportSellerFinance(type), `keuangan-penjual-${type}.xlsx`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Export gagal');
    } finally {
      setExporting(null);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Keuangan Penjual</h1>
          <p className="text-sm text-zinc-500">Uang hasil penjualan Hellom Page: saldo penjual, penarikan, webhook, dan rekonsiliasi.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {([['orders', 'Pesanan'], ['ledger', 'Mutasi saldo'], ['withdrawals', 'Penarikan']] as const).map(([type, label]) => (
            <button
              key={type}
              type="button"
              onClick={() => void doExport(type)}
              disabled={exporting !== null}
              className="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 disabled:opacity-50"
            >
              {exporting === type ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Download className="h-3.5 w-3.5" />}
              Excel {label}
            </button>
          ))}
        </div>
      </div>
      <p className="-mt-4 text-xs text-zinc-400">Export mencakup 30 hari terakhir.</p>

      {error && <p className="rounded-xl border border-rose-100 bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}

      <div className="flex gap-1 overflow-x-auto border-b border-zinc-200">
        {TABS.map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => setTab(key)}
            className={cn('min-h-11 shrink-0 border-b-2 px-4 text-sm font-semibold', tab === key ? 'border-zinc-900 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-800')}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'summary' && <SummaryTab onOpenWithdrawals={() => setTab('withdrawals')} />}
      {tab === 'withdrawals' && <WithdrawalsTab />}
      {tab === 'refunds' && <RefundsQueue />}
      {tab === 'webhooks' && <WebhooksTab />}
      {tab === 'reconciliation' && <ReconciliationTab />}
      {tab === 'settings' && <SettingsTab />}
    </div>
  );
}

function Stat({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: 'warn' | 'bad' }) {
  return (
    <div className={cn('rounded-2xl border bg-white p-4', tone === 'bad' ? 'border-rose-200' : tone === 'warn' ? 'border-amber-200' : 'border-zinc-200')}>
      <p className="text-xs font-semibold text-zinc-500">{label}</p>
      <p className="mt-1 text-xl font-bold text-zinc-900">{value}</p>
      {hint && <p className="mt-0.5 text-xs text-zinc-400">{hint}</p>}
    </div>
  );
}

function SummaryTab({ onOpenWithdrawals }: { onOpenWithdrawals: () => void }) {
  const [data, setData] = useState<AdminSellerFinanceSummary | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getAdminSellerFinanceSummary().then(setData).catch((err) => setError(err instanceof Error ? err.message : 'Gagal memuat'));
  }, []);

  if (error) return <p className="text-sm text-rose-600">{error}</p>;
  if (!data) return <div className="h-40 animate-pulse rounded-2xl bg-zinc-100" />;

  const owed = data.liabilities.pending + data.liabilities.available + data.liabilities.processing;
  return (
    <div className="space-y-4">
      {(data.withdrawals_over_sla > 0 || data.withdrawals_near_sla > 0) && (
        <button type="button" onClick={onOpenWithdrawals} className="flex w-full items-center gap-2 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-left text-sm text-rose-800">
          <AlertTriangle className="h-4 w-4 shrink-0" />
          {data.withdrawals_over_sla} penarikan lewat SLA, {data.withdrawals_near_sla} mendekati SLA. Proses sekarang →
        </button>
      )}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Uang masuk (lunas)" value={rupiah(data.money_in)} hint={`${data.paid_orders} pesanan`} />
        <Stat label="Biaya layanan Hellom" value={rupiah(data.platform_fee)} />
        <Stat label="Biaya gateway" value={rupiah(data.gateway_fee)} />
        <Stat label="Pendapatan bersih Hellom" value={rupiah(data.hellom_net)} tone={data.hellom_net < 0 ? 'bad' : undefined} />
      </div>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Utang ke penjual" value={rupiah(owed)} hint="tertahan + tersedia + diproses" />
        <Stat label="Tertahan" value={rupiah(data.liabilities.pending)} />
        <Stat label="Tersedia" value={rupiah(data.liabilities.available)} />
        <Stat label="Sudah ditarik" value={rupiah(data.withdrawn_total)} />
      </div>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Penarikan terbuka" value={String(data.withdrawals_open)} hint={rupiah(data.liabilities.processing)} tone={data.withdrawals_open > 0 ? 'warn' : undefined} />
        <Stat label="Pesanan menunggu bayar" value={String(data.orders_pending)} />
        <Stat label="Refund perlu ditransfer" value={String(data.refunds_open)} hint={rupiah(data.refunds_open_amount)} tone={data.refunds_open > 0 ? 'warn' : undefined} />
      </div>
    </div>
  );
}

const SLA_BADGE: Record<AdminWithdrawalRow['sla'], string> = {
  ok: 'bg-emerald-100 text-emerald-800',
  near: 'bg-amber-100 text-amber-800',
  over: 'bg-rose-100 text-rose-800',
  done: 'bg-zinc-100 text-zinc-600',
};

function WithdrawalsTab() {
  const [status, setStatus] = useState('open');
  const [rows, setRows] = useState<AdminWithdrawalRow[]>([]);
  const [page, setPage] = useState({ current: 1, last: 1 });
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState<number | null>(null);
  const [paying, setPaying] = useState<AdminWithdrawalRow | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (p = 1) => {
    setLoading(true);
    try {
      const res = await getAdminSellerWithdrawals(status, p);
      setRows(res.data);
      setPage({ current: res.current_page, last: res.last_page });
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat');
    } finally {
      setLoading(false);
    }
  }, [status]);

  useEffect(() => { void load(1); }, [load]);

  const run = async (id: number, fn: () => Promise<unknown>) => {
    setBusy(id);
    setError(null);
    try {
      await fn();
      await load(page.current);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Aksi gagal');
    } finally {
      setBusy(null);
    }
  };

  const fail = (w: AdminWithdrawalRow) => {
    const reason = window.prompt(`Alasan gagal untuk ${w.reference} (dilihat penjual, saldo dikembalikan):`);
    if (reason && reason.trim().length >= 3) void run(w.id, () => markSellerWithdrawalFailed(w.id, reason.trim()));
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="min-h-10 rounded-xl border border-zinc-200 bg-white px-3 text-sm">
          <option value="open">Perlu diproses</option>
          <option value="requested">Diajukan</option>
          <option value="processing">Diproses</option>
          <option value="paid">Berhasil</option>
          <option value="failed">Gagal</option>
          <option value="cancelled">Dibatalkan</option>
          <option value="all">Semua</option>
        </select>
        <button type="button" onClick={() => void load(page.current)} className="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 text-sm">
          <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} /> Muat ulang
        </button>
      </div>
      {error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-zinc-200 bg-white">
        <table className="w-full min-w-[900px] text-sm">
          <thead className="bg-zinc-50 text-left text-xs uppercase text-zinc-500">
            <tr>
              <th className="px-4 py-3">Penjual</th>
              <th className="px-4 py-3">Nominal</th>
              <th className="px-4 py-3">Tujuan</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">SLA</th>
              <th className="px-4 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100">
            {rows.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-8 text-center text-zinc-500">{loading ? 'Memuat…' : 'Tidak ada penarikan.'}</td></tr>
            )}
            {rows.map((w) => (
              <tr key={w.id} className="align-top">
                <td className="px-4 py-3">
                  <p className="font-semibold text-zinc-900">{w.organization.name ?? `#${w.organization.id}`}</p>
                  <p className="font-mono text-xs text-zinc-400">{w.reference}</p>
                  <p className="text-xs text-zinc-400">{dateTime(w.created_at)}</p>
                </td>
                <td className="px-4 py-3">
                  <p className="font-semibold">{rupiah(w.amount)}</p>
                  {w.fee_amount > 0 && <p className="text-xs text-zinc-500">transfer {rupiah(w.net_amount)}</p>}
                </td>
                <td className="px-4 py-3">
                  <p>{w.bank_name || w.bank_code} <span className="text-xs text-zinc-400">({w.destination_type === 'ewallet' ? 'e-wallet' : 'bank'})</span></p>
                  <p className="font-mono">{w.account_number}</p>
                  <p className="text-xs text-zinc-500">a.n. {w.account_name}</p>
                </td>
                <td className="px-4 py-3">
                  <p className="font-medium">{w.status_label}</p>
                  {w.failure_reason && <p className="text-xs text-rose-600">{w.failure_reason}</p>}
                  {w.auto_error && <p className="text-xs text-amber-700">Auto: {w.auto_error}</p>}
                </td>
                <td className="px-4 py-3">
                  <span className={cn('rounded-full px-2 py-0.5 text-xs font-semibold', SLA_BADGE[w.sla])}>
                    {w.sla === 'done' ? 'selesai' : `${Math.floor(w.age_hours)} jam`}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap justify-end gap-2">
                    {busy === w.id && <Loader2 className="h-4 w-4 animate-spin text-zinc-400" />}
                    {w.status === 'requested' && (
                      <button type="button" disabled={busy !== null} onClick={() => void run(w.id, () => approveSellerWithdrawal(w.id))} className="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">
                        Proses
                      </button>
                    )}
                    {(w.status === 'requested' || w.status === 'processing') && (
                      <>
                        <button type="button" disabled={busy !== null} onClick={() => setPaying(w)} className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">
                          Tandai berhasil
                        </button>
                        <button type="button" disabled={busy !== null} onClick={() => fail(w)} className="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 disabled:opacity-50">
                          Gagal
                        </button>
                      </>
                    )}
                    {w.has_proof && (
                      <button type="button" onClick={() => void downloadSellerWithdrawalProof(w.id).then((b) => saveBlob(b, `bukti-${w.reference}`)).catch(() => setError('Bukti tidak bisa diunduh'))} className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold">
                        <FileText className="h-3.5 w-3.5" /> Bukti
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {page.last > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <button type="button" disabled={page.current <= 1} onClick={() => void load(page.current - 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">‹</button>
          <span>{page.current} / {page.last}</span>
          <button type="button" disabled={page.current >= page.last} onClick={() => void load(page.current + 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">›</button>
        </div>
      )}

      {paying && (
        <MarkPaidDialog
          withdrawal={paying}
          onClose={() => setPaying(null)}
          onSubmit={(form) => {
            const w = paying;
            setPaying(null);
            void run(w.id, () => markSellerWithdrawalPaid(w.id, form));
          }}
        />
      )}
    </div>
  );
}

function MarkPaidDialog({ withdrawal, onClose, onSubmit }: { withdrawal: AdminWithdrawalRow; onClose: () => void; onSubmit: (form: FormData) => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [ref, setRef] = useState('');

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    const form = new FormData();
    if (file) form.append('proof', file);
    if (ref.trim()) form.append('provider_ref', ref.trim());
    onSubmit(form);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <form onSubmit={submit} onClick={(e) => e.stopPropagation()} className="w-full max-w-md space-y-4 rounded-2xl bg-white p-5">
        <div>
          <h2 className="text-lg font-bold">Tandai transfer berhasil</h2>
          <p className="text-sm text-zinc-500">
            {rupiah(withdrawal.net_amount)} ke {withdrawal.bank_name || withdrawal.bank_code} {withdrawal.account_number} a.n. {withdrawal.account_name}
          </p>
        </div>
        <label className="block space-y-1 text-sm">
          <span className="font-semibold">Bukti transfer (jpg/png/webp/pdf, maks 4 MB)</span>
          <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="w-full text-sm" />
        </label>
        <label className="block space-y-1 text-sm">
          <span className="font-semibold">No. referensi bank (opsional)</span>
          <input value={ref} onChange={(e) => setRef(e.target.value)} maxLength={120} className="w-full rounded-xl border border-zinc-300 px-3 py-2" />
        </label>
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-xl border px-4 py-2 text-sm font-semibold">Batal</button>
          <button type="submit" className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">
            <CheckCircle2 className="h-4 w-4" /> Simpan
          </button>
        </div>
      </form>
    </div>
  );
}

function WebhooksTab() {
  const [filters, setFilters] = useState({ provider: '', outcome: '', reference: '' });
  const [rows, setRows] = useState<AdminWebhookLog[]>([]);
  const [page, setPage] = useState({ current: 1, last: 1 });
  const [open, setOpen] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (p = 1) => {
    try {
      const res = await getAdminWebhookLogs({
        provider: filters.provider || undefined,
        outcome: filters.outcome || undefined,
        reference: filters.reference || undefined,
        page: p,
      });
      setRows(res.data);
      setPage({ current: res.current_page, last: res.last_page });
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat');
    }
  }, [filters]);

  useEffect(() => { void load(1); }, [load]);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        <select value={filters.provider} onChange={(e) => setFilters((f) => ({ ...f, provider: e.target.value }))} className="min-h-10 rounded-xl border border-zinc-200 bg-white px-3 text-sm">
          <option value="">Semua gateway</option>
          <option value="ipaymu">iPaymu</option>
          <option value="xendit">Xendit</option>
          <option value="doku">DOKU</option>
        </select>
        <input
          placeholder="Hasil (mis. amount_mismatch)"
          defaultValue={filters.outcome}
          onBlur={(e) => setFilters((f) => ({ ...f, outcome: e.target.value.trim() }))}
          className="min-h-10 rounded-xl border border-zinc-200 px-3 text-sm"
        />
        <input
          placeholder="No. pesanan"
          defaultValue={filters.reference}
          onBlur={(e) => setFilters((f) => ({ ...f, reference: e.target.value.trim() }))}
          className="min-h-10 rounded-xl border border-zinc-200 px-3 text-sm"
        />
      </div>
      {error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}
      <div className="divide-y divide-zinc-100 rounded-2xl border border-zinc-200 bg-white">
        {rows.length === 0 && <p className="p-6 text-center text-sm text-zinc-500">Belum ada webhook.</p>}
        {rows.map((log) => (
          <div key={log.id} className="p-3 text-sm">
            <button type="button" onClick={() => setOpen(open === log.id ? null : log.id)} className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 text-left">
              <span className="w-32 text-xs text-zinc-500">{dateTime(log.received_at)}</span>
              <span className="w-16 font-semibold uppercase">{log.provider}</span>
              <span className="font-mono text-xs">{log.reference ?? '-'}</span>
              <span className={cn('rounded-full px-2 py-0.5 text-xs font-semibold', log.signature_valid ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600')}>
                {log.signature_valid ? 'terverifikasi' : 'tanpa tanda tangan'}
              </span>
              <span className={cn('text-xs font-semibold', log.error ? 'text-rose-600' : 'text-zinc-700')}>{log.outcome ?? '-'}</span>
            </button>
            {open === log.id && (
              <div className="mt-2 space-y-1">
                {log.error && <p className="text-xs text-rose-600">{log.error}</p>}
                <p className="text-xs text-zinc-400">IP {log.ip ?? '-'} · event {log.event_id ?? '-'}</p>
                <pre className="max-h-64 overflow-auto rounded-xl bg-zinc-950 p-3 text-xs text-zinc-100">{log.payload}</pre>
              </div>
            )}
          </div>
        ))}
      </div>
      {page.last > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <button type="button" disabled={page.current <= 1} onClick={() => void load(page.current - 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">‹</button>
          <span>{page.current} / {page.last}</span>
          <button type="button" disabled={page.current >= page.last} onClick={() => void load(page.current + 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">›</button>
        </div>
      )}
    </div>
  );
}

function ReconciliationTab() {
  const [data, setData] = useState<AdminReconciliation | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = async () => {
    setLoading(true);
    try {
      setData(await getAdminReconciliation());
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <button type="button" onClick={() => void load()} className="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 text-sm">
          <RefreshCw className={cn('h-4 w-4', loading && 'animate-spin')} /> Cek ulang
        </button>
        {data && <span className="text-xs text-zinc-500">Dicek {dateTime(data.checked_at)} · {data.checked_sellers} penjual</span>}
      </div>
      {error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}
      {data && (
        <>
          <section className="rounded-2xl border border-zinc-200 bg-white p-4">
            <h3 className="text-sm font-bold">Saldo cache vs mutasi</h3>
            {data.mismatches.length === 0 ? (
              <p className="mt-2 flex items-center gap-1.5 text-sm text-emerald-700"><CheckCircle2 className="h-4 w-4" /> Semua saldo cocok dengan mutasi.</p>
            ) : (
              <ul className="mt-2 space-y-2 text-sm">
                {data.mismatches.map((m) => (
                  <li key={m.organization_id} className="rounded-xl bg-rose-50 p-3 text-rose-800">
                    <p className="font-semibold">{m.organization ?? `#${m.organization_id}`}</p>
                    <p className="font-mono text-xs">cache {JSON.stringify(m.cached)} · mutasi {JSON.stringify(m.computed)}</p>
                  </li>
                ))}
                <li className="text-xs text-zinc-500">Perbaiki dengan <code>php artisan balance:reconcile --fix</code> setelah dicek.</li>
              </ul>
            )}
          </section>
          <section className="rounded-2xl border border-zinc-200 bg-white p-4">
            <h3 className="text-sm font-bold">Pesanan bermasalah</h3>
            <p className="text-xs text-zinc-500">{data.stale_pending_orders} pesanan menunggu bayar sudah lewat batas waktu (akan dikadaluarsakan otomatis).</p>
            {data.problem_orders.length === 0 ? (
              <p className="mt-2 text-sm text-zinc-500">Tidak ada pembayaran yang ditolak.</p>
            ) : (
              <ul className="mt-2 divide-y divide-zinc-100 text-sm">
                {data.problem_orders.map((o) => (
                  <li key={o.reference} className="py-2">
                    <p><span className="font-mono text-xs">{o.reference}</span> · {o.status} · {rupiah(o.amount)} · {dateTime(o.created_at)}</p>
                    <pre className="mt-1 overflow-auto rounded-lg bg-zinc-50 p-2 text-xs">{JSON.stringify(o.problems, null, 1)}</pre>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </div>
  );
}

const METHOD_LABELS: Record<keyof SellerFinanceSettings['gateway_fees'], string> = {
  qris: 'QRIS',
  va: 'Virtual Account',
  ewallet: 'E-wallet',
  cc: 'Kartu kredit',
  retail: 'Gerai retail',
  other: 'Lainnya',
};

function SettingsTab() {
  const [form, setForm] = useState<SellerFinanceSettings | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getSellerFinanceSettings().then(setForm).catch((err) => setError(err instanceof Error ? err.message : 'Gagal memuat'));
  }, []);

  if (!form) return error ? <p className="text-sm text-rose-600">{error}</p> : <div className="h-40 animate-pulse rounded-2xl bg-zinc-100" />;

  const set = <K extends keyof SellerFinanceSettings>(key: K, value: SellerFinanceSettings[K]) => setForm((f) => (f ? { ...f, [key]: value } : f));
  const num = (v: string) => (v === '' ? 0 : Number(v));

  const save = async () => {
    setSaving(true);
    setMessage(null);
    setError(null);
    try {
      setForm(await updateSellerFinanceSettings(form));
      setMessage('Pengaturan disimpan. Berlaku untuk pesanan dan penarikan berikutnya.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal menyimpan');
    } finally {
      setSaving(false);
    }
  };

  const field = (label: string, key: keyof SellerFinanceSettings, hint?: string, step = '1') => (
    <label className="space-y-1 text-sm">
      <span className="font-semibold text-zinc-700">{label}</span>
      <input
        type="number"
        min={0}
        step={step}
        value={String(form[key] as number)}
        onChange={(e) => set(key, num(e.target.value) as never)}
        className="w-full rounded-xl border border-zinc-300 px-3 py-2"
      />
      {hint && <span className="block text-xs text-zinc-500">{hint}</span>}
    </label>
  );

  return (
    <div className="space-y-6">
      <section className="rounded-2xl border border-zinc-200 bg-white p-5">
        <h3 className="text-sm font-bold">Biaya layanan Hellom</h3>
        <p className="mt-1 text-xs text-zinc-500">
          Dipotong dari penjual per penjualan: maks(harga × persen + flat, biaya gateway + margin minimum). Biaya gateway ditanggung Hellom dari biaya ini.
        </p>
        <div className="mt-4 grid gap-4 md:grid-cols-3">
          {field('Persen (%)', 'platform_fee_percent', undefined, '0.1')}
          {field('Flat (Rp)', 'platform_fee_flat')}
          {field('Margin minimum Hellom (Rp)', 'min_margin_flat', 'Supaya Hellom tidak rugi di transaksi kecil')}
        </div>
      </section>

      <section className="rounded-2xl border border-zinc-200 bg-white p-5">
        <h3 className="text-sm font-bold">Biaya gateway per metode (perkiraan)</h3>
        <p className="mt-1 text-xs text-zinc-500">Dipakai kalau gateway tidak mengirim biaya aktual.</p>
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          {(Object.keys(METHOD_LABELS) as Array<keyof SellerFinanceSettings['gateway_fees']>).map((method) => (
            <div key={method} className="flex items-center gap-2 text-sm">
              <span className="w-32 font-medium">{METHOD_LABELS[method]}</span>
              <input
                type="number" min={0} step="0.1"
                value={String(form.gateway_fees[method]?.percent ?? 0)}
                onChange={(e) => set('gateway_fees', { ...form.gateway_fees, [method]: { ...form.gateway_fees[method], percent: num(e.target.value) } })}
                className="w-20 rounded-lg border border-zinc-300 px-2 py-1.5"
              />
              <span className="text-zinc-500">% +</span>
              <input
                type="number" min={0}
                value={String(form.gateway_fees[method]?.flat ?? 0)}
                onChange={(e) => set('gateway_fees', { ...form.gateway_fees, [method]: { ...form.gateway_fees[method], flat: num(e.target.value) } })}
                className="w-24 rounded-lg border border-zinc-300 px-2 py-1.5"
              />
              <span className="text-zinc-500">Rp</span>
            </div>
          ))}
        </div>
      </section>

      <section className="rounded-2xl border border-zinc-200 bg-white p-5">
        <h3 className="text-sm font-bold">Dana tertahan & penarikan</h3>
        <div className="mt-4 grid gap-4 md:grid-cols-3">
          {field('Masa tahan (hari)', 'hold_days', '0 = langsung tersedia setelah lunas')}
          {field('Masa tahan penjual baru (hari)', 'new_seller_hold_days')}
          {field('Dianggap penjual baru selama (hari)', 'new_seller_days')}
          {field('Minimal penarikan (Rp)', 'min_withdrawal')}
          {field('Biaya transfer ke penjual (Rp)', 'withdrawal_fee_flat')}
          <label className="space-y-1 text-sm">
            <span className="font-semibold text-zinc-700">Mode pencairan</span>
            <select value={form.withdrawal_mode} onChange={(e) => set('withdrawal_mode', e.target.value as 'manual' | 'auto')} className="w-full rounded-xl border border-zinc-300 px-3 py-2">
              <option value="manual">Manual (admin transfer)</option>
              <option value="auto">Otomatis (Xendit payout)</option>
            </select>
          </label>
          {field('SLA penarikan (jam)', 'sla_hours')}
          {field('Peringatan SLA (jam)', 'sla_warn_hours')}
          {field('Tahan setelah ganti rekening (jam)', 'bank_change_hold_hours')}
          {field('Batas bayar pesanan (jam)', 'order_expiry_hours')}
        </div>
      </section>

      {message && <p className="rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{message}</p>}
      {error && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{error}</p>}
      <button type="button" onClick={() => void save()} disabled={saving} className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-zinc-900 px-5 text-sm font-semibold text-white disabled:opacity-50">
        {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />} Simpan pengaturan
      </button>
    </div>
  );
}
