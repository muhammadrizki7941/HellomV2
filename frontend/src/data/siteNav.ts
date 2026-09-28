// Public site navigation: one source for the navbar, footer, curtain titles and
// old #hash redirects.

export type SitePage = {
  path: string;
  label: string;
  /** Old single-page anchor that now lives at `path`. */
  hash: string;
};

export const SITE_PAGES: SitePage[] = [
  { path: '/', label: 'Beranda', hash: '#top' },
  { path: '/tentang', label: 'Tentang', hash: '#about' },
  { path: '/layanan', label: 'Layanan', hash: '#services' },
  { path: '/aplikasi', label: 'Aplikasi', hash: '#apps' },
  { path: '/produk', label: 'Produk', hash: '#products' },
  { path: '/portofolio', label: 'Portofolio', hash: '#portfolio' },
  { path: '/wawasan', label: 'Wawasan', hash: '#insights' },
  { path: '/kontak', label: 'Kontak', hash: '#contact' },
];

/** Big title shown in the curtain while navigating to `pathname`. */
export function curtainTitleFor(pathname: string): string {
  if (pathname === '/') return 'Beranda';
  const match = [...SITE_PAGES]
    .filter((page) => page.path !== '/')
    .find((page) => pathname === page.path || pathname.startsWith(`${page.path}/`));
  return match?.label ?? 'Hellom';
}

export function pathForLegacyHash(hash: string): string | null {
  return SITE_PAGES.find((page) => page.hash === hash)?.path ?? null;
}
