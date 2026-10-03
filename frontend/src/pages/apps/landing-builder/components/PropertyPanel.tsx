import React, { useState } from 'react';
import {
  Palette, Upload, Trash2, Link as LinkIcon,
  Facebook, Instagram, Music2, AtSign, MousePointer2,
  AlignLeft, AlignCenter, AlignRight, LayoutTemplate, Plus, MessageCircle
} from 'lucide-react';
import { Block, BlockStyles } from '../types';
import { useLang } from '../i18n';
import LinkedProductPicker from './LinkedProductPicker';
import { getImageUrl, uploadLandingAsset } from '@/lib/hellomApi';
import { useSellerProducts } from '../sellerProducts';

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
  const [uploadingIdx, setUploadingIdx] = useState<number | null>(null);
  const { products: sellerProducts } = useSellerProducts();

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

  const MAX_SLIDER_IMAGE_BYTES = 8 * 1024 * 1024; // same as the server (FileAssetController::MAX_UPLOAD_KB)
  // Images go to the server (stored as WebP), never inline base64 in the page.
  const uploadSliderImage = async (idx: number, file: File | undefined) => {
    if (!file) return;
    if (file.size > MAX_SLIDER_IMAGE_BYTES) {
      setUploadError(t('pp.slider.tooLarge'));
      return;
    }
    setUploadError(null);
    setUploadingIdx(idx);
    try {
      const { url } = await uploadLandingAsset(file);
      const images = [...(block.content.images || [])];
      images[idx] = { ...images[idx], url };
      patch({ images });
    } catch (err) {
      setUploadError(err instanceof Error ? err.message : 'Upload gagal');
    } finally {
      setUploadingIdx(null);
    }
  };
  const hasButtonColor = block.content.buttonText !== undefined
    || ['product', 'button', 'countdown'].includes(block.type);

  return (
    <div className="p-4 md:p-6">
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
              <img src={getImageUrl(block.content.imageUrl)} alt="Preview" className="w-full h-32 object-cover rounded-lg border border-zinc-200 mt-2" />
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
            {block.content.gifUrl && <img src={getImageUrl(block.content.gifUrl)} alt="GIF" className="w-full h-32 object-contain rounded-lg border border-zinc-200 mt-2 bg-zinc-50" />}
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
          <LinkedProductPicker content={block.content} onPatch={patch} />
        )}
        {block.type === 'product' && !block.content.productId && (
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

            <div className="p-3 bg-blue-50 rounded-lg border border-blue-100 text-xs text-blue-800">
              {t('pp.product.gatewayNote')}
            </div>

            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">{t('pp.product.fileUrl')}</label>
              <div className="flex items-center gap-2">
                <LinkIcon className="w-4 h-4 text-zinc-400" />
                <input type="text" value={block.content.fileUrl || ''} onChange={(e) => patch({ fileUrl: e.target.value })} className={inputClass} placeholder="https://... (opsional)" />
              </div>
              <p className="text-xs text-zinc-500">{t('pp.product.fileUrlHint')}</p>
            </div>
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
            <label className="text-xs font-bold text-zinc-700">Link video YouTube atau TikTok</label>
            <input type="url" inputMode="url" value={block.content.videoUrl} onChange={(e) => patch({ videoUrl: e.target.value })} className={inputClass} placeholder="https://youtu.be/… atau https://www.tiktok.com/@akun/video/…" />
            <EmbedHint url={block.content.videoUrl} allowed={['youtube', 'tiktok']} />
          </div>
        )}

        {/* Spacer */}
        {block.type === 'spacer' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700" htmlFor="spacer-height">Tinggi spasi: {block.content.height ?? 32} px</label>
            <input id="spacer-height" type="range" min={8} max={160} step={8} value={block.content.height ?? 32} onChange={(e) => patch({ height: Number(e.target.value) })} className="w-full accent-zinc-900" />
          </div>
        )}

        {/* WhatsApp */}
        {block.type === 'whatsapp' && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Bentuk">
              {([['button', 'Tombol saja'], ['card', 'Kartu + judul']] as const).map(([value, label]) => (
                <button key={value} type="button" role="radio" aria-checked={(block.content.style ?? 'button') === value} onClick={() => patch({ style: value })}
                  className={`min-h-11 rounded-lg border text-sm font-semibold ${(block.content.style ?? 'button') === value ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 text-zinc-700'}`}>
                  {label}
                </button>
              ))}
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Teks tombol</label>
              <input type="text" maxLength={60} value={block.content.text ?? ''} onChange={(e) => patch({ text: e.target.value })} className={inputClass} />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Nomor WhatsApp <span className="font-normal text-zinc-500">(kosongkan = nomor di Tampilan)</span></label>
              <input type="tel" inputMode="tel" value={block.content.number ?? ''} onChange={(e) => patch({ number: e.target.value.replace(/[^0-9+]/g, '') })} className={inputClass} placeholder="08123456789" />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Pesan pembuka</label>
              <textarea rows={2} maxLength={300} value={block.content.message ?? ''} onChange={(e) => patch({ message: e.target.value })} className={`${inputClass} resize-none`} />
            </div>
          </div>
        )}

        {/* Embed */}
        {block.type === 'embed' && (
          <div className="space-y-2">
            <label className="text-xs font-bold text-zinc-700">Link Spotify, TikTok, Instagram, atau YouTube</label>
            <input type="url" inputMode="url" value={block.content.url ?? ''} onChange={(e) => patch({ url: e.target.value.trim() })} className={inputClass} placeholder="https://open.spotify.com/…" />
            <EmbedHint url={block.content.url} allowed={['spotify', 'tiktok', 'instagram', 'youtube']} />
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

        {/* Profile (link-in-bio header) */}
        {block.type === 'profile' && (
          <div className="space-y-4">
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Nama</label>
              <input type="text" value={block.content.name || ''} onChange={(e) => patch({ name: e.target.value })} className={inputClass} placeholder="Nama kamu / toko" maxLength={80} />
            </div>
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Bio</label>
              <textarea value={block.content.bio || ''} onChange={(e) => patch({ bio: e.target.value })} rows={3} maxLength={300} className={`${inputClass} resize-none`} />
            </div>
            {(['avatarUrl', 'coverUrl'] as const).map((field) => (
              <div key={field} className="space-y-2">
                <label className="text-xs font-bold text-zinc-700">{field === 'avatarUrl' ? 'Foto profil' : 'Foto sampul (opsional)'}</label>
                <div className="flex items-center gap-2">
                  {block.content[field] ? <img src={getImageUrl(block.content[field])} alt="" className={field === 'avatarUrl' ? 'h-12 w-12 rounded-full object-cover' : 'h-12 w-20 rounded-lg object-cover'} /> : null}
                  <label className="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-100 px-3 text-sm hover:bg-zinc-200">
                    <Upload className="h-4 w-4 text-zinc-600" /> Upload
                    <input type="file" className="hidden" accept="image/jpeg,image/png,image/webp" onChange={(e) => handleFileUpload(e, field)} />
                  </label>
                  {block.content[field] && <button type="button" onClick={() => patch({ [field]: '' })} className="p-2 text-red-500 hover:bg-red-50 rounded" aria-label="Hapus"><Trash2 className="h-4 w-4" /></button>}
                </div>
              </div>
            ))}
            <label className="flex items-center gap-2 text-xs font-bold text-zinc-700">
              <input type="checkbox" checked={block.content.showVerified !== false} onChange={(e) => patch({ showVerified: e.target.checked })} />
              Tampilkan lencana Penjual Terverifikasi (jika sudah terverifikasi)
            </label>
          </div>
        )}

        {/* Catalog */}
        {block.type === 'catalog' && (
          <div className="space-y-4">
            <label className="flex items-center gap-2 text-sm font-semibold text-zinc-700">
              <input type="checkbox" checked={block.content.showAll !== false} onChange={(e) => patch({ showAll: e.target.checked })} className="h-5 w-5" />
              Tampilkan semua produk aktif
            </label>
            {block.content.showAll === false && (
              <div className="space-y-1 rounded-lg border border-zinc-200 p-2">
                {(sellerProducts ?? []).length === 0 && <p className="text-xs text-zinc-500">Belum ada produk. Tambahkan di tab Produk.</p>}
                {(sellerProducts ?? []).map((p) => {
                  const ids: string[] = block.content.productIds || [];
                  const on = ids.includes(p.id);
                  return (
                    <label key={p.id} className="flex min-h-11 items-center gap-2 text-sm">
                      <input type="checkbox" checked={on} className="h-5 w-5" onChange={() => patch({ productIds: on ? ids.filter((x) => x !== p.id) : [...ids, p.id] })} />
                      <span className="flex-1 truncate">{p.name}</span><span className="text-xs text-zinc-500">Rp {p.price.toLocaleString('id-ID')}</span>
                    </label>
                  );
                })}
              </div>
            )}
            <div className="space-y-2">
              <label className="text-xs font-bold text-zinc-700">Kolom di layar besar</label>
              <select value={block.content.columns || 2} onChange={(e) => patch({ columns: Number(e.target.value) })} className={inputClass}>
                <option value={2}>2 kolom</option>
                <option value={3}>3 kolom</option>
              </select>
            </div>
          </div>
        )}

        {/* Slider / gallery images */}
        {(block.type === 'slider' || block.type === 'gallery') && (
          <div className="space-y-4 pt-4 border-t border-zinc-100">
            {block.type === 'slider' ? (
              <label className="flex items-center gap-2 text-xs font-bold text-zinc-700">
                <input type="checkbox" checked={!!block.content.autoplay} onChange={(e) => patch({ autoplay: e.target.checked })} />
                {t('pp.slider.autoplay')}
              </label>
            ) : (
              <div className="space-y-2">
                <label className="text-xs font-bold text-zinc-700">Kolom</label>
                <select value={block.content.columns || 3} onChange={(e) => patch({ columns: Number(e.target.value) })} className={inputClass}>
                  <option value={2}>2</option><option value={3}>3</option><option value={4}>4</option>
                </select>
              </div>
            )}
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-zinc-700">{t('pp.slider.images')}</label>
              <button
                onClick={() => patch({ images: [...(block.content.images || []), { url: '', caption: '' }] })}
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
                    {uploadingIdx === idx ? <span className="block h-4 w-4 animate-spin rounded-full border-2 border-zinc-400 border-t-transparent" /> : <Upload className="w-4 h-4 text-zinc-600" />}
                    <input
                      type="file"
                      className="hidden"
                      accept="image/jpeg,image/png,image/webp"
                      onChange={(e) => { void uploadSliderImage(idx, e.target.files?.[0]); e.target.value = ''; }}
                    />
                  </label>
                  <button onClick={() => patch({ images: (block.content.images || []).filter((_: any, i: number) => i !== idx) })} className="p-2 text-red-500 hover:bg-red-50 rounded">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
                {img.url && (
                  <img src={getImageUrl(img.url)} alt={img.caption || `Slide ${idx + 1}`} className="w-full h-24 object-cover rounded border border-zinc-200" />
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

      {/* Look of this block only (the whole page: Tampilan). Content comes first. */}
      <details className="group mt-8 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3">
        <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between text-sm font-semibold text-zinc-800">
          Gaya bagian ini <span className="text-xs font-normal text-zinc-500 group-open:hidden">warna, latar, jarak</span>
        </summary>
        <div className="mt-4 space-y-6 border-t border-zinc-200 px-1 pt-4">
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
      </details>
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

// Same link patterns as the server (App\Support\Landing\Embed): tells the seller right away
// whether the link will show, in plain words.
const EMBED_PATTERNS: Array<[string, string, RegExp]> = [
  ['spotify', 'Spotify', /^https?:\/\/open\.spotify\.com\/(?:intl-[a-z]{2}(?:-[a-z]{2})?\/)?(track|album|playlist|episode|show|artist)\/[A-Za-z0-9]{10,40}/i],
  ['tiktok', 'TikTok', /^https?:\/\/(?:www\.|m\.)?tiktok\.com\/@[\w.-]+\/video\/\d{8,25}/i],
  ['instagram', 'Instagram', /^https?:\/\/(?:www\.)?instagram\.com\/(?:[\w.]+\/)?(p|reel|tv)\/[A-Za-z0-9_-]{5,40}/i],
  ['youtube', 'YouTube', /(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)[A-Za-z0-9_-]{11}/],
];

function EmbedHint({ url, allowed }: { url?: string; allowed: string[] }) {
  const value = (url ?? '').trim();
  if (!value) return <p className="text-xs text-zinc-500">Tempel link dari tombol "Bagikan / Salin link" di aplikasinya.</p>;
  const found = EMBED_PATTERNS.find(([key, , re]) => allowed.includes(key) && re.test(value));
  if (found) return <p className="text-xs font-medium text-green-700">✓ Link {found[1]} dikenali.</p>;
  const names = EMBED_PATTERNS.filter(([key]) => allowed.includes(key)).map(([, name]) => name).join(', ');
  return <p className="text-xs font-medium text-red-600">Link belum dikenali. Pakai link video/lagu/postingan dari {names} (contoh tiktok.com/@akun/video/123…). Link pendek seperti vt.tiktok.com perlu dibuka dulu lalu salin link lengkapnya.</p>;
}
