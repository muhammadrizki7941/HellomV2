import React from 'react';
import { MessageCircle, X } from 'lucide-react';

interface SettingsModalProps {
  isOpen: boolean;
  onClose: () => void;
  settings: {
    whatsappNumber: string;
    whatsappMessage: string;
    showFloatingWhatsapp: boolean;
  };
  setSettings: (settings: any) => void;
}

export const SettingsModal: React.FC<SettingsModalProps> = ({
  isOpen,
  onClose,
  settings,
  setSettings
}) => {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-2 md:p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-hidden animate-in fade-in zoom-in duration-200 flex flex-col">
        <div className="p-4 md:p-6 border-b border-zinc-100 flex justify-between items-center shrink-0">
          <h3 className="font-bold text-base md:text-lg text-zinc-900">Page Settings & Widgets</h3>
          <button onClick={onClose} className="p-2 hover:bg-zinc-100 rounded-full text-zinc-400 hover:text-zinc-600">
            <X className="w-5 h-5" />
          </button>
        </div>
        
        <div className="p-4 md:p-6 space-y-6 overflow-y-auto flex-1">
          {/* WhatsApp Widget Settings */}
          <div className="space-y-4">
            <div className="flex items-center gap-2 mb-2">
              <div className="p-2 bg-green-100 rounded-lg text-green-600">
                <MessageCircle className="w-5 h-5" />
              </div>
              <h4 className="font-bold text-zinc-800">Floating WhatsApp Widget</h4>
            </div>
            
            <div className="flex items-center justify-between p-4 bg-zinc-50 rounded-xl border border-zinc-200">
              <span className="text-sm font-medium text-zinc-700">Aktifkan Widget</span>
              <label className="relative inline-flex items-center cursor-pointer">
                <input 
                  type="checkbox" 
                  className="sr-only peer"
                  checked={settings.showFloatingWhatsapp}
                  onChange={(e) => setSettings({...settings, showFloatingWhatsapp: e.target.checked})}
                />
                <div className="w-11 h-6 bg-zinc-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-zinc-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
              </label>
            </div>

            {settings.showFloatingWhatsapp && (
              <div className="space-y-3 animate-in fade-in slide-in-from-top-2">
                <div>
                  <label className="block text-xs font-bold text-zinc-600 mb-1">Nomor WhatsApp</label>
                  <input 
                    type="text" 
                    value={settings.whatsappNumber}
                    onChange={(e) => setSettings({...settings, whatsappNumber: e.target.value})}
                    placeholder="628123456789"
                    className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none"
                  />
                  <p className="text-[10px] text-zinc-400 mt-1">Gunakan format internasional (62...)</p>
                </div>
                <div>
                  <label className="block text-xs font-bold text-zinc-600 mb-1">Pesan Default</label>
                  <textarea 
                    value={settings.whatsappMessage}
                    onChange={(e) => setSettings({...settings, whatsappMessage: e.target.value})}
                    rows={2}
                    className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none resize-none"
                  />
                </div>
              </div>
            )}
          </div>
        </div>

        <div className="p-4 md:p-6 bg-zinc-50 border-t border-zinc-100 shrink-0">
          <button 
            onClick={onClose}
            className="w-full py-2 md:py-3 bg-zinc-900 text-white font-bold rounded-xl hover:bg-zinc-800 transition-all text-sm md:text-base"
          >
            Simpan Pengaturan
          </button>
        </div>
      </div>
    </div>
  );
};
