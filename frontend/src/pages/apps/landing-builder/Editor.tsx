import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import {
  AlertTriangle, ArrowLeft, Check, CheckCircle2, ChevronDown, Copy, ExternalLink, Eye, EyeOff, FileStack, History, LayoutList,
  Link2, Loader2, MoreHorizontal, Palette, Plus, Redo2, RefreshCw, Share2, Sparkles, Trash2, Undo2,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { LanguageProvider } from './i18n';
import { useOptionalEditorPreference } from './editorPreference';
import { DEFAULT_PRESET, presetTerms } from './presets';
import type { EditorPreset } from './presets';
import EditorTour from './EditorTour';
import { PropertyPanel } from './components/PropertyPanel';
import { HistoryDialog, PagesDialog, TemplatesDialog } from './components/EditorDialogs';
import type { PageTemplate } from './templates';
import type { Block } from './types';
import { useEditorDocument } from './editor/useEditorDocument';
import type { EditorApi } from './editor/useEditorDocument';
import PhonePreview from './editor/PhonePreview';
import BlockList from './editor/BlockList';
import AddBlockGallery from './editor/AddBlockGallery';
import SocialPanel, { SocialIcon } from './editor/SocialPanel';
import AppearancePanel from './editor/AppearancePanel';
import type { SocialPlatform } from './editor/socialPlatforms';
import Sheet from './editor/Sheet';
import { BLOCK_META } from './editor/blockMeta';
import type { GalleryItem } from './editor/blockMeta';

/**
 * Hellom Page editor, link-in-bio style (Fase 2). Desktop: block list (or the selected block's
 * settings, or the block gallery) next to a live phone preview. Phone: full preview with a
 * bottom bar; list, gallery and settings open as bottom sheets. The preview is the real page
 * (server-rendered), autosave keeps a draft, nothing is public until "Terbitkan".
 */
type Dialog = 'none' | 'templates' | 'history' | 'pages';
type Opener = Dialog | 'design';
type MobileSheet = 'none' | 'list' | 'add' | 'block' | 'social' | 'design';
/** The social icon row in the preview (page.blade.php data-hl-block="__social"). */
const SOCIAL_ID = '__social';

const isTyping = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

export default function Editor() {
  const ed = useEditorDocument();
  const preference = useOptionalEditorPreference();
  const preset = preference?.preset ?? DEFAULT_PRESET;
  const terms = useMemo(() => presetTerms(preset), [preset]);
  const [dialog, setDialog] = useState<Dialog>('none');
  const [panel, setPanel] = useState<'list' | 'add' | 'social' | 'design'>('list');
  const [sheet, setSheet] = useState<MobileSheet>('none');
  const [isMobile, setIsMobile] = useState(() => typeof window !== 'undefined' && window.innerWidth < 1024);
  const [linkCopied, setLinkCopied] = useState(false);
  const { actions, selectedId, setSelectedId } = ed;
  const selected = ed.doc.blocks.find((b) => b.id === selectedId);

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth < 1024);
    window.addEventListener('resize', check);
    return () => window.removeEventListener('resize', check);
  }, []);

  // The block whose sheet is open was deleted (or undone away): close the sheet.
  useEffect(() => {
    if (sheet === 'block' && !selected) setSheet('none');
  }, [sheet, selected]);

  // Undo / redo from the keyboard (text fields keep their own undo).
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (!(e.ctrlKey || e.metaKey) || isTyping(e.target)) return;
      const key = e.key.toLowerCase();
      if (key === 'z' && !e.shiftKey) { e.preventDefault(); actions.undo(); }
      if ((key === 'z' && e.shiftKey) || key === 'y') { e.preventDefault(); actions.redo(); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [actions]);

  const select = useCallback((id: string | null) => {
    if (id === SOCIAL_ID) { // tap on the icon row in the preview
      setSelectedId(null);
      setPanel('social');
      setSheet('social');
      return;
    }
    setSelectedId(id);
    if (id) { setPanel('list'); setSheet('block'); }
  }, [setSelectedId]);

  const add = (item: GalleryItem) => {
    actions.addBlock(item.type, undefined, item.content);
    setPanel('list');
    setSheet('block');
  };

  const copyLink = async (url: string) => {
    try { await navigator.clipboard.writeText(url); } catch { window.prompt('Salin link:', url); }
    setLinkCopied(true);
    window.setTimeout(() => setLinkCopied(false), 2000);
  };

  const viewPage = async () => {
    const tab = window.open('', '_blank'); // opened now: a popup after "await" would be blocked
    const url = await ed.viewUrl();
    if (url && tab) tab.location.href = url;
    else tab?.close();
  };

  const applyTemplate = (template: PageTemplate) => {
    if (ed.doc.blocks.length > 0 && !window.confirm(`Ganti isi halaman dengan template "${template.name}"? Bisa dibatalkan dengan Urungkan.`)) return;
    actions.applyTemplate(template);
    setDialog('none');
  };

  if (ed.loadState !== 'ready' || !ed.page || !ed.site) {
    return (
      <div className="mx-auto max-w-3xl space-y-4 p-4" aria-busy={ed.loadState === 'loading'}>
        {ed.loadState === 'error' ? (
          <div className="rounded-xl border border-red-100 bg-red-50 p-4 text-sm text-red-700">
            <p className="font-semibold">Halaman belum berhasil dimuat.</p>
            <p className="mt-1">Supaya halaman kamu tidak tertimpa, editor dikunci sampai data termuat. {ed.loadError}</p>
            <button type="button" onClick={ed.reload} className="mt-3 inline-flex min-h-11 items-center gap-2 rounded-lg bg-black px-4 text-sm font-semibold text-white"><RefreshCw className="h-4 w-4" /> Coba lagi</button>
          </div>
        ) : (
          <div className="flex items-center gap-2 text-sm text-zinc-500"><Loader2 className="h-4 w-4 animate-spin" /> Memuat halaman…</div>
        )}
      </div>
    );
  }

  const page = ed.page;
  // Page colors as defaults for a block's own colors (Gaya bagian ini).
  const activeTheme = { colors: { backgroundColor: ed.doc.theme.bg?.color ?? ed.doc.theme.background ?? '#ffffff', textColor: ed.doc.theme.text ?? '#18181b' } };
  const openDesign = () => { setSelectedId(null); setPanel('design'); setSheet('design'); };
  const open = (target: Opener) => (target === 'design' ? openDesign() : setDialog(target));
  const onFile = (e: React.ChangeEvent<HTMLInputElement>, field: string, isStyle?: boolean) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (file && selectedId) void ed.uploadFile(file, selectedId, field, isStyle);
  };

  const settingsPanel = selected && (
    <PropertyPanel
      selectedBlock={selected}
      activeTheme={activeTheme}
      updateBlockContent={(id, content) => actions.updateContent(id, content)}
      updateBlockStyles={(id, styles) => actions.updateStyles(id, styles)}
      handleFileUpload={onFile}
    />
  );
  const blockActions = selected && <BlockActions block={selected} onToggleHidden={() => actions.toggleHidden(selected.id)} onDuplicate={() => actions.duplicate(selected.id)} onDelete={() => { if (window.confirm('Hapus bagian ini? Bisa dibatalkan dengan Urungkan.')) actions.remove(selected.id); }} />;
  const list = (
    <BlockList
      blocks={ed.doc.blocks}
      selectedId={selectedId}
      itemWord={preset.terms.items}
      onSelect={select}
      onReorder={actions.reorder}
      onToggleHidden={actions.toggleHidden}
      onDuplicate={actions.duplicate}
      onDelete={actions.remove}
    />
  );
  const empty = <EmptyHint preset={preset} onTemplates={() => setDialog('templates')} />;
  const socialPanel = (
    <SocialPanel social={ed.doc.social} onChange={(patch, key) => actions.setSocial(patch, key)} legacyBlocks={ed.doc.blocks.filter((b) => b.type === 'social')} />
  );
  const socialCount = ed.doc.social.items.filter((i) => i.value.trim() !== '').length;
  const designPanel = (
    <AppearancePanel theme={ed.doc.theme} settings={ed.doc.settings} onTheme={(patch, key) => actions.setTheme(patch, key)} onReplace={actions.replaceTheme} onSettings={(patch) => actions.setSettings(patch)} />
  );
  const preview = (framed: boolean) => (
    <PhonePreview pageId={page.id} document={ed.document} selectedId={selectedId} onSelect={(id) => (id ? select(id) : setSelectedId(null))} framed={framed} />
  );

  return (
    <LanguageProvider overrides={terms} fixedLang="id">
      <div className="flex h-full flex-col bg-zinc-50">
        <TopBar ed={ed} isMobile={isMobile} linkCopied={linkCopied} onCopy={() => void copyLink(page.url)} onView={() => void viewPage()} onDialog={open} />
        <Banners ed={ed} linkCopied={linkCopied} onCopy={(url) => void copyLink(url)} />

        {isMobile ? (
          <>
            <div className="relative min-h-0 flex-1">{preview(false)}</div>
            <nav className="grid grid-cols-4 gap-1 border-t border-zinc-200 bg-white px-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-2" aria-label="Alat editor">
              <BarButton tour="list" icon={<LayoutList className="h-5 w-5" />} label={preset.terms.list} onClick={() => setSheet('list')} />
              <button type="button" data-tour="add" onClick={() => setSheet('add')} className="flex min-h-12 items-center justify-center gap-1.5 rounded-2xl bg-zinc-900 px-2 text-sm font-semibold text-white">
                <Plus className="h-5 w-5 rounded-full bg-yellow-400 p-0.5 text-black" strokeWidth={3} /> Tambah
              </button>
              <BarButton tour="social" icon={<Share2 className="h-5 w-5" />} label="Sosial" onClick={() => setSheet('social')} />
              <BarButton tour="design" icon={<Palette className="h-5 w-5" />} label="Tampilan" onClick={openDesign} />
            </nav>
            {sheet === 'list' && (
              <Sheet title={preset.terms.list} onClose={() => setSheet('none')}>
                {ed.doc.blocks.length === 0 ? empty : list}
              </Sheet>
            )}
            {sheet === 'add' && (
              <Sheet title={preset.terms.add} onClose={() => setSheet('none')} tall>
                <AddBlockGallery preset={preset} onPick={add} />
              </Sheet>
            )}
            {sheet === 'design' && (
              <Sheet title="Tampilan" onClose={() => setSheet('none')} tall>
                {designPanel}
              </Sheet>
            )}
            {sheet === 'social' && (
              <Sheet title="Sosial media" onClose={() => setSheet('none')} tall>
                {socialPanel}
              </Sheet>
            )}
            {sheet === 'block' && selected && (
              <Sheet title={BLOCK_META[selected.type].label} onClose={() => setSheet('none')} actions={blockActions} tall>
                {settingsPanel}
              </Sheet>
            )}
          </>
        ) : (
          <div className={cn('flex min-h-0 flex-1', preset.lead === 'preview' && 'flex-row-reverse')}>
            <aside className={cn('flex w-[420px] shrink-0 flex-col bg-white', preset.lead === 'preview' ? 'border-l' : 'border-r', 'border-zinc-200')}>
              {selected ? (
                <>
                  <PanelHeader title={BLOCK_META[selected.type].label} onBack={() => setSelectedId(null)} actions={blockActions} />
                  <div className="min-h-0 flex-1 overflow-y-auto">{settingsPanel}</div>
                </>
              ) : panel === 'design' ? (
                <>
                  <PanelHeader title="Tampilan" onBack={() => setPanel('list')} />
                  <div className="min-h-0 flex-1 overflow-y-auto p-4">{designPanel}</div>
                </>
              ) : panel === 'social' ? (
                <>
                  <PanelHeader title="Sosial media" onBack={() => setPanel('list')} />
                  <div className="min-h-0 flex-1 overflow-y-auto p-4">{socialPanel}</div>
                </>
              ) : panel === 'add' ? (
                <>
                  <PanelHeader title={preset.terms.add} onBack={() => setPanel('list')} />
                  <div className="min-h-0 flex-1 overflow-y-auto p-4"><AddBlockGallery preset={preset} onPick={add} /></div>
                </>
              ) : (
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                  <button type="button" data-tour="add" onClick={() => setPanel('add')} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl bg-zinc-900 text-sm font-semibold text-white hover:bg-zinc-800">
                    <Plus className="h-5 w-5 rounded-full bg-yellow-400 p-0.5 text-black" strokeWidth={3} /> {preset.terms.add}
                  </button>
                  <button type="button" data-tour="social" onClick={() => setPanel('social')} className="flex min-h-14 w-full items-center gap-3 rounded-2xl border border-zinc-200 bg-white px-3 text-left hover:border-zinc-900">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-yellow-50 text-yellow-700"><Share2 className="h-5 w-5" /></span>
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-semibold text-zinc-900">Sosial media</span>
                      <span className="block truncate text-xs text-zinc-500">{socialCount > 0 ? `${socialCount} akun` : 'Instagram, TikTok, WhatsApp, …'}</span>
                    </span>
                    <span className="flex shrink-0 -space-x-1">
                      {ed.doc.social.items.slice(0, 4).map((i) => (
                        <span key={i.platform} className="flex h-7 w-7 items-center justify-center rounded-full bg-white text-zinc-700 ring-1 ring-zinc-200"><SocialIcon platform={i.platform as SocialPlatform} className="h-4 w-4" /></span>
                      ))}
                    </span>
                  </button>
                  <section data-tour="list" aria-label={preset.terms.list}>
                    <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{preset.terms.list}</h2>
                    {ed.doc.blocks.length === 0 ? empty : list}
                  </section>
                </div>
              )}
            </aside>
            <main className="min-w-0 flex-1 overflow-hidden p-6">{preview(true)}</main>
          </div>
        )}
      </div>

      {dialog === 'templates' && <TemplatesDialog onClose={() => setDialog('none')} onApply={applyTemplate} />}
      {dialog === 'history' && <HistoryDialog pageId={page.id} onClose={() => setDialog('none')} onRestore={async (id, no) => { await ed.restore(id, no); setDialog('none'); }} />}
      {dialog === 'pages' && (
        <PagesDialog site={ed.site} currentPageId={page.id} onClose={() => setDialog('none')} onChanged={async () => { await ed.refreshSite(); }} onOpenPage={(p) => { void ed.switchPage(p); setDialog('none'); }} />
      )}
      {/* Tour after the onboarding question (or "Ulangi tur" in Pengaturan). */}
      {preference?.loaded && preference.preference && !preference.tourDone && dialog === 'none' && sheet === 'none' && (
        <EditorTour key={`${preference.tourRun}-${isMobile ? 'm' : 'd'}`} preset={preset} onFinish={preference.finishTour} />
      )}
    </LanguageProvider>
  );
}

function TopBar({ ed, isMobile, linkCopied, onCopy, onView, onDialog }: {
  ed: EditorApi;
  isMobile: boolean;
  linkCopied: boolean;
  onCopy: () => void;
  onView: () => void;
  onDialog: (target: Opener) => void;
}) {
  const [menu, setMenu] = useState(false);
  const page = ed.page!;
  const status = ed.saveState === 'saving' ? 'Menyimpan…'
    : ed.saveState === 'dirty' ? 'Belum tersimpan'
      : ed.saveState === 'error' ? 'Gagal menyimpan'
        : ed.saveState === 'conflict' ? 'Bentrok'
          : 'Tersimpan';
  // Phones: one row at 360 px (title, status, undo, redo, menu, publish).
  const iconButton = cn('flex h-11 shrink-0 items-center justify-center rounded-xl text-zinc-700 hover:bg-zinc-100 disabled:opacity-30', isMobile ? 'w-10' : 'w-11');
  const textButton = 'flex min-h-11 shrink-0 items-center gap-1.5 rounded-xl px-3 text-sm font-semibold text-zinc-800 hover:bg-zinc-100';

  return (
    <header className={cn('flex items-center gap-1 border-b border-zinc-200 bg-white px-2 py-1.5 lg:px-4', isMobile ? 'flex-nowrap' : 'flex-wrap')}>
      <button type="button" onClick={() => onDialog('pages')} className="flex min-h-11 min-w-0 max-w-[30%] items-center gap-1 rounded-xl px-1.5 text-sm font-semibold text-zinc-900 hover:bg-zinc-100 sm:max-w-[45%] lg:max-w-xs">
        <FileStack className="hidden h-4 w-4 shrink-0 sm:block" /><span className="truncate">{page.title}</span><ChevronDown className="h-4 w-4 shrink-0" />
      </button>
      <span className={cn('flex items-center gap-1 px-1 text-xs', ['error', 'conflict'].includes(ed.saveState) ? 'text-rose-600' : 'text-zinc-500')} aria-live="polite" title={status}>
        {ed.saveState === 'saving' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : ed.saveState === 'saved' ? <Check className="h-3.5 w-3.5" /> : null}
        <span className={isMobile ? 'sr-only' : ''}>{status}</span>
        {!isMobile && page.status === 'published' && page.has_unpublished_changes && ed.saveState === 'saved' && <span className="text-amber-700">· belum diterbitkan</span>}
      </span>
      <button type="button" onClick={ed.actions.undo} disabled={!ed.canUndo} aria-label="Urungkan" title="Urungkan (Ctrl+Z)" className={iconButton}><Undo2 className="h-5 w-5" /></button>
      <button type="button" onClick={ed.actions.redo} disabled={!ed.canRedo} aria-label="Ulangi" title="Ulangi (Ctrl+Shift+Z)" className={iconButton}><Redo2 className="h-5 w-5" /></button>
      <div className="ml-auto flex items-center gap-1">
        {!isMobile && (
          <>
            <button type="button" data-tour="templates" onClick={() => onDialog('templates')} className={textButton}><Sparkles className="h-4 w-4" /> Template</button>
            <button type="button" data-tour="design" onClick={() => onDialog('design')} className={textButton}><Palette className="h-4 w-4" /> Tampilan</button>
            <button type="button" onClick={() => onDialog('history')} aria-label="Riwayat terbit" title="Riwayat terbit" className={iconButton}><History className="h-5 w-5" /></button>
            <button type="button" onClick={onView} className={textButton}><ExternalLink className="h-4 w-4" /> Lihat halaman</button>
            <button type="button" onClick={onCopy} className={textButton}>{linkCopied ? <Check className="h-4 w-4" /> : <Link2 className="h-4 w-4" />} {linkCopied ? 'Tersalin' : 'Salin link'}</button>
          </>
        )}
        {isMobile && (
          <div className="relative">
            <button type="button" onClick={() => setMenu((v) => !v)} aria-label="Menu lainnya" aria-expanded={menu} className={iconButton}><MoreHorizontal className="h-5 w-5" /></button>
            {menu && (
              <>
                <button type="button" aria-label="Tutup menu" className="fixed inset-0 z-30 cursor-default" onClick={() => setMenu(false)} />
                <div role="menu" className="absolute right-0 top-12 z-40 w-52 overflow-hidden rounded-2xl border border-zinc-200 bg-white py-1 shadow-xl">
                  {([
                    [<ExternalLink key="v" className="h-4 w-4" />, 'Lihat halaman', onView],
                    [<Link2 key="c" className="h-4 w-4" />, linkCopied ? 'Tersalin' : 'Salin link', onCopy],
                    [<Sparkles key="t" className="h-4 w-4" />, 'Template', () => onDialog('templates')],
                    [<History key="h" className="h-4 w-4" />, 'Riwayat terbit', () => onDialog('history')],
                  ] as const).map(([icon, label, run]) => (
                    <button key={label} type="button" role="menuitem" onClick={() => { setMenu(false); run(); }} className="flex min-h-11 w-full items-center gap-2 px-4 text-sm text-zinc-800 hover:bg-zinc-50">{icon} {label}</button>
                  ))}
                </div>
              </>
            )}
          </div>
        )}
        <button type="button" data-tour="publish" onClick={() => void ed.publish()} disabled={ed.publishing} className={cn('flex min-h-11 shrink-0 items-center gap-1.5 rounded-xl bg-zinc-900 text-sm font-semibold text-white hover:bg-zinc-800 disabled:opacity-60', isMobile ? 'px-3' : 'px-4')}>
          {ed.publishing && <Loader2 className="h-4 w-4 animate-spin" />} Terbitkan
        </button>
      </div>
    </header>
  );
}

function Banners({ ed, linkCopied, onCopy }: { ed: EditorApi; linkCopied: boolean; onCopy: (url: string) => void }) {
  const notice = ed.notice;
  return (
    <>
      {ed.saveState === 'conflict' && (
        <div className="flex flex-wrap items-center gap-2 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
          <AlertTriangle className="h-4 w-4" /> Halaman ini baru diubah dari tab atau perangkat lain.
          <button type="button" onClick={ed.reload} className="min-h-11 font-semibold underline">Muat versi terbaru</button>
        </div>
      )}
      {notice && (
        <div className={cn('border-b px-4 py-2 text-sm', notice.kind === 'ok' ? 'border-green-100 bg-green-50 text-green-800' : 'border-red-100 bg-red-50 text-red-700')} role="status">
          <div className="flex items-start justify-between gap-2">
            <p className="flex items-center gap-1.5 font-medium">{notice.kind === 'ok' && <CheckCircle2 className="h-4 w-4 shrink-0" />}{notice.text}</p>
            <button type="button" onClick={() => ed.setNotice(null)} className="min-h-11 text-xs underline">Tutup</button>
          </div>
          {notice.url && (
            <div className="mt-1 flex flex-col gap-2 sm:flex-row">
              <input readOnly value={notice.url} onFocus={(e) => e.currentTarget.select()} className="min-h-11 min-w-0 flex-1 rounded-lg border border-green-200 bg-white px-3 text-sm text-zinc-700" />
              <div className="flex gap-2">
                <button type="button" onClick={() => onCopy(notice.url!)} className="inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg bg-black px-3 text-sm font-semibold text-white">
                  {linkCopied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}{linkCopied ? 'Tersalin!' : 'Salin link'}
                </button>
                <a href={notice.url} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 text-sm font-semibold"><ExternalLink className="h-4 w-4" /> Buka</a>
              </div>
            </div>
          )}
        </div>
      )}
    </>
  );
}

function PanelHeader({ title, onBack, actions }: { title: string; onBack: () => void; actions?: ReactNode }) {
  return (
    <div className="flex items-center gap-1 border-b border-zinc-100 px-2 py-1.5">
      <button type="button" onClick={onBack} aria-label="Kembali ke daftar" className="flex h-11 w-11 items-center justify-center rounded-xl text-zinc-700 hover:bg-zinc-100"><ArrowLeft className="h-5 w-5" /></button>
      <h2 className="min-w-0 flex-1 truncate text-base font-bold text-zinc-900">{title}</h2>
      {actions}
    </div>
  );
}

function BlockActions({ block, onToggleHidden, onDuplicate, onDelete }: { block: Block; onToggleHidden: () => void; onDuplicate: () => void; onDelete: () => void }) {
  const button = 'flex h-11 w-11 items-center justify-center rounded-xl text-zinc-600 hover:bg-zinc-100';
  return (
    <div className="flex items-center">
      <button type="button" onClick={onToggleHidden} aria-pressed={!block.hidden} aria-label={block.hidden ? 'Tampilkan' : 'Sembunyikan'} title={block.hidden ? 'Tampilkan' : 'Sembunyikan'} className={button}>
        {block.hidden ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
      </button>
      <button type="button" onClick={onDuplicate} aria-label="Duplikat" title="Duplikat" className={button}><Copy className="h-5 w-5" /></button>
      <button type="button" onClick={onDelete} aria-label="Hapus" title="Hapus" className={cn(button, 'text-red-600 hover:bg-red-50')}><Trash2 className="h-5 w-5" /></button>
    </div>
  );
}

function BarButton({ tour, icon, label, onClick }: { tour: string; icon: ReactNode; label: string; onClick: () => void }) {
  return (
    <button type="button" data-tour={tour} onClick={onClick} className="flex min-h-12 flex-col items-center justify-center gap-0.5 rounded-2xl text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
      {icon}<span className="max-w-full truncate px-1">{label}</span>
    </button>
  );
}

function EmptyHint({ preset, onTemplates }: { preset: EditorPreset; onTemplates: () => void }) {
  return (
    <div className="rounded-2xl border border-dashed border-zinc-300 bg-white p-5 text-center text-sm text-zinc-600">
      <p>Halaman masih kosong. Tekan <strong>{preset.terms.add}</strong> untuk mulai{preset.guided ? ', atau mulai cepat dari template.' : '.'}</p>
      <button type="button" onClick={onTemplates} className="mt-3 inline-flex min-h-11 items-center gap-1.5 rounded-xl border border-zinc-300 px-4 text-sm font-semibold text-zinc-900 hover:bg-zinc-50">
        <Sparkles className="h-4 w-4" /> Pilih template
      </button>
    </div>
  );
}
