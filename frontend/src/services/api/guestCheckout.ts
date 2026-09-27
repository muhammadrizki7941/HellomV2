// Guest (no-login) checkout for platform digital products and email sign-in links.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { publicApiRequest } from './client';

export type GuestCheckoutProduct = {
  id: number;
  slug: string;
  name: string;
  tagline: string | null;
  category: string | null;
  type: string;
  price: number;
  currency: string | null;
  thumbnail_url: string | null;
};

export type ManualPaymentMethodOption = {
  key: string;
  label: string;
  bank_name?: string | null;
  account_name?: string | null;
  account_number?: string | null;
  instructions?: string | null;
  image_url?: string | null;
};

export type GatewayPaymentInstructions = {
  provider?: string | null;
  method?: string | null;
  channel?: string | null;
  channel_label?: string | null;
  va_number?: string | null;
  qr_string?: string | null;
  qr_image_url?: string | null;
  amount?: number | null;
  fee?: number | null;
  expires_at?: string | null;
  reference_id?: string | null;
};

// GuestProductCheckoutController::options
export type GuestCheckoutOptions = {
  product: GuestCheckoutProduct;
  guest_checkout_available: boolean;
  payment: {
    gateway: {
      provider: string;
      ready: boolean;
      channels: Array<{ key: string; label: string; type: string }>;
    };
    manual: {
      enabled: boolean;
      notes: string | null;
      methods: ManualPaymentMethodOption[];
    };
  };
};

// GuestProductCheckoutController::store (ProductCheckoutService::start data + token)
export type GuestCheckoutStartResult = {
  checkout_token: string;
  purchase_id: number;
  status: string;
  payment_gateway: string | null;
  payment_method: string | null;
  checkout_url: string | null;
  payment_instructions?: GatewayPaymentInstructions | null;
  manual_payment?: ManualPaymentMethodOption | null;
};

// GuestProductCheckoutController::status
export type GuestCheckoutStatus = {
  transaction_code: string | null;
  status: 'pending' | 'paid' | 'failed' | 'refunded' | 'cancelled' | string;
  is_paid: boolean;
  amount: number;
  payment_gateway: string | null;
  payment_method: string | null;
  checkout_url: string | null;
  payment_instructions: GatewayPaymentInstructions | null;
  manual_payment: ManualPaymentMethodOption | null;
  email: string;
  access_email_sent: boolean;
  product: GuestCheckoutProduct | null;
};

export function getGuestCheckoutOptions(slug: string) {
  return publicApiRequest<GuestCheckoutOptions>(`/public/products/${encodeURIComponent(slug)}/checkout`);
}

export function startGuestCheckout(
  slug: string,
  payload: {
    email: string;
    phone?: string;
    payment_flow?: 'manual' | 'gateway';
    manual_payment_method?: string;
    gateway_channel?: string;
  }
) {
  return publicApiRequest<GuestCheckoutStartResult>(`/public/products/${encodeURIComponent(slug)}/checkout`, {
    method: 'POST',
    body: payload,
  });
}

export function getGuestCheckoutStatus(token: string) {
  return publicApiRequest<GuestCheckoutStatus>(`/public/product-checkouts/${encodeURIComponent(token)}`);
}

export function resendGuestAccessEmail(token: string) {
  return publicApiRequest<{ email: string }>(`/public/product-checkouts/${encodeURIComponent(token)}/resend-access`, {
    method: 'POST',
    body: {},
  });
}

// AuthController::magicLogin — exchanges an emailed sign-in link for a session.
export function magicLogin(token: string) {
  return publicApiRequest<{ token: string; token_type: string; user: unknown; redirect_path: string | null }>(
    '/auth/magic-login',
    { method: 'POST', body: { token } }
  );
}
