import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, ArrowRight, RefreshCw, Search, X } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import {
  getFinanceJournalSummary,
  getFinanceJournalTransactions,
  type FinanceSummary,
  type FinanceTransaction,
  type FinanceTransactionFilters,
  type FinanceTransactionPage,
} from '@/lib/hellomApi';
import { useAdminRealtimeEvent } from '@/hooks/useAdminRealtimeEvent';

// Chart colours: validated pair (dataviz validator, light surface) — blue = uang masuk, orange = pendapatan Hellom.
const BLUE = '#2a78d6';
const ORANGE = '#eb6834';
const GRID = '#e4e4e7';
const MUTED = '#71717a';
const POLL_MS = 30_000;
const RANGES = [7, 30, 90, 365] as const;

const rupiah = (value: number) => `Rp ${Math.round(value || 0).toLocaleString('id-ID')}`;
const compact = (value: number) => {
  const abs = Math.abs(value);
  if (abs >= 1_000_000_000) return `${(value / 1_000_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} M`;
  if (abs >= 1_000_000) return `${(value / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`;
  if (abs >= 1_000) return `${(value / 1_000).toLocaleString('id-ID', { maximumFractionDigits: 0 })} rb`;
  return String(value);
};
const shortDate = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const SOURCE_LABELS: Record<string, string> = {
  landing_page: 'Hellom Page (penjual)',
  digital_product: 'Produk Hellom',
  subscription: 'Langganan',
  wallet_topup: 'Top-up saldo',
  seller_finance: 'Saldo penjual',
};

const EVENT_LABELS: Record<string, string> = {
  sale: 'Penjualan',
  platform_fee: 'Biaya layanan',
  gateway_fee: 'Biaya pembayaran',
  release: 'Saldo cair',
  withdrawal: 'Penarikan diajukan',
  withdrawal_reversal: 'Penarikan batal/gagal',
  withdrawal_paid: 'Penarikan dibayar',
  refund: 'Refund diajukan',
  refund_reversal: 'Refund gagal',
  refund_paid: 'Refund dibayar',
  adjustment: 'Penyesuaian',
  opening: 'Saldo awal',
  product_paid: 'Produk terjual',
  product_refunded: 'Produk direfund',
  subscription_paid: 'Langganan dibayar',
  wallet_topup: 'Top-up',
  wallet_to_seller_balance: 'Pindah ke Saldo Penjualan',
};

const PROVIDER_LABELS: Record<string, string> = { ipaymu: 'iPaymu', xendit: 'Xendit', doku: 'DOKU', manual: 'Manual', wallet: 'Saldo dompet' };

const ACCOUNT_LABELS: Record<string, string> = {
  'bank:hellom': 'Rekening Hellom',
  'refund:payable': 'Refund terutang ke pembeli',
  'revenue:platform_fee': 'Pendapatan · biaya layanan',
  'revenue:digital_product': 'Pendapatan · produk Hellom',
  'revenue:subscription': 'Pendapatan · langganan',
  'revenue:withdrawal_fee': 'Pendapatan · biaya penarikan',
  'expense:gateway_fee': 'Beban · biaya gateway',
  'hellom:adjustment': 'Beban · penyesuaian saldo',
  'equity:opening': 'Saldo awal',
};
const SELLER_BUCKETS: Record<string, string> = { pending: 'tertahan', available: 'tersedia', processing: 'diproses' };

function accountLabel(account: string): string {
  if (ACCOUNT_LABELS[account]) return ACCOUNT_LABELS[account];
  const [kind, id, bucket] = account.split(':');
  if (kind === 'gateway') return `Kas di ${PROVIDER_LABELS[id] ?? id}`;
  if (kind === 'seller') return `Saldo penjual · ${SELLER_BUCKETS[bucket] ?? bucket}`;
  if (kind === 'wallet') return 'Dompet top-up';
  return account;
}

type ChartTooltipProps = { active?: boolean; label?: string | number; payload?: Array<{ value?: number | string; name?: string }> };

function MoneyTooltip({ active, label, payload }: ChartTooltipProps) {
  if (!active || !payload?.length) return null;
  const title = typeof label === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(label) ? shortDate(label) : label;
  return (
    <div className="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs shadow-md">
      <p className="font-semibold text-zinc-900">{title}</p>
      {payload.map((item) => (
        <p key={item.name} className="text-zinc-600">{item.name}: <span className="font-semibold text-zinc-900">{rupiah(Number(item.value ?? 0))}</span></p>
      ))}
    </div>
  );
}

function Tile({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-wide text-zinc-500">{label}</p>
      <p className="mt-2 text-2xl font-bold text-zinc-900">{value}</p>
      {hint && <p className="mt-1 text-xs text-zinc-500">{hint}</p>}
    </div>
  );
}

function TrendChart({ title, data, dataKey, name, color }: { title: string; data: FinanceSummary['trend']; dataKey: 'gross' | 'revenue'; name: string; color: string }) {
  const total = data.reduce((sum, point) => sum + point[dataKey], 0);
  return (
    <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
      <h3 className="font-bold text-zinc-900">{title}</h3>
      <p className="text-xs text-zinc-500">Total periode: {rupiah(total)}</p>
      <div className="mt-4 h-[220px] w-full min-w-0" role="img" aria-label={`${title}: total ${rupiah(total)}`}>
        <ResponsiveContainer width="100%" height="100%" minWidth={0}>
          <LineChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
            <CartesianGrid vertical={false} stroke={GRID} strokeWidth={1} />
            <XAxis dataKey="date" tickFormatter={shortDate} axisLine={false} tickLine={false} tick={{ fill: MUTED, fontSize: 11 }} minTickGap={24} />
            <YAxis tickFormatter={compact} axisLine={false} tickLine={false} tick={{ fill: MUTED, fontSize: 11 }} width={60} />
            <Tooltip content={<MoneyTooltip />} cursor={{ stroke: '#a1a1aa', strokeWidth: 1 }} />
            <Line type="monotone" dataKey={dataKey} name={name} stroke={color} strokeWidth={2} dot={false} activeDot={{ r: 4, strokeWidth: 2, stroke: '#fff' }} />
          </LineChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}

const EMPTY_FILTERS: FinanceTransactionFilters = { provider: '', source: '', event_type: '', q: '', from: '', to: '' };

export default function FinanceOverview() {
  const [days, setDays] = useState<number>(30);
  const [summary, setSummary] = useState<FinanceSummary | null>(null);
  const [summaryError, setSummaryError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);

  const [filters, setFilters] = useState<FinanceTransactionFilters>(EMPTY_FILTERS);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [transactions, setTransactions] = useState<FinanceTransactionPage | null>(null);
  const [listError, setListError] = useState<string | null>(null);
  const [selected, setSelected] = useState<FinanceTransaction | null>(null);

  const loadSummary = useCallback(async (refreshBalances = false) => {
    try {
      setSummary(await getFinanceJournalSummary(days, refreshBalances));
      setSummaryError(null);
      setUpdatedAt(new Date());
    } catch (error) {
      setSummaryError(error instanceof Error ? error.message : 'Ringkasan keuangan gagal dimuat.');
    }
  }, [days]);

  const loadTransactions = useCallback(async () => {
    try {
      setTransactions(await getFinanceJournalTransactions({ ...filters, page, per_page: 25 }));
      setListError(null);
    } catch (error) {
      setListError(error instanceof Error ? error.message : 'Transaksi gagal dimuat.');
    }
  }, [filters, page]);

  useEffect(() => {
    void loadSummary();
  }, [loadSummary]);

  useEffect(() => {
    void loadTransactions();
  }, [loadTransactions]);

  // Near real time: poll while the tab is visible, plus a push from the realtime server.
  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.visibilityState !== 'visible') return;
      void loadSummary();
      void loadTransactions();
    }, POLL_MS);
    return () => window.clearInterval(timer);
  }, [loadSummary, loadTransactions]);

  useAdminRealtimeEvent('admin.finance.journal', () => {
    void loadSummary();
    void loadTransactions();
  });

  const refreshAll = async () => {
    setRefreshing(true);
    await Promise.all([loadSummary(true), loadTransactions()]);
    setRefreshing(false);
  };

  const setFilter = (key: 'provider' | 'source' | 'event_type' | 'q' | 'from' | 'to', value: string) => {
    setPage(1);
    setFilters((current) => ({ ...current, [key]: value }));
  };

  const providerChart = useMemo(
    () => (summary?.providers ?? []).map((item) => ({ name: item.label, gross: item.gross })),
    [summary],
  );
  const sellerChart = useMemo(
    () => (summary?.top_sellers ?? []).map((item) => ({ name: item.name, gross: item.gross })),
    [summary],
  );
  const hasFilters = Object.values(filters).some((value) => value !== '' && value !== undefined);

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Ringkasan Keuangan</h1>
          <p className="text-sm text-zinc-500">
            Semua uang masuk & keluar dari iPaymu, Xendit, DOKU dan transfer manual — dari jurnal double-entry.
            {updatedAt && <> Diperbarui {updatedAt.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}.</>}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <select
            value={days}
            onChange={(event) => setDays(Number(event.target.value))}
            className="rounded-lg border-zinc-200 text-sm text-zinc-700 focus:border-yellow-400 focus:ring-yellow-400"
            aria-label="Periode"
          >
            {RANGES.map((value) => (
              <option key={value} value={value}>{value === 365 ? '1 tahun terakhir' : `${value} hari terakhir`}</option>
            ))}
          </select>
          <button
            type="button"
            onClick={() => void refreshAll()}
            className="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
          >
            <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} /> Perbarui
          </button>
        </div>
      </div>

      {summaryError && (
        <div className="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          <AlertCircle className="h-4 w-4" /> {summaryError}
        </div>
      )}

      {!summary && !summaryError && (
        <div className="rounded-xl border border-zinc-200 bg-white p-6 text-sm text-zinc-500 shadow-sm">Memuat ringkasan keuangan…</div>
      )}

      {summary && summary.journal.entries === 0 && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          Jurnal keuangan masih kosong. Jalankan <code className="font-mono">php artisan finance:journal-backfill</code> (laporan), lalu dengan <code className="font-mono">--force</code> untuk memasukkan riwayat transaksi.
        </div>
      )}

      {summary && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Tile label="Uang masuk" value={rupiah(summary?.totals.gross ?? 0)} hint={`${(summary?.totals.transactions ?? 0).toLocaleString('id-ID')} transaksi`} />
            <Tile label="Pendapatan Hellom" value={rupiah(summary?.totals.revenue ?? 0)} hint="Biaya layanan, produk, langganan, biaya penarikan" />
            <Tile label="Biaya gateway" value={rupiah(summary?.totals.gateway_fees ?? 0)} hint="Ditanggung Hellom" />
            <Tile label="Bersih Hellom" value={rupiah(summary?.totals.hellom_net ?? 0)} hint="Pendapatan − biaya gateway − penyesuaian" />
          </div>

          <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="font-bold text-zinc-900">Uang milik orang lain yang dipegang Hellom</h3>
              <Link to="/admin/keuangan-penjual" className="inline-flex items-center gap-1 text-xs font-semibold text-zinc-600 underline">
                Keuangan Penjual <ArrowRight className="h-3 w-3" />
              </Link>
            </div>
            <dl className="mt-4 grid grid-cols-2 gap-4 text-sm md:grid-cols-5">
              {[
                ['Saldo penjual tertahan', summary?.liabilities.seller_pending],
                ['Saldo penjual tersedia', summary?.liabilities.seller_available],
                ['Penarikan diproses', summary?.liabilities.seller_processing],
                ['Dompet top-up', summary?.liabilities.wallets],
                ['Refund terutang', summary?.liabilities.refunds],
              ].map(([label, value]) => (
                <div key={String(label)}>
                  <dt className="text-xs text-zinc-500">{label}</dt>
                  <dd className="mt-1 font-semibold text-zinc-900">{rupiah(Number(value ?? 0))}</dd>
                </div>
              ))}
            </dl>
          </div>

          <div>
            <h2 className="mb-3 text-lg font-bold text-zinc-900">Per gateway</h2>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
              {(summary?.providers ?? []).map((item) => (
                <div key={item.provider} className="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                  <p className="font-bold text-zinc-900">{item.label}</p>
                  <dl className="mt-3 space-y-1.5 text-sm">
                    <div className="flex justify-between gap-2"><dt className="text-zinc-500">Uang masuk</dt><dd className="font-semibold text-zinc-900">{rupiah(item.gross)}</dd></div>
                    <div className="flex justify-between gap-2"><dt className="text-zinc-500">Transaksi</dt><dd className="text-zinc-900">{item.transactions.toLocaleString('id-ID')}</dd></div>
                    <div className="flex justify-between gap-2"><dt className="text-zinc-500">Biaya gateway</dt><dd className="text-zinc-900">{rupiah(item.gateway_fees)}</dd></div>
                    <div className="flex justify-between gap-2"><dt className="text-zinc-500">Saldo menurut jurnal</dt><dd className="text-zinc-900">{rupiah(item.journal_balance)}</dd></div>
                    {item.provider !== 'manual' && (
                      <div className="flex justify-between gap-2">
                        <dt className="text-zinc-500">Saldo di {item.label}</dt>
                        <dd className="text-zinc-900">{item.live_balance ? rupiah(item.live_balance.available) : <span className="text-zinc-400">Tidak tersedia</span>}</dd>
                      </div>
                    )}
                  </dl>
                </div>
              ))}
            </div>
            <p className="mt-2 text-xs text-zinc-500">
              Saldo menurut jurnal = semua uang yang pernah masuk lewat gateway dikurangi biayanya, sejak jurnal dimulai; bisa beda dengan saldo di gateway karena pencairan ke rekening Hellom tidak tercatat di sini.
            </p>
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <TrendChart title="Uang masuk per hari" data={summary?.trend ?? []} dataKey="gross" name="Uang masuk" color={BLUE} />
            <TrendChart title="Pendapatan Hellom per hari" data={summary?.trend ?? []} dataKey="revenue" name="Pendapatan Hellom" color={ORANGE} />
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
              <h3 className="font-bold text-zinc-900">Perbandingan gateway</h3>
              <p className="text-xs text-zinc-500">Uang masuk per gateway dalam periode ini</p>
              <div className="mt-4 h-[220px] w-full min-w-0">
                <ResponsiveContainer width="100%" height="100%" minWidth={0}>
                  <BarChart data={providerChart} layout="vertical" margin={{ top: 0, right: 16, bottom: 0, left: 0 }} barCategoryGap={8}>
                    <CartesianGrid horizontal={false} stroke={GRID} />
                    <XAxis type="number" tickFormatter={compact} axisLine={false} tickLine={false} tick={{ fill: MUTED, fontSize: 11 }} />
                    <YAxis type="category" dataKey="name" axisLine={false} tickLine={false} tick={{ fill: '#3f3f46', fontSize: 12 }} width={110} />
                    <Tooltip content={<MoneyTooltip />} cursor={{ fill: '#f4f4f5' }} />
                    <Bar dataKey="gross" name="Uang masuk" fill={BLUE} radius={[0, 4, 4, 0]} maxBarSize={22} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>

            <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
              <h3 className="font-bold text-zinc-900">Penjual teratas</h3>
              <p className="text-xs text-zinc-500">Penjualan Hellom Page dalam periode ini</p>
              {sellerChart.length === 0 ? (
                <div className="mt-4 rounded-xl border border-dashed border-zinc-200 bg-zinc-50 p-5 text-sm text-zinc-500">Belum ada penjualan di periode ini.</div>
              ) : (
                <>
                  <div className="mt-4 w-full min-w-0" style={{ height: Math.max(120, sellerChart.length * 32) }}>
                    <ResponsiveContainer width="100%" height="100%" minWidth={0}>
                      <BarChart data={sellerChart} layout="vertical" margin={{ top: 0, right: 16, bottom: 0, left: 0 }} barCategoryGap={6}>
                        <CartesianGrid horizontal={false} stroke={GRID} />
                        <XAxis type="number" tickFormatter={compact} axisLine={false} tickLine={false} tick={{ fill: MUTED, fontSize: 11 }} />
                        <YAxis type="category" dataKey="name" axisLine={false} tickLine={false} tick={{ fill: '#3f3f46', fontSize: 12 }} width={130} />
                        <Tooltip content={<MoneyTooltip />} cursor={{ fill: '#f4f4f5' }} />
                        <Bar dataKey="gross" name="Penjualan" fill={BLUE} radius={[0, 4, 4, 0]} maxBarSize={20} />
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                  <ul className="mt-3 divide-y divide-zinc-100 text-sm">
                    {summary?.top_sellers.map((seller) => (
                      <li key={seller.organization_id} className="flex justify-between gap-3 py-2">
                        <button type="button" className="truncate text-left text-zinc-700 underline-offset-2 hover:underline" onClick={() => { setPage(1); setFilters({ ...EMPTY_FILTERS, organization_id: seller.organization_id }); }}>
                          {seller.name}
                        </button>
                        <span className="shrink-0 text-zinc-500">{seller.orders} order · biaya layanan {rupiah(seller.platform_fee)}</span>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </div>
          </div>
        </>
      )}

      <div className="rounded-xl border border-zinc-200 bg-white shadow-sm">
        <div className="border-b border-zinc-100 p-6">
          <h3 className="font-bold text-zinc-900">Transaksi</h3>
          <p className="text-xs text-zinc-500">Setiap baris = satu kejadian di jurnal. Klik untuk melihat rinciannya.</p>
          <div className="mt-4 flex flex-wrap items-center gap-2">
            <form
              className="relative min-w-[200px] flex-1"
              onSubmit={(event) => { event.preventDefault(); setFilter('q', search.trim()); }}
            >
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Cari toko, keterangan, kode…"
                className="w-full rounded-lg border-zinc-200 pl-9 text-sm text-zinc-900 focus:border-yellow-400 focus:ring-yellow-400"
              />
            </form>
            <select value={filters.provider} onChange={(event) => setFilter('provider', event.target.value)} className="rounded-lg border-zinc-200 text-sm text-zinc-700" aria-label="Gateway">
              <option value="">Semua gateway</option>
              {Object.entries(PROVIDER_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
            <select value={filters.source} onChange={(event) => setFilter('source', event.target.value)} className="rounded-lg border-zinc-200 text-sm text-zinc-700" aria-label="Sumber">
              <option value="">Semua sumber</option>
              {Object.entries(SOURCE_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
            <select value={filters.event_type} onChange={(event) => setFilter('event_type', event.target.value)} className="rounded-lg border-zinc-200 text-sm text-zinc-700" aria-label="Jenis">
              <option value="">Semua jenis</option>
              {Object.entries(EVENT_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
            <input type="date" value={filters.from} onChange={(event) => setFilter('from', event.target.value)} className="rounded-lg border-zinc-200 text-sm text-zinc-700" aria-label="Dari tanggal" />
            <input type="date" value={filters.to} onChange={(event) => setFilter('to', event.target.value)} className="rounded-lg border-zinc-200 text-sm text-zinc-700" aria-label="Sampai tanggal" />
            {hasFilters && (
              <button type="button" onClick={() => { setSearch(''); setPage(1); setFilters(EMPTY_FILTERS); }} className="inline-flex items-center gap-1 rounded-lg px-2 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100">
                <X className="h-4 w-4" /> Reset
              </button>
            )}
          </div>
          {filters.organization_id && (
            <p className="mt-2 text-xs text-zinc-600">Filter toko: {summary?.top_sellers.find((s) => s.organization_id === filters.organization_id)?.name ?? `#${filters.organization_id}`}</p>
          )}
        </div>

        {listError && <div className="m-6 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{listError}</div>}

        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500">
              <tr>
                <th className="px-6 py-3 font-medium">Waktu</th>
                <th className="px-6 py-3 font-medium">Jenis</th>
                <th className="px-6 py-3 font-medium">Sumber</th>
                <th className="px-6 py-3 font-medium">Toko</th>
                <th className="px-6 py-3 font-medium">Gateway</th>
                <th className="px-6 py-3 text-right font-medium">Nominal</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100">
              {transactions?.items.length === 0 && (
                <tr><td colSpan={6} className="px-6 py-10 text-center text-zinc-500">Tidak ada transaksi yang cocok.</td></tr>
              )}
              {transactions?.items.map((item) => (
                <tr key={item.id} onClick={() => setSelected(item)} className="cursor-pointer hover:bg-zinc-50">
                  <td className="whitespace-nowrap px-6 py-3 text-zinc-600">{dateTime(item.occurred_at)}</td>
                  <td className="px-6 py-3 font-medium text-zinc-900">{EVENT_LABELS[item.event_type] ?? item.event_type}</td>
                  <td className="px-6 py-3 text-zinc-600">{SOURCE_LABELS[item.source] ?? item.source}</td>
                  <td className="max-w-[200px] truncate px-6 py-3 text-zinc-600">{item.organization?.name ?? '-'}</td>
                  <td className="px-6 py-3 text-zinc-600">{item.provider ? PROVIDER_LABELS[item.provider] ?? item.provider : '-'}</td>
                  <td className="whitespace-nowrap px-6 py-3 text-right font-semibold text-zinc-900">{rupiah(item.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {transactions && transactions.pagination.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-zinc-100 px-6 py-3 text-sm text-zinc-600">
            <span>Halaman {transactions.pagination.page} dari {transactions.pagination.last_page} · {transactions.pagination.total.toLocaleString('id-ID')} transaksi</span>
            <div className="flex gap-2">
              <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-lg border border-zinc-200 px-3 py-1.5 font-semibold disabled:opacity-40">Sebelumnya</button>
              <button type="button" disabled={page >= transactions.pagination.last_page} onClick={() => setPage((p) => p + 1)} className="rounded-lg border border-zinc-200 px-3 py-1.5 font-semibold disabled:opacity-40">Berikutnya</button>
            </div>
          </div>
        )}
      </div>

      {selected && (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/30" onClick={() => setSelected(null)}>
          <aside className="h-full w-full max-w-md overflow-y-auto bg-white p-6 shadow-xl" onClick={(event) => event.stopPropagation()} aria-label="Rincian transaksi">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="text-xs text-zinc-500">{dateTime(selected.occurred_at)}</p>
                <h3 className="text-lg font-bold text-zinc-900">{EVENT_LABELS[selected.event_type] ?? selected.event_type}</h3>
                <p className="text-sm text-zinc-600">{selected.description ?? '-'}</p>
              </div>
              <button type="button" onClick={() => setSelected(null)} className="rounded-lg p-1 text-zinc-500 hover:bg-zinc-100" aria-label="Tutup"><X className="h-5 w-5" /></button>
            </div>
            <dl className="mt-5 grid grid-cols-2 gap-3 text-sm">
              <div><dt className="text-xs text-zinc-500">Nominal</dt><dd className="font-semibold text-zinc-900">{rupiah(selected.amount)}</dd></div>
              <div><dt className="text-xs text-zinc-500">Gateway</dt><dd className="text-zinc-900">{selected.provider ? PROVIDER_LABELS[selected.provider] ?? selected.provider : '-'}</dd></div>
              <div><dt className="text-xs text-zinc-500">Sumber</dt><dd className="text-zinc-900">{SOURCE_LABELS[selected.source] ?? selected.source}</dd></div>
              <div><dt className="text-xs text-zinc-500">Toko</dt><dd className="text-zinc-900">{selected.organization?.name ?? '-'}</dd></div>
              <div className="col-span-2"><dt className="text-xs text-zinc-500">Referensi</dt><dd className="break-all font-mono text-xs text-zinc-700">{selected.source_type ? `${selected.source_type} #${selected.source_id}` : '-'} · {selected.event_key}</dd></div>
            </dl>
            <h4 className="mt-6 text-sm font-bold text-zinc-900">Jurnal</h4>
            <table className="mt-2 w-full text-sm">
              <thead className="text-xs text-zinc-500">
                <tr><th className="py-1 text-left font-medium">Akun</th><th className="py-1 text-right font-medium">Debit</th><th className="py-1 text-right font-medium">Kredit</th></tr>
              </thead>
              <tbody className="divide-y divide-zinc-100">
                {selected.lines.map((line) => (
                  <tr key={line.account}>
                    <td className="py-2 text-zinc-700">{accountLabel(line.account)}</td>
                    <td className="py-2 text-right text-zinc-900">{line.amount > 0 ? rupiah(line.amount) : ''}</td>
                    <td className="py-2 text-right text-zinc-900">{line.amount < 0 ? rupiah(-line.amount) : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </aside>
        </div>
      )}
    </div>
  );
}
