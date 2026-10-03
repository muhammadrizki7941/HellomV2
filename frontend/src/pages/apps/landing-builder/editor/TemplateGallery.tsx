import { useEffect, useMemo, useState } from 'react';
import { ArrowLeft, Loader2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getImageUrl, previewPageTemplate } from '@/lib/hellomApi';
import type { PageTemplate, PageTemplateList } from '@/lib/hellomApi';
import { loadPageTemplates } from './pageTemplates';
import { backgroundPreview, fontStack } from './themes';

/**
 * Template gallery (Fase 7.4): grouped by category with "Populer"/"Baru" labels, a live preview in a
 * phone mockup (rendered by the server with the shop's own products), and two ways to apply:
 * style only (content stays) or everything.
 */
export default function TemplateGallery({ onClose, onApply }: { onClose: () => void; onApply: (template: PageTemplate, mode: 'all' | 'style') => void }) {
  const [data, setData] = useState<PageTemplateList | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [category, setCategory] = useState('all');
  const [selected, setSelected] = useState<PageTemplate | null>(null);
  const [preview, setPreview] = useState<{ id: string; html?: string; error?: string } | null>(null);
  const [mobileDetail, setMobileDetail] = useState(false);

  useEffect(() => {
    loadPageTemplates().then((list) => { setData(list); setSelected(list.templates[0] ?? null); })
      .catch((e) => setError(e instanceof Error ? e.message : 'Template belum bisa dimuat'));
  }, []);

  useEffect(() => {
    if (!selected) return;
    let alive = true;
    previewPageTemplate(selected.id)
      .then((r) => alive && setPreview({ id: selected.id, html: r.html }))
      .catch((e) => alive && setPreview({ id: selected.id, error: e instanceof Error ? e.message : 'Pratinjau belum bisa dimuat' }));
    return () => { alive = false; };
  }, [selected]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const shown = useMemo(() => (data?.templates ?? []).filter((t) => category === 'all' || t.category === category), [data, category]);
  const usedCategories = useMemo(() => (data?.categories ?? []).filter((c) => data?.templates.some((t) => t.category === c.key)), [data]);
  const current = preview && selected && preview.id === selected.id ? preview : null;

  const phone = (
    <div className="flex min-h-0 w-full flex-1 justify-center">
      <div className="relative aspect-[9/19] h-full max-h-[640px] max-w-full overflow-hidden rounded-[2.2rem] border-[10px] border-zinc-900 bg-white shadow-xl">
        {current?.html ? (
          <iframe title={`Pratinjau template ${selected?.name ?? ''}`} sandbox="allow-scripts" srcDoc={current.html} className="absolute inset-0 h-full w-full border-0" />
        ) : current?.error ? (
          <p className="p-4 text-sm text-rose-700">{current.error}</p>
        ) : (
          <div className="flex h-full items-center justify-center"><Loader2 className="h-6 w-6 animate-spin text-zinc-400" /></div>
        )}
      </div>
    </div>
  );
  const actions = selected && (
    <div className="grid shrink-0 grid-cols-2 gap-2">
      <button type="button" onClick={() => onApply(selected, 'style')} className="min-h-12 rounded-xl border border-zinc-300 px-3 text-sm font-semibold text-zinc-900 hover:border-zinc-900">Pakai gaya saja</button>
      <button type="button" onClick={() => onApply(selected, 'all')} className="min-h-12 rounded-xl bg-zinc-900 px-3 text-sm font-semibold text-white hover:bg-zinc-800">Pakai semuanya</button>
      <p className="col-span-2 text-xs text-zinc-500">"Gaya saja": warna, background, huruf, tombol, animasi — isi halamanmu tetap. "Semuanya": susunan blok ikut diganti (nama & foto profilmu tetap).</p>
    </div>
  );

  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 sm:items-center sm:p-4" onClick={onClose}>
      <div className="flex h-[92svh] w-full max-w-6xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:h-[88vh] sm:rounded-2xl" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label="Pilih template">
        <header className="flex shrink-0 items-center gap-2 border-b border-zinc-100 px-4 py-2">
          {mobileDetail && (
            <button type="button" onClick={() => setMobileDetail(false)} aria-label="Kembali ke daftar template" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100 lg:hidden"><ArrowLeft className="h-5 w-5" /></button>
          )}
          <h3 className="flex-1 text-base font-bold">
            {mobileDetail && selected ? <><span className="lg:hidden">{selected.name}</span><span className="hidden lg:inline">Pilih template</span></> : 'Pilih template'}
          </h3>
          <button type="button" onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full hover:bg-zinc-100"><X className="h-5 w-5" /></button>
        </header>

        {error && <p className="m-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
        {!data && !error && <div className="flex flex-1 items-center justify-center"><Loader2 className="h-6 w-6 animate-spin text-zinc-400" /></div>}

        {data && (
          <div className="flex min-h-0 flex-1">
            <div className={cn('min-h-0 flex-1 overflow-y-auto p-4', mobileDetail && 'hidden lg:block')}>
              <div className="mb-3 flex gap-1.5 overflow-x-auto pb-1" role="tablist" aria-label="Kategori template">
                {[{ key: 'all', label: 'Semua' }, ...usedCategories].map((c) => (
                  <button key={c.key} type="button" role="tab" aria-selected={category === c.key} onClick={() => setCategory(c.key)}
                    className={cn('min-h-10 shrink-0 rounded-full border px-3 text-sm font-semibold', category === c.key ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 text-zinc-700')}>
                    {c.label}
                  </button>
                ))}
              </div>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {shown.map((t) => <TemplateCard key={t.id} template={t} active={selected?.id === t.id} onSelect={() => { setSelected(t); setMobileDetail(true); }} />)}
              </div>
              <p className="mt-4 text-xs text-zinc-500">Template mengganti draft halaman ini saja — halaman yang sudah terbit tidak berubah sampai kamu menerbitkan lagi. Bisa dibatalkan dengan Urungkan.</p>
            </div>
            <aside className={cn('min-h-0 w-full shrink-0 flex-col gap-3 border-l border-zinc-100 bg-zinc-50 p-4 lg:flex lg:w-[380px]', mobileDetail ? 'flex' : 'hidden')} aria-label="Pratinjau template">
              {selected && <p className="hidden text-sm font-bold text-zinc-900 lg:block">{selected.name}</p>}
              {phone}
              {actions}
            </aside>
          </div>
        )}
      </div>
    </div>
  );
}

function TemplateCard({ template, active, onSelect }: { template: PageTemplate; active: boolean; onSelect: () => void }) {
  const t = template.document.theme;
  const profile = template.document.blocks.find((b) => b.type === 'profile')?.content ?? {};
  const cover = typeof profile.coverUrl === 'string' && profile.coverUrl ? getImageUrl(profile.coverUrl) : null;
  const buttons = template.document.blocks.filter((b) => b.type === 'button').slice(0, 2);
  const radius = { square: '4px', rounded: '12px', pill: '999px' }[t.button?.shape ?? 'rounded'];
  const fill = t.button?.fill ?? 'solid';
  return (
    <button type="button" onClick={onSelect} data-template={template.id} aria-pressed={active}
      className={cn('overflow-hidden rounded-2xl border text-left transition', active ? 'border-zinc-900 ring-2 ring-zinc-900' : 'border-zinc-200 hover:border-zinc-500')}>
      <span className="relative flex h-44 flex-col items-center gap-1.5 overflow-hidden px-3 pb-3" style={{ ...backgroundPreview(t.bg, t.background ?? '#ffffff'), color: t.text }}>
        {cover ? <img src={cover} alt="" className="h-14 w-full shrink-0 object-cover" /> : <span className="h-4 shrink-0" />}
        <span className={cn('text-base font-extrabold leading-tight', cover ? '' : 'mt-3')} style={{ fontFamily: fontStack(t.headingFont) }}>{String(profile.name ?? template.name)}</span>
        {buttons.map((b) => (
          <span key={b.id} className="block w-full truncate px-2 py-1.5 text-center text-[11px] font-bold"
            style={{ borderRadius: radius, background: fill === 'solid' ? t.primary : fill === 'glass' ? 'rgba(255,255,255,.18)' : 'transparent', color: fill === 'solid' ? t.buttonText : t.text, border: `1px solid ${fill === 'solid' ? t.primary : 'currentColor'}` }}>
            {String(b.content.text ?? '')}
          </span>
        ))}
        {template.badge && (
          <span className={cn('absolute left-2 top-2 rounded-full px-2 py-0.5 text-[11px] font-bold', template.badge === 'populer' ? 'bg-amber-400 text-black' : 'bg-emerald-700 text-white')}>
            {template.badge === 'populer' ? 'Populer' : 'Baru'}
          </span>
        )}
      </span>
      <span className="block px-2.5 py-2">
        <span className="block truncate text-sm font-semibold text-zinc-900">{template.name}</span>
        <span className="block truncate text-xs text-zinc-500">{template.category_label}</span>
      </span>
    </button>
  );
}
