// Public site pages: each is its own chunk. The same import thunks are used for
// React.lazy and for prefetching, so after the first page is idle every other
// page is already downloaded and the curtain never waits on the network.
import { lazy } from 'react';

const loaders = {
  beranda: () => import('./BerandaPage'),
  tentang: () => import('./TentangPage'),
  layanan: () => import('./LayananPage'),
  aplikasi: () => import('./AplikasiPage'),
  aplikasiDetail: () => import('./AplikasiDetailPage'),
  produk: () => import('./ProdukPage'),
  portofolio: () => import('./PortofolioPage'),
  wawasan: () => import('./WawasanPage'),
  wawasanDetail: () => import('./WawasanDetailPage'),
  kontak: () => import('./KontakPage'),
};

export const BerandaPage = lazy(loaders.beranda);
export const TentangPage = lazy(loaders.tentang);
export const LayananPage = lazy(loaders.layanan);
export const AplikasiPage = lazy(loaders.aplikasi);
export const AplikasiDetailPage = lazy(loaders.aplikasiDetail);
export const ProdukPage = lazy(loaders.produk);
export const PortofolioPage = lazy(loaders.portofolio);
export const WawasanPage = lazy(loaders.wawasan);
export const WawasanDetailPage = lazy(loaders.wawasanDetail);
export const KontakPage = lazy(loaders.kontak);

let prefetched = false;

/** Download every public page chunk once the browser is idle. */
export function prefetchSitePages(): void {
  if (prefetched || typeof window === 'undefined') return;
  prefetched = true;
  const run = () => Object.values(loaders).forEach((load) => void load().catch(() => undefined));
  const idle = (window as Window & { requestIdleCallback?: (cb: () => void) => number }).requestIdleCallback;
  if (idle) idle(run);
  else window.setTimeout(run, 1500);
}
