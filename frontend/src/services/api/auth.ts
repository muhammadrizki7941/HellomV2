// Authentication and profile.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { PosAccess, apiRequest } from './client';

// ─── Auth ───

export function login(email: string, password: string) {
  return apiRequest<{ token: string; user: unknown }>('/auth/login', {
    method: 'POST',
    body: { email, password },
    token: null,
  });
}

/** Staff/cashier login: straight into the store where the account is POS staff (409 = choose a store). */
export function staffLogin(email: string, password: string, staffId?: number) {
  return apiRequest<{ token: string; user: unknown }>('/auth/staff-login', {
    method: 'POST',
    body: { email, password, staff_id: staffId },
    token: null,
  });
}

export type StaffStoreChoice = { staff_id: number; organization_id: number; organization_name: string; outlet_name: string | null };

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

export function ssoLogin(ssoToken: string) {
  return apiRequest<{ token: string; user: unknown }>('/auth/sso-login', {
    method: 'POST',
    body: { sso_token: ssoToken },
    token: null,
  });
}

export function logout() {
  return apiRequest<null>('/auth/logout', {
    method: 'POST',
    body: {},
  });
}

// AuthController::userPayload (returned by /auth/me and /auth/profile).
export type HellomUser = {
  id: number;
  name: string;
  email: string;
  role: string;
  current_organization: { id: number; name: string; slug: string; status: string } | null;
  organizations: Array<{ id: number; name: string; slug: string; status: string; role: string }>;
  pos_access?: PosAccess;
};

export function getAuthMe() {
  return apiRequest<HellomUser>('/auth/me');
}

export function updateProfile(payload: { name?: string; email?: string; phone?: string }) {
  return apiRequest<HellomUser>('/auth/profile', {
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

export type PublicInvitation = {
  status: 'pending' | 'expired' | 'accepted' | 'revoked';
  email: string;
  role: string;
  role_label: string;
  organization_name: string;
  outlet_name: string | null;
  is_pos_staff: boolean;
  staff_name: string | null;
  has_account: boolean;
  expires_at: string | null;
};

/** Invitation page: who invited, and whether the invited email already has an account. */
export function getPublicInvitation(token: string) {
  return apiRequest<PublicInvitation>(`/public/invitations/${encodeURIComponent(token)}`, { token: null });
}

export function forgotPassword(email: string) {
  return apiRequest<{ email: string; sent: boolean }>('/auth/forgot-password', {
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
  // `next`: /login/kasir for POS staff, /login otherwise.
  return apiRequest<{ email: string; reset: boolean; next?: string }>('/auth/reset-password', {
    method: 'POST',
    body: payload,
    token: null,
  });
}
