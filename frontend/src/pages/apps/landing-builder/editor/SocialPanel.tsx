import { useEffect, useRef, useState } from 'react';
import { ArrowDown, ArrowUp, Download, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { LandingSocial } from '@/lib/hellomApi';
import type { Block } from '../types';
import { PLATFORM, SOCIAL_ICONS, SOCIAL_PLATFORMS, socialUrl } from './socialPlatforms';
import type { SocialPlatform } from './socialPlatforms';

/** Glyph of a platform (our own SVG constants). */
export function SocialIcon({ platform, className }: { platform: SocialPlatform; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"
      className={className} dangerouslySetInnerHTML={{ __html: SOCIAL_ICONS[platform] }} />
  );
}

/** Fields of the old "Ikon sosial media" block that map onto the panel. */
const LEGACY_KEYS: SocialPlatform[] = ['instagram', 'tiktok', 'youtube', 'facebook', 'threads', 'x', 'linkedin', 'whatsapp'];

/**
 * Sosial media panel (Fase 4): optional, separate from link blocks. The seller types a username,
 * number or link; the editor shows at once whether it is recognised (the server rebuilds links).
 */
export default function SocialPanel({ social, onChange, legacyBlocks }: {
  social: LandingSocial;
  onChange: (patch: Partial<LandingSocial>, key?: string) => void;
  legacyBlocks: Block[];
}) {
  const [focus, setFocus] = useState<string | null>(null);
  const inputs = useRef<Record<string, HTMLInputElement | null>>({});
  const items = social.items as Array<{ platform: SocialPlatform; value: string }>;
  const added = new Set(items.map((i) => i.platform));

  useEffect(() => {
    if (focus) inputs.current[focus]?.focus();
  }, [focus]);

  const setItems = (next: typeof items, key?: string) => onChange({ items: next }, key);
  const add = (platform: SocialPlatform) => {
    setItems([...items, { platform, value: '' }]);
    setFocus(platform);
  };
  const move = (index: number, dir: -1 | 1) => {
    const to = index + dir;
    if (to < 0 || to >= items.length) return;
    const next = [...items];
    [next[index], next[to]] = [next[to], next[index]];
    setItems(next);
  };
  const legacy = legacyBlocks.flatMap((b) => LEGACY_KEYS.map((key) => ({ platform: key, value: String((b.content as Record<string, unknown>)[key] ?? '').trim() })))
    .filter((i) => i.value && !added.has(i.platform) && socialUrl(i.platform, i.value));
  const importLegacy = () => {
    const unique = legacy.filter((i, idx) => legacy.findIndex((x) => x.platform === i.platform) === idx);
    setItems([...items, ...unique]);
  };

  return (
    <div className="space-y-6">
      <p className="text-sm text-zinc-600">Ikon akun sosial media tampil sebagai satu baris di dekat profil. Opsional — isi yang kamu punya saja.</p>

      {legacy.length > 0 && (
        <div className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
          Halaman ini punya blok "Ikon sosial media" lama ({legacy.length} akun).
          <button type="button" onClick={importLegacy} className="mt-2 flex min-h-11 items-center gap-1.5 rounded-lg bg-white px-3 font-semibold text-zinc-900 ring-1 ring-amber-200">
            <Download className="h-4 w-4" /> Ambil ke panel ini
          </button>
          <p className="mt-1 text-xs">Setelah itu, hapus blok lamanya supaya ikon tidak tampil dua kali.</p>
        </div>
      )}

      {items.length > 0 && (
        <section aria-label="Akun kamu" className="space-y-3">
          {items.map((item, index) => {
            const info = PLATFORM[item.platform];
            if (!info) return null;
            const url = socialUrl(item.platform, item.value);
            const id = `social-${item.platform}`;
            return (
              <div key={item.platform} className="rounded-2xl border border-zinc-200 p-3">
                <div className="flex items-center gap-2">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white" style={{ background: info.color, color: item.platform === 'snapchat' ? '#000' : '#fff' }}>
                    <SocialIcon platform={item.platform} className="h-5 w-5" />
                  </span>
                  <label htmlFor={id} className="min-w-0 flex-1 text-sm font-semibold text-zinc-900">{info.label}</label>
                  <button type="button" onClick={() => move(index, -1)} disabled={index === 0} aria-label={`Naikkan ${info.label}`} className="flex h-10 w-10 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 disabled:opacity-30"><ArrowUp className="h-4 w-4" /></button>
                  <button type="button" onClick={() => move(index, 1)} disabled={index === items.length - 1} aria-label={`Turunkan ${info.label}`} className="flex h-10 w-10 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 disabled:opacity-30"><ArrowDown className="h-4 w-4" /></button>
                  <button type="button" onClick={() => setItems(items.filter((_, i) => i !== index))} aria-label={`Hapus ${info.label}`} className="flex h-10 w-10 items-center justify-center rounded-lg text-red-600 hover:bg-red-50"><Trash2 className="h-4 w-4" /></button>
                </div>
                <input
                  id={id}
                  ref={(el) => { inputs.current[item.platform] = el; }}
                  type="text"
                  inputMode={info.inputMode ?? 'text'}
                  autoCapitalize="off"
                  autoCorrect="off"
                  spellCheck={false}
                  value={item.value}
                  onChange={(e) => setItems(items.map((it, i) => (i === index ? { ...it, value: e.target.value } : it)), `social:${item.platform}`)}
                  placeholder={info.placeholder}
                  aria-invalid={item.value.trim() !== '' && !url}
                  aria-describedby={`${id}-hint`}
                  className="mt-2 min-h-11 w-full rounded-lg border border-zinc-300 px-3 text-base text-zinc-900 outline-none focus:border-zinc-900"
                />
                <p id={`${id}-hint`} className={cn('mt-1 break-all text-xs', item.value.trim() === '' ? 'text-zinc-500' : url ? 'text-green-700' : 'text-red-600')}>
                  {item.value.trim() === '' ? 'Belum diisi — tidak tampil di halaman.'
                    : url ? `✓ ${url.replace(/^mailto:/, '')}`
                      : `Belum dikenali, tidak akan tampil. Contoh: ${info.placeholder}.`}
                </p>
              </div>
            );
          })}
        </section>
      )}

      {added.size < SOCIAL_PLATFORMS.length && (
        <section>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Tambah akun</h3>
          <div className="flex flex-wrap gap-2">
            {SOCIAL_PLATFORMS.filter((p) => !added.has(p.key)).map((p) => (
              <button key={p.key} type="button" onClick={() => add(p.key)} data-platform={p.key}
                className="flex min-h-11 items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-3 text-sm font-medium text-zinc-800 hover:border-zinc-900">
                <SocialIcon platform={p.key} className="h-4 w-4" /> {p.label}
              </button>
            ))}
          </div>
        </section>
      )}

      <section className="space-y-4 border-t border-zinc-100 pt-4">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-500">Tampilan ikon</h3>
        <Choice label="Posisi" value={social.position} onChange={(position) => onChange({ position })} options={[['top', 'Di atas profil'], ['bottom', 'Di bawah profil']]} />
        <Choice label="Ukuran" value={social.size} onChange={(size) => onChange({ size })} options={[['sm', 'Kecil'], ['md', 'Sedang'], ['lg', 'Besar']]} />
        <Choice label="Warna" value={social.color} onChange={(color) => onChange(color === 'custom' && !social.customColor ? { color, customColor: '#18181b' } : { color })}
          options={[['mono', 'Ikuti teks'], ['brand', 'Warna asli'], ['custom', 'Pilih sendiri']]} />
        {social.color === 'custom' && (
          <label className="flex items-center gap-3 text-sm font-medium text-zinc-800">
            <input type="color" value={social.customColor ?? '#18181b'} onChange={(e) => onChange({ customColor: e.target.value }, 'social:color')} className="h-11 w-14 cursor-pointer rounded-lg border border-zinc-300" />
            Warna ikon
          </label>
        )}
      </section>
    </div>
  );
}

function Choice<T extends string>({ label, value, onChange, options }: { label: string; value: T; onChange: (value: T) => void; options: Array<[T, string]> }) {
  return (
    <div>
      <p className="mb-1.5 text-sm font-medium text-zinc-800">{label}</p>
      <div className={cn('grid gap-2', options.length === 3 ? 'grid-cols-3' : 'grid-cols-2')} role="radiogroup" aria-label={label}>
        {options.map(([key, text]) => (
          <button key={key} type="button" role="radio" aria-checked={value === key} onClick={() => onChange(key)}
            className={cn('min-h-11 rounded-lg border px-2 text-sm font-semibold', value === key ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 text-zinc-700')}>
            {text}
          </button>
        ))}
      </div>
    </div>
  );
}
