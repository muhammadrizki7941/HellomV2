import { HELLOM_API_BASE } from '@/lib/hellomApi';

export type PosMenuProduct = {
  id: number;
  name: string;
  description: string;
  price: number;
  image_path: string | null;
  is_available: boolean;
  track_stock?: boolean;
  stock?: number | null;
  is_available_now?: boolean;
  category?: {
    id: number;
    name: string | null;
  };
};

export type PosMenuCategory = {
  id: number;
  name: string;
  products: PosMenuProduct[];
};

export type PosMenuPayload = {
  table: {
    id: number;
    public_id?: string | null;
    code: string;
    name: string;
    tenant_slug?: string | null;
    organization_slug?: string | null;
  };
  categories: PosMenuCategory[];
  experience: PosCustomerExperiencePayload;
};

export type PosCustomerExperiencePayload = {
  brand: {
    business_name: string;
    tagline: string | null;
    about: string | null;
    phone: string | null;
    whatsapp: string | null;
    address: string | null;
    instagram: string | null;
    website: string | null;
    primary_color: string;
    secondary_color: string;
    accent_color: string;
    background_color: string;
    logo_url: string | null;
    banner_url: string | null;
    banner_kind: 'image' | 'video' | null;
    google_rating: {
      rating: number;
      user_ratings_total: number;
    } | null;
  };
  routes: {
    legacy_order: string;
    promo: string;
    reservations: string;
    member_login: string;
    member_register: string;
    member_dashboard: string;
  };
  promos: Array<{
    id: number;
    title: string;
    promo_code: string | null;
    description: string | null;
    terms: string | null;
    thumbnail_url: string | null;
    link_url: string | null;
    bonus_points: number;
    minimum_spend: number;
    claim_limit: number | null;
    claimed_count: number;
    requires_reservation: boolean;
    valid_until: string | null;
  }>;
  reservations: Array<{
    id: number;
    name: string;
    location: string | null;
    capacity: number;
    description: string | null;
    cover_image_url: string | null;
    rent_price: number;
    rent_enabled: boolean;
    min_menu_total: number;
    estimated_points: number;
    images: Array<{
      id: number;
      url: string;
      caption: string | null;
    }>;
    items: Array<{
      id: number;
      product_id: number;
      product_name: string;
      unit_price: number;
      qty: number;
      is_required: boolean;
      line_total: number;
    }>;
  }>;
  summary: {
    promo_count: number;
    reservation_count: number;
  };
  payment: {
    qris_static_enabled: boolean;
    qris_static_image_url: string | null;
    require_paid_before_submit: boolean;
    whatsapp_number: string | null;
    gopay_enabled: boolean;
    gopay_account_name: string | null;
    gopay_account_number: string | null;
    gopay_deeplink_template: string | null;
    dana_enabled: boolean;
    dana_account_name: string | null;
    dana_account_number: string | null;
    dana_deeplink_template: string | null;
  };
  pending_order: PosOrderPayload | null;
};

export type PosOrderItem = {
  id: number;
  product_id: number;
  product_name: string;
  quantity: number;
  price: number;
  line_total: number;
  selected_options?: unknown;
};

export type PosOrderPayload = {
  id: number;
  order_number: string;
  status: string;
  customer_name: string | null;
  table: {
    id: number;
    code: string;
    name: string;
  } | null;
  table_label: string | null;
  service_type: string | null;
  order_source: string | null;
  payment_method: string | null;
  payment_status: string | null;
  notes: string | null;
  total_amount: number;
  final_amount: number;
  created_at: string | null;
  updated_at: string | null;
  items: PosOrderItem[];
};

type ApiEnvelope<T> = {
  success: boolean;
  message: string;
  data: T;
  error: unknown;
};

async function publicRequest<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${HELLOM_API_BASE}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...(init?.headers ?? {}),
    },
  });

  const payload = (await response.json().catch(() => null)) as ApiEnvelope<T> | null;

  if (!response.ok || !payload?.success) {
    throw new Error(payload?.message || `HTTP ${response.status}`);
  }

  return payload.data;
}

export function getCustomerMenu(tableToken: string) {
  return publicRequest<PosMenuPayload>(`/pos/customer/menu/${tableToken}`);
}

export function getCustomerMenuByOrganization(organizationSlug: string) {
  return publicRequest<PosMenuPayload>(`/pos/customer/organization/${encodeURIComponent(organizationSlug)}/menu`);
}

export function createCustomerOrder(payload: {
  table_token: string;
  items: Array<{
    product_id: number;
    quantity: number;
  }>;
  customer_name?: string;
  customer_phone?: string;
  notes?: string;
  payment_confirmed?: boolean;
  payment_method?: string;
}) {
  return publicRequest<{ order: PosOrderPayload }>('/pos/customer/order', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function getCustomerOrderStatus(orderNumber: string) {
  return publicRequest<{ order: PosOrderPayload }>(`/pos/customer/order/${orderNumber}`);
}

export function claimCustomerPromo(payload: {
  table_token: string;
  customer_name: string;
  customer_phone: string;
  customer_email?: string;
  notes?: string;
}, promoId: number) {
  return publicRequest<{
    claim: Record<string, unknown>;
    member: {
      id: number;
      name: string;
      phone: string | null;
      email: string | null;
      total_points: number;
      redeemable_points: number;
      total_orders: number;
      total_spent: number;
      tier: string;
    } | null;
    created_member: boolean;
    already_claimed: boolean;
    awarded_points?: number;
  }>(`/pos/customer/promos/${promoId}/claim`, {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function createCustomerReservation(payload: {
  table_token: string;
  reservation_space_id: number;
  customer_name: string;
  customer_phone: string;
  customer_email?: string;
  scheduled_at: string;
  duration_minutes: number;
  guests_count: number;
  selected_space_items?: Array<{ item_id: number; qty: number }>;
  menu_items?: Array<{ product_id: number; qty: number }>;
  notes?: string;
}) {
  return publicRequest<{
    reservation: Record<string, unknown>;
    member: {
      id: number;
      name: string;
      phone: string | null;
      email: string | null;
      total_points: number;
      redeemable_points: number;
      total_orders: number;
      total_spent: number;
      tier: string;
    } | null;
    estimated_points: number;
  }>('/pos/customer/reservations', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

// ─── Public POS Member (no auth — customer self-service) ───

export type PosMemberInfo = {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  total_points: number;
  redeemable_points: number;
  total_orders: number;
  total_spent: number;
  tier: string;
};

export function lookupPublicPosMember(orgSlug: string, phone: string) {
  return publicRequest<{ member: PosMemberInfo | null }>(
    `/pos/public/members/lookup?org=${encodeURIComponent(orgSlug)}&phone=${encodeURIComponent(phone)}`
  );
}

export function registerPublicPosMember(
  orgSlug: string,
  payload: { name: string; phone: string; email?: string }
) {
  return publicRequest<{ member: PosMemberInfo; created_member: boolean }>(
    '/pos/public/members/register',
    {
      method: 'POST',
      body: JSON.stringify({ org_slug: orgSlug, ...payload }),
    }
  );
}
