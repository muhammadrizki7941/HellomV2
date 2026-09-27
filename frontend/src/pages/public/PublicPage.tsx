import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import {
  ShoppingCart, CheckCircle, XCircle, Loader2,
  ArrowRight, Star, Menu, X, FileText, Upload,
  Facebook, Instagram, Music2, AtSign, MessageCircle,
  Quote, Check, ChevronDown
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { createLandingOrder, getLandingOrderStatus, getPublicLandingByDomain, getPublicLandingPage, getPublicLandingPageByOrganization, submitLandingCustomer } from '@/lib/hellomApi';
import { THEMES } from '@/pages/apps/landing-builder/constants';
import { BLOCK_TYPES, BlockType } from '@/pages/apps/landing-builder/types';

// --- Types ---
interface Block {
  id: string;
  type: BlockType;
  content: any;
  styles?: any;
}

// --- Components for Rendering Blocks ---

const parseRupiah = (value: unknown) => {
  if (typeof value === 'number') return value;
  const numeric = String(value || '').replace(/[^\d]/g, '');
  return numeric ? Number(numeric) : 0;
};

const formatPrice = (value: unknown) => {
  const price = parseRupiah(value);
  return price > 0 ? `Rp ${price.toLocaleString('id-ID')}` : String(value || 'Rp 0');
};

const resolveTheme = (themeId: string) => THEMES.find((item) => item.id === themeId) || THEMES[0];

const resolveBlockStyles = (blockStyles: any, themeId: string) => {
  const theme = resolveTheme(themeId);
  return {
    backgroundColor: blockStyles?.backgroundColor || theme.colors.backgroundColor,
    backgroundImage: blockStyles?.backgroundImage,
    textColor: blockStyles?.textColor || theme.colors.textColor,
    buttonColor: blockStyles?.buttonColor || theme.colors.buttonColor,
    buttonTextColor: blockStyles?.buttonTextColor || theme.colors.buttonTextColor,
    accentColor: blockStyles?.buttonColor || theme.colors.accentColor,
    textAlign: blockStyles?.textAlign || 'center',
  };
};

const buildWhatsappUrl = (phone: unknown, message: unknown) => {
  const normalizedPhone = String(phone || '').replace(/\D/g, '');
  if (!normalizedPhone) return '#';
  return `https://wa.me/${normalizedPhone}?text=${encodeURIComponent(String(message || ''))}`;
};

const RenderHero = ({ content, styles }: { content: any, styles?: any }) => (
  <section 
    className="py-20 px-6 text-center relative overflow-hidden"
    style={{ 
      backgroundColor: styles?.backgroundColor,
      backgroundImage: styles?.backgroundImage ? `url(${styles.backgroundImage})` : undefined,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
      color: styles?.textColor
    }}
  >
    {styles?.backgroundImage && <div className="absolute inset-0 bg-black/50 z-0" />}
    <div className="relative z-10 max-w-4xl mx-auto">
      <h1 className="text-4xl md:text-6xl font-bold mb-6 leading-tight">{content.title}</h1>
      <p className="text-xl opacity-90 mb-8 max-w-2xl mx-auto">{content.subtitle}</p>
      {(content.showButton ?? true) && (
        <button 
          className="px-8 py-4 bg-yellow-400 text-black font-bold rounded-full hover:bg-yellow-300 transition-transform hover:scale-105"
          style={{ backgroundColor: styles?.buttonColor, color: styles?.buttonTextColor }}
        >
          {content.buttonText || content.cta_label || 'Get Started'}
        </button>
      )}
    </div>
  </section>
);

const RenderFeatures = ({ content, styles }: { content: any, styles?: any }) => (
  <section 
    className="py-20 px-6"
    style={{ backgroundColor: styles?.backgroundColor || '#fff', color: styles?.textColor }}
  >
    <div className="max-w-6xl mx-auto">
      <h2 className="text-3xl font-bold text-center mb-12">{content.title}</h2>
      <div className="grid md:grid-cols-3 gap-8">
        {(content.items || []).map((item: any, i: number) => (
          <div key={i} className="p-6 rounded-2xl bg-zinc-50 border border-zinc-100">
            <h3 className="text-xl font-bold mb-3">{item.title}</h3>
            <p className="leading-relaxed opacity-80">{item.description || item.desc || item.text || ''}</p>
          </div>
        ))}
      </div>
    </div>
  </section>
);

const RenderProduct = ({ content, styles, blockId, onBuy }: { content: any, styles?: any, blockId: string, onBuy: (product: any) => void }) => (
  <section
    className="py-20 px-6"
    style={{ backgroundColor: styles?.backgroundColor || '#f9fafb', color: styles?.textColor }}
  >
      <div className="max-w-4xl mx-auto bg-white rounded-3xl shadow-xl overflow-hidden border border-zinc-100 flex flex-col md:flex-row">
      <div className="md:w-1/2 bg-zinc-200 min-h-[300px] flex items-center justify-center overflow-hidden">
         {content.imageUrl ? (
           <img src={content.imageUrl} alt={content.name} className="h-full w-full object-cover" />
         ) : (
           <ShoppingCart className="w-24 h-24 text-zinc-400" />
         )}
      </div>
      <div className="md:w-1/2 p-8 md:p-12 flex flex-col justify-center">
        <h2 className="text-3xl font-bold mb-2 text-zinc-900">{content.name}</h2>
        <p className="text-2xl font-bold mb-6" style={{ color: styles?.accentColor }}>{formatPrice(content.price)}</p>
        <p className="text-zinc-600 mb-8 leading-relaxed">{content.description}</p>

        <button
          onClick={() => onBuy({ blockId, name: content.name, price: content.price })}
          className="w-full py-4 bg-black text-white font-bold rounded-xl hover:bg-zinc-800 transition-all flex items-center justify-center gap-2"
          style={{ backgroundColor: styles?.buttonColor, color: styles?.buttonTextColor }}
        >
          {content.buttonText || 'Beli Sekarang'} <ArrowRight className="w-5 h-5" />
        </button>
        <p className="text-xs text-center text-zinc-400 mt-4">Pembayaran aman diproses oleh Hellom</p>
      </div>
    </div>
  </section>
);

const RenderContent = ({ content, styles }: { content: any, styles?: any }) => (
  <section className="py-20 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
    <div className="max-w-3xl mx-auto prose prose-lg">
      <h2 className="text-3xl font-bold mb-6">{content.title}</h2>
      <div className="whitespace-pre-wrap opacity-80">{content.body}</div>
    </div>
  </section>
);

const RenderCTA = ({ content, styles, pageSettings }: { content: any, styles?: any, pageSettings: { whatsappNumber: string; whatsappMessage: string } }) => (
  <section 
    className="py-24 px-6 text-center"
    style={{ 
      backgroundColor: styles?.backgroundColor || '#000', 
      color: styles?.textColor || '#fff' 
    }}
  >
    <div className="max-w-3xl mx-auto">
      <h2 className="text-3xl md:text-5xl font-bold mb-6">{content.title}</h2>
      <p className="text-xl opacity-80 mb-10">{content.subtitle}</p>
      <a
        href={content.actionType === 'whatsapp'
          ? buildWhatsappUrl(content.whatsappNumber || pageSettings.whatsappNumber, content.whatsappMessage || pageSettings.whatsappMessage)
          : content.linkUrl || '#'}
        target="_blank"
        rel="noopener noreferrer"
        className="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-black font-bold rounded-full hover:bg-zinc-200 transition-colors"
        style={{ backgroundColor: styles?.buttonColor, color: styles?.buttonTextColor }}
      >
        {content.actionType === 'whatsapp' && <MessageCircle className="w-5 h-5" />}
        {content.buttonText || content.button_label || 'Continue'}
      </a>
    </div>
  </section>
);

const RenderBanner = ({ content, styles }: { content: any, styles?: any }) => (
  <section
    className="relative overflow-hidden py-24 px-6 text-center"
    style={{
      backgroundImage: content.imageUrl ? `url(${content.imageUrl})` : undefined,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
      color: content.textColor || styles?.textColor || '#fff',
    }}
  >
    <div className="absolute inset-0 bg-black" style={{ opacity: content.overlayOpacity ?? 0.55 }} />
    <div className="relative z-10 mx-auto max-w-4xl">
      <h2 className="mb-4 text-3xl font-bold md:text-5xl">{content.title}</h2>
      <p className="text-lg opacity-90 md:text-xl">{content.subtitle}</p>
    </div>
  </section>
);

const RenderText = ({ content, styles }: { content: any, styles?: any }) => (
  <section className="py-16 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
    <div className="mx-auto max-w-3xl whitespace-pre-wrap text-base leading-8 opacity-80" style={{ textAlign: styles?.textAlign || 'left' }}>
      {content.body}
    </div>
  </section>
);

const RenderImage = ({ content, styles }: { content: any, styles?: any }) => (
  <section className="py-16 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
    <div className="mx-auto max-w-5xl text-center">
      <img src={content.imageUrl} alt={content.caption || 'Landing page image'} className="w-full rounded-2xl border border-zinc-100 object-cover shadow-sm" />
      {content.caption && <p className="mt-4 text-sm text-zinc-500">{content.caption}</p>}
    </div>
  </section>
);

const RenderPdf = ({ content, styles, blockId, onBuy }: { content: any, styles?: any, blockId: string, onBuy: (product: any) => void }) => (
  <section className="py-16 px-6" style={{ backgroundColor: styles?.backgroundColor || '#f8fafc', color: styles?.textColor }}>
    <div className="mx-auto flex max-w-2xl flex-col gap-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm md:flex-row md:items-center">
      <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-700">
        <FileText className="h-7 w-7" />
      </div>
      <div className="flex-1">
        <h3 className="text-lg font-bold text-zinc-900">{content.title}</h3>
        <p className="mt-1 text-sm text-zinc-600">{content.description}</p>
        {content.fileName && <p className="mt-1 text-xs text-zinc-400">{content.fileName}</p>}
        {content.accessType === 'paid' && <p className="mt-2 text-sm font-bold text-green-700">{formatPrice(content.price)}</p>}
      </div>
      {content.accessType === 'paid' ? (
        <button
          onClick={() => onBuy({ blockId, name: content.title || 'Katalog Produk', price: content.price })}
          className="inline-flex items-center justify-center gap-2 rounded-xl bg-black px-4 py-3 text-sm font-bold text-white"
        >
          <Upload className="h-4 w-4 rotate-180" /> {content.paidButtonText || 'Beli Katalog'}
        </button>
      ) : (
        <a href={content.fileUrl || '#'} download className="inline-flex items-center justify-center gap-2 rounded-xl bg-black px-4 py-3 text-sm font-bold text-white">
          <Upload className="h-4 w-4 rotate-180" /> {content.buttonText || 'Download Gratis'}
        </a>
      )}
    </div>
  </section>
);

const RenderSocial = ({ content, styles }: { content: any, styles?: any }) => {
  const links = [
    { key: 'facebook', href: content.facebook, icon: Facebook, label: 'Facebook', color: 'text-blue-600' },
    { key: 'instagram', href: content.instagram, icon: Instagram, label: 'Instagram', color: 'text-pink-600' },
    { key: 'tiktok', href: content.tiktok, icon: Music2, label: 'TikTok', color: 'text-black' },
    { key: 'threads', href: content.threads, icon: AtSign, label: 'Threads', color: 'text-black' },
  ].filter((item) => item.href);

  return (
    <section className="py-14 px-6 text-center" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
      <h3 className="mb-6 text-xl font-bold">Ikuti Kami</h3>
      {links.length > 0 ? (
        <div className="flex flex-wrap justify-center gap-4">
          {links.map(({ key, href, icon: Icon, label, color }) => (
            <a key={key} href={href} target="_blank" rel="noopener noreferrer" className={`inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-5 py-3 font-semibold shadow-sm ${color}`}>
              <Icon className="h-5 w-5" /> {label}
            </a>
          ))}
        </div>
      ) : (
        <p className="text-sm text-zinc-500">Link sosial media belum ditambahkan.</p>
      )}
    </section>
  );
};

const RenderVideo = ({ content, styles }: { content: any, styles?: any }) => (
  <section className="py-20 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
    <div className="mx-auto max-w-4xl">
      <h3 className="mb-8 text-center text-2xl font-bold">{content.title}</h3>
      <div className="aspect-video overflow-hidden rounded-2xl bg-black shadow-xl">
        <iframe
          width="100%"
          height="100%"
          src={content.videoUrl}
          title={content.title}
          frameBorder="0"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowFullScreen
        />
      </div>
    </div>
  </section>
);

const RenderForm = ({
  block,
  pageId,
  styles,
}: {
  block: Block;
  pageId: number | null;
  styles?: any;
}) => {
  const { content } = block;
  const [values, setValues] = useState<Record<string, string>>({});
  const [status, setStatus] = useState<'idle' | 'submitting' | 'success' | 'error'>('idle');
  const [error, setError] = useState<string | null>(null);

  const fields = Array.isArray(content.fields) ? content.fields : [];

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!pageId) {
      setError('Landing page belum siap menerima data.');
      setStatus('error');
      return;
    }

    setStatus('submitting');
    setError(null);
    try {
      await submitLandingCustomer(pageId, {
        block_id: block.id,
        form_title: content.title,
        fields: values,
      });
      setValues({});
      setStatus('success');
    } catch (submitError) {
      setError(submitError instanceof Error ? submitError.message : 'Gagal mengirim data.');
      setStatus('error');
    }
  };

  return (
    <section className="py-20 px-6" style={{ backgroundColor: styles?.backgroundColor || '#fff', color: styles?.textColor }}>
      <form onSubmit={submit} className="mx-auto max-w-2xl rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
        <h2 className="text-3xl font-bold text-zinc-900">{content.title}</h2>
        {content.subtitle && <p className="mt-2 text-zinc-600">{content.subtitle}</p>}
        <div className="mt-6 grid gap-4">
          {fields.map((field: any) => (
            <label key={field.id} className="grid gap-1 text-sm font-semibold text-zinc-700">
              <span>{field.label}{field.required ? ' *' : ''}</span>
              {field.type === 'textarea' ? (
                <textarea
                  rows={3}
                  required={!!field.required}
                  value={values[field.id] || ''}
                  onChange={(e) => setValues((current) => ({ ...current, [field.id]: e.target.value }))}
                  className="rounded-xl border border-zinc-300 px-3 py-2 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-100"
                />
              ) : (
                <input
                  type={field.type || 'text'}
                  required={!!field.required}
                  value={values[field.id] || ''}
                  onChange={(e) => setValues((current) => ({ ...current, [field.id]: e.target.value }))}
                  className="rounded-xl border border-zinc-300 px-3 py-2 outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-100"
                />
              )}
            </label>
          ))}
        </div>
        {status === 'success' && (
          <div className="mt-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700">
            {content.successMessage || 'Terima kasih, data Anda sudah terkirim.'}
          </div>
        )}
        {status === 'error' && error && (
          <div className="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
            {error}
          </div>
        )}
        <button
          type="submit"
          disabled={status === 'submitting'}
          className="mt-6 w-full rounded-xl bg-black px-5 py-3 font-bold text-white disabled:opacity-60"
          style={{ backgroundColor: styles?.buttonColor }}
        >
          {status === 'submitting' ? 'Mengirim...' : (content.buttonText || 'Kirim Pendaftaran')}
        </button>
      </form>
    </section>
  );
};

const RenderButton = ({ content, styles, pageSettings }: { content: any, styles?: any, pageSettings: { whatsappNumber: string; whatsappMessage: string } }) => {
  const align = content.align || 'center';
  const justify = align === 'left' ? 'justify-start' : align === 'right' ? 'justify-end' : 'justify-center';
  const href = content.actionType === 'whatsapp'
    ? buildWhatsappUrl(content.whatsappNumber || pageSettings.whatsappNumber, content.whatsappMessage || pageSettings.whatsappMessage)
    : content.linkUrl || '#';
  return (
    <section className="py-10 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
      <div className={`flex ${justify}`}>
        <a
          href={href}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-2 rounded-xl px-7 py-3.5 font-bold transition-transform hover:scale-105"
          style={{ backgroundColor: styles?.buttonColor, color: styles?.buttonTextColor }}
        >
          {content.actionType === 'whatsapp' && <MessageCircle className="w-5 h-5" />}
          {content.text || 'Klik di Sini'}
          {content.actionType !== 'whatsapp' && <ArrowRight className="w-5 h-5" />}
        </a>
      </div>
    </section>
  );
};

const RenderDivider = ({ content, styles }: { content: any, styles?: any }) => (
  <section className="py-8 px-6" style={{ backgroundColor: styles?.backgroundColor }}>
    <div className="mx-auto" style={{ width: `${content.width ?? 100}%` }}>
      <hr style={{ borderTopStyle: content.style || 'solid', borderTopWidth: `${content.thickness ?? 1}px`, borderColor: styles?.textColor, opacity: 0.35 }} />
    </div>
  </section>
);

const RenderTestimonials = ({ content, styles }: { content: any, styles?: any }) => (
  <section className="py-20 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
    <div className="max-w-6xl mx-auto">
      {content.title && <h2 className="text-3xl font-bold text-center mb-12">{content.title}</h2>}
      <div className="grid md:grid-cols-3 gap-6">
        {(content.items || []).map((item: any, i: number) => (
          <div key={i} className="p-6 rounded-2xl bg-white border border-zinc-100 shadow-sm text-left">
            <Quote className="w-7 h-7 mb-4 text-zinc-300" />
            <p className="leading-relaxed text-zinc-700 mb-4">{item.text}</p>
            <div className="flex gap-1 mb-3">
              {Array.from({ length: 5 }).map((_, s) => (
                <Star key={s} className={`w-4 h-4 ${s < (item.rating ?? 5) ? 'fill-yellow-400 text-yellow-400' : 'text-zinc-300'}`} />
              ))}
            </div>
            <p className="font-bold text-zinc-900">{item.name}</p>
            {item.role && <p className="text-sm text-zinc-500">{item.role}</p>}
          </div>
        ))}
      </div>
    </div>
  </section>
);

const RenderFaq = ({ content, styles }: { content: any, styles?: any }) => {
  const [open, setOpen] = useState<number | null>(0);
  return (
    <section className="py-20 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
      <div className="max-w-2xl mx-auto">
        {content.title && <h2 className="text-3xl font-bold text-center mb-10">{content.title}</h2>}
        <div className="space-y-3">
          {(content.items || []).map((item: any, i: number) => (
            <div key={i} className="rounded-xl border border-zinc-200 bg-white overflow-hidden">
              <button
                onClick={() => setOpen(open === i ? null : i)}
                className="flex w-full items-center justify-between px-5 py-4 text-left font-semibold text-zinc-900"
              >
                <span>{item.q}</span>
                <ChevronDown className={`w-5 h-5 shrink-0 text-zinc-400 transition-transform ${open === i ? 'rotate-180' : ''}`} />
              </button>
              {open === i && <div className="px-5 pb-4 text-zinc-600">{item.a}</div>}
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};

const RenderList = ({ content, styles }: { content: any, styles?: any }) => (
  <section className="py-16 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
    <div className="max-w-2xl mx-auto">
      {content.title && <h2 className="text-3xl font-bold text-center mb-8">{content.title}</h2>}
      <ul className="space-y-4">
        {(content.items || []).map((item: any, i: number) => (
          <li key={i} className="flex items-start gap-3">
            <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full" style={{ backgroundColor: (styles?.accentColor || '#16a34a') + '22', color: styles?.accentColor || '#16a34a' }}>
              <Check className="w-3.5 h-3.5" />
            </span>
            <span className="text-lg opacity-90">{item.text}</span>
          </li>
        ))}
      </ul>
    </div>
  </section>
);

const RenderSlider = ({ content, styles }: { content: any, styles?: any }) => {
  const images: any[] = content.images || [];
  const [index, setIndex] = useState(0);
  useEffect(() => {
    if (!content.autoplay || images.length <= 1) return;
    const timer = setInterval(() => setIndex((i) => (i + 1) % images.length), 4000);
    return () => clearInterval(timer);
  }, [content.autoplay, images.length]);

  if (images.length === 0) return null;
  const current = images[Math.min(index, images.length - 1)];
  return (
    <section className="py-16 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
      <div className="max-w-4xl mx-auto">
        <div className="relative aspect-video w-full overflow-hidden rounded-2xl bg-black/10">
          {current?.url && <img src={current.url} alt={current.caption || 'Slide'} className="h-full w-full object-cover" />}
          {images.length > 1 && (
            <>
              <button onClick={() => setIndex((i) => (i - 1 + images.length) % images.length)} className="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-white/80 p-2 shadow hover:bg-white">
                <ArrowRight className="w-5 h-5 rotate-180 text-zinc-800" />
              </button>
              <button onClick={() => setIndex((i) => (i + 1) % images.length)} className="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-white/80 p-2 shadow hover:bg-white">
                <ArrowRight className="w-5 h-5 text-zinc-800" />
              </button>
            </>
          )}
        </div>
        {current?.caption && <p className="mt-3 text-center text-sm opacity-60">{current.caption}</p>}
        <div className="mt-4 flex justify-center gap-1.5">
          {images.map((_, i) => (
            <button key={i} onClick={() => setIndex(i)} className={`h-2 rounded-full transition-all ${i === index ? 'w-6 bg-zinc-800' : 'w-2 bg-zinc-300'}`} />
          ))}
        </div>
      </div>
    </section>
  );
};

const RenderCountdown = ({ content, styles }: { content: any, styles?: any }) => {
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  const diff = Math.max(0, new Date(content.targetDate).getTime() - now);
  const parts = [
    { label: 'Hari', value: Math.floor(diff / 86400000) },
    { label: 'Jam', value: Math.floor((diff % 86400000) / 3600000) },
    { label: 'Menit', value: Math.floor((diff % 3600000) / 60000) },
    { label: 'Detik', value: Math.floor((diff % 60000) / 1000) },
  ];
  return (
    <section className="py-20 px-6 text-center" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
      {content.title && <h2 className="text-3xl font-bold mb-2">{content.title}</h2>}
      {content.subtitle && <p className="opacity-80 mb-8">{content.subtitle}</p>}
      <div className="flex justify-center gap-3 sm:gap-4">
        {parts.map((part) => (
          <div key={part.label} className="flex flex-col items-center">
            <div className="flex h-16 w-16 sm:h-24 sm:w-24 items-center justify-center rounded-2xl text-2xl sm:text-4xl font-bold" style={{ backgroundColor: styles?.buttonColor, color: styles?.buttonTextColor }}>
              {String(part.value).padStart(2, '0')}
            </div>
            <span className="mt-2 text-sm opacity-70">{part.label}</span>
          </div>
        ))}
      </div>
    </section>
  );
};

const RenderGif = ({ content, styles }: { content: any, styles?: any }) => (
  <section className="py-16 px-6 text-center" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
    <div className="mx-auto max-w-3xl">
      {content.gifUrl && <img src={content.gifUrl} alt={content.caption || 'GIF'} className="mx-auto rounded-2xl shadow-sm" />}
      {content.caption && <p className="mt-4 text-sm opacity-60 italic">{content.caption}</p>}
    </div>
  </section>
);

const RenderHtml = ({ content, styles }: { content: any, styles?: any }) => (
  <section className="py-12 px-6" style={{ backgroundColor: styles?.backgroundColor, color: styles?.textColor }}>
    <div className="mx-auto max-w-3xl" dangerouslySetInnerHTML={{ __html: content.html || '' }} />
  </section>
);

// --- Main Public Page Component ---

export default function PublicPage() {
  const { organizationSlug, pageSlug, domain } = useParams<{ organizationSlug?: string; pageSlug?: string; domain?: string }>();
  const [blocks, setBlocks] = useState<Block[]>([]);
  const [theme, setTheme] = useState('modern');
  const [pageId, setPageId] = useState<number | null>(null);
  const [pageSettings, setPageSettings] = useState({
    whatsappNumber: '',
    whatsappMessage: 'Halo, saya tertarik dengan produk Anda.',
    showFloatingWhatsapp: false,
  });
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showCheckout, setShowCheckout] = useState(false);
  const [selectedProduct, setSelectedProduct] = useState<any>(null);
  const [checkoutSubmitting, setCheckoutSubmitting] = useState(false);
  const [paymentError, setPaymentError] = useState<string | null>(null);
  const [checkoutOrgSlug, setCheckoutOrgSlug] = useState<string>('');
  const [buyerForm, setBuyerForm] = useState({ name: '', email: '', phone: '' });
  // In-app QRIS flow (iPaymu, when only QRIS is enabled)
  const [qrCheckout, setQrCheckout] = useState<{ reference: string; qrImageUrl: string } | null>(null);
  const [qrPaid, setQrPaid] = useState(false);

  // Load published page from backend public endpoint
  useEffect(() => {
    const loadPage = async () => {
      setLoading(true);
      setLoadError(null);
      try {
        const response = domain
          ? await getPublicLandingByDomain(domain)
          : organizationSlug && pageSlug
            ? await getPublicLandingPage(organizationSlug, pageSlug)
            : organizationSlug
              ? await getPublicLandingPageByOrganization(organizationSlug)
            : null;

        if (!response) {
          setLoadError('URL public tidak lengkap. Gunakan /{organizationSlug}, /p/{organizationSlug}/{pageSlug}, atau /p/domain/{domain}.');
          setBlocks([]);
          setLoading(false);
          return;
        }

        const landingPayload = response as {
          blocks?: Array<Record<string, any>>;
          page?: { id?: number; organization_slug?: string | null; content?: Record<string, unknown> | null };
          seo?: { title?: string };
        };

        setCheckoutOrgSlug(String(landingPayload.page?.organization_slug || organizationSlug || ''));

        const mappedBlocks: Block[] = (landingPayload.blocks || []).map((block) => ({
          id: String(block.id),
          type: (BLOCK_TYPES.includes(block.block_type as BlockType)
            ? block.block_type
            : 'content') as Block['type'],
          content: block.content || {},
          styles: (block.content as Record<string, unknown> | null)?.styles,
        }));

        setBlocks(mappedBlocks);
        setPageId(landingPayload.page?.id || null);
        const resolvedTheme = String(landingPayload.page?.content?.theme || 'modern');
        setTheme(resolvedTheme);
        const settings = landingPayload.page?.content?.settings as Partial<typeof pageSettings> | undefined;
        if (settings) {
          setPageSettings((current) => ({ ...current, ...settings }));
        }
        if (landingPayload.seo?.title) {
          document.title = landingPayload.seo.title;
        }
      } catch (error) {
        const message = error instanceof Error ? error.message : 'Gagal memuat public page';
        setLoadError(message);
        setBlocks([]);
        setPageId(null);
      } finally {
        setLoading(false);
      }
    };

    void loadPage();
  }, [organizationSlug, pageSlug, domain]);

  const handleBuy = (product: any) => {
    // All product/PDF purchases go through the secure gateway only.
    setSelectedProduct(product);
    setPaymentError(null);
    setCheckoutSubmitting(false);
    setShowCheckout(true);
  };

  const submitBuyerCheckout = async () => {
    if (!selectedProduct?.blockId) {
      setPaymentError('Produk tidak valid.');
      return;
    }
    if (!checkoutOrgSlug) {
      setPaymentError('Halaman ini belum siap menerima pembayaran.');
      return;
    }
    if (!buyerForm.name.trim() || !buyerForm.email.trim()) {
      setPaymentError('Nama dan email wajib diisi.');
      return;
    }

    setCheckoutSubmitting(true);
    setPaymentError(null);
    try {
      const res = await createLandingOrder(checkoutOrgSlug, {
        block_id: selectedProduct.blockId,
        buyer_name: buyerForm.name.trim(),
        buyer_email: buyerForm.email.trim(),
        buyer_phone: buyerForm.phone.trim() || undefined,
      }) as { payment_url?: string | null; mode?: string; reference_id?: string; qr_image_url?: string };

      // QRIS-only (iPaymu direct): show a downloadable QR in-app and poll for payment.
      if (res?.mode === 'qris' && res.reference_id) {
        setQrCheckout({ reference: res.reference_id, qrImageUrl: String(res.qr_image_url || '') });
        setQrPaid(false);
        setCheckoutSubmitting(false);
        return;
      }

      if (res?.payment_url) {
        window.location.href = res.payment_url;
        return;
      }
      setPaymentError('Gagal membuat halaman pembayaran. Coba lagi.');
      setCheckoutSubmitting(false);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Checkout gagal diproses.';
      setPaymentError(message);
      setCheckoutSubmitting(false);
    }
  };

  // Poll order status while a QRIS payment is pending.
  useEffect(() => {
    if (!qrCheckout || qrPaid) return;
    const timer = setInterval(async () => {
      try {
        const s = await getLandingOrderStatus(qrCheckout.reference) as { status?: string };
        if (s?.status === 'paid') {
          setQrPaid(true);
          clearInterval(timer);
        }
      } catch {
        /* keep polling */
      }
    }, 4000);
    return () => clearInterval(timer);
  }, [qrCheckout, qrPaid]);

  if (loading) return <div className="min-h-screen flex items-center justify-center"><Loader2 className="w-8 h-8 animate-spin" /></div>;

  if (blocks.length === 0) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center p-6 text-center">
        <h1 className="text-2xl font-bold mb-2">Page Not Found</h1>
        <p className="text-zinc-500 mb-2">This page hasn't been published yet.</p>
        {loadError && <p className="text-sm text-red-600 mb-6">{loadError}</p>}
        <Link to="/dashboard/apps/landing-builder" className="px-6 py-2 bg-black text-white rounded-lg">Go to Builder</Link>
      </div>
    );
  }

  return (
    <div className="min-h-screen font-sans" style={{ backgroundColor: resolveTheme(theme).colors.backgroundColor, color: resolveTheme(theme).colors.textColor }}>
      
      {/* Render Blocks */}
      {blocks.map(block => {
        const mergedStyles = resolveBlockStyles(block.styles, theme);
        switch (block.type) {
          case 'hero': return <RenderHero key={block.id} content={block.content} styles={mergedStyles} />;
          case 'features': return <RenderFeatures key={block.id} content={block.content} styles={mergedStyles} />;
          case 'product': return <RenderProduct key={block.id} content={block.content} styles={mergedStyles} blockId={String(block.id)} onBuy={handleBuy} />;
          case 'content': return <RenderContent key={block.id} content={block.content} styles={mergedStyles} />;
          case 'cta': return <RenderCTA key={block.id} content={block.content} styles={mergedStyles} pageSettings={pageSettings} />;
          case 'banner': return <RenderBanner key={block.id} content={block.content} styles={mergedStyles} />;
          case 'video': return <RenderVideo key={block.id} content={block.content} styles={mergedStyles} />;
          case 'text': return <RenderText key={block.id} content={block.content} styles={mergedStyles} />;
          case 'image': return <RenderImage key={block.id} content={block.content} styles={mergedStyles} />;
          case 'pdf': return <RenderPdf key={block.id} content={block.content} styles={mergedStyles} blockId={String(block.id)} onBuy={handleBuy} />;
          case 'social': return <RenderSocial key={block.id} content={block.content} styles={mergedStyles} />;
          case 'form': return <RenderForm key={block.id} block={block} pageId={pageId} styles={mergedStyles} />;
          case 'button': return <RenderButton key={block.id} content={block.content} styles={mergedStyles} pageSettings={pageSettings} />;
          case 'divider': return <RenderDivider key={block.id} content={block.content} styles={mergedStyles} />;
          case 'testimonials': return <RenderTestimonials key={block.id} content={block.content} styles={mergedStyles} />;
          case 'faq': return <RenderFaq key={block.id} content={block.content} styles={mergedStyles} />;
          case 'list': return <RenderList key={block.id} content={block.content} styles={mergedStyles} />;
          case 'slider': return <RenderSlider key={block.id} content={block.content} styles={mergedStyles} />;
          case 'countdown': return <RenderCountdown key={block.id} content={block.content} styles={mergedStyles} />;
          case 'gif': return <RenderGif key={block.id} content={block.content} styles={mergedStyles} />;
          case 'html': return <RenderHtml key={block.id} content={block.content} styles={mergedStyles} />;
          default: return null;
        }
      })}

      {/* Footer */}
      <footer className="py-8 text-center text-sm opacity-50 border-t border-current/10 mt-auto">
        Powered by Hellom Page Builder
      </footer>

      {pageSettings.showFloatingWhatsapp && pageSettings.whatsappNumber && (
        <a
          href={buildWhatsappUrl(pageSettings.whatsappNumber, pageSettings.whatsappMessage)}
          target="_blank"
          rel="noopener noreferrer"
          className="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-green-500 text-white shadow-2xl transition-transform hover:scale-105"
        >
          <MessageCircle className="h-7 w-7" />
        </a>
      )}

      {/* Checkout Modal */}
      <AnimatePresence>
        {showCheckout && (
          <motion.div 
            initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
            className="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4 backdrop-blur-sm"
          >
            <motion.div 
              initial={{ scale: 0.95, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} exit={{ scale: 0.95, opacity: 0 }}
              className="bg-white rounded-2xl max-w-md w-full overflow-hidden shadow-2xl"
            >
              <div className="p-6">
                <div className="flex justify-between items-center mb-5">
                  <h3 className="text-xl font-bold text-zinc-900">{qrCheckout ? (qrPaid ? 'Pembayaran Berhasil' : 'Scan QRIS') : 'Checkout'}</h3>
                  <button onClick={() => { setShowCheckout(false); setQrCheckout(null); setQrPaid(false); }}><X className="w-5 h-5 text-zinc-400" /></button>
                </div>

                <div className="mb-5 p-4 bg-zinc-50 rounded-xl border border-zinc-100">
                  <p className="text-sm text-zinc-500 mb-1">Produk</p>
                  <p className="font-bold text-zinc-900">{selectedProduct?.name}</p>
                  <div className="my-2 border-t border-zinc-200" />
                  <div className="flex justify-between items-center">
                    <p className="text-sm text-zinc-500">Total</p>
                    <p className="text-xl font-bold text-zinc-900">{formatPrice(selectedProduct?.price)}</p>
                  </div>
                </div>

                {paymentError && (
                  <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {paymentError}
                  </div>
                )}

                {qrCheckout ? (
                  qrPaid ? (
                    <div className="text-center py-4">
                      <div className="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4">
                        <CheckCircle className="w-8 h-8" />
                      </div>
                      <p className="font-bold text-zinc-900 text-lg">Pembayaran diterima 🎉</p>
                      <p className="mt-1 text-sm text-zinc-500">Bukti & akses produk dikirim ke email kamu.</p>
                      <button
                        onClick={() => { setShowCheckout(false); setQrCheckout(null); setQrPaid(false); }}
                        className="mt-5 w-full py-3 bg-black text-white font-bold rounded-xl"
                      >
                        Selesai
                      </button>
                    </div>
                  ) : (
                    <div className="text-center">
                      <p className="text-sm text-zinc-600 mb-3">Scan QR berikut dengan aplikasi e-wallet / m-banking apa pun (QRIS).</p>
                      <div className="rounded-2xl border border-zinc-200 p-3 inline-block bg-white">
                        {qrCheckout.qrImageUrl
                          ? <img src={qrCheckout.qrImageUrl} alt="QRIS" className="w-56 h-56 object-contain" />
                          : <div className="w-56 h-56 flex items-center justify-center text-zinc-400 text-sm">QR tidak tersedia</div>}
                      </div>
                      <div className="mt-4 flex items-center justify-center gap-2 text-sm text-zinc-500">
                        <Loader2 className="w-4 h-4 animate-spin" /> Menunggu pembayaran...
                      </div>
                      <a
                        href={`${qrCheckout.qrImageUrl}?download=1`}
                        className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 py-3 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                      >
                        <Upload className="w-4 h-4 rotate-180" /> Download Gambar QR
                      </a>
                      <p className="mt-3 text-xs text-zinc-400">Jangan tutup halaman ini sampai pembayaran selesai.</p>
                    </div>
                  )
                ) : (
                  <>
                    <div className="space-y-3 mb-5">
                      <p className="text-sm font-bold text-zinc-700">Data Pembeli</p>
                      <input
                        type="text"
                        placeholder="Nama lengkap"
                        value={buyerForm.name}
                        onChange={(e) => setBuyerForm((f) => ({ ...f, name: e.target.value }))}
                        className="w-full rounded-xl border border-zinc-300 px-3 py-2.5 text-sm outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-100"
                      />
                      <input
                        type="email"
                        placeholder="Email (untuk bukti & akses produk)"
                        value={buyerForm.email}
                        onChange={(e) => setBuyerForm((f) => ({ ...f, email: e.target.value }))}
                        className="w-full rounded-xl border border-zinc-300 px-3 py-2.5 text-sm outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-100"
                      />
                      <input
                        type="tel"
                        placeholder="Nomor HP (opsional)"
                        value={buyerForm.phone}
                        onChange={(e) => setBuyerForm((f) => ({ ...f, phone: e.target.value }))}
                        className="w-full rounded-xl border border-zinc-300 px-3 py-2.5 text-sm outline-none focus:border-yellow-400 focus:ring-2 focus:ring-yellow-100"
                      />
                    </div>

                    <button
                      onClick={submitBuyerCheckout}
                      disabled={checkoutSubmitting}
                      className="w-full py-3 bg-black text-white font-bold rounded-xl hover:bg-zinc-800 transition-colors flex items-center justify-center gap-2 disabled:opacity-70"
                    >
                      {checkoutSubmitting ? <Loader2 className="w-5 h-5 animate-spin" /> : 'Lanjut ke Pembayaran'}
                    </button>
                    <p className="mt-3 text-xs text-center text-zinc-400">Pembayaran aman diproses oleh gateway resmi Hellom</p>
                  </>
                )}
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
