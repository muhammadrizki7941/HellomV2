// Public POS customer endpoints (table QR menu, guest orders, promos, reservations).
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { publicApiRequest } from './client';

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
  // Add-ons (only active options/values of this outlet's product).
  options?: PosMenuOption[];
};

export type PosMenuOption = {
  id: number;
  name: string;
  type: 'single' | 'multi' | string;
  is_required: boolean;
  values: Array<{ id: number; name: string; price_delta: number }>;
};

// Outlet state shown to the guest (closed / not accepting self-orders).
export type PosCustomerOutletStatus = {
  accepts_orders: boolean;
  is_open: boolean;
  can_order: boolean;
  message: string | null;
  today_hours: string | null;
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
    kind?: 'table' | 'counter';
  };
  outlet?: {
    id: number;
    slug: string;
    name: string;
    address: string | null;
    phone: string | null;
    is_primary: boolean;
    status?: PosCustomerOutletStatus;
    pricing?: { tax_percent: number; service_percent: number; rounding_step: number };
    payment_methods?: string[];
  } | null;
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
    methods?: string[];
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
  status_label?: string;
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
  subtotal_amount?: number;
  service_amount?: number;
  tax_amount?: number;
  rounding_amount?: number;
  discount_amount?: number;
  total_amount: number;
  final_amount: number;
  table_bill_id?: number | null;
  cancel_reason?: string | null;
  created_at: string | null;
  updated_at: string | null;
  items: PosOrderItem[];
};

// Orders on the table's open bill (self-order and cashier). Empty for the shop-link counter.
export function getCustomerTableOrders(tableToken: string) {
  return publicApiRequest<{
    bill: { id: number; opened_at: string | null; total_amount: number; unpaid_amount: number } | null;
    orders: PosOrderPayload[];
  }>(`/pos/customer/table/${encodeURIComponent(tableToken)}/orders`);
}


export function getCustomerMenu(tableToken: string) {
  return publicApiRequest<PosMenuPayload>(`/pos/customer/menu/${tableToken}`);
}

export function getCustomerMenuByOrganization(organizationSlug: string, outletSlug?: string | null) {
  const qs = outletSlug ? `?outlet=${encodeURIComponent(outletSlug)}` : '';
  return publicApiRequest<PosMenuPayload>(`/pos/customer/organization/${encodeURIComponent(organizationSlug)}/menu${qs}`);
}

export type CustomerOutlet = {
  id: number;
  slug: string;
  name: string;
  address: string | null;
  phone: string | null;
  is_primary: boolean;
  status?: PosCustomerOutletStatus;
};

export function getCustomerOrganizationOutlets(organizationSlug: string) {
  return publicApiRequest<{ organization: { slug: string; name: string }; outlets: CustomerOutlet[] }>(
    `/pos/customer/organization/${encodeURIComponent(organizationSlug)}/outlets`
  );
}

export function createCustomerOrder(payload: {
  table_token: string;
  items: Array<{
    product_id: number;
    quantity: number;
    options?: Array<{ option_id: number; value_id: number }>;
    // Unit price shown to the guest; the server reports changes instead of charging silently.
    expected_unit_price?: number;
  }>;
  customer_name?: string;
  customer_phone?: string;
  register_member?: boolean;
  notes?: string;
  payment_confirmed?: boolean;
  payment_method?: string;
}) {
  return publicApiRequest<{ order: PosOrderPayload; member?: { id: number; name: string; points: number } | null }>('/pos/customer/order', {
    method: 'POST',
    body: payload,
  });
}

// tableToken proves the guest ordered from that table (order numbers alone are guessable).
export function getCustomerOrderStatus(orderNumber: string, tableToken: string) {
  const query = new URLSearchParams({ table_token: tableToken }).toString();
  return publicApiRequest<{ order: PosOrderPayload }>(`/pos/customer/order/${encodeURIComponent(orderNumber)}?${query}`);
}

export function claimCustomerPromo(payload: {
  table_token: string;
  customer_name: string;
  customer_phone: string;
  customer_email?: string;
  notes?: string;
}, promoId: number) {
  return publicApiRequest<{
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
    body: payload,
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
  return publicApiRequest<{
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
    body: payload,
  });
}
