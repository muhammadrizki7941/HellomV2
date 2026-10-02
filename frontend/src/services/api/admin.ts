// Super admin: finance, gateways, users, apps/plans, promos, dashboard, notifications, mail.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import type { WalletWithdrawal } from './billing';
import { apiRequest, buildQuery } from './client';

// ─── Types (SuperAdminController payloads) ───

type AppRef = { id: number; name: string; slug: string };
type PlanRef = { id: number; name: string; slug: string; type: string; price: number };

export type AdminUserListItem = {
  id: number;
  name: string;
  email: string;
  role: string;
  created_at: string;
  current_organization_id: number | null;
  current_organization?: { id: number; name: string; slug: string; status?: string } | null;
};

export type AdminPagination = { total: number; per_page: number; current_page: number; last_page: number };

export type AdminUserDetail = {
  id: number;
  name: string;
  email: string;
  role: string;
  created_at: string;
  current_organization: { id: number; name: string; slug: string; status: string } | null;
  organizations: Array<{
    id: number;
    name: string;
    slug: string;
    role: string;
    status: string;
    entitlements: Array<{ id: number; status: string; starts_at: string | null; ends_at: string | null; app: AppRef | null; plan: PlanRef | null }>;
    subscriptions: Array<{
      id: number;
      status: string;
      billing_cycle: string;
      amount: number;
      currency: string;
      starts_at: string | null;
      ends_at: string | null;
      created_at: string | null;
      app: AppRef | null;
      plan: PlanRef | null;
    }>;
  }>;
  product_purchases: Array<{
    id: number;
    transaction_code: string;
    amount_paid: number;
    payment_status: string;
    payment_method: string;
    payment_gateway: string;
    gateway_ref: string;
    checkout_url: string;
    paid_at: string | null;
    created_at: string | null;
    product: { id: number; slug: string; name: string; category: string } | null;
  }>;
};

// A plans row (Plan model attributes).
export type AdminPlan = {
  id: number;
  slug: string;
  name: string;
  type: string;
  price: number;
  is_active: boolean;
  description: string | null;
  features: unknown;
  billing_cycles: string[] | null;
  duration_days: number | null;
  max_outlets: number | null;
  is_visible: boolean;
  is_recommended: boolean;
  sort_order: number;
};

export type EmailDeliveryResult = { sent: boolean; error: string | null; mailer?: string };

// ─── Finance / organizations types ───

// Platform-wide finance (WalletController::platformFinanceSummary, super admin).
export type PlatformFinanceSummary = {
  range: { days: number; start_at: string; end_at: string };
  xendit_balance?: { available_balance: number; pending_balance: number; currency: string; captured_at: string | null };
  platform_revenue?: {
    total_revenue: number;
    revenue_count: number;
    by_category: Record<string, number>;
    withdrawable_revenue: number;
    pending_payouts: number;
  };
  user_deposits?: { total_deposits: number; active_users_count: number };
  organization_wallets?: { total_available: number; total_pending: number; total_inflow: number; total_outflow: number };
  platform_payouts?: {
    pending_count: number;
    processing_count: number;
    paid_count: number;
    failed_count: number;
    total_paid_amount: number;
    total_pending_amount: number;
  };
  user_withdrawals?: {
    pending_count: number;
    processing_count: number;
    paid_count: number;
    failed_count: number;
    total_pending_amount: number;
  };
};

// Super-admin payout queue (WalletController::adminPayoutQueue).
export type AdminPayoutQueueItem = WalletWithdrawal & {
  organization: { id: number; name: string; slug: string } | null;
  actions: { can_approve: boolean; can_reject: boolean; can_mark_paid: boolean; can_mark_failed: boolean; can_cancel: boolean };
};

export type AdminPayoutQueue = {
  organization: null;
  requester_role: string;
  pagination: { has_more: boolean; next_cursor: number | null };
  summary: { pending_count: number; processing_count: number; failed_count: number; paid_count: number };
  items: AdminPayoutQueueItem[];
};

// Manual (transfer) checkouts awaiting approval (BillingController::adminPendingCheckouts).
export type AdminManualCheckout = {
  id: number;
  intent_token: string;
  status: string;
  amount: number;
  currency: string;
  created_at: string;
  organization?: { id: number; name: string };
  user?: { id: number; name: string; email: string };
  app?: { slug: string; name: string };
  plan?: { slug: string; name: string };
};

// organizations row + users_count (SuperAdminController::listOrganizations).
export type AdminOrganizationListItem = {
  id: number;
  name: string;
  slug: string;
  status: string;
  users_count: number;
  created_at: string;
  max_outlets_override?: number | null;
  pos_tenant_slug?: string | null;
  default_locale?: string | null;
};

// ─── Admin Finance ───

export function getFinanceSummary(query: { days?: number } = {}) {
  const params = new URLSearchParams();
  if (query.days) params.set('days', String(query.days));
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<PlatformFinanceSummary>(`/platform/finance-summary${qs}`);
}

export function getPlatformFinanceSummary(query: { days?: number } = {}) {
  return getFinanceSummary(query);
}

export function createPlatformPayout(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/platform/payouts', {
    method: 'POST',
    body: payload,
  });
}

export function getAdminPayoutQueue(query: { status?: string; limit?: number; cursor?: number } = {}) {
  const params = new URLSearchParams();
  if (query.status) params.set('status', query.status);
  if (query.limit) params.set('limit', String(query.limit));
  if (query.cursor) params.set('cursor', String(query.cursor));
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<AdminPayoutQueue>(`/wallet/admin/payout-queue${qs}`);
}

export function approveWithdrawal(withdrawalId: number) {
  return apiRequest<Record<string, unknown>>(`/wallet/withdrawals/${withdrawalId}/approve`, {
    method: 'POST',
    body: {},
  });
}

export function rejectWithdrawal(withdrawalId: number, notes?: string) {
  return apiRequest<Record<string, unknown>>(`/wallet/withdrawals/${withdrawalId}/reject`, {
    method: 'POST',
    body: { notes },
  });
}

export function markWithdrawalPaid(withdrawalId: number, providerRef?: string, notes?: string) {
  return apiRequest<Record<string, unknown>>(`/wallet/withdrawals/${withdrawalId}/mark-paid`, {
    method: 'POST',
    body: { provider_ref: providerRef, notes },
  });
}

export function markWithdrawalFailed(withdrawalId: number, notes?: string) {
  return apiRequest<Record<string, unknown>>(`/wallet/withdrawals/${withdrawalId}/mark-failed`, {
    method: 'POST',
    body: { notes },
  });
}

// ─── Admin Runtime / Gateway ───

// GET /admin/billing/provider-config (BillingController::adminGatewayConfig).
export type GatewayProviderCard = {
  provider: string;
  mode: 'sandbox' | 'production';
  is_ready: boolean;
  webhook: { path: string; callback_token_configured: boolean };
  balance?: { currency: string; amount: number | null; error?: string } | null;
};

export type AdminPaymentGatewayConfig = {
  active_provider: 'xendit' | 'ipaymu' | 'doku';
  checkout_mode: 'manual_confirmation' | 'gateway_automatic';
  member_wallet_enabled: boolean;
  sale_commission_percent: number;
  guest_checkout_enabled?: boolean;
  providers: {
    xendit: GatewayProviderCard & {
      secret_key_masked: string | null;
      callback_token_masked: string | null;
      va_channels: string[];
    };
    ipaymu: GatewayProviderCard & {
      va_masked: string | null;
      api_key_masked: string | null;
      callback_token_masked: string | null;
      payment_methods?: string[];
      direct_channels?: string[];
      available_direct_channels?: Array<{ key: string; label: string; group: string }>;
    };
    doku: GatewayProviderCard & {
      client_id_masked: string | null;
      secret_key_masked: string | null;
      callback_token_masked: string | null;
      payment_method_types: string[];
    };
  };
  manual_payment: Record<string, unknown>;
};

export function getAdminPaymentGatewayConfig() {
  return apiRequest<AdminPaymentGatewayConfig>('/admin/billing/provider-config');
}

export function updateAdminPaymentGatewayConfig(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/billing/provider-config', {
    method: 'PUT',
    body: payload,
  });
}

export function resetIpaymuGatewayConfig() {
  return apiRequest<Record<string, unknown>>('/admin/billing/provider-config/ipaymu/reset', {
    method: 'POST',
    body: {},
  });
}

export function getAdminManualPaymentConfig() {
  return apiRequest<Record<string, unknown>>('/admin/billing/manual-payment-config');
}

export function updateAdminManualPaymentConfig(payload: FormData | Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/billing/manual-payment-config', {
    method: 'POST',
    body: payload,
  });
}

export function getAdminManualCheckouts(params?: { limit?: number }) {
  return apiRequest<{ items: AdminManualCheckout[] }>(`/admin/billing/manual-checkouts${buildQuery(params)}`);
}

export function approveAdminManualCheckout(intentId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/billing/manual-checkouts/${intentId}/approve`, {
    method: 'POST',
    body: {},
  });
}

export function rejectAdminManualCheckout(intentId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/billing/manual-checkouts/${intentId}/reject`, {
    method: 'POST',
    body: {},
  });
}

// ─── Admin Users & Organizations ───

export function getAdminOrganizations(params?: { search?: string; status?: string; limit?: number; page?: number }) {
  return apiRequest<{ items: AdminOrganizationListItem[]; pagination: AdminPagination }>(`/admin/organizations${buildQuery(params)}`);
}

export function getAdminUsers(params?: { search?: string; page?: number; limit?: number }) {
  return apiRequest<{ items: AdminUserListItem[]; pagination: AdminPagination }>(`/admin/users${buildQuery(params)}`);
}

export function getAdminUserDetail(userId: number) {
  return apiRequest<{ user: AdminUserDetail }>(`/admin/users/${userId}`);
}

export function suspendUser(userId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/users/${userId}/suspend`, {
    method: 'POST',
    body: {},
  });
}

export function reactivateUser(userId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/users/${userId}/reactivate`, {
    method: 'POST',
    body: {},
  });
}

export function deleteAdminUser(userId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/users/${userId}`, {
    method: 'DELETE',
  });
}

export function updateAdminUserAppAccess(userId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/users/${userId}/app-access`, {
    method: 'PUT',
    body: payload,
  });
}

export function overrideEntitlement(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/entitlements/override', {
    method: 'POST',
    body: payload,
  });
}

// ─── Admin Apps & Plans ───

export function getAdminApps() {
  return apiRequest<Record<string, unknown>>('/admin/apps');
}

export function updateAdminApp(appId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/apps/${appId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function getAdminPlans() {
  return apiRequest<{ items: AdminPlan[] }>('/admin/plans');
}

export function createAdminPlan(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/plans', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminPlan(planId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/plans/${planId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminPlan(planId: number) {
  // Plans with billing history are archived (archived: true) instead of deleted.
  return apiRequest<{ id: number; archived: boolean }>(`/admin/plans/${planId}`, {
    method: 'DELETE',
  });
}

// ─── Admin Promos ───

export function getAdminPromos() {
  return apiRequest<Record<string, unknown>>('/admin/promos');
}

export function createAdminPromo(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/promos', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminPromo(promoId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/promos/${promoId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminPromo(promoId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/promos/${promoId}`, {
    method: 'DELETE',
  });
}

export function validatePromoCode(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/promo/validate', {
    method: 'POST',
    body: payload,
  });
}

// ─── Admin Dashboard ───

// SuperAdminController::dashboardStats payload.
export type AdminDashboardStats = {
  period_days: number;
  organizations: { total: number; new_in_period: number };
  users: { total: number; new_in_period: number };
  subscriptions: { active: number; total: number };
  paid_entitlements: number;
  app_usage: Array<{ id: number; name: string; slug: string; active_count: number }>;
  growth: Array<{ date: string; users: number; organizations: number }>;
};

export function getAdminDashboardStats(query: { days?: number } = {}) {
  const params = new URLSearchParams();
  if (query.days) params.set('days', String(query.days));
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<AdminDashboardStats>(`/admin/dashboard-stats${qs}`);
}

// ─── Admin Notifications ───

// Owner (super admin) notification row (OwnerNotificationController).
export type OwnerNotification = {
  id: number;
  type: 'new_user' | 'new_transaction' | 'expiry_reminder';
  title: string;
  message: string;
  data: Record<string, unknown>;
  is_read: boolean;
  action_type?: string | null;
  action_url?: string | null;
  action_status?: 'pending' | 'done' | 'ignored' | null;
  action_done_at?: string | null;
  reference_id?: number | null;
  reference_type?: string | null;
  created_at: string;
  updated_at: string;
};

export function getAdminNotifications(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return apiRequest<{ data: OwnerNotification[]; meta: { current_page: number; per_page: number; total: number; last_page: number } }>(`/admin/notifications${qs ? `?${qs}` : ''}`);
}

export function getAdminNotificationsUnreadCount() {
  return apiRequest<{ count: number }>('/admin/notifications/unread-count');
}

export function markAdminNotificationAsRead(id: number | string) {
  return apiRequest<Record<string, unknown>>(`/admin/notifications/${id}/read`, {
    method: 'PATCH',
    body: {},
  });
}

export function markAllAdminNotificationsAsRead() {
  return apiRequest<Record<string, unknown>>('/admin/notifications/read-all', {
    method: 'PATCH',
    body: {},
  });
}

export function getOwnerNotificationDetail(id: number | string) {
  return apiRequest<OwnerNotification>(`/admin/notifications/${id}`);
}

export function executeOwnerNotificationAction(id: number | string) {
  return apiRequest<Record<string, unknown>>(`/admin/notifications/${id}/execute`, {
    method: 'POST',
    body: {},
  });
}

export function ignoreOwnerNotificationAction(id: number | string) {
  return apiRequest<Record<string, unknown>>(`/admin/notifications/${id}/ignore`, {
    method: 'POST',
    body: {},
  });
}

// ─── Admin Mail Settings ───

// Mail settings summary (PlatformMailService::publicSettingsSummary).
export type AdminMailSettings = {
  enabled: boolean;
  host: string;
  port: number;
  username: string;
  password_masked: string | null;
  encryption: string;
  from_address: string;
  from_name: string;
  reply_to_address: string;
  reply_to_name: string;
  is_ready: boolean;
};

export function getAdminMailSettings() {
  return apiRequest<{ mail: AdminMailSettings }>('/admin/mail-settings');
}

export function updateAdminMailSettings(payload: Record<string, unknown>) {
  return apiRequest<{ mail: AdminMailSettings }>('/admin/mail-settings', {
    method: 'PUT',
    body: payload,
  });
}

// Backend validates { email }; callers pass the recipient address.
export function sendAdminMailTest(email: string) {
  return apiRequest<{ delivery: EmailDeliveryResult }>('/admin/mail-settings/test', {
    method: 'POST',
    body: { email },
  });
}
