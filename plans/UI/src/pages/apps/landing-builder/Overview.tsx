import { useEffect, useMemo, useState } from 'react';
import { BarChart3, Globe, ArrowUpRight, Users, MousePointer2, AlertCircle, Copy, Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getLandingBuilderPageStats, getLandingBuilderPerformance, getLandingBuilderStats, getSessionUser } from '@/lib/hellomApi';

export default function Overview({ onEdit }: { onEdit: () => void }) {
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [linkCopied, setLinkCopied] = useState(false);
  const [stats, setStats] = useState({ published_count: 0, views_count: 0, first_published_page: null as null | { id: number; title: string; slug: string } });
  const [performance, setPerformance] = useState({ total_pages: 0, total_views: 0, average_views_per_page: 0, top_page: null as null | { id: number; title: string; slug: string; status: string; views_count: number } });
  const [pageSeries, setPageSeries] = useState<Array<{ views_count: number }>>([]);

  useEffect(() => {
    const loadOverview = async () => {
      setErrorMessage(null);
      try {
        const [statsResult, perfResult, pageResult] = await Promise.all([
          getLandingBuilderStats(),
          getLandingBuilderPerformance(),
          getLandingBuilderPageStats(),
        ]);

        setStats(statsResult);
        setPerformance(perfResult.summary);
        setPageSeries(pageResult.items || []);
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

  const trendSeries = pageSeries.slice(0, 15);
  const maxViews = Math.max(1, ...trendSeries.map((row) => row.views_count || 0));

  return (
    <div className="max-w-5xl mx-auto space-y-8">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-zinc-900">Landing Page Overview</h1>
          <p className="text-zinc-600">Pantau performa halaman landing Anda.</p>
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

      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="p-6 bg-white rounded-xl border border-zinc-200 shadow-sm">
          <div className="flex items-center justify-between mb-4">
            <div className="p-2 bg-yellow-50 rounded-lg">
              <Users className="w-5 h-5 text-yellow-600" />
            </div>
            <span className="flex items-center text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full">
              <ArrowUpRight className="w-3 h-3 mr-1" /> +12%
            </span>
          </div>
          <p className="text-sm text-zinc-500 font-medium">Total Pengunjung</p>
          <h3 className="text-3xl font-bold text-zinc-900 mt-1">{stats.views_count.toLocaleString('id-ID')}</h3>
        </div>

        <div className="p-6 bg-white rounded-xl border border-zinc-200 shadow-sm">
          <div className="flex items-center justify-between mb-4">
            <div className="p-2 bg-zinc-100 rounded-lg">
              <MousePointer2 className="w-5 h-5 text-zinc-600" />
            </div>
            <span className="flex items-center text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full">
              <ArrowUpRight className="w-3 h-3 mr-1" /> +5.4%
            </span>
          </div>
          <p className="text-sm text-zinc-500 font-medium">Total Klik CTA</p>
          <h3 className="text-3xl font-bold text-zinc-900 mt-1">{performance.top_page?.views_count?.toLocaleString('id-ID') || 0}</h3>
        </div>

        <div className="p-6 bg-white rounded-xl border border-zinc-200 shadow-sm">
          <div className="flex items-center justify-between mb-4">
            <div className="p-2 bg-zinc-100 rounded-lg">
              <BarChart3 className="w-5 h-5 text-zinc-600" />
            </div>
            <span className="flex items-center text-xs font-bold text-zinc-500 bg-zinc-50 px-2 py-1 rounded-full">
              0%
            </span>
          </div>
          <p className="text-sm text-zinc-500 font-medium">Konversi</p>
          <h3 className="text-3xl font-bold text-zinc-900 mt-1">{Math.min(100, Math.round((performance.average_views_per_page || 0) * 10) / 10)}%</h3>
        </div>
      </div>

      {/* Recent Activity / Placeholder Chart */}
      <div className="bg-white rounded-xl border border-zinc-200 shadow-sm p-6">
        <h3 className="text-lg font-bold text-zinc-900 mb-6">Traffic 30 Hari Terakhir</h3>
        <div className="h-64 flex items-end justify-between gap-2 px-4">
          {(trendSeries.length > 0 ? trendSeries : [{ views_count: 0 }]).map((row, i) => {
            const h = Math.max(8, Math.round(((row.views_count || 0) / maxViews) * 100));
            return (
            <div key={i} className="w-full bg-yellow-400/20 hover:bg-yellow-400 rounded-t-sm transition-colors relative group" style={{ height: `${h}%` }}>
              <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 bg-black text-white text-xs rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap">
                {(row.views_count || 0).toLocaleString('id-ID')} views
              </div>
            </div>
          )})}
        </div>
        <div className="flex justify-between mt-4 text-xs text-zinc-400 font-medium uppercase tracking-wider">
          <span>1 Mar</span>
          <span>15 Mar</span>
          <span>30 Mar</span>
        </div>
      </div>
    </div>
  );
}
