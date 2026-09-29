import React from 'react';
import { Sparkles, X } from 'lucide-react';
import { cn } from '@/lib/utils';

interface AiModalProps {
  isOpen: boolean;
  onClose: () => void;
  onGenerate: () => void;
  isGenerating: boolean;
  prompt: {
    name: string;
    description: string;
    tone: string;
  };
  setPrompt: (prompt: any) => void;
}

export const AiModal: React.FC<AiModalProps> = ({
  isOpen,
  onClose,
  onGenerate,
  isGenerating,
  prompt,
  setPrompt
}) => {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-2 md:p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-hidden animate-in fade-in zoom-in duration-200 flex flex-col">
        <div className="p-4 md:p-6 border-b border-zinc-100 flex justify-between items-center bg-gradient-to-r from-purple-50 to-white shrink-0">
          <div className="flex items-center gap-2">
            <div className="p-2 bg-purple-100 rounded-lg text-purple-600">
              <Sparkles className="w-5 h-5" />
            </div>
            <div>
              <h3 className="font-bold text-base md:text-lg text-zinc-900">AI Page Generator</h3>
              <p className="text-xs text-zinc-500">Buat struktur landing lengkap siap edit</p>
            </div>
          </div>
          <button onClick={onClose} className="p-2 hover:bg-zinc-100 rounded-full text-zinc-400 hover:text-zinc-600 shrink-0">
            <X className="w-5 h-5" />
          </button>
        </div>
        
        <div className="p-4 md:p-6 space-y-4 overflow-y-auto flex-1">
          <div className="space-y-2">
            <label className="text-sm font-bold text-zinc-700">1. Nama Produk / Layanan</label>
            <input 
              type="text" 
              value={prompt.name}
              onChange={(e) => setPrompt({...prompt, name: e.target.value})}
              className="w-full px-4 py-2 border border-zinc-300 rounded-xl focus:ring-2 focus:ring-purple-400 focus:border-purple-400 outline-none"
              placeholder="Contoh: Kopi Senja Premium"
            />
          </div>

          <div className="space-y-2">
            <label className="text-sm font-bold text-zinc-700">2. Deskripsi Detail</label>
            <textarea 
              value={prompt.description}
              onChange={(e) => setPrompt({...prompt, description: e.target.value})}
              rows={3}
              className="w-full px-4 py-2 border border-zinc-300 rounded-xl focus:ring-2 focus:ring-purple-400 focus:border-purple-400 outline-none resize-none"
              placeholder="Jelaskan produk, target audiens, harga, promo, keunggulan, dan CTA yang diinginkan..."
            />
          </div>

          <div className="space-y-2">
            <label className="text-sm font-bold text-zinc-700">3. Gaya Bahasa</label>
            <div className="grid grid-cols-3 gap-2">
              {['Professional', 'Santai', 'Persuasif'].map((tone) => (
                <button
                  key={tone}
                  onClick={() => setPrompt({...prompt, tone: tone.toLowerCase()})}
                  className={cn(
                    "px-3 py-2 text-xs md:text-sm font-medium rounded-lg border transition-all",
                    prompt.tone === tone.toLowerCase()
                      ? "bg-purple-50 border-purple-400 text-purple-700"
                      : "bg-white border-zinc-200 text-zinc-600 hover:bg-zinc-50"
                  )}
                >
                  {tone}
                </button>
              ))}
            </div>
          </div>
        </div>

        <div className="p-4 md:p-6 bg-zinc-50 border-t border-zinc-100 shrink-0">
          <button 
            onClick={onGenerate}
            disabled={isGenerating || !prompt.name || !prompt.description}
            className="w-full py-2 md:py-3 bg-purple-600 text-white font-bold rounded-xl hover:bg-purple-700 transition-all shadow-lg shadow-purple-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 text-sm md:text-base"
          >
            {isGenerating ? (
              <>
                <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                Sedang Membuat...
              </>
            ) : (
              <>
                <Sparkles className="w-5 h-5" />
                Generate Landing Page
              </>
            )}
          </button>
        </div>
      </div>
    </div>
  );
};
