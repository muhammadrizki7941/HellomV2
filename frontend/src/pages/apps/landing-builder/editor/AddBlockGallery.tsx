import { useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import type { BlockType } from '../types';
import type { EditorPreset } from '../presets';
import BlockThumb from './BlockThumb';
import { BLOCK_META, GALLERY_TYPES, GROUP_LABELS } from './blockMeta';
import type { BlockGroup } from './blockMeta';

/** "+ Tambah": illustrated block gallery. The preset's blocks come first ("Disarankan"). */
export default function AddBlockGallery({ preset, onPick }: { preset: EditorPreset; onPick: (type: BlockType) => void }) {
  const [query, setQuery] = useState('');

  const sections = useMemo(() => {
    const q = query.trim().toLowerCase();
    const match = (type: BlockType) => !q || `${BLOCK_META[type].label} ${BLOCK_META[type].description}`.toLowerCase().includes(q);
    if (q) return [{ title: 'Hasil pencarian', types: GALLERY_TYPES.filter(match) }];
    const featured = preset.featured.filter((type) => GALLERY_TYPES.includes(type));
    const groups = (Object.keys(GROUP_LABELS) as BlockGroup[]).map((group) => ({
      title: GROUP_LABELS[group],
      types: GALLERY_TYPES.filter((type) => BLOCK_META[type].group === group && !featured.includes(type)),
    }));
    return [{ title: 'Disarankan untukmu', types: featured }, ...groups].filter((s) => s.types.length > 0);
  }, [preset, query]);

  return (
    <div className="space-y-5">
      <label className="relative block">
        <span className="sr-only">Cari {preset.terms.item}</span>
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={`Cari ${preset.terms.item}…`}
          className="min-h-11 w-full rounded-xl border border-zinc-200 bg-white pl-9 pr-3 text-base text-zinc-900 outline-none focus:border-zinc-900"
        />
      </label>
      {sections.length === 0 && <p className="py-6 text-center text-sm text-zinc-500">Tidak ada yang cocok dengan "{query}".</p>}
      {sections.map((section) => (
        <section key={section.title}>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{section.title}</h3>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {section.types.map((type) => {
              const meta = BLOCK_META[type];
              return (
                <button
                  key={type}
                  type="button"
                  onClick={() => onPick(type)}
                  data-block-type={type}
                  className="group overflow-hidden rounded-2xl border border-zinc-200 bg-white text-left transition hover:border-zinc-900 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-zinc-900"
                >
                  <span className="block aspect-[3/2] border-b border-zinc-100"><BlockThumb type={type} /></span>
                  <span className="block p-2.5">
                    <span className="block text-sm font-semibold text-zinc-900">{meta.label}</span>
                    <span className="block text-xs leading-snug text-zinc-500">{meta.description}</span>
                  </span>
                </button>
              );
            })}
          </div>
        </section>
      ))}
    </div>
  );
}
