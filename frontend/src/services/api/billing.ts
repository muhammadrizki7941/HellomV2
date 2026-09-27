// Catalog, pricing, checkout, wallet, payout/KYC profile.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest } from './client';

// ─── Catalog & Pricing ───

// GET /catalog/apps (AppCatalogController::index): apps with this org's access.
export type CatalogAppItem = {
  app: { slug: string; name: string; [key: string]: unknown };
  entitlement: { status: string; allowed: boolean; plan?: { slug: string; name: string } | null; [key: string]: unknown };
  recommended_plan?: { slug: string; name: string; [key: string]: unknown } | null;
  [key: string]: unknown;
};

export function getCatalogApps() {
  return apiRequest<{ items: CatalogAppItem[] }>('/catalog/apps');
}

// GET /pricing/matrix (PricingController::matrix).
export type PricingMatrixPlan = {
  id: number;
  slug: string;
  name: string;
  type: string;
  price: number;
  description: string;
  features: unknown;
  billing_cycles: string[];
  duration_days: number | null;
  max_outlets: number;
  is_recommended: boolean;
  sort_order: number;
  is_current: boolean;
};

export type PricingMatrixItem = {
  app: { id: number; slug: string; name: string };
  current: { status: string; plan_slug: string | null };
  plans: PricingMatrixPlan[];
};

export function getPricingMatrix() {
  return apiRequest<{ organization_id: number; items: PricingMatrixItem[] }>('/pricing/matrix');
}

// ─── Billing & Wallet ───

export function getPaymentGatewayStatus() {
  return apiRequest<Record<string, unknown>>('/billing/gateway-status');
}

export function getCheckoutRuntimeConfig() {
  return apiRequest<Record<string, unknown>>('/billing/runtime-config');
}

export function updateCheckoutRuntimeConfig(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/billing/runtime-config', {
    method: 'PUT',
    body: payload,
  });
}

export function checkoutStart(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/billing/checkout-start', {
    method: 'POST',
    body: payload,
  });
}

/**
 * Confirm a gateway checkout without waiting for the inbound webhook — the server
 * verifies the payment with iPaymu and activates access. Returns { active, status }.
 */
export function reconcileCheckout(intentToken: string, transactionId?: string | null) {
  return apiRequest<{ active: boolean; status: string }>('/billing/checkout-reconcile', {
    method: 'POST',
    body: { intent_token: intentToken, transaction_id: transactionId || undefined },
  });
}

export function checkoutIntentMock(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/billing/checkout-intent-mock', {
    method: 'POST',
    body: payload,
  });
}

export function checkoutConfirmMock(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/billing/checkout-confirm-mock', {
    method: 'POST',
    body: payload,
  });
}

export function checkoutConfirmWallet(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/billing/checkout-confirm-wallet', {
    method: 'POST',
    body: payload,
  });
}

// ─── Wallet types (WalletController payloads) ───

export type WalletBalance = { available_balance: number; pending_balance: number; total_in: number; total_out: number };

export type WalletTransaction = {
  id: number;
  type: string;
  direction: string;
  amount: number;
  balance_after: number;
  reference_type: string | null;
  reference_id: string | null;
  external_ref: string | null;
  description: string | null;
  metadata: unknown;
  created_at: string | null;
};

export type WalletWithdrawal = {
  id: number;
  status: string;
  amount: number;
  fee_amount: number;
  net_amount: number;
  bank_code: string | null;
  account_number_masked: string | null;
  account_name: string | null;
  provider: string;
  external_ref: string | null;
  provider_ref: string | null;
  notes: string | null;
  processed_at: string | null;
  created_at: string | null;
  updated_at: string | null;
};

export type WalletOverview = {
  organization: { id: number; name: string; slug: string };
  requester_role: string;
  wallet: WalletBalance;
  recent_transactions: WalletTransaction[];
  pending_withdrawals: WalletWithdrawal[];
};

export type PayoutPolicy = {
  query: { channel: string; amount: number };
  policy: { default: Record<string, unknown>; channels: Record<string, unknown>; selected: Record<string, unknown> };
  estimation: { fee_amount: number; net_amount: number };
};

export function getWalletOverview() {
  return apiRequest<WalletOverview>('/wallet/overview');
}

// Short-lived token for the Socket.IO handshake (grants private rooms).
export function getRealtimeToken() {
  return apiRequest<{ token: string; expires_at: number; rooms: string[] }>('/realtime/token');
}

export function getWalletTransactions(query: { limit?: number; cursor?: number; type?: string } = {}) {
  const params = new URLSearchParams();
  if (query.limit) params.set('limit', String(query.limit));
  if (query.cursor) params.set('cursor', String(query.cursor));
  if (query.type) params.set('type', query.type);
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<{ items: WalletTransaction[]; pagination: Record<string, unknown> }>(`/wallet/transactions${qs}`);
}

export function createWalletTopupSession(payload: { amount: number; channel?: string }) {
  return apiRequest<{ reference_id: string; provider: string; payment_session_id: string; payment_url: string; amount: number; channel: string }>('/billing/wallet/topup-session', {
    method: 'POST',
    body: payload,
  });
}

export function walletTopupMock(payload: { amount: number; source?: string; notes?: string }) {
  return apiRequest<Record<string, unknown>>('/billing/wallet/topup-mock', {
    method: 'POST',
    body: payload,
  });
}

export function getPayoutPolicy(query: { channel?: string; amount?: number } = {}) {
  const params = new URLSearchParams();
  if (query.channel) params.set('channel', query.channel);
  if (query.amount) params.set('amount', String(query.amount));
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<PayoutPolicy>(`/wallet/payout-policy${qs}`);
}

export function requestWithdrawal(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/wallet/withdrawals', {
    method: 'POST',
    body: payload,
  });
}

// ─── Payout / KYC profile (KTP + bank) ───

export function getPayoutProfile() {
  return apiRequest<Record<string, unknown>>('/payout-profile');
}

export function submitPayoutProfile(formData: FormData) {
  return apiRequest<Record<string, unknown>>('/payout-profile', {
    method: 'POST',
    body: formData,
  });
}

export function getAdminPayoutProfiles(status: string = 'pending') {
  return apiRequest<Record<string, unknown>>(`/admin/payout-profiles?status=${encodeURIComponent(status)}`);
}

export function approvePayoutProfile(profileId: number, notes?: string) {
  return apiRequest<Record<string, unknown>>(`/admin/payout-profiles/${profileId}/approve`, {
    method: 'POST',
    body: { notes },
  });
}

export function rejectPayoutProfile(profileId: number, notes: string) {
  return apiRequest<Record<string, unknown>>(`/admin/payout-profiles/${profileId}/reject`, {
    method: 'POST',
    body: { notes },
  });
}

export function getAutoRenewPreview(query: { days?: number; limit?: number; include_overdue?: boolean } = {}) {
  const params = new URLSearchParams();
  if (query.days) params.set('days', String(query.days));
  if (query.limit) params.set('limit', String(query.limit));
  if (query.include_overdue !== undefined) params.set('include_overdue', query.include_overdue ? '1' : '0');
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<{ summary: { total_due_count: number; minimum_topup_required: number; [key: string]: unknown }; [key: string]: unknown }>(`/billing/wallet/auto-renew-preview${qs}`);
}
