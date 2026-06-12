import { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { 
  ShoppingCart, CheckCircle, XCircle, Loader2, 
  ArrowRight, Star, Menu, X, FileText, Upload,
  Facebook, Instagram, Music2, AtSign, MessageCircle
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { checkoutConfirmMock, checkoutIntentMock, getPublicLandingByDomain, getPublicLandingPage, getPublicLandingPageByOrganization, getToken, submitLandingCustomer } from '@/lib/hellomApi';
import { THEMES } from '@/pages/apps/landing-builder/constants';

// --- Types (Should be shared, but defining here for speed) ---
interface Block {
  id: string;
  type: 'hero' | 'features' | 'content' | 'cta' | 'gallery' | 'testimonials' | 'product' | 'banner' | 'video' | 'text' | 'image' | 'pdf' | 'social' | 'form';
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

const RenderProduct = ({ content, styles, onBuy }: { content: any, styles?: any, onBuy: (product: any) => void }) => (
  (() => {
    const gatewayConfigMissing = content.paymentType === 'gateway' && (!content.appSlug || !content.planSlug);
    return (
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
          onClick={() => onBuy(content)}
          disabled={gatewayConfigMissing}
          className="w-full py-4 bg-black text-white font-bold rounded-xl hover:bg-zinc-800 transition-all flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-black"
          style={{ backgroundColor: styles?.buttonColor, color: styles?.buttonTextColor }}
        >
          {gatewayConfigMissing
            ? 'Checkout Belum Tersedia'
            : content.paymentType === 'whatsapp'
              ? 'Buy via WhatsApp'
              : 'Buy Now'} <ArrowRight className="w-5 h-5" />
        </button>
        {gatewayConfigMissing && (
          <p className="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Checkout gateway belum dikonfigurasi untuk produk ini.
          </p>
        )}
        <p className="text-xs text-center text-zinc-400 mt-4">Secure payment powered by Hellom</p>
      </div>
    </div>
  </section>
    );
  })()
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

const RenderPdf = ({ content, styles, onBuy }: { content: any, styles?: any, onBuy: (product: any) => void }) => (
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
          onClick={() => onBuy({
            ...content,
            name: content.title || 'Katalog Produk',
            description: content.description,
            paymentType: content.paymentType || 'gateway',
            buttonText: content.paidButtonText || 'Beli Katalog',
            isPdf: true,
          })}
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
  const [paymentStatus, setPaymentStatus] = useState<'idle' | 'processing' | 'success'>('idle');
  const [paymentError, setPaymentError] = useState<string | null>(null);

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
          setLoadError('URL public tidak lengkap. Gunakan /p/landingpage/{organizationSlug}, /p/{organizationSlug}/{pageSlug}, atau /p/domain/{domain}.');
          setBlocks([]);
          setLoading(false);
          return;
        }

        const landingPayload = response as {
          blocks?: Array<Record<string, any>>;
          page?: { id?: number; content?: Record<string, unknown> | null };
          seo?: { title?: string };
        };

        const mappedBlocks: Block[] = (landingPayload.blocks || []).map((block) => ({
          id: String(block.id),
        type: (['hero', 'features', 'content', 'cta', 'gallery', 'testimonials', 'product', 'banner', 'video', 'text', 'image', 'pdf', 'social', 'form'].includes(block.block_type)
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
    if (product.paymentType === 'whatsapp') {
      const phone = String(product.whatsappNumber || pageSettings.whatsappNumber || '').replace(/\D/g, '');
      const text = product.whatsappMessage || `Halo, saya tertarik dengan ${product.name} (${formatPrice(product.price)}).`;
      if (phone) {
        window.open(`https://wa.me/${phone}?text=${encodeURIComponent(text)}`, '_blank');
      } else {
        setPaymentError('Nomor WhatsApp organisasi belum diatur.');
      }
    } else {
      // Gateway Logic
      setSelectedProduct(product);
      setPaymentError(null);
      setPaymentStatus('idle');
      setShowCheckout(true);
    }
  };

  const processPayment = async () => {
    const token = getToken();
    if (!token) {
      setPaymentError('Untuk checkout online, silakan login dulu ke dashboard Hellom.');
      return;
    }

    const appSlug = String(selectedProduct?.appSlug || selectedProduct?.app_slug || '').trim();
    const planSlug = String(selectedProduct?.planSlug || selectedProduct?.plan_slug || '').trim();

    if (!appSlug || !planSlug) {
      setPaymentError('Produk ini belum terhubung ke app/plan checkout. Tambahkan appSlug dan planSlug di blok produk.');
      return;
    }

    setPaymentStatus('processing');
    setPaymentError(null);

    try {
      const intent = await checkoutIntentMock({
        app_slug: appSlug,
        plan_slug: planSlug,
      });

      const checkoutIntent = intent as { checkout_intent?: { intent_token?: string } };
      await checkoutConfirmMock({
        intent_token: checkoutIntent.checkout_intent?.intent_token,
      });

      setPaymentStatus('success');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Checkout gagal diproses.';
      setPaymentError(message);
      setPaymentStatus('idle');
    }
  };

  const loginPath = window.location.pathname.startsWith('/hellom') ? '/hellom/login' : '/login';

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
          case 'product': return <RenderProduct key={block.id} content={block.content} styles={mergedStyles} onBuy={handleBuy} />;
          case 'content': return <RenderContent key={block.id} content={block.content} styles={mergedStyles} />;
          case 'cta': return <RenderCTA key={block.id} content={block.content} styles={mergedStyles} pageSettings={pageSettings} />;
          case 'banner': return <RenderBanner key={block.id} content={block.content} styles={mergedStyles} />;
          case 'video': return <RenderVideo key={block.id} content={block.content} styles={mergedStyles} />;
          case 'text': return <RenderText key={block.id} content={block.content} styles={mergedStyles} />;
          case 'image': return <RenderImage key={block.id} content={block.content} styles={mergedStyles} />;
          case 'pdf': return <RenderPdf key={block.id} content={block.content} styles={mergedStyles} onBuy={handleBuy} />;
          case 'social': return <RenderSocial key={block.id} content={block.content} styles={mergedStyles} />;
          case 'form': return <RenderForm key={block.id} block={block} pageId={pageId} styles={mergedStyles} />;
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
              {paymentStatus === 'success' ? (
                <div className="p-8 text-center">
                  <div className="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4">
                    <CheckCircle className="w-8 h-8" />
                  </div>
                  <h3 className="text-2xl font-bold text-zinc-900 mb-2">Payment Successful!</h3>
                  <p className="text-zinc-500 mb-6">Thank you for your purchase. Checkout sudah diproses ke backend Hellom.</p>
                  <button 
                    onClick={() => { setShowCheckout(false); setPaymentStatus('idle'); setPaymentError(null); }}
                    className="w-full py-3 bg-black text-white font-bold rounded-xl"
                  >
                    Close
                  </button>
                </div>
              ) : (
                <div className="p-6">
                  <div className="flex justify-between items-center mb-6">
                    <h3 className="text-xl font-bold text-zinc-900">Checkout</h3>
                    <button onClick={() => setShowCheckout(false)}><X className="w-5 h-5 text-zinc-400" /></button>
                  </div>
                  
                  <div className="mb-6 p-4 bg-zinc-50 rounded-xl border border-zinc-100">
                    <p className="text-sm text-zinc-500 mb-1">Product</p>
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

                  <div className="space-y-3 mb-6">
                    <p className="text-sm font-bold text-zinc-700">Select Payment Method</p>
                    <div className="p-3 border border-yellow-400 bg-yellow-50 rounded-lg flex items-center gap-3 cursor-pointer">
                      <div className="w-4 h-4 rounded-full bg-yellow-400 border border-yellow-500" />
                      <span className="font-medium text-zinc-900">QRIS (Instant)</span>
                    </div>
                    <div className="p-3 border border-zinc-200 rounded-lg flex items-center gap-3 opacity-50 cursor-not-allowed">
                      <div className="w-4 h-4 rounded-full border border-zinc-300" />
                      <span className="font-medium text-zinc-500">Virtual Account</span>
                    </div>
                  </div>

                  {!getToken() && (
                    <Link
                      to={loginPath}
                      className="mb-4 inline-flex w-full items-center justify-center rounded-xl border border-zinc-200 py-3 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                    >
                      Login untuk Checkout
                    </Link>
                  )}

                  <button 
                    onClick={processPayment}
                    disabled={paymentStatus === 'processing'}
                    className="w-full py-3 bg-black text-white font-bold rounded-xl hover:bg-zinc-800 transition-colors flex items-center justify-center gap-2 disabled:opacity-70"
                  >
                    {paymentStatus === 'processing' ? <Loader2 className="w-5 h-5 animate-spin" /> : 'Pay Now'}
                  </button>
                </div>
              )}
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
