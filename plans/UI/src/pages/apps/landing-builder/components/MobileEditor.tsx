import React, { useState } from 'react';
import {
  Plus, Settings, ChevronDown, ChevronUp,
  Sparkles, Eye, Globe, ArrowUp, ArrowDown, Trash2, MessageCircle, Save,
  Pencil, GripVertical, Smartphone, PanelTopClose, PanelTopOpen,
} from 'lucide-react';
import {
  DndContext,
  closestCenter,
  PointerSensor,
  TouchSensor,
  KeyboardSensor,
  useSensor,
  useSensors,
  DragEndEvent,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
  useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { cn } from '@/lib/utils';
import { Block, BlockType, BlockStyles } from '../types';
import { BLOCK_ICON, BLOCK_LABEL_KEY } from '../blockCatalog';
import { BlockToolbox } from './BlockToolbox';
import { PropertyPanel } from './PropertyPanel';
import { BlockRenderer } from './BlockRenderer';
import { useLang } from '../i18n';
import { useEditorChrome } from '@/contexts/editorChrome';

interface MobileEditorProps {
  blocks: Block[];
  selectedBlockId: string | null;
  setSelectedBlockId: (id: string | null) => void;
  activeTheme: any;
  addBlock: (type: BlockType) => void;
  updateBlockContent: (id: string, newContent: any) => void;
  updateBlockStyles: (id: string, newStyles: BlockStyles) => void;
  moveBlock: (index: number, direction: 'up' | 'down') => void;
  reorderBlocks: (oldIndex: number, newIndex: number) => void;
  deleteBlock: (id: string) => void;
  handleFileUpload: (e: React.ChangeEvent<HTMLInputElement>, fieldName: string, isStyle?: boolean) => void;
  isPreview: boolean;
  setIsPreview: (v: boolean) => void;
  setShowAiModal: (v: boolean) => void;
  setShowSettingsModal: (v: boolean) => void;
  onSave: () => void;
  onPublish: () => void;
  isSaving: boolean;
  pageSettings: {
    whatsappNumber: string;
    whatsappMessage: string;
    showFloatingWhatsapp: boolean;
  };
}

/**
 * Human-friendly label for a block row in the list — mirrors what a beginner
 * sees on the page so the list reads like the content, not like block types.
 * Falls back to the catalog label (e.g. "Hero", "Produk").
 */
const blockDisplayName = (block: Block, typeLabel: string): string => {
  const c = (block.content || {}) as Record<string, unknown>;
  const pick = (v: unknown) => (typeof v === 'string' && v.trim() ? v.trim() : '');
  const raw =
    pick(c.title) ||
    pick(c.name) ||
    pick(c.heading) ||
    pick(c.buttonText) ||
    pick(c.text) ||
    pick(c.body) ||
    pick(c.caption);
  if (!raw) return typeLabel;
  const oneLine = raw.replace(/\s+/g, ' ');
  return oneLine.length > 38 ? `${oneLine.slice(0, 38)}…` : oneLine;
};

interface SortableBlockRowProps {
  block: Block;
  index: number;
  total: number;
  selectedBlockId: string | null;
  menuOpen: boolean;
  onToggleMenu: () => void;
  onEdit: () => void;
  onMove: (direction: 'up' | 'down') => void;
  onDelete: () => void;
  t: (key: string) => string;
}

/** A draggable block row in the Lynk-style list. The grip is the drag handle. */
const SortableBlockRow: React.FC<SortableBlockRowProps> = ({
  block, index, total, selectedBlockId, menuOpen, onToggleMenu, onEdit, onMove, onDelete, t,
}) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: block.id });
  const Icon = BLOCK_ICON[block.type] || GripVertical;
  const typeLabel = t(BLOCK_LABEL_KEY[block.type] || block.type);
  const name = blockDisplayName(block, typeLabel);

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        'bg-white rounded-2xl border transition-colors',
        isDragging && 'opacity-60 shadow-xl ring-2 ring-yellow-400 z-50 relative',
        selectedBlockId === block.id ? 'border-yellow-400' : 'border-zinc-200',
      )}
    >
      {/* Row */}
      <div className="flex items-center gap-2 p-3">
        <button
          {...attributes}
          {...listeners}
          className="p-1 -ml-1 text-zinc-300 hover:text-zinc-500 touch-none cursor-grab active:cursor-grabbing shrink-0"
          title={t('mobile.dragHint')}
          aria-label={t('mobile.dragHint')}
        >
          <GripVertical className="w-4 h-4" />
        </button>
        <span className="flex items-center justify-center w-9 h-9 rounded-xl bg-yellow-50 text-yellow-600 shrink-0">
          <Icon className="w-4 h-4" />
        </span>
        <button onClick={onEdit} className="flex-1 min-w-0 text-left">
          <p className="text-sm font-semibold text-zinc-800 truncate">{name}</p>
          <p className="text-[11px] text-zinc-400 truncate">{typeLabel} · {t('mobile.tapToEdit')}</p>
        </button>
        <button
          onClick={onToggleMenu}
          className="p-2 text-zinc-400 hover:text-zinc-700 shrink-0"
          title={t('toolbox.editBlock')}
        >
          {menuOpen ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
        </button>
      </div>

      {/* Expanded action row */}
      {menuOpen && (
        <div className="flex items-center gap-1.5 px-3 pb-3 pt-0">
          <button
            onClick={onEdit}
            className="flex-1 flex items-center justify-center gap-1.5 py-2 bg-zinc-900 text-white rounded-xl text-xs font-semibold"
          >
            <Pencil className="w-3.5 h-3.5" /> {t('toolbox.editBlock')}
          </button>
          <button
            onClick={() => onMove('up')}
            disabled={index === 0}
            title={t('mobile.moveUp')}
            className="w-9 h-9 flex items-center justify-center bg-zinc-100 text-zinc-600 rounded-xl disabled:opacity-30"
          >
            <ArrowUp className="w-4 h-4" />
          </button>
          <button
            onClick={() => onMove('down')}
            disabled={index === total - 1}
            title={t('mobile.moveDown')}
            className="w-9 h-9 flex items-center justify-center bg-zinc-100 text-zinc-600 rounded-xl disabled:opacity-30"
          >
            <ArrowDown className="w-4 h-4" />
          </button>
          <button
            onClick={onDelete}
            title={t('mobile.delete')}
            className="w-9 h-9 flex items-center justify-center bg-red-50 text-red-500 rounded-xl"
          >
            <Trash2 className="w-4 h-4" />
          </button>
        </div>
      )}
    </div>
  );
};

export const MobileEditor: React.FC<MobileEditorProps> = ({
  blocks,
  selectedBlockId,
  setSelectedBlockId,
  activeTheme,
  addBlock,
  updateBlockContent,
  updateBlockStyles,
  moveBlock,
  reorderBlocks,
  deleteBlock,
  handleFileUpload,
  isPreview,
  setIsPreview,
  setShowAiModal,
  setShowSettingsModal,
  onSave,
  onPublish,
  isSaving,
  pageSettings,
}) => {
  const { t, lang, setLang } = useLang();
  const { chromeHidden, setChromeHidden } = useEditorChrome();
  const [activeSheet, setActiveSheet] = useState<'none' | 'add' | 'edit'>('none');
  // Which row currently has its action menu expanded (Lynk-style 3-dot row).
  const [openMenuId, setOpenMenuId] = useState<string | null>(null);

  // Touch-friendly drag: press-and-hold ~180ms on the grip to start dragging
  // (a small tolerance prevents accidental drags while scrolling the list).
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(TouchSensor, { activationConstraint: { delay: 180, tolerance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIndex = blocks.findIndex((b) => b.id === active.id);
    const newIndex = blocks.findIndex((b) => b.id === over.id);
    if (oldIndex !== -1 && newIndex !== -1) reorderBlocks(oldIndex, newIndex);
  };

  const handleAddBlock = (type: BlockType) => {
    addBlock(type);
    setActiveSheet('none');
  };

  const openEditor = (id: string) => {
    setSelectedBlockId(id);
    setOpenMenuId(null);
    setActiveSheet('edit');
  };

  const selectedBlock = blocks.find((b) => b.id === selectedBlockId);

  return (
    <div className="flex flex-col w-full h-full max-w-full min-w-0 bg-zinc-100 overflow-hidden">
      {/* Compact Mobile Toolbar */}
      <header className="h-12 bg-white border-b border-zinc-200 flex items-center justify-between gap-2 px-3 shrink-0 z-20">
        {/* Left: AI */}
        <button
          onClick={() => setShowAiModal(true)}
          className="flex items-center gap-1.5 px-2.5 py-1.5 text-purple-600 bg-purple-50 rounded-lg text-xs font-semibold shrink-0"
        >
          <Sparkles className="w-3.5 h-3.5" />
          AI
        </button>

        {/* Right: actions */}
        <div className="flex items-center gap-1 min-w-0">
          <button
            onClick={() => setLang(lang === 'id' ? 'en' : 'id')}
            title={t('chrome.language')}
            className="px-2 py-1.5 bg-zinc-100 text-zinc-700 rounded-lg text-xs font-bold uppercase shrink-0"
          >
            {lang}
          </button>
          <button
            onClick={() => setChromeHidden(!chromeHidden)}
            title={chromeHidden ? t('mobile.showChrome') : t('mobile.hideChrome')}
            className={cn(
              'p-2 rounded-lg shrink-0',
              chromeHidden ? 'bg-yellow-400 text-black' : 'text-zinc-500 hover:bg-zinc-100',
            )}
          >
            {chromeHidden ? <PanelTopOpen className="w-4 h-4" /> : <PanelTopClose className="w-4 h-4" />}
          </button>
          <button
            onClick={onSave}
            disabled={isSaving}
            title={t('chrome.save')}
            className="flex items-center gap-1 px-2 py-1.5 bg-zinc-100 text-zinc-700 rounded-lg text-xs font-semibold disabled:opacity-50 shrink-0"
          >
            <Save className="w-3.5 h-3.5" />
            <span className="hidden min-[400px]:inline">{isSaving ? '...' : t('chrome.save')}</span>
          </button>
          <button
            onClick={() => setIsPreview(!isPreview)}
            title={isPreview ? t('chrome.edit') : t('mobile.fullPreview')}
            className={cn(
              'p-2 rounded-lg shrink-0',
              isPreview ? 'bg-zinc-900 text-white' : 'text-zinc-500 hover:bg-zinc-100',
            )}
          >
            <Eye className="w-4 h-4" />
          </button>
          <button
            onClick={() => setShowSettingsModal(true)}
            title={t('chrome.settings')}
            className="p-2 text-zinc-500 hover:bg-zinc-100 rounded-lg shrink-0"
          >
            <Settings className="w-4 h-4" />
          </button>
          <button
            onClick={onPublish}
            disabled={isSaving}
            title={t('chrome.publish')}
            className="p-2 bg-black text-white rounded-lg disabled:opacity-50 shrink-0"
          >
            <Globe className="w-4 h-4" />
          </button>
        </div>
      </header>

      {/* Full-screen live preview (Eye toggle) */}
      {isPreview ? (
        <main className="flex-1 overflow-y-auto overflow-x-hidden">
          <div
            className="min-h-full w-full"
            style={{ backgroundColor: activeTheme.colors.backgroundColor }}
          >
            {blocks.map((block) => (
              <BlockRenderer key={block.id} block={block} theme={activeTheme} />
            ))}
          </div>
        </main>
      ) : (
        /* Lynk-style manager: Add button → Block list → live phone preview */
        <main className="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4 space-y-5">
          {/* Add new block */}
          <button
            onClick={() => setActiveSheet('add')}
            className="w-full flex items-center justify-center gap-2 py-3.5 bg-zinc-900 text-white rounded-2xl text-sm font-bold shadow-sm active:scale-[0.99] transition-transform"
          >
            <span className="flex items-center justify-center w-5 h-5 rounded-full bg-yellow-400 text-black">
              <Plus className="w-3.5 h-3.5" strokeWidth={3} />
            </span>
            {t('mobile.addNewBlock')}
          </button>

          {/* Block list */}
          <section>
            <h3 className="px-1 mb-2 text-xs font-bold uppercase tracking-wide text-zinc-400">
              {t('mobile.blockList')}
            </h3>

            {blocks.length === 0 ? (
              <div className="flex flex-col items-center justify-center text-center text-zinc-400 py-10 px-6 bg-white rounded-2xl border border-dashed border-zinc-200">
                <Plus className="w-8 h-8 mb-2 opacity-20" />
                <p className="text-sm">{t('mobile.emptyList')}</p>
              </div>
            ) : (
              <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={handleDragEnd}
              >
                <SortableContext items={blocks.map((b) => b.id)} strategy={verticalListSortingStrategy}>
                  <div className="space-y-2">
                    {blocks.map((block, index) => (
                      <SortableBlockRow
                        key={block.id}
                        block={block}
                        index={index}
                        total={blocks.length}
                        selectedBlockId={selectedBlockId}
                        menuOpen={openMenuId === block.id}
                        onToggleMenu={() => setOpenMenuId(openMenuId === block.id ? null : block.id)}
                        onEdit={() => openEditor(block.id)}
                        onMove={(direction) => moveBlock(index, direction)}
                        onDelete={() => { deleteBlock(block.id); setOpenMenuId(null); }}
                        t={t}
                      />
                    ))}
                  </div>
                </SortableContext>
              </DndContext>
            )}
          </section>

          {/* Live page preview (phone mockup) */}
          <section>
            <div className="px-1 mb-2 flex items-center gap-1.5">
              <Smartphone className="w-3.5 h-3.5 text-zinc-400" />
              <h3 className="text-xs font-bold uppercase tracking-wide text-zinc-400">
                {t('mobile.pagePreview')}
              </h3>
            </div>
            <p className="px-1 mb-3 text-[11px] text-zinc-400">{t('mobile.previewHint')}</p>

            <div className="flex justify-center">
              {/* Phone frame */}
              <div className="relative w-[260px] rounded-[2rem] bg-zinc-900 p-2 shadow-xl">
                {/* Notch */}
                <div className="absolute top-2 left-1/2 -translate-x-1/2 w-20 h-4 bg-zinc-900 rounded-b-xl z-10" />
                <div
                  className="relative h-[460px] overflow-y-auto overflow-x-hidden rounded-[1.6rem] bg-white"
                  style={{ backgroundColor: activeTheme.colors.backgroundColor }}
                >
                  {blocks.length === 0 ? (
                    <div className="h-full flex items-center justify-center text-zinc-300 text-xs px-6 text-center">
                      {t('mobile.emptyList')}
                    </div>
                  ) : (
                    <div className="origin-top">
                      {blocks.map((block) => (
                        <div
                          key={block.id}
                          onClick={() => openEditor(block.id)}
                          className={cn(
                            'relative cursor-pointer',
                            selectedBlockId === block.id && 'ring-2 ring-yellow-400 ring-inset',
                          )}
                        >
                          <BlockRenderer block={block} theme={activeTheme} />
                        </div>
                      ))}
                    </div>
                  )}

                  {/* Floating WhatsApp inside preview */}
                  {pageSettings.showFloatingWhatsapp && (
                    <div className="sticky bottom-3 float-right mr-3 z-30">
                      <span className="flex items-center justify-center w-9 h-9 bg-green-500 text-white rounded-full shadow-lg">
                        <MessageCircle className="w-4 h-4" />
                      </span>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </section>
        </main>
      )}

      {/* Bottom Sheet: Add Block */}
      {activeSheet === 'add' && (
        <>
          <div className="fixed inset-0 bg-black/30 z-40" onClick={() => setActiveSheet('none')} />
          <div className="fixed bottom-0 left-0 right-0 bg-white rounded-t-2xl shadow-2xl z-50 h-[80vh] flex flex-col">
            <div className="flex items-center justify-between px-4 py-3 border-b border-zinc-100 shrink-0">
              <h3 className="font-bold text-base">{t('toolbox.add')}</h3>
              <button onClick={() => setActiveSheet('none')} className="p-1.5 bg-zinc-100 rounded-full">
                <ChevronDown className="w-5 h-5" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto min-h-0">
              <BlockToolbox onAddBlock={handleAddBlock} />
            </div>
          </div>
        </>
      )}

      {/* Bottom Sheet: Edit Block */}
      {activeSheet === 'edit' && selectedBlock && (
        <>
          <div className="fixed inset-0 bg-black/30 z-40" onClick={() => setActiveSheet('none')} />
          <div className="fixed bottom-0 left-0 right-0 bg-white rounded-t-2xl shadow-2xl z-50 h-[80vh] flex flex-col">
            <div className="flex items-center justify-between px-4 py-3 border-b border-zinc-100 shrink-0">
              <h3 className="font-bold text-base capitalize">{t('toolbox.editBlock')} · {selectedBlock.type}</h3>
              <button onClick={() => setActiveSheet('none')} className="p-1.5 bg-zinc-100 rounded-full">
                <ChevronDown className="w-5 h-5" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto min-h-0">
              <PropertyPanel
                selectedBlock={selectedBlock}
                activeTheme={activeTheme}
                updateBlockContent={updateBlockContent}
                updateBlockStyles={updateBlockStyles}
                handleFileUpload={handleFileUpload}
              />
            </div>
            <div className="px-4 py-3 border-t border-zinc-100 flex gap-2 shrink-0">
              <button
                onClick={() => setActiveSheet('none')}
                className="flex-1 py-2.5 bg-zinc-100 text-zinc-700 rounded-xl text-sm font-semibold"
              >
                {t('toolbox.close')}
              </button>
              <button
                onClick={() => { onSave(); setActiveSheet('none'); }}
                disabled={isSaving}
                className="flex-1 py-2.5 bg-black text-white rounded-xl text-sm font-semibold disabled:opacity-50"
              >
                {t('chrome.save')}
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  );
};
