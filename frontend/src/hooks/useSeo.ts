import { useEffect } from 'react';

export interface SeoConfig {
  title: string;
  description?: string | null;
  /** Canonical/OG url path or absolute url. Defaults to current location. */
  url?: string | null;
  image?: string | null;
  /** og:type — 'website' for listings, 'article' for posts. */
  type?: 'website' | 'article';
  keywords?: string | null;
  author?: string | null;
  publishedTime?: string | null;
  modifiedTime?: string | null;
  /** Schema.org JSON-LD object injected into a <script type="application/ld+json">. */
  jsonLd?: Record<string, unknown> | null;
  /** Set to false to allow indexing only when content is ready. Defaults true. */
  index?: boolean;
}

const SEO_ATTR = 'data-managed-seo';

function upsertMeta(selector: string, attr: 'name' | 'property', key: string, content: string) {
  let el = document.head.querySelector<HTMLMetaElement>(selector);
  if (!el) {
    el = document.createElement('meta');
    el.setAttribute(attr, key);
    el.setAttribute(SEO_ATTR, 'true');
    document.head.appendChild(el);
  }
  el.setAttribute('content', content);
}

function upsertLink(rel: string, href: string) {
  let el = document.head.querySelector<HTMLLinkElement>(`link[rel="${rel}"][${SEO_ATTR}]`);
  if (!el) {
    el = document.createElement('link');
    el.setAttribute('rel', rel);
    el.setAttribute(SEO_ATTR, 'true');
    document.head.appendChild(el);
  }
  el.setAttribute('href', href);
}

function toAbsoluteUrl(value?: string | null): string {
  if (typeof window === 'undefined') return value || '';
  if (!value) return window.location.href;
  try {
    return new URL(value, window.location.origin).href;
  } catch {
    return window.location.href;
  }
}

/**
 * Dynamically manages document <head> SEO tags for the current page.
 * Cleans up the tags it created on unmount so each route controls its own meta.
 */
export default function useSeo(config: SeoConfig) {
  const {
    title,
    description,
    url,
    image,
    type = 'website',
    keywords,
    author,
    publishedTime,
    modifiedTime,
    jsonLd,
    index = true,
  } = config;

  const jsonLdKey = jsonLd ? JSON.stringify(jsonLd) : '';

  useEffect(() => {
    const previousTitle = document.title;
    document.title = title;

    const canonical = toAbsoluteUrl(url);
    const absoluteImage = image ? toAbsoluteUrl(image) : '';

    if (description) upsertMeta('meta[name="description"][data-managed-seo]', 'name', 'description', description);
    if (keywords) upsertMeta('meta[name="keywords"][data-managed-seo]', 'name', 'keywords', keywords);
    upsertMeta('meta[name="robots"][data-managed-seo]', 'name', 'robots', index ? 'index, follow' : 'noindex, nofollow');

    // Open Graph
    upsertMeta('meta[property="og:title"][data-managed-seo]', 'property', 'og:title', title);
    if (description) upsertMeta('meta[property="og:description"][data-managed-seo]', 'property', 'og:description', description);
    upsertMeta('meta[property="og:type"][data-managed-seo]', 'property', 'og:type', type);
    upsertMeta('meta[property="og:url"][data-managed-seo]', 'property', 'og:url', canonical);
    if (absoluteImage) upsertMeta('meta[property="og:image"][data-managed-seo]', 'property', 'og:image', absoluteImage);

    // Twitter
    upsertMeta('meta[name="twitter:card"][data-managed-seo]', 'name', 'twitter:card', absoluteImage ? 'summary_large_image' : 'summary');
    upsertMeta('meta[name="twitter:title"][data-managed-seo]', 'name', 'twitter:title', title);
    if (description) upsertMeta('meta[name="twitter:description"][data-managed-seo]', 'name', 'twitter:description', description);
    if (absoluteImage) upsertMeta('meta[name="twitter:image"][data-managed-seo]', 'name', 'twitter:image', absoluteImage);

    // Article-specific
    if (type === 'article') {
      if (author) upsertMeta('meta[property="article:author"][data-managed-seo]', 'property', 'article:author', author);
      if (publishedTime) upsertMeta('meta[property="article:published_time"][data-managed-seo]', 'property', 'article:published_time', publishedTime);
      if (modifiedTime) upsertMeta('meta[property="article:modified_time"][data-managed-seo]', 'property', 'article:modified_time', modifiedTime);
    }

    upsertLink('canonical', canonical);

    if (jsonLdKey) {
      const scriptEl = document.createElement('script');
      scriptEl.type = 'application/ld+json';
      scriptEl.setAttribute(SEO_ATTR, 'true');
      scriptEl.text = jsonLdKey;
      document.head.appendChild(scriptEl);
    }

    return () => {
      document.title = previousTitle;
      document.head.querySelectorAll(`[${SEO_ATTR}]`).forEach((node) => node.remove());
    };
  }, [title, description, url, image, type, keywords, author, publishedTime, modifiedTime, index, jsonLdKey]);
}
