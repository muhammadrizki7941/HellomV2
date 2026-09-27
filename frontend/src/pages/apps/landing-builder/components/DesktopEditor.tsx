import React, { useState } from 'react';
import {
  Plus, Settings, Eye, Save, Globe,
  Sparkles, Check, Trash2, MessageCircle, GripVertical,
  PanelLeftClose, PanelLeftOpen, Languages, Copy
} from 'lucide-react';
import {
  DndContext,
  DragOverlay,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  useDroppable,
  DragEndEvent,
  DragStartEvent,
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
import { BlockToolbox } from './BlockToolbox';
import { PropertyPanel } from './PropertyPanel';
import { BlockRenderer } from './BlockRenderer';
import { BLOCK_CATALOG } from '../blockCatalog';
import { useLang } from '../i18n';

interface DesktopEditorProps {
  blocks: Block[];
  selectedBlockId: string | null;
  setSelectedBlockId: (id: string | null) => void;
  activeTheme: any;
  activeThemeId: string;
  setActiveThemeId: (id: string) => void;
  THEMES: any[];
  addBlock: (type: BlockType) => void;
  addBlockAt?: (type: BlockType, index: number) => void;
  updateBlockContent: (id: string, newContent: any) => void;
  updateBlockStyles: (id: string, newStyles: BlockStyles) => void;
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
 * Drop strip between blocks. Doubles as:
 *  - a click "+" to insert at this position (when idle), and
 *  - a droppable target highlighted while dragging a component / block.
 */
const InsertZone: React.FC<{
  index: number;
  dragging: boolean;
  onInsert: (type: BlockType, index: number) => void;
}> = ({ index, dragging, onInsert }) => {
  const { t } = useLang();
  const { setNodeRef, isOver } = useDroppable({ id: `insert:${index}`, data: { index } });
  const [open, setOpen] = useState(false);

  return (
    <div
      ref={setNodeRef}
      className={cn('relative z-20 transition-all', dragging ? (isOver ? 'h-20' : 'h-12') : 'h-4 group/ins')}
    >
      {dragging ? (
        <div className={cn(
          'absolute inset-x-4 inset-y-1 flex items-center justify-center rounded-xl border-2 border-dashed text-xs font-semibold transition-colors',
          isOver ? 'border-yellow-500 bg-yellow-50 text-yellow-700' : 'border-zinc-300 text-zinc-400'
        )}>
          {isOver ? t('canvas.dropHere') : t('canvas.dropSlot')}
        </div>
      ) : (
        <>
          <div className={cn(
            'absolute inset-x-0 top-1/2 flex -translate-y-1/2 items-center px-6 transition-opacity',
            open ? 'opacity-100' : 'opacity-0 group-hover/ins:opacity-100'
          )}>
            <div className="h-px flex-1 bg-yellow-400" />
            <button
              onClick={() => setOpen((o) => !o)}
              className="mx-2 inline-flex items-center gap-1 rounded-full bg-yellow-400 px-2.5 py-1 text-xs font-bold text-black shadow-md hover:bg-yellow-300"
            >
              <Plus className="w-3.5 h-3.5" /> {t('toolbox.add')}
            </button>
            <div className="h-px flex-1 bg-yellow-400" />
          </div>

          {open && (
            <>
              <div className="fixed inset-0 z-30" onClick={() => setOpen(false)} />
              <div className="absolute left-1/2 top-full z-40 mt-1 w-[22rem] -translate-x-1/2 rounded-2xl border border-zinc-200 bg-white p-2 shadow-2xl">
                <div className="grid max-h-72 grid-cols-3 gap-1.5 overflow-y-auto">
                  {BLOCK_CATALOG.map((item) => {
                    const Icon = item.icon;
                    return (
                      <button
                        key={item.type}
                        onClick={() => { onInsert(item.type, index); setOpen(false); }}
                        className="flex flex-col items-center gap-1 rounded-xl border border-transparent p-2 text-center hover:border-yellow-400 hover:bg-yellow-50"
                      >
                        <Icon className="h-5 w-5 text-zinc-500" />
                        <span className="text-[11px] font-semibold leading-tight text-zinc-700">{t(item.labelKey)}</span>
                      </button>
                    );
                  })}
                </div>
              </div>
            </>
          )}
        </>
      )}
    </div>
  );
};

/** Big drop target shown when the canvas has no blocks yet. */
const EmptyCanvasDrop: React.FC = () => {
  const { t } = useLang();
  const { setNodeRef, isOver } = useDroppable({ id: 'insert:0', data: { index: 0 } });
  return (
    <div
      ref={setNodeRef}
      className={cn(
        'm-8 flex h-72 flex-col items-center justify-center rounded-2xl border-2 border-dashed text-center transition-colors',
        isOver ? 'border-yellow-500 bg-yellow-50 text-yellow-700' : 'border-zinc-300 text-zinc-400'
      )}
    >
      <Plus className="mb-3 h-10 w-10 opacity-30" />
      <p className="text-sm font-medium">{t('canvas.dropEmpty')}</p>
    </div>
  );
};

const SortableBlock = ({
  block,
  selectedBlockId,
  setSelectedBlockId,
  deleteBlock,
  duplicateBlock,
  activeTheme,
  isPreview
}: any) => {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging
  } = useSortable({ id: block.id });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    zIndex: isDragging ? 50 : 'auto',
  };

  const isSelected = selectedBlockId === block.id && !isPreview;

  return (
    <div
      ref={setNodeRef}
      style={style}
      onClick={() => {
        if (!isPreview) setSelectedBlockId(block.id);
      }}
      className={cn(
        'relative group transition-all',
        !isPreview && 'cursor-pointer hover:ring-2 hover:ring-yellow-400/50',
        isSelected && 'ring-2 ring-yellow-400 z-10',
        isDragging && 'opacity-40'
      )}
    >
      {!isPreview && (
        <div className={cn(
          'absolute right-4 top-4 z-20 flex items-center gap-1 rounded-lg border border-zinc-100 bg-white p-1 shadow-lg transition-opacity',
          isSelected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'
        )}>
          <button
            {...attributes}
            {...listeners}
            className="cursor-grab rounded p-1.5 text-zinc-500 hover:bg-zinc-100 active:cursor-grabbing"
            title="Seret untuk pindahkan"
            onClick={(e) => e.stopPropagation()}
          >
            <GripVertical className="w-4 h-4" />
          </button>
          <button
            onClick={(e) => { e.stopPropagation(); duplicateBlock(block.id); }}
            className="rounded p-1.5 text-zinc-500 hover:bg-zinc-100"
            title="Duplikat"
          >
            <Copy className="w-4 h-4" />
          </button>
          <div className="mx-1 h-4 w-px bg-zinc-200" />
          <button
            onClick={(e) => { e.stopPropagation(); deleteBlock(block.id); }}
            className="rounded p-1.5 text-zinc-500 hover:bg-red-50 hover:text-red-600"
            title="Hapus"
          >
            <Trash2 className="w-4 h-4" />
          </button>
        </div>
      )}

      <BlockRenderer block={block} theme={activeTheme} />
    </div>
  );
};

export const DesktopEditor: React.FC<DesktopEditorProps> = ({
  blocks,
  selectedBlockId,
  setSelectedBlockId,
  activeTheme,
  activeThemeId,
  setActiveThemeId,
  THEMES,
  addBlock,
  addBlockAt,
  updateBlockContent,
  updateBlockStyles,
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
  pageSettings
}) => {
  const { t, lang, setLang } = useLang();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [activePaletteType, setActivePaletteType] = useState<BlockType | null>(null);

  const selectedBlock = blocks.find(b => b.id === selectedBlockId);
  const activeBlock = activeId ? blocks.find(b => b.id === activeId) : null;
  const dragging = activeId !== null || activePaletteType !== null;
  const paletteCatalog = activePaletteType ? BLOCK_CATALOG.find((i) => i.type === activePaletteType) : null;

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  );

  const handleDragStart = (event: DragStartEvent) => {
    const data = event.active.data.current as { source?: string; type?: BlockType } | undefined;
    if (data?.source === 'palette' && data.type) {
      setActivePaletteType(data.type);
    } else {
      setActiveId(String(event.active.id));
    }
  };

  const resetDrag = () => {
    setActiveId(null);
    setActivePaletteType(null);
  };

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    const data = active.data.current as { source?: string; type?: BlockType } | undefined;
    resetDrag();
    if (!over) return;

    const overId = String(over.id);
    const insertIndexFromOver = (): number => {
      if (overId.startsWith('insert:')) return Number(overId.slice(7));
      const bi = blocks.findIndex((b) => b.id === overId);
      return bi >= 0 ? bi + 1 : blocks.length;
    };

    // Dropping a component from the palette
    if (data?.source === 'palette' && data.type) {
      insertAt(data.type, insertIndexFromOver());
      return;
    }

    // Reordering an existing block
    const oldIndex = blocks.findIndex((b) => b.id === active.id);
    if (oldIndex < 0) return;
    let newIndex: number;
    if (overId.startsWith('insert:')) {
      const ins = Number(overId.slice(7));
      newIndex = ins > oldIndex ? ins - 1 : ins;
    } else {
      newIndex = blocks.findIndex((b) => b.id === overId);
    }
    if (newIndex >= 0 && newIndex !== oldIndex) {
      reorderBlocks(oldIndex, newIndex);
    }
  };

  const moveBlock = (index: number, direction: 'up' | 'down') => {
    const target = direction === 'up' ? index - 1 : index + 1;
    if (target < 0 || target >= blocks.length) return;
    reorderBlocks(index, target);
  };

  const duplicateBlock = (id: string) => {
    const index = blocks.findIndex((b) => b.id === id);
    if (index < 0 || !addBlockAt) return;
    addBlockAt(blocks[index].type, index + 1);
  };

  const insertAt = (type: BlockType, index: number) => {
    if (addBlockAt) addBlockAt(type, index);
    else addBlock(type);
  };

  return (
    <div className="h-[calc(100vh-12rem)] flex flex-col bg-zinc-50 border border-zinc-200 rounded-xl overflow-hidden shadow-sm">
      {/* Top Bar */}
      <header className="h-14 bg-white border-b border-zinc-200 flex items-center justify-between px-4 z-20">
        <div className="flex items-center gap-3">
          {!isPreview && (
            <button
              onClick={() => setSidebarOpen((v) => !v)}
              title={sidebarOpen ? t('chrome.hideSidebar') : t('chrome.showSidebar')}
              className="p-2 rounded-lg text-zinc-500 hover:bg-zinc-100 transition-colors"
            >
              {sidebarOpen ? <PanelLeftClose className="w-5 h-5" /> : <PanelLeftOpen className="w-5 h-5" />}
            </button>
          )}

          <span className="px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-500">{t('chrome.draft')}</span>

          <div className="flex items-center gap-2 ml-2 border-l border-zinc-200 pl-3">
            <span className="text-xs font-bold text-zinc-500 hidden lg:inline">{t('chrome.theme')}:</span>
            <div className="flex gap-1">
              {THEMES.map(theme => (
                <button
                  key={theme.id}
                  onClick={() => setActiveThemeId(theme.id)}
                  className={cn(
                    "w-6 h-6 rounded-full border-2 transition-all flex items-center justify-center",
                    activeThemeId === theme.id ? "border-zinc-900 scale-110" : "border-transparent hover:scale-105"
                  )}
                  style={{ backgroundColor: theme.colors.buttonColor }}
                  title={theme.name}
                >
                  {activeThemeId === theme.id && <Check className="w-3 h-3 text-white mix-blend-difference" />}
                </button>
              ))}
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <div className="flex items-center rounded-lg border border-zinc-200 overflow-hidden mr-1" title={t('chrome.language')}>
            <Languages className="w-3.5 h-3.5 text-zinc-400 ml-2" />
            {(['id', 'en'] as const).map((code) => (
              <button
                key={code}
                onClick={() => setLang(code)}
                className={cn(
                  'px-2 py-1.5 text-xs font-bold uppercase transition-colors',
                  lang === code ? 'bg-zinc-900 text-white' : 'text-zinc-500 hover:bg-zinc-100'
                )}
              >
                {code}
              </button>
            ))}
          </div>
          <button
            onClick={() => setShowAiModal(true)}
            className="flex items-center gap-2 px-3 py-1.5 text-sm font-bold text-purple-600 bg-purple-50 hover:bg-purple-100 border border-purple-200 rounded-lg transition-colors"
          >
            <Sparkles className="w-4 h-4" /> <span className="hidden md:inline">{t('chrome.ai')}</span>
          </button>
          <button
            onClick={() => setShowSettingsModal(true)}
            className="flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 rounded-lg transition-colors"
            title={t('chrome.settings')}
          >
            <Settings className="w-4 h-4" /> <span className="hidden md:inline">{t('chrome.settings')}</span>
          </button>
          <button
            onClick={() => setIsPreview(!isPreview)}
            className="flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 rounded-lg transition-colors"
          >
            <Eye className="w-4 h-4" /> {isPreview ? t('chrome.edit') : t('chrome.preview')}
          </button>
          <button onClick={onSave} disabled={isSaving} className="flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 rounded-lg transition-colors disabled:opacity-60">
            <Save className="w-4 h-4" /> {isSaving ? t('chrome.saving') : t('chrome.save')}
          </button>
          <button onClick={onPublish} disabled={isSaving} className="flex items-center gap-2 px-3 py-1.5 bg-black text-white text-sm font-bold rounded-lg hover:bg-zinc-800 transition-colors shadow-sm disabled:opacity-60">
            <Globe className="w-4 h-4" /> {t('chrome.publish')}
          </button>
        </div>
      </header>

      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        onDragStart={handleDragStart}
        onDragEnd={handleDragEnd}
        onDragCancel={resetDrag}
      >
        <div className="flex-1 flex overflow-hidden relative">
          {/* Left Sidebar: Tools (collapsible, hidden in Preview) */}
          {!isPreview && sidebarOpen && (
            <aside className="w-72 bg-white border-r border-zinc-200 flex flex-col overflow-hidden z-10">
              <BlockToolbox
                onAddBlock={addBlock}
                enableDrag
                blocks={blocks}
                selectedBlockId={selectedBlockId}
                onSelectBlock={(id) => setSelectedBlockId(id)}
                onMoveBlock={moveBlock}
                onDeleteBlock={deleteBlock}
              />
            </aside>
          )}

          {/* Center: Canvas */}
          <main className="flex-1 overflow-y-auto bg-zinc-100 p-6 lg:p-8 relative">
            <div className={cn(
              "mx-auto min-h-[800px] shadow-sm transition-all duration-300 bg-white relative",
              isPreview ? "shadow-2xl max-w-5xl" : (sidebarOpen ? "max-w-5xl" : "max-w-7xl")
            )}
            style={{ backgroundColor: activeTheme.colors.backgroundColor }}
            >
              {blocks.length === 0 ? (
                isPreview ? (
                  <div className="h-full flex flex-col items-center justify-center text-zinc-400 py-40">
                    <Plus className="w-12 h-12 mb-4 opacity-20" />
                    <p>{t('canvas.empty')}</p>
                  </div>
                ) : (
                  <EmptyCanvasDrop />
                )
              ) : (
                <SortableContext
                  items={blocks.map(b => b.id)}
                  strategy={verticalListSortingStrategy}
                >
                  {!isPreview && <InsertZone index={0} dragging={dragging} onInsert={insertAt} />}
                  {blocks.map((block, index) => (
                    <React.Fragment key={block.id}>
                      <SortableBlock
                        block={block}
                        selectedBlockId={selectedBlockId}
                        setSelectedBlockId={setSelectedBlockId}
                        deleteBlock={deleteBlock}
                        duplicateBlock={duplicateBlock}
                        activeTheme={activeTheme}
                        isPreview={isPreview}
                      />
                      {!isPreview && <InsertZone index={index + 1} dragging={dragging} onInsert={insertAt} />}
                    </React.Fragment>
                  ))}
                </SortableContext>
              )}

              {/* Floating WhatsApp Widget */}
              {pageSettings.showFloatingWhatsapp && (
                <div className="absolute bottom-6 right-6 z-30">
                  <a
                    href={`https://wa.me/${pageSettings.whatsappNumber}?text=${encodeURIComponent(pageSettings.whatsappMessage)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center justify-center w-14 h-14 bg-green-500 text-white rounded-full shadow-2xl hover:scale-110 transition-transform animate-in zoom-in duration-300"
                  >
                    <MessageCircle className="w-8 h-8" />
                  </a>
                </div>
              )}
            </div>
          </main>

          {/* Right Sidebar: Properties (Hidden in Preview) */}
          {!isPreview && (
            <aside className="w-80 bg-white border-l border-zinc-200 flex flex-col overflow-y-auto z-10">
              <PropertyPanel
                selectedBlock={selectedBlock}
                activeTheme={activeTheme}
                updateBlockContent={updateBlockContent}
                updateBlockStyles={updateBlockStyles}
                handleFileUpload={handleFileUpload}
              />
            </aside>
          )}
        </div>

        {/* Drag ghost */}
        <DragOverlay dropAnimation={null}>
          {activeBlock ? (
            <div className="overflow-hidden rounded-lg opacity-90 shadow-2xl ring-2 ring-yellow-400 pointer-events-none">
              <BlockRenderer block={activeBlock} theme={activeTheme} />
            </div>
          ) : paletteCatalog ? (
            <div className="inline-flex items-center gap-2 rounded-xl border border-yellow-400 bg-white px-4 py-3 shadow-2xl">
              <paletteCatalog.icon className="h-5 w-5 text-yellow-600" />
              <span className="text-sm font-bold text-zinc-800">{t(paletteCatalog.labelKey)}</span>
            </div>
          ) : null}
        </DragOverlay>
      </DndContext>
    </div>
  );
};
