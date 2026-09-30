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

export function forgotPassword(email: string) {
  // debug_reset_token is only returned when the backend runs with APP_ENV=local.
  return apiRequest<{ debug_reset_token?: string | null }>('/auth/forgot-password', {
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
