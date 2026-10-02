// Public product catalog and admin digital products.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { HELLOM_API_BASE, apiRequest, getToken, publicApiRequest } from './client';

// ─── Public Products ───

export function getPublicProducts(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return publicApiRequest<Record<string, unknown>[]>(`/public/products${qs ? `?${qs}` : ''}`);
}

// Flagship SaaS apps for the /aplikasi showcase (marked "Aplikasi unggulan" by the super admin).
export type PublicFlagshipApp = {
  id: number;
  slug: string;
  name: string;
  tagline: string | null;
  description: string | null;
  category: string | null;
  type: string | null;
  price: number | string | null;
  thumbnail_url: string | null;
  banner_url: string | null;
  banner_mobile_url: string | null;
  /** Built-in app this product stands for (data/apps.ts slug), e.g. "pos". */
  flagship_app: string | null;
  tags: string[] | null;
};

export function getPublicFlagshipApps() {
  return publicApiRequest<PublicFlagshipApp[]>('/public/flagship-apps');
}

// ─── Admin Digital Products ───

export function getAdminProducts(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return apiRequest<Record<string, unknown>>(`/admin/digital-products${qs ? `?${qs}` : ''}`);
}

export function createProduct(data: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/digital-products', {
    method: 'POST',
    body: data,
  });
}

export function getAdminProductById(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}`);
}

export function updateProduct(id: string | number, data: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}`, {
    method: 'PUT',
    body: data,
  });
}

export function deleteProduct(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}`, {
    method: 'DELETE',
  });
}

export function publishProduct(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/publish`, {
    method: 'POST',
    body: {},
  });
}

export function unpublishProduct(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/unpublish`, {
    method: 'POST',
    body: {},
  });
}

export function uploadProductThumbnail(id: string | number, formData: FormData) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/thumbnail`, {
    method: 'POST',
    body: formData,
  });
}

// Flagship banner: formData with `banner` (image) and `variant` ("desktop" | "mobile").
export function uploadProductBanner(id: string | number, formData: FormData) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/banner`, {
    method: 'POST',
    body: formData,
  });
}

export function deleteProductBanner(id: string | number, variant: 'desktop' | 'mobile') {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/banner?variant=${variant}`, {
    method: 'DELETE',
  });
}

export function uploadProductFile(id: string | number, formData: FormData) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/files`, {
    method: 'POST',
    body: formData,
  });
}

export function uploadProductDoc(id: string | number, data: Record<string, unknown> | FormData) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/docs`, {
    method: 'POST',
    body: data,
  });
}

export function deleteProductFile(fileId: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/files/${fileId}`, {
    method: 'DELETE',
  });
}

export function deleteProductDoc(docId: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/docs/${docId}`, {
    method: 'DELETE',
  });
}

export async function fetchAuthorizedBlobUrl(path: string): Promise<string> {
  const token = getToken();
  const response = await fetch(`${HELLOM_API_BASE}${path}`, {
    method: 'GET',
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }

  const blob = await response.blob();
  return URL.createObjectURL(blob);
}

export function getAdminProductPurchases(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return apiRequest<Record<string, unknown>>(`/admin/product-purchases${qs ? `?${qs}` : ''}`);
}

export function approveProductPurchase(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/product-purchases/${id}/approve`, {
    method: 'POST',
    body: {},
  });
}

export function refundProductPurchase(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/product-purchases/${id}/refund`, {
    method: 'POST',
    body: {},
  });
}
