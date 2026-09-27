// Consumer notifications, digital product purchases, onboarding.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { HELLOM_API_BASE, apiRequest, getToken } from './client';

// ─── Consumer Notifications ───

export function getConsumerNotifications() {
  return apiRequest<Record<string, unknown>>('/consumer/notifications');
}

export function getConsumerNotificationsUnreadCount() {
  return apiRequest<Record<string, unknown>>('/consumer/notifications/unread-count');
}

export function markConsumerNotificationAsRead(id: number) {
  return apiRequest<Record<string, unknown>>(`/consumer/notifications/${id}/read`, {
    method: 'POST',
    body: {},
  });
}

export function markAllConsumerNotificationsAsRead() {
  return apiRequest<Record<string, unknown>>('/consumer/notifications/read-all', {
    method: 'POST',
    body: {},
  });
}

// ─── Consumer Products ───

export function getConsumerProducts() {
  return apiRequest<Record<string, unknown>[]>('/consumer/products');
}

export function getConsumerProductBySlug(slug: string) {
  return apiRequest<Record<string, unknown>>(`/consumer/products/${encodeURIComponent(slug)}`);
}

export function purchaseProduct(id: string | number, payload: Record<string, unknown> = {}) {
  return apiRequest<Record<string, unknown>>(`/consumer/products/${id}/purchase`, {
    method: 'POST',
    body: payload,
  });
}

export function getProductPurchaseStatus(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/consumer/products/${id}/purchase/status`);
}

export function cancelProductPurchase(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/consumer/products/${id}/purchase/cancel`, {
    method: 'POST',
    body: {},
  });
}

export async function downloadProductFile(id: string | number, fileId: string | number): Promise<void> {
  const token = getToken();
  const response = await fetch(`${HELLOM_API_BASE}/consumer/products/${id}/download/${fileId}`, {
    method: 'POST',
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!response.ok) {
    let message = `HTTP ${response.status}`;
    try {
      const data = await response.json();
      message = (data as { message?: string })?.message || message;
    } catch {
      // response was not JSON (e.g. the binary file itself) — keep status message
    }
    throw new Error(message);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);

  // Derive filename from Content-Disposition, fall back to a generic name.
  const disposition = response.headers.get('Content-Disposition') || '';
  const match = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
  const filename = match ? decodeURIComponent(match[1]) : `product-${id}-file-${fileId}`;

  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

export function getMyPurchases() {
  return apiRequest<Record<string, unknown>[]>('/consumer/my-purchases');
}

// ─── Onboarding ───

export function getOnboardingTips() {
  return apiRequest<Record<string, unknown>>('/consumer/onboarding/tips');
}

export function dismissOnboarding() {
  return apiRequest<Record<string, unknown>>('/consumer/onboarding/dismiss', {
    method: 'POST',
    body: {},
  });
}
