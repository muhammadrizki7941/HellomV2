import React from 'react';
import { MessageCircle, Palette, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { THEMES } from '../constants';

export type ThemeOptions = {
  font: 'sans' | 'serif' | 'rounded' | 'mono';
  buttonShape: 'rounded' | 'pill' | 'square';
  buttonStyle: 'solid' | 'outline';
};

interface SettingsModalProps {
  isOpen: boolean;
  onClose: () => void;
  settings: {
    whatsappNumber: string;
    whatsappMessage: string;
    showFloatingWhatsapp: boolean;
  };
  setSettings: (settings: any) => void;
  themeId: string;
  setThemeId: (id: string) => void;
  themeOptions: ThemeOptions;
  setThemeOptions: (options: ThemeOptions) => void;
}

const FONTS: Array<[ThemeOptions['font'], string, string]> = [
  ['sans', 'Modern', 'system-ui, sans-serif'],
  ['serif', 'Elegan', 'Georgia, serif'],
  ['rounded', 'Ramah', 'ui-rounded, system-ui, sans-serif'],
  ['mono', 'Teknis', 'ui-monospace, monospace'],
];

/** Page look (theme, font, buttons) and the floating WhatsApp widget. */
export const SettingsModal: React.FC<SettingsModalProps> = ({
  isOpen,
  onClose,
  settings,
  setSettings,
  themeId,
  setThemeId,
  themeOptions,
  setThemeOptions,
}) => {
  if (!isOpen) return null;
  const opt = (patch: Partial<ThemeOptions>) => setThemeOptions({ ...themeOptions, ...patch });

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 backdrop-blur-sm sm:items-center sm:p-4" onClick={onClose}>
      <div className="flex max-h-[92svh] w-full max-w-md flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:rounded-2xl" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label="Pengaturan halaman">
        <div className="flex shrink-0 items-center justify-between border-b border-zinc-100 p-4">
          <h3 className="text-base font-bold text-zinc-900">Tampilan & widget</h3>
          <button onClick={onClose} aria-label="Tutup" className="flex h-11 w-11 items-center justify-center rounded-full text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 space-y-6 overflow-y-auto p-4">
          <section className="space-y-3">
            <h4 className="flex items-center gap-2 font-bold text-zinc-800"><Palette className="h-4 w-4" /> Tema</h4>
            <div className="grid grid-cols-2 gap-2">
              {THEMES.map((theme) => (
                <button
                  key={theme.id}
                  type="button"
                  onClick={() => setThemeId(theme.id)}
                  className={cn('flex min-h-12 items-center gap-2 rounded-xl border p-2 text-left text-sm', themeId === theme.id ? 'border-zinc-900 ring-1 ring-zinc-900' : 'border-zinc-200')}
                >
                  <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-zinc-200" style={{ backgroundColor: theme.colors.backgroundColor }}>
                    <span className="h-4 w-4 rounded-full" style={{ backgroundColor: theme.colors.buttonColor }} />
                  </span>
                  <span className="truncate font-medium">{theme.name}</span>
                </button>
              ))}
            </div>
            <div>
              <p className="mb-1 text-xs font-bold text-zinc-600">Huruf</p>
              <div className="grid grid-cols-4 gap-2">
                {FONTS.map(([key, label, family]) => (
                  <button key={key} type="button" onClick={() => opt({ font: key })} style={{ fontFamily: family }}
                    className={cn('min-h-11 rounded-xl border text-sm', themeOptions.font === key ? 'border-zinc-900 bg-zinc-50 font-bold' : 'border-zinc-200')}>
                    {label}
                  </button>
                ))}
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <p className="mb-1 text-xs font-bold text-zinc-600">Bentuk tombol</p>
                <div className="flex gap-1">
                  {([['rounded', 'rounded-lg'], ['pill', 'rounded-full'], ['square', 'rounded-none']] as const).map(([key, cls]) => (
                    <button key={key} type="button" aria-label={key} onClick={() => opt({ buttonShape: key })}
                      className={cn('flex min-h-11 flex-1 items-center justify-center rounded-xl border', themeOptions.buttonShape === key ? 'border-zinc-900' : 'border-zinc-200')}>
                      <span className={cn('h-4 w-8 bg-zinc-900', cls)} />
                    </button>
                  ))}
                </div>
              </div>
              <div>
                <p className="mb-1 text-xs font-bold text-zinc-600">Gaya tombol</p>
                <div className="flex gap-1">
                  {([['solid', 'Isi'], ['outline', 'Garis']] as const).map(([key, label]) => (
                    <button key={key} type="button" onClick={() => opt({ buttonStyle: key })}
                      className={cn('min-h-11 flex-1 rounded-xl border text-sm', themeOptions.buttonStyle === key ? 'border-zinc-900 font-bold' : 'border-zinc-200')}>
                      {label}
                    </button>
                  ))}
                </div>
              </div>
            </div>
          </section>

          <section className="space-y-4">
            <div className="flex items-center gap-2">
              <div className="rounded-lg bg-green-100 p-2 text-green-600"><MessageCircle className="h-5 w-5" /></div>
              <h4 className="font-bold text-zinc-800">Tombol WhatsApp mengambang</h4>
            </div>
            <label className="flex min-h-12 items-center justify-between rounded-xl border border-zinc-200 bg-zinc-50 px-4">
              <span className="text-sm font-medium text-zinc-700">Aktifkan</span>
              <input type="checkbox" className="h-5 w-5" checked={settings.showFloatingWhatsapp} onChange={(e) => setSettings({ ...settings, showFloatingWhatsapp: e.target.checked })} />
            </label>
            <div>
              <label className="mb-1 block text-xs font-bold text-zinc-600">Nomor WhatsApp</label>
              <input type="tel" inputMode="tel" value={settings.whatsappNumber} onChange={(e) => setSettings({ ...settings, whatsappNumber: e.target.value })} placeholder="08123456789"
                className="min-h-12 w-full rounded-xl border border-zinc-300 px-3 text-base outline-none focus:border-zinc-900" />
              <p className="mt-1 text-xs text-zinc-400">Dipakai juga oleh tombol & form yang dikirim ke WhatsApp.</p>
            </div>
            <div>
              <label className="mb-1 block text-xs font-bold text-zinc-600">Pesan awal</label>
              <textarea value={settings.whatsappMessage} onChange={(e) => setSettings({ ...settings, whatsappMessage: e.target.value })} rows={2}
                className="w-full resize-none rounded-xl border border-zinc-300 px-3 py-2 text-base outline-none focus:border-zinc-900" />
            </div>
          </section>
        </div>

        <div className="shrink-0 border-t border-zinc-100 bg-zinc-50 p-4" style={{ paddingBottom: 'calc(1rem + env(safe-area-inset-bottom))' }}>
          <button onClick={onClose} className="min-h-12 w-full rounded-xl bg-zinc-900 font-bold text-white">Selesai</button>
          <p className="mt-2 text-center text-xs text-zinc-400">Perubahan tersimpan otomatis.</p>
        </div>
      </div>
    </div>
  );
};
