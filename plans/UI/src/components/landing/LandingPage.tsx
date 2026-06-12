import { useEffect } from 'react';
import useBrand from '@/hooks/useBrand';
import { HellomspaceLanding } from './HellomspaceLanding';

export const LandingPage = () => {
  const { brand, logoSrc } = useBrand();

  useEffect(() => {
    const pageTitle = brand.meta_title || `${brand.app_name || 'Hellom'} | Jual aplikasi, template, dan SaaS`;
    const description =
      brand.meta_description ||
      'Landing premium untuk menjual aplikasi, produk digital, template download, dan layanan berlangganan dari satu brand.';
    const currentUrl = window.location.href;

    document.title = pageTitle;

    const ensureMeta = (selector: string, factory: () => HTMLMetaElement) => {
      let meta = document.querySelector<HTMLMetaElement>(selector);
      if (!meta) {
        meta = factory();
        document.head.appendChild(meta);
      }
      return meta;
    };

    ensureMeta('meta[name="description"]', () => {
      const meta = document.createElement('meta');
      meta.name = 'description';
      return meta;
    }).content = description;

    ensureMeta('meta[property="og:title"]', () => {
      const meta = document.createElement('meta');
      meta.setAttribute('property', 'og:title');
      return meta;
    }).content = pageTitle;

    ensureMeta('meta[property="og:description"]', () => {
      const meta = document.createElement('meta');
      meta.setAttribute('property', 'og:description');
      return meta;
    }).content = description;

    ensureMeta('meta[property="og:url"]', () => {
      const meta = document.createElement('meta');
      meta.setAttribute('property', 'og:url');
      return meta;
    }).content = currentUrl;

    let canonical = document.querySelector<HTMLLinkElement>('link[rel="canonical"]');
    if (!canonical) {
      canonical = document.createElement('link');
      canonical.rel = 'canonical';
      document.head.appendChild(canonical);
    }
    canonical.href = currentUrl;
  }, [brand]);

  return <HellomspaceLanding brand={brand} logoSrc={logoSrc} />;
};

export default LandingPage;
