import React from 'react';
import {
  FileText, Upload,
  Facebook, Instagram, Music2, AtSign, ShoppingBag, MessageCircle,
  Star, Quote, Check, ChevronDown, ArrowRight
} from 'lucide-react';
import { Block, BlockStyles } from '../types';

const useBlockStyles = (blockStyles: BlockStyles | undefined, theme: any) => {
  return {
    container: {
      backgroundColor: blockStyles?.backgroundColor || theme.colors.backgroundColor,
      backgroundImage: blockStyles?.backgroundImage ? `url(${blockStyles.backgroundImage})` : undefined,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
      color: blockStyles?.textColor || theme.colors.textColor,
      textAlign: blockStyles?.textAlign || 'center',
    },
    button: {
      backgroundColor: blockStyles?.buttonColor || theme.colors.buttonColor,
      color: blockStyles?.buttonTextColor || theme.colors.buttonTextColor,
    },
    accent: {
      color: blockStyles?.buttonColor || theme.colors.accentColor,
    },
    padding: blockStyles?.paddingY || 'py-10 sm:py-16',
  };
};

const HeroBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8 border-b border-white/10`} style={styles.container}>
    <h1 className="text-2xl sm:text-4xl md:text-5xl font-bold mb-3 sm:mb-6 leading-tight">{content.title}</h1>
    <p
      className="text-sm sm:text-xl opacity-80 mb-5 sm:mb-8 max-w-2xl"
      style={{
        marginLeft: styles.container.textAlign === 'left' ? 0 : 'auto',
        marginRight: styles.container.textAlign === 'right' ? 0 : 'auto',
      }}
    >
      {content.subtitle}
    </p>
    {content.showButton && (
      <button
        className="px-5 py-2.5 sm:px-8 sm:py-3 text-sm sm:text-base font-bold rounded-lg hover:opacity-90 transition-opacity"
        style={styles.button}
      >
        {content.buttonText}
      </button>
    )}
  </div>
);

const FeaturesBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8 border-b border-white/10`} style={styles.container}>
    <h2 className="text-xl sm:text-3xl font-bold mb-6 sm:mb-10">{content.title}</h2>
    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 sm:gap-6 max-w-6xl mx-auto text-left">
      {content.items.map((item: any, idx: number) => (
        <div key={idx} className="p-4 sm:p-6 rounded-xl border border-black/5 shadow-sm bg-white/50 backdrop-blur-sm">
          <h3 className="text-base sm:text-xl font-bold mb-2">{item.title}</h3>
          <p className="text-sm sm:text-base opacity-80">{item.desc}</p>
        </div>
      ))}
    </div>
  </div>
);

const CtaBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
    <h2 className="text-xl sm:text-3xl md:text-4xl font-bold mb-3 sm:mb-6 leading-tight">{content.title}</h2>
    <p
      className="text-sm sm:text-xl opacity-80 mb-6 sm:mb-10 max-w-2xl"
      style={{
        marginLeft: styles.container.textAlign === 'left' ? 0 : 'auto',
        marginRight: styles.container.textAlign === 'right' ? 0 : 'auto',
      }}
    >
      {content.subtitle}
    </p>
    <a
      href={
        content.actionType === 'whatsapp' && content.whatsappNumber
          ? `https://wa.me/${String(content.whatsappNumber).replace(/\D/g, '')}?text=${encodeURIComponent(content.whatsappMessage || '')}`
          : content.linkUrl || '#'
      }
      target="_blank"
      rel="noopener noreferrer"
      className="inline-flex items-center justify-center gap-2 px-5 py-2.5 sm:px-8 sm:py-3 text-sm sm:text-base font-bold rounded-lg hover:opacity-90 transition-opacity"
      style={styles.button}
    >
      {content.actionType === 'whatsapp' && <MessageCircle className="w-4 h-4" />}
      {content.buttonText}
    </a>
  </div>
);

const ContentBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8 border-b border-white/10`} style={styles.container}>
    <div className="max-w-3xl mx-auto">
      <h2 className="text-xl sm:text-3xl font-bold mb-4 sm:mb-6">{content.title}</h2>
      <div className="prose max-w-none whitespace-pre-wrap text-sm sm:text-base" style={{ color: 'inherit', textAlign: 'inherit' }}>
        <p className="opacity-90">{content.body}</p>
      </div>
    </div>
  </div>
);

const BannerBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div
    className={`relative ${styles.padding} px-4 sm:px-8 bg-cover bg-center`}
    style={{ backgroundImage: `url(${content.imageUrl})`, textAlign: styles.container.textAlign }}
  >
    <div className="absolute inset-0 bg-black" style={{ opacity: content.overlayOpacity }} />
    <div
      className="relative z-10 max-w-4xl mx-auto"
      style={{
        color: content.textColor,
        marginLeft: styles.container.textAlign === 'left' ? 0 : 'auto',
        marginRight: styles.container.textAlign === 'right' ? 0 : 'auto',
      }}
    >
      <h2 className="text-xl sm:text-4xl font-bold mb-2 sm:mb-4 leading-tight">{content.title}</h2>
      <p className="text-sm sm:text-xl opacity-90">{content.subtitle}</p>
    </div>
  </div>
);

const ProductBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8 border-b border-white/10`} style={{ ...styles.container, textAlign: 'left' }}>
    <div className="max-w-4xl mx-auto flex flex-col md:flex-row gap-5 sm:gap-8 items-center">
      <div className="w-full md:w-1/2">
        <img src={content.imageUrl} alt={content.name} className="w-full rounded-xl shadow-sm border border-black/5" />
      </div>
      <div className="w-full md:w-1/2 text-left">
        <h3 className="text-lg sm:text-2xl font-bold mb-1 sm:mb-2">{content.name}</h3>
        <p className="text-base sm:text-xl font-bold mb-2 sm:mb-4" style={styles.accent}>{content.price}</p>
        <p className="text-sm sm:text-base opacity-80 mb-4 sm:mb-6">{content.description}</p>
        <a
          href={content.productUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center justify-center px-5 py-2.5 sm:px-6 sm:py-3 text-sm sm:text-base font-bold rounded-lg hover:opacity-90 transition-opacity"
          style={styles.button}
        >
          <ShoppingBag className="w-4 h-4 mr-2" />
          {content.buttonText}
        </a>
      </div>
    </div>
  </div>
);

const VideoBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
    <div className="max-w-4xl mx-auto">
      <h3 className="text-lg sm:text-2xl font-bold mb-4 sm:mb-8">{content.title}</h3>
      <div className="aspect-video w-full bg-black rounded-xl overflow-hidden shadow-2xl">
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
  </div>
);

const TextBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
    <div className="max-w-3xl mx-auto leading-relaxed whitespace-pre-wrap opacity-90 text-sm sm:text-base">
      {content.body}
    </div>
  </div>
);

const ImageBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
    <div className="max-w-4xl mx-auto">
      <img src={content.imageUrl} alt="Content" className="w-full rounded-xl shadow-sm" />
      {content.caption && (
        <p className="mt-3 text-xs sm:text-sm opacity-60 italic">{content.caption}</p>
      )}
    </div>
  </div>
);

const PdfBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8 border-y border-white/10`} style={styles.container}>
    <div className="max-w-2xl mx-auto bg-white/50 backdrop-blur-sm p-4 sm:p-6 rounded-xl border border-black/5 shadow-sm flex flex-col sm:flex-row items-start sm:items-center gap-4 sm:gap-6 text-left">
      <div
        className="w-12 h-12 sm:w-16 sm:h-16 rounded-lg flex items-center justify-center flex-shrink-0"
        style={{ backgroundColor: styles.accent.color + '20', color: styles.accent.color }}
      >
        <FileText className="w-6 h-6 sm:w-8 sm:h-8" />
      </div>
      <div className="flex-1 min-w-0">
        <h4 className="text-base sm:text-lg font-bold">{content.title}</h4>
        <p className="opacity-80 text-xs sm:text-sm mb-1">{content.description}</p>
        <p className="text-xs opacity-50 truncate">{content.fileName}</p>
      </div>
      <a
        href={content.accessType === 'paid' ? '#' : content.fileUrl}
        download={content.accessType === 'paid' ? undefined : true}
        className="self-start sm:self-auto px-3 py-2 sm:px-4 bg-black/5 text-sm font-medium rounded-lg hover:bg-black/10 transition-colors flex items-center gap-2 whitespace-nowrap"
        style={{ color: 'inherit' }}
      >
        <Upload className="w-4 h-4 rotate-180" />
        {content.accessType === 'paid' ? (content.paidButtonText || 'Beli') : (content.buttonText || 'Download')}
      </a>
    </div>
  </div>
);

const FormBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8 border-b border-white/10`} style={styles.container}>
    <div className="max-w-2xl mx-auto text-left bg-white/70 backdrop-blur-sm border border-black/5 rounded-2xl p-4 sm:p-6 shadow-sm">
      <h2 className="text-lg sm:text-2xl font-bold mb-1 sm:mb-2" style={{ color: styles.container.color }}>{content.title}</h2>
      <p className="text-xs sm:text-sm opacity-70 mb-4 sm:mb-6" style={{ color: styles.container.color }}>{content.subtitle}</p>
      <div className="grid gap-3 sm:gap-4">
        {(content.fields || []).map((field: any) => (
          <label key={field.id} className="grid gap-1 text-xs sm:text-sm font-medium" style={{ color: styles.container.color }}>
            <span>{field.label}{field.required ? ' *' : ''}</span>
            {field.type === 'textarea' ? (
              <textarea disabled rows={3} className="rounded-lg border border-black/10 bg-white px-3 py-2 text-sm" />
            ) : (
              <input disabled type={field.type || 'text'} className="rounded-lg border border-black/10 bg-white px-3 py-2 text-sm" />
            )}
          </label>
        ))}
        <button className="mt-1 px-5 py-2.5 sm:px-6 sm:py-3 text-sm sm:text-base font-bold rounded-lg" style={styles.button}>
          {content.buttonText || 'Kirim'}
        </button>
      </div>
    </div>
  </div>
);

const SocialBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
    <h3 className="text-base sm:text-xl font-bold mb-5 sm:mb-8 opacity-80">Ikuti Kami di Sosial Media</h3>
    <div
      className={`flex flex-wrap gap-4 sm:gap-6 ${
        styles.container.textAlign === 'left'
          ? 'justify-start'
          : styles.container.textAlign === 'right'
          ? 'justify-end'
          : 'justify-center'
      }`}
    >
      {content.facebook && (
        <a href={content.facebook} target="_blank" rel="noopener noreferrer" className="p-3 sm:p-4 bg-white rounded-full shadow-sm hover:scale-110 transition-transform text-blue-600">
          <Facebook className="w-6 h-6 sm:w-8 sm:h-8" />
        </a>
      )}
      {content.instagram && (
        <a href={content.instagram} target="_blank" rel="noopener noreferrer" className="p-3 sm:p-4 bg-white rounded-full shadow-sm hover:scale-110 transition-transform text-pink-600">
          <Instagram className="w-6 h-6 sm:w-8 sm:h-8" />
        </a>
      )}
      {content.tiktok && (
        <a href={content.tiktok} target="_blank" rel="noopener noreferrer" className="p-3 sm:p-4 bg-white rounded-full shadow-sm hover:scale-110 transition-transform text-black">
          <Music2 className="w-6 h-6 sm:w-8 sm:h-8" />
        </a>
      )}
      {content.threads && (
        <a href={content.threads} target="_blank" rel="noopener noreferrer" className="p-3 sm:p-4 bg-white rounded-full shadow-sm hover:scale-110 transition-transform text-black">
          <AtSign className="w-6 h-6 sm:w-8 sm:h-8" />
        </a>
      )}
      {!content.facebook && !content.instagram && !content.tiktok && !content.threads && (
        <p className="text-xs sm:text-sm opacity-50 italic w-full">Belum ada link sosial media.</p>
      )}
    </div>
  </div>
);

const ButtonBlock = ({ content, styles }: { content: any, styles: any }) => {
  const align = content.align || 'center';
  const justify = align === 'left' ? 'justify-start' : align === 'right' ? 'justify-end' : 'justify-center';
  return (
    <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
      <div className={`flex ${justify}`}>
        <span
          className="inline-flex items-center gap-2 px-6 py-3 text-sm sm:text-base font-bold rounded-lg cursor-pointer hover:opacity-90 transition-opacity"
          style={styles.button}
        >
          {content.actionType === 'whatsapp' && <MessageCircle className="w-4 h-4" />}
          {content.text || 'Klik di Sini'}
          {content.actionType !== 'whatsapp' && <ArrowRight className="w-4 h-4" />}
        </span>
      </div>
    </div>
  );
};

const DividerBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={{ backgroundColor: styles.container.backgroundColor }}>
    <div className="mx-auto" style={{ width: `${content.width ?? 100}%` }}>
      <hr
        style={{
          borderTopStyle: content.style || 'solid',
          borderTopWidth: `${content.thickness ?? 1}px`,
          borderColor: styles.container.color,
          opacity: 0.35,
        }}
      />
    </div>
  </div>
);

const TestimonialsBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8 border-b border-white/10`} style={styles.container}>
    {content.title && <h2 className="text-xl sm:text-3xl font-bold mb-6 sm:mb-10">{content.title}</h2>}
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-6 max-w-6xl mx-auto text-left">
      {(content.items || []).map((item: any, idx: number) => (
        <div key={idx} className="p-4 sm:p-6 rounded-xl border border-black/5 shadow-sm bg-white/60 backdrop-blur-sm">
          <Quote className="w-6 h-6 mb-3 opacity-30" />
          <p className="text-sm sm:text-base opacity-90 mb-4">{item.text}</p>
          <div className="flex items-center gap-1 mb-2">
            {Array.from({ length: 5 }).map((_, i) => (
              <Star key={i} className={`w-4 h-4 ${i < (item.rating ?? 5) ? 'fill-yellow-400 text-yellow-400' : 'text-zinc-300'}`} />
            ))}
          </div>
          <p className="text-sm font-bold">{item.name}</p>
          {item.role && <p className="text-xs opacity-60">{item.role}</p>}
        </div>
      ))}
    </div>
  </div>
);

const FaqBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8 border-b border-white/10`} style={styles.container}>
    {content.title && <h2 className="text-xl sm:text-3xl font-bold mb-6 sm:mb-10">{content.title}</h2>}
    <div className="max-w-2xl mx-auto text-left space-y-3">
      {(content.items || []).map((item: any, idx: number) => (
        <div key={idx} className="rounded-xl border border-black/10 bg-white/60 backdrop-blur-sm overflow-hidden">
          <div className="flex items-center justify-between px-4 py-3 font-semibold text-sm sm:text-base">
            <span>{item.q}</span>
            <ChevronDown className="w-4 h-4 opacity-50" />
          </div>
          <div className="px-4 pb-3 text-sm opacity-80">{item.a}</div>
        </div>
      ))}
    </div>
  </div>
);

const ListBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
    {content.title && <h2 className="text-xl sm:text-3xl font-bold mb-6">{content.title}</h2>}
    <ul className="max-w-2xl mx-auto text-left space-y-3">
      {(content.items || []).map((item: any, idx: number) => (
        <li key={idx} className="flex items-start gap-3">
          <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full" style={{ backgroundColor: styles.accent.color + '22', color: styles.accent.color }}>
            <Check className="w-3 h-3" />
          </span>
          <span className="text-sm sm:text-base opacity-90">{item.text}</span>
        </li>
      ))}
    </ul>
  </div>
);

const SliderBlock = ({ content, styles }: { content: any, styles: any }) => {
  const images = content.images || [];
  const first = images[0];
  return (
    <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
      <div className="max-w-4xl mx-auto">
        <div className="relative aspect-video w-full overflow-hidden rounded-xl bg-black/10">
          {first?.url && <img src={first.url} alt={first.caption || 'Slide'} className="h-full w-full object-cover" />}
        </div>
        <div className="mt-3 flex justify-center gap-1.5">
          {images.map((_: any, idx: number) => (
            <span key={idx} className={`h-2 rounded-full ${idx === 0 ? 'w-5' : 'w-2'} `} style={{ backgroundColor: idx === 0 ? styles.accent.color : styles.container.color + '40' }} />
          ))}
        </div>
      </div>
    </div>
  );
};

const formatCountdownParts = (target: string) => {
  const diff = Math.max(0, new Date(target).getTime() - Date.now());
  const days = Math.floor(diff / 86400000);
  const hours = Math.floor((diff % 86400000) / 3600000);
  const minutes = Math.floor((diff % 3600000) / 60000);
  const seconds = Math.floor((diff % 60000) / 1000);
  return [
    { label: 'Hari', value: days },
    { label: 'Jam', value: hours },
    { label: 'Menit', value: minutes },
    { label: 'Detik', value: seconds },
  ];
};

const CountdownBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
    {content.title && <h2 className="text-xl sm:text-3xl font-bold mb-2">{content.title}</h2>}
    {content.subtitle && <p className="text-sm sm:text-base opacity-80 mb-6">{content.subtitle}</p>}
    <div className="flex justify-center gap-3 sm:gap-4">
      {formatCountdownParts(content.targetDate).map((part) => (
        <div key={part.label} className="flex flex-col items-center">
          <div className="flex h-14 w-14 sm:h-20 sm:w-20 items-center justify-center rounded-xl text-xl sm:text-3xl font-bold" style={styles.button}>
            {String(part.value).padStart(2, '0')}
          </div>
          <span className="mt-2 text-xs sm:text-sm opacity-70">{part.label}</span>
        </div>
      ))}
    </div>
  </div>
);

const GifBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
    <div className="max-w-3xl mx-auto">
      {content.gifUrl && <img src={content.gifUrl} alt={content.caption || 'GIF'} className="mx-auto rounded-xl shadow-sm" />}
      {content.caption && <p className="mt-3 text-xs sm:text-sm opacity-60 italic">{content.caption}</p>}
    </div>
  </div>
);

const HtmlBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-4 sm:px-8`} style={styles.container}>
    <div className="max-w-3xl mx-auto" dangerouslySetInnerHTML={{ __html: content.html || '' }} />
  </div>
);

export const BlockRenderer = ({ block, theme }: { block: Block, theme: any }) => {
  const styles = useBlockStyles(block.styles, theme);

  switch (block.type) {
    case 'hero': return <HeroBlock content={block.content} styles={styles} />;
    case 'features': return <FeaturesBlock content={block.content} styles={styles} />;
    case 'cta': return <CtaBlock content={block.content} styles={styles} />;
    case 'content': return <ContentBlock content={block.content} styles={styles} />;
    case 'banner': return <BannerBlock content={block.content} styles={styles} />;
    case 'product': return <ProductBlock content={block.content} styles={styles} />;
    case 'video': return <VideoBlock content={block.content} styles={styles} />;
    case 'text': return <TextBlock content={block.content} styles={styles} />;
    case 'image': return <ImageBlock content={block.content} styles={styles} />;
    case 'pdf': return <PdfBlock content={block.content} styles={styles} />;
    case 'social': return <SocialBlock content={block.content} styles={styles} />;
    case 'form': return <FormBlock content={block.content} styles={styles} />;
    case 'button': return <ButtonBlock content={block.content} styles={styles} />;
    case 'divider': return <DividerBlock content={block.content} styles={styles} />;
    case 'testimonials': return <TestimonialsBlock content={block.content} styles={styles} />;
    case 'faq': return <FaqBlock content={block.content} styles={styles} />;
    case 'list': return <ListBlock content={block.content} styles={styles} />;
    case 'slider': return <SliderBlock content={block.content} styles={styles} />;
    case 'countdown': return <CountdownBlock content={block.content} styles={styles} />;
    case 'gif': return <GifBlock content={block.content} styles={styles} />;
    case 'html': return <HtmlBlock content={block.content} styles={styles} />;
    default: return null;
  }
};
