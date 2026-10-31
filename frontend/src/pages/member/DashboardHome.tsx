import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, Grid2X2, ReceiptText, Wallet, ArrowDownLeft, ArrowUpRight } from 'lucide-react';
import { getMemberDashboardCards, getWalletOverview, getWalletTransactions, getAutoRenewPreview, getSessionUser } from '@/lib/hellomApi';
import DashboardApps from '@/components/consumer/DashboardApps';
import DashboardBanner from '@/components/consumer/DashboardBanner';

const money = (value: number) => `Rp ${value.toLocaleString('id-ID')}`;
const labels: Record<string, string> = { wallet_topup: 'Top up saldo', subscription_debit: 'Pembayaran langganan', wallet_debit: 'Pembayaran', wallet_credit: 'Saldo masuk', subscription_renewal: 'Perpanjangan langganan', refund: 'Pengembalian dana', manual_topup: 'Top up manual' };
export default function DashboardHome() {
  const [summary, setSummary] = useState({ apps: null as number | null, bills: null as number | null, balance: null as number | null });
  const [activity, setActivity] = useState<Array<{ id: string; title: string; date: string; amount: number; credit: boolean }>>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const user = getSessionUser<{ name?: string }>();
  const load = async () => {
    setLoading(true);
    const results = await Promise.allSettled([getMemberDashboardCards(), getAutoRenewPreview({ days: 30, include_overdue: true, limit: 50 }), getWalletOverview(), getWalletTransactions({ limit: 5 })]);
    const [apps, bills, wallet, transactions] = results;
    setSummary({ apps: apps.status === 'fulfilled' ? apps.value.cards.filter(c => c.entitlement.allowed).length : null, bills: bills.status === 'fulfilled' ? bills.value.summary.total_due_count : null, balance: wallet.status === 'fulfilled' ? wallet.value.wallet.available_balance : null });
    if (transactions.status === 'fulfilled') setActivity(transactions.value.items.map(t => ({ id: String(t.id), title: labels[String(t.type)] || String(t.type).replace(/_/g, ' '), date: String(t.created_at), amount: Number(t.amount), credit: t.direction === 'credit' })));
    setError(results.some(r => r.status === 'rejected') ? 'Sebagian ringkasan belum dapat dimuat.' : '');
    setLoading(false);
  };
  useEffect(() => { void load(); }, []);
  return <div className="space-y-5">
    <header className="flex items-center gap-3"><div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-base font-bold text-amber-800">{user?.name?.slice(0, 1).toUpperCase() || 'H'}</div><div className="min-w-0"><h1 className="text-lg font-bold text-zinc-900 sm:text-xl">Halo, {user?.name?.split(' ')[0] || 'selamat datang'}!</h1><p className="text-xs text-zinc-500 sm:text-sm">Selamat datang kembali. Siap mengembangkan bisnis?</p></div></header>
    <DashboardBanner />
    {error && <div role="alert" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{error} <button onClick={() => void load()} className="font-bold underline">Coba lagi</button></div>}
    <div className="grid grid-cols-3 gap-2 sm:gap-3">
      {[{ label: 'Aplikasi aktif', value: summary.apps, icon: Grid2X2, color: 'bg-emerald-50 text-emerald-700', to: '/dashboard/my-purchases' }, { label: 'Jatuh tempo 30 hari', value: summary.bills, icon: ReceiptText, color: 'bg-amber-50 text-amber-700', to: '/dashboard/payments' }, { label: 'Saldo tersedia', value: summary.balance === null ? null : money(summary.balance), icon: Wallet, color: 'bg-violet-50 text-violet-700', to: '/dashboard/payments' }].map(s => <Link key={s.label} to={s.to} className="min-w-0 rounded-xl border border-zinc-200 bg-white p-2.5 shadow-sm sm:p-4"><span className={`flex h-8 w-8 items-center justify-center rounded-lg ${s.color}`}><s.icon className="h-4 w-4" /></span><p className="mt-2 break-words text-sm font-bold text-zinc-900 [overflow-wrap:anywhere] sm:text-lg">{s.value ?? (loading ? '…' : '—')}</p><p className="mt-1 text-[11px] leading-4 text-zinc-500 sm:text-xs">{s.label}</p></Link>)}
    </div>
    <section><div className="mb-3 flex items-center justify-between gap-3"><h2 className="text-lg font-bold text-zinc-900">Akses Cepat</h2><Link to="/dashboard/my-purchases" className="inline-flex min-h-11 items-center gap-1 text-xs font-semibold text-amber-700">Lihat semua <ArrowRight className="h-4 w-4" /></Link></div><DashboardApps owned compact /></section>
    <section className="rounded-2xl border border-zinc-200 bg-white p-3.5 shadow-sm sm:p-4"><div className="mb-2 flex items-center justify-between gap-3"><h2 className="text-sm font-bold text-zinc-900">Aktivitas Transaksi</h2><Link to="/dashboard/payments" className="inline-flex min-h-10 items-center gap-1 text-xs font-semibold text-amber-700">Lihat semua <ArrowRight className="h-4 w-4" /></Link></div>
      {loading ? <p className="py-3 text-sm text-zinc-500">Memuat aktivitas...</p> : !activity.length ? <p className="py-3 text-sm text-zinc-500">{error ? 'Aktivitas belum tersedia.' : 'Belum ada transaksi. Aktivitas pembayaran Anda akan tampil di sini.'}</p> : <div className="divide-y divide-zinc-100">{activity.map(a => <div key={a.id} className="flex items-center gap-2.5 py-2.5"><span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${a.credit ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>{a.credit ? <ArrowDownLeft className="h-4 w-4" /> : <ArrowUpRight className="h-4 w-4" />}</span><div className="min-w-0 flex-1"><p className="text-xs font-semibold text-zinc-900 sm:text-sm">{a.title}</p><p className="mt-1 text-[11px] text-zinc-500">{new Date(a.date).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}</p></div><span className="text-right text-xs font-semibold text-zinc-700">{a.credit ? '+' : '−'}{money(a.amount)}</span></div>)}</div>}
    </section>
  </div>;
}
