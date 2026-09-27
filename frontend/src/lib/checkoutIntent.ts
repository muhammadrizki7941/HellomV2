import { checkoutConfirmWallet, checkoutStart, purchaseProduct } from '@/lib/hellomApi';

export type AppCheckoutIntent = {
  kind: 'app_subscription';
  app_slug: string;
  app_name?: string;
  plan_slug?: string;
  billing_cycle?: 'monthly' | 'yearly' | 'lifetime';
  payment_flow?: 'wallet' | 'direct';
  manual_payment_method?: 'bank_transfer' | 'gopay' | 'dana' | 'qris';
  return_to?: string;
};

export type ProductCheckoutIntent = {
  kind: 'digital_product';
  product_id?: number;
  product_slug: string;
  payment_flow?: 'manual' | 'gateway';
  manual_payment_method?: string;
  return_to?: string;
};

export type PendingCheckoutIntent = (AppCheckoutIntent | ProductCheckoutIntent) & {
  version: 1;
  created_at: string;
};

type NavigateFn = (to: string, options?: { replace?: boolean }) => void;

const CHECKOUT_INTENT_KEY = 'hellom_pending_checkout_intent';
const MAX_INTENT_AGE_MS = 24 * 60 * 60 * 1000;

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function normalizeIntent(value: unknown): PendingCheckoutIntent | null {
  if (!isRecord(value)) return null;
  if (value.version !== 1) return null;
  if (typeof value.created_at !== 'string') return null;

  const createdAt = Date.parse(value.created_at);
  if (!Number.isFinite(createdAt) || Date.now() - createdAt > MAX_INTENT_AGE_MS) {
    return null;
  }

  if (value.kind === 'app_subscription' && typeof value.app_slug === 'string') {
    return value as PendingCheckoutIntent;
  }

  if (value.kind === 'digital_product' && typeof value.product_slug === 'string') {
    return value as PendingCheckoutIntent;
  }

  return null;
}

export function savePendingCheckoutIntent(intent: AppCheckoutIntent | ProductCheckoutIntent) {
  if (typeof window === 'undefined') return;

  const payload: PendingCheckoutIntent = {
    ...intent,
    version: 1,
    created_at: new Date().toISOString(),
  };

  window.localStorage.setItem(CHECKOUT_INTENT_KEY, JSON.stringify(payload));
  if (intent.return_to) {
    window.localStorage.setItem('hellom_intended_url', intent.return_to);
  }
}

export function getPendingCheckoutIntent(): PendingCheckoutIntent | null {
  if (typeof window === 'undefined') return null;

  const raw = window.localStorage.getItem(CHECKOUT_INTENT_KEY);
  if (!raw) return null;

  try {
    const parsed = JSON.parse(raw) as unknown;
    const intent = normalizeIntent(parsed);
    if (!intent) {
      clearPendingCheckoutIntent();
    }
    return intent;
  } catch {
    clearPendingCheckoutIntent();
    return null;
  }
}

export function clearPendingCheckoutIntent() {
  if (typeof window === 'undefined') return;
  window.localStorage.removeItem(CHECKOUT_INTENT_KEY);
  window.localStorage.removeItem('hellom_intended_url');
}

export function authUrlForCheckoutIntent(path: '/login' | '/register', intent: AppCheckoutIntent | ProductCheckoutIntent) {
  const params = new URLSearchParams();

  if (intent.kind === 'app_subscription') {
    params.set('app', intent.app_slug);
    params.set('subscribe', '1');
    if (intent.plan_slug) params.set('plan', intent.plan_slug);
  }

  if (intent.kind === 'digital_product') {
    params.set('checkout', 'product');
    params.set('product', intent.product_slug);
  }

  const qs = params.toString();
  return `${path}${qs ? `?${qs}` : ''}`;
}

export async function continuePendingCheckoutAfterAuth(navigate: NavigateFn): Promise<boolean> {
  const intent = getPendingCheckoutIntent();
  if (!intent) return false;

  if (intent.kind === 'app_subscription') {
    const fallback = intent.return_to || `/dashboard/apps/${intent.app_slug}${intent.app_slug === 'pos' ? '?subscribe=1' : ''}`;

    if (!intent.plan_slug || !intent.payment_flow) {
      clearPendingCheckoutIntent();
      navigate(fallback, { replace: true });
      return true;
    }

    const checkout = await checkoutStart({
      app_slug: intent.app_slug,
      plan_slug: intent.plan_slug,
      billing_cycle: intent.billing_cycle || (intent.plan_slug.includes('yearly') ? 'yearly' : intent.plan_slug.includes('lifetime') ? 'lifetime' : 'monthly'),
      payment_flow: intent.payment_flow,
      manual_payment_method: intent.manual_payment_method,
    });

    if (intent.payment_flow === 'wallet') {
      const checkoutIntent = checkout.checkout_intent as { intent_token?: string };
      await checkoutConfirmWallet({
        intent_token: String(checkoutIntent.intent_token || ''),
      });
      clearPendingCheckoutIntent();
      navigate(`/dashboard/apps/${intent.app_slug}`, { replace: true });
      return true;
    }

    clearPendingCheckoutIntent();
    const paymentUrl = String((checkout.payment as { payment_url?: unknown } | undefined)?.payment_url || '');
    if (paymentUrl) {
      window.location.href = paymentUrl;
      return true;
    }

    navigate('/dashboard/payments', { replace: true });
    return true;
  }

  const fallback = intent.return_to || `/dashboard/products/${intent.product_slug}/checkout`;
  if (!intent.product_id || !intent.payment_flow) {
    clearPendingCheckoutIntent();
    navigate(fallback, { replace: true });
    return true;
  }

  const purchase = await purchaseProduct(intent.product_id, {
    payment_flow: intent.payment_flow,
    manual_payment_method: intent.payment_flow === 'manual' ? intent.manual_payment_method : undefined,
  });

  clearPendingCheckoutIntent();
  const checkoutUrl = String((purchase as { checkout_url?: unknown }).checkout_url || '');
  if (checkoutUrl) {
    window.location.href = checkoutUrl;
    return true;
  }

  navigate('/dashboard/my-purchases', { replace: true });
  return true;
}
