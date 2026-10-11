import { apiRequest, buildQuery, publicApiRequest } from './client';

// POS › Log Audit: owner PIN + audit trail (backend PosActivityController, App\Services\Pos\Audit).
// Reading the log needs a fresh PIN unlock; the token goes back as X-Owner-Pin-Token.

export type PosOwnerPinStatus = {
  is_owner: boolean;
  pin_set: boolean;
  locked_until: string | null;
  reset_email: string | null;
};

export type PosActivitySeverity = 'info' | 'warning' | 'critical';

export type PosActivityLog = {
  id: number;
  occurred_at: string;
  action: string;
  action_label: string;
  severity: PosActivitySeverity;
  outlet: { id: number; name: string | null } | null;
  actor: { name: string | null; role: string | null; user_id: number | null; staff_id: number | null };
  target: { type: string | null; id: number | null; label: string | null };
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  reason: string | null;
  approver: { name: string; method: string | null } | null;
  ip_address: string | null;
  user_agent: string | null;
  device_id: string | null;
  session_ref: string | null;
  hash: string;
};

export type PosActivityPage = {
  from: string;
  to: string;
  logs: PosActivityLog[];
  pagination: { page: number; last_page: number; total: number };
  actions: Array<{ key: string; label: string }>;
  actors: Array<{ key: string; name: string; role: string | null }>;
  outlets: Array<{ id: number; name: string }>;
};

export type PosActivityFilters = {
  from?: string;
  to?: string;
  action?: string;
  severity?: string;
  actor?: string;
  outlet_id?: number | string;
  q?: string;
  page?: number;
};

export type PosAuditFlag = { type: string; severity: 'warning' | 'critical'; message: string };

export type PosAnomalyCashier = {
  name: string;
  role: string | null;
  transactions: number;
  voids: number;
  refunds: number;
  reprints: number;
  cash_closes: number;
  cash_short: number;
  cash_over: number;
  pin_failed: number;
  active_days: number;
  per_day: { transactions: number; voids: number; reprints: number };
  rates: { voids: number; refunds: number; reprints: number };
  flags: PosAuditFlag[];
};

export type PosAnomalyReport = {
  from: string;
  to: string;
  days: number;
  average: { voids: number; refunds: number; reprints: number };
  cashiers: PosAnomalyCashier[];
};

export type PosLogIntegrity = { ok: boolean; checked: number; broken_id: number | null; problem: string | null };

const pinHeader = (token: string) => ({ 'X-Owner-Pin-Token': token });

export function getPosOwnerPinStatus() {
  return apiRequest<PosOwnerPinStatus>('/pos/owner-pin');
}

export function createPosOwnerPin(pin: string, pinConfirmation: string) {
  return apiRequest<PosOwnerPinStatus>('/pos/owner-pin', { method: 'POST', body: { pin, pin_confirmation: pinConfirmation } });
}

export function changePosOwnerPin(currentPin: string, pin: string, pinConfirmation: string) {
  return apiRequest<PosOwnerPinStatus>('/pos/owner-pin/change', { method: 'POST', body: { current_pin: currentPin, pin, pin_confirmation: pinConfirmation } });
}

export function unlockPosAudit(pin: string) {
  return apiRequest<{ token: string; expires_at: string }>('/pos/owner-pin/unlock', { method: 'POST', body: { pin } });
}

export function requestPosOwnerPinReset() {
  return apiRequest<{ sent_to: string }>('/pos/owner-pin/forgot', { method: 'POST' });
}

export function getPosActivity(token: string, filters: PosActivityFilters = {}) {
  return apiRequest<PosActivityPage>(`/pos/activity${buildQuery(filters)}`, { headers: pinHeader(token) });
}

export function getPosAnomalies(token: string, filters: Pick<PosActivityFilters, 'from' | 'to' | 'outlet_id'> = {}) {
  return apiRequest<PosAnomalyReport>(`/pos/activity/anomalies${buildQuery(filters)}`, { headers: pinHeader(token) });
}

export function getPosLogIntegrity(token: string) {
  return apiRequest<PosLogIntegrity>('/pos/activity/integrity', { headers: pinHeader(token) });
}

/** Call right before printing: returns the print number and whether it is a draft (unpaid) receipt. */
export function recordPosReceiptPrint(orderId: number) {
  return apiRequest<{ print_no: number; kind: 'draft' | 'final' }>(`/pos/orders/${orderId}/receipt/printed`, { method: 'POST' });
}

export function getPosPinReset(token: string) {
  return publicApiRequest<{ organization: string; expires_at: string }>(`/public/pos-pin-reset/${encodeURIComponent(token)}`);
}

export function resetPosOwnerPin(token: string, pin: string, pinConfirmation: string) {
  return publicApiRequest<{ organization: string }>(`/public/pos-pin-reset/${encodeURIComponent(token)}`, { method: 'POST', body: { pin, pin_confirmation: pinConfirmation } });
}
