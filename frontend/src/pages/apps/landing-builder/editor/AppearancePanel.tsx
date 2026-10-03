import { useState } from 'react';
import type { ReactNode } from 'react';
import { Check, ImagePlus, Loader2, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getImageUrl, uploadLandingAsset } from '@/lib/hellomApi';
import type { LandingBackground, LandingButtonStyle, LandingTheme } from '@/lib/hellomApi';
import { FONTS, FONT_FACES_CSS } from './fonts';
import { THEME_PRESETS, backgroundPreview, fontStack } from './themes';
import type { EditorSettings } from './useEditorDocument';

/**
 * Tampilan (Fase 5): ready-made looks, background, fonts, buttons, colors and the floating
 * WhatsApp button. Changes show at once in the phone preview (the real page).
 */
type Section = 'tema' | 'background' | 'huruf' | 'tombol' | 'warna' | 'whatsapp';

const GRADIENTS: Array<[string, string, string?]> = [
  ['#fce7f3', '#e0e7ff'], ['#fde68a', '#fca5a5'], ['#a5f3fc', '#f0abfc', '#fde68a'], ['#0f172a', '#7c3aed', '#db2777'],
  ['#064e3b', '#10b981'], ['#1e3a8a', '#06b6d4'], ['#fff7ed', '#fed7aa'], ['#111827', '#374151'],
];
const PATTERNS: Array<[NonNullable<LandingBackground['pattern']>, string]> = [['dots', 'Titik'], ['grid', 'Kotak'], ['diagonal', 'Garis miring'], ['checks', 'Catur'], ['waves', 'Ombak'], ['plus', 'Plus']];
const ANIMATIONS: Array<[NonNullable<LandingBackground['animation']>, string]> = [['aurora', 'Gradien bergerak'], ['blobs', 'Gelembung warna'], ['particles', 'Partikel ringan']];

export default function AppearancePanel({ theme, settings, onTheme, onReplace, onSettings }: {
  theme: LandingTheme;
  settings: EditorSettings;
  onTheme: (patch: Partial<LandingTheme>, key?: string) => void;
  onReplace: (theme: LandingTheme) => void;
  onSettings: (patch: Partial<EditorSettings>) => void;
}) {
  const [open, setOpen] = useState<Section>('tema');
  const bg = theme.bg ?? { type: 'solid', color: theme.background ?? '#ffffff' };
  const button = theme.button ?? {};
  const setBg = (patch: Partial<LandingBackground>, key = 'theme:bg') => onTheme({ bg: { ...bg, ...patch } }, key);
  const setButton = (patch: Partial<LandingButtonStyle>) => onTheme({ button: { ...button, ...patch } }, 'theme:button');
  const page = theme.background ?? '#ffffff';

  return (
    <div className="space-y-3">
      <style>{FONT_FACES_CSS}</style>

      <Group id="tema" open={open} onOpen={setOpen} title="Tema siap pakai" hint="Sekali ketuk: background, huruf, dan tombol">
        <div className="grid grid-cols-2 gap-2">
          {THEME_PRESETS.map((preset) => {
            const t = preset.theme;
            const active = theme.preset === preset.id;
            return (
              <button key={preset.id} type="button" data-theme={preset.id} onClick={() => onReplace({ ...t, preset: preset.id })}
                className={cn('overflow-hidden rounded-2xl border text-left transition', active ? 'border-zinc-900 ring-2 ring-zinc-900' : 'border-zinc-200 hover:border-zinc-500')}>
                <span className="flex h-28 flex-col items-center justify-center gap-1.5 px-3" style={{ ...backgroundPreview(t.bg, t.background ?? '#ffffff'), color: t.text }}>
                  <span className="text-base font-extrabold leading-none" style={{ fontFamily: fontStack(t.headingFont) }}>Toko Kamu</span>
                  {[0, 1].map((i) => <ButtonSample key={i} theme={t} />)}
                </span>
                <span className="flex items-center gap-1 px-2.5 py-2">
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold text-zinc-900">{preset.name}</span>
                    <span className="block truncate text-xs text-zinc-500">{preset.note}</span>
                  </span>
                  {active && <Check className="h-4 w-4 shrink-0 text-zinc-900" />}
                </span>
              </button>
            );
          })}
        </div>
      </Group>

      <Group id="background" open={open} onOpen={setOpen} title="Background" hint={{ solid: 'Warna polos', gradient: 'Gradien', image: 'Gambar', pattern: 'Pola', animated: 'Animasi' }[bg.type ?? 'solid']}>
        <Segmented value={bg.type ?? 'solid'} onChange={(type) => setBg({ type }, 'theme:bg:type')}
          options={[['solid', 'Warna'], ['gradient', 'Gradien'], ['image', 'Gambar'], ['pattern', 'Pola'], ['animated', 'Animasi']]} />
        {(bg.type ?? 'solid') === 'solid' && <ColorField label="Warna background" value={bg.color ?? page} onChange={(color) => onTheme({ bg: { ...bg, color }, background: color }, 'theme:bg:color')} />}
        {(bg.type === 'gradient' || bg.type === 'animated') && (
          <>
            {bg.type === 'animated' && (
              <Segmented value={bg.animation ?? 'aurora'} onChange={(animation) => setBg({ animation })} options={ANIMATIONS} />
            )}
            <div className="grid grid-cols-4 gap-2" aria-label="Gradien siap pakai">
              {GRADIENTS.map(([from, to, via]) => (
                <button key={`${from}${to}${via ?? ''}`} type="button" aria-label={`Gradien ${from} ke ${to}`} onClick={() => setBg({ from, to, via })}
                  className="h-10 rounded-xl border border-zinc-200" style={{ backgroundImage: `linear-gradient(135deg,${[from, via, to].filter(Boolean).join(',')})` }} />
              ))}
            </div>
            <div className="grid grid-cols-3 gap-2">
              <ColorField label="Warna 1" value={bg.from ?? page} onChange={(from) => setBg({ from })} />
              <ColorField label="Tengah" value={bg.via ?? ''} optional onChange={(via) => setBg({ via: via || undefined })} />
              <ColorField label="Warna 2" value={bg.to ?? page} onChange={(to) => setBg({ to })} />
            </div>
            <Slider label={`Arah ${bg.angle ?? 160}°`} min={0} max={360} step={15} value={bg.angle ?? 160} onChange={(angle) => setBg({ angle })} />
            {bg.type === 'animated' && <ColorField label="Warna dasar (dipakai saat animasi dimatikan)" value={bg.color ?? page} onChange={(color) => setBg({ color })} />}
            {bg.type === 'animated' && <p className="text-xs text-zinc-500">Animasi ringan, otomatis berhenti di HP yang mengaktifkan "kurangi gerakan".</p>}
          </>
        )}
        {bg.type === 'image' && <ImageBackground bg={bg} setBg={setBg} />}
        {bg.type === 'pattern' && (
          <>
            <div className="grid grid-cols-3 gap-2">
              {PATTERNS.map(([key, label]) => (
                <button key={key} type="button" onClick={() => setBg({ pattern: key })} aria-pressed={(bg.pattern ?? 'dots') === key}
                  className={cn('min-h-11 rounded-xl border text-sm font-medium', (bg.pattern ?? 'dots') === key ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 text-zinc-700')}>
                  {label}
                </button>
              ))}
            </div>
            <div className="grid grid-cols-2 gap-2">
              <ColorField label="Warna dasar" value={bg.color ?? page} onChange={(color) => setBg({ color })} />
              <ColorField label="Warna pola" value={bg.patternColor ?? '#000000'} onChange={(patternColor) => setBg({ patternColor })} />
            </div>
            <Slider label={`Kepekatan pola ${Math.round((bg.patternOpacity ?? 0.12) * 100)}%`} min={0.03} max={0.5} step={0.01} value={bg.patternOpacity ?? 0.12} onChange={(patternOpacity) => setBg({ patternOpacity })} />
          </>
        )}
      </Group>

      <Group id="huruf" open={open} onOpen={setOpen} title="Huruf" hint={`${FONTS.find((f) => f.id === (theme.headingFont ?? 'sans'))?.label} / ${FONTS.find((f) => f.id === (theme.bodyFont ?? 'sans'))?.label}`}>
        <FontPicker label="Judul" value={theme.headingFont ?? 'sans'} onChange={(headingFont) => onTheme({ headingFont })} heading />
        <FontPicker label="Isi" value={theme.bodyFont ?? 'sans'} onChange={(bodyFont) => onTheme({ bodyFont })} />
      </Group>

      <Group id="tombol" open={open} onOpen={setOpen} title="Tombol" hint="Bentuk, isi, bayangan, efek">
        <div className="flex justify-center rounded-2xl p-4" style={{ ...backgroundPreview(bg, page), color: theme.text }}>
          <ButtonSample theme={theme} wide />
        </div>
        <Labeled label="Bentuk"><Segmented value={button.shape ?? 'rounded'} onChange={(shape) => setButton({ shape })} options={[['square', 'Kotak'], ['rounded', 'Rounded'], ['pill', 'Pill']]} /></Labeled>
        <Labeled label="Isi"><Segmented value={button.fill ?? 'solid'} onChange={(fill) => setButton({ fill })} options={[['solid', 'Solid'], ['outline', 'Garis'], ['glass', 'Kaca']]} /></Labeled>
        {(button.fill === 'outline' || button.shadow === 'hard') && (
          <Slider label={`Tebal garis ${button.borderWidth ?? 2} px`} min={1} max={4} step={1} value={button.borderWidth ?? 2} onChange={(borderWidth) => setButton({ borderWidth })} />
        )}
        <Labeled label="Bayangan"><Segmented value={button.shadow ?? 'none'} onChange={(shadow) => setButton({ shadow })} options={[['none', 'Tanpa'], ['soft', 'Lembut'], ['hard', 'Tegas']]} /></Labeled>
        <Labeled label="Efek saat disentuh"><Segmented value={button.hover ?? 'none'} onChange={(hover) => setButton({ hover })} options={[['none', 'Tanpa'], ['lift', 'Naik'], ['grow', 'Besar'], ['shine', 'Kilau']]} /></Labeled>
        <p className="text-xs text-zinc-500">Satu tombol bisa punya gaya sendiri, ikon, atau jadi tombol unggulan — atur di pengaturan tombolnya.</p>
      </Group>

      <Group id="warna" open={open} onOpen={setOpen} title="Warna" hint="Warna utama, teks tombol, teks">
        <div className="grid grid-cols-3 gap-2">
          <ColorField label="Utama" value={theme.primary ?? '#18181b'} onChange={(primary) => onTheme({ primary }, 'theme:primary')} />
          <ColorField label="Teks tombol" value={theme.buttonText ?? '#ffffff'} onChange={(buttonText) => onTheme({ buttonText }, 'theme:buttonText')} />
          <ColorField label="Teks" value={theme.text ?? '#18181b'} onChange={(text) => onTheme({ text }, 'theme:text')} />
        </div>
        <p className="text-xs text-zinc-500">Kalau teks sulit dibaca di atas background, warnanya otomatis disesuaikan supaya tetap terbaca.</p>
      </Group>

      <Group id="whatsapp" open={open} onOpen={setOpen} title="Tombol WhatsApp mengambang" hint={settings.showFloatingWhatsapp ? 'Aktif' : 'Nonaktif'}>
        <label className="flex min-h-11 items-center gap-3 text-sm font-medium text-zinc-800">
          <input type="checkbox" className="h-5 w-5" checked={settings.showFloatingWhatsapp} onChange={(e) => onSettings({ showFloatingWhatsapp: e.target.checked })} />
          Tampilkan tombol WhatsApp di pojok halaman
        </label>
        <label className="block text-sm font-medium text-zinc-800">Nomor WhatsApp
          <input type="tel" inputMode="tel" value={settings.whatsappNumber} onChange={(e) => onSettings({ whatsappNumber: e.target.value })} placeholder="08123456789"
            className="mt-1 min-h-11 w-full rounded-lg border border-zinc-300 px-3 text-base outline-none focus:border-zinc-900" />
        </label>
        <label className="block text-sm font-medium text-zinc-800">Pesan pembuka
          <textarea rows={2} value={settings.whatsappMessage} onChange={(e) => onSettings({ whatsappMessage: e.target.value })}
            className="mt-1 w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 text-base outline-none focus:border-zinc-900" />
        </label>
        <p className="text-xs text-zinc-500">Nomor ini juga dipakai blok WhatsApp yang nomornya dikosongkan.</p>
      </Group>
    </div>
  );
}

/** A button drawn like the page will (approximation for the panel). */
function ButtonSample({ theme, wide = false }: { theme: LandingTheme; wide?: boolean }) {
  const b = theme.button ?? {};
  const radius = { square: 4, rounded: 12, pill: 999 }[b.shape ?? 'rounded'];
  const dark = (theme.text ?? '#000').toLowerCase().startsWith('#f');
  const style = b.fill === 'outline'
    ? { background: 'transparent', color: theme.primary, border: `${b.borderWidth ?? 2}px solid ${theme.primary}` }
    : b.fill === 'glass'
      ? { background: dark ? 'rgba(255,255,255,.16)' : 'rgba(255,255,255,.6)', color: theme.text, border: `1px solid ${dark ? 'rgba(255,255,255,.3)' : 'rgba(0,0,0,.08)'}` }
      : { background: theme.primary, color: theme.buttonText, border: `${b.borderWidth ?? 2}px solid ${b.shadow === 'hard' ? (dark ? '#fff' : '#000') : theme.primary}` };
  const shadow = b.shadow === 'hard' ? `${(b.borderWidth ?? 2) + 2}px ${(b.borderWidth ?? 2) + 2}px 0 ${dark ? '#fff' : '#000'}` : b.shadow === 'soft' ? '0 6px 16px rgba(0,0,0,.18)' : 'none';
  return (
    <span className={cn('block text-center font-bold', wide ? 'w-full max-w-64 py-3 text-sm' : 'w-4/5 py-1 text-[10px]')}
      style={{ ...style, borderRadius: radius, boxShadow: shadow, fontFamily: fontStack(theme.bodyFont) }}>
      {wide ? 'Contoh tombol' : 'Link saya'}
    </span>
  );
}

function ImageBackground({ bg, setBg }: { bg: LandingBackground; setBg: (patch: Partial<LandingBackground>, key?: string) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const upload = async (file: File | undefined) => {
    if (!file) return;
    setBusy(true);
    setError(null);
    try {
      const { url } = await uploadLandingAsset(file);
      setBg({ image: url, overlay: bg.overlay ?? 0.35 }, 'theme:bg:image');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Upload gagal');
    } finally {
      setBusy(false);
    }
  };
  return (
    <>
      {bg.image ? (
        <div className="relative h-32 overflow-hidden rounded-xl border border-zinc-200 bg-cover bg-center" style={{ backgroundImage: `url("${getImageUrl(bg.image)}")` }}>
          <div className="absolute inset-0" style={{ background: `rgba(0,0,0,${bg.overlay ?? 0.35})` }} />
          <button type="button" onClick={() => setBg({ image: undefined })} aria-label="Hapus gambar background" className="absolute right-2 top-2 flex h-10 w-10 items-center justify-center rounded-lg bg-white/90 text-red-600"><Trash2 className="h-4 w-4" /></button>
        </div>
      ) : null}
      <label className="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 text-sm font-semibold text-zinc-700 hover:border-zinc-900">
        {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <ImagePlus className="h-4 w-4" />} {bg.image ? 'Ganti gambar' : 'Unggah gambar'}
        <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => { void upload(e.target.files?.[0]); e.target.value = ''; }} />
      </label>
      {error && <p className="text-sm text-red-600">{error}</p>}
      <Slider label={`Gelapkan ${Math.round((bg.overlay ?? 0.35) * 100)}%`} min={0} max={0.8} step={0.05} value={bg.overlay ?? 0.35} onChange={(overlay) => setBg({ overlay })} />
      <Slider label={`Blur ${bg.blur ?? 0} px`} min={0} max={20} step={1} value={bg.blur ?? 0} onChange={(blur) => setBg({ blur })} />
      <Labeled label="Posisi gambar"><Segmented value={bg.position ?? 'center'} onChange={(position) => setBg({ position })} options={[['top', 'Atas'], ['center', 'Tengah'], ['bottom', 'Bawah']]} /></Labeled>
    </>
  );
}

function FontPicker({ label, value, onChange, heading = false }: { label: string; value: string; onChange: (id: string) => void; heading?: boolean }) {
  return (
    <fieldset>
      <legend className="mb-1.5 text-sm font-medium text-zinc-800">{label}</legend>
      <div className="grid max-h-56 grid-cols-2 gap-2 overflow-y-auto pr-1" role="radiogroup" aria-label={`Huruf ${label.toLowerCase()}`}>
        {FONTS.map((font) => (
          <button key={font.id} type="button" role="radio" aria-checked={value === font.id} data-font={font.id} onClick={() => onChange(font.id)}
            className={cn('min-h-14 rounded-xl border px-3 py-2 text-left', value === font.id ? 'border-zinc-900 ring-1 ring-zinc-900' : 'border-zinc-200 hover:border-zinc-500')}>
            <span className={cn('block truncate text-zinc-900', heading ? 'text-lg font-bold' : 'text-base')} style={{ fontFamily: font.stack }}>{heading ? 'Toko Kamu' : 'Halo, selamat datang'}</span>
            <span className="block truncate text-[11px] text-zinc-500">{font.label}</span>
          </button>
        ))}
      </div>
    </fieldset>
  );
}

function Group({ id, open, onOpen, title, hint, children }: { id: Section; open: Section; onOpen: (id: Section) => void; title: string; hint?: string; children: ReactNode }) {
  const expanded = open === id;
  return (
    <section className="rounded-2xl border border-zinc-200 bg-white" data-section={id}>
      <button type="button" aria-expanded={expanded} onClick={() => onOpen(id)} className="flex min-h-14 w-full items-center justify-between gap-3 px-4 text-left">
        <span>
          <span className="block text-sm font-bold text-zinc-900">{title}</span>
          {hint && <span className="block text-xs text-zinc-500">{hint}</span>}
        </span>
        <span aria-hidden="true" className={cn('text-zinc-400 transition-transform', expanded && 'rotate-180')}>▾</span>
      </button>
      {expanded && <div className="space-y-4 border-t border-zinc-100 p-4">{children}</div>}
    </section>
  );
}

function Labeled({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <p className="mb-1.5 text-sm font-medium text-zinc-800">{label}</p>
      {children}
    </div>
  );
}

function Segmented<T extends string>({ value, onChange, options }: { value: T; onChange: (value: T) => void; options: Array<[T, string]> }) {
  return (
    <div className="flex flex-wrap gap-1.5" role="radiogroup">
      {options.map(([key, label]) => (
        <button key={key} type="button" role="radio" aria-checked={value === key} onClick={() => onChange(key)}
          className={cn('min-h-11 flex-1 rounded-lg border px-2 text-sm font-semibold', value === key ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 text-zinc-700 hover:border-zinc-400')}>
          {label}
        </button>
      ))}
    </div>
  );
}

function Slider({ label, value, min, max, step, onChange }: { label: string; value: number; min: number; max: number; step: number; onChange: (value: number) => void }) {
  return (
    <label className="block text-sm font-medium text-zinc-800">
      {label}
      <input type="range" min={min} max={max} step={step} value={value} onChange={(e) => onChange(Number(e.target.value))} className="mt-1 w-full accent-zinc-900" />
    </label>
  );
}

function ColorField({ label, value, onChange, optional = false }: { label: string; value: string; onChange: (value: string) => void; optional?: boolean }) {
  return (
    <label className="block text-xs font-medium text-zinc-700">
      {label}
      <span className="mt-1 flex items-center gap-1.5">
        <input type="color" value={value || '#ffffff'} onChange={(e) => onChange(e.target.value)} className="h-11 w-11 shrink-0 cursor-pointer rounded-lg border border-zinc-300" />
        {optional && value && <button type="button" onClick={() => onChange('')} className="min-h-11 text-xs text-zinc-500 underline">Hapus</button>}
        {optional && !value && <span className="text-[11px] text-zinc-400">Opsional</span>}
      </span>
    </label>
  );
}
