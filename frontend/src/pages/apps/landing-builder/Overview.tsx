import { useEffect, useMemo, useState } from 'react';
import { Globe, AlertCircle, Copy, Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getLandingBuilderPerformance, getLandingBuilderStats, getSessionUser } from '@/lib/hellomApi';
import SalesSummary from './SalesSummary';

export default function Overview({ onEdit, onOpenOrders, onOpenProducts }: { onEdit: () => void; onOpenOrders: () => void; onOpenProducts: () => void }) {
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [linkCopied, setLinkCopied] = useState(false);
  const [stats, setStats] = useState({ published_count: 0, views_count: 0, first_published_page: null as null | { id: number; title: string; slug: string } });
  const [performance, setPerformance] = useState({ total_pages: 0, total_views: 0, average_views_per_page: 0, top_page: null as null | { id: number; title: string; slug: string; status: string; views_count: number } });

  useEffect(() => {
    const loadOverview = async () => {
      setErrorMessage(null);
      try {
        const [statsResult, perfResult] = await Promise.all([
          getLandingBuilderStats(),
          getLandingBuilderPerformance(),
        ]);

        setStats(statsResult);
        setPerformance(perfResult.summary);
      } catch (loadError) {
        const message = loadError instanceof Error ? loadError.message : 'Gagal memuat landing overview';
        setErrorMessage(message);
      }
    };

    void loadOverview();
  }, []);

  const orgSlug = getSessionUser<{ current_organization?: { slug?: string } }>()?.current_organization?.slug;
  const openPublicLink = useMemo(() => {
    if (!orgSlug || (!performance.top_page?.slug && !stats.first_published_page?.slug)) {
      return '#';
    }
    return `/${orgSlug}`;
  }, [orgSlug, performance.top_page?.slug, stats.first_published_page?.slug]);

  const isPublished = openPublicLink !== '#';
  const shareUrl = isPublished ? `${window.location.origin}${openPublicLink}` : '';

  const copyShareLink = async () => {
    if (!shareUrl) return;
    try {
      await navigator.clipboard.writeText(shareUrl);
    } catch {
      const textarea = document.createElement('textarea');
      textarea.value = shareUrl;
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.appendChild(textarea);
      textarea.focus();
      textarea.select();
      try { document.execCommand('copy'); } catch { /* ignore */ }
      document.body.removeChild(textarea);
    }
    setLinkCopied(true);
    window.setTimeout(() => setLinkCopied(false), 2000);
  };

  return (
    <div className="max-w-5xl mx-auto space-y-8">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Ringkasan Toko</h1>
          <p className="text-zinc-600">Penjualan, saldo, dan pesanan halaman Hellom kamu.</p>
        </div>
        <div className="flex gap-3 w-full md:w-auto">
          {isPublished && (
            <button
              onClick={() => void copyShareLink()}
              title={shareUrl}
              className={cn(
                'flex-1 md:flex-none flex items-center justify-center gap-2 px-4 py-2 font-medium rounded-lg border transition-colors',
                linkCopied
                  ? 'bg-green-600 border-green-600 text-white'
                  : 'bg-white border-zinc-200 text-zinc-600 hover:border-zinc-300',
              )}
            >
              {linkCopied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
              <span className="hidden sm:inline">{linkCopied ? 'Tersalin!' : 'Salin Link'}</span>
              <span className="sm:hidden">{linkCopied ? 'Tersalin' : 'Salin'}</span>
            </button>
          )}
          <a
            href={openPublicLink}
            target="_blank"
            className="flex-1 md:flex-none flex items-center justify-center gap-2 px-4 py-2 bg-white border border-zinc-200 text-zinc-600 font-medium rounded-lg hover:border-zinc-300 transition-colors"
          >
            <Globe className="w-4 h-4" /> <span className="hidden sm:inline">Buka Halaman</span><span className="sm:hidden">Buka</span>
          </a>
          <button
            onClick={onEdit}
            className="flex-1 md:flex-none flex items-center justify-center gap-2 px-4 py-2 bg-black text-white font-bold rounded-lg hover:bg-zinc-800 transition-colors shadow-sm"
          >
            Edit Halaman
          </button>
        </div>
      </div>

      {errorMessage && (
        <div className="p-3 rounded-lg bg-red-50 border border-red-100 text-sm text-red-600 flex items-center gap-2">
          <AlertCircle className="w-4 h-4" /> {errorMessage}
        </div>
      )}

      {/* Sales (real data; page traffic stats come with Fase 4) */}
      <SalesSummary visitors={stats.views_count} onOpenOrders={onOpenOrders} onOpenProducts={onOpenProducts} />
    </div>
  );
}
