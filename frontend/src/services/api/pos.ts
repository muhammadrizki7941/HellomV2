// POS app (outlets, menu, tables, orders, members, loyalty, experience, reports) and public POS member endpoints.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest, apiRequestBlob, publicApiRequest } from './client';

// ─── POS ───

// GET /apps/pos/access (EntitlementController::posAccess): SSO links into the POS.
// customer_url/order_url point at legacy Blade routes and are not used by the SPA.
export type PosAccessInfo = {
  app: string;
  organization: {
    id: number;
    name: string;
    slug: string;
    pos_tenant_slug: string;
    pos_tenant_name: string;
    pos_provisioned_at: string | null;
  };
  access: {
    admin_url: string;
    cashier_url: string;
    customer_url: string;
    order_url: string;
    requires_legacy_admin_auth: boolean;
  };
};

export function getPosAccess() {
  return apiRequest<PosAccessInfo>('/apps/pos/access');
}

// ─── Menu, orders and reports (PosProductController/PosOrderController/PosReportController) ───

export type PosCategoryRecord = {
  id: number;
  tenant_id: string;
  name: string;
  slug: string;
  sort_order: number;
  is_active: boolean;
};

export type PosProductOptionValue = {
  id: number;
  product_option_id: number;
  name: string;
  price_delta: number;
  is_active: boolean;
  sort_order: number;
};

export type PosProductOption = {
  id: number;
  product_id: number;
  name: string;
  type: 'single' | 'multi';
  is_required: boolean;
  is_active: boolean;
  sort_order: number;
  values: PosProductOptionValue[];
};

// products row with its category and options (index always eager-loads both).
export type PosProductRecord = {
  id: number;
  tenant_id: string;
  category_id: number;
  name: string;
  slug: string;
  description: string | null;
  price: number;
  image_path: string | null;
  sort_order: number;
  is_available: boolean;
  track_stock: boolean;
  stock: number | null;
  is_package: boolean;
  show_as_banner: boolean;
  banner_title: string | null;
  banner_subtitle: string | null;
  banner_starts_at: string | null;
  banner_ends_at: string | null;
  available_purchase_types: string[] | null;
  preorder_enabled: boolean;
  preorder_lead_time_minutes: number | null;
  hide_when_unavailable: boolean;
  category: PosCategoryRecord;
  options: PosProductOption[];
};

export type PosOrderListItem = {
  id: number;
  order_number: string;
  customer_name: string;
  table: { id: number; code: string; name: string } | null;
  table_label: string;
  service_type: string;
  status: string;
  payment_status: string;
  total_amount: number;
  discount_amount: number;
  final_amount: number;
  member_id: number | null;
  created_at: string;
  updated_at: string;
  items_count: number;
  items: Array<{ id: number; product_name: string; quantity: number; unit_price: number; line_total: number }>;
};

export type PosReportPeriod = { start: string; end: string; days?: number };

export type PosReportSummary = {
  scope: 'all_outlets' | 'single_outlet';
  is_owner: boolean;
  period: PosReportPeriod;
  summary: {
    total_revenue: number;
    total_orders: number;
    avg_order_value: number;
    total_items_sold: number;
    revenue_change: number;
    orders_change: number;
  };
  payment_breakdown: Array<{ payment_method: string; count: number; total: number }>;
  service_breakdown: Array<Record<string, unknown>>;
  peak_hours: Array<{ hour: number; count: number; total: number }>;
  outlet_breakdown: Array<Record<string, unknown>>;
};

export type PosReportDaily = {
  daily: Array<{ date: string; total_orders: number; total_revenue: number; avg_order: number }>;
};

export type PosReportProducts = {
  top_products: Array<{
    product_name: string;
    product_id: number;
    total_qty: number;
    total_revenue: number;
    avg_price: number;
    order_count: number;
  }>;
  top_categories: Array<Record<string, unknown>>;
  period: PosReportPeriod;
};

// A dining table as returned by PosTableController (dining_tables row).
export type PosTableRecord = {
  id: number;
  tenant_id: string;
  public_id: string;
  code: string;
  name: string | null;
  is_active: boolean;
  created_at?: string | null;
  updated_at?: string | null;
};

export function getPosTables() {
  return apiRequest<{ tables: PosTableRecord[] }>('/pos/tables');
}

export function createPosTable(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/tables', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosTable(tableId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/pos/tables/${tableId}`, {
    method: 'PATCH',
    body: payload,
  });
}

export function deletePosTable(tableId: number) {
  return apiRequest<Record<string, unknown>>(`/pos/tables/${tableId}`, {
    method: 'DELETE',
  });
}

export function getPosCategories() {
  return apiRequest<{ categories: PosCategoryRecord[] }>('/pos/categories');
}

export function createPosCategory(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/categories', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosCategory(categoryId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/pos/categories/${categoryId}`, {
    method: 'PATCH',
    body: payload,
  });
}

export function deletePosCategory(categoryId: number) {
  return apiRequest<Record<string, unknown>>(`/pos/categories/${categoryId}`, {
    method: 'DELETE',
  });
}

export function getPosProducts() {
  return apiRequest<{ products: PosProductRecord[] }>('/pos/products');
}

export function createPosProduct(payload: Record<string, unknown> | FormData) {
  return apiRequest<Record<string, unknown>>('/pos/products', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosProduct(productId: number, payload: Record<string, unknown> | FormData) {
  return apiRequest<Record<string, unknown>>(`/pos/products/${productId}`, {
    method: 'PATCH',
    body: payload,
  });
}

export function deletePosProduct(productId: number) {
  return apiRequest<Record<string, unknown>>(`/pos/products/${productId}`, {
    method: 'DELETE',
  });
}

// ─── POS Outlets (multi-outlet) ───

export type PosOutlet = {
  id: number;
  organization_id: number;
  name: string;
  slug: string;
  tenant_slug: string | null;
  address: string | null;
  phone: string | null;
  email: string | null;
  description: string | null;
  is_primary: boolean;
  is_active: boolean;
};

export type PosOutletListResponse = {
  outlets: PosOutlet[];
  active_outlet_id: number | string | null;
  meta: { used: number; max_outlets: number; can_add: boolean };
};

export function getPosOutlets() {
  return apiRequest<PosOutletListResponse>('/pos/outlets');
}

export function createPosOutlet(payload: { name: string; address?: string; phone?: string; email?: string; description?: string }) {
  return apiRequest<{ outlet: PosOutlet }>('/pos/outlets', { method: 'POST', body: payload });
}

export function updatePosOutlet(outletId: number | string, payload: Record<string, unknown>) {
  return apiRequest<{ outlet: PosOutlet }>(`/pos/outlets/${outletId}`, { method: 'PATCH', body: payload });
}

export function deletePosOutlet(outletId: number | string) {
  return apiRequest<null>(`/pos/outlets/${outletId}`, { method: 'DELETE' });
}

export function getPublicOrganizationOutlets(organizationSlug: string) {
  return publicApiRequest<{ organization: { slug: string; name: string }; outlets: PosOutlet[] }>(
    `/pos/customer/organization/${organizationSlug}/outlets`
  );
}

// status: filter on the server ('all' or empty = no filter). The backend
// returns at most the 100 latest orders, so filtering must happen there.
export function getPosOrders(status?: string) {
  const qs = status && status !== 'all' ? `?${new URLSearchParams({ status }).toString()}` : '';
  return apiRequest<{ orders: PosOrderListItem[] }>(`/pos/orders${qs}`);
}

export function createPosOrder(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/orders', {
    method: 'POST',
    body: payload,
  });
}

// Backend validates { status }; callers pass the status string.
export function updatePosOrderStatus(orderId: number, status: string) {
  return apiRequest<{ order: Record<string, unknown> }>(`/pos/orders/${orderId}/status`, {
    method: 'PATCH',
    body: { status },
  });
}

export function confirmPosOrderPayment(orderId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/pos/orders/${orderId}/payment`, {
    method: 'POST',
    body: payload,
  });
}

export function getPosOrderReceipt(orderId: number) {
  return apiRequest<Record<string, unknown>>(`/pos/orders/${orderId}/receipt`);
}

export function getPosMembers() {
  return apiRequest<Record<string, unknown>>('/pos/members');
}

export function createPosMember(payload: Record<string, unknown>) {
  // Returns the created pos_members row (superset of the search result fields).
  return apiRequest<{ member: PosMemberSearchResult }>('/pos/members', {
    method: 'POST',
    body: payload,
  });
}

// Member search result (PosMemberController::search).
export type PosMemberSearchResult = {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  total_points: number;
  total_orders: number;
  total_spent: number;
};

export function searchPosMembers(query: { q?: string; keyword?: string }) {
  const params = new URLSearchParams();
  const searchTerm = query.q || query.keyword || '';
  if (searchTerm) params.set('q', searchTerm);
  const qs = params.toString() ? `?${params.toString()}` : '';
  return apiRequest<{ members: PosMemberSearchResult[] }>(`/pos/members/search${qs}`);
}

// PosLoyaltySetting::toPosPayload.
export type PosLoyaltySettings = {
  enabled: boolean;
  points_per_amount: number;
  min_spend_amount: number;
  max_points_per_order: number | null;
};

export function getPosLoyaltySettings() {
  return apiRequest<PosLoyaltySettings>('/pos/loyalty/settings');
}

export function updatePosLoyaltySettings(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/loyalty/settings', {
    method: 'PUT',
    body: payload,
  });
}

// pos_reward_rules rows (PosRewardRule model).
export type PosRewardRuleRecord = {
  id: number;
  tenant_id: string;
  name: string;
  trigger_type: 'points_threshold' | 'orders_threshold' | 'spend_threshold';
  trigger_value: number;
  reward_type: 'free_product' | 'discount_percent' | 'discount_fixed' | 'bonus_points';
  reward_value: number;
  reward_product_id: number | null;
  is_active: boolean;
  description: string | null;
  created_at: string;
  updated_at: string;
};

export function getPosLoyaltyRewardRules() {
  return apiRequest<PosRewardRuleRecord[]>('/pos/loyalty/reward-rules');
}

export function createPosRewardRule(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/loyalty/reward-rules', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosRewardRule(ruleId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/pos/loyalty/reward-rules/${ruleId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deletePosRewardRule(ruleId: number) {
  return apiRequest<Record<string, unknown>>(`/pos/loyalty/reward-rules/${ruleId}`, {
    method: 'DELETE',
  });
}

export function calculateLoyaltyPoints(payload: Record<string, unknown>) {
  return apiRequest<{ points_to_earn: number; available_rewards: Array<Record<string, unknown>> }>('/pos/loyalty/calculate', {
    method: 'POST',
    body: payload,
  });
}

export function applyReward(payload: Record<string, unknown>) {
  return apiRequest<{ reward: { id: number; name: string; type: string }; discount_amount: number; final_amount: number; free_product_id: number | null }>('/pos/loyalty/apply-reward', {
    method: 'POST',
    body: payload,
  });
}

// Shapes returned by PosExperienceController (transformPromo/Claim/Space/Reservation).
export type PosExperiencePromo = {
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
  starts_at: string | null;
  ends_at: string | null;
  valid_until: string | null;
  is_active: boolean;
  sort_order: number;
};

export type PosExperienceMember = {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  total_points: number;
  redeemable_points: number;
  total_orders: number;
  total_spent: number;
  tier: string | null;
};

export type PosExperienceClaim = {
  id: number;
  promo: { id: number; title: string; promo_code: string | null } | null;
  member: PosExperienceMember | null;
  customer_name: string;
  customer_phone: string | null;
  customer_email: string | null;
  claim_code: string | null;
  bonus_points_awarded: number;
  claimed_via: string | null;
  created_at: string | null;
};

export type PosExperienceSpaceItem = {
  id: number;
  product_id: number;
  product_name: string;
  unit_price: number;
  qty: number;
  is_required: boolean;
  sort_order: number;
  line_total: number;
};

export type PosExperienceSpace = {
  id: number;
  name: string;
  slug: string;
  location: string | null;
  capacity: number;
  description: string | null;
  rent_price: number;
  rent_enabled: boolean;
  min_menu_total: number;
  sort_order: number;
  is_active: boolean;
  cover_image_url: string | null;
  images: Array<{ id: number; url: string; caption: string | null }>;
  items: PosExperienceSpaceItem[];
  estimated_points: number;
};

export type PosExperienceReservation = {
  id: number;
  space_name: string;
  reservation_space_id: number;
  customer_name: string;
  customer_phone: string;
  customer_email: string | null;
  scheduled_at: string | null;
  duration_minutes: number;
  guests_count: number;
  notes: string | null;
  admin_notes: string | null;
  status: string;
  rent_price: number;
  items_total: number;
  menu_commitment_total: number;
  total_price: number;
  estimated_points: number;
  items_snapshot: Array<Record<string, unknown>>;
  menu_order_snapshot: Array<Record<string, unknown>>;
};

export type PosExperienceDashboard = {
  summary: {
    active_promos: number;
    promo_claims: number;
    active_spaces: number;
    pending_reservations: number;
  };
  loyalty_settings: Record<string, unknown>;
  products: Array<{ id: number; name: string; price: number }>;
  promos: PosExperiencePromo[];
  promo_claims: PosExperienceClaim[];
  spaces: PosExperienceSpace[];
  reservations: PosExperienceReservation[];
};

export function getPosExperienceDashboard() {
  return apiRequest<PosExperienceDashboard>('/pos/customer-experience/dashboard');
}

export function createPosExperiencePromo(payload: Record<string, unknown> | FormData) {
  return apiRequest<Record<string, unknown>>('/pos/customer-experience/promos', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosExperiencePromo(promoId: number, payload: Record<string, unknown> | FormData) {
  return apiRequest<Record<string, unknown>>(`/pos/customer-experience/promos/${promoId}`, {
    method: 'POST',
    body: payload,
  });
}

export function deletePosExperiencePromo(promoId: number) {
  return apiRequest<Record<string, unknown>>(`/pos/customer-experience/promos/${promoId}`, {
    method: 'DELETE',
  });
}

export function createPosExperienceSpace(payload: Record<string, unknown> | FormData) {
  return apiRequest<Record<string, unknown>>('/pos/customer-experience/spaces', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosExperienceSpace(spaceId: number, payload: Record<string, unknown> | FormData) {
  return apiRequest<Record<string, unknown>>(`/pos/customer-experience/spaces/${spaceId}`, {
    method: 'POST',
    body: payload,
  });
}

export function deletePosExperienceSpace(spaceId: number) {
  return apiRequest<Record<string, unknown>>(`/pos/customer-experience/spaces/${spaceId}`, {
    method: 'DELETE',
  });
}

export function updatePosExperienceReservationStatus(reservationId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/pos/customer-experience/reservations/${reservationId}/status`, {
    method: 'PATCH',
    body: payload,
  });
}

type ReportParams = Record<string, string | number | undefined>;

function toReportQuery(params?: ReportParams): string {
  if (!params) return '';
  const entries = Object.entries(params).filter(([, v]) => v !== undefined && v !== '');
  if (!entries.length) return '';
  return '?' + new URLSearchParams(entries.map(([k, v]) => [k, String(v)])).toString();
}

export function getPosReportSummary(params?: ReportParams) {
  return apiRequest<PosReportSummary>(`/pos/reports/summary${toReportQuery(params)}`);
}

export function getPosReportProducts(params?: ReportParams) {
  return apiRequest<PosReportProducts>(`/pos/reports/products${toReportQuery(params)}`);
}

export function getPosReportDaily(params?: ReportParams) {
  return apiRequest<PosReportDaily>(`/pos/reports/daily${toReportQuery(params)}`);
}

// Excel (.xlsx) export for the given period; returns the file as a Blob.
export function exportPosReport(params?: ReportParams) {
  return apiRequestBlob(`/pos/reports/export${toReportQuery(params)}`);
}

// ─── POS Public Member (no auth required — customer-facing) ───

export function registerPublicPosMember(
  orgSlug: string,
  payload: { name: string; phone: string; email?: string }
) {
  return publicApiRequest<Record<string, unknown>>('/pos/public/members/register', {
    method: 'POST',
    body: { org_slug: orgSlug, ...payload },
  });
}

export function lookupPublicPosMember(orgSlug: string, phone: string) {
  return publicApiRequest<Record<string, unknown>>(
    `/pos/public/members/lookup?org=${encodeURIComponent(orgSlug)}&phone=${encodeURIComponent(phone)}`
  );
}
