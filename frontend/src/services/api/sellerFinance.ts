// Landing-page sales money (Fase 2): seller "Saldo Penjualan", buyer order status page,
// super admin "Keuangan Penjual".
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest, apiRequestBlob, buildQuery, publicApiRequest } from './client';

export type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number; per_page: number };

// ─── Buyer (public) ───

export type LandingOrderStatus = {
  status: 'pending' | 'paid' | 'fulfilled' | 'expired' | 'failed' | 'refunded';
  status_label: string;
  product_name: string;
  amount: number;
  buyer_email_masked: string | null;
  expires_at: string | null;
  paid_at: string | null;
  has_file: boolean;
  download_token: string | null;
  access_path: string | null; // "/akses/{token}" once paid
};

export function getLandingOrderPublicStatus(reference: string) {
  return publicApiRequest<LandingOrderStatus>(`/public/landingpage/orders/${encodeURIComponent(reference)}/status`);
}

// Buyer came back from the payment page; the transaction id is only a hint for a server check.
export function reportLandingOrderReturn(reference: string, trxId?: string | null) {
  return publicApiRequest<{ status: string }>(`/public/landingpage/orders/${encodeURIComponent(reference)}/returned`, {
    method: 'POST',
    body: trxId ? { trx_id: trxId } : {},
  });
}

// ─── Seller ───

export type SellerPayoutAccount = {
  status: string;
  verified: boolean;
  email_verified: boolean;
  destination_type: 'bank' | 'ewallet';
  bank_code: string | null;
  bank_name: string | null;
  account_number_masked: string | null;
  account_name: string | null;
  hold_until: string | null;
  can_withdraw: boolean;
  blocked_reason: string | null;
};

export type SellerFinanceSummary = {
  balance: { pending: number; available: number; processing: number; withdrawn: number; is_frozen: boolean };
  payout_account: SellerPayoutAccount;
  rules: { min_withdrawal: number; withdrawal_fee_flat: number; hold_days: number; platform_fee_percent: number; sla_hours: number };
  sales: { paid_orders: number; gross_total: number };
};

export type SellerLedgerRow = {
  id: number;
  type: string;
  type_label: string;
  bucket: 'pending' | 'available';
  amount: number;
  pending_after: number;
  available_after: number;
  description: string | null;
  available_at: string | null;
  created_at: string | null;
  order: { reference: string; product_name: string; buyer_name: string | null; price: number; fee: number; net: number; payment_method: string | null } | null;
  withdrawal: { reference: string; status: string } | null;
};

export type SellerWithdrawalRow = {
  id: number;
  reference: string;
  status: 'requested' | 'processing' | 'paid' | 'failed' | 'cancelled';
  status_label: string;
  amount: number;
  fee_amount: number;
  net_amount: number;
  destination_type: string;
  bank_code: string;
  bank_name: string | null;
  account_number_masked: string;
  account_name: string;
  mode: string;
  failure_reason: string | null;
  created_at: string | null;
  processing_at: string | null;
  paid_at: string | null;
};

export function getSellerFinanceSummary() {
  return apiRequest<SellerFinanceSummary>('/seller/finance/summary');
}

export function getSellerLedger(page = 1) {
  return apiRequest<Paginated<SellerLedgerRow>>(`/seller/finance/ledger?page=${page}`);
}

export function getSellerWithdrawals(page = 1) {
  return apiRequest<Paginated<SellerWithdrawalRow>>(`/seller/finance/withdrawals?page=${page}`);
}

export function requestSellerWithdrawal(amount: number, notes?: string) {
  return apiRequest<{ withdrawal: SellerWithdrawalRow }>('/seller/finance/withdrawals', { method: 'POST', body: { amount, notes } });
}

export function cancelSellerWithdrawal(id: number) {
  return apiRequest<{ withdrawal: SellerWithdrawalRow }>(`/seller/finance/withdrawals/${id}/cancel`, { method: 'POST' });
}

// ─── Super admin ───

export type AdminSellerFinanceSummary = {
  money_in: number;
  paid_orders: number;
  platform_fee: number;
  gateway_fee: number;
  hellom_net: number;
  liabilities: { pending: number; available: number; processing: number };
  withdrawn_total: number;
  withdrawals_open: number;
  withdrawals_near_sla: number;
  withdrawals_over_sla: number;
  orders_pending: number;
  refunds_open: number;
  refunds_open_amount: number;
};

export type AdminWithdrawalRow = SellerWithdrawalRow & {
  organization: { id: number; name: string | null; slug: string | null };
  account_number: string;
  age_hours: number;
  sla: 'ok' | 'near' | 'over' | 'done';
  has_proof: boolean;
  auto_error: string | null;
};

export type AdminWebhookLog = {
  id: number;
  provider: string;
  event_id: string | null;
  reference: string | null;
  signature_valid: boolean;
  outcome: string | null;
  error: string | null;
  ip: string | null;
  received_at: string | null;
  payload: string;
};

export type SellerFinanceSettings = {
  platform_fee_percent: number;
  platform_fee_flat: number;
  min_margin_flat: number;
  gateway_fees: Record<'qris' | 'va' | 'ewallet' | 'cc' | 'retail' | 'other', { percent: number; flat: number }>;
  hold_days: number;
  new_seller_hold_days: number;
  new_seller_days: number;
  min_withdrawal: number;
  withdrawal_fee_flat: number;
  withdrawal_mode: 'manual' | 'auto';
  order_expiry_hours: number;
  physical_order_expiry_hours: number;
  sla_hours: number;
  sla_warn_hours: number;
  bank_change_hold_hours: number;
};

export type AdminReconciliation = {
  checked_sellers: number;
  mismatches: Array<{ organization_id: number; organization: string | null; cached: Record<string, number>; computed: Record<string, number> }>;
  problem_orders: Array<{ reference: string; status: string; amount: number; problems: Array<Record<string, unknown>>; created_at: string | null }>;
  stale_pending_orders: number;
  checked_at: string;
};

export function getAdminSellerFinanceSummary() {
  return apiRequest<AdminSellerFinanceSummary>('/admin/seller-finance/summary');
}

export function getAdminSellerWithdrawals(status = 'open', page = 1) {
  return apiRequest<Paginated<AdminWithdrawalRow>>(`/admin/seller-finance/withdrawals${buildQuery({ status, page })}`);
}

export function approveSellerWithdrawal(id: number) {
  return apiRequest<{ withdrawal: SellerWithdrawalRow }>(`/admin/seller-finance/withdrawals/${id}/approve`, { method: 'POST' });
}

// FormData: optional `proof` (image/pdf) and `provider_ref`. Do not set Content-Type manually.
export function markSellerWithdrawalPaid(id: number, form: FormData) {
  return apiRequest<{ withdrawal: SellerWithdrawalRow }>(`/admin/seller-finance/withdrawals/${id}/mark-paid`, { method: 'POST', body: form });
}

export function markSellerWithdrawalFailed(id: number, reason: string) {
  return apiRequest<{ withdrawal: SellerWithdrawalRow }>(`/admin/seller-finance/withdrawals/${id}/mark-failed`, { method: 'POST', body: { reason } });
}

export function downloadSellerWithdrawalProof(id: number) {
  return apiRequestBlob(`/admin/seller-finance/withdrawals/${id}/proof`);
}

export function getAdminWebhookLogs(params: { provider?: string; outcome?: string; reference?: string; page?: number } = {}) {
  return apiRequest<Paginated<AdminWebhookLog>>(`/admin/seller-finance/webhooks${buildQuery(params)}`);
}

export function getAdminReconciliation() {
  return apiRequest<AdminReconciliation>('/admin/seller-finance/reconciliation');
}

export function getSellerFinanceSettings() {
  return apiRequest<SellerFinanceSettings>('/admin/seller-finance/settings');
}

export function updateSellerFinanceSettings(payload: Partial<SellerFinanceSettings>) {
  return apiRequest<SellerFinanceSettings>('/admin/seller-finance/settings', { method: 'PUT', body: payload });
}

// Hold a seller's balance (no withdrawals) or set a per-seller hold period.
export function updateSellerFinanceSeller(organizationId: number, body: { is_frozen?: boolean; frozen_reason?: string | null; hold_days_override?: number | null }) {
  return apiRequest<unknown>(`/admin/seller-finance/sellers/${organizationId}`, { method: 'PATCH', body });
}

// Manual correction of a seller balance (ledger entry type "adjustment", audited).
export function adjustSellerBalance(organizationId: number, body: { amount: number; bucket: 'available' | 'pending'; reason: string }) {
  return apiRequest<unknown>(`/admin/seller-finance/sellers/${organizationId}/adjustment`, { method: 'POST', body });
}

export function exportSellerFinance(type: 'withdrawals' | 'ledger' | 'orders', from?: string, to?: string) {
  return apiRequestBlob(`/admin/seller-finance/export${buildQuery({ type, from, to })}`);
}
