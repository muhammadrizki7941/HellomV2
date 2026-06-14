export const HELLOM_API_BASE =
  (import.meta as { env?: Record<string, string | undefined> }).env?.VITE_HELLOM_API_BASE ||
  'http://127.0.0.1:8000/api/v1/hellom';

export const HELLOM_REALTIME_PUBLIC_URL =
  (import.meta as { env?: Record<string, string | undefined> }).env?.VITE_REALTIME_PUBLIC_URL ||
  (() => {
    try {
      const apiUrl = new URL(HELLOM_API_BASE);
      return `${apiUrl.protocol}//${apiUrl.hostname}:3001`;
    } catch {
      return 'http://127.0.0.1:3001';
    }
  })();

const TOKEN_KEY = 'hellom_token';
const USER_KEY = 'hellom_user';
const LEGACY_TOKEN_KEY = 'token';
const LEGACY_USER_KEY = 'user';
const SESSION_EVENT_NAME = 'hellom-session-changed';

export const getImageUrl = (path: string | null | undefined): string => {
  if (!path) return '';
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:') || path.startsWith('blob:')) {
    return path;
  }

  const webBase = HELLOM_API_BASE.replace(/\/api\/v1\/hellom\/?$/, '');

  if (path.startsWith('/storage/')) {
    return `${webBase}${path}`;
  }
  if (path.startsWith('/media/')) {
    return `${webBase}${path}`;
  }
  if (path.startsWith('storage/')) {
    return `${webBase}/${path}`;
  }
  if (path.startsWith('media/')) {
    return `${webBase}/${path}`;
  }

  return `${webBase}/storage/${path}`;
};

type ApiEnvelope<T> = {
  success: boolean;
  message: string;
  data: T;
  error: unknown;
};

async function apiRequest<T>(
  path: string,
  options?: {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: unknown;
    token?: string | null;
    autoLogout?: boolean;
  }
): Promise<T> {
  const token = options?.token ?? getToken();
  const autoLogout = options?.autoLogout ?? (path === '/auth/me' || path === '/auth/logout');
  const isFormData = options?.body instanceof FormData;

  const response = await fetch(`${HELLOM_API_BASE}${path}`, {
    method: options?.method ?? 'GET',
    headers: {
      Accept: 'application/json',
      ...(!isFormData && options?.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: options?.body !== undefined
      ? (isFormData ? (options.body as BodyInit) : JSON.stringify(options.body))
      : undefined,
  });

  const payload = (await response.json().catch(() => null)) as ApiEnvelope<T> | null;

  if (response.status === 401 && token && autoLogout) {
    clearSession();
  }

  if (!response.ok || !payload || payload.success !== true) {
    const message = payload?.message || `HTTP ${response.status}`;
    throw new Error(message);
  }

  return payload.data;
}

async function publicApiRequest<T>(
  path: string,
  options?: {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: unknown;
  }
): Promise<T> {
  const isFormData = options?.body instanceof FormData;
  const response = await fetch(`${HELLOM_API_BASE}${path}`, {
    method: options?.method ?? 'GET',
    headers: {
      Accept: 'application/json',
      ...(!isFormData && options?.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
    },
    body: options?.body !== undefined
      ? (isFormData ? (options.body as BodyInit) : JSON.stringify(options.body))
      : undefined,
  });

  const payload = (await response.json().catch(() => null)) as ApiEnvelope<T> | null;

  if (!response.ok || !payload || payload.success !== true) {
    const message = payload?.message || `HTTP ${response.status}`;
    throw new Error(message);
  }

  return payload.data;
}

const api = {
  get<T>(path: string) {
    return apiRequest<T>(path);
  },
  post<T>(path: string, body: unknown = {}) {
    return apiRequest<T>(path, {
      method: 'POST',
      body,
    });
  },
  put<T>(path: string, body: unknown = {}) {
    return apiRequest<T>(path, {
      method: 'PUT',
      body,
    });
  },
  patch<T>(path: string, body: unknown = {}) {
    return apiRequest<T>(path, {
      method: 'PATCH',
      body,
    });
  },
  delete<T>(path: string) {
    return apiRequest<T>(path, {
      method: 'DELETE',
    });
  },
};

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

function emitSessionChanged(): void {
  if (typeof window === 'undefined') return;
  window.dispatchEvent(new CustomEvent(SESSION_EVENT_NAME));
}

export function setSession(token: string, user: unknown): void {
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(USER_KEY, JSON.stringify(user));
  localStorage.removeItem(LEGACY_TOKEN_KEY);
  localStorage.removeItem(LEGACY_USER_KEY);
  emitSessionChanged();
}

export function getSessionUser<T = unknown>(): T | null {
  const raw = localStorage.getItem(USER_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

export function clearSession(): void {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
  localStorage.removeItem(LEGACY_TOKEN_KEY);
  localStorage.removeItem(LEGACY_USER_KEY);
  emitSessionChanged();
}

export function getSessionEventName(): string {
  return SESSION_EVENT_NAME;
}

// ─── Auth ───

export function login(email: string, password: string) {
  return apiRequest<{ token: string; user: unknown }>('/auth/login', {
    method: 'POST',
    body: { email, password },
    token: null,
  });
}

export function register(payload: {
  name: string;
  email: string;
  password: string;
  organization_name?: string;
  invite_token?: string;
}) {
  return apiRequest<{ token: string; user: unknown }>('/auth/register', {
    method: 'POST',
    body: payload,
    token: null,
  });
}

export function ssoLogin(payload: { provider: string; token: string }) {
  return apiRequest<{ token: string; user: unknown }>('/auth/sso-login', {
    method: 'POST',
    body: payload,
    token: null,
  });
}

export function logout() {
  return apiRequest<null>('/auth/logout', {
    method: 'POST',
    body: {},
  });
}

export function getAuthMe() {
  return apiRequest<{
    id: number;
    name: string;
    email: string;
    role: string;
    current_organization: { id: number; name: string; slug: string; status: string } | null;
    organizations: Array<{ id: number; name: string; slug: string; status: string; role: string }>;
  }>('/auth/me');
}

export function updateProfile(payload: { name?: string; email?: string; phone?: string }) {
  return apiRequest<Record<string, unknown>>('/auth/profile', {
    method: 'PUT',
    body: payload,
  });
}

export function changePassword(payload: { current_password: string; password: string; password_confirmation: string }) {
  return apiRequest<Record<string, unknown>>('/auth/change-password', {
    method: 'POST',
    body: payload,
  });
}

export function forgotPassword(email: string) {
  return apiRequest<Record<string, unknown>>('/auth/forgot-password', {
    method: 'POST',
    body: { email },
    token: null,
  });
}

export function resetPassword(payload: {
  email: string;
  token: string;
  password: string;
  password_confirmation: string;
}) {
  return apiRequest<Record<string, unknown>>('/auth/reset-password', {
    method: 'POST',
    body: payload,
    token: null,
  });
}

// ─── Organizations ───

export function getOrganizations() {
  return apiRequest<Array<Record<string, unknown>>>('/organizations');
}

export function getCurrentOrganization() {
  return apiRequest<Record<string, unknown> | null>('/organizations/current');
}

export function switchOrganization(payload: { organization_id: number }) {
  return apiRequest<Record<string, unknown>>('/organizations/switch', {
    method: 'POST',
    body: payload,
  });
}

export function getOrganizationTeam() {
  return apiRequest<Record<string, unknown>>('/organizations/current/team');
}

export function createOrganizationInvitation(payload: { email: string; role?: string }) {
  return apiRequest<Record<string, unknown>>('/organizations/current/team/invite', {
    method: 'POST',
    body: payload,
  });
}

export function getOrganizationInvitations() {
  return apiRequest<Record<string, unknown>>('/organizations/current/team/invitations');
}

export function resendOrganizationInvitation(invitationId: number) {
  return apiRequest<Record<string, unknown>>(`/organizations/current/team/invitations/${invitationId}/resend`, {
    method: 'POST',
    body: {},
  });
}

export function revokeOrganizationInvitation(invitationId: number) {
  return apiRequest<Record<string, unknown>>(`/organizations/current/team/invitations/${invitationId}`, {
    method: 'DELETE',
  });
}

export function acceptOrganizationInvitation(payload: { token: string }) {
  return apiRequest<Record<string, unknown>>('/organizations/current/team/invitations/accept', {
    method: 'POST',
    body: payload,
  });
}

export function inviteOrganizationMember(payload: { email: string; role?: string }) {
  return createOrganizationInvitation(payload);
}

export function removeOrganizationMember(userId: number) {
  return apiRequest<Record<string, unknown>>(`/organizations/current/team/${userId}`, {
    method: 'DELETE',
  });
}

export function getOrganizationDetail(organizationId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/organizations/${organizationId}`);
}

// ─── Dashboard Cards ───

export function getMemberDashboardCards() {
  return apiRequest<{ cards: Array<Record<string, unknown>> }>('/member/dashboard/cards');
}

/** Poll until the given app slug becomes allowed (entitlement activated). */
export async function pollAppEntitlement(appSlug: string): Promise<boolean> {
  const res = await getMemberDashboardCards();
  const card = (res.cards || []).find((c: any) => c.app?.slug === appSlug) as any;
  return Boolean(card?.entitlement?.allowed);
}

/** Returns current wallet available balance for polling. */
export async function pollWalletBalance(): Promise<number> {
  const res = await getWalletOverview() as any;
  return Number(res?.wallet?.available_balance ?? 0);
}

// ─── Catalog & Pricing ───

export function getCatalogApps() {
  return apiRequest<Record<string, unknown>>('/catalog/apps');
}

export function getPricingMatrix() {
  return apiRequest<Record<string, unknown>>('/pricing/matrix');
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

export function getWalletOverview() {
  return apiRequest<Record<string, unknown>>('/wallet/overview');
}

export function getWalletTransactions(query: { limit?: number; cursor?: number; type?: string } = {}) {
  const params = new URLSearchParams();
  if (query.limit) params.set('limit', String(query.limit));
  if (query.cursor) params.set('cursor', String(query.cursor));
  if (query.type) params.set('type', query.type);
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<Record<string, unknown>>(`/wallet/transactions${qs}`);
}

export function createWalletTopupSession(payload: { amount: number; channel?: string }) {
  return apiRequest<Record<string, unknown>>('/billing/wallet/topup-session', {
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

  return apiRequest<Record<string, unknown>>(`/wallet/payout-policy${qs}`);
}

export function requestWithdrawal(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/wallet/withdrawals', {
    method: 'POST',
    body: payload,
  });
}

export function getAutoRenewPreview(query: { days?: number; limit?: number; include_overdue?: boolean } = {}) {
  const params = new URLSearchParams();
  if (query.days) params.set('days', String(query.days));
  if (query.limit) params.set('limit', String(query.limit));
  if (query.include_overdue !== undefined) params.set('include_overdue', query.include_overdue ? '1' : '0');
  const qs = params.toString() ? `?${params.toString()}` : '';

  return apiRequest<Record<string, unknown>>(`/billing/wallet/auto-renew-preview${qs}`);
}

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

// ─── Consumer Notifications ───

export function getConsumerNotifications() {
  return apiRequest<Record<string, unknown>>('/consumer/notifications');
}

export function getConsumerNotificationsUnreadCount() {
  return apiRequest<Record<string, unknown>>('/consumer/notifications/unread-count');
}

export function markConsumerNotificationAsRead(id: number) {
  return apiRequest<Record<string, unknown>>(`/consumer/notifications/${id}/read`, {
    method: 'POST',
    body: {},
  });
}

export function markAllConsumerNotificationsAsRead() {
  return apiRequest<Record<string, unknown>>('/consumer/notifications/read-all', {
    method: 'POST',
    body: {},
  });
}

// ─── Public Landing ───

export function getPublicLandingPage(organizationSlug: string, pageSlug: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/landing/${encodeURIComponent(organizationSlug)}/${encodeURIComponent(pageSlug)}`);
}

export function getPublicLandingPageByOrganization(organizationSlug: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/landingpage/${encodeURIComponent(organizationSlug)}`);
}

export function getPublicLandingByDomain(domain: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/landing/domain/${encodeURIComponent(domain)}`);
}

export function submitLandingCustomer(landingPageId: string | number, payload: Record<string, unknown>) {
  return publicApiRequest<Record<string, unknown>>(`/public/landing/${encodeURIComponent(String(landingPageId))}/customers`, {
    method: 'POST',
    body: payload,
  });
}

export function getLandingPageCustomers(pageId?: string | number) {
  const qs = pageId ? `?page_id=${encodeURIComponent(String(pageId))}` : '';
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/customers${qs}`);
}

export function getPublicBanners(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return publicApiRequest<{ items: Record<string, unknown>[] }>(`/public/banners${qs ? `?${qs}` : ''}`)
    .then((payload) => payload.items || []);
}

// ─── Landing Builder ───

export function getLandingPages() {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/pages');
}

export function createLandingPage(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/pages', {
    method: 'POST',
    body: payload,
  });
}

export function updateLandingPage(pageId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function publishLandingPage(pageId: number) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}/publish`, {
    method: 'POST',
    body: {},
  });
}

export function getLandingPageBlocks(pageId: number) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}/blocks`);
}

export function createLandingPageBlock(pageId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}/blocks`, {
    method: 'POST',
    body: payload,
  });
}

export function deleteLandingPageBlock(pageId: number, blockId: number) {
  return apiRequest<Record<string, unknown>>(`/apps/landing-builder/pages/${pageId}/blocks/${blockId}`, {
    method: 'DELETE',
  });
}

export function getLandingBuilderStats() {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/stats');
}

export function getLandingBuilderPageStats() {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/stats/pages');
}

export function getLandingBuilderPerformance() {
  return apiRequest<Record<string, unknown>>('/apps/landing-builder/stats/performance');
}

// ─── Public Products ───

export function getPublicProducts(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return publicApiRequest<Record<string, unknown>[]>(`/public/products${qs ? `?${qs}` : ''}`);
}

export function getPublicProductBySlug(slug: string) {
  return publicApiRequest<Record<string, unknown>>(`/public/products/${encodeURIComponent(slug)}`);
}

export function getProductCategories() {
  return publicApiRequest<Record<string, unknown>>('/public/products/categories');
}

// ─── Consumer Products ───

export function getConsumerProducts() {
  return apiRequest<Record<string, unknown>[]>('/consumer/products');
}

export function getConsumerProductBySlug(slug: string) {
  return apiRequest<Record<string, unknown>>(`/consumer/products/${encodeURIComponent(slug)}`);
}

export function purchaseProduct(id: string | number, payload: Record<string, unknown> = {}) {
  return apiRequest<Record<string, unknown>>(`/consumer/products/${id}/purchase`, {
    method: 'POST',
    body: payload,
  });
}

export function downloadProductFile(id: string | number, fileId: string | number) {
  return apiRequest<Record<string, unknown>>(`/consumer/products/${id}/download/${fileId}`, {
    method: 'POST',
    body: {},
  });
}

export function getMyPurchases() {
  return apiRequest<Record<string, unknown>[]>('/consumer/my-purchases');
}

// ─── Onboarding ───

export function getOnboardingTips() {
  return apiRequest<Record<string, unknown>>('/consumer/onboarding/tips');
}

export function dismissOnboarding() {
  return apiRequest<Record<string, unknown>>('/consumer/onboarding/dismiss', {
    method: 'POST',
    body: {},
  });
}

// ─── Admin Digital Products ───

export function getAdminProducts(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return apiRequest<Record<string, unknown>>(`/admin/digital-products${qs ? `?${qs}` : ''}`);
}

export function createProduct(data: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/digital-products', {
    method: 'POST',
    body: data,
  });
}

export function getAdminProductById(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}`);
}

export function updateProduct(id: string | number, data: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}`, {
    method: 'PUT',
    body: data,
  });
}

export function deleteProduct(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}`, {
    method: 'DELETE',
  });
}

export function publishProduct(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/publish`, {
    method: 'POST',
    body: {},
  });
}

export function unpublishProduct(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/unpublish`, {
    method: 'POST',
    body: {},
  });
}

export function uploadProductThumbnail(id: string | number, formData: FormData) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/thumbnail`, {
    method: 'POST',
    body: formData,
  });
}

export function uploadProductFile(id: string | number, formData: FormData) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/files`, {
    method: 'POST',
    body: formData,
  });
}

export function uploadProductDoc(id: string | number, data: Record<string, unknown> | FormData) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/${id}/docs`, {
    method: 'POST',
    body: data,
  });
}

export function deleteProductFile(fileId: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/files/${fileId}`, {
    method: 'DELETE',
  });
}

export function deleteProductDoc(docId: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/digital-products/docs/${docId}`, {
    method: 'DELETE',
  });
}

export async function fetchAuthorizedBlobUrl(path: string): Promise<string> {
  const token = getToken();
  const response = await fetch(`${HELLOM_API_BASE}${path}`, {
    method: 'GET',
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }

  const blob = await response.blob();
  return URL.createObjectURL(blob);
}

export function getAdminProductPurchases(params?: Record<string, string | number | boolean | undefined>) {
  const qs = params ? new URLSearchParams(
    Object.entries(params)
      .filter(([, value]) => value !== undefined)
      .map(([key, value]) => [key, String(value)])
  ).toString() : '';

  return apiRequest<Record<string, unknown>>(`/admin/product-purchases${qs ? `?${qs}` : ''}`);
}

export function approveProductPurchase(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/product-purchases/${id}/approve`, {
    method: 'POST',
    body: {},
  });
}

export function refundProductPurchase(id: string | number) {
  return apiRequest<Record<string, unknown>>(`/admin/product-purchases/${id}/refund`, {
    method: 'POST',
    body: {},
  });
}

// ─── POS ───

export function getPosAccess() {
  return apiRequest<Record<string, unknown>>('/apps/pos/access');
}

export function getPosTables() {
  return apiRequest<Record<string, unknown>>('/pos/tables');
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

export type PosExperienceDashboard = Record<string, unknown>;
export type PosExperiencePromo = Record<string, unknown>;
export type PosExperienceSpace = Record<string, unknown>;
export type PosExperienceReservation = Record<string, unknown>;

export function getPosExperienceDashboard() {
  return apiRequest<PosExperienceDashboard>('/pos/customer-experience/dashboard');
}

export function createPosExperiencePromo(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/customer-experience/promos', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosExperiencePromo(promoId: number, payload: Record<string, unknown>) {
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

export function createPosExperienceSpace(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/pos/customer-experience/spaces', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosExperienceSpace(spaceId: number, payload: Record<string, unknown>) {
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

export function getPosReportSummary() {
  return apiRequest<Record<string, unknown>>('/pos/reports/summary');
}

export function getPosReportProducts() {
  return apiRequest<Record<string, unknown>>('/pos/reports/products');
}

export function getPosReportDaily() {
  return apiRequest<Record<string, unknown>>('/pos/reports/daily');
}

export function exportPosReport() {
  return apiRequest<Record<string, unknown>>('/pos/reports/export');
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

// ─── Showcase ───

export type ShowcasePortfolio = Record<string, unknown>;
export type ShowcaseClient = Record<string, unknown>;
export type LandingContent = Record<string, unknown>;

export function getPublicShowcasePortfolios() {
  return publicApiRequest<{ items: Record<string, unknown>[] }>('/public/showcase/portfolios')
    .then((payload) => payload.items || []);
}

export function getPublicShowcaseClients() {
  return publicApiRequest<{ items: Record<string, unknown>[] }>('/public/showcase/clients')
    .then((payload) => payload.items || []);
}

export function getPublicLandingContent() {
  return publicApiRequest<LandingContent>('/public/landing-content');
}

export function getAdminLandingContent() {
  return apiRequest<LandingContent>('/admin/landing-content');
}

export function updateAdminLandingAbout(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/landing-content/about', {
    method: 'PUT',
    body: payload,
  });
}

export function createAdminLandingService(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/landing-content/services', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminLandingService(serviceId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/landing-content/services/${serviceId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminLandingService(serviceId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/landing-content/services/${serviceId}`, {
    method: 'DELETE',
  });
}

export function createAdminLandingArticle(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/landing-content/articles', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminLandingArticle(articleId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/landing-content/articles/${articleId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminLandingArticle(articleId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/landing-content/articles/${articleId}`, {
    method: 'DELETE',
  });
}

export function getAdminPortfolios() {
  return apiRequest<Record<string, unknown>>('/admin/showcase/portfolios');
}

export function createAdminPortfolio(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/showcase/portfolios', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminPortfolio(portfolioId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/showcase/portfolios/${portfolioId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminPortfolio(portfolioId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/showcase/portfolios/${portfolioId}`, {
    method: 'DELETE',
  });
}

export function getAdminClients() {
  return apiRequest<Record<string, unknown>>('/admin/showcase/clients');
}

export function createAdminClient(payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>('/admin/showcase/clients', {
    method: 'POST',
    body: payload,
  });
}

export function updateAdminClient(clientId: number, payload: Record<string, unknown>) {
  return apiRequest<Record<string, unknown>>(`/admin/showcase/clients/${clientId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deleteAdminClient(clientId: number) {
  return apiRequest<Record<string, unknown>>(`/admin/showcase/clients/${clientId}`, {
    method: 'DELETE',
  });
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

export function uploadShowcaseMedia(payload: FormData | File) {
  const body = payload instanceof File
    ? (() => {
        const formData = new FormData();
        formData.append('file', payload);
        return formData;
      })()
    : payload;

  return apiRequest<Record<string, unknown>>('/admin/showcase/upload-media', {
    method: 'POST',
    body,
  });
}
