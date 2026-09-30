// HTTP client, session/token storage, active outlet, image URLs.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.

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
const ACTIVE_OUTLET_KEY = 'hellom_active_outlet_id';
const ACTIVE_OUTLET_EVENT_NAME = 'hellom-active-outlet-changed';

// Active POS outlet — sent as X-Outlet-Id on every authenticated request so the
// backend scopes POS data (orders, products, reports, staff…) to that outlet.
export function getActiveOutletId(): string | null {
  if (typeof window === 'undefined') return null;
  return window.localStorage.getItem(ACTIVE_OUTLET_KEY);
}

export function setActiveOutletId(outletId: string | number | null): void {
  if (typeof window === 'undefined') return;
  if (outletId === null || outletId === '') {
    window.localStorage.removeItem(ACTIVE_OUTLET_KEY);
  } else {
    window.localStorage.setItem(ACTIVE_OUTLET_KEY, String(outletId));
  }
  window.dispatchEvent(new CustomEvent(ACTIVE_OUTLET_EVENT_NAME));
}

export function getActiveOutletEventName(): string {
  return ACTIVE_OUTLET_EVENT_NAME;
}

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

// One affected cart line in an order error (price changed, out of stock, gone, ...).
export type ApiProblem = {
  index?: number;
  product_id?: number;
  name?: string;
  reason?: 'not_found' | 'unavailable' | 'stock' | 'price' | 'options' | string;
  message?: string;
  old_price?: number;
  new_price?: number;
  available?: number;
};

// Error thrown by apiRequest/publicApiRequest. Still an Error (message = server message),
// plus the HTTP status, the error code and cart problems when the server sent them.
export class ApiError extends Error {
  status: number;
  code: string | null;
  /** The whole `error` object of the envelope (extra fields such as `site_key`). */
  details: Record<string, unknown>;
  problems: ApiProblem[];
  data: unknown;
  // Laravel validation (422): field → messages, e.g. { "delivery_url": ["…"] }.
  fieldErrors: Record<string, string[]>;

  constructor(message: string, status: number, error: unknown, data: unknown, fieldErrors: Record<string, string[]> = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    const err = (error && typeof error === 'object' ? error : {}) as { code?: unknown; problems?: unknown };
    this.code = typeof err.code === 'string' ? err.code : null;
    this.details = err as Record<string, unknown>;
    this.problems = Array.isArray(err.problems) ? (err.problems as ApiProblem[]) : [];
    this.data = data;
    this.fieldErrors = fieldErrors;
  }
}

function toApiError(response: Response, payload: ApiEnvelope<unknown> | null): ApiError {
  const errors = (payload as { errors?: unknown } | null)?.errors;
  const fieldErrors = errors && typeof errors === 'object' ? (errors as Record<string, string[]>) : {};
  // Laravel's 422 message is "first error (and N more errors)": show the first error only.
  const firstField = Object.values(fieldErrors)[0]?.[0];
  const code = (payload?.error as { code?: unknown } | null | undefined)?.code;
  if (code === 'POS_PERMISSION_DENIED' && typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('hellom-pos-permission-denied'));
  }
  return new ApiError(firstField || payload?.message || `HTTP ${response.status}`, response.status, payload?.error ?? null, payload?.data ?? null, fieldErrors);
}

export async function apiRequest<T>(
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
  const activeOutletId = getActiveOutletId();

  const response = await fetch(`${HELLOM_API_BASE}${path}`, {
    method: options?.method ?? 'GET',
    headers: {
      Accept: 'application/json',
      ...(!isFormData && options?.body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(activeOutletId ? { 'X-Outlet-Id': activeOutletId } : {}),
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
    throw toApiError(response, payload);
  }

  return payload.data;
}

// "?a=1&b=2" from an object, skipping undefined/null/'' values ('' when empty).
export function buildQuery(params?: Record<string, string | number | boolean | null | undefined>): string {
  if (!params) return '';
  const entries = Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => [key, String(value)] as [string, string]);
  return entries.length ? `?${new URLSearchParams(entries).toString()}` : '';
}

// Authenticated GET for binary downloads (Excel exports, files). Sends the same
// token and active-outlet headers as apiRequest, but returns the raw Blob.
export async function apiRequestBlob(path: string): Promise<Blob> {
  const token = getToken();
  const activeOutletId = getActiveOutletId();

  const response = await fetch(`${HELLOM_API_BASE}${path}`, {
    method: 'GET',
    headers: {
      Accept: '*/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(activeOutletId ? { 'X-Outlet-Id': activeOutletId } : {}),
    },
  });

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as { message?: string } | null;
    throw new Error(payload?.message || `HTTP ${response.status}`);
  }

  return response.blob();
}

export async function publicApiRequest<T>(
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
    throw toApiError(response, payload);
  }

  return payload.data;
}

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

export interface PosAccess {
  is_cashier: boolean;
  pos_role?: 'admin' | 'cashier';
  permissions?: Record<string, boolean>;
  outlet_id?: number | null;
  outlet_name?: string | null;
  tenant_slug?: string | null;
}

/** POS access context for the logged-in user (cashier lock + assigned outlet). */
export function getSessionPosAccess(): PosAccess | null {
  const user = getSessionUser<{ pos_access?: PosAccess }>();
  return user?.pos_access ?? null;
}

/** True when the current account is a POS cashier locked to a single outlet. */
export function isPosCashier(): boolean {
  return getSessionPosAccess()?.is_cashier === true;
}

/**
 * Whether the signed-in user may use a POS feature. Owners/admins always may; cashiers only
 * what their owner/admin switched on (App\Support\Pos\PosPermissions). The API enforces the
 * same rule; this only hides what would be refused.
 */
export function canPos(permission: string): boolean {
  const access = getSessionPosAccess();
  if (!access?.is_cashier) return true;
  if (permission === 'orders') return true;
  return access.permissions?.[permission] === true;
}

/** Store fresh POS access (GET /pos/me/access) in the session; notifies session listeners. */
export function setSessionPosAccess(access: PosAccess): void {
  const user = getSessionUser<Record<string, unknown>>();
  if (!user) return;
  localStorage.setItem(USER_KEY, JSON.stringify({ ...user, pos_access: access }));
  emitSessionChanged();
}

/** Fired when the API refuses a POS feature (permissions changed) → POS reloads access. */
export const POS_PERMISSION_DENIED_EVENT = 'hellom-pos-permission-denied';

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
