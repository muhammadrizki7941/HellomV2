// Flagship SaaS apps for /aplikasi: products the super admin marked "Aplikasi unggulan"
// (name, copy, banners), merged with the built-in apps in data/apps.ts (benefits, try
// flow, UI preview). Built-in apps always appear, even before a product row exists.
import { useEffect, useState } from 'react';
import { Sparkles, type LucideIcon } from 'lucide-react';
import { getImageUrl, getPublicFlagshipApps } from '@/lib/hellomApi';
import type { PublicFlagshipApp } from '@/lib/hellomApi';
import { HELLOM_APPS, type HellomApp } from '@/data/apps';
import { formatProductPrice } from './useSiteData';

export type ShowcaseApp = {
  /** Route key: /aplikasi/{key}. Built-in slug ("pos") or the product slug. */
  key: string;
  name: string;
  summary: string;
  description: string | null;
  icon: LucideIcon;
  status: HellomApp['status'];
  benefits: string[];
  pricing?: HellomApp['pricing'];
  preview?: HellomApp['preview'];
  screenshots?: HellomApp['screenshots'];
  banner: string | null;
  bannerMobile: string | null;
  tryHref: { guest: string; member: string };
  tryLabel: string;
};

function fromBuiltIn(app: HellomApp, product?: PublicFlagshipApp): ShowcaseApp {
  return {
    key: app.slug,
    name: product?.name || app.name,
    summary: product?.tagline || app.summary,
    description: product?.description || null,
    icon: app.icon,
    status: app.status,
    benefits: app.benefits,
    pricing: app.pricing,
    preview: app.preview,
    screenshots: app.screenshots,
    banner: product?.banner_url ? getImageUrl(product.banner_url) : null,
    bannerMobile: product?.banner_mobile_url ? getImageUrl(product.banner_mobile_url) : null,
    tryHref: app.tryHref,
    tryLabel: 'Coba Sekarang',
  };
}

// A flagship product without a built-in app: bought like any digital product.
function fromProduct(product: PublicFlagshipApp): ShowcaseApp {
  const free = product.type === 'free' || Number(product.price || 0) <= 0;
  const dashboard = `/dashboard/products/${product.slug}/checkout`;
  return {
    key: product.slug,
    name: product.name,
    summary: product.tagline || '',
    description: product.description,
    icon: Sparkles,
    status: 'available',
    benefits: product.tags ?? [],
    pricing: { label: formatProductPrice(product), note: free ? 'Cukup daftar akun Hellom untuk memakainya.' : 'Sekali beli, akses selamanya.' },
    banner: product.banner_url ? getImageUrl(product.banner_url) : (product.thumbnail_url ? getImageUrl(product.thumbnail_url) : null),
    bannerMobile: product.banner_mobile_url ? getImageUrl(product.banner_mobile_url) : null,
    tryHref: { guest: free ? '/login' : `/produk/${product.slug}/checkout`, member: dashboard },
    tryLabel: free ? 'Pakai Gratis' : 'Beli Sekarang',
  };
}

export function buildShowcase(products: PublicFlagshipApp[]): ShowcaseApp[] {
  const used = new Set<string>();
  const list = products.map((product) => {
    const builtIn = HELLOM_APPS.find((app) => app.slug === product.flagship_app && !used.has(app.slug));
    if (builtIn) {
      used.add(builtIn.slug);
      return fromBuiltIn(builtIn, product);
    }
    return fromProduct(product);
  });

  return [...list, ...HELLOM_APPS.filter((app) => !used.has(app.slug)).map((app) => fromBuiltIn(app))];
}

let cache: ShowcaseApp[] | null = null;
let inflight: Promise<ShowcaseApp[]> | null = null;

function load(): Promise<ShowcaseApp[]> {
  if (cache) return Promise.resolve(cache);
  inflight ??= getPublicFlagshipApps()
    .catch(() => [] as PublicFlagshipApp[])
    .then((products) => {
      cache = buildShowcase(products);
      inflight = null;
      return cache;
    });
  return inflight;
}

export default function useFlagshipApps(): { apps: ShowcaseApp[]; loaded: boolean } {
  const [state, setState] = useState(() => ({ apps: cache ?? buildShowcase([]), loaded: cache !== null }));

  useEffect(() => {
    let active = true;
    void load().then((apps) => {
      if (active) setState({ apps, loaded: true });
    });
    return () => {
      active = false;
    };
  }, []);

  return state;
}
