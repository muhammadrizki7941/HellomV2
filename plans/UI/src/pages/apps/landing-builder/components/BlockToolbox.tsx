import React, { useMemo, useState } from 'react';
import { useDraggable } from '@dnd-kit/core';
import { Search, Layers, LayoutGrid, ArrowUp, ArrowDown, Trash2, GripVertical } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Block, BlockType } from '../types';
import { BLOCK_CATALOG, CATEGORY_TABS, BLOCK_ICON, BlockCatalogItem } from '../blockCatalog';
import { useLang } from '../i18n';

const paletteClass = 'flex flex-col items-start text-left p-3 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-1.5 group/item w-full';

const PaletteItemInner: React.FC<{ item: BlockCatalogItem }> = ({ item }) => {
  const { t } = useLang();
  const Icon = item.icon;
  return (
    <>
      <Icon className="w-5 h-5 text-zinc-400 group-hover/item:text-yellow-600" />
      <span className="text-sm font-semibold text-zinc-800 leading-tight">{t(item.labelKey)}</span>
      <span className="text-[11px] text-zinc-400 leading-tight">{t(item.descKey)}</span>
    </>
  );
};

/** Draggable palette card — used on desktop where a DndContext wraps the editor. */
const DraggablePaletteItem: React.FC<{ item: BlockCatalogItem; onAdd: (type: BlockType) => void }> = ({ item, onAdd }) => {
  const { setNodeRef, listeners, attributes, isDragging } = useDraggable({
    id: `palette:${item.type}`,
    data: { source: 'palette', type: item.type },
  });
  return (
    <button
      ref={setNodeRef}
      {...listeners}
      {...attributes}
      onClick={() => onAdd(item.type)}
      className={cn(paletteClass, 'cursor-grab active:cursor-grabbing touch-none', isDragging && 'opacity-40')}
      title="Seret ke canvas atau klik untuk menambah"
    >
      <PaletteItemInner item={item} />
    </button>
  );
};

const PaletteItem: React.FC<{ item: BlockCatalogItem; enableDrag: boolean; onAdd: (type: BlockType) => void }> = ({ item, enableDrag, onAdd }) => {
  if (enableDrag) return <DraggablePaletteItem item={item} onAdd={onAdd} />;
  return (
    <button onClick={() => onAdd(item.type)} className={paletteClass}>
      <PaletteItemInner item={item} />
    </button>
  );
};

interface BlockToolboxProps {
  onAddBlock: (type: BlockType) => void;
  /** Enable drag-to-canvas (only when rendered inside the editor DndContext). */
  enableDrag?: boolean;
  // Optional structure outline controls
  blocks?: Block[];
  selectedBlockId?: string | null;
  onSelectBlock?: (id: string) => void;
  onMoveBlock?: (index: number, direction: 'up' | 'down') => void;
  onDeleteBlock?: (id: string) => void;
}

export const BlockToolbox: React.FC<BlockToolboxProps> = ({
  onAddBlock,
  enableDrag = false,
  blocks,
  selectedBlockId,
  onSelectBlock,
  onMoveBlock,
  onDeleteBlock,
}) => {
  const { t } = useLang();
  const showStructureTab = Array.isArray(blocks) && !!onSelectBlock;
  const [tab, setTab] = useState<'components' | 'structure'>('components');
  const [category, setCategory] = useState<'all' | string>('all');
  const [query, setQuery] = useState('');

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return BLOCK_CATALOG.filter((item) => {
      const inCategory = category === 'all' || item.categories.includes(category as never);
      if (!inCategory) return false;
      if (!q) return true;
      const label = t(item.labelKey).toLowerCase();
      const desc = t(item.descKey).toLowerCase();
      return label.includes(q) || desc.includes(q) || item.type.includes(q);
    });
  }, [category, query, t]);

  return (
    <div className="flex flex-col h-full">
      {/* Components / Structure switch */}
      {showStructureTab && (
        <div className="flex items-center gap-2 p-3 border-b border-zinc-100">
          <button
            onClick={() => setTab('components')}
            className={cn(
              'flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg text-sm font-semibold transition-colors',
              tab === 'components' ? 'bg-zinc-900 text-white' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200',
            )}
          >
            <LayoutGrid className="w-4 h-4" /> {t('toolbox.components')}
          </button>
          <button
            onClick={() => setTab('structure')}
            className={cn(
              'flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg text-sm font-semibold transition-colors',
              tab === 'structure' ? 'bg-zinc-900 text-white' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200',
            )}
          >
            <Layers className="w-4 h-4" /> {t('toolbox.structure')}
          </button>
        </div>
      )}

      {tab === 'components' ? (
        <div className="flex flex-col min-h-0 flex-1">
          {/* Search */}
          <div className="p-3 pb-2">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400" />
              <input
                type="text"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={t('toolbox.search')}
                className="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-zinc-200 focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
              />
            </div>
          </div>

          {/* Category tabs */}
          <div className="px-3 pb-2 flex flex-wrap gap-1.5">
            {CATEGORY_TABS.map((cat) => (
              <button
                key={cat.id}
                onClick={() => setCategory(cat.id)}
                className={cn(
                  'px-2.5 py-1 rounded-full text-xs font-medium border transition-colors',
                  category === cat.id
                    ? 'bg-yellow-400 border-yellow-400 text-black'
                    : 'bg-white border-zinc-200 text-zinc-500 hover:border-zinc-300',
                )}
              >
                {t(cat.labelKey)}
              </button>
            ))}
          </div>

          {/* Block grid */}
          <div className="flex-1 overflow-y-auto p-3 pt-1">
            {enableDrag && (
              <p className="px-1 pb-2 text-[11px] text-zinc-400">{t('toolbox.dragHint')}</p>
            )}
            {filtered.length === 0 ? (
              <p className="text-sm text-zinc-400 text-center py-10">{t('toolbox.empty')}</p>
            ) : (
              <div className="grid grid-cols-2 gap-2.5">
                {filtered.map((item) => (
                  <PaletteItem key={item.type} item={item} enableDrag={enableDrag} onAdd={onAddBlock} />
                ))}
              </div>
            )}
          </div>
        </div>
      ) : (
        /* Structure outline */
        <div className="flex-1 overflow-y-auto p-3">
          <p className="text-[11px] text-zinc-400 mb-2">{t('toolbox.structureHint')}</p>
          {(blocks || []).length === 0 ? (
            <p className="text-sm text-zinc-400 text-center py-10">{t('toolbox.structureEmpty')}</p>
          ) : (
            <div className="space-y-1.5">
              {(blocks || []).map((block, index) => {
                const Icon = BLOCK_ICON[block.type] || GripVertical;
                return (
                  <div
                    key={block.id}
                    onClick={() => onSelectBlock?.(block.id)}
                    className={cn(
                      'flex items-center gap-2 px-2.5 py-2 rounded-lg border cursor-pointer transition-colors',
                      selectedBlockId === block.id
                        ? 'border-yellow-400 bg-yellow-50'
                        : 'border-zinc-200 bg-white hover:border-zinc-300',
                    )}
                  >
                    <Icon className="w-4 h-4 text-zinc-500 shrink-0" />
                    <span className="flex-1 text-sm font-medium text-zinc-700 capitalize truncate">
                      {block.type}
                    </span>
                    <button
                      onClick={(e) => { e.stopPropagation(); onMoveBlock?.(index, 'up'); }}
                      disabled={index === 0}
                      className="p-1 text-zinc-400 hover:text-zinc-700 disabled:opacity-30"
                    >
                      <ArrowUp className="w-3.5 h-3.5" />
                    </button>
                    <button
                      onClick={(e) => { e.stopPropagation(); onMoveBlock?.(index, 'down'); }}
                      disabled={index === (blocks || []).length - 1}
                      className="p-1 text-zinc-400 hover:text-zinc-700 disabled:opacity-30"
                    >
                      <ArrowDown className="w-3.5 h-3.5" />
                    </button>
                    <button
                      onClick={(e) => { e.stopPropagation(); onDeleteBlock?.(block.id); }}
                      className="p-1 text-zinc-400 hover:text-red-600"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}
    </div>
  );
};
