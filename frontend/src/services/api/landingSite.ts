// Hellom Page editor & marketing (Fase 4): shop username, pages, draft document (autosave),
// publish/versions, preview link, image upload, pixel settings, traffic stats, and the
// one-time Purchase event for the thank-you page. Import from '@/lib/hellomApi'.
import { apiRequest, publicApiRequest } from './client';

export type LandingDocBlock = {
  id: string;
  type: string;
  hidden?: boolean;
  content: Record<string, unknown>;
  styles?: Record<string, unknown>;
};

export type LandingDocument = {
  version?: number;
  theme: {
    preset?: string;
    primary?: string;
    background?: string;
    text?: string;
    buttonText?: string;
    font?: 'sans' | 'serif' | 'rounded' | 'mono';
    buttonShape?: 'rounded' | 'pill' | 'square';
    buttonStyle?: 'solid' | 'outline';
  };
  settings: { whatsappNumber?: string; whatsappMessage?: string; showFloatingWhatsapp?: boolean };
  blocks: LandingDocBlock[];
};

export type LandingSitePage = {
  id: number;
  title: string;
  slug: string;
  status: 'draft' | 'published';
  is_home: boolean;
  is_live: boolean;
  url: string;
  published_at: string | null;
  draft_saved_at: string | null;
  has_unpublished_changes: boolean;
  seo_title: string | null;
  seo_description: string | null;
  seo_image: string | null;
};

export type LandingSite = {
  username: string;
  username_is_custom: boolean;
  public_url: string;
  suspended: boolean;
  quota: { pages: number; used: number; free: number };
  pages: LandingSitePage[];
};

export type LandingDraft = { document: LandingDocument; revision: number; saved_at: string | null; page?: LandingSitePage };

export type LandingVersion = { id: number; version_no: number; published_at: string | null; blocks: number | null; is_live: boolean };

export type LandingTracking = {
  meta_pixel_id: string | null;
  meta_capi_token_set: boolean;
  meta_test_event_code: string | null;
  ga4_id: string | null;
  google_ads_id: string | null;
  google_ads_label: string | null;
  tiktok_pixel_id: string | null;
};

export type LandingTrackingInput = Partial<Omit<LandingTracking, 'meta_capi_token_set'>> & { meta_capi_token?: string; clear_meta_capi_token?: boolean };

export type LandingTrafficReport = {
  days: number;
  totals: { visits: number; product_views: number; clicks: number; checkout_starts: number };
  daily: Array<{ date: string; visits: number }>;
  sources: Array<{ source: string; visits: number }>;
  clicks: Array<{ label: string; clicks: number }>;
  products: Array<{ product_id: number; name: string; views: number; checkout_starts: number; orders: number; revenue: number; conversion: number | null }>;
  sales_by_source: Array<{ source: string; orders: number; revenue: number }>;
};

const BASE = '/apps/landing-builder';

export function getLandingSite() {
  return apiRequest<LandingSite>(`${BASE}/site`);
}

export function updateLandingUsername(username: string) {
  return apiRequest<LandingSite>(`${BASE}/site/username`, { method: 'PUT', body: { username } });
}

export function createLandingSitePage(body: { title: string; slug?: string; document?: Partial<LandingDocument> }) {
  return apiRequest<LandingSitePage>(`${BASE}/site/pages`, { method: 'POST', body });
}

export function updateLandingSitePage(id: number, body: Partial<Pick<LandingSitePage, 'title' | 'slug' | 'is_home' | 'seo_title' | 'seo_description' | 'seo_image'>>) {
  return apiRequest<LandingSitePage>(`${BASE}/site/pages/${id}`, { method: 'PATCH', body });
}

export function deleteLandingSitePage(id: number) {
  return apiRequest<{ deleted: boolean }>(`${BASE}/site/pages/${id}`, { method: 'DELETE' });
}

export function getLandingDraft(pageId: number) {
  return apiRequest<LandingDraft>(`${BASE}/site/pages/${pageId}/document`);
}

export function saveLandingDraft(pageId: number, document: LandingDocument, revision: number | null) {
  return apiRequest<LandingDraft>(`${BASE}/site/pages/${pageId}/document`, { method: 'PUT', body: { document, revision } });
}

export function publishLandingSitePage(pageId: number) {
  return apiRequest<{ version_no: number; page: LandingSitePage }>(`${BASE}/site/pages/${pageId}/publish`, { method: 'POST' });
}

export function unpublishLandingSitePage(pageId: number) {
  return apiRequest<{ page: LandingSitePage }>(`${BASE}/site/pages/${pageId}/unpublish`, { method: 'POST' });
}

export function getLandingHistory(pageId: number) {
  return apiRequest<{ items: LandingVersion[] }>(`${BASE}/site/pages/${pageId}/history`);
}

export function restoreLandingVersion(pageId: number, versionId: number) {
  return apiRequest<LandingDraft>(`${BASE}/site/pages/${pageId}/history/${versionId}/restore`, { method: 'POST' });
}

export function getLandingPreviewLink(pageId: number) {
  return apiRequest<{ url: string }>(`${BASE}/site/pages/${pageId}/preview-link`, { method: 'POST' });
}

/** Upload an image/PDF for the page (photos are stored as WebP). Returns its public URL. */
export function uploadLandingAsset(file: File) {
  const form = new FormData();
  form.append('file', file);
  return apiRequest<{ id: number; url: string; mime_type: string | null }>(`${BASE}/assets/upload`, { method: 'POST', body: form });
}

export function getLandingTracking() {
  return apiRequest<LandingTracking>(`${BASE}/tracking`);
}

export function updateLandingTracking(body: LandingTrackingInput) {
  return apiRequest<LandingTracking>(`${BASE}/tracking`, { method: 'PUT', body });
}

export function getLandingTraffic(days: 7 | 30 | 90 = 30) {
  return apiRequest<LandingTrafficReport>(`${BASE}/stats/traffic?days=${days}`);
}

export type PurchaseEvent = {
  fire: boolean;
  event_id?: string;
  value?: number;
  currency?: string;
  content_ids?: string[];
  content_name?: string;
  tracking?: Record<string, string>;
};

/** Thank-you page: the Purchase pixel event, handed out once per paid order. */
export function claimPurchaseEvent(reference: string) {
  return publicApiRequest<PurchaseEvent>(`/public/landingpage/orders/${encodeURIComponent(reference)}/purchase-event`, { method: 'POST' });
}
