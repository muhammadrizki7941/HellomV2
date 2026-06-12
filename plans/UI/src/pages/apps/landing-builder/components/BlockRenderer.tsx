import React from 'react';
import { 
  FileText, Upload, Link as LinkIcon, 
  Facebook, Instagram, Music2, AtSign, ShoppingBag, MessageCircle
} from 'lucide-react';
import { Block, BlockStyles } from '../types';

// Helper to merge block styles with theme defaults
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
    padding: blockStyles?.paddingY || 'py-16'
  };
};

const HeroBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8 border-b border-white/10`} style={styles.container}>
    <h1 className="text-4xl md:text-5xl font-bold mb-6">{content.title}</h1>
    <p className="text-xl opacity-80 mb-8 max-w-2xl mx-auto" style={{ marginLeft: styles.container.textAlign === 'left' ? 0 : 'auto', marginRight: styles.container.textAlign === 'right' ? 0 : 'auto' }}>{content.subtitle}</p>
    {content.showButton && (
      <button 
        className="px-8 py-3 font-bold rounded-lg hover:opacity-90 transition-opacity"
        style={styles.button}
      >
        {content.buttonText}
      </button>
    )}
  </div>
);

const FeaturesBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8 border-b border-white/10`} style={styles.container}>
    <h2 className="text-3xl font-bold mb-12">{content.title}</h2>
    <div className="grid md:grid-cols-3 gap-8 max-w-6xl mx-auto text-left">
      {content.items.map((item: any, idx: number) => (
        <div key={idx} className="p-6 rounded-xl border border-black/5 shadow-sm bg-white/50 backdrop-blur-sm">
          <h3 className="text-xl font-bold mb-3">{item.title}</h3>
          <p className="opacity-80">{item.desc}</p>
        </div>
      ))}
    </div>
  </div>
);

const CtaBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8`} style={styles.container}>
    <h2 className="text-3xl md:text-4xl font-bold mb-6">{content.title}</h2>
    <p className="text-xl opacity-80 mb-10 max-w-2xl mx-auto" style={{ marginLeft: styles.container.textAlign === 'left' ? 0 : 'auto', marginRight: styles.container.textAlign === 'right' ? 0 : 'auto' }}>{content.subtitle}</p>
    <a
      href={content.actionType === 'whatsapp' && content.whatsappNumber
        ? `https://wa.me/${String(content.whatsappNumber).replace(/\D/g, '')}?text=${encodeURIComponent(content.whatsappMessage || '')}`
        : content.linkUrl || '#'}
      target="_blank"
      rel="noopener noreferrer"
      className="inline-flex items-center justify-center gap-2 px-8 py-3 font-bold rounded-lg hover:opacity-90 transition-opacity"
      style={styles.button}
    >
      {content.actionType === 'whatsapp' && <MessageCircle className="w-4 h-4" />}
      {content.buttonText}
    </a>
  </div>
);

const ContentBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8 border-b border-white/10`} style={styles.container}>
    <div className="max-w-3xl mx-auto">
      <h2 className="text-3xl font-bold mb-6">{content.title}</h2>
      <div className="prose max-w-none whitespace-pre-wrap" style={{ color: 'inherit', textAlign: 'inherit' }}>
        <p className="opacity-90">{content.body}</p>
      </div>
    </div>
  </div>
);

const BannerBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div 
    className={`relative ${styles.padding} px-8 bg-cover bg-center`}
    style={{ backgroundImage: `url(${content.imageUrl})`, textAlign: styles.container.textAlign }}
  >
    <div 
      className="absolute inset-0 bg-black" 
      style={{ opacity: content.overlayOpacity }}
    ></div>
    <div className="relative z-10 max-w-4xl mx-auto" style={{ color: content.textColor, marginLeft: styles.container.textAlign === 'left' ? 0 : 'auto', marginRight: styles.container.textAlign === 'right' ? 0 : 'auto' }}>
      <h2 className="text-4xl font-bold mb-4">{content.title}</h2>
      <p className="text-xl opacity-90">{content.subtitle}</p>
    </div>
  </div>
);

const ProductBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8 border-b border-white/10`} style={{ ...styles.container, textAlign: 'left' }}>
    <div className="max-w-4xl mx-auto flex flex-col md:flex-row gap-8 items-center">
      <div className="w-full md:w-1/2">
        <img src={content.imageUrl} alt={content.name} className="w-full rounded-xl shadow-sm border border-black/5" />
      </div>
      <div className="w-full md:w-1/2 text-left">
        <h3 className="text-2xl font-bold mb-2">{content.name}</h3>
        <p className="text-xl font-bold mb-4" style={styles.accent}>{content.price}</p>
        <p className="opacity-80 mb-6">{content.description}</p>
        <a 
          href={content.productUrl} 
          target="_blank" 
          rel="noopener noreferrer"
          className="inline-flex items-center justify-center px-6 py-3 font-bold rounded-lg hover:opacity-90 transition-opacity"
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
  <div className={`${styles.padding} px-8`} style={styles.container}>
    <div className="max-w-4xl mx-auto">
      <h3 className="text-2xl font-bold mb-8">{content.title}</h3>
      <div className="aspect-video w-full bg-black rounded-xl overflow-hidden shadow-2xl">
        <iframe 
          width="100%" 
          height="100%" 
          src={content.videoUrl} 
          title={content.title}
          frameBorder="0" 
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
          allowFullScreen
        ></iframe>
      </div>
    </div>
  </div>
);

const TextBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8`} style={styles.container}>
    <div className="max-w-3xl mx-auto leading-relaxed whitespace-pre-wrap opacity-90">
      {content.body}
    </div>
  </div>
);

const ImageBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8`} style={styles.container}>
    <div className="max-w-4xl mx-auto">
      <img src={content.imageUrl} alt="Content" className="w-full rounded-xl shadow-sm" />
      {content.caption && (
        <p className="mt-4 text-sm opacity-60 italic">{content.caption}</p>
      )}
    </div>
  </div>
);

const PdfBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8 border-y border-white/10`} style={styles.container}>
    <div className="max-w-2xl mx-auto bg-white/50 backdrop-blur-sm p-6 rounded-xl border border-black/5 shadow-sm flex items-center gap-6 text-left">
      <div className="w-16 h-16 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: styles.accent.color + '20', color: styles.accent.color }}>
        <FileText className="w-8 h-8" />
      </div>
      <div className="flex-1">
        <h4 className="text-lg font-bold">{content.title}</h4>
        <p className="opacity-80 text-sm mb-1">{content.description}</p>
        <p className="text-xs opacity-50">{content.fileName}</p>
      </div>
      <a
        href={content.accessType === 'paid' ? '#' : content.fileUrl}
        download={content.accessType === 'paid' ? undefined : true}
        className="px-4 py-2 bg-black/5 font-medium rounded-lg hover:bg-black/10 transition-colors flex items-center gap-2"
        style={{ color: 'inherit' }}
      >
        <Upload className="w-4 h-4 rotate-180" /> {content.accessType === 'paid' ? (content.paidButtonText || 'Beli Katalog') : (content.buttonText || 'Download')}
      </a>
    </div>
  </div>
);

const FormBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8 border-b border-white/10`} style={styles.container}>
    <div className="max-w-2xl mx-auto text-left bg-white/70 backdrop-blur-sm border border-black/5 rounded-2xl p-6 shadow-sm">
      <h2 className="text-2xl font-bold mb-2" style={{ color: styles.container.color }}>{content.title}</h2>
      <p className="text-sm opacity-70 mb-6" style={{ color: styles.container.color }}>{content.subtitle}</p>
      <div className="grid gap-4">
        {(content.fields || []).map((field: any) => (
          <label key={field.id} className="grid gap-1 text-sm font-medium" style={{ color: styles.container.color }}>
            <span>{field.label}{field.required ? ' *' : ''}</span>
            {field.type === 'textarea' ? (
              <textarea disabled rows={3} className="rounded-lg border border-black/10 bg-white px-3 py-2" />
            ) : (
              <input disabled type={field.type || 'text'} className="rounded-lg border border-black/10 bg-white px-3 py-2" />
            )}
          </label>
        ))}
        <button className="mt-2 px-6 py-3 font-bold rounded-lg" style={styles.button}>{content.buttonText || 'Kirim'}</button>
      </div>
    </div>
  </div>
);

const SocialBlock = ({ content, styles }: { content: any, styles: any }) => (
  <div className={`${styles.padding} px-8`} style={styles.container}>
    <h3 className="text-xl font-bold mb-8 opacity-80">Ikuti Kami di Sosial Media</h3>
    <div className={`flex flex-wrap gap-6 ${styles.container.textAlign === 'left' ? 'justify-start' : styles.container.textAlign === 'right' ? 'justify-end' : 'justify-center'}`}>
      {content.facebook && (
        <a href={content.facebook} target="_blank" rel="noopener noreferrer" className="p-4 bg-white rounded-full shadow-sm hover:scale-110 transition-transform text-blue-600">
          <Facebook className="w-8 h-8" />
        </a>
      )}
      {content.instagram && (
        <a href={content.instagram} target="_blank" rel="noopener noreferrer" className="p-4 bg-white rounded-full shadow-sm hover:scale-110 transition-transform text-pink-600">
          <Instagram className="w-8 h-8" />
        </a>
      )}
      {content.tiktok && (
        <a href={content.tiktok} target="_blank" rel="noopener noreferrer" className="p-4 bg-white rounded-full shadow-sm hover:scale-110 transition-transform text-black">
          <Music2 className="w-8 h-8" />
        </a>
      )}
      {content.threads && (
        <a href={content.threads} target="_blank" rel="noopener noreferrer" className="p-4 bg-white rounded-full shadow-sm hover:scale-110 transition-transform text-black">
          <AtSign className="w-8 h-8" />
        </a>
      )}
      {!content.facebook && !content.instagram && !content.tiktok && !content.threads && (
        <p className="text-sm opacity-50 italic w-full">Belum ada link sosial media yang ditambahkan.</p>
      )}
    </div>
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
    default: return null;
  }
};
