// POS app (outlets, menu, tables, orders, members, loyalty, experience, reports) and public POS member endpoints.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest, publicApiRequest } from './client';

// ─── POS ───

export function getPosAccess() {
  return apiRequest<Record<string, unknown>>('/apps/pos/access');
}

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
  return apiRequest<Record<string, unknown>>('/pos/categories');
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
  return apiRequest<Record<string, unknown>>('/pos/products');
}

export function createPosProduct(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/products', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosProduct(productId: number, payload: Record<string, unknown>) {
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

export function getPosOrders() {
  return apiRequest<Record<string, unknown>>('/pos/orders');
}

export function createPosOrder(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/orders', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosOrderStatus(orderId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/pos/orders/${orderId}/status`, {
    method: 'PATCH',
    body: payload,
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
  return apiRequest<Record<string, unknown>>('/pos/members', {
    method: 'POST',
    body: payload,
  });
}

export function searchPosMembers(query: { q?: string; keyword?: string }) {
  const params = new URLSearchParams();
  const searchTerm = query.q || query.keyword || '';
  if (searchTerm) params.set('q', searchTerm);
  const qs = params.toString() ? `?${params.toString()}` : '';
  return apiRequest<Record<string, unknown>>(`/pos/members/search${qs}`);
}

export function getPosLoyaltySettings() {
  return apiRequest<Record<string, unknown>>('/pos/loyalty/settings');
}

export function updatePosLoyaltySettings(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/loyalty/settings', {
    method: 'PUT',
    body: payload,
  });
}

export function getPosLoyaltyRewardRules() {
  return apiRequest<Record<string, unknown>>('/pos/loyalty/reward-rules');
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
  return apiRequest<Record<string, unknown>>('/pos/loyalty/calculate', {
    method: 'POST',
    body: payload,
  });
}

export function applyReward(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/loyalty/apply-reward', {
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
  return apiRequest<Record<string, unknown>>(`/pos/reports/summary${toReportQuery(params)}`);
}

export function getPosReportProducts(params?: ReportParams) {
  return apiRequest<Record<string, unknown>>(`/pos/reports/products${toReportQuery(params)}`);
}

export function getPosReportDaily(params?: ReportParams) {
  return apiRequest<Record<string, unknown>>(`/pos/reports/daily${toReportQuery(params)}`);
}

export function exportPosReport() {
  return apiRequest<Record<string, unknown>>('/pos/reports/export');
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
