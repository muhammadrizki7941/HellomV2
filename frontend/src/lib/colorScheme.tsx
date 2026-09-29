import { useEffect, useState } from 'react';
import { Monitor, Moon, Sun } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Dashboard light/dark mode. The colors live in styles/dashboard-dark.css (html.hl-dark remaps
 * Tailwind's color variables); only the dashboard layout turns it on, so public pages, shop
 * pages, admin and POS keep their own look. Default: light (Hellom's design).
 */
export type ColorSchemePref = 'light' | 'dark' | 'system';

const KEY = 'hellom_theme';
const EVENT = 'hellom-theme-change';

export function getColorSchemePref(): ColorSchemePref {
  const v = typeof localStorage !== 'undefined' ? localStorage.getItem(KEY) : null;
  return v === 'dark' || v === 'system' ? v : 'light';
}

export function setColorSchemePref(pref: ColorSchemePref): void {
  localStorage.setItem(KEY, pref);
  window.dispatchEvent(new Event(EVENT));
}

/** Apply the preference to <html> while the calling layout is mounted. */
export function useDashboardColorScheme(): void {
  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const apply = () => {
      const pref = getColorSchemePref();
      const dark = pref === 'dark' || (pref === 'system' && media.matches);
      document.documentElement.classList.toggle('hl-dark', dark);
    };
    document.documentElement.classList.add('hl-dash');
    apply();
    media.addEventListener('change', apply);
    window.addEventListener(EVENT, apply);
    window.addEventListener('storage', apply);
    return () => {
      media.removeEventListener('change', apply);
      window.removeEventListener(EVENT, apply);
      window.removeEventListener('storage', apply);
      document.documentElement.classList.remove('hl-dark', 'hl-dash');
    };
  }, []);
}

const OPTIONS: Array<[ColorSchemePref, string, typeof Sun]> = [
  ['light', 'Terang', Sun],
  ['dark', 'Gelap', Moon],
  ['system', 'Ikuti sistem', Monitor],
];

export function ColorSchemeToggle({ className }: { className?: string }) {
  const [pref, setPref] = useState<ColorSchemePref>(getColorSchemePref);
  useEffect(() => {
    const sync = () => setPref(getColorSchemePref());
    window.addEventListener(EVENT, sync);
    return () => window.removeEventListener(EVENT, sync);
  }, []);

  return (
    <div role="radiogroup" aria-label="Tema tampilan" className={cn('flex rounded-xl bg-zinc-100 p-1', className)}>
      {OPTIONS.map(([value, label, Icon]) => (
        <button
          key={value}
          type="button"
          role="radio"
          aria-checked={pref === value}
          aria-label={label}
          title={label}
          onClick={() => { setColorSchemePref(value); setPref(value); }}
          className={cn('flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-lg text-xs font-semibold transition',
            pref === value ? 'bg-white text-zinc-900 shadow-sm' : 'text-zinc-500 hover:text-zinc-800')}
        >
          <Icon className="h-4 w-4" />
          <span className="hidden sm:inline">{label === 'Ikuti sistem' ? 'Sistem' : label}</span>
        </button>
      ))}
    </div>
  );
}
