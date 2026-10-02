// Public site content (showcase, insights, landing content) and its admin CRUD.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest, publicApiRequest } from './client';

// ─── Showcase ───

// showcase_portfolios row (ShowcasePortfolio model).
export type ShowcasePortfolio = {
  id: number;
  title: string;
  slug: string | null;
  description: string | null;
  full_description: string | null;
  video_url: string | null;
  thumbnail_url: string | null;
  gallery_images: string[] | null;
  client_name: string | null;
  project_year: string | null;
  project_url: string | null;
  category: string | null;
  tech_stack: string[] | null;
  sort_order: number;
  is_published: boolean;
  is_featured: boolean;
  created_at?: string | null;
  updated_at?: string | null;
};

// showcase_clients row (ShowcaseClient model).
export type ShowcaseClient = {
  id: number;
  name: string;
  logo_url: string;
  website_url: string | null;
  sort_order: number;
  is_published: boolean;
  created_at?: string | null;
  updated_at?: string | null;
};
export type LandingContent = Record<string, unknown>;

export function getPublicShowcasePortfolios() {
  return publicApiRequest<{ items: Record<string, unknown>[] }>('/public/showcase/portfolios')
    .then((payload) => payload.items || []);
}

export function getPublicShowcaseClients() {
  return publicApiRequest<{ items: Record<string, unknown>[] }>('/public/showcase/clients')
    .then((payload) => payload.items || []);
}

export function getPublicLandingContent() {
  return publicApiRequest<LandingContent>('/public/landing-content');
}

export function getPublicInsights(params?: { page?: number; per_page?: number; q?: string; category?: string }) {
  const qs = new URLSearchParams();
  if (params?.page) qs.set('page', String(params.page));
  if (params?.per_page) qs.set('per_page', String(params.per_page));
  if (params?.q) qs.set('q', params.q);
  if (params?.category) qs.set('category', params.category);
  const suffix = qs.toString() ? `?${qs.toString()}` : '';
  return publicApiRequest<Record<string, unknown>>(`/public/insights${suffix}`);
}

export function getPublicInsightBySlug(slug: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/insights/${encodeURIComponent(slug)}`);
}

export function getAdminLandingContent() {
  return apiRequest<LandingContent>('/admin/landing-content');
}

export function updateAdminLandingAbout(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/landing-content/about', {
    method: 'PUT',
    body: payload,
  });
}

export function createAdminLandingService(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/landing-content/services', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminLandingService(serviceId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/landing-content/services/${serviceId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminLandingService(serviceId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/landing-content/services/${serviceId}`, {
    method: 'DELETE',
  });
}

export function createAdminLandingArticle(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/landing-content/articles', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminLandingArticle(articleId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/landing-content/articles/${articleId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminLandingArticle(articleId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/landing-content/articles/${articleId}`, {
    method: 'DELETE',
  });
}

export function aiAssistArticle(payload: {
  mode: 'draft' | 'improve' | 'seo' | 'excerpt' | 'ideas';
  title?: string;
  content?: string;
  keywords?: string;
  tone?: string;
  category?: string;
}) {
  return apiRequest<{ result?: string; fields?: Record<string, string> }>('/admin/landing-content/articles/ai-assist', {
    method: 'POST',
    body: payload,
  });
}

export function getAdminPortfolios() {
  return apiRequest<{ items: ShowcasePortfolio[] }>('/admin/showcase/portfolios');
}

export function createAdminPortfolio(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/showcase/portfolios', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminPortfolio(portfolioId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/showcase/portfolios/${portfolioId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminPortfolio(portfolioId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/showcase/portfolios/${portfolioId}`, {
    method: 'DELETE',
  });
}

export function getAdminClients() {
  return apiRequest<{ items: ShowcaseClient[] }>('/admin/showcase/clients');
}

export function createAdminClient(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/showcase/clients', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminClient(clientId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/showcase/clients/${clientId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminClient(clientId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/showcase/clients/${clientId}`, {
    method: 'DELETE',
  });
}

/** Same as the server limit (and Nginx client_max_body_size 20m). */
export const SHOWCASE_MEDIA_MAX_BYTES = 20 * 1024 * 1024;

export function uploadShowcaseMedia(payload: FormData | File) {
  const file = payload instanceof File ? payload : payload.get('file');
  if (file instanceof File && file.size > SHOWCASE_MEDIA_MAX_BYTES) {
    // Checked here: a bigger body is refused by Nginx with an HTML 413 before Laravel answers.
    return Promise.reject(new Error(`Ukuran file maksimal 20 MB (file ini ${(file.size / 1024 / 1024).toFixed(1)} MB).`));
  }
  const body = payload instanceof File
    ? (() => {
        const formData = new FormData();
        formData.append('file', payload);
        return formData;
      })()
    : payload;

  return apiRequest<{ url: string; path: string; mime_type: string | null; size_bytes: number; original_name: string }>('/admin/showcase/upload-media', {
    method: 'POST',
    body,
  });
}
