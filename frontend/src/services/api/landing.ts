// Public landing pages and the Landing Builder app.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest, publicApiRequest } from './client';

// ─── Public Landing ───

export function getPublicLandingPage(organizationSlug: string, pageSlug: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/landing/${encodeURIComponent(organizationSlug)}/${encodeURIComponent(pageSlug)}`);
}

export function getPublicLandingPageByOrganization(organizationSlug: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/landingpage/${encodeURIComponent(organizationSlug)}`);
}

export function getPublicLandingByDomain(domain: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/landing/domain/${encodeURIComponent(domain)}`);
}

export function submitLandingCustomer(landingPageId: string | number, payload: Record<string, unknown>) {
  return publicApiRequest<Record<string, unknown>>(`/public/landing/${encodeURIComponent(String(landingPageId))}/customers`, {
    method: 'POST',
    body: payload,
  });
}

/** Public buyer checkout for a landing-page product/PDF — returns { payment_url }. */
export function createLandingOrder(organizationSlug: string, payload: {
  block_id: string | number;
  buyer_name: string;
  buyer_email: string;
  buyer_phone?: string;
}) {
  return publicApiRequest<Record<string, unknown>>(`/public/landingpage/${encodeURIComponent(organizationSlug)}/orders`, {
    method: 'POST',
    body: payload,
  });
}

export function getLandingOrderDownload(token: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/landingpage/orders/${encodeURIComponent(token)}/download`);
}

export function getLandingOrderStatus(reference: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/landingpage/orders/${encodeURIComponent(reference)}/status`);
}

export function getLandingPageCustomers(pageId?: string | number) {
  const qs = pageId ? `?page_id=${encodeURIComponent(String(pageId))}` : '';
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/customers${qs}`);
}

export function getPublicBanners(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return publicApiRequest<{ items: Record<string, unknown>[] }>(`/public/banners${qs ? `?${qs}` : ''}`)
    .then((payload) => payload.items || []);
}

// ─── Landing Builder ───

export function getLandingPages() {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/pages');
}

export function createLandingPage(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/pages', {
    method: 'POST',
    body: payload,
  });
}

export function updateLandingPage(pageId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function publishLandingPage(pageId: number) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}/publish`, {
    method: 'POST',
    body: {},
  });
}

export function getLandingPageBlocks(pageId: number) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}/blocks`);
}

export function createLandingPageBlock(pageId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}/blocks`, {
    method: 'POST',
    body: payload,
  });
}

export function deleteLandingPageBlock(pageId: number, blockId: number) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}/blocks/${blockId}`, {
    method: 'DELETE',
  });
}

export function getLandingBuilderStats() {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/stats');
}

export function getLandingBuilderPageStats() {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/stats/pages');
}

export function getLandingBuilderPerformance() {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/stats/performance');
}
