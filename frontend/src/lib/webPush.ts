// Web push on this device: permission is asked only when the user taps "Aktifkan".
// iPhone/iPad allow push only for a web app added to the home screen (iOS 16.4+).
import { deletePushSubscription, getPushPublicKey, savePushSubscription } from '@/services/api/push';

export type PushState = 'unsupported' | 'ios-install' | 'server-off' | 'denied' | 'off' | 'on';

function isIos(): boolean {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function isStandalone(): boolean {
  return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as unknown as { standalone?: boolean }).standalone === true;
}

function base64UrlToUint8Array(value: string): Uint8Array<ArrayBuffer> {
  const padded = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(padded);
  const bytes = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
  return bytes;
}

async function registration(): Promise<ServiceWorkerRegistration> {
  const existing = await navigator.serviceWorker.getRegistration('/');
  if (existing) return existing;
  await navigator.serviceWorker.register('/sw.js', { scope: '/' });
  return navigator.serviceWorker.ready;
}

let publicKeyPromise: Promise<{ enabled: boolean; public_key: string | null }> | null = null;
function serverKey() {
  publicKeyPromise ??= getPushPublicKey().catch(() => ({ enabled: false, public_key: null }));
  return publicKeyPromise;
}

export async function getPushState(): Promise<PushState> {
  if (typeof window === 'undefined' || !('serviceWorker' in navigator)) return 'unsupported';
  if (!('PushManager' in window) || !('Notification' in window)) return isIos() && !isStandalone() ? 'ios-install' : 'unsupported';
  const key = await serverKey();
  if (!key.enabled || !key.public_key) return 'server-off';
  if (Notification.permission === 'denied') return 'denied';
  const reg = await navigator.serviceWorker.getRegistration('/');
  const subscription = await reg?.pushManager.getSubscription();
  return subscription && Notification.permission === 'granted' ? 'on' : 'off';
}

/** Ask permission (must run from a tap), subscribe and save on the server. */
export async function enablePush(): Promise<PushState> {
  const key = await serverKey();
  if (!key.enabled || !key.public_key) return 'server-off';
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'off';

  const reg = await registration();
  let subscription = await reg.pushManager.getSubscription();
  if (!subscription) {
    subscription = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToUint8Array(key.public_key) });
  }
  await savePushSubscription(subscription.toJSON());
  return 'on';
}

export async function disablePush(): Promise<PushState> {
  const reg = await navigator.serviceWorker.getRegistration('/');
  const subscription = await reg?.pushManager.getSubscription();
  if (subscription) {
    await deletePushSubscription(subscription.endpoint).catch(() => undefined);
    await subscription.unsubscribe().catch(() => false);
  }
  return 'off';
}
