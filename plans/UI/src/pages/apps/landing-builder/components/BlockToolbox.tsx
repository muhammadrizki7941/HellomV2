import React from 'react';
import { 
  Layout, Type, Image as ImageIcon, MousePointer2, 
  Video, ShoppingBag, FileText, Share2, ClipboardList
} from 'lucide-react';
import { BlockType } from '../types';

interface BlockToolboxProps {
  onAddBlock: (type: BlockType) => void;
}

export const BlockToolbox: React.FC<BlockToolboxProps> = ({ onAddBlock }) => {
  return (
    <div className="p-4">
      <h3 className="text-xs font-bold text-zinc-400 uppercase tracking-wider mb-4">Add Blocks</h3>
      <div className="grid grid-cols-2 gap-3">
        <button onClick={() => onAddBlock('hero')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <Layout className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">Hero</span>
        </button>
        <button onClick={() => onAddBlock('banner')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <ImageIcon className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">Banner</span>
        </button>
        <button onClick={() => onAddBlock('features')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <Layout className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">Features</span>
        </button>
        <button onClick={() => onAddBlock('product')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <ShoppingBag className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">Product</span>
        </button>
        <button onClick={() => onAddBlock('video')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <Video className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">Video</span>
        </button>
        <button onClick={() => onAddBlock('text')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <Type className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">Text</span>
        </button>
        <button onClick={() => onAddBlock('image')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <ImageIcon className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">Image</span>
        </button>
        <button onClick={() => onAddBlock('pdf')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <FileText className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">PDF</span>
        </button>
        <button onClick={() => onAddBlock('cta')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <MousePointer2 className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">CTA</span>
        </button>
        <button onClick={() => onAddBlock('form')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <ClipboardList className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">Form</span>
        </button>
        <button onClick={() => onAddBlock('social')} className="flex flex-col items-center justify-center p-4 rounded-xl border border-zinc-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all gap-2 group">
          <Share2 className="w-6 h-6 text-zinc-400 group-hover:text-yellow-600" />
          <span className="text-xs font-medium text-zinc-600 group-hover:text-zinc-900">Social</span>
        </button>
      </div>
    </div>
  );
};
