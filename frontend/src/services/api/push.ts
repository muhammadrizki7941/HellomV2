// Web push subscriptions (PushSubscriptionController).
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest } from './client';

export function getPushPublicKey() {
  return apiRequest<{ enabled: boolean; public_key: string | null }>('/push/public-key');
}

export function savePushSubscription(subscription: PushSubscriptionJSON) {
  return apiRequest<{ subscribed: boolean }>('/push/subscriptions', {
    method: 'POST',
    body: { endpoint: subscription.endpoint, keys: subscription.keys },
  });
}

export function deletePushSubscription(endpoint: string) {
  return apiRequest<{ subscribed: boolean }>('/push/subscriptions', {
    method: 'DELETE',
    body: { endpoint },
  });
}

/** Endpoint of this browser's push subscription, if any (sent with logout). */
export async function currentPushEndpoint(): Promise<string | null> {
  try {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return null;
    const reg = await navigator.serviceWorker.getRegistration('/');
    const subscription = await reg?.pushManager?.getSubscription();
    return subscription?.endpoint ?? null;
  } catch {
    return null;
  }
}
