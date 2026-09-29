import { useCallback, useEffect, useState } from 'react';
import { Globe, AlertCircle, Copy, Check, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getLandingBuilderStats, getLandingOnboarding, getSessionUser } from '@/lib/hellomApi';
import type { LandingOnboarding } from '@/lib/hellomApi';
import SalesSummary from './SalesSummary';
import OnboardingChecklist from './OnboardingChecklist';
import OnboardingWizard from './OnboardingWizard';

// The wizard opens by itself once per shop (until the first page is published).
const seenKey = () => `hl_onboarding_seen:${getSessionUser<{ current_organization?: { id?: number } }>()?.current_organization?.id ?? '0'}`;

export default function Overview({ onEdit, onOpenOrders, onOpenProducts, onOpenSettings }: { onEdit: () => void; onOpenOrders: () => void; onOpenProducts: () => void; onOpenSettings: () => void }) {
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [linkCopied, setLinkCopied] = useState(false);
  const [views, setViews] = useState(0);
  const [onboarding, setOnboarding] = useState<LandingOnboarding | null>(null);
  const [wizardOpen, setWizardOpen] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  const loadOnboarding = useCallback(async (autoOpen = false) => {
    try {
      const data = await getLandingOnboarding();
      setOnboarding(data);
      if (autoOpen && !data.checklist.page_published && !localStorage.getItem(seenKey())) {
        setWizardOpen(true);
      }
    } catch (err) {
      setErrorMessage(err instanceof Error ? err.message : 'Ringkasan toko belum bisa dimuat. Coba muat ulang.');
    }
  }, []);

  useEffect(() => {
    setErrorMessage(null);
    void loadOnboarding(true);
    getLandingBuilderStats().then((s) => setViews(s.views_count)).catch(() => undefined);
  }, [loadOnboarding]);

  useEffect(() => {
    if (!toast) return undefined;
    const t = window.setTimeout(() => setToast(null), 3500);
    return () => window.clearTimeout(t);
  }, [toast]);

  const isPublished = !!onboarding?.checklist.page_published;
  const shareUrl = onboarding?.public_url ?? '';

  const closeWizard = () => {
    localStorage.setItem(seenKey(), '1');
    setWizardOpen(false);
  };

  const copyShareLink = async () => {
    if (!shareUrl) return;
    try {
      await navigator.clipboard.writeText(shareUrl);
      setLinkCopied(true);
      window.setTimeout(() => setLinkCopied(false), 2000);
    } catch {
      setToast('Link belum tersalin. Buka halaman lalu salin dari address bar.');
    }
  };

  return (
    <div className="max-w-5xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="min-w-0">
          <h1 className="text-2xl font-bold text-zinc-900">Ringkasan Toko</h1>
          {onboarding ? (
            <p className="truncate text-zinc-600">{isPublished ? shareUrl.replace(/^https?:\/\//, '') : 'Halaman kamu belum diterbitkan.'}</p>
          ) : (
            <div className="mt-1 h-5 w-48 animate-pulse rounded bg-zinc-100" aria-hidden="true" />
          )}
        </div>
        <div className="flex gap-3 w-full md:w-auto">
          {isPublished && (
            <>
              <button
                type="button"
                onClick={() => void copyShareLink()}
                title={shareUrl}
                className={cn(
                  'flex-1 md:flex-none flex min-h-11 items-center justify-center gap-2 px-4 font-medium rounded-lg border transition-colors',
                  linkCopied ? 'bg-green-600 border-green-600 text-white' : 'bg-white border-zinc-200 text-zinc-600 hover:border-zinc-300',
                )}
              >
                {linkCopied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                <span className="sm:hidden">{linkCopied ? 'Tersalin' : 'Salin'}</span>
                <span className="hidden sm:inline">{linkCopied ? 'Tersalin' : 'Salin link'}</span>
              </button>
              <a
                href={shareUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="flex-1 md:flex-none flex min-h-11 items-center justify-center gap-2 px-4 bg-white border border-zinc-200 text-zinc-600 font-medium rounded-lg hover:border-zinc-300 transition-colors"
              >
                <Globe className="w-4 h-4" /> Buka
              </a>
            </>
          )}
          <button
            type="button"
            onClick={isPublished || !onboarding ? onEdit : () => setWizardOpen(true)}
            className="flex-1 md:flex-none flex min-h-11 items-center justify-center gap-2 px-4 bg-black text-white font-bold rounded-lg hover:bg-zinc-800 transition-colors shadow-sm"
          >
            <span className="sm:hidden">{isPublished || !onboarding ? 'Edit' : 'Buat'}</span>
            <span className="hidden sm:inline">{isPublished || !onboarding ? 'Edit halaman' : 'Buat halaman'}</span>
          </button>
        </div>
      </div>

      {errorMessage && (
        <div role="alert" className="flex items-center gap-2 rounded-lg border border-red-100 bg-red-50 p-3 text-sm text-red-700">
          <AlertCircle className="h-4 w-4 shrink-0" /> <span className="flex-1">{errorMessage}</span>
          <button type="button" onClick={() => { setErrorMessage(null); void loadOnboarding(); }} className="inline-flex min-h-11 items-center gap-1 font-semibold underline">
            <RefreshCw className="h-4 w-4" /> Coba lagi
          </button>
        </div>
      )}

      {onboarding ? (
        <OnboardingChecklist data={onboarding} onStartWizard={() => setWizardOpen(true)} onOpenProducts={onOpenProducts} onOpenSettings={onOpenSettings} onNotice={setToast} />
      ) : !errorMessage && (
        <div className="h-48 animate-pulse rounded-2xl border border-zinc-100 bg-zinc-50" aria-hidden="true" />
      )}

      <SalesSummary visitors={views} onOpenOrders={onOpenOrders} onOpenProducts={onOpenProducts} />

      {wizardOpen && onboarding && (
        <OnboardingWizard data={onboarding} onClose={closeWizard} onDone={() => { setToast('Halaman kamu sudah online. Selamat berjualan!'); void loadOnboarding(); }} />
      )}
      {toast && <div role="status" className="fixed inset-x-4 bottom-24 z-[70] mx-auto max-w-sm rounded-2xl bg-zinc-900 px-4 py-3 text-center text-sm text-white shadow-lg md:bottom-6">{toast}</div>}
    </div>
  );
}
