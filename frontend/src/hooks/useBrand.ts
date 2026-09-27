import { useEffect, useState } from 'react';
import { HELLOM_API_BASE } from '@/lib/hellomApi';

export interface BrandSettings {
  app_name: string;
  business_name: string;
  tagline: string | null;
  logo_url: string | null;
  logo_base64: string | null;
  logo_dark_url: string | null;
  favicon_url: string | null;
  primary_color: string;
  secondary_color: string;
  accent_color: string;
  background_color: string;
  login_bg_image: string | null;
  login_title: string;
  login_subtitle: string;
  register_title: string;
  register_subtitle: string;
  footer_text: string;
  support_email: string | null;
  support_phone: string | null;
  social_instagram: string | null;
  social_facebook: string | null;
  social_tiktok: string | null;
  meta_title: string;
  meta_description: string | null;
}

export const DEFAULT_BRAND: BrandSettings = {
  app_name: 'Hellom',
  business_name: 'Hellom',
  tagline: 'Solusi kasir modern untuk UMKM',
  logo_url: null,
  logo_base64: null,
  logo_dark_url: null,
  favicon_url: null,
  primary_color: '#0c0c0c',
  secondary_color: '#334155',
  accent_color: '#facc15',
  background_color: '#111111',
  login_bg_image: null,
  login_title: 'Selamat datang lagi',
  login_subtitle: 'Masuk ke akun kamu dan lanjutkan kerja hari ini.',
  register_title: 'Bikin akun baru',
  register_subtitle: 'Gabung dan mulai kelola bisnis kamu bareng Hellom.',
  footer_text: '© 2026 Hellom. All rights reserved.',
  support_email: null,
  support_phone: null,
  social_instagram: null,
  social_facebook: null,
  social_tiktok: null,
  meta_title: 'Hellom',
  meta_description: 'Platform Hellom untuk landing page, login, register, dan pengelolaan bisnis.',
};

let brandCache: BrandSettings | null = null;
let inflightRequest: Promise<BrandSettings> | null = null;

function normalizeBrand(input?: Partial<BrandSettings> | null): BrandSettings {
  return {
    ...DEFAULT_BRAND,
    ...(input ?? {}),
    app_name: input?.app_name || input?.business_name || DEFAULT_BRAND.app_name,
    business_name: input?.business_name || input?.app_name || DEFAULT_BRAND.business_name,
  };
}

function hexToRgb(hex: string): { r: number; g: number; b: number } | null {
  const normalized = hex.replace('#', '').trim();
  if (!/^[0-9a-fA-F]{6}$/.test(normalized)) {
    return null;
  }

  return {
    r: parseInt(normalized.slice(0, 2), 16),
    g: parseInt(normalized.slice(2, 4), 16),
    b: parseInt(normalized.slice(4, 6), 16),
  };
}

function rgba(hex: string, alpha: number, fallback: string): string {
  const rgb = hexToRgb(hex);
  if (!rgb) {
    return fallback;
  }

  return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
}

function readableTextColor(background: string): string {
  const rgb = hexToRgb(background);
  if (!rgb) {
    return '#fffdf5';
  }

  const brightness = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
  return brightness > 160 ? '#111111' : '#fffdf5';
}

function applyBrandVariables(brand: BrandSettings) {
  if (typeof document === 'undefined') {
    return;
  }

  const root = document.documentElement;
  const text = readableTextColor(brand.background_color);
  const muted = text === '#111111' ? 'rgba(17, 17, 17, 0.72)' : 'rgba(255, 253, 245, 0.78)';

  root.style.setProperty('--brand-primary', brand.primary_color);
  root.style.setProperty('--brand-secondary', brand.secondary_color);
  root.style.setProperty('--brand-accent', brand.accent_color);
  root.style.setProperty('--brand-bg', brand.background_color);
  root.style.setProperty('--brand-bg2', rgba(brand.secondary_color, 0.24, '#1f1b16'));
  root.style.setProperty('--brand-bg3', rgba(brand.primary_color, 0.38, '#2a241d'));
  root.style.setProperty('--brand-text', text);
  root.style.setProperty('--brand-muted', muted);

  root.style.setProperty('--color-brand-bg', brand.background_color);
  root.style.setProperty('--color-brand-bg2', rgba(brand.secondary_color, 0.24, '#1f1b16'));
  root.style.setProperty('--color-brand-bg3', rgba(brand.primary_color, 0.38, '#2a241d'));
  root.style.setProperty('--color-brand-accent', brand.accent_color);
  root.style.setProperty('--color-brand-text', text);
  root.style.setProperty('--color-brand-muted', muted);
}

function applyFavicon(brand: BrandSettings) {
  if (typeof document === 'undefined' || !brand.favicon_url) {
    return;
  }

  const faviconUrl = brand.favicon_url;
  const extension = faviconUrl.split('?')[0].split('#')[0].split('.').pop()?.toLowerCase();
  const mimeType = extension === 'svg'
    ? 'image/svg+xml'
    : extension === 'png'
      ? 'image/png'
      : extension === 'jpg' || extension === 'jpeg'
        ? 'image/jpeg'
        : extension === 'ico'
          ? 'image/x-icon'
          : undefined;

  const ensureLink = (rel: string) => {
    let link = document.querySelector<HTMLLinkElement>(`link[rel="${rel}"]`);
    if (!link) {
      link = document.createElement('link');
      link.rel = rel;
      document.head.appendChild(link);
    }
    link.href = faviconUrl;
    if (mimeType) {
      link.type = mimeType;
    }
  };

  ensureLink('icon');
  ensureLink('shortcut icon');
  ensureLink('apple-touch-icon');
}

export async function fetchBrand(force = false): Promise<BrandSettings> {
  if (!force && brandCache) {
    return brandCache;
  }

  if (!force && inflightRequest) {
    return inflightRequest;
  }

  inflightRequest = fetch(`${HELLOM_API_BASE}/public/brand`, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
    },
  })
    .then(async (response) => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload = await response.json();
      if (!payload?.success) {
        throw new Error(payload?.message || 'Gagal mengambil brand');
      }

      const brand = normalizeBrand(payload?.data?.brand);
      brandCache = brand;
      applyBrandVariables(brand);
      applyFavicon(brand);
      return brand;
    })
    .catch((error) => {
      // If we have a cached brand, use it instead of fallback
      // Only return fallback if there's no cache at all
      if (brandCache) {
        console.warn('Brand fetch failed, using cached version:', error);
        return brandCache;
      }
      
      console.warn('Brand fetch failed and no cache available:', error);
      const fallback = normalizeBrand();
      brandCache = fallback;
      applyBrandVariables(fallback);
      return fallback;
    })
    .finally(() => {
      inflightRequest = null;
    });

  return inflightRequest;
}

export function resetBrandCache() {
  brandCache = null;
  inflightRequest = null;
}

export function getCachedBrand(): BrandSettings | null {
  return brandCache;
}

export default function useBrand() {
  const [brand, setBrand] = useState<BrandSettings>(brandCache || DEFAULT_BRAND);
  const [isLoading, setIsLoading] = useState<boolean>(!brandCache);

  useEffect(() => {
    let cancelled = false;

    fetchBrand()
      .then((nextBrand) => {
        if (!cancelled) {
          setBrand(nextBrand);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    applyBrandVariables(brand);
    applyFavicon(brand);
  }, [brand]);

  return {
    brand,
    isLoading,
    logoSrc: brand.logo_base64 || brand.logo_url,
  };
}
