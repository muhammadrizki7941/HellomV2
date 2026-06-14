import React from 'react';
import { 
  Palette, Upload, Trash2, Link as LinkIcon, 
  Facebook, Instagram, Music2, AtSign, MousePointer2,
  AlignLeft, AlignCenter, AlignRight, LayoutTemplate, Plus, MessageCircle
} from 'lucide-react';
import { Block, BlockStyles } from '../types';

interface PropertyPanelProps {
  selectedBlock: Block | undefined;
  activeTheme: any;
  updateBlockContent: (id: string, newContent: any) => void;
  updateBlockStyles: (id: string, newStyles: BlockStyles) => void;
  handleFileUpload: (e: React.ChangeEvent<HTMLInputElement>, fieldName: string, isStyle?: boolean) => void;
}

export const PropertyPanel: React.FC<PropertyPanelProps> = ({
  selectedBlock,
  activeTheme,
  updateBlockContent,
  updateBlockStyles,
  handleFileUpload
}) => {
  if (!selectedBlock) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center text-zinc-400 p-8 text-center h-full">
        <MousePointer2 className="w-12 h-12 mb-4 opacity-20" />
        <p className="text-sm">Pilih block di canvas untuk mengedit properti</p>
      </div>
    );
  }

  return (
    <div className="p-4 md:p-6">
      <div className="flex items-center justify-between mb-4 md:mb-6">
        <h3 className="text-sm font-bold text-zinc-900 uppercase tracking-wider">Edit Block</h3>
        <span className="text-xs px-2 py-1 bg-zinc-100 rounded text-zinc-500 font-mono">{selectedBlock.type}</span>
      </div>

      {/* --- STYLE EDITOR SECTION --- */}
      <div className="mb-8 p-4 bg-zinc-50 rounded-xl border border-zinc-200 space-y-6">
        
        {/* Layout Settings */}
        <div className="space-y-4">
          <div className="flex items-center gap-2 mb-2">
            <LayoutTemplate className="w-4 h-4 text-zinc-500" />
            <h4 className="text-xs font-bold text-zinc-700 uppercase">Layout</h4>
          </div>

          {/* Padding Y */}
          <div className="space-y-2">
            <label className="text-xs font-medium text-zinc-600">Vertical Padding</label>
            <div className="grid grid-cols-3 gap-2">
              {[
                { label: 'Small', value: 'py-8' },
                { label: 'Medium', value: 'py-16' },
                { label: 'Large', value: 'py-24' }
              ].map((opt) => (
                <button
                  key={opt.value}
                  onClick={() => updateBlockStyles(selectedBlock.id, { paddingY: opt.value })}
                  className={`px-2 py-1.5 text-xs rounded border transition-all ${
                    (selectedBlock.styles?.paddingY || 'py-16') === opt.value
                      ? 'bg-zinc-900 text-white border-zinc-900'
                      : 'bg-white text-zinc-600 border-zinc-200 hover:border-zinc-300'
                  }`}
                >
                  {opt.label}
                </button>
              ))}
            </div>
          </div>

          {/* Text Align */}
          <div className="space-y-2">
            <label className="text-xs font-medium text-zinc-600">Text Alignment</label>
            <div className="flex bg-white rounded-lg border border-zinc-200 p-1 w-fit">
              {[
                { icon: AlignLeft, value: 'left' },
                { icon: AlignCenter, value: 'center' },
                { icon: AlignRight, value: 'right' }
              ].map((opt) => (
                <button
                  key={opt.value}
                  onClick={() => updateBlockStyles(selectedBlock.id, { textAlign: opt.value as any })}
                  className={`p-1.5 rounded transition-all ${
                    (selectedBlock.styles?.textAlign || 'center') === opt.value
                      ? 'bg-zinc-100 text-zinc-900'
                      : 'text-zinc-400 hover:text-zinc-600'
                  }`}
                >
                  <opt.icon className="w-4 h-4" />
                </button>
              ))}
            </div>
          </div>
        </div>

        <div className="h-px bg-zinc-200 w-full"></div>

        {/* Colors & Background */}
        <div className="space-y-4">
          <div className="flex items-center gap-2 mb-2">
            <Palette className="w-4 h-4 text-zinc-500" />
            <h4 className="text-xs font-bold text-zinc-700 uppercase">Colors</h4>
          </div>
          
          {/* Background Color */}
          <div className="space-y-2">
            <label className="text-xs font-medium text-zinc-600">Background Color</label>
            <div className="flex gap-2">
              <input 
                type="color" 
                value={selectedBlock.styles?.backgroundColor || activeTheme.colors.backgroundColor}
                onChange={(e) => updateBlockStyles(selectedBlock.id, { backgroundColor: e.target.value })}
                className="w-8 h-8 rounded cursor-pointer border-0 p-0"
              />
              <input 
                type="text" 
                value={selectedBlock.styles?.backgroundColor || activeTheme.colors.backgroundColor}
                onChange={(e) => updateBlockStyles(selectedBlock.id, { backgroundColor: e.target.value })}
                className="flex-1 px-2 py-1 text-xs border border-zinc-300 rounded"
              />
            </div>
          </div>

          {/* Text Color */}
          <div className="space-y-2">
            <label className="text-xs font-medium text-zinc-600">Text Color</label>
            <div className="flex gap-2">
              <input 
                type="color" 
                value={selectedBlock.styles?.textColor || activeTheme.colors.textColor}
                onChange={(e) => updateBlockStyles(selectedBlock.id, { textColor: e.target.value })}
                className="w-8 h-8 rounded cursor-pointer border-0 p-0"
              />
              <input 
                type="text" 
                value={selectedBlock.styles?.textColor || activeTheme.colors.textColor}
                onChange={(e) => updateBlockStyles(selectedBlock.id, { textColor: e.target.value })}
                className="flex-1 px-2 py-1 text-xs border border-zinc-300 rounded"
              />
            </div>
          </div>

          {/* Button Color (if applicable) */}
          {(selectedBlock.content.buttonText || selectedBlock.type === 'product') && (
            <div className="space-y-2">
              <label className="text-xs font-medium text-zinc-600">Button Color</label>
              <div className="flex gap-2">
                <input 
                  type="color" 
                  value={selectedBlock.styles?.buttonColor || activeTheme.colors.buttonColor}
                  onChange={(e) => updateBlockStyles(selectedBlock.id, { buttonColor: e.target.value })}
                  className="w-8 h-8 rounded cursor-pointer border-0 p-0"
                />
                <input 
                  type="text" 
                  value={selectedBlock.styles?.buttonColor || activeTheme.colors.buttonColor}
                  onChange={(e) => updateBlockStyles(selectedBlock.id, { buttonColor: e.target.value })}
                  className="flex-1 px-2 py-1 text-xs border border-zinc-300 rounded"
                />
              </div>
            </div>
          )}

          {/* Background Image Upload */}
          <div className="space-y-2">
            <label className="text-xs font-medium text-zinc-600">Background Image</label>
            <div className="flex items-center gap-2">
              <label className="flex-1 flex items-center justify-center gap-2 px-3 py-2 bg-white border border-zinc-300 rounded-lg cursor-pointer hover:bg-zinc-50 text-xs text-zinc-600">
                <Upload className="w-3 h-3" />
                {selectedBlock.styles?.backgroundImage ? 'Change Image' : 'Upload Image'}
                <input 
                  type="file" 
                  className="hidden" 
                  accept="image/*"
                  onChange={(e) => handleFileUpload(e, 'backgroundImage', true)}
                />
              </label>
              {selectedBlock.styles?.backgroundImage && (
                <button 
                  onClick={() => updateBlockStyles(selectedBlock.id, { backgroundImage: undefined })}
                  className="p-2 text-red-500 hover:bg-red-50 rounded-lg"
                  title="Remove Background Image"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              )}
            </div>
          </div>
        </div>
      </div>

      <div className="space-y-6">
        {/* Common Fields */}
        {selectedBlock.content.title !== undefined && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">Title</label>
            <input 
              type="text" 
              value={selectedBlock.content.title}
              onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, title: e.target.value })}
              className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
            />
          </div>
        )}

        {selectedBlock.content.subtitle !== undefined && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">Subtitle</label>
            <textarea 
              value={selectedBlock.content.subtitle}
              onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, subtitle: e.target.value })}
              rows={3}
              className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none resize-none"
            />
          </div>
        )}

        {selectedBlock.content.body !== undefined && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">Body Text</label>
            <textarea 
              value={selectedBlock.content.body}
              onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, body: e.target.value })}
              rows={6}
              className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none resize-none"
            />
          </div>
        )}

        {/* Image Upload Field */}
        {selectedBlock.content.imageUrl !== undefined && selectedBlock.type !== 'banner' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">Image</label>
            <div className="flex items-center gap-2">
              <input 
                type="text" 
                value={selectedBlock.content.imageUrl}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, imageUrl: e.target.value })}
                className="flex-1 px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
                placeholder="https://..."
              />
              <label className="p-2 bg-zinc-100 border border-zinc-200 rounded-lg cursor-pointer hover:bg-zinc-200">
                <Upload className="w-4 h-4 text-zinc-600" />
                <input 
                  type="file" 
                  className="hidden" 
                  accept="image/*"
                  onChange={(e) => handleFileUpload(e, 'imageUrl')}
                />
              </label>
            </div>
            {selectedBlock.content.imageUrl && (
              <img src={selectedBlock.content.imageUrl} alt="Preview" className="w-full h-32 object-cover rounded-lg border border-zinc-200 mt-2" />
            )}
          </div>
        )}

        {/* Banner Image is handled differently (Background) */}
        {selectedBlock.type === 'banner' && (
            <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">Banner Image</label>
            <div className="flex items-center gap-2">
              <input 
                type="text" 
                value={selectedBlock.content.imageUrl}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, imageUrl: e.target.value })}
                className="flex-1 px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
                placeholder="https://..."
              />
              <label className="p-2 bg-zinc-100 border border-zinc-200 rounded-lg cursor-pointer hover:bg-zinc-200">
                <Upload className="w-4 h-4 text-zinc-600" />
                <input 
                  type="file" 
                  className="hidden" 
                  accept="image/*"
                  onChange={(e) => handleFileUpload(e, 'imageUrl')}
                />
              </label>
            </div>
          </div>
        )}

        {/* PDF Upload Field */}
        {selectedBlock.content.fileUrl !== undefined && (
          <div className="space-y-4">
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">PDF File</label>
              <div className="flex items-center gap-2">
                <input
                  type="text"
                  value={selectedBlock.content.fileUrl}
                  onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, fileUrl: e.target.value })}
                  className="flex-1 px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
                  placeholder="https://..."
                />
                <label className="p-2 bg-zinc-100 border border-zinc-200 rounded-lg cursor-pointer hover:bg-zinc-200">
                  <Upload className="w-4 h-4 text-zinc-600" />
                  <input
                    type="file"
                    className="hidden"
                    accept="application/pdf"
                    onChange={(e) => handleFileUpload(e, 'fileUrl')}
                  />
                </label>
              </div>
              {selectedBlock.content.fileName && (
                <p className="text-xs text-zinc-500 mt-1">Selected: {selectedBlock.content.fileName}</p>
              )}
            </div>

            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Akses Katalog</label>
              <select
                value={selectedBlock.content.accessType || 'free'}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, accessType: e.target.value })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
              >
                <option value="free">Gratis - user langsung download</option>
                <option value="paid">Berbayar - user checkout dulu</option>
              </select>
            </div>

            {selectedBlock.content.accessType === 'paid' && (
              <div className="space-y-2">
                <label className="text-xs font-bold text-zinc-700">Harga Katalog</label>
                <input
                  type="text"
                  value={selectedBlock.content.price || ''}
                  onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, price: e.target.value })}
                  className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
                  placeholder="Rp 49.000"
                />
              </div>
            )}
          </div>
        )}

        {/* Product Specific Fields */}
        {selectedBlock.type === 'product' && (
          <>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Product Name</label>
              <input 
                type="text" 
                value={selectedBlock.content.name}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, name: e.target.value })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
              />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Price</label>
              <input 
                type="text" 
                value={selectedBlock.content.price}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, price: e.target.value })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
              />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Description</label>
              <textarea 
                value={selectedBlock.content.description}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, description: e.target.value })}
                rows={3}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none resize-none"
              />
            </div>
            
            {/* Payment Method Selection */}
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Payment Action</label>
              <select
                value={selectedBlock.content.paymentType || 'link'}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, paymentType: e.target.value })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
              >
                <option value="link">Direct Link (External)</option>
                <option value="whatsapp">WhatsApp Checkout</option>
                <option value="gateway">Payment Gateway (Auto)</option>
              </select>
            </div>

            {selectedBlock.content.paymentType === 'whatsapp' && (
              <div className="p-3 bg-green-50 rounded-lg border border-green-100 text-xs text-green-800">
                Button will open WhatsApp with a pre-filled message. Configure your number in <strong>Payments</strong> settings.
              </div>
            )}

            {selectedBlock.content.paymentType === 'gateway' && (
              <div className="p-3 bg-blue-50 rounded-lg border border-blue-100 text-xs text-blue-800">
                Button will open the automated checkout modal. Funds will be deposited to your Wallet.
              </div>
            )}

            {(!selectedBlock.content.paymentType || selectedBlock.content.paymentType === 'link') && (
              <div className="space-y-2">
                <label className="text-xs font-bold text-zinc-700">External Link URL</label>
                <div className="flex items-center gap-2">
                  <LinkIcon className="w-4 h-4 text-zinc-400" />
                  <input 
                    type="text" 
                    value={selectedBlock.content.productUrl}
                    onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, productUrl: e.target.value })}
                    className="flex-1 px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
                    placeholder="https://..."
                  />
                </div>
              </div>
            )}
          </>
        )}

        {/* Social Specific Fields */}
        {selectedBlock.type === 'social' && (
          <div className="space-y-4">
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700 flex items-center gap-2">
                <Facebook className="w-4 h-4 text-blue-600" /> Facebook URL
              </label>
              <input 
                type="text" 
                value={selectedBlock.content.facebook}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, facebook: e.target.value })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
                placeholder="https://facebook.com/..."
              />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700 flex items-center gap-2">
                <Instagram className="w-4 h-4 text-pink-600" /> Instagram URL
              </label>
              <input 
                type="text" 
                value={selectedBlock.content.instagram}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, instagram: e.target.value })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
                placeholder="https://instagram.com/..."
              />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700 flex items-center gap-2">
                <Music2 className="w-4 h-4 text-black" /> TikTok URL
              </label>
              <input 
                type="text" 
                value={selectedBlock.content.tiktok}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, tiktok: e.target.value })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
                placeholder="https://tiktok.com/@..."
              />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700 flex items-center gap-2">
                <AtSign className="w-4 h-4 text-black" /> Threads URL
              </label>
              <input 
                type="text" 
                value={selectedBlock.content.threads}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, threads: e.target.value })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
                placeholder="https://threads.net/@..."
              />
            </div>
          </div>
        )}

        {/* Video Specific Fields */}
        {selectedBlock.type === 'video' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">Video Embed URL</label>
            <input 
              type="text" 
              value={selectedBlock.content.videoUrl}
              onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, videoUrl: e.target.value })}
              className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
              placeholder="https://www.youtube.com/embed/..."
            />
            <p className="text-xs text-zinc-500">Gunakan link embed YouTube/Vimeo.</p>
          </div>
        )}

        {selectedBlock.content.buttonText !== undefined && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">Button Text</label>
            <input 
              type="text" 
              value={selectedBlock.content.buttonText}
              onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, buttonText: e.target.value })}
              className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none"
            />
          </div>
        )}

        {selectedBlock.type === 'cta' && (
          <div className="space-y-4 p-3 rounded-xl bg-green-50 border border-green-100">
            <div className="flex items-center gap-2 text-xs font-bold text-green-800 uppercase">
              <MessageCircle className="w-4 h-4" /> CTA Action
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Action Type</label>
              <select
                value={selectedBlock.content.actionType || 'whatsapp'}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, actionType: e.target.value })}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none"
              >
                <option value="whatsapp">Redirect ke WhatsApp</option>
                <option value="link">Buka link custom</option>
              </select>
            </div>
            {(selectedBlock.content.actionType || 'whatsapp') === 'whatsapp' ? (
              <>
                <div className="space-y-2">
                  <label className="text-xs font-bold text-zinc-700">Nomor WhatsApp Organisasi</label>
                  <input
                    type="text"
                    value={selectedBlock.content.whatsappNumber || ''}
                    onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, whatsappNumber: e.target.value })}
                    className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none"
                    placeholder="628123456789"
                  />
                  <p className="text-[10px] text-zinc-500">Gunakan format internasional, contoh 62812...</p>
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-bold text-zinc-700">Pesan WhatsApp</label>
                  <textarea
                    value={selectedBlock.content.whatsappMessage || ''}
                    onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, whatsappMessage: e.target.value })}
                    rows={3}
                    className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none resize-none"
                  />
                </div>
              </>
            ) : (
              <div className="space-y-2">
                <label className="text-xs font-bold text-zinc-700">Link URL</label>
                <input
                  type="text"
                  value={selectedBlock.content.linkUrl || ''}
                  onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, linkUrl: e.target.value })}
                  className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none"
                  placeholder="https://..."
                />
              </div>
            )}
          </div>
        )}

        {selectedBlock.type === 'form' && (
          <div className="space-y-4 pt-4 border-t border-zinc-100">
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-zinc-700">Form Fields</label>
              <button
                onClick={() => {
                  const fieldId = `field_${Date.now()}`;
                  updateBlockContent(selectedBlock.id, {
                    ...selectedBlock.content,
                    fields: [
                      ...(selectedBlock.content.fields || []),
                      { id: fieldId, label: 'Field Baru', type: 'text', required: false }
                    ],
                  });
                }}
                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-bold rounded-lg bg-zinc-900 text-white"
              >
                <Plus className="w-3 h-3" /> Tambah
              </button>
            </div>
            {(selectedBlock.content.fields || []).map((field: any, idx: number) => (
              <div key={field.id || idx} className="p-3 bg-zinc-50 rounded-lg border border-zinc-200 space-y-3">
                <div className="flex justify-between gap-2">
                  <input
                    type="text"
                    value={field.label || ''}
                    onChange={(e) => {
                      const fields = [...(selectedBlock.content.fields || [])];
                      fields[idx] = { ...field, label: e.target.value };
                      updateBlockContent(selectedBlock.id, { ...selectedBlock.content, fields });
                    }}
                    className="flex-1 px-2 py-1 border border-zinc-300 rounded text-sm"
                    placeholder="Label field"
                  />
                  {!field.system && (
                    <button
                      onClick={() => {
                        const fields = (selectedBlock.content.fields || []).filter((_: any, index: number) => index !== idx);
                        updateBlockContent(selectedBlock.id, { ...selectedBlock.content, fields });
                      }}
                      className="p-2 text-red-500 hover:bg-red-50 rounded"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-2">
                  <select
                    value={field.type || 'text'}
                    onChange={(e) => {
                      const fields = [...(selectedBlock.content.fields || [])];
                      fields[idx] = { ...field, type: e.target.value };
                      updateBlockContent(selectedBlock.id, { ...selectedBlock.content, fields });
                    }}
                    className="px-2 py-1 border border-zinc-300 rounded text-sm"
                  >
                    <option value="text">Text</option>
                    <option value="tel">Nomor HP</option>
                    <option value="email">Email</option>
                    <option value="number">Number</option>
                    <option value="textarea">Textarea</option>
                  </select>
                  <label className="flex items-center gap-2 text-xs font-medium text-zinc-600">
                    <input
                      type="checkbox"
                      checked={!!field.required}
                      onChange={(e) => {
                        const fields = [...(selectedBlock.content.fields || [])];
                        fields[idx] = { ...field, required: e.target.checked };
                        updateBlockContent(selectedBlock.id, { ...selectedBlock.content, fields });
                      }}
                    />
                    Wajib diisi
                  </label>
                </div>
              </div>
            ))}
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Success Message</label>
              <textarea
                value={selectedBlock.content.successMessage || ''}
                onChange={(e) => updateBlockContent(selectedBlock.id, { ...selectedBlock.content, successMessage: e.target.value })}
                rows={2}
                className="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none resize-none"
              />
            </div>
          </div>
        )}

        {/* Features Specific Editor */}
        {selectedBlock.type === 'features' && (
          <div className="space-y-4 pt-4 border-t border-zinc-100">
            <label className="text-xs font-bold text-zinc-700">Feature Items</label>
            {selectedBlock.content.items.map((item: any, idx: number) => (
              <div key={idx} className="p-3 bg-zinc-50 rounded-lg border border-zinc-200 space-y-3">
                <input 
                  type="text" 
                  value={item.title}
                  onChange={(e) => {
                    const newItems = [...selectedBlock.content.items];
                    newItems[idx].title = e.target.value;
                    updateBlockContent(selectedBlock.id, { ...selectedBlock.content, items: newItems });
                  }}
                  className="w-full px-2 py-1 border border-zinc-300 rounded text-sm"
                  placeholder="Feature Title"
                />
                <textarea 
                  value={item.desc}
                  onChange={(e) => {
                    const newItems = [...selectedBlock.content.items];
                    newItems[idx].desc = e.target.value;
                    updateBlockContent(selectedBlock.id, { ...selectedBlock.content, items: newItems });
                  }}
                  rows={2}
                  className="w-full px-2 py-1 border border-zinc-300 rounded text-sm resize-none"
                  placeholder="Description"
                />
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};
