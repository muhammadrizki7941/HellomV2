import React, { useState } from 'react';
import { 
  Plus, Settings, ChevronDown,
  Sparkles, Eye, Globe, ArrowUp, ArrowDown, Trash2, MessageCircle
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Block, BlockType, BlockStyles } from '../types';
import { BlockToolbox } from './BlockToolbox';
import { PropertyPanel } from './PropertyPanel';
import { BlockRenderer } from './BlockRenderer';

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
  pageSettings
}) => {
  const [activeSheet, setActiveSheet] = useState<'none' | 'add' | 'edit'>('none');

  const handleAddBlock = (type: BlockType) => {
    addBlock(type);
    setActiveSheet('none');
    // Scroll to bottom?
  };

  const selectedBlock = blocks.find(b => b.id === selectedBlockId);

  return (
    <div className="flex flex-col h-full bg-zinc-100 relative">
      {/* Mobile Header */}
      <header className="h-14 bg-white border-b border-zinc-200 flex items-center justify-between px-4 shrink-0 z-20">
        <div className="flex items-center gap-2">
           <button 
            onClick={() => setShowAiModal(true)}
            className="p-2 text-purple-600 bg-purple-50 rounded-lg"
          >
            <Sparkles className="w-5 h-5" />
          </button>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={onSave} disabled={isSaving} className="p-2 bg-zinc-100 text-zinc-700 rounded-lg shadow-sm disabled:opacity-60">
            Simpan
          </button>
          <button 
            onClick={() => setIsPreview(!isPreview)}
            className={cn("p-2 rounded-lg", isPreview ? "bg-zinc-100 text-zinc-900" : "text-zinc-500")}
          >
            <Eye className="w-5 h-5" />
          </button>
          <button 
            onClick={() => setShowSettingsModal(true)}
            className="p-2 text-zinc-500 hover:bg-zinc-50 rounded-lg"
          >
            <Settings className="w-5 h-5" />
          </button>
          <button onClick={onPublish} disabled={isSaving} className="p-2 bg-black text-white rounded-lg shadow-sm disabled:opacity-60">
            <Globe className="w-5 h-5" />
          </button>
        </div>
      </header>

      {/* Main Canvas Area */}
      <main className="flex-1 overflow-y-auto relative pb-20" onClick={() => {
        if (activeSheet === 'edit') setActiveSheet('none');
      }}>
        <div 
          className="min-h-full bg-white shadow-sm relative"
          style={{ backgroundColor: activeTheme.colors.backgroundColor }}
        >
          {blocks.length === 0 ? (
            <div className="h-[60vh] flex flex-col items-center justify-center text-zinc-400 p-8 text-center">
              <Plus className="w-12 h-12 mb-4 opacity-20" />
              <p>Tap "+" below to add your first block</p>
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
                  "relative transition-all",
                  !isPreview && selectedBlockId === block.id && "ring-2 ring-yellow-400 z-10"
                )}
              >
                {/* Block Actions Overlay (Mobile) */}
                {!isPreview && selectedBlockId === block.id && (
                  <div className="absolute right-2 top-2 flex flex-col gap-1 z-20">
                     <button 
                        onClick={(e) => { e.stopPropagation(); moveBlock(index, 'up'); }}
                        disabled={index === 0}
                        className="p-2 bg-white shadow-md rounded-full text-zinc-600 disabled:opacity-30 border border-zinc-100"
                      >
                        <ArrowUp className="w-4 h-4" />
                      </button>
                      <button 
                        onClick={(e) => { e.stopPropagation(); moveBlock(index, 'down'); }}
                        disabled={index === blocks.length - 1}
                        className="p-2 bg-white shadow-md rounded-full text-zinc-600 disabled:opacity-30 border border-zinc-100"
                      >
                        <ArrowDown className="w-4 h-4" />
                      </button>
                      <button 
                        onClick={(e) => { e.stopPropagation(); deleteBlock(block.id); }}
                        className="p-2 bg-white shadow-md rounded-full text-red-500 border border-zinc-100"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                  </div>
                )}
                
                <BlockRenderer block={block} theme={activeTheme} />
              </div>
            ))
          )}

          {/* Floating WhatsApp Widget */}
          {pageSettings.showFloatingWhatsapp && (
            <div className="absolute bottom-24 right-4 z-30">
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

      {/* Bottom Floating Action Bar */}
      {!isPreview && (
        <div className="absolute bottom-6 left-1/2 -translate-x-1/2 flex items-center gap-4 z-30">
          <button 
            onClick={() => setActiveSheet(activeSheet === 'add' ? 'none' : 'add')}
            className="flex items-center gap-2 px-6 py-3 bg-zinc-900 text-white rounded-full shadow-xl hover:scale-105 transition-transform font-bold"
          >
            <Plus className="w-5 h-5" /> Add Block
          </button>
          
          {selectedBlockId && (
            <button 
              onClick={() => setActiveSheet(activeSheet === 'edit' ? 'none' : 'edit')}
              className="flex items-center gap-2 px-6 py-3 bg-white text-zinc-900 border border-zinc-200 rounded-full shadow-xl hover:scale-105 transition-transform font-bold"
            >
              <Settings className="w-5 h-5" /> Edit
            </button>
          )}
        </div>
      )}

      {/* Bottom Sheet: Add Block */}
      {activeSheet === 'add' && (
        <>
          <div className="fixed inset-0 bg-black/20 z-40" onClick={() => setActiveSheet('none')} />
          <div className="fixed bottom-0 left-0 right-0 bg-white rounded-t-2xl shadow-2xl z-50 max-h-[70vh] overflow-y-auto animate-in slide-in-from-bottom duration-300">
            <div className="sticky top-0 bg-white border-b border-zinc-100 p-4 flex justify-between items-center">
              <h3 className="font-bold text-lg">Add New Block</h3>
              <button onClick={() => setActiveSheet('none')} className="p-2 bg-zinc-100 rounded-full">
                <ChevronDown className="w-5 h-5" />
              </button>
            </div>
            <BlockToolbox onAddBlock={handleAddBlock} />
          </div>
        </>
      )}

      {/* Bottom Sheet: Edit Block */}
      {activeSheet === 'edit' && selectedBlock && (
        <>
          <div className="fixed inset-0 bg-black/20 z-40" onClick={() => setActiveSheet('none')} />
          <div className="fixed bottom-0 left-0 right-0 bg-white rounded-t-2xl shadow-2xl z-50 max-h-[70vh] overflow-y-auto animate-in slide-in-from-bottom duration-300">
            <div className="sticky top-0 bg-white border-b border-zinc-100 p-4 flex justify-between items-center">
              <h3 className="font-bold text-lg">Edit {selectedBlock.type}</h3>
              <button onClick={() => setActiveSheet('none')} className="p-2 bg-zinc-100 rounded-full">
                <ChevronDown className="w-5 h-5" />
              </button>
            </div>
            <PropertyPanel 
              selectedBlock={selectedBlock}
              activeTheme={activeTheme}
              updateBlockContent={updateBlockContent}
              updateBlockStyles={updateBlockStyles}
              handleFileUpload={handleFileUpload}
            />
          </div>
        </>
      )}
    </div>
  );
};
