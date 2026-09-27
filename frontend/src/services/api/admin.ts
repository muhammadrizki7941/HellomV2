// Super admin: finance, gateways, users, apps/plans, promos, dashboard, notifications, mail.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest } from './client';

// ─── Admin Finance ───

export function getFinanceSummary(query: { days?: number } = {}) {
  const params = new URLSearchParams();
  if (query.days) params.set('days', String(query.days));
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<Record<string, unknown>>(`/platform/finance-summary${qs}`);
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

  return apiRequest<Record<string, unknown>>(`/wallet/admin/payout-queue${qs}`);
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

export function getAdminPaymentGatewayConfig() {
  return apiRequest<Record<string, unknown>>('/admin/billing/provider-config');
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

export function getAdminManualCheckouts() {
  return apiRequest<Record<string, unknown>>('/admin/billing/manual-checkouts');
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

export function getAdminOrganizations() {
  return apiRequest<Record<string, unknown>>('/admin/organizations');
}

export function getAdminUsers() {
  return apiRequest<Record<string, unknown>>('/admin/users');
}

export function getAdminUserDetail(userId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/users/${userId}`);
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
  return apiRequest<Record<string, unknown>>('/admin/plans');
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
  return apiRequest<Record<string, unknown>>(`/admin/plans/${planId}`, {
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

export function getAdminDashboardStats(query: { days?: number } = {}) {
  const params = new URLSearchParams();
  if (query.days) params.set('days', String(query.days));
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<Record<string, unknown>>(`/admin/dashboard-stats${qs}`);
}

// ─── Admin Notifications ───

export function getAdminNotifications(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return apiRequest<Record<string, unknown>>(`/admin/notifications${qs ? `?${qs}` : ''}`);
}

export function getAdminNotificationsUnreadCount() {
  return apiRequest<Record<string, unknown>>('/admin/notifications/unread-count');
}

export function markAdminNotificationAsRead(id: number) {
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

export function getOwnerNotificationDetail(id: number) {
  return apiRequest<Record<string, unknown>>(`/admin/notifications/${id}`);
}

export function executeOwnerNotificationAction(id: number) {
  return apiRequest<Record<string, unknown>>(`/admin/notifications/${id}/execute`, {
    method: 'POST',
    body: {},
  });
}

export function ignoreOwnerNotificationAction(id: number) {
  return apiRequest<Record<string, unknown>>(`/admin/notifications/${id}/ignore`, {
    method: 'POST',
    body: {},
  });
}

// ─── Admin Mail Settings ───

export function getAdminMailSettings() {
  return apiRequest<Record<string, unknown>>('/admin/mail-settings');
}

export function updateAdminMailSettings(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/mail-settings', {
    method: 'PUT',
    body: payload,
  });
}

export function sendAdminMailTest(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/mail-settings/test', {
    method: 'POST',
    body: payload,
  });
}
