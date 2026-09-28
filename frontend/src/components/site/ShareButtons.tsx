import { useEffect, useRef, useState } from 'react';
import { Check, Facebook, Link2, Linkedin, Send, Share2 } from 'lucide-react';

// Share a public page to WhatsApp and social networks. `compact` shows one "Bagikan"
// button that opens a small menu (for cards); otherwise the targets are listed inline.
// On phones the system share sheet (navigator.share) is offered first.

type Props = {
  /** Absolute URL or a path on this site ("/aplikasi/pos"). */
  url: string;
  title: string;
  text?: string;
  compact?: boolean;
  className?: string;
};

function WhatsAppIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" aria-hidden className={className} fill="currentColor">
      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z" />
    </svg>
  );
}

function XIcon({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" aria-hidden className={className} fill="currentColor">
      <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
    </svg>
  );
}

export function absoluteUrl(url: string): string {
  if (/^https?:\/\//i.test(url)) return url;
  if (typeof window === 'undefined') return url;
  return `${window.location.origin}${url.startsWith('/') ? '' : '/'}${url}`;
}

export default function ShareButtons({ url, title, text, compact = false, className = '' }: Props) {
  const [open, setOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const href = absoluteUrl(url);
  const message = text ? `${title} — ${text}` : title;
  const canNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

  useEffect(() => {
    if (!open) return undefined;
    const close = (event: MouseEvent | KeyboardEvent) => {
      if (event instanceof KeyboardEvent ? event.key === 'Escape' : !rootRef.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', close);
    return () => {
      document.removeEventListener('mousedown', close);
      document.removeEventListener('keydown', close);
    };
  }, [open]);

  const encodedUrl = encodeURIComponent(href);
  const targets = [
    { key: 'whatsapp', label: 'WhatsApp', icon: WhatsAppIcon, color: 'hover:bg-[#25D366] hover:text-black', href: `https://wa.me/?text=${encodeURIComponent(`${message}\n${href}`)}` },
    { key: 'facebook', label: 'Facebook', icon: Facebook, color: 'hover:bg-[#1877F2] hover:text-white', href: `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}` },
    { key: 'x', label: 'X', icon: XIcon, color: 'hover:bg-white hover:text-black', href: `https://twitter.com/intent/tweet?text=${encodeURIComponent(message)}&url=${encodedUrl}` },
    { key: 'telegram', label: 'Telegram', icon: Send, color: 'hover:bg-[#229ED9] hover:text-white', href: `https://t.me/share/url?url=${encodedUrl}&text=${encodeURIComponent(message)}` },
    { key: 'linkedin', label: 'LinkedIn', icon: Linkedin, color: 'hover:bg-[#0A66C2] hover:text-white', href: `https://www.linkedin.com/sharing/share-offsite/?url=${encodedUrl}` },
  ];

  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(href);
    } catch {
      window.prompt('Salin link ini:', href);
    }
    setCopied(true);
    window.setTimeout(() => setCopied(false), 2000);
  };

  const nativeShare = async () => {
    try {
      await navigator.share({ title, text: text || title, url: href });
      setOpen(false);
    } catch {
      // Cancelled or unsupported: the menu stays open.
    }
  };

  const list = (
    <ul className={compact ? 'space-y-1' : 'flex flex-wrap gap-2'}>
      {targets.map(({ key, label, icon: Icon, color, href: target }) => (
        <li key={key}>
          <a
            href={target}
            target="_blank"
            rel="noopener noreferrer"
            onClick={() => setOpen(false)}
            aria-label={`Bagikan ke ${label}`}
            className={compact
              ? `flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm text-[#F5F5F2] transition-colors ${color}`
              : `flex h-11 min-w-11 items-center justify-center gap-2 rounded-full border border-white/[0.14] px-3 text-sm text-[#F5F5F2] transition-colors ${color}`}
          >
            <Icon className="h-4 w-4" />
            <span className={compact ? '' : 'sr-only sm:not-sr-only'}>{label}</span>
          </a>
        </li>
      ))}
      <li>
        <button
          type="button"
          onClick={() => void copyLink()}
          className={compact
            ? 'flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-sm text-[#F5F5F2] transition-colors hover:bg-white/[0.08]'
            : 'flex h-11 items-center gap-2 rounded-full border border-white/[0.14] px-3 text-sm text-[#F5F5F2] transition-colors hover:border-[#F6B400]'}
        >
          {copied ? <Check className="h-4 w-4 text-emerald-300" /> : <Link2 className="h-4 w-4" />}
          <span>{copied ? 'Link disalin' : 'Salin link'}</span>
        </button>
      </li>
    </ul>
  );

  if (!compact) {
    return (
      <div className={className}>
        <p className="mb-3 text-xs font-bold uppercase tracking-[0.3em] text-[#A1A1A6]">Bagikan</p>
        <div className="flex flex-wrap items-center gap-2">
          {canNativeShare && (
            <button
              type="button"
              onClick={() => void nativeShare()}
              className="flex h-11 items-center gap-2 rounded-full bg-[#F6B400] px-4 text-sm font-bold text-black hover:bg-[#FFCC47]"
            >
              <Share2 className="h-4 w-4" /> Bagikan
            </button>
          )}
          {list}
        </div>
      </div>
    );
  }

  return (
    // Callers may position the wrapper (e.g. "absolute right-4 top-4"); the menu anchors to it either way.
    <div ref={rootRef} className={/\b(absolute|fixed|sticky)\b/.test(className) ? className : `relative ${className}`}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Bagikan ${title}`}
        className="flex h-11 w-11 items-center justify-center rounded-full border border-white/[0.14] bg-black/40 text-[#F5F5F2] backdrop-blur transition-colors hover:border-[#F6B400] hover:text-[#F6B400]"
      >
        <Share2 className="h-4 w-4" />
      </button>
      {open && (
        <div role="menu" className="absolute right-0 z-30 mt-2 w-52 rounded-xl border border-white/[0.12] bg-[#141417] p-2 shadow-2xl">
          {canNativeShare && (
            <button
              type="button"
              onClick={() => void nativeShare()}
              className="mb-1 flex min-h-11 w-full items-center gap-3 rounded-lg bg-[#F6B400] px-3 text-sm font-bold text-black hover:bg-[#FFCC47]"
            >
              <Share2 className="h-4 w-4" /> Bagikan lewat…
            </button>
          )}
          {list}
        </div>
      )}
    </div>
  );
}
