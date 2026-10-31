import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Calculator, Layout, ShoppingCart, ArrowRight, Grid2X2 } from 'lucide-react';
import CheckoutModal from '@/components/CheckoutModal';
import { getImageUrl, getMemberDashboardCards } from '@/lib/hellomApi';
import type { MemberDashboardCard } from '@/services/api/member';

export const appPresentation = (slug: string) => ({
  pos: { icon: ShoppingCart, color: 'bg-emerald-50 text-emerald-700', description: 'Kelola penjualan, pesanan, dan laporan bisnis.', path: '/dashboard/apps/pos' },
  landing_builder: { icon: Layout, color: 'bg-amber-50 text-amber-700', description: 'Buat halaman bisnis dan toko online tanpa coding.', path: '/dashboard/apps/landing-builder' },
  hpp: { icon: Calculator, color: 'bg-violet-50 text-violet-700', description: 'Hitung harga pokok dan rencanakan keuntungan.', path: '/dashboard/apps/hpp' },
}[slug] || { icon: Grid2X2, color: 'bg-zinc-100 text-zinc-700', description: 'Alat untuk mendukung bisnis Anda.', path: '' });

export default function DashboardApps({ owned = false, compact = false, promotedOnly = false, search = '' }: { owned?: boolean; compact?: boolean; promotedOnly?: boolean; search?: string }) {
  const [cards, setCards] = useState<MemberDashboardCard[]>([]);
  const [promotedCards, setPromotedCards] = useState<MemberDashboardCard[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selected, setSelected] = useState<MemberDashboardCard | null>(null);
  const load = async () => {
    setLoading(true);
    try {
      const response = await getMemberDashboardCards();
      setCards(response.cards);
      setPromotedCards(response.promoted_cards || []);
      setError('');
    }
    catch (e) { setError(e instanceof Error ? e.message : 'Gagal memuat aplikasi.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { void load(); }, []);
  const sourceCards = promotedOnly ? promotedCards : cards;
  const visible = sourceCards.filter(c => (!owned || c.entitlement.allowed) && c.app.name.toLowerCase().includes(search.toLowerCase()));
  if (loading) return <p role="status" className="py-4 text-sm text-zinc-500">Memuat aplikasi...</p>;
  if (error) return <div role="alert" className="rounded-2xl border border-red-200 p-4 text-sm text-red-700">{error} <button onClick={() => void load()} className="underline">Coba lagi</button></div>;
  if (promotedOnly && !visible.length) return null;
  return <>
    {promotedOnly && <div className="mb-3"><h2 className="text-lg font-bold text-zinc-900">Jelajahi Aplikasi</h2><p className="mt-1 text-xs text-zinc-500">Aplikasi pilihan untuk membantu mengembangkan bisnis Anda.</p></div>}
    <div className={compact ? 'grid grid-cols-2 gap-3 sm:grid-cols-3' : 'grid gap-4 xl:grid-cols-2'}>
      {visible.map(card => {
        const presentation = appPresentation(card.app.slug);
        const Icon = presentation.icon;
        const path = presentation.path;
        const open = card.entitlement.allowed && path;
        const iconUrl = card.app.icon_url ? getImageUrl(card.app.icon_url) : '';
        const oneTimeTarget = card.promotion?.type === 'one_time_product' ? card.promotion : null;
        return <article key={card.app.slug} className={`min-w-0 rounded-2xl border border-zinc-200 bg-white ${compact ? 'p-3' : 'p-4'} shadow-sm`}>
          <div className={compact ? '' : 'flex items-start gap-3'}>
            <div className={`mb-2.5 flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-2xl ${presentation.color}`}>
              {iconUrl ? <img src={iconUrl} alt={`Ikon ${card.app.name}`} className="h-full w-full object-cover" /> : <Icon className="h-6 w-6" />}
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center justify-between gap-2"><h3 className={`font-bold text-zinc-900 ${compact ? 'text-sm' : ''}`}>{card.app.name}</h3>{!compact && <span className={`rounded-full px-2 py-1 text-xs font-semibold ${card.entitlement.allowed ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'}`}>{card.entitlement.allowed ? 'Aktif' : 'Tersedia'}</span>}</div>
              <p className="mt-1 text-xs leading-5 text-zinc-600">{oneTimeTarget ? 'Aplikasi digital dengan akses sekali beli.' : presentation.description}</p>
              {oneTimeTarget && <p className="mt-2 text-xs font-semibold text-zinc-800">Rp {oneTimeTarget.price.toLocaleString('id-ID')} · Sekali beli</p>}
              {!compact && card.entitlement.plan && <p className="mt-2 text-xs text-zinc-500">Paket {card.entitlement.plan.name}</p>}
              {oneTimeTarget ? <Link to={oneTimeTarget.target} className="mt-3 inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-zinc-950 px-3 text-xs font-bold text-amber-300">Lihat &amp; beli <ArrowRight className="h-4 w-4" /></Link>
                : open ? <Link to={path} className="mt-3 inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-amber-400 px-3 text-xs font-bold text-black">Buka <ArrowRight className="h-4 w-4" /></Link>
                  : <button onClick={() => setSelected(card)} className="mt-3 min-h-10 rounded-xl bg-amber-400 px-3 text-xs font-bold text-black">Lihat paket &amp; aktifkan</button>}
            </div>
          </div>
        </article>;
      })}
    </div>
    {!visible.length && !promotedOnly && <p className="rounded-2xl border border-dashed border-zinc-200 p-5 text-sm text-zinc-500">{owned ? 'Belum ada aplikasi aktif. Jelajahi aplikasi untuk memulai.' : 'Tidak ada aplikasi yang cocok.'}</p>}
    {selected && <CheckoutModal isOpen onClose={() => setSelected(null)} appName={selected.app.name} appSlug={selected.app.slug} appIcon={appPresentation(selected.app.slug).icon} context="member" onSuccess={() => { setSelected(null); void load(); }} />}
  </>;
}
