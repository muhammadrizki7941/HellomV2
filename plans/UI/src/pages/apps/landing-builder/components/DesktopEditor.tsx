import React from 'react';
import { 
  Plus, Settings, Eye, Save, Globe, 
  Sparkles, Check, Trash2, MessageCircle, GripVertical
} from 'lucide-react';
import { 
  DndContext, 
  closestCenter, 
  KeyboardSensor, 
  PointerSensor, 
  useSensor, 
  useSensors,
  DragEndEvent
} from '@dnd-kit/core';
import {
  arrayMove,
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

interface DesktopEditorProps {
  blocks: Block[];
  selectedBlockId: string | null;
  setSelectedBlockId: (id: string | null) => void;
  activeTheme: any;
  activeThemeId: string;
  setActiveThemeId: (id: string) => void;
  THEMES: any[];
  addBlock: (type: BlockType) => void;
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

const SortableBlock = ({ 
  block, 
  index, 
  selectedBlockId, 
  setSelectedBlockId, 
  deleteBlock, 
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
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <div 
      ref={setNodeRef} 
      style={style}
      onClick={() => {
        if (!isPreview) {
          setSelectedBlockId(block.id);
        }
      }}
      className={cn(
        "relative group transition-all",
        !isPreview && "hover:ring-2 hover:ring-yellow-400/50 cursor-pointer",
        selectedBlockId === block.id && !isPreview && "ring-2 ring-yellow-400 z-10"
      )}
    >
      {/* Block Actions (Hover) */}
      {!isPreview && selectedBlockId === block.id && (
        <div className="absolute right-4 top-4 flex items-center gap-1 bg-white shadow-lg rounded-lg border border-zinc-100 p-1 z-20">
          <button 
            {...attributes} 
            {...listeners}
            className="p-1.5 hover:bg-zinc-100 rounded text-zinc-500 cursor-grab active:cursor-grabbing"
            title="Drag to reorder"
          >
            <GripVertical className="w-4 h-4" />
          </button>
          <div className="w-px h-4 bg-zinc-200 mx-1"></div>
          <button 
            onClick={(e) => { e.stopPropagation(); deleteBlock(block.id); }}
            className="p-1.5 hover:bg-red-50 text-zinc-500 hover:text-red-600 rounded"
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
  const selectedBlock = blocks.find(b => b.id === selectedBlockId);
  
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    
    if (over && active.id !== over.id) {
      const oldIndex = blocks.findIndex((b) => b.id === active.id);
      const newIndex = blocks.findIndex((b) => b.id === over.id);
      reorderBlocks(oldIndex, newIndex);
    }
  };

  return (
    <div className="h-[calc(100vh-12rem)] flex flex-col bg-zinc-50 border border-zinc-200 rounded-xl overflow-hidden shadow-sm">
      {/* Top Bar */}
      <header className="h-14 bg-white border-b border-zinc-200 flex items-center justify-between px-4 z-20">
        <div className="flex items-center gap-4">
          <span className="px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-500">Draft Mode</span>
          
          {/* Theme Selector */}
          <div className="flex items-center gap-2 ml-4 border-l border-zinc-200 pl-4">
            <span className="text-xs font-bold text-zinc-500">Theme:</span>
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
          <button 
            onClick={() => setShowAiModal(true)}
            className="flex items-center gap-2 px-3 py-1.5 text-sm font-bold text-purple-600 bg-purple-50 hover:bg-purple-100 border border-purple-200 rounded-lg transition-colors mr-2"
          >
            <Sparkles className="w-4 h-4" /> <span className="hidden md:inline">AI Magic</span>
          </button>
          <button 
            onClick={() => setShowSettingsModal(true)}
            className="flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 rounded-lg transition-colors mr-2"
            title="Page Settings"
          >
            <Settings className="w-4 h-4" /> <span className="hidden md:inline">Settings</span>
          </button>
          <button 
            onClick={() => setIsPreview(!isPreview)}
            className="flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 rounded-lg transition-colors"
          >
            <Eye className="w-4 h-4" /> {isPreview ? 'Edit' : 'Preview'}
          </button>
          <button onClick={onSave} disabled={isSaving} className="flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-zinc-600 hover:bg-zinc-50 rounded-lg transition-colors disabled:opacity-60">
            <Save className="w-4 h-4" /> Simpan
          </button>
          <button onClick={onPublish} disabled={isSaving} className="flex items-center gap-2 px-3 py-1.5 bg-black text-white text-sm font-bold rounded-lg hover:bg-zinc-800 transition-colors shadow-sm disabled:opacity-60">
            <Globe className="w-4 h-4" /> Publish
          </button>
        </div>
      </header>

      <div className="flex-1 flex overflow-hidden relative">
        {/* Left Sidebar: Tools (Hidden in Preview) */}
        {!isPreview && (
          <aside className="w-64 bg-white border-r border-zinc-200 flex flex-col overflow-y-auto z-10">
            <BlockToolbox onAddBlock={addBlock} />
          </aside>
        )}

        {/* Center: Canvas */}
        <main className="flex-1 overflow-y-auto bg-zinc-100 p-8 relative">
          <div className={cn(
            "max-w-5xl mx-auto min-h-[800px] shadow-sm transition-all duration-300 bg-white relative",
            isPreview ? "shadow-2xl scale-100" : "scale-[0.98]"
          )}
          style={{ backgroundColor: activeTheme.colors.backgroundColor }}
          >
            {blocks.length === 0 ? (
              <div className="h-full flex flex-col items-center justify-center text-zinc-400 py-40">
                <Plus className="w-12 h-12 mb-4 opacity-20" />
                <p>Klik block di sidebar untuk mulai membuat halaman</p>
              </div>
            ) : (
              <DndContext 
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={handleDragEnd}
              >
                <SortableContext 
                  items={blocks.map(b => b.id)}
                  strategy={verticalListSortingStrategy}
                >
                  {blocks.map((block, index) => (
                    <SortableBlock 
                      key={block.id}
                      block={block}
                      index={index}
                      selectedBlockId={selectedBlockId}
                      setSelectedBlockId={setSelectedBlockId}
                      deleteBlock={deleteBlock}
                      activeTheme={activeTheme}
                      isPreview={isPreview}
                    />
                  ))}
                </SortableContext>
              </DndContext>
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
    </div>
  );
};
