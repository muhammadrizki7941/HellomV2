import React, { useState } from 'react';
import {
  Plus, Settings, ChevronDown,
  Sparkles, Eye, Globe, ArrowUp, ArrowDown, Trash2, MessageCircle, Save,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Block, BlockType, BlockStyles } from '../types';
import { BlockToolbox } from './BlockToolbox';
import { PropertyPanel } from './PropertyPanel';
import { BlockRenderer } from './BlockRenderer';
import { useLang } from '../i18n';

interface MobileEditorProps {
  blocks: Block[];
  selectedBlockId: string | null;
  setSelectedBlockId: (id: string | null) => void;
  activeTheme: any;
  addBlock: (type: BlockType) => void;
  updateBlockContent: (id: string, newContent: any) => void;
  updateBlockStyles: (id: string, newStyles: BlockStyles) => void;
  moveBlock: (index: number, direction: 'up' | 'down') => void;
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

export const MobileEditor: React.FC<MobileEditorProps> = ({
  blocks,
  selectedBlockId,
  setSelectedBlockId,
  activeTheme,
  addBlock,
  updateBlockContent,
  updateBlockStyles,
  moveBlock,
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
  const [activeSheet, setActiveSheet] = useState<'none' | 'add' | 'edit'>('none');

  const handleAddBlock = (type: BlockType) => {
    addBlock(type);
    setActiveSheet('none');
  };

  const selectedBlock = blocks.find((b) => b.id === selectedBlockId);

  return (
    <div className="flex flex-col w-full h-full bg-zinc-100 overflow-hidden">
      {/* Compact Mobile Toolbar */}
      <header className="h-12 bg-white border-b border-zinc-200 flex items-center justify-between px-3 shrink-0 z-20">
        {/* Left: AI */}
        <button
          onClick={() => setShowAiModal(true)}
          className="flex items-center gap-1.5 px-2.5 py-1.5 text-purple-600 bg-purple-50 rounded-lg text-xs font-semibold"
        >
          <Sparkles className="w-3.5 h-3.5" />
          AI
        </button>

        {/* Right: actions */}
        <div className="flex items-center gap-1">
          <button
            onClick={() => setLang(lang === 'id' ? 'en' : 'id')}
            title={t('chrome.language')}
            className="px-2 py-1.5 bg-zinc-100 text-zinc-700 rounded-lg text-xs font-bold uppercase"
          >
            {lang}
          </button>
          <button
            onClick={onSave}
            disabled={isSaving}
            title={t('chrome.save')}
            className="flex items-center gap-1 px-2.5 py-1.5 bg-zinc-100 text-zinc-700 rounded-lg text-xs font-semibold disabled:opacity-50"
          >
            <Save className="w-3.5 h-3.5" />
            {isSaving ? '...' : t('chrome.save')}
          </button>
          <button
            onClick={() => setIsPreview(!isPreview)}
            title={isPreview ? 'Edit' : 'Preview'}
            className={cn(
              'p-2 rounded-lg',
              isPreview ? 'bg-zinc-900 text-white' : 'text-zinc-500 hover:bg-zinc-100',
            )}
          >
            <Eye className="w-4 h-4" />
          </button>
          <button
            onClick={() => setShowSettingsModal(true)}
            title="Pengaturan"
            className="p-2 text-zinc-500 hover:bg-zinc-100 rounded-lg"
          >
            <Settings className="w-4 h-4" />
          </button>
          <button
            onClick={onPublish}
            disabled={isSaving}
            title="Publish"
            className="p-2 bg-black text-white rounded-lg disabled:opacity-50"
          >
            <Globe className="w-4 h-4" />
          </button>
        </div>
      </header>

      {/* Canvas */}
      <main
        className="flex-1 overflow-y-auto overflow-x-hidden relative"
        style={{ paddingBottom: isPreview ? 0 : '5rem' }}
        onClick={() => {
          if (activeSheet === 'edit') setActiveSheet('none');
        }}
      >
        <div
          className="min-h-full w-full bg-white relative"
          style={{ backgroundColor: activeTheme.colors.backgroundColor }}
        >
          {blocks.length === 0 ? (
            <div className="h-64 flex flex-col items-center justify-center text-zinc-400 p-6 text-center">
              <Plus className="w-10 h-10 mb-3 opacity-20" />
              <p className="text-sm">{t('canvas.emptyMobile')}</p>
            </div>
          ) : (
            blocks.map((block, index) => (
              <div
                key={block.id}
                onClick={(e) => {
                  e.stopPropagation();
                  if (!isPreview) {
                    setSelectedBlockId(block.id);
                    setActiveSheet('edit');
                  }
                }}
                className={cn(
                  'relative transition-all',
                  !isPreview && selectedBlockId === block.id && 'ring-2 ring-yellow-400 ring-inset z-10',
                )}
              >
                {/* Block action buttons */}
                {!isPreview && selectedBlockId === block.id && (
                  <div className="absolute right-2 top-2 flex flex-col gap-1 z-20">
                    <button
                      onClick={(e) => { e.stopPropagation(); moveBlock(index, 'up'); }}
                      disabled={index === 0}
                      className="w-8 h-8 flex items-center justify-center bg-white shadow-md rounded-full text-zinc-600 disabled:opacity-30 border border-zinc-100"
                    >
                      <ArrowUp className="w-3.5 h-3.5" />
                    </button>
                    <button
                      onClick={(e) => { e.stopPropagation(); moveBlock(index, 'down'); }}
                      disabled={index === blocks.length - 1}
                      className="w-8 h-8 flex items-center justify-center bg-white shadow-md rounded-full text-zinc-600 disabled:opacity-30 border border-zinc-100"
                    >
                      <ArrowDown className="w-3.5 h-3.5" />
                    </button>
                    <button
                      onClick={(e) => { e.stopPropagation(); deleteBlock(block.id); }}
                      className="w-8 h-8 flex items-center justify-center bg-white shadow-md rounded-full text-red-500 border border-zinc-100"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                )}

                <BlockRenderer block={block} theme={activeTheme} />
              </div>
            ))
          )}

          {/* Floating WhatsApp */}
          {pageSettings.showFloatingWhatsapp && (
            <div className="absolute bottom-20 right-4 z-30">
              <a
                href={`https://wa.me/${pageSettings.whatsappNumber}?text=${encodeURIComponent(pageSettings.whatsappMessage)}`}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center justify-center w-12 h-12 bg-green-500 text-white rounded-full shadow-2xl"
              >
                <MessageCircle className="w-6 h-6" />
              </a>
            </div>
          )}
        </div>
      </main>

      {/* Bottom Action Bar */}
      {!isPreview && (
        <div className="absolute bottom-0 left-0 right-0 z-30 bg-white border-t border-zinc-200 px-3 py-2 flex items-center gap-2 safe-area-inset-bottom">
          <button
            onClick={() => setActiveSheet(activeSheet === 'add' ? 'none' : 'add')}
            className="flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-zinc-900 text-white rounded-xl text-sm font-bold"
          >
            <Plus className="w-4 h-4" /> {t('toolbox.add')}
          </button>

          {selectedBlockId && (
            <button
              onClick={() => setActiveSheet(activeSheet === 'edit' ? 'none' : 'edit')}
              className="flex-1 flex items-center justify-center gap-1.5 py-2.5 bg-white text-zinc-900 border border-zinc-200 rounded-xl text-sm font-bold"
            >
              <Settings className="w-4 h-4" /> {t('toolbox.editBlock')}
            </button>
          )}
        </div>
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
