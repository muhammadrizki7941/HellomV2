// Social media panel (Fase 4): platforms, Hellom's own line glyphs and the same link rules as the
// server (App\Support\Landing\SocialLinks — the server rebuilds every link; this file only gives
// instant feedback while typing). Keep both in sync.

export type SocialPlatform =
  | 'instagram' | 'tiktok' | 'youtube' | 'facebook' | 'x' | 'threads' | 'whatsapp' | 'telegram' | 'linkedin'
  | 'pinterest' | 'shopee' | 'tokopedia' | 'spotify' | 'discord' | 'snapchat' | 'twitch' | 'email' | 'website';

export interface PlatformInfo {
  key: SocialPlatform;
  label: string;
  color: string;
  /** What to type, shown as placeholder. */
  placeholder: string;
  inputMode?: 'text' | 'tel' | 'email' | 'url';
}

export const SOCIAL_PLATFORMS: PlatformInfo[] = [
  { key: 'instagram', label: 'Instagram', color: '#e1306c', placeholder: '@username atau link profil' },
  { key: 'tiktok', label: 'TikTok', color: '#000000', placeholder: '@username atau link profil' },
  { key: 'youtube', label: 'YouTube', color: '#ff0000', placeholder: '@channel atau link channel' },
  { key: 'facebook', label: 'Facebook', color: '#1877f2', placeholder: 'username atau link halaman' },
  { key: 'x', label: 'X', color: '#000000', placeholder: '@username' },
  { key: 'threads', label: 'Threads', color: '#000000', placeholder: '@username' },
  { key: 'whatsapp', label: 'WhatsApp', color: '#25d366', placeholder: '08123456789', inputMode: 'tel' },
  { key: 'telegram', label: 'Telegram', color: '#229ed9', placeholder: '@username atau t.me/…' },
  { key: 'linkedin', label: 'LinkedIn', color: '#0a66c2', placeholder: 'link profil / halaman perusahaan', inputMode: 'url' },
  { key: 'pinterest', label: 'Pinterest', color: '#e60023', placeholder: 'username' },
  { key: 'shopee', label: 'Shopee', color: '#ee4d2d', placeholder: 'nama toko atau link toko' },
  { key: 'tokopedia', label: 'Tokopedia', color: '#03ac0e', placeholder: 'nama toko atau link toko' },
  { key: 'spotify', label: 'Spotify', color: '#1db954', placeholder: 'link artis / podcast / playlist', inputMode: 'url' },
  { key: 'discord', label: 'Discord', color: '#5865f2', placeholder: 'link undangan discord.gg/…', inputMode: 'url' },
  { key: 'snapchat', label: 'Snapchat', color: '#fffc00', placeholder: 'username' },
  { key: 'twitch', label: 'Twitch', color: '#9146ff', placeholder: 'username' },
  { key: 'email', label: 'Email', color: '#52525b', placeholder: 'nama@domain.com', inputMode: 'email' },
  { key: 'website', label: 'Website', color: '#52525b', placeholder: 'tokokamu.com', inputMode: 'url' },
];

export const PLATFORM: Record<SocialPlatform, PlatformInfo> = Object.fromEntries(SOCIAL_PLATFORMS.map((p) => [p.key, p])) as Record<SocialPlatform, PlatformInfo>;

/** Same glyphs as the server (SocialLinks::ICONS), inside <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">. */
export const SOCIAL_ICONS: Record<SocialPlatform, string> = {
  instagram: '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>',
  tiktok: '<path d="M14 3v11.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 3c.6 2.6 2.4 4.4 5 4.8"/>',
  youtube: '<rect x="2" y="5" width="20" height="14" rx="4"/><path d="M10 9l5 3-5 3z" fill="currentColor"/>',
  facebook: '<path d="M15 3h-2a4 4 0 0 0-4 4v3H7v4h2v7h4v-7h2.5l.5-4h-3V7.5a.5.5 0 0 1 .5-.5H15z"/>',
  x: '<path d="M4 4l16 16M20 4L4 20"/>',
  threads: '<circle cx="12" cy="12" r="3.5"/><path d="M15.5 12v1.3a2.6 2.6 0 0 0 5.2 0V12a8.7 8.7 0 1 0-3.4 6.9"/>',
  whatsapp: '<path d="M3.5 20.5l1.4-4.3A8.6 8.6 0 1 1 8 19.3z"/><path d="M9.2 8.8c0 3 2.9 6 6 6l1-1.1-1.7-1.2-.9.5a3.6 3.6 0 0 1-1.6-1.6l.5-.9-1.2-1.7z" fill="currentColor" stroke="none"/>',
  telegram: '<path d="M21 4L3 11l6.5 2.2L12 20l3.3-4.4L20 19z"/><path d="M9.5 13.2L21 4"/>',
  linkedin: '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10.5V17M8 7.5v.01M12 17v-6.5M12 13.5a2.5 2.5 0 0 1 5 0V17"/>',
  pinterest: '<circle cx="12" cy="12" r="9"/><path d="M10.5 20.5l2-8.2"/><path d="M9.3 13.5A3.6 3.6 0 1 1 13 15"/>',
  shopee: '<path d="M5 8h14l-1.2 12H6.2z"/><path d="M9 8a3 3 0 0 1 6 0"/><path d="M14 12.3c-.5-.6-1.2-.8-2-.8-1 0-1.8.5-1.8 1.3 0 1.8 3.8 1 3.8 2.9 0 .8-.8 1.4-2 1.4-.9 0-1.6-.3-2.1-.9"/>',
  tokopedia: '<path d="M4 9h16v11H4z"/><path d="M8 9a4 4 0 0 1 8 0"/><circle cx="9.5" cy="14" r="1.6"/><circle cx="14.5" cy="14" r="1.6"/>',
  spotify: '<circle cx="12" cy="12" r="9"/><path d="M7.5 9.5c3-1 6.5-.7 9 .8M8 12.6c2.5-.7 5.2-.4 7.2.8M8.6 15.5c2-.5 4-.3 5.6.6"/>',
  discord: '<path d="M6.5 7c3.6-1.6 7.4-1.6 11 0l2 9c-1.8 1.8-3.6 2.4-4.8 2.6l-1-2.1h-3.4l-1 2.1c-1.2-.2-3-.8-4.8-2.6z"/><circle cx="9.5" cy="12.5" r="1" fill="currentColor"/><circle cx="14.5" cy="12.5" r="1" fill="currentColor"/>',
  snapchat: '<path d="M12 3.5c2.8 0 4.7 2 4.7 4.8v2l1.8.9-1.8.9c.4 1.8 1.8 2.8 2.8 3.2-1.4.5-2.8.5-3.7 1.4-.9.8-2.2 1-3.8 1s-2.9-.2-3.8-1c-.9-.9-2.3-.9-3.7-1.4 1-.4 2.4-1.4 2.8-3.2l-1.8-.9 1.8-.9v-2c0-2.8 1.9-4.8 4.7-4.8z"/>',
  twitch: '<path d="M4 3h16v11l-5 5h-4l-3 3v-3H4z"/><path d="M11 7.5v4M15.5 7.5v4"/>',
  email: '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 6.5l8.5 6.5 8.5-6.5"/>',
  website: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.7 5.6 3.7 9s-1.2 6.4-3.7 9c-2.5-2.6-3.7-5.6-3.7-9S9.5 5.6 12 3z"/>',
};

const HOST_PREFIX = '^(?:https?://)?(?:(?:www|m|mobile|web|id)\\.)?(?:';

/** Path of a pasted link on the platform's own hosts ("instagram.com/toko/" → "toko"), else null. */
function pathOf(value: string, hosts: string): string | null {
  const m = new RegExp(`${HOST_PREFIX}${hosts})(?:/([^?#]*))?`, 'i').exec(value);
  return m ? (m[1] ?? '').replace(/^\/+|\/+$/g, '') : null;
}
const firstSegment = (path: string | null, fallback: string) => (path !== null ? path.split('/')[0] : fallback);
const handle = (pattern: string, from: string) => new RegExp(`^@?(${pattern})$`).exec(from)?.[1] ?? null;

/** Profile URL for what the seller typed, or null (same rules as SocialLinks::url). */
export function socialUrl(platform: SocialPlatform, raw: string): string | null {
  let v = raw.trim();
  if (platform === 'whatsapp') v = v.replace(/[\s().-]/g, '');
  if (!v || v.length > 300 || /[\s<>"'`]/.test(v)) return null;
  switch (platform) {
    case 'instagram': {
      const h = handle('[A-Za-z0-9._]{1,30}', firstSegment(pathOf(v, 'instagram\\.com'), v));
      return h && !['p', 'reel', 'explore', 'accounts'].includes(h.toLowerCase()) ? `https://www.instagram.com/${h}` : null;
    }
    case 'tiktok': {
      const h = handle('[A-Za-z0-9._]{2,24}', firstSegment(pathOf(v, 'tiktok\\.com'), v));
      return h ? `https://www.tiktok.com/@${h}` : null;
    }
    case 'youtube': {
      const p = pathOf(v, 'youtube\\.com');
      if (p !== null) return /^(@[A-Za-z0-9._-]{3,30}|channel\/UC[A-Za-z0-9_-]{22}|c\/[A-Za-z0-9._-]{1,100}|user\/[A-Za-z0-9._-]{1,100})$/.test(p) ? `https://www.youtube.com/${p}` : null;
      const h = handle('[A-Za-z0-9._-]{3,30}', v);
      return h ? `https://www.youtube.com/@${h}` : null;
    }
    case 'facebook': {
      let p = pathOf(v, 'facebook\\.com|fb\\.com');
      if (p !== null) {
        const id = /[?&]id=(\d{5,20})/.exec(v);
        if (p === 'profile.php' && id) return `https://www.facebook.com/profile.php?id=${id[1]}`;
        p = p.split('/')[0];
      }
      const h = handle('[A-Za-z0-9.]{5,50}', p ?? v);
      return h ? `https://www.facebook.com/${h}` : null;
    }
    case 'x': {
      const h = handle('[A-Za-z0-9_]{1,15}', firstSegment(pathOf(v, 'x\\.com|twitter\\.com'), v));
      return h ? `https://x.com/${h}` : null;
    }
    case 'threads': {
      const h = handle('[A-Za-z0-9._]{1,30}', firstSegment(pathOf(v, 'threads\\.net|threads\\.com'), v));
      return h ? `https://www.threads.net/@${h}` : null;
    }
    case 'whatsapp': {
      let digits = (pathOf(v, 'wa\\.me') ?? v).replace(/\D/g, '');
      if (digits.startsWith('0')) digits = `62${digits.slice(1)}`;
      return /^[1-9]\d{8,14}$/.test(digits) ? `https://wa.me/${digits}` : null;
    }
    case 'telegram': {
      const h = handle('[A-Za-z][A-Za-z0-9_]{4,31}', firstSegment(pathOf(v, 't\\.me|telegram\\.me'), v));
      return h ? `https://t.me/${h}` : null;
    }
    case 'linkedin': {
      const p = pathOf(v, 'linkedin\\.com');
      if (p !== null) {
        const m = /^(in|company|school)\/([A-Za-z0-9-]{2,100})/.exec(p);
        return m ? `https://www.linkedin.com/${m[1]}/${m[2]}` : null;
      }
      const h = handle('[A-Za-z0-9-]{3,100}', v);
      return h ? `https://www.linkedin.com/in/${h}` : null;
    }
    case 'pinterest': {
      const h = handle('[A-Za-z0-9_]{3,30}', firstSegment(pathOf(v, 'pinterest\\.com|id\\.pinterest\\.com|pin\\.it'), v));
      return h ? `https://www.pinterest.com/${h}` : null;
    }
    case 'shopee': {
      const h = handle('[A-Za-z0-9._]{3,40}', firstSegment(pathOf(v, 'shopee\\.co\\.id'), v));
      return h ? `https://shopee.co.id/${h}` : null;
    }
    case 'tokopedia': {
      const h = handle('[A-Za-z0-9-]{3,40}', firstSegment(pathOf(v, 'tokopedia\\.com'), v));
      return h ? `https://www.tokopedia.com/${h}` : null;
    }
    case 'spotify': {
      const m = /^(?:intl-[a-z]{2}(?:-[a-z]{2})?\/)?(artist|user|show|playlist)\/([A-Za-z0-9]{10,40})/i.exec(pathOf(v, 'open\\.spotify\\.com') ?? '');
      return m ? `https://open.spotify.com/${m[1].toLowerCase()}/${m[2]}` : null;
    }
    case 'discord': {
      const code = handle('[A-Za-z0-9-]{2,32}', firstSegment(pathOf(v, 'discord\\.gg|discord\\.com/invite'), v));
      return code ? `https://discord.gg/${code}` : null;
    }
    case 'snapchat': {
      const p = pathOf(v, 'snapchat\\.com');
      const h = handle('[A-Za-z][A-Za-z0-9._-]{2,14}', p !== null ? p.replace(/^add\//, '') : v);
      return h ? `https://www.snapchat.com/add/${h}` : null;
    }
    case 'twitch': {
      const h = handle('[A-Za-z0-9_]{4,25}', firstSegment(pathOf(v, 'twitch\\.tv'), v));
      return h ? `https://www.twitch.tv/${h}` : null;
    }
    case 'email': {
      const email = v.replace(/^mailto:/i, '');
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? `mailto:${email.toLowerCase()}` : null;
    }
    case 'website': {
      const url = /^https?:\/\//i.test(v) ? v : `https://${v}`;
      // Host as typed (new URL() would turn "123" into the IP 0.0.0.123); same check as the server.
      const host = /^https?:\/\/([^/:?#]+)/i.exec(url)?.[1] ?? '';
      try {
        new URL(url);
      } catch {
        return null;
      }
      return host.includes('.') && /^[a-z0-9.-]+$/i.test(host) ? url : null;
    }
  }
}
