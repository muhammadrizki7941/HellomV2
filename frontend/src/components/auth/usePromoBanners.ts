import { useEffect, useMemo, useState } from 'react';
import { getPublicBanners } from '@/lib/hellomApi';
import type { PromoBanner } from './PromoBanner';

const normalizeBanner = (item: Record<string, unknown>): PromoBanner => ({
  id: String(item.id ?? Math.random()),
  title: (item.title as string | undefined) || undefined,
  subtitle: (item.subtitle as string | undefined) || undefined,
  ctaText: (item.cta_text as string | undefined) || undefined,
  ctaLink: (item.link as string | undefined) || undefined,
  imageUrl: (item.image_url as string | undefined) || undefined,
  badge: (item.badge as string | undefined) || undefined,
  isActive: Boolean(item.is_active ?? true),
  expiresAt: (item.ends_at as string | undefined) || undefined,
  backgroundFrom: (item.background_from as string | undefined) || undefined,
  backgroundTo: (item.background_to as string | undefined) || undefined,
});

export const usePromoBanners = (position: 'header' | 'hero' | 'sidebar' = 'header') => {
  const [items, setItems] = useState<PromoBanner[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    const load = async () => {
      try {
        const data = await getPublicBanners({ position });
        const mapped = Array.isArray(data) ? data.map((item) => normalizeBanner(item as Record<string, unknown>)) : [];
        if (active) setItems(mapped);
      } catch {
        if (active) setItems([]);
      } finally {
        if (active) setLoading(false);
      }
    };

    void load();

    return () => {
      active = false;
    };
  }, [position]);

  return useMemo(() => ({ items, loading }), [items, loading]);
};
