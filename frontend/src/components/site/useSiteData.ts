// Public site content (clients, products, portfolio, landing content), fetched once
// per page load and shared by every public page so navigation does not refetch.
import { useEffect, useState } from 'react';
import {
  getPublicLandingContent,
  getPublicProducts,
  getPublicShowcaseClients,
  getPublicShowcasePortfolios,
} from '@/lib/hellomApi';

export type SiteProduct = {
  id: number;
  slug: string;
  name: string;
  tagline?: string | null;
  category?: string | null;
  type?: string | null;
  price?: number | string | null;
  thumbnail_url?: string | null;
};

export type SitePortfolio = {
  id: number;
  title: string;
  slug?: string | null;
  category?: string | null;
  thumbnail_url?: string | null;
  description?: string | null;
  full_description?: string | null;
  video_url?: string | null;
  client_name?: string | null;
  project_year?: string | null;
  project_url?: string | null;
};

export type SiteClient = { id: number; name: string; logo_url?: string | null; website_url?: string | null };

export type SiteService = {
  id: number;
  title: string;
  slug?: string | null;
  icon?: string | null;
  short_description?: string | null;
};

export type SiteArticle = {
  id: number;
  title: string;
  slug?: string | null;
  thumbnail?: string | null;
  excerpt?: string | null;
  category?: string | null;
  published_at?: string | null;
  read_time?: number | null;
};

export type SiteAbout = {
  title?: string | null;
  subtitle?: string | null;
  description?: string | null;
  years_experience?: number | null;
  projects_completed?: number | null;
  happy_clients?: number | null;
  support_label?: string | null;
};

export type SiteContent = {
  about?: SiteAbout | null;
  products?: { label?: string | null; heading?: string | null; description?: string | null; cta_label?: string | null } | null;
  services?: SiteService[];
  articles?: SiteArticle[];
};

export type SiteData = {
  clients: SiteClient[];
  products: SiteProduct[];
  portfolios: SitePortfolio[];
  content: SiteContent;
  loaded: boolean;
};

// Shown until the API answers (and if a list is empty on a fresh install).
export const FALLBACK_CLIENTS: SiteClient[] = [
  { id: 1, name: 'Maco Studio' },
  { id: 2, name: 'BRAND.ID' },
  { id: 3, name: 'Sadewa Coffee' },
  { id: 4, name: 'Trackon' },
  { id: 5, name: 'bukanstudio' },
  { id: 6, name: 'pixelgrain' },
];

export const FALLBACK_SERVICES: SiteService[] = [
  { id: 1, title: 'Branding & Identitas', icon: 'PenTool', short_description: 'Membangun identitas brand yang kuat, estetik, dan mudah diingat.' },
  { id: 2, title: 'Desain Web & UI', icon: 'Layers3', short_description: 'Website dan antarmuka yang modern, responsif, dan nyaman dipakai.' },
  { id: 3, title: 'Produk Digital', icon: 'Cpu', short_description: 'Template, tools, dan produk digital siap pakai untuk bisnismu.' },
  { id: 4, title: 'Otomasi & Sistem', icon: 'Zap', short_description: 'Sistem kerja otomatis supaya bisnis lebih efisien dan siap tumbuh.' },
  { id: 5, title: 'Konsultasi & Strategi', icon: 'BriefcaseBusiness', short_description: 'Strategi brand, digital, dan produk yang pas untuk pertumbuhan.' },
];

export const FALLBACK_PORTFOLIOS: SitePortfolio[] = [
  { id: 1, title: 'Maco Studio', category: 'Branding & Web Design', description: 'Identitas studio kreatif dengan sistem visual premium.' },
  { id: 2, title: 'Sadewa Coffee & Eatery', category: 'Branding & Website', description: 'Website dan pengalaman digital untuk brand kuliner.' },
  { id: 3, title: 'Trackon Dashboard', category: 'System', description: 'Dashboard operasional dengan antarmuka yang tajam.' },
];

export const FALLBACK_ARTICLES: SiteArticle[] = [
  { id: 1, title: 'Cara membangun brand yang autentik dan berkesan', published_at: '2026-05-20', read_time: 5 },
  { id: 2, title: 'Sistem kerja kreatif yang bikin lebih produktif', published_at: '2026-05-15', read_time: 6 },
  { id: 3, title: 'Kenapa desain yang baik bisa meningkatkan penjualan', published_at: '2026-05-10', read_time: 7 },
];

let cache: SiteData | null = null;
let inflight: Promise<SiteData> | null = null;

function load(): Promise<SiteData> {
  if (cache) return Promise.resolve(cache);
  if (inflight) return inflight;

  inflight = Promise.allSettled([
    getPublicShowcaseClients(),
    getPublicProducts(),
    getPublicShowcasePortfolios(),
    getPublicLandingContent(),
  ]).then(([clients, products, portfolios, content]) => {
    cache = {
      clients: clients.status === 'fulfilled' && clients.value.length ? (clients.value as SiteClient[]) : FALLBACK_CLIENTS,
      products: products.status === 'fulfilled' ? (products.value as SiteProduct[]) : [],
      portfolios: portfolios.status === 'fulfilled' && portfolios.value.length ? (portfolios.value as SitePortfolio[]) : FALLBACK_PORTFOLIOS,
      content: content.status === 'fulfilled' ? (content.value as SiteContent) : {},
      loaded: true,
    };
    inflight = null;
    return cache;
  });

  return inflight;
}

export default function useSiteData(): SiteData {
  const [data, setData] = useState<SiteData>(
    () => cache ?? { clients: FALLBACK_CLIENTS, products: [], portfolios: FALLBACK_PORTFOLIOS, content: {}, loaded: false }
  );

  useEffect(() => {
    let active = true;
    void load().then((result) => {
      if (active) setData(result);
    });
    return () => {
      active = false;
    };
  }, []);

  return data;
}

export const formatProductPrice = (product: SiteProduct) => {
  const price = Number(product.price || 0);
  if (product.type === 'free' || price <= 0) return 'Gratis';
  return `Rp ${price.toLocaleString('id-ID')}`;
};

export const formatArticleDate = (date?: string | null) => {
  if (!date) return '';
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(date));
};
