// POS staff management (team, shifts, attendance, cash sessions). Requests carry
// X-Outlet-Id via apiRequest so each outlet keeps its own team.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest, apiRequestBlob, type PosAccess } from './client';

// Keep in sync with backend App\Support\Pos\PosPermissions::CATALOG.
export type PosStaffPermissionKey =
  | 'orders'
  | 'order_cancel'
  | 'order_refund'
  | 'tables'
  | 'products'
  | 'members'
  | 'member_points'
  | 'loyalty'
  | 'customer_hub'
  | 'reports'
  | 'cash_control'
  | 'outlet_settings';

/** One switchable POS feature (GET /pos/staff → meta.permissions). `locked` = always on. */
export type PosPermissionDef = {
  key: PosStaffPermissionKey;
  label: string;
  description: string;
  locked: boolean;
  default_cashier: boolean;
  default_admin: boolean;
};

export type PosStaffRole = 'admin' | 'cashier';
export type PosStaffEmploymentStatus = 'active' | 'inactive' | 'on_leave';

export type PosStaffShift = {
  id: number;
  staff_id: number;
  staff_name?: string | null;
  title: string;
  start_at: string;
  end_at: string;
  status: 'scheduled' | 'in_progress' | 'completed' | 'missed' | 'cancelled';
  reminder_minutes: number;
  notes?: string | null;
};

export type PosStaffAttendance = {
  id: number;
  staff_id: number;
  staff_name?: string | null;
  attendance_date: string;
  status: 'present' | 'late' | 'leave' | 'absent';
  late_minutes: number;
  check_in_at?: string | null;
  check_out_at?: string | null;
  checked_in: boolean;
  checked_out: boolean;
  check_in_method?: 'manual' | 'gps' | 'qr' | null;
  check_out_method?: 'manual' | 'gps' | 'qr' | null;
  location_label?: string | null;
  latitude?: number | null;
  longitude?: number | null;
  check_in_location_label?: string | null;
  check_in_latitude?: number | null;
  check_in_longitude?: number | null;
  check_out_location_label?: string | null;
  check_out_latitude?: number | null;
  check_out_longitude?: number | null;
  notes?: string | null;
};

export type PosStaffAttendanceQr = {
  token_preview: string;
  rotated_at?: string | null;
  payload: {
    type: 'pos_staff_attendance';
    version: number;
    tenant: string;
    staff_id: number;
    staff_name: string;
    token: string;
  };
  svg_data_uri?: string | null;
};

export type PosStaffCashLog = {
  id: number;
  staff_id: number;
  staff_name?: string | null;
  status: 'open' | 'closed';
  opening_cash: number;
  closing_cash?: number | null;
  expected_cash?: number | null;
  difference_cash?: number | null;
  total_cash_sales: number;
  total_transactions: number;
  started_at: string;
  closed_at?: string | null;
  notes?: string | null;
};

export type PosStaffItem = {
  id: number;
  name: string;
  email?: string | null;
  phone?: string | null;
  role: PosStaffRole;
  employment_status: PosStaffEmploymentStatus;
  permissions: Record<PosStaffPermissionKey, boolean>;
  hourly_rate: number;
  joined_at?: string | null;
  notes?: string | null;
  linked_user_id?: number | null;
  linked_user_name?: string | null;
  last_activity_at?: string | null;
  attendance_qr: PosStaffAttendanceQr;
  today_shift?: PosStaffShift | null;
  upcoming_shift?: PosStaffShift | null;
  today_attendance?: PosStaffAttendance | null;
  cash_session?: PosStaffCashLog | null;
  performance: {
    total_transactions: number;
    total_sales: number;
    work_minutes: number;
    late_count: number;
    leave_count: number;
    attendance_days: number;
  };
};

export type PosStaffDashboard = {
  summary: {
    total_staff: number;
    active_staff: number;
    checked_in_now: number;
    scheduled_today: number;
    open_cash_sessions: number;
    attendance_rate: number;
  };
  staff: PosStaffItem[];
  top_staff: PosStaffItem[];
  notifications: Array<{
    type: 'shift_reminder' | 'late_attendance' | 'shift_end';
    staff_id: number;
    message: string;
  }>;
  today: {
    date: string;
    shifts: PosStaffShift[];
    recent_attendances: PosStaffAttendance[];
    recent_cash_logs: PosStaffCashLog[];
  };
  current_user_staff_id?: number | null;
  meta: {
    roles: PosStaffRole[];
    permissions: PosPermissionDef[];
  };
};

export function getPosStaffDashboard() {
  return apiRequest<PosStaffDashboard>('/pos/staff');
}

/** The signed-in user's current POS access (cashier permissions change without re-login). */
export function getPosMyAccess() {
  return apiRequest<PosAccess>('/pos/me/access');
}

export function createPosStaff(payload: {
  name: string;
  email?: string;
  phone?: string;
  role: PosStaffRole;
  employment_status: PosStaffEmploymentStatus;
  permissions: Record<PosStaffPermissionKey, boolean>;
  hourly_rate?: number;
  joined_at?: string;
  notes?: string;
}) {
  return apiRequest<{ staff: PosStaffItem }>('/pos/staff', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosStaff(
  staffId: number,
  payload: {
    name: string;
    email?: string;
    phone?: string;
    role: PosStaffRole;
    employment_status: PosStaffEmploymentStatus;
    permissions: Record<PosStaffPermissionKey, boolean>;
    hourly_rate?: number;
    joined_at?: string;
    notes?: string;
  }
) {
  return apiRequest<{ staff: PosStaffItem }>(`/pos/staff/${staffId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function deletePosStaff(staffId: number) {
  return apiRequest<null>(`/pos/staff/${staffId}`, {
    method: 'DELETE',
  });
}

export function invitePosStaffLogin(
  staffId: number,
  payload?: { email?: string; expires_in_days?: number }
) {
  return apiRequest<{
    linked: boolean;
    invitation?: { id: number; email: string; role: string; status: string; expires_at: string | null };
    email_delivery?: unknown;
    staff: PosStaffItem;
  }>(`/pos/staff/${staffId}/invite-login`, {
    method: 'POST',
    body: payload || {},
  });
}

export function createPosStaffShift(payload: {
  staff_id: number;
  title: string;
  start_at: string;
  end_at: string;
  reminder_minutes?: number;
  notes?: string;
}) {
  return apiRequest<{ shift: PosStaffShift }>('/pos/staff/shifts', {
    method: 'POST',
    body: payload,
  });
}

export function updatePosStaffShift(
  shiftId: number,
  payload: {
    title: string;
    start_at: string;
    end_at: string;
    status?: PosStaffShift['status'];
    reminder_minutes?: number;
    notes?: string;
  }
) {
  return apiRequest<{ shift: PosStaffShift }>(`/pos/staff/shifts/${shiftId}`, {
    method: 'PUT',
    body: payload,
  });
}

export function checkInPosStaff(staffId: number, payload?: {
  method?: 'manual' | 'gps' | 'qr';
  location_label?: string;
  latitude?: number;
  longitude?: number;
  notes?: string;
}) {
  return apiRequest<{ attendance: PosStaffAttendance }>(`/pos/staff/${staffId}/attendance/check-in`, {
    method: 'POST',
    body: payload ?? {},
  });
}

export function checkOutPosStaff(staffId: number, payload?: {
  method?: 'manual' | 'gps' | 'qr';
  location_label?: string;
  latitude?: number;
  longitude?: number;
  notes?: string;
}) {
  return apiRequest<{ attendance: PosStaffAttendance }>(`/pos/staff/${staffId}/attendance/check-out`, {
    method: 'POST',
    body: payload ?? {},
  });
}

export function getPosStaffAttendanceQr(staffId: number) {
  return apiRequest<{ staff_id: number; staff_name: string; attendance_qr: PosStaffAttendanceQr }>(`/pos/staff/${staffId}/attendance-qr`);
}

export function regeneratePosStaffAttendanceQr(staffId: number) {
  return apiRequest<{ staff_id: number; staff_name: string; attendance_qr: PosStaffAttendanceQr }>(`/pos/staff/${staffId}/attendance-qr/regenerate`, {
    method: 'POST',
  });
}

export function scanPosStaffAttendanceQr(payload: {
  qr_content: string;
  action: 'check_in' | 'check_out';
  location_label?: string;
  latitude?: number;
  longitude?: number;
  notes?: string;
}) {
  return apiRequest<{ staff_id: number; staff_name: string; attendance: PosStaffAttendance }>('/pos/staff/attendance/scan', {
    method: 'POST',
    body: payload,
  });
}

export function markLeavePosStaff(staffId: number, payload?: {
  attendance_date?: string;
  notes?: string;
}) {
  return apiRequest<{ attendance: PosStaffAttendance }>(`/pos/staff/${staffId}/attendance/leave`, {
    method: 'POST',
    body: payload ?? {},
  });
}

export function openPosStaffCash(staffId: number, payload: {
  opening_cash: number;
  shift_id?: number;
  notes?: string;
}) {
  return apiRequest<{ cash_log: PosStaffCashLog }>(`/pos/staff/${staffId}/cash/open`, {
    method: 'POST',
    body: payload,
  });
}

export function closePosStaffCash(staffId: number, payload: {
  closing_cash: number;
  notes?: string;
}) {
  return apiRequest<{ cash_log: PosStaffCashLog }>(`/pos/staff/${staffId}/cash/close`, {
    method: 'POST',
    body: payload,
  });
}

export async function downloadPosStaffExport(type: 'attendance' | 'performance' | 'cash', month?: string) {
  const params = new URLSearchParams({ type, ...(month ? { month } : {}) });
  const blob = await apiRequestBlob(`/pos/staff/export/download?${params.toString()}`);
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `staff-${type}-${month || 'current'}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}

export type PosMyCash = {
  staff_id: number | null;
  open: (PosStaffCashLog & { live_transactions: number; live_cash_sales: number; live_expected_cash: number }) | null;
  last_closed: PosStaffCashLog | null;
};

/** The signed-in staff's cash drawer at the active outlet (cashier screen). */
export function getPosMyCash() {
  return apiRequest<PosMyCash>('/pos/me/cash');
}
