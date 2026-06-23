import React, { useState } from 'react';
import {
  Palette, Upload, Trash2, Link as LinkIcon,
  Facebook, Instagram, Music2, AtSign, MousePointer2,
  AlignLeft, AlignCenter, AlignRight, LayoutTemplate, Plus, MessageCircle
} from 'lucide-react';
import { Block, BlockStyles } from '../types';
import { useLang } from '../i18n';

interface PropertyPanelProps {
  selectedBlock: Block | undefined;
  activeTheme: any;
  updateBlockContent: (id: string, newContent: any) => void;
  updateBlockStyles: (id: string, newStyles: BlockStyles) => void;
  handleFileUpload: (e: React.ChangeEvent<HTMLInputElement>, fieldName: string, isStyle?: boolean) => void;
}

const inputClass = 'w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 focus:border-yellow-400 outline-none';

export const PropertyPanel: React.FC<PropertyPanelProps> = ({
  selectedBlock,
  activeTheme,
  updateBlockContent,
  updateBlockStyles,
  handleFileUpload
}) => {
  const { t } = useLang();
  const [uploadError, setUploadError] = useState<string | null>(null);

  if (!selectedBlock) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center text-zinc-400 p-8 text-center h-full">
        <MousePointer2 className="w-12 h-12 mb-4 opacity-20" />
        <p className="text-sm">{t('pp.empty')}</p>
      </div>
    );
  }

  const block = selectedBlock;
  const patch = (changes: Record<string, any>) => updateBlockContent(block.id, { ...block.content, ...changes });

  const MAX_SLIDER_IMAGE_BYTES = 1024 * 1024; // 1 MB
  const uploadSliderImage = (idx: number, file: File | undefined) => {
    if (!file) return;
    if (file.size > MAX_SLIDER_IMAGE_BYTES) {
      setUploadError(t('pp.slider.tooLarge'));
      return;
    }
    setUploadError(null);
    const reader = new FileReader();
    reader.onload = (ev) => {
      const url = ev.target?.result as string;
      const images = [...(block.content.images || [])];
      images[idx] = { ...images[idx], url };
      patch({ images });
    };
    reader.readAsDataURL(file);
  };
  const hasButtonColor = block.content.buttonText !== undefined
    || ['product', 'button', 'countdown'].includes(block.type);

  return (
    <div className="p-4 md:p-6">
      <div className="flex items-center justify-between mb-4 md:mb-6">
        <h3 className="text-sm font-bold text-zinc-900 uppercase tracking-wider">{t('pp.editBlock')}</h3>
        <span className="text-xs px-2 py-1 bg-zinc-100 rounded text-zinc-500 font-mono">{block.type}</span>
      </div>

      {/* --- STYLE EDITOR SECTION --- */}
      <div className="mb-8 p-4 bg-zinc-50 rounded-xl border border-zinc-200 space-y-6">
        {/* Layout Settings */}
        <div className="space-y-4">
          <div className="flex items-center gap-2 mb-2">
            <LayoutTemplate className="w-4 h-4 text-zinc-500" />
            <h4 className="text-xs font-bold text-zinc-700 uppercase">{t('pp.layout')}</h4>
          </div>

          {/* Padding Y */}
          <div className="space-y-2">
            <label className="text-xs font-medium text-zinc-600">{t('pp.padding')}</label>
            <div className="grid grid-cols-3 gap-2">
              {[
                { label: t('pp.small'), value: 'py-8' },
                { label: t('pp.medium'), value: 'py-16' },
                { label: t('pp.large'), value: 'py-24' }
              ].map((opt) => (
                <button
                  key={opt.value}
                  onClick={() => updateBlockStyles(block.id, { paddingY: opt.value })}
                  className={`px-2 py-1.5 text-xs rounded border transition-all ${
                    (block.styles?.paddingY || 'py-16') === opt.value
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
            <label className="text-xs font-medium text-zinc-600">{t('pp.textAlign')}</label>
            <div className="flex bg-white rounded-lg border border-zinc-200 p-1 w-fit">
              {[
                { icon: AlignLeft, value: 'left' },
                { icon: AlignCenter, value: 'center' },
                { icon: AlignRight, value: 'right' }
              ].map((opt) => (
                <button
                  key={opt.value}
                  onClick={() => updateBlockStyles(block.id, { textAlign: opt.value as any })}
                  className={`p-1.5 rounded transition-all ${
                    (block.styles?.textAlign || 'center') === opt.value
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
            <h4 className="text-xs font-bold text-zinc-700 uppercase">{t('pp.colors')}</h4>
          </div>

          {/* Background Color */}
          <div className="space-y-2">
            <label className="text-xs font-medium text-zinc-600">{t('pp.bgColor')}</label>
            <div className="flex gap-2">
              <input
                type="color"
                value={block.styles?.backgroundColor || activeTheme.colors.backgroundColor}
                onChange={(e) => updateBlockStyles(block.id, { backgroundColor: e.target.value })}
                className="w-8 h-8 rounded cursor-pointer border-0 p-0"
              />
              <input
                type="text"
                value={block.styles?.backgroundColor || activeTheme.colors.backgroundColor}
                onChange={(e) => updateBlockStyles(block.id, { backgroundColor: e.target.value })}
                className="flex-1 px-2 py-1 text-xs border border-zinc-300 rounded"
              />
            </div>
          </div>

          {/* Text Color */}
          <div className="space-y-2">
            <label className="text-xs font-medium text-zinc-600">{t('pp.textColor')}</label>
            <div className="flex gap-2">
              <input
                type="color"
                value={block.styles?.textColor || activeTheme.colors.textColor}
                onChange={(e) => updateBlockStyles(block.id, { textColor: e.target.value })}
                className="w-8 h-8 rounded cursor-pointer border-0 p-0"
              />
              <input
                type="text"
                value={block.styles?.textColor || activeTheme.colors.textColor}
                onChange={(e) => updateBlockStyles(block.id, { textColor: e.target.value })}
                className="flex-1 px-2 py-1 text-xs border border-zinc-300 rounded"
              />
            </div>
          </div>

          {/* Button Color (if applicable) */}
          {hasButtonColor && (
            <div className="space-y-2">
              <label className="text-xs font-medium text-zinc-600">{t('pp.buttonColor')}</label>
              <div className="flex gap-2">
                <input
                  type="color"
                  value={block.styles?.buttonColor || activeTheme.colors.buttonColor}
                  onChange={(e) => updateBlockStyles(block.id, { buttonColor: e.target.value })}
                  className="w-8 h-8 rounded cursor-pointer border-0 p-0"
                />
                <input
                  type="text"
                  value={block.styles?.buttonColor || activeTheme.colors.buttonColor}
                  onChange={(e) => updateBlockStyles(block.id, { buttonColor: e.target.value })}
                  className="flex-1 px-2 py-1 text-xs border border-zinc-300 rounded"
                />
              </div>
            </div>
          )}

          {/* Background Image Upload */}
          <div className="space-y-2">
            <label className="text-xs font-medium text-zinc-600">{t('pp.bgImage')}</label>
            <div className="flex items-center gap-2">
              <label className="flex-1 flex items-center justify-center gap-2 px-3 py-2 bg-white border border-zinc-300 rounded-lg cursor-pointer hover:bg-zinc-50 text-xs text-zinc-600">
                <Upload className="w-3 h-3" />
                {block.styles?.backgroundImage ? t('pp.changeImage') : t('pp.uploadImage')}
                <input type="file" className="hidden" accept="image/*" onChange={(e) => handleFileUpload(e, 'backgroundImage', true)} />
              </label>
              {block.styles?.backgroundImage && (
                <button
                  onClick={() => updateBlockStyles(block.id, { backgroundImage: undefined })}
                  className="p-2 text-red-500 hover:bg-red-50 rounded-lg"
                  title={t('pp.removeBgImage')}
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
        {block.content.title !== undefined && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.title')}</label>
            <input type="text" value={block.content.title} onChange={(e) => patch({ title: e.target.value })} className={inputClass} />
          </div>
        )}

        {block.content.subtitle !== undefined && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.subtitle')}</label>
            <textarea value={block.content.subtitle} onChange={(e) => patch({ subtitle: e.target.value })} rows={3} className={`${inputClass} resize-none`} />
          </div>
        )}

        {block.content.body !== undefined && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.body')}</label>
            <textarea value={block.content.body} onChange={(e) => patch({ body: e.target.value })} rows={6} className={`${inputClass} resize-none`} />
          </div>
        )}

        {/* Image Upload Field */}
        {block.content.imageUrl !== undefined && block.type !== 'banner' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.image')}</label>
            <div className="flex items-center gap-2">
              <input type="text" value={block.content.imageUrl} onChange={(e) => patch({ imageUrl: e.target.value })} className={inputClass} placeholder="https://..." />
              <label className="p-2 bg-zinc-100 border border-zinc-200 rounded-lg cursor-pointer hover:bg-zinc-200">
                <Upload className="w-4 h-4 text-zinc-600" />
                <input type="file" className="hidden" accept="image/*" onChange={(e) => handleFileUpload(e, 'imageUrl')} />
              </label>
            </div>
            {block.content.imageUrl && (
              <img src={block.content.imageUrl} alt="Preview" className="w-full h-32 object-cover rounded-lg border border-zinc-200 mt-2" />
            )}
          </div>
        )}

        {/* Caption (image/gif) */}
        {block.content.caption !== undefined && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.caption')}</label>
            <input type="text" value={block.content.caption} onChange={(e) => patch({ caption: e.target.value })} className={inputClass} />
          </div>
        )}

        {/* Banner Image */}
        {block.type === 'banner' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.bannerImage')}</label>
            <div className="flex items-center gap-2">
              <input type="text" value={block.content.imageUrl} onChange={(e) => patch({ imageUrl: e.target.value })} className={inputClass} placeholder="https://..." />
              <label className="p-2 bg-zinc-100 border border-zinc-200 rounded-lg cursor-pointer hover:bg-zinc-200">
                <Upload className="w-4 h-4 text-zinc-600" />
                <input type="file" className="hidden" accept="image/*" onChange={(e) => handleFileUpload(e, 'imageUrl')} />
              </label>
            </div>
          </div>
        )}

        {/* GIF block */}
        {block.type === 'gif' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.gif.url')}</label>
            <div className="flex items-center gap-2">
              <input type="text" value={block.content.gifUrl} onChange={(e) => patch({ gifUrl: e.target.value })} className={inputClass} placeholder="https://...giphy.com/...gif" />
              <label className="p-2 bg-zinc-100 border border-zinc-200 rounded-lg cursor-pointer hover:bg-zinc-200">
                <Upload className="w-4 h-4 text-zinc-600" />
                <input type="file" className="hidden" accept="image/gif,image/*" onChange={(e) => handleFileUpload(e, 'gifUrl')} />
              </label>
            </div>
            {block.content.gifUrl && <img src={block.content.gifUrl} alt="GIF" className="w-full h-32 object-contain rounded-lg border border-zinc-200 mt-2 bg-zinc-50" />}
          </div>
        )}

        {/* HTML block */}
        {block.type === 'html' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.html.code')}</label>
            <textarea value={block.content.html} onChange={(e) => patch({ html: e.target.value })} rows={8} className={`${inputClass} resize-none font-mono text-xs`} />
            <p className="text-[10px] text-amber-600">{t('pp.html.hint')}</p>
          </div>
        )}

        {/* Button block */}
        {block.type === 'button' && (
          <div className="space-y-4">
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.buttonText')}</label>
              <input type="text" value={block.content.text} onChange={(e) => patch({ text: e.target.value })} className={inputClass} />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.button.align')}</label>
              <div className="grid grid-cols-3 gap-2">
                {[
                  { value: 'left', label: t('pp.align.left') },
                  { value: 'center', label: t('pp.align.center') },
                  { value: 'right', label: t('pp.align.right') },
                ].map((opt) => (
                  <button
                    key={opt.value}
                    onClick={() => patch({ align: opt.value })}
                    className={`px-2 py-1.5 text-xs rounded border ${(block.content.align || 'center') === opt.value ? 'bg-zinc-900 text-white border-zinc-900' : 'bg-white text-zinc-600 border-zinc-200'}`}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.cta.type')}</label>
              <select value={block.content.actionType || 'link'} onChange={(e) => patch({ actionType: e.target.value })} className={inputClass}>
                <option value="link">{t('pp.cta.link')}</option>
                <option value="whatsapp">{t('pp.cta.wa')}</option>
              </select>
            </div>
            {(block.content.actionType || 'link') === 'whatsapp' ? (
              <>
                <div className="space-y-2">
                  <label className="text-xs font-bold text-zinc-700">{t('pp.cta.waNumber')}</label>
                  <input type="text" value={block.content.whatsappNumber || ''} onChange={(e) => patch({ whatsappNumber: e.target.value })} className={inputClass} placeholder="628123456789" />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-bold text-zinc-700">{t('pp.cta.waMessage')}</label>
                  <textarea value={block.content.whatsappMessage || ''} onChange={(e) => patch({ whatsappMessage: e.target.value })} rows={2} className={`${inputClass} resize-none`} />
                </div>
              </>
            ) : (
              <div className="space-y-2">
                <label className="text-xs font-bold text-zinc-700">{t('pp.cta.linkUrl')}</label>
                <input type="text" value={block.content.linkUrl || ''} onChange={(e) => patch({ linkUrl: e.target.value })} className={inputClass} placeholder="https://..." />
              </div>
            )}
          </div>
        )}

        {/* Divider block */}
        {block.type === 'divider' && (
          <div className="space-y-4">
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.divider.style')}</label>
              <select value={block.content.style || 'solid'} onChange={(e) => patch({ style: e.target.value })} className={inputClass}>
                <option value="solid">{t('pp.divider.solid')}</option>
                <option value="dashed">{t('pp.divider.dashed')}</option>
                <option value="dotted">{t('pp.divider.dotted')}</option>
              </select>
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.divider.thickness')}</label>
              <input type="number" min={1} max={20} value={block.content.thickness ?? 1} onChange={(e) => patch({ thickness: Number(e.target.value) })} className={inputClass} />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.divider.width')}</label>
              <input type="number" min={10} max={100} value={block.content.width ?? 100} onChange={(e) => patch({ width: Number(e.target.value) })} className={inputClass} />
            </div>
          </div>
        )}

        {/* Countdown block */}
        {block.type === 'countdown' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.countdown.target')}</label>
            <input
              type="datetime-local"
              value={toLocalInput(block.content.targetDate)}
              onChange={(e) => patch({ targetDate: e.target.value ? new Date(e.target.value).toISOString() : block.content.targetDate })}
              className={inputClass}
            />
            <p className="text-[10px] text-zinc-500">{t('pp.countdown.hint')}</p>
          </div>
        )}

        {/* PDF Upload Field */}
        {block.content.fileUrl !== undefined && (
          <div className="space-y-4">
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.pdf.file')}</label>
              <div className="flex items-center gap-2">
                <input type="text" value={block.content.fileUrl} onChange={(e) => patch({ fileUrl: e.target.value })} className={inputClass} placeholder="https://..." />
                <label className="p-2 bg-zinc-100 border border-zinc-200 rounded-lg cursor-pointer hover:bg-zinc-200">
                  <Upload className="w-4 h-4 text-zinc-600" />
                  <input type="file" className="hidden" accept="application/pdf" onChange={(e) => handleFileUpload(e, 'fileUrl')} />
                </label>
              </div>
              {block.content.fileName && <p className="text-xs text-zinc-500 mt-1">{block.content.fileName}</p>}
            </div>

            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.pdf.access')}</label>
              <select value={block.content.accessType || 'free'} onChange={(e) => patch({ accessType: e.target.value })} className={inputClass}>
                <option value="free">{t('pp.pdf.free')}</option>
                <option value="paid">{t('pp.pdf.paid')}</option>
              </select>
            </div>

            {block.content.accessType === 'paid' && (
              <div className="space-y-2">
                <label className="text-xs font-bold text-zinc-700">{t('pp.pdf.price')}</label>
                <input type="text" value={block.content.price || ''} onChange={(e) => patch({ price: e.target.value })} className={inputClass} placeholder="Rp 49.000" />
              </div>
            )}
          </div>
        )}

        {/* Product Specific Fields */}
        {block.type === 'product' && (
          <>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.product.name')}</label>
              <input type="text" value={block.content.name} onChange={(e) => patch({ name: e.target.value })} className={inputClass} />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.product.price')}</label>
              <input type="text" value={block.content.price} onChange={(e) => patch({ price: e.target.value })} className={inputClass} />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.product.desc')}</label>
              <textarea value={block.content.description} onChange={(e) => patch({ description: e.target.value })} rows={3} className={`${inputClass} resize-none`} />
            </div>

            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.product.payment')}</label>
              <select value={block.content.paymentType || 'link'} onChange={(e) => patch({ paymentType: e.target.value })} className={inputClass}>
                <option value="link">Direct Link (External)</option>
                <option value="whatsapp">WhatsApp Checkout</option>
                <option value="gateway">Payment Gateway (Auto)</option>
              </select>
            </div>

            {block.content.paymentType === 'whatsapp' && (
              <div className="p-3 bg-green-50 rounded-lg border border-green-100 text-xs text-green-800">
                Button will open WhatsApp with a pre-filled message. Configure your number in <strong>Payments</strong> settings.
              </div>
            )}

            {block.content.paymentType === 'gateway' && (
              <div className="p-3 bg-blue-50 rounded-lg border border-blue-100 text-xs text-blue-800">
                Button will open the automated checkout modal. Funds will be deposited to your Wallet.
              </div>
            )}

            {(!block.content.paymentType || block.content.paymentType === 'link') && (
              <div className="space-y-2">
                <label className="text-xs font-bold text-zinc-700">{t('pp.product.linkUrl')}</label>
                <div className="flex items-center gap-2">
                  <LinkIcon className="w-4 h-4 text-zinc-400" />
                  <input type="text" value={block.content.productUrl} onChange={(e) => patch({ productUrl: e.target.value })} className={inputClass} placeholder="https://..." />
                </div>
              </div>
            )}
          </>
        )}

        {/* Social Specific Fields */}
        {block.type === 'social' && (
          <div className="space-y-4">
            {[
              { key: 'facebook', icon: Facebook, color: 'text-blue-600', label: 'Facebook URL', ph: 'https://facebook.com/...' },
              { key: 'instagram', icon: Instagram, color: 'text-pink-600', label: 'Instagram URL', ph: 'https://instagram.com/...' },
              { key: 'tiktok', icon: Music2, color: 'text-black', label: 'TikTok URL', ph: 'https://tiktok.com/@...' },
              { key: 'threads', icon: AtSign, color: 'text-black', label: 'Threads URL', ph: 'https://threads.net/@...' },
            ].map(({ key, icon: Icon, color, label, ph }) => (
              <div key={key} className="space-y-2">
                <label className="text-xs font-bold text-zinc-700 flex items-center gap-2">
                  <Icon className={`w-4 h-4 ${color}`} /> {label}
                </label>
                <input type="text" value={block.content[key] || ''} onChange={(e) => patch({ [key]: e.target.value })} className={inputClass} placeholder={ph} />
              </div>
            ))}
          </div>
        )}

        {/* Video Specific Fields */}
        {block.type === 'video' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.video.url')}</label>
            <input type="text" value={block.content.videoUrl} onChange={(e) => patch({ videoUrl: e.target.value })} className={inputClass} placeholder="https://www.youtube.com/embed/..." />
            <p className="text-xs text-zinc-500">{t('pp.video.hint')}</p>
          </div>
        )}

        {block.content.buttonText !== undefined && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">{t('pp.buttonText')}</label>
            <input type="text" value={block.content.buttonText} onChange={(e) => patch({ buttonText: e.target.value })} className={inputClass} />
          </div>
        )}

        {block.type === 'cta' && (
          <div className="space-y-4 p-3 rounded-xl bg-green-50 border border-green-100">
            <div className="flex items-center gap-2 text-xs font-bold text-green-800 uppercase">
              <MessageCircle className="w-4 h-4" /> {t('pp.cta.action')}
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.cta.type')}</label>
              <select value={block.content.actionType || 'whatsapp'} onChange={(e) => patch({ actionType: e.target.value })} className={inputClass}>
                <option value="whatsapp">{t('pp.cta.wa')}</option>
                <option value="link">{t('pp.cta.link')}</option>
              </select>
            </div>
            {(block.content.actionType || 'whatsapp') === 'whatsapp' ? (
              <>
                <div className="space-y-2">
                  <label className="text-xs font-bold text-zinc-700">{t('pp.cta.waNumber')}</label>
                  <input type="text" value={block.content.whatsappNumber || ''} onChange={(e) => patch({ whatsappNumber: e.target.value })} className={inputClass} placeholder="628123456789" />
                  <p className="text-[10px] text-zinc-500">Format: 62812...</p>
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-bold text-zinc-700">{t('pp.cta.waMessage')}</label>
                  <textarea value={block.content.whatsappMessage || ''} onChange={(e) => patch({ whatsappMessage: e.target.value })} rows={3} className={`${inputClass} resize-none`} />
                </div>
              </>
            ) : (
              <div className="space-y-2">
                <label className="text-xs font-bold text-zinc-700">{t('pp.cta.linkUrl')}</label>
                <input type="text" value={block.content.linkUrl || ''} onChange={(e) => patch({ linkUrl: e.target.value })} className={inputClass} placeholder="https://..." />
              </div>
            )}
          </div>
        )}

        {/* Form fields editor */}
        {block.type === 'form' && (
          <div className="space-y-4 pt-4 border-t border-zinc-100">
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-zinc-700">{t('pp.form.fields')}</label>
              <button
                onClick={() => patch({ fields: [...(block.content.fields || []), { id: `field_${Date.now()}`, label: 'Field Baru', type: 'text', required: false }] })}
                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-bold rounded-lg bg-zinc-900 text-white"
              >
                <Plus className="w-3 h-3" /> {t('pp.add')}
              </button>
            </div>
            {(block.content.fields || []).map((field: any, idx: number) => (
              <div key={field.id || idx} className="p-3 bg-zinc-50 rounded-lg border border-zinc-200 space-y-3">
                <div className="flex justify-between gap-2">
                  <input
                    type="text"
                    value={field.label || ''}
                    onChange={(e) => {
                      const fields = [...(block.content.fields || [])];
                      fields[idx] = { ...field, label: e.target.value };
                      patch({ fields });
                    }}
                    className="flex-1 px-2 py-1 border border-zinc-300 rounded text-sm"
                    placeholder={t('pp.form.fieldLabel')}
                  />
                  {!field.system && (
                    <button
                      onClick={() => patch({ fields: (block.content.fields || []).filter((_: any, i: number) => i !== idx) })}
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
                      const fields = [...(block.content.fields || [])];
                      fields[idx] = { ...field, type: e.target.value };
                      patch({ fields });
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
                        const fields = [...(block.content.fields || [])];
                        fields[idx] = { ...field, required: e.target.checked };
                        patch({ fields });
                      }}
                    />
                    {t('pp.form.required')}
                  </label>
                </div>
              </div>
            ))}
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.form.success')}</label>
              <textarea value={block.content.successMessage || ''} onChange={(e) => patch({ successMessage: e.target.value })} rows={2} className={`${inputClass} resize-none`} />
            </div>
          </div>
        )}

        {/* Features items editor */}
        {block.type === 'features' && (
          <div className="space-y-4 pt-4 border-t border-zinc-100">
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-zinc-700">{t('pp.features.items')}</label>
              <button
                onClick={() => patch({ items: [...(block.content.items || []), { title: 'Fitur Baru', desc: 'Deskripsi singkat.' }] })}
                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-bold rounded-lg bg-zinc-900 text-white"
              >
                <Plus className="w-3 h-3" /> {t('pp.add')}
              </button>
            </div>
            {(block.content.items || []).map((item: any, idx: number) => (
              <div key={idx} className="p-3 bg-zinc-50 rounded-lg border border-zinc-200 space-y-3">
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={item.title}
                    onChange={(e) => {
                      const items = [...block.content.items];
                      items[idx] = { ...item, title: e.target.value };
                      patch({ items });
                    }}
                    className="flex-1 px-2 py-1 border border-zinc-300 rounded text-sm"
                    placeholder="Feature Title"
                  />
                  <button onClick={() => patch({ items: block.content.items.filter((_: any, i: number) => i !== idx) })} className="p-2 text-red-500 hover:bg-red-50 rounded">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
                <textarea
                  value={item.desc}
                  onChange={(e) => {
                    const items = [...block.content.items];
                    items[idx] = { ...item, desc: e.target.value };
                    patch({ items });
                  }}
                  rows={2}
                  className="w-full px-2 py-1 border border-zinc-300 rounded text-sm resize-none"
                  placeholder="Description"
                />
              </div>
            ))}
          </div>
        )}

        {/* Testimonials editor */}
        {block.type === 'testimonials' && (
          <div className="space-y-4 pt-4 border-t border-zinc-100">
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-zinc-700">{t('pp.testi.items')}</label>
              <button
                onClick={() => patch({ items: [...(block.content.items || []), { name: 'Nama', role: '', text: 'Testimoni...', rating: 5 }] })}
                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-bold rounded-lg bg-zinc-900 text-white"
              >
                <Plus className="w-3 h-3" /> {t('pp.add')}
              </button>
            </div>
            {(block.content.items || []).map((item: any, idx: number) => {
              const setItem = (changes: Record<string, any>) => {
                const items = [...(block.content.items || [])];
                items[idx] = { ...item, ...changes };
                patch({ items });
              };
              return (
                <div key={idx} className="p-3 bg-zinc-50 rounded-lg border border-zinc-200 space-y-2">
                  <div className="flex gap-2">
                    <input type="text" value={item.name || ''} onChange={(e) => setItem({ name: e.target.value })} className="flex-1 px-2 py-1 border border-zinc-300 rounded text-sm" placeholder={t('pp.testi.name')} />
                    <button onClick={() => patch({ items: (block.content.items || []).filter((_: any, i: number) => i !== idx) })} className="p-2 text-red-500 hover:bg-red-50 rounded">
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                  <input type="text" value={item.role || ''} onChange={(e) => setItem({ role: e.target.value })} className="w-full px-2 py-1 border border-zinc-300 rounded text-sm" placeholder={t('pp.testi.role')} />
                  <textarea value={item.text || ''} onChange={(e) => setItem({ text: e.target.value })} rows={2} className="w-full px-2 py-1 border border-zinc-300 rounded text-sm resize-none" placeholder={t('pp.testi.text')} />
                  <div className="space-y-1">
                    <label className="text-[11px] text-zinc-500">{t('pp.testi.rating')}</label>
                    <input type="number" min={1} max={5} value={item.rating ?? 5} onChange={(e) => setItem({ rating: Number(e.target.value) })} className="w-full px-2 py-1 border border-zinc-300 rounded text-sm" />
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {/* FAQ editor */}
        {block.type === 'faq' && (
          <div className="space-y-4 pt-4 border-t border-zinc-100">
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-zinc-700">{t('pp.faq.items')}</label>
              <button
                onClick={() => patch({ items: [...(block.content.items || []), { q: 'Pertanyaan baru?', a: 'Jawaban...' }] })}
                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-bold rounded-lg bg-zinc-900 text-white"
              >
                <Plus className="w-3 h-3" /> {t('pp.add')}
              </button>
            </div>
            {(block.content.items || []).map((item: any, idx: number) => {
              const setItem = (changes: Record<string, any>) => {
                const items = [...(block.content.items || [])];
                items[idx] = { ...item, ...changes };
                patch({ items });
              };
              return (
                <div key={idx} className="p-3 bg-zinc-50 rounded-lg border border-zinc-200 space-y-2">
                  <div className="flex gap-2">
                    <input type="text" value={item.q || ''} onChange={(e) => setItem({ q: e.target.value })} className="flex-1 px-2 py-1 border border-zinc-300 rounded text-sm" placeholder={t('pp.faq.q')} />
                    <button onClick={() => patch({ items: (block.content.items || []).filter((_: any, i: number) => i !== idx) })} className="p-2 text-red-500 hover:bg-red-50 rounded">
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                  <textarea value={item.a || ''} onChange={(e) => setItem({ a: e.target.value })} rows={2} className="w-full px-2 py-1 border border-zinc-300 rounded text-sm resize-none" placeholder={t('pp.faq.a')} />
                </div>
              );
            })}
          </div>
        )}

        {/* List editor */}
        {block.type === 'list' && (
          <div className="space-y-4 pt-4 border-t border-zinc-100">
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-zinc-700">{t('pp.list.items')}</label>
              <button
                onClick={() => patch({ items: [...(block.content.items || []), { text: 'Item baru' }] })}
                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-bold rounded-lg bg-zinc-900 text-white"
              >
                <Plus className="w-3 h-3" /> {t('pp.add')}
              </button>
            </div>
            {(block.content.items || []).map((item: any, idx: number) => (
              <div key={idx} className="flex gap-2">
                <input
                  type="text"
                  value={item.text || ''}
                  onChange={(e) => {
                    const items = [...(block.content.items || [])];
                    items[idx] = { ...item, text: e.target.value };
                    patch({ items });
                  }}
                  className="flex-1 px-2 py-1 border border-zinc-300 rounded text-sm"
                  placeholder={t('pp.list.text')}
                />
                <button onClick={() => patch({ items: (block.content.items || []).filter((_: any, i: number) => i !== idx) })} className="p-2 text-red-500 hover:bg-red-50 rounded">
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>
            ))}
          </div>
        )}

        {/* Slider editor */}
        {block.type === 'slider' && (
          <div className="space-y-4 pt-4 border-t border-zinc-100">
            <label className="flex items-center gap-2 text-xs font-bold text-zinc-700">
              <input type="checkbox" checked={!!block.content.autoplay} onChange={(e) => patch({ autoplay: e.target.checked })} />
              {t('pp.slider.autoplay')}
            </label>
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-zinc-700">{t('pp.slider.images')}</label>
              <button
                onClick={() => patch({ images: [...(block.content.images || []), { url: 'https://picsum.photos/seed/new/1200/600', caption: '' }] })}
                className="inline-flex items-center gap-1 px-2 py-1 text-xs font-bold rounded-lg bg-zinc-900 text-white"
              >
                <Plus className="w-3 h-3" /> {t('pp.add')}
              </button>
            </div>
            {uploadError && (
              <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-600">{uploadError}</p>
            )}
            {(block.content.images || []).map((img: any, idx: number) => (
              <div key={idx} className="p-3 bg-zinc-50 rounded-lg border border-zinc-200 space-y-2">
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={img.url || ''}
                    onChange={(e) => {
                      const images = [...(block.content.images || [])];
                      images[idx] = { ...img, url: e.target.value };
                      patch({ images });
                    }}
                    className="flex-1 px-2 py-1 border border-zinc-300 rounded text-sm"
                    placeholder={t('pp.slider.imageUrl')}
                  />
                  <label className="p-2 bg-zinc-100 border border-zinc-200 rounded cursor-pointer hover:bg-zinc-200" title={t('pp.slider.upload')}>
                    <Upload className="w-4 h-4 text-zinc-600" />
                    <input
                      type="file"
                      className="hidden"
                      accept="image/*"
                      onChange={(e) => { uploadSliderImage(idx, e.target.files?.[0]); e.target.value = ''; }}
                    />
                  </label>
                  <button onClick={() => patch({ images: (block.content.images || []).filter((_: any, i: number) => i !== idx) })} className="p-2 text-red-500 hover:bg-red-50 rounded">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
                {img.url && (
                  <img src={img.url} alt={img.caption || `Slide ${idx + 1}`} className="w-full h-24 object-cover rounded border border-zinc-200" />
                )}
                <input
                  type="text"
                  value={img.caption || ''}
                  onChange={(e) => {
                    const images = [...(block.content.images || [])];
                    images[idx] = { ...img, caption: e.target.value };
                    patch({ images });
                  }}
                  className="w-full px-2 py-1 border border-zinc-300 rounded text-sm"
                  placeholder={t('pp.caption')}
                />
              </div>
            ))}
            <p className="text-[11px] text-zinc-400">{t('pp.slider.uploadHint')}</p>
          </div>
        )}
      </div>
    </div>
  );
};

// Convert ISO string to value usable by <input type="datetime-local"> (local time, no seconds).
function toLocalInput(iso: string | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
