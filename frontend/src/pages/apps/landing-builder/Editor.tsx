import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { nanoid } from 'nanoid';
import { AlertTriangle, Check, CheckCircle2, ChevronDown, Copy, ExternalLink, Eye, FileStack, History, Loader2, RefreshCw } from 'lucide-react';
import { arrayMove } from '@dnd-kit/sortable';
import { cn } from '@/lib/utils';
import { THEMES, defaultContent } from './constants';
import { Block, BlockType, BlockStyles, BLOCK_TYPES } from './types';
import { LanguageProvider } from './i18n';
import { useOptionalEditorPreference } from './editorPreference';
import { DEFAULT_PRESET, presetTerms } from './presets';
import EditorTour from './EditorTour';
import {
  ApiError,
  createLandingSitePage,
  getLandingDraft,
  getLandingPreviewLink,
  getLandingSite,
  publishLandingSitePage,
  restoreLandingVersion,
  saveLandingDraft,
  uploadLandingAsset,
} from '@/lib/hellomApi';
import type { LandingDocument, LandingSite, LandingSitePage } from '@/lib/hellomApi';
import { MobileEditor } from './components/MobileEditor';
import { DesktopEditor } from './components/DesktopEditor';
import { SettingsModal } from './components/SettingsModal';
import type { ThemeOptions } from './components/SettingsModal';
import { HistoryDialog, PagesDialog, PreviewDialog, TemplatesDialog } from './components/EditorDialogs';
import type { PageTemplate } from './templates';

/**
 * Hellom Page editor (Fase 4). The page is one JSON document: every change is autosaved
 * as a draft (revision-checked, so another tab cannot silently overwrite it) and nothing
 * reaches the public page until "Terbitkan", which stores a version (see Riwayat).
 */
type SaveState = 'saved' | 'dirty' | 'saving' | 'error' | 'conflict';
const AUTOSAVE_MS = 1500;
const PAGE_KEY = 'hellom_landing_editor_page';

const toBlocks = (doc: LandingDocument): Block[] =>
  (doc.blocks || [])
    .filter((b) => BLOCK_TYPES.includes(b.type as BlockType))
    .map((b) => ({ id: b.id, type: b.type as BlockType, hidden: !!b.hidden, content: (b.content || {}) as Record<string, any>, styles: (b.styles || {}) as BlockStyles }));

export default function LandingBuilder() {
  const [site, setSite] = useState<LandingSite | null>(null);
  const [page, setPage] = useState<LandingSitePage | null>(null);
  const [blocks, setBlocks] = useState<Block[]>([]);
  const [activeThemeId, setActiveThemeId] = useState<string>('industrial');
  const [themeOptions, setThemeOptions] = useState<ThemeOptions>({ font: 'sans', buttonShape: 'rounded', buttonStyle: 'solid' });
  const [pageSettings, setPageSettings] = useState({ whatsappNumber: '', whatsappMessage: 'Halo, saya tertarik dengan produk Anda.', showFloatingWhatsapp: false });
  const [selectedBlockId, setSelectedBlockId] = useState<string | null>(null);
  const [isPreview, setIsPreview] = useState(false);
  // The editor is locked until the draft is loaded (never save defaults over a real page).
  const [loadState, setLoadState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [loadError, setLoadError] = useState<string | null>(null);
  const [loadAttempt, setLoadAttempt] = useState(0);
  const [saveState, setSaveState] = useState<SaveState>('saved');
  const [savedAt, setSavedAt] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ kind: 'ok' | 'error'; text: string; url?: string } | null>(null);
  const [publishing, setPublishing] = useState(false);
  const [dialog, setDialog] = useState<'none' | 'settings' | 'templates' | 'history' | 'pages' | 'preview'>('none');
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [linkCopied, setLinkCopied] = useState(false);
  const [isMobile, setIsMobile] = useState(false);
  const preference = useOptionalEditorPreference();
  const preset = preference?.preset ?? DEFAULT_PRESET;
  const terms = useMemo(() => presetTerms(preset), [preset]);

  const revisionRef = useRef<number | null>(null);
  const loadedRef = useRef(false);          // skip the autosave triggered by loading
  const savingRef = useRef<Promise<void> | null>(null);
  const timerRef = useRef<number | undefined>(undefined);
  const docRef = useRef<LandingDocument | null>(null);

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth < 1024);
    check();
    window.addEventListener('resize', check);
    window.addEventListener('orientationchange', check);
    return () => { window.removeEventListener('resize', check); window.removeEventListener('orientationchange', check); };
  }, []);

  const buildDocument = useCallback((): LandingDocument => ({
    theme: { preset: activeThemeId, ...themeOptions },
    settings: pageSettings,
    blocks: blocks.map((b) => {
      const { styles: _legacy, ...content } = (b.content || {}) as Record<string, unknown>;
      return { id: b.id, type: b.type, hidden: !!b.hidden, content, styles: (b.styles || {}) as Record<string, unknown> };
    }),
  }), [activeThemeId, themeOptions, pageSettings, blocks]);

  const applyDocument = (doc: LandingDocument) => {
    loadedRef.current = false;
    const next = toBlocks(doc);
    setBlocks(next);
    setSelectedBlockId(next[0]?.id ?? null);
    const preset = doc.theme?.preset;
    setActiveThemeId(preset && THEMES.some((t) => t.id === preset) ? preset : 'industrial');
    setThemeOptions({ font: doc.theme?.font ?? 'sans', buttonShape: doc.theme?.buttonShape ?? 'rounded', buttonStyle: doc.theme?.buttonStyle ?? 'solid' });
    setPageSettings((s) => ({ ...s, whatsappNumber: doc.settings?.whatsappNumber ?? '', whatsappMessage: doc.settings?.whatsappMessage ?? s.whatsappMessage, showFloatingWhatsapp: !!doc.settings?.showFloatingWhatsapp }));
  };

  const openPage = useCallback(async (target: LandingSitePage) => {
    const draft = await getLandingDraft(target.id);
    revisionRef.current = draft.revision;
    setPage(draft.page ?? target);
    setSavedAt(draft.saved_at);
    setSaveState('saved');
    applyDocument(draft.document);
    localStorage.setItem(PAGE_KEY, String(target.id));
  }, []);

  const refreshSite = useCallback(async () => {
    const next = await getLandingSite();
    setSite(next);
    setPage((current) => (current ? next.pages.find((p) => p.id === current.id) ?? current : current));
    return next;
  }, []);

  // Load the shop, then the remembered (or home) page's draft.
  useEffect(() => {
    let alive = true;
    (async () => {
      setLoadState('loading');
      setLoadError(null);
      try {
        let current = await getLandingSite();
        if (current.pages.length === 0) {
          try {
            await createLandingSitePage({ title: 'Halaman Utama' });
          } catch (err) {
            // Another load (second tab, React dev double effect) created it first: use that page.
            if (!(err instanceof ApiError && err.code === 'PAGE_QUOTA')) throw err;
          }
          current = await getLandingSite();
        }
        if (!alive) return;
        setSite(current);
        const remembered = Number(localStorage.getItem(PAGE_KEY));
        const target = current.pages.find((p) => p.id === remembered) ?? current.pages.find((p) => p.is_home) ?? current.pages[0];
        await openPage(target);
        if (alive) setLoadState('ready');
      } catch (err) {
        if (!alive) return;
        setLoadError(err instanceof Error ? err.message : 'Halaman belum bisa dimuat');
        setLoadState('error');
      }
    })();
    return () => { alive = false; };
  }, [loadAttempt, openPage]);

  const save = useCallback(async (): Promise<void> => {
    if (!page || loadState !== 'ready') return;
    if (savingRef.current) {
      await savingRef.current; // one save at a time; the next one sends the newest document
    }
    const doc = docRef.current ?? buildDocument();
    setSaveState('saving');
    const run = (async () => {
      try {
        const saved = await saveLandingDraft(page.id, doc, revisionRef.current);
        revisionRef.current = saved.revision;
        setSavedAt(saved.saved_at);
        setSaveState(docRef.current === doc ? 'saved' : 'dirty');
      } catch (err) {
        if (err instanceof ApiError && err.status === 409) {
          setSaveState('conflict');
        } else {
          setSaveState('error');
          setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Draft belum tersimpan' });
        }
        throw err;
      }
    })();
    savingRef.current = run.then(() => undefined, () => undefined).finally(() => { savingRef.current = null; });
    await run;
  }, [page, loadState, buildDocument]);

  // Autosave after edits (debounced).
  useEffect(() => {
    if (loadState !== 'ready') return undefined;
    docRef.current = buildDocument();
    if (!loadedRef.current) {
      loadedRef.current = true;
      return undefined;
    }
    if (saveState === 'conflict') return undefined;
    setSaveState('dirty');
    window.clearTimeout(timerRef.current);
    timerRef.current = window.setTimeout(() => { void save().catch(() => undefined); }, AUTOSAVE_MS);
    return () => window.clearTimeout(timerRef.current);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [blocks, activeThemeId, themeOptions, pageSettings, loadState]);

  // Warn before leaving with unsaved edits.
  useEffect(() => {
    const onLeave = (e: BeforeUnloadEvent) => {
      if (saveState === 'dirty' || saveState === 'saving') {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', onLeave);
    return () => window.removeEventListener('beforeunload', onLeave);
  }, [saveState]);

  const flush = async () => {
    window.clearTimeout(timerRef.current);
    if (saveState === 'dirty' || saveState === 'error') await save();
    else if (savingRef.current) await savingRef.current;
  };

  const handlePublish = async () => {
    if (!page) return;
    setPublishing(true);
    setNotice(null);
    try {
      await flush();
      const result = await publishLandingSitePage(page.id);
      setPage(result.page);
      await refreshSite();
      setNotice({ kind: 'ok', text: result.page.is_live ? 'Halaman terbit! Bagikan link ini:' : 'Terbit, tapi halaman ini melebihi kuota paket kamu sehingga belum tayang.', url: result.page.url });
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Belum bisa diterbitkan' });
    } finally {
      setPublishing(false);
    }
  };

  const openPreview = async () => {
    if (!page) return;
    try {
      await flush();
      const { url } = await getLandingPreviewLink(page.id);
      setPreviewUrl(url);
      setDialog('preview');
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Pratinjau belum bisa dibuka' });
    }
  };

  const switchPage = async (target: LandingSitePage) => {
    try {
      await flush();
      await openPage(target);
      setDialog('none');
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Halaman belum bisa dibuka' });
    }
  };

  const applyTemplate = (template: PageTemplate) => {
    if (blocks.length > 0 && !window.confirm(`Ganti isi draft dengan template "${template.name}"?`)) return;
    const next = template.blocks();
    setBlocks(next);
    setSelectedBlockId(next[0]?.id ?? null);
    setActiveThemeId(template.themeId);
    setThemeOptions(template.options);
    setDialog('none');
  };

  const restore = async (versionId: number, versionNo: number) => {
    if (!page) return;
    try {
      await flush();
      const draft = await restoreLandingVersion(page.id, versionId);
      revisionRef.current = draft.revision;
      setSavedAt(draft.saved_at);
      applyDocument(draft.document);
      setSaveState('saved');
      setDialog('none');
      setNotice({ kind: 'ok', text: `Versi ${versionNo} dikembalikan ke draft. Tekan Terbitkan untuk menayangkannya.` });
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Versi belum bisa dikembalikan' });
    }
  };

  // ── Block operations ──
  const createBlock = (type: BlockType): Block => ({ id: nanoid(10), type, content: structuredClone(defaultContent[type] ?? {}) });
  const addBlock = (type: BlockType) => {
    const nb = createBlock(type);
    setBlocks((prev) => [...prev, nb]);
    setSelectedBlockId(nb.id);
  };
  const addBlockAt = (type: BlockType, index: number) => {
    const nb = createBlock(type);
    setBlocks((prev) => { const next = [...prev]; next.splice(Math.max(0, Math.min(index, next.length)), 0, nb); return next; });
    setSelectedBlockId(nb.id);
  };
  const updateBlockContent = (id: string, content: any) => setBlocks((prev) => prev.map((b) => (b.id === id ? { ...b, content } : b)));
  const updateBlockStyles = (id: string, styles: BlockStyles) => setBlocks((prev) => prev.map((b) => (b.id === id ? { ...b, styles: { ...b.styles, ...styles } } : b)));
  const moveBlock = (index: number, direction: 'up' | 'down') => {
    const target = direction === 'up' ? index - 1 : index + 1;
    setBlocks((prev) => (target < 0 || target >= prev.length ? prev : arrayMove(prev, index, target)));
  };
  const reorderBlocks = (oldIndex: number, newIndex: number) => setBlocks((prev) => arrayMove(prev, oldIndex, newIndex));
  const deleteBlock = (id: string) => {
    setBlocks((prev) => prev.filter((b) => b.id !== id));
    if (selectedBlockId === id) setSelectedBlockId(null);
  };
  const duplicateBlock = (id: string) => {
    setBlocks((prev) => {
      const index = prev.findIndex((b) => b.id === id);
      if (index < 0) return prev;
      const copy: Block = { ...structuredClone(prev[index]), id: nanoid(10) };
      const next = [...prev];
      next.splice(index + 1, 0, copy);
      setSelectedBlockId(copy.id);
      return next;
    });
  };
  const toggleHidden = (id: string) => setBlocks((prev) => prev.map((b) => (b.id === id ? { ...b, hidden: !b.hidden } : b)));

  // Images/PDF are uploaded to the server (WebP), never stored inline.
  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>, fieldName: string, isStyle = false) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    const blockId = selectedBlockId;
    if (!file || !blockId) return;
    if (file.size > 8 * 1024 * 1024) {
      setNotice({ kind: 'error', text: 'File maksimal 8 MB.' });
      return;
    }
    try {
      const { url } = await uploadLandingAsset(file);
      if (isStyle) updateBlockStyles(blockId, { [fieldName]: url } as BlockStyles);
      else setBlocks((prev) => prev.map((b) => (b.id === blockId ? { ...b, content: { ...b.content, [fieldName]: url, ...(fieldName === 'fileUrl' ? { fileName: file.name } : {}) } } : b)));
    } catch (err) {
      setNotice({ kind: 'error', text: err instanceof Error ? err.message : 'Upload gagal' });
    }
  };

  const copyLink = async (url: string) => {
    try { await navigator.clipboard.writeText(url); } catch { window.prompt('Salin link:', url); }
    setLinkCopied(true);
    window.setTimeout(() => setLinkCopied(false), 2000);
  };

  if (loadState !== 'ready' || !page || !site) {
    return (
      <div className="mx-auto max-w-3xl space-y-4 p-4" aria-busy={loadState === 'loading'}>
        {loadState === 'error' ? (
          <div className="rounded-xl border border-red-100 bg-red-50 p-4 text-sm text-red-700">
            <p className="font-semibold">Halaman belum berhasil dimuat.</p>
            <p className="mt-1">Supaya halaman kamu tidak tertimpa, editor dikunci sampai data termuat. {loadError}</p>
            <button type="button" onClick={() => setLoadAttempt((n) => n + 1)} className="mt-3 inline-flex min-h-11 items-center gap-2 rounded-lg bg-black px-4 text-sm font-semibold text-white">
              <RefreshCw className="h-4 w-4" /> Coba lagi
            </button>
          </div>
        ) : (
          <>
            <p className="text-sm text-zinc-500">Memuat halaman kamu…</p>
            <div className="h-12 animate-pulse rounded-xl bg-zinc-200" />
            {[0, 1, 2].map((i) => <div key={i} className="h-16 animate-pulse rounded-xl bg-zinc-100" />)}
          </>
        )}
      </div>
    );
  }

  const activeTheme = THEMES.find((t) => t.id === activeThemeId) || THEMES[0];
  const statusLabel = {
    saved: savedAt ? `Tersimpan ${new Date(savedAt).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}` : 'Tersimpan',
    dirty: 'Belum tersimpan…',
    saving: 'Menyimpan…',
    error: 'Gagal menyimpan',
    conflict: 'Diubah di tempat lain',
  }[saveState];
  const editorProps = {
    blocks, selectedBlockId, setSelectedBlockId, activeTheme, addBlock, updateBlockContent, updateBlockStyles, reorderBlocks, deleteBlock, duplicateBlock, toggleHidden,
    handleFileUpload: (e: React.ChangeEvent<HTMLInputElement>, field: string, isStyle?: boolean) => { void handleFileUpload(e, field, isStyle); },
    isPreview, setIsPreview,
    setShowAiModal: (open: boolean) => setDialog(open ? 'templates' : 'none'),
    setShowSettingsModal: (open: boolean) => setDialog(open ? 'settings' : 'none'),
    onSave: () => { void flush().then(() => setNotice({ kind: 'ok', text: 'Draft tersimpan. Belum tayang sampai kamu tekan Terbitkan.' })).catch(() => undefined); },
    onPublish: () => { void handlePublish(); },
    isSaving: publishing || saveState === 'saving',
    pageSettings,
  };

  return (
    <LanguageProvider overrides={terms}>
      {/* Page bar: which page, autosave state, preview, history, publish result */}
      <div className="mb-2 flex flex-wrap items-center gap-2 px-2 lg:px-0">
        <button type="button" onClick={() => setDialog('pages')} className="flex min-h-11 max-w-[60%] items-center gap-1 rounded-xl border border-zinc-200 bg-white px-3 text-sm font-semibold">
          <FileStack className="h-4 w-4 shrink-0" /><span className="truncate">{page.title}</span><ChevronDown className="h-4 w-4 shrink-0" />
        </button>
        <span className={cn('flex items-center gap-1 text-xs', saveState === 'error' || saveState === 'conflict' ? 'text-rose-600' : 'text-zinc-500')} aria-live="polite">
          {saveState === 'saving' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : saveState === 'saved' ? <Check className="h-3.5 w-3.5" /> : null}
          {statusLabel}
          {page.status === 'published' && page.has_unpublished_changes && saveState === 'saved' && <span className="text-amber-700"> · belum diterbitkan</span>}
        </span>
        <div className="ml-auto flex gap-1">
          <button type="button" onClick={() => void openPreview()} aria-label="Pratinjau" className="flex min-h-11 items-center gap-1 rounded-xl border border-zinc-200 bg-white px-3 text-sm font-semibold"><Eye className="h-4 w-4" /><span className="hidden sm:inline">Pratinjau</span></button>
          <button type="button" onClick={() => setDialog('history')} className="flex min-h-11 items-center gap-1 rounded-xl border border-zinc-200 bg-white px-3 text-sm font-semibold" aria-label="Riwayat terbit"><History className="h-4 w-4" /><span className="hidden sm:inline">Riwayat</span></button>
        </div>
      </div>

      {saveState === 'conflict' && (
        <div className="mx-2 mb-2 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 lg:mx-0">
          <AlertTriangle className="h-4 w-4" /> Halaman ini baru diubah dari tab atau perangkat lain.
          <button type="button" onClick={() => setLoadAttempt((n) => n + 1)} className="min-h-11 font-semibold underline">Muat versi terbaru</button>
        </div>
      )}
      {notice && (
        <div className={cn('mx-2 mb-2 rounded-xl border p-3 text-sm lg:mx-0', notice.kind === 'ok' ? 'border-green-100 bg-green-50 text-green-800' : 'border-red-100 bg-red-50 text-red-700')}>
          <div className="flex items-start justify-between gap-2">
            <p className="flex items-center gap-1.5 font-medium">{notice.kind === 'ok' && <CheckCircle2 className="h-4 w-4 shrink-0" />}{notice.text}</p>
            <button type="button" onClick={() => setNotice(null)} className="text-xs underline">Tutup</button>
          </div>
          {notice.url && (
            <div className="mt-2 flex flex-col gap-2 sm:flex-row">
              <input readOnly value={notice.url} onFocus={(e) => e.currentTarget.select()} className="min-h-11 min-w-0 flex-1 rounded-lg border border-green-200 bg-white px-3 text-sm text-zinc-700" />
              <div className="flex gap-2">
                <button type="button" onClick={() => void copyLink(notice.url!)} className="inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg bg-black px-3 text-sm font-semibold text-white">
                  {linkCopied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}{linkCopied ? 'Tersalin!' : 'Salin link'}
                </button>
                <a href={notice.url} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 text-sm font-semibold"><ExternalLink className="h-4 w-4" /> Buka</a>
              </div>
            </div>
          )}
        </div>
      )}

      {isMobile ? (
        <MobileEditor {...editorProps} moveBlock={moveBlock} />
      ) : (
        <DesktopEditor {...editorProps} activeThemeId={activeThemeId} setActiveThemeId={setActiveThemeId} THEMES={THEMES} addBlockAt={addBlockAt} />
      )}

      <SettingsModal
        isOpen={dialog === 'settings'}
        onClose={() => setDialog('none')}
        settings={pageSettings}
        setSettings={setPageSettings}
        themeId={activeThemeId}
        setThemeId={setActiveThemeId}
        themeOptions={themeOptions}
        setThemeOptions={setThemeOptions}
      />
      {dialog === 'templates' && <TemplatesDialog onClose={() => setDialog('none')} onApply={applyTemplate} />}
      {dialog === 'history' && <HistoryDialog pageId={page.id} onClose={() => setDialog('none')} onRestore={restore} />}
      {dialog === 'preview' && previewUrl && <PreviewDialog url={previewUrl} onClose={() => setDialog('none')} />}
      {dialog === 'pages' && (
        <PagesDialog site={site} currentPageId={page.id} onClose={() => setDialog('none')} onChanged={async () => { await refreshSite(); }} onOpenPage={(p) => void switchPage(p)} />
      )}
      {/* Tour after the onboarding question (or "Ulangi tur" in Pengaturan). */}
      {preference?.loaded && preference.preference && !preference.tourDone && dialog === 'none' && (
        <EditorTour key={`${preference.tourRun}-${isMobile ? 'm' : 'd'}`} preset={preset} onFinish={preference.finishTour} />
      )}
    </LanguageProvider>
  );
}
