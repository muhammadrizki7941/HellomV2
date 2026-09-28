// POS orders engine (Fase 2B): server-priced orders, status flow, payment, table bills,
// realtime tokens, outlet settings, QR tables and the owner member page.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest, apiRequestBlob, buildQuery, publicApiRequest } from './client';

// Stored status codes (labels come from the server as status_label).
export type PosOrderStatus = 'new' | 'accepted' | 'preparing' | 'prepared' | 'completed' | 'cancelled';

export const POS_ORDER_STATUS_LABELS: Record<PosOrderStatus, string> = {
  new: 'Menunggu Konfirmasi',
  accepted: 'Dikonfirmasi',
  preparing: 'Diproses',
  prepared: 'Siap',
  completed: 'Selesai',
  cancelled: 'Dibatalkan',
};

export type PosOrderTotals = {
  subtotal: number;
  discount_amount: number;
  points_discount_amount: number;
  service_amount: number;
  tax_amount: number;
  rounding_amount: number;
  final_amount: number;
  service_percent: number;
  tax_percent: number;
  rounding_step: number;
};

export type PosCartLine = {
  product_id: number;
  quantity: number;
  options?: Array<{ option_id: number; value_id: number }>;
  notes?: string | null;
};

export type PosOrderDraft = {
  items: PosCartLine[];
  table_id?: number | null;
  service_type?: 'dine_in' | 'takeaway';
  customer_name?: string | null;
  customer_phone?: string | null;
  member_id?: number | null;
  reward_rule_id?: number | null;
  redeem_points?: number;
  confirm_member_name?: string | null;
  notes?: string | null;
};

// POST /pos/orders/preview — exactly what the server will charge.
export function previewPosOrder(draft: PosOrderDraft) {
  return apiRequest<{ lines: Array<{ product_id: number; product_name: string; unit_price: number; qty: number; line_total: number }>; totals: PosOrderTotals; redeem_points: number }>(
    '/pos/orders/preview',
    { method: 'POST', body: draft }
  );
}

export function cancelPosOrder(orderId: number, reason: string) {
  return apiRequest<{ order: Record<string, unknown> }>(`/pos/orders/${orderId}/cancel`, { method: 'POST', body: { reason } });
}

export function refundPosOrder(orderId: number, reason: string) {
  return apiRequest<{ order: Record<string, unknown> }>(`/pos/orders/${orderId}/refund`, { method: 'POST', body: { reason } });
}

export type PosTableBill = {
  id: number;
  status: 'open' | 'paid' | 'cancelled';
  dining_table_id: number;
  table_label: string | null;
  opened_at: string | null;
  closed_at: string | null;
  orders_count: number;
  total_amount: number;
  paid_amount: number;
  unpaid_amount: number;
  orders: Array<{
    id: number;
    order_number: string;
    order_source: string | null;
    status: PosOrderStatus;
    status_label: string;
    payment_status: string;
    final_amount: number;
    items: Array<{ name: string; qty: number; line_total: number }>;
  }>;
};

export function getPosTableBills() {
  return apiRequest<{ bills: PosTableBill[] }>('/pos/table-bills');
}

export function payPosTableBill(billId: number, payload: { payment_method: string; payment_amount: number; payment_note?: string }) {
  return apiRequest<{ bill: PosTableBill; total: number; change_amount: number }>(`/pos/table-bills/${billId}/pay`, { method: 'POST', body: payload });
}

export function getPosRealtimeToken() {
  return apiRequest<{ enabled: boolean; token: string | null; expires_at: number | null; room: string; poll_seconds: number }>('/pos/realtime/token');
}

// ─── Outlet settings (tax, service, rounding, self-order, opening hours) ───

export type PosOpeningSlot = { open: string; close: string };
export type PosOutletSettings = {
  pricing: { tax_percent: number; service_percent: number; rounding: 0 | 100 | 500 | 1000 };
  self_order: { accept_orders: boolean; require_confirmation: boolean; max_pending_per_table: number };
  opening_hours: Record<'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun', PosOpeningSlot[]> | null;
  timezone: string;
};
export type PosOutletStatus = { accepts_orders: boolean; is_open: boolean; can_order: boolean; message: string | null; today_hours: string | null };

export function getPosOutletSettings() {
  return apiRequest<{ outlet_id: number; settings: PosOutletSettings; status: PosOutletStatus; payment_methods: string[] }>('/pos/outlet-settings');
}

export function updatePosOutletSettings(payload: Partial<PosOutletSettings>) {
  return apiRequest<{ outlet_id: number; settings: PosOutletSettings; status: PosOutletStatus; payment_methods: string[] }>('/pos/outlet-settings', { method: 'PUT', body: payload });
}

// ─── Tables / QR ───

export type PosQrTable = {
  id: number;
  outlet_id: number | null;
  public_id: string;
  code: string;
  name: string | null;
  is_active: boolean;
  token_rotated_at: string | null;
  has_weak_token: boolean;
};

export function regeneratePosTableToken(tableId: number) {
  return apiRequest<{ table: PosQrTable }>(`/pos/tables/${tableId}/regenerate-token`, { method: 'POST' });
}

export function getPosTablesQrSheet() {
  return apiRequest<{ organization: { name: string; slug: string }; outlet: { id: number; name: string; address: string | null } | null; tables: PosQrTable[] }>('/pos/tables-qr-sheet');
}

// ─── Members (owner page) ───

export type PosMemberRecord = {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  outlet_id: number | null;
  total_points: number;
  redeemable_points: number;
  total_orders: number;
  total_spent: number;
  tier: string;
  last_order_at: string | null;
  created_at: string | null;
};

export type PosMemberListParams = { q?: string; outlet_id?: number | string; sort?: 'recent' | 'most_active' | 'top_spend' | 'points' | 'name'; page?: number; per_page?: number };

export type PosPaginated<T> = { data: T[]; current_page: number; last_page: number; total: number; per_page: number };

export function getPosMemberPage(params: PosMemberListParams = {}) {
  return apiRequest<PosPaginated<PosMemberRecord>>(`/pos/members${buildQuery(params)}`);
}

export type PosLedgerRow = {
  id: number;
  type: 'earn' | 'redeem' | 'adjust' | 'expire' | 'reversal';
  type_label: string;
  points: number;
  balance_after: number;
  reason: string | null;
  order_number: string | null;
  outlet: string | null;
  user: string | null;
  expires_at: string | null;
  created_at: string | null;
};

export type PosMemberDetail = {
  member: PosMemberRecord;
  available_rewards: Array<{ id: number; name: string; description: string | null; reward_type: string; reward_value: number; product: string | null }>;
  recent_points: PosLedgerRow[];
  next_reward: { reward_name: string; current: number; target: number; progress_pct: number; remaining: number } | null;
  redeem: { settings: Record<string, unknown>; verification: { type: 'name' | 'otp'; channel?: string } };
};

export function getPosMemberDetail(memberId: number) {
  return apiRequest<PosMemberDetail>(`/pos/members/${memberId}`);
}

export function getPosMemberLedger(memberId: number, page = 1) {
  return apiRequest<PosPaginated<PosLedgerRow>>(`/pos/members/${memberId}/points?page=${page}`);
}

export function getPosMemberOrders(memberId: number, page = 1) {
  return apiRequest<PosPaginated<{ id: number; order_number: string; outlet: string | null; status: string; payment_status: string; final_amount: number; points_earned: number; redeemed_points: number; created_at: string | null }>>(
    `/pos/members/${memberId}/orders?page=${page}`
  );
}

export function adjustPosMemberPoints(memberId: number, points: number, reason: string) {
  return apiRequest<{ member: PosMemberRecord; transaction: PosLedgerRow }>(`/pos/members/${memberId}/adjust-points`, { method: 'POST', body: { points, reason } });
}

export type PosMemberDuplicateGroup = {
  phone: string;
  members: Array<{ id: number; name: string; phone: string | null; redeemable_points: number; total_orders: number; total_spent: number; last_order_at: string | null; created_at: string | null }>;
};

export function getPosMemberDuplicates() {
  return apiRequest<{ duplicates: PosMemberDuplicateGroup[] }>('/pos/members/duplicates');
}

export function mergePosMembers(targetMemberId: number, sourceMemberId: number, reason: string) {
  return apiRequest<{ member: PosMemberRecord }>('/pos/members/merge', {
    method: 'POST',
    body: { target_member_id: targetMemberId, source_member_id: sourceMemberId, reason },
  });
}

export function exportPosMembers(params: PosMemberListParams = {}) {
  return apiRequestBlob(`/pos/members/export${buildQuery(params)}`);
}

export type PosFraudFlag = {
  id: number;
  rule: string;
  rule_label: string;
  member_id: number | null;
  member_name: string | null;
  user_id: number | null;
  user_name: string | null;
  order_id: number | null;
  outlet_id: number | null;
  details: Record<string, unknown> | null;
  status: 'open' | 'dismissed' | 'confirmed';
  created_at: string | null;
};

export function getPosFraudFlags(status: 'open' | 'dismissed' | 'confirmed' = 'open') {
  return apiRequest<PosPaginated<PosFraudFlag>>(`/pos/members/fraud-flags?status=${status}`);
}

export function resolvePosFraudFlag(flagId: number, status: 'dismissed' | 'confirmed') {
  return apiRequest<{ id: number; status: string }>(`/pos/members/fraud-flags/${flagId}`, { method: 'PATCH', body: { status } });
}

// ─── Customer (public) ───

export function getCustomerRealtimeToken(tableToken: string) {
  return publicApiRequest<{ enabled: boolean; token: string | null; expires_at: number | null; poll_seconds: number }>(
    `/pos/customer/table/${encodeURIComponent(tableToken)}/realtime-token`
  );
}
