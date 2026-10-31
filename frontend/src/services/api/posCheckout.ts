import { publicApiRequest } from './client';

export type PosCheckoutPlan = {
  id: number;
  slug: string;
  name: string;
  price: number;
  description: string;
  features: unknown;
  billing_cycle: 'monthly';
  is_recommended: boolean;
  max_outlets: number;
};

export type PosPaymentChannel = {
  key: string;
  label: string;
  type: 'qris' | 'va';
};

export type PosCheckoutOptions = {
  app: { id: number; slug: string; name: string } | null;
  plans: PosCheckoutPlan[];
  payment: {
    available: boolean;
    channels: PosPaymentChannel[];
    wallet_available: boolean;
  };
};

export type PosPromoPreview = {
  code: string;
  discount_amount: number;
  total_amount: number;
  [key: string]: unknown;
};

export type PosCheckoutPayment = {
  id: number;
  method: 'qris' | 'va';
  channel: string;
  channel_label: string;
  payment_number: string | null;
  qr_string: string | null;
  qr_image_url: string | null;
  status: string;
  expires_at: string | null;
};

export type PosCheckoutStatus = {
  status: 'pending' | 'paid' | 'failed' | 'expired';
  invoice_number: string;
  amount: number;
  base_amount: number;
  discount_amount: number;
  promo_code: string;
  plan: { name: string; slug: string };
  payment: PosCheckoutPayment | null;
  expires_at: string | null;
  paid_at: string | null;
  login_url: string | null;
  help_url: string;
  purchase_event_id: string;
  email: string;
};

export function getPosCheckoutOptions() {
  return publicApiRequest<PosCheckoutOptions>('/public/pos-checkout/options');
}

export function previewPosCheckoutPromo(planSlug: string, code: string) {
  return publicApiRequest<PosPromoPreview>('/public/pos-checkout/promo', {
    method: 'POST',
    body: { plan_slug: planSlug, code },
  });
}

export function startGuestPosCheckout(payload: {
  name: string;
  email: string;
  phone: string;
  business_name: string;
  plan_slug: string;
  payment_channel: string;
  promo_code?: string;
  website: string;
  purchase_event_id: string;
  terms_accepted: true;
}) {
  return publicApiRequest<{ status_url: string; status_token: string; payment: PosCheckoutPayment }>(
    '/public/pos-checkout',
    { method: 'POST', body: payload },
  );
}

export function getPosCheckoutStatus(token: string) {
  return publicApiRequest<PosCheckoutStatus>(`/public/pos-checkout/${encodeURIComponent(token)}`);
}

export function replacePosCheckoutPayment(token: string, paymentChannel: string) {
  return publicApiRequest<{ status_url: string; payment: PosCheckoutPayment }>(
    `/public/pos-checkout/${encodeURIComponent(token)}/payment`,
    { method: 'POST', body: { payment_channel: paymentChannel } },
  );
}
