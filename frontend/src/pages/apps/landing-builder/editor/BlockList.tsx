import { useState } from 'react';
import { DndContext, KeyboardSensor, PointerSensor, TouchSensor, closestCenter, useSensor, useSensors } from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Copy, Eye, EyeOff, GripVertical, MoreHorizontal, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Block } from '../types';
import { BLOCK_META, blockSummary } from './blockMeta';

/** Page blocks in order: tap to edit, drag (or keyboard) to reorder, show/hide, duplicate, delete. */
export default function BlockList({ blocks, selectedId, itemWord, onSelect, onReorder, onToggleHidden, onDuplicate, onDelete }: {
  blocks: Block[];
  selectedId: string | null;
  itemWord: string;
  onSelect: (id: string) => void;
  onReorder: (from: number, to: number) => void;
  onToggleHidden: (id: string) => void;
  onDuplicate: (id: string) => void;
  onDelete: (id: string) => void;
}) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    // Phones: hold briefly so scrolling the list does not start a drag.
    useSensor(TouchSensor, { activationConstraint: { delay: 180, tolerance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );
  const [menuFor, setMenuFor] = useState<string | null>(null);

  const onDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over || active.id === over.id) return;
    const from = blocks.findIndex((b) => b.id === active.id);
    const to = blocks.findIndex((b) => b.id === over.id);
    if (from >= 0 && to >= 0) onReorder(from, to);
  };

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
      <SortableContext items={blocks.map((b) => b.id)} strategy={verticalListSortingStrategy}>
        <ul className="space-y-2" aria-label={`Urutan ${itemWord}`}>
          {blocks.map((block) => (
            <Row
              key={block.id}
              block={block}
              selected={block.id === selectedId}
              menuOpen={menuFor === block.id}
              onMenu={(open) => setMenuFor(open ? block.id : null)}
              onSelect={() => onSelect(block.id)}
              onToggleHidden={() => onToggleHidden(block.id)}
              onDuplicate={() => { setMenuFor(null); onDuplicate(block.id); }}
              onDelete={() => {
                setMenuFor(null);
                if (window.confirm(`Hapus ${BLOCK_META[block.type].label.toLowerCase()} ini? Bisa dibatalkan dengan Urungkan.`)) onDelete(block.id);
              }}
            />
          ))}
        </ul>
      </SortableContext>
    </DndContext>
  );
}

function Row({ block, selected, menuOpen, onMenu, onSelect, onToggleHidden, onDuplicate, onDelete }: {
  block: Block;
  selected: boolean;
  menuOpen: boolean;
  onMenu: (open: boolean) => void;
  onSelect: () => void;
  onToggleHidden: () => void;
  onDuplicate: () => void;
  onDelete: () => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: block.id });
  const meta = BLOCK_META[block.type];
  const Icon = meta.icon;
  const summary = blockSummary(block);

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        'relative flex items-center gap-1 rounded-2xl border bg-white pr-1 shadow-sm',
        selected ? 'border-zinc-900 ring-1 ring-zinc-900' : 'border-zinc-200',
        isDragging && 'z-10 shadow-lg',
        block.hidden && 'bg-zinc-50',
      )}
    >
      <button
        type="button"
        {...attributes}
        {...listeners}
        aria-label={`Geser ${meta.label} (tahan lalu geser, atau Spasi lalu panah)`}
        className="flex min-h-14 w-9 shrink-0 cursor-grab touch-none items-center justify-center text-zinc-400 active:cursor-grabbing"
      >
        <GripVertical className="h-5 w-5" />
      </button>
      <button type="button" onClick={onSelect} className="flex min-h-14 min-w-0 flex-1 items-center gap-3 py-2 text-left">
        <span className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', block.hidden ? 'bg-zinc-100 text-zinc-400' : 'bg-yellow-50 text-yellow-700')}>
          <Icon className="h-5 w-5" />
        </span>
        <span className="min-w-0">
          <span className={cn('block truncate text-sm font-semibold', block.hidden ? 'text-zinc-400' : 'text-zinc-900')}>{meta.label}</span>
          <span className="block truncate text-xs text-zinc-500">{block.hidden ? 'Disembunyikan' : summary || 'Ketuk untuk mengisi'}</span>
        </span>
      </button>
      <button
        type="button"
        onClick={onToggleHidden}
        aria-pressed={!block.hidden}
        aria-label={block.hidden ? `Tampilkan ${meta.label}` : `Sembunyikan ${meta.label}`}
        title={block.hidden ? 'Tampilkan' : 'Sembunyikan'}
        className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-zinc-500 hover:bg-zinc-100"
      >
        {block.hidden ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
      </button>
      <div className="relative">
        <button
          type="button"
          onClick={() => onMenu(!menuOpen)}
          aria-label={`Menu ${meta.label}`}
          aria-expanded={menuOpen}
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-zinc-500 hover:bg-zinc-100"
        >
          <MoreHorizontal className="h-5 w-5" />
        </button>
        {menuOpen && (
          <>
            <button type="button" aria-label="Tutup menu" className="fixed inset-0 z-20 cursor-default" onClick={() => onMenu(false)} />
            <div role="menu" className="absolute right-0 top-12 z-30 w-44 overflow-hidden rounded-xl border border-zinc-200 bg-white py-1 shadow-xl">
              <button type="button" role="menuitem" onClick={onDuplicate} className="flex min-h-11 w-full items-center gap-2 px-3 text-sm text-zinc-800 hover:bg-zinc-50"><Copy className="h-4 w-4" /> Duplikat</button>
              <button type="button" role="menuitem" onClick={onDelete} className="flex min-h-11 w-full items-center gap-2 px-3 text-sm text-red-600 hover:bg-red-50"><Trash2 className="h-4 w-4" /> Hapus</button>
            </div>
          </>
        )}
      </div>
    </li>
  );
}
