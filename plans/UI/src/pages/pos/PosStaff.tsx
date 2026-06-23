import { useEffect, useRef, useState, type ComponentType, type FormEvent, type ReactNode } from 'react';
import {
  Bell,
  BriefcaseBusiness,
  CalendarClock,
  Camera,
  CheckCircle2,
  Clock3,
  Download,
  Edit2,
  Mail,
  MapPin,
  Plus,
  QrCode,
  ReceiptText,
  RefreshCcw,
  Search,
  ShieldCheck,
  Trash2,
  UserCheck,
  UserRound,
  Wallet,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  checkInPosStaff,
  checkOutPosStaff,
  closePosStaffCash,
  createPosStaff,
  createPosStaffShift,
  deletePosStaff,
  invitePosStaffLogin,
  downloadPosStaffExport,
  getPosStaffDashboard,
  markLeavePosStaff,
  openPosStaffCash,
  type PosStaffDashboard,
  type PosStaffEmploymentStatus,
  type PosStaffItem,
  type PosStaffPermissionKey,
  type PosStaffAttendanceQr,
  type PosStaffRole,
  type PosStaffShift,
  getPosStaffAttendanceQr,
  regeneratePosStaffAttendanceQr,
  scanPosStaffAttendanceQr,
  updatePosStaff,
  updatePosStaffShift,
} from '@/lib/pos/staffApi';
import { getPosOutlets, getActiveOutletId, setActiveOutletId, type PosOutlet } from '@/lib/hellomApi';

type ScanMode = 'check_in' | 'check_out';

type ScannerLocationState = {
  label: string;
  latitude?: number;
  longitude?: number;
  accuracy?: number;
  granted: boolean;
};

type BarcodeDetectorLike = {
  detect: (source: ImageBitmapSource) => Promise<Array<{ rawValue?: string }>>;
};

declare global {
  interface Window {
    BarcodeDetector?: {
      new (options: { formats: string[] }): BarcodeDetectorLike;
      getSupportedFormats?: () => Promise<string[]>;
    };
  }
}

const defaultPermissions: Record<PosStaffRole, Record<PosStaffPermissionKey, boolean>> = {
  admin: {
    transactions: true,
    reports: true,
    products: true,
    orders: true,
    cash_control: true,
  },
  cashier: {
    transactions: true,
    reports: false,
    products: false,
    orders: true,
    cash_control: true,
  },
};

const initialStaffForm = {
  name: '',
  email: '',
  phone: '',
  role: 'cashier' as PosStaffRole,
  employment_status: 'active' as PosStaffEmploymentStatus,
  permissions: { ...defaultPermissions.cashier },
  hourly_rate: 0,
  joined_at: '',
  notes: '',
};

const initialShiftForm = {
  staff_id: 0,
  title: 'Shift Pagi',
  start_at: '',
  end_at: '',
  reminder_minutes: 30,
  notes: '',
};

const currencyFormatter = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  minimumFractionDigits: 0,
});

const compactNumberFormatter = new Intl.NumberFormat('id-ID', {
  notation: 'compact',
  maximumFractionDigits: 1,
});

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
});

const dateTimeFormatter = new Intl.DateTimeFormat('id-ID', {
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
});

function formatCurrency(value: number) {
  return currencyFormatter.format(value || 0);
}

function formatCompactNumber(value: number) {
  return compactNumberFormatter.format(value || 0);
}

function formatDate(value?: string | null) {
  if (!value) return '-';
  return dateFormatter.format(new Date(value));
}

function formatDateTime(value?: string | null) {
  if (!value) return '-';
  return dateTimeFormatter.format(new Date(value));
}

function formatCoordinatePair(latitude?: number | null, longitude?: number | null) {
  if (typeof latitude !== 'number' || typeof longitude !== 'number') return null;
  return `${latitude.toFixed(6)}, ${longitude.toFixed(6)}`;
}

function formatHours(minutes: number) {
  if (!minutes) return '0 jam';
  const hours = minutes / 60;
  return `${hours.toFixed(hours >= 10 ? 0 : 1)} jam`;
}

function toDateInput(value?: string | null) {
  if (!value) return '';
  return value.slice(0, 10);
}

function toDateTimeInput(value?: string | null) {
  if (!value) return '';
  const date = new Date(value);
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hour = String(date.getHours()).padStart(2, '0');
  const minute = String(date.getMinutes()).padStart(2, '0');
  return `${year}-${month}-${day}T${hour}:${minute}`;
}

function roleLabel(role: PosStaffRole) {
  if (role === 'admin') return 'Admin';
  return 'Kasir';
}

function statusLabel(status: PosStaffEmploymentStatus) {
  if (status === 'active') return 'Aktif';
  if (status === 'inactive') return 'Nonaktif';
  return 'Izin';
}

function shiftStatusLabel(status: PosStaffShift['status']) {
  if (status === 'scheduled') return 'Terjadwal';
  if (status === 'in_progress') return 'Berjalan';
  if (status === 'completed') return 'Selesai';
  if (status === 'missed') return 'Terlewat';
  return 'Batal';
}

const inputClass =
  'w-full rounded-2xl border border-[#eadfbe] bg-[#fffdf7] px-4 py-3 text-sm text-[#1d1914] outline-none transition focus:border-amber-300 focus:ring-2 focus:ring-amber-100';

export default function PosStaff() {
  const [dashboard, setDashboard] = useState<PosStaffDashboard | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [showStaffModal, setShowStaffModal] = useState(false);
  const [showShiftModal, setShowShiftModal] = useState(false);
  const [showQrModal, setShowQrModal] = useState(false);
  const [showScannerModal, setShowScannerModal] = useState(false);
  const [editingStaff, setEditingStaff] = useState<PosStaffItem | null>(null);
  const [editingShift, setEditingShift] = useState<PosStaffShift | null>(null);
  const [staffForm, setStaffForm] = useState(initialStaffForm);
  const [shiftForm, setShiftForm] = useState(initialShiftForm);
  const [selectedQrStaff, setSelectedQrStaff] = useState<PosStaffItem | null>(null);
  const [selectedQr, setSelectedQr] = useState<PosStaffAttendanceQr | null>(null);
  const [scanMode, setScanMode] = useState<ScanMode>('check_in');
  const [scanMessage, setScanMessage] = useState<string | null>(null);
  const [scanError, setScanError] = useState<string | null>(null);
  const [scannerLocation, setScannerLocation] = useState<ScannerLocationState>({ label: '', granted: false });
  const [scannerLocationDraft, setScannerLocationDraft] = useState('');
  const [lastScannedStaff, setLastScannedStaff] = useState<string | null>(null);

  const scannerVideoRef = useRef<HTMLVideoElement | null>(null);
  const scannerCanvasRef = useRef<HTMLCanvasElement | null>(null);
  const scannerStreamRef = useRef<MediaStream | null>(null);
  const scanLoopRef = useRef<number | null>(null);
  const scanLockRef = useRef(false);

  const currentMonth = new Date().toISOString().slice(0, 7);

  const [outlets, setOutlets] = useState<PosOutlet[]>([]);
  const activeOutletId = getActiveOutletId();
  // What the backend effectively scopes to: the active outlet, else the primary.
  const selectedOutletValue =
    activeOutletId ?? String(outlets.find((o) => o.is_primary)?.id ?? outlets[0]?.id ?? '');

  useEffect(() => {
    void loadDashboard();
    getPosOutlets()
      .then((res) => setOutlets(res.outlets || []))
      .catch(() => setOutlets([]));
  }, []);

  // Switching the managed outlet re-scopes the whole page (list + new staff) via
  // the X-Outlet-Id header, so each outlet keeps its own team.
  function handleOutletChange(outletId: string) {
    if (!outletId || outletId === (activeOutletId ?? '')) return;
    setActiveOutletId(outletId);
    window.location.reload();
  }

  useEffect(() => {
    if (!showScannerModal) {
      stopScanner();
      setScanMessage(null);
      setScanError(null);
      setLastScannedStaff(null);
      return;
    }

    void startScanner();

    return () => {
      stopScanner();
    };
  }, [showScannerModal, scanMode]);

  const visibleStaff = (dashboard?.staff || []).filter((member) => {
    const q = searchTerm.toLowerCase().trim();
    if (!q) return true;
    return (
      member.name.toLowerCase().includes(q) ||
      (member.email || '').toLowerCase().includes(q) ||
      (member.phone || '').toLowerCase().includes(q) ||
      roleLabel(member.role).toLowerCase().includes(q)
    );
  });

  async function loadDashboard() {
    try {
      setLoading(true);
      setError(null);
      const payload = await getPosStaffDashboard();
      setDashboard(payload);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Gagal memuat data staff');
    } finally {
      setLoading(false);
    }
  }

  function stopScanner() {
    if (scanLoopRef.current !== null) {
      window.clearInterval(scanLoopRef.current);
      scanLoopRef.current = null;
    }

    if (scannerStreamRef.current) {
      scannerStreamRef.current.getTracks().forEach((track) => track.stop());
      scannerStreamRef.current = null;
    }

    if (scannerVideoRef.current) {
      scannerVideoRef.current.srcObject = null;
    }

    scanLockRef.current = false;
  }

  async function startScanner() {
    if (!window.isSecureContext && window.location.hostname !== '127.0.0.1' && window.location.hostname !== 'localhost') {
      setScanError('Browser memblokir akses kamera di konteks yang tidak aman.');
      return;
    }

    setScanError(null);
    setScanMessage('Mengaktifkan kamera admin...');
    await requestScannerLocation();

    if (!window.BarcodeDetector) {
      setScanError('Browser ini belum mendukung BarcodeDetector untuk QR. Gunakan Chrome/Edge terbaru di device admin.');
      return;
    }

    const stream = await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: { ideal: 'environment' },
      },
      audio: false,
    });

    scannerStreamRef.current = stream;

    const video = scannerVideoRef.current;
    if (!video) return;

    video.srcObject = stream;
    video.setAttribute('playsinline', 'true');
    await video.play();

    const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
    setScanMessage('Arahkan kamera admin ke QR staff.');

    scanLoopRef.current = window.setInterval(async () => {
      if (scanLockRef.current || !video.videoWidth || !video.videoHeight) return;

      const canvas = scannerCanvasRef.current;
      if (!canvas) return;

      const context = canvas.getContext('2d');
      if (!context) return;

      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      context.drawImage(video, 0, 0, canvas.width, canvas.height);

      try {
        const codes = await detector.detect(canvas);
        const rawValue = codes[0]?.rawValue?.trim();
        if (!rawValue) return;

        scanLockRef.current = true;
        setScanMessage('QR terbaca, menyimpan absensi...');
        await handleQrScan(rawValue);
      } catch (err) {
        setScanError(err instanceof Error ? err.message : 'Gagal membaca QR dari kamera.');
      }
    }, 900);
  }

  async function requestScannerLocation() {
    if (!navigator.geolocation) {
      setScannerLocation((prev) => ({ ...prev, granted: false }));
      return;
    }

    try {
      const position = await new Promise<GeolocationPosition>((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(resolve, reject, {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 120000,
        });
      });

      setScannerLocation((prev) => ({
        ...prev,
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: position.coords.accuracy,
        granted: true,
      }));
    } catch {
      setScannerLocation((prev) => ({ ...prev, granted: false }));
    }
  }

  async function openQrModal(member: PosStaffItem) {
    setSelectedQrStaff(member);
    setSelectedQr(member.attendance_qr?.svg_data_uri ? member.attendance_qr : null);
    setShowQrModal(true);
    setScanError(null);

    try {
      const payload = await getPosStaffAttendanceQr(member.id);
      setSelectedQr(payload.attendance_qr);
    } catch (err) {
      setScanError(err instanceof Error ? err.message : 'Gagal memuat QR staff');
    }
  }

  async function handleRegenerateQr() {
    if (!selectedQrStaff) return;
    if (!confirm(`Regenerasi QR ${selectedQrStaff.name}? QR lama akan langsung tidak berlaku.`)) return;

    await withBusy(`regen-qr-${selectedQrStaff.id}`, async () => {
      const payload = await regeneratePosStaffAttendanceQr(selectedQrStaff.id);
      setSelectedQr(payload.attendance_qr);
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal regenerasi QR');
    });
  }

  function openScanner(mode: ScanMode) {
    setScanMode(mode);
    setScannerLocation((prev) => ({ ...prev, label: scannerLocationDraft }));
    setScanError(null);
    setScanMessage(null);
    setShowScannerModal(true);
  }

  async function handleQrScan(rawValue: string) {
    try {
      const payload = await scanPosStaffAttendanceQr({
        qr_content: rawValue,
        action: scanMode,
        location_label: scannerLocationDraft.trim() || scannerLocation.label || undefined,
        latitude: scannerLocation.latitude,
        longitude: scannerLocation.longitude,
      });

      setLastScannedStaff(payload.staff_name);
      setScanMessage(`${payload.staff_name} berhasil ${scanMode === 'check_in' ? 'check-in' : 'check-out'}.`);
      setScanError(null);
      await loadDashboard();
      window.setTimeout(() => {
        setShowScannerModal(false);
      }, 1200);
    } catch (err) {
      scanLockRef.current = false;
      setScanError(err instanceof Error ? err.message : 'Gagal memproses scan QR');
      setScanMessage(null);
    }
  }

  function downloadQrCard() {
    if (!selectedQr || !selectedQrStaff) return;
    const link = document.createElement('a');
    link.href = selectedQr.svg_data_uri;
    link.download = `attendance-qr-${selectedQrStaff.name.toLowerCase().replace(/\s+/g, '-')}.svg`;
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  function openCreateStaffModal() {
    setEditingStaff(null);
    setStaffForm(initialStaffForm);
    setShowStaffModal(true);
  }

  function openEditStaffModal(member: PosStaffItem) {
    setEditingStaff(member);
    setStaffForm({
      name: member.name,
      email: member.email || '',
      phone: member.phone || '',
      role: member.role,
      employment_status: member.employment_status,
      permissions: { ...member.permissions },
      hourly_rate: member.hourly_rate || 0,
      joined_at: toDateInput(member.joined_at),
      notes: member.notes || '',
    });
    setShowStaffModal(true);
  }

  function openCreateShiftModal(staffId?: number) {
    setEditingShift(null);
    setShiftForm({
      ...initialShiftForm,
      staff_id: staffId || dashboard?.staff[0]?.id || 0,
    });
    setShowShiftModal(true);
  }

  function openEditShiftModal(shift: PosStaffShift) {
    setEditingShift(shift);
    setShiftForm({
      staff_id: shift.staff_id,
      title: shift.title,
      start_at: toDateTimeInput(shift.start_at),
      end_at: toDateTimeInput(shift.end_at),
      reminder_minutes: shift.reminder_minutes,
      notes: shift.notes || '',
    });
    setShowShiftModal(true);
  }

  async function withBusy<T>(key: string, action: () => Promise<T>) {
    try {
      setBusyKey(key);
      return await action();
    } finally {
      setBusyKey(null);
    }
  }

  async function handleSaveStaff(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await withBusy('save-staff', async () => {
      const payload = {
        name: staffForm.name.trim(),
        email: staffForm.email.trim() || undefined,
        phone: staffForm.phone.trim() || undefined,
        role: staffForm.role,
        employment_status: staffForm.employment_status,
        permissions: staffForm.permissions,
        hourly_rate: Number(staffForm.hourly_rate) || 0,
        joined_at: staffForm.joined_at || undefined,
        notes: staffForm.notes.trim() || undefined,
      };

      if (editingStaff) {
        await updatePosStaff(editingStaff.id, payload);
      } else {
        await createPosStaff(payload);
      }

      setShowStaffModal(false);
      setStaffForm(initialStaffForm);
      setEditingStaff(null);
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal menyimpan staff');
    });
  }

  async function handleSaveShift(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await withBusy('save-shift', async () => {
      const payload = {
        staff_id: Number(shiftForm.staff_id),
        title: shiftForm.title.trim() || 'Shift',
        start_at: shiftForm.start_at,
        end_at: shiftForm.end_at,
        reminder_minutes: Number(shiftForm.reminder_minutes) || 30,
        notes: shiftForm.notes.trim() || undefined,
      };

      if (editingShift) {
        await updatePosStaffShift(editingShift.id, {
          title: payload.title,
          start_at: payload.start_at,
          end_at: payload.end_at,
          reminder_minutes: payload.reminder_minutes,
          notes: payload.notes,
        });
      } else {
        await createPosStaffShift(payload);
      }

      setShowShiftModal(false);
      setEditingShift(null);
      setShiftForm(initialShiftForm);
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal menyimpan shift');
    });
  }

  async function handleDeleteStaff(member: PosStaffItem) {
    if (!confirm(`Hapus ${member.name} dari daftar staff?`)) return;
    await withBusy(`delete-${member.id}`, async () => {
      await deletePosStaff(member.id);
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal menghapus staff');
    });
  }

  async function handleInviteLogin(member: PosStaffItem) {
    if (member.linked_user_id) {
      alert(`${member.name} sudah punya akun login (${member.linked_user_name || 'tertaut'}).`);
      return;
    }

    let email = member.email || '';
    if (!email) {
      email = (prompt(`Email untuk undangan login ${member.name}:`) || '').trim();
      if (!email) return;
    }

    await withBusy(`invite-${member.id}`, async () => {
      const res = await invitePosStaffLogin(member.id, { email });
      if (res.linked) {
        alert(`Akun ${email} sudah jadi anggota organisasi dan langsung ditautkan ke ${member.name}.`);
      } else {
        alert(`Undangan login dikirim ke ${email}. Kasir tinggal set password lewat link, lalu langsung masuk POS outlet ini.`);
      }
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal mengirim undangan login');
    });
  }

  async function handleCheckIn(member: PosStaffItem) {
    const locationLabel = prompt('Lokasi check-in (opsional). Contoh: Kasir depan / QR meja depan') || undefined;
    await withBusy(`checkin-${member.id}`, async () => {
      await checkInPosStaff(member.id, {
        method: 'manual',
        location_label: locationLabel,
      });
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal check-in');
    });
  }

  async function handleCheckOut(member: PosStaffItem) {
    const notes = prompt('Catatan check-out (opsional)') || undefined;
    await withBusy(`checkout-${member.id}`, async () => {
      await checkOutPosStaff(member.id, { method: 'manual', notes });
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal check-out');
    });
  }

  async function handleMarkLeave(member: PosStaffItem) {
    if (!confirm(`Tandai ${member.name} sebagai izin hari ini?`)) return;
    await withBusy(`leave-${member.id}`, async () => {
      await markLeavePosStaff(member.id, { notes: 'Diinput dari panel owner/admin' });
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal menandai izin');
    });
  }

  async function handleOpenCash(member: PosStaffItem) {
    const value = prompt(`Kas awal untuk ${member.name}`, '0');
    if (value === null) return;
    const openingCash = Number(value);
    if (Number.isNaN(openingCash) || openingCash < 0) {
      alert('Kas awal harus berupa angka valid');
      return;
    }

    await withBusy(`cash-open-${member.id}`, async () => {
      await openPosStaffCash(member.id, {
        opening_cash: openingCash,
        shift_id: member.today_shift?.id,
        notes: 'Dibuka dari panel staff management',
      });
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal membuka kas shift');
    });
  }

  async function handleCloseCash(member: PosStaffItem) {
    const value = prompt(`Kas akhir untuk ${member.name}`, String(member.cash_session?.opening_cash || 0));
    if (value === null) return;
    const closingCash = Number(value);
    if (Number.isNaN(closingCash) || closingCash < 0) {
      alert('Kas akhir harus berupa angka valid');
      return;
    }

    await withBusy(`cash-close-${member.id}`, async () => {
      await closePosStaffCash(member.id, {
        closing_cash: closingCash,
        notes: 'Ditutup dari panel staff management',
      });
      await loadDashboard();
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal menutup kas shift');
    });
  }

  async function handleExport(type: 'attendance' | 'performance' | 'cash') {
    await withBusy(`export-${type}`, async () => {
      await downloadPosStaffExport(type, currentMonth);
    }).catch((err) => {
      alert(err instanceof Error ? err.message : 'Gagal export data');
    });
  }

  function togglePermission(key: PosStaffPermissionKey) {
    setStaffForm((prev) => ({
      ...prev,
      permissions: {
        ...prev.permissions,
        [key]: !prev.permissions[key],
      },
    }));
  }

  function applyRolePreset(role: PosStaffRole) {
    setStaffForm((prev) => ({
      ...prev,
      role,
      permissions: { ...defaultPermissions[role] },
    }));
  }

  const myStaff = dashboard?.staff.find((member) => member.id === dashboard.current_user_staff_id) || null;

  return (
    <div className="min-h-screen bg-[#f7f4ea]">
      <div className="mx-auto max-w-7xl space-y-6">
        <section className="overflow-hidden rounded-[28px] border border-[#eadfbe] bg-[radial-gradient(circle_at_top_left,_rgba(251,191,36,0.22),_transparent_38%),linear-gradient(135deg,#fff8df_0%,#fffef9_54%,#f4efe3_100%)] p-6 shadow-[0_18px_50px_rgba(17,17,17,0.08)]">
          <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div className="max-w-3xl space-y-3">
              <span className="inline-flex items-center gap-2 rounded-full border border-[#e9d9a0] bg-white/75 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-[#8b6f18]">
                <ShieldCheck className="h-3.5 w-3.5" />
                Staff Management POS
              </span>
              <div>
                <h1 className="text-3xl font-bold tracking-tight text-[#181512] sm:text-4xl">Kelola staff, shift, absensi, dan kas tanpa ribet.</h1>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-[#6a6255] sm:text-base">
                  Satu panel ringan untuk owner dan admin: atur role, pantau shift hari ini, cek performa kasir, dan rekap absensi bulanan yang siap dibuka di Excel.
                </p>
              </div>

              {outlets.length > 1 && (
                <div className="flex flex-col gap-1 rounded-2xl border border-[#e6d8b2] bg-white/70 p-3 sm:max-w-md">
                  <label className="text-xs font-semibold uppercase tracking-wide text-[#8b6f18]">Outlet yang dikelola</label>
                  <select
                    value={selectedOutletValue}
                    onChange={(event) => handleOutletChange(event.target.value)}
                    className="rounded-xl border border-[#eadfbe] bg-white px-3 py-2 text-sm font-semibold text-[#1d1914] outline-none focus:border-amber-300 focus:ring-2 focus:ring-amber-100"
                  >
                    {outlets.map((outlet) => (
                      <option key={outlet.id} value={String(outlet.id)}>
                        {outlet.name}{outlet.is_primary ? ' (Utama)' : ''}
                      </option>
                    ))}
                  </select>
                  <p className="text-xs text-[#8d806c]">Staff & undangan login yang dibuat di sini terikat ke outlet ini. Pindah outlet untuk kelola timnya masing-masing.</p>
                </div>
              )}
            </div>

            <div className="flex flex-wrap gap-3">
              <button
                onClick={() => openScanner('check_in')}
                className="inline-flex items-center gap-2 rounded-2xl border border-[#d5c089] bg-[#fff7dd] px-4 py-3 text-sm font-semibold text-[#5a4310] transition hover:-translate-y-0.5 hover:shadow-md"
              >
                <Camera className="h-4 w-4" />
                Scan QR Check-in
              </button>
              <button
                onClick={() => openScanner('check_out')}
                className="inline-flex items-center gap-2 rounded-2xl border border-[#e6d8b2] bg-white px-4 py-3 text-sm font-semibold text-[#2e2820] transition hover:-translate-y-0.5 hover:shadow-md"
              >
                <QrCode className="h-4 w-4" />
                Scan QR Check-out
              </button>
              <button
                onClick={() => handleExport('attendance')}
                disabled={busyKey === 'export-attendance'}
                className="inline-flex items-center gap-2 rounded-2xl border border-[#e6d8b2] bg-white px-4 py-3 text-sm font-semibold text-[#2e2820] transition hover:-translate-y-0.5 hover:shadow-md disabled:opacity-60"
              >
                <Download className="h-4 w-4" />
                Export Absensi
              </button>
              <button
                onClick={() => handleExport('performance')}
                disabled={busyKey === 'export-performance'}
                className="inline-flex items-center gap-2 rounded-2xl border border-[#e6d8b2] bg-white px-4 py-3 text-sm font-semibold text-[#2e2820] transition hover:-translate-y-0.5 hover:shadow-md disabled:opacity-60"
              >
                <ReceiptText className="h-4 w-4" />
                Export Performa
              </button>
              <button
                onClick={() => openCreateShiftModal()}
                className="inline-flex items-center gap-2 rounded-2xl border border-[#111111]/5 bg-[#111111] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[#2b241c]"
              >
                <CalendarClock className="h-4 w-4" />
                Atur Shift
              </button>
              <button
                onClick={openCreateStaffModal}
                className="inline-flex items-center gap-2 rounded-2xl bg-amber-400 px-4 py-3 text-sm font-semibold text-[#111111] transition hover:bg-amber-300"
              >
                <Plus className="h-4 w-4" />
                Tambah Staff
              </button>
            </div>
          </div>
        </section>

        {loading ? (
          <div className="rounded-[24px] border border-[#eadfbe] bg-white p-12 text-center shadow-sm">
            <div className="mx-auto mb-4 h-10 w-10 animate-spin rounded-full border-4 border-[#eadfbe] border-t-amber-400" />
            <p className="text-sm text-[#6a6255]">Memuat panel staff management...</p>
          </div>
        ) : error ? (
          <div className="rounded-[24px] border border-red-200 bg-red-50 p-6 text-red-700">
            <p className="font-semibold">Gagal memuat data staff</p>
            <p className="mt-1 text-sm">{error}</p>
            <button
              onClick={() => void loadDashboard()}
              className="mt-4 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm"
            >
              Coba lagi
            </button>
          </div>
        ) : dashboard ? (
          <>
            <section className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
              <StatCard icon={UserRound} title="Total Staff" value={String(dashboard.summary.total_staff)} tone="amber" />
              <StatCard icon={BriefcaseBusiness} title="Aktif" value={String(dashboard.summary.active_staff)} tone="emerald" />
              <StatCard icon={UserCheck} title="Sudah Check-in" value={String(dashboard.summary.checked_in_now)} tone="sky" />
              <StatCard icon={CalendarClock} title="Shift Hari Ini" value={String(dashboard.summary.scheduled_today)} tone="violet" />
              <StatCard icon={Wallet} title="Kas Terbuka" value={String(dashboard.summary.open_cash_sessions)} tone="rose" />
              <StatCard icon={CheckCircle2} title="Kedisiplinan" value={`${dashboard.summary.attendance_rate}%`} tone="slate" />
            </section>

            <section className="grid grid-cols-1 gap-6 xl:grid-cols-[1.6fr_1fr]">
              <PanelCard>
                <div className="flex items-center justify-between gap-4">
                  <div>
                    <p className="text-sm font-semibold text-[#2d261e]">Notifikasi ringan</p>
                    <p className="mt-1 text-sm text-[#7a7063]">Pengingat shift, keterlambatan absen, dan penutupan shift.</p>
                  </div>
                  <span className="inline-flex items-center gap-2 rounded-full bg-[#fff5d6] px-3 py-1 text-xs font-semibold text-[#8b6f18]">
                    <Bell className="h-3.5 w-3.5" />
                    {dashboard.notifications.length} aktif
                  </span>
                </div>

                <div className="mt-4 grid gap-3">
                  {dashboard.notifications.length > 0 ? dashboard.notifications.map((notification, index) => (
                    <div
                      key={`${notification.staff_id}-${index}`}
                      className="rounded-2xl border border-[#efe5c8] bg-[#fffdf7] px-4 py-3 text-sm text-[#53493e]"
                    >
                      {notification.message}
                    </div>
                  )) : (
                    <div className="rounded-2xl border border-dashed border-[#eadfbe] bg-[#fffdf7] px-4 py-5 text-sm text-[#7a7063]">
                      Belum ada notifikasi penting. Shift dan absensi hari ini terlihat aman.
                    </div>
                  )}
                </div>
              </PanelCard>

              <PanelCard>
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <p className="text-sm font-semibold text-[#2d261e]">Top staff</p>
                    <p className="mt-1 text-sm text-[#7a7063]">Ranking sederhana dari transaksi dan penjualan.</p>
                  </div>
                  <ShieldCheck className="h-5 w-5 text-[#aa8e3b]" />
                </div>

                <div className="mt-4 space-y-3">
                  {dashboard.top_staff.length > 0 ? dashboard.top_staff.map((member, index) => (
                    <div key={member.id} className="flex items-center justify-between rounded-2xl border border-[#efe5c8] bg-[#fffdf7] px-4 py-3">
                      <div>
                        <p className="font-semibold text-[#2d261e]">{index + 1}. {member.name}</p>
                        <p className="text-xs text-[#7a7063]">{roleLabel(member.role)} • {member.performance.total_transactions} transaksi</p>
                      </div>
                      <div className="text-right">
                        <p className="font-semibold text-[#2d261e]">{formatCurrency(member.performance.total_sales)}</p>
                        <p className="text-xs text-[#7a7063]">{formatHours(member.performance.work_minutes)}</p>
                      </div>
                    </div>
                  )) : (
                    <div className="rounded-2xl border border-dashed border-[#eadfbe] bg-[#fffdf7] px-4 py-5 text-sm text-[#7a7063]">
                      Belum ada data performa yang cukup untuk dirangking.
                    </div>
                  )}
                </div>
              </PanelCard>
            </section>

            <section className="grid grid-cols-1 gap-6 xl:grid-cols-[1.4fr_1fr]">
              <PanelCard>
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <p className="text-sm font-semibold text-[#2d261e]">Manajemen staff</p>
                    <p className="mt-1 text-sm text-[#7a7063]">Cari staff, atur role, lihat performa, dan jalankan aksi harian.</p>
                  </div>
                  <div className="relative w-full sm:max-w-sm">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#998d77]" />
                    <input
                      value={searchTerm}
                      onChange={(event) => setSearchTerm(event.target.value)}
                      placeholder="Cari nama, email, role..."
                      className="w-full rounded-2xl border border-[#eadfbe] bg-[#fffdf7] py-3 pl-10 pr-4 text-sm text-[#1d1914] outline-none transition focus:border-amber-300 focus:ring-2 focus:ring-amber-100"
                    />
                  </div>
                </div>

                <div className="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                  {visibleStaff.map((member) => (
                    <article
                      key={member.id}
                      className={cn(
                        'rounded-[24px] border p-5 shadow-sm transition',
                        member.id === dashboard.current_user_staff_id
                          ? 'border-[#d7c17a] bg-[linear-gradient(180deg,#fff8e0_0%,#fffef9_100%)]'
                          : 'border-[#eadfbe] bg-white'
                      )}
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div className="flex items-start gap-3">
                          <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#fff2c3] text-lg font-bold text-[#7a5d15]">
                            {member.name.charAt(0).toUpperCase()}
                          </div>
                          <div>
                            <div className="flex flex-wrap items-center gap-2">
                              <h3 className="font-semibold text-[#1d1914]">{member.name}</h3>
                              <span className="rounded-full bg-[#111111] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-white">
                                {roleLabel(member.role)}
                              </span>
                              <span className={cn(
                                'rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                member.employment_status === 'active'
                                  ? 'bg-emerald-100 text-emerald-700'
                                  : member.employment_status === 'inactive'
                                    ? 'bg-slate-200 text-slate-700'
                                    : 'bg-amber-100 text-amber-700'
                              )}>
                                {statusLabel(member.employment_status)}
                              </span>
                            </div>
                            <p className="mt-1 text-sm text-[#6a6255]">{member.email || 'Tanpa email'}{member.phone ? ` • ${member.phone}` : ''}</p>
                            <p className="mt-1 text-xs text-[#8d806c]">
                              Gabung {formatDate(member.joined_at)}{member.linked_user_name ? ` • Terkait akun ${member.linked_user_name}` : ' • Staff manual'}
                            </p>
                          </div>
                        </div>

                        <div className="flex gap-2">
                          <button
                            onClick={() => openEditStaffModal(member)}
                            className="rounded-xl border border-[#eadfbe] bg-[#fffdf7] p-2 text-[#4f463a] transition hover:bg-white"
                            title="Edit staff"
                          >
                            <Edit2 className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => void handleDeleteStaff(member)}
                            className="rounded-xl border border-red-200 bg-red-50 p-2 text-red-600 transition hover:bg-red-100"
                            title="Hapus staff"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </div>
                      </div>

                      <div className="mt-4 grid grid-cols-2 gap-3">
                        <MiniMetric label="Transaksi" value={formatCompactNumber(member.performance.total_transactions)} />
                        <MiniMetric label="Penjualan" value={formatCurrency(member.performance.total_sales)} />
                        <MiniMetric label="Jam kerja" value={formatHours(member.performance.work_minutes)} />
                        <MiniMetric label="Terlambat" value={`${member.performance.late_count}x`} />
                      </div>

                      {member.today_attendance?.check_in_location_label && (
                        <div className="mt-4 rounded-2xl border border-[#efe5c8] bg-[#fffdf8] p-4">
                          <p className="inline-flex items-center gap-1 text-xs font-semibold text-[#7a7063]">
                            <MapPin className="h-3.5 w-3.5" />
                            Scan masuk: {member.today_attendance.check_in_location_label}
                          </p>
                          {formatCoordinatePair(member.today_attendance.check_in_latitude, member.today_attendance.check_in_longitude) && (
                            <p className="mt-2 text-xs text-[#8d806c]">
                              Koordinat {formatCoordinatePair(member.today_attendance.check_in_latitude, member.today_attendance.check_in_longitude)}
                            </p>
                          )}
                        </div>
                      )}

                      <div className="mt-4 rounded-2xl border border-[#efe5c8] bg-[linear-gradient(180deg,#fff8e6_0%,#fffef9_100%)] p-4">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="text-sm font-semibold text-[#2d261e]">QR absensi unik</p>
                            <p className="mt-1 text-xs text-[#7a7063]">Token {member.attendance_qr.token_preview} • scan dari device admin untuk validasi kehadiran.</p>
                          </div>
                          <QrCode className="h-5 w-5 text-[#8b6f18]" />
                        </div>
                        <button
                          onClick={() => void openQrModal(member)}
                          className="mt-3 inline-flex items-center gap-2 rounded-2xl border border-[#dcc58a] bg-white px-3 py-2 text-xs font-semibold text-[#5f4913] transition hover:bg-[#fffdf7]"
                        >
                          <QrCode className="h-3.5 w-3.5" />
                          Lihat QR Staff
                        </button>
                      </div>

                      <div className="mt-4 rounded-2xl border border-[#efe5c8] bg-[#fffdf8] p-4">
                        <div className="flex items-center justify-between gap-3">
                          <p className="text-sm font-semibold text-[#2d261e]">Shift & absensi hari ini</p>
                          {member.today_shift ? (
                            <span className="text-xs font-medium text-[#8b6f18]">{shiftStatusLabel(member.today_shift.status)}</span>
                          ) : (
                            <span className="text-xs font-medium text-[#8d806c]">Belum ada shift</span>
                          )}
                        </div>
                        <p className="mt-2 text-sm text-[#5b5247]">
                          {member.today_shift
                            ? `${member.today_shift.title} • ${formatDateTime(member.today_shift.start_at)} - ${new Date(member.today_shift.end_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}`
                            : 'Bisa dipakai untuk jadwal fleksibel atau on-call.'}
                        </p>
                        <p className="mt-2 text-xs text-[#8d806c]">
                          {member.today_attendance
                            ? `Status ${member.today_attendance.status}. Check-in ${member.today_attendance.check_in_at ? formatDateTime(member.today_attendance.check_in_at) : '-'}${member.today_attendance.check_out_at ? ` • Check-out ${formatDateTime(member.today_attendance.check_out_at)}` : ''}`
                            : 'Belum ada catatan absensi hari ini.'}
                        </p>
                      </div>

                      <div className="mt-4 rounded-2xl border border-[#efe5c8] bg-[#fffdf8] p-4">
                        <div className="flex items-center justify-between">
                          <p className="text-sm font-semibold text-[#2d261e]">Shift kas</p>
                          <Wallet className="h-4 w-4 text-[#8b6f18]" />
                        </div>
                        <p className="mt-2 text-sm text-[#5b5247]">
                          {member.cash_session?.status === 'open'
                            ? `Kas terbuka sejak ${formatDateTime(member.cash_session.started_at)}`
                            : 'Belum ada kas aktif.'}
                        </p>
                        <p className="mt-1 text-xs text-[#8d806c]">
                          {member.cash_session?.status === 'open'
                            ? `Kas awal ${formatCurrency(member.cash_session.opening_cash)}`
                            : 'Input kas awal saat shift dimulai, lalu tutup dengan kas akhir untuk hitung selisih.'}
                        </p>
                      </div>

                      <div className="mt-4 flex flex-wrap gap-2">
                        <ActionButton
                          label="QR Staff"
                          icon={QrCode}
                          onClick={() => void openQrModal(member)}
                        />
                        <ActionButton
                          label={member.linked_user_id ? 'Akun tertaut' : 'Undang login'}
                          icon={Mail}
                          disabled={Boolean(member.linked_user_id)}
                          loading={busyKey === `invite-${member.id}`}
                          onClick={() => void handleInviteLogin(member)}
                        />
                        <ActionButton
                          label="Check-in"
                          icon={Clock3}
                          disabled={Boolean(member.today_attendance?.checked_in)}
                          loading={busyKey === `checkin-${member.id}`}
                          onClick={() => void handleCheckIn(member)}
                        />
                        <ActionButton
                          label="Check-out"
                          icon={CheckCircle2}
                          disabled={!member.today_attendance?.checked_in || Boolean(member.today_attendance?.checked_out)}
                          loading={busyKey === `checkout-${member.id}`}
                          onClick={() => void handleCheckOut(member)}
                        />
                        <ActionButton
                          label="Izin"
                          icon={UserCheck}
                          disabled={member.today_attendance?.status === 'leave'}
                          loading={busyKey === `leave-${member.id}`}
                          onClick={() => void handleMarkLeave(member)}
                        />
                        <ActionButton
                          label="Buka kas"
                          icon={Wallet}
                          disabled={member.cash_session?.status === 'open'}
                          loading={busyKey === `cash-open-${member.id}`}
                          onClick={() => void handleOpenCash(member)}
                        />
                        <ActionButton
                          label="Tutup kas"
                          icon={ReceiptText}
                          disabled={member.cash_session?.status !== 'open'}
                          loading={busyKey === `cash-close-${member.id}`}
                          onClick={() => void handleCloseCash(member)}
                        />
                        <ActionButton
                          label="Atur shift"
                          icon={CalendarClock}
                          onClick={() => openCreateShiftModal(member.id)}
                        />
                      </div>

                      <div className="mt-4 flex flex-wrap gap-2">
                        {Object.entries(member.permissions).map(([key, enabled]) => (
                          <span
                            key={key}
                            className={cn(
                              'rounded-full px-2.5 py-1 text-[11px] font-semibold',
                              enabled ? 'bg-[#111111] text-white' : 'bg-[#ede7da] text-[#7a7063]'
                            )}
                          >
                            {key.replace('_', ' ')}
                          </span>
                        ))}
                      </div>
                    </article>
                  ))}
                </div>

                {visibleStaff.length === 0 && (
                  <div className="mt-5 rounded-2xl border border-dashed border-[#eadfbe] bg-[#fffdf7] px-4 py-10 text-center text-sm text-[#7a7063]">
                    Tidak ada staff yang cocok dengan pencarian.
                  </div>
                )}
              </PanelCard>

              <div className="space-y-6">
                <PanelCard>
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-semibold text-[#2d261e]">My shift</p>
                      <p className="mt-1 text-sm text-[#7a7063]">Panel cepat untuk staff yang sedang login.</p>
                    </div>
                    <UserCheck className="h-5 w-5 text-[#8b6f18]" />
                  </div>

                  {myStaff ? (
                    <div className="mt-4 space-y-3 rounded-2xl border border-[#efe5c8] bg-[#fffdf7] p-4">
                      <div>
                        <p className="font-semibold text-[#1d1914]">{myStaff.name}</p>
                        <p className="text-sm text-[#6a6255]">{roleLabel(myStaff.role)} • {statusLabel(myStaff.employment_status)}</p>
                      </div>
                      <p className="text-sm text-[#5b5247]">
                        {myStaff.today_shift
                          ? `${myStaff.today_shift.title} dimulai ${formatDateTime(myStaff.today_shift.start_at)}`
                          : 'Belum ada shift yang dijadwalkan hari ini.'}
                      </p>
                      <div className="grid grid-cols-2 gap-2">
                        <QuickAction
                          label="Check-in"
                          onClick={() => void handleCheckIn(myStaff)}
                          disabled={Boolean(myStaff.today_attendance?.checked_in)}
                        />
                        <QuickAction
                          label="Check-out"
                          onClick={() => void handleCheckOut(myStaff)}
                          disabled={!myStaff.today_attendance?.checked_in || Boolean(myStaff.today_attendance?.checked_out)}
                        />
                        <QuickAction
                          label="Buka kas"
                          onClick={() => void handleOpenCash(myStaff)}
                          disabled={myStaff.cash_session?.status === 'open'}
                        />
                        <QuickAction
                          label="Tutup kas"
                          onClick={() => void handleCloseCash(myStaff)}
                          disabled={myStaff.cash_session?.status !== 'open'}
                        />
                      </div>
                    </div>
                  ) : (
                    <div className="mt-4 rounded-2xl border border-dashed border-[#eadfbe] bg-[#fffdf7] px-4 py-5 text-sm text-[#7a7063]">
                      User login saat ini belum dikaitkan ke data staff POS. Owner tetap bisa kelola tim dari panel kiri.
                    </div>
                  )}
                </PanelCard>

                <PanelCard>
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-semibold text-[#2d261e]">Agenda shift</p>
                      <p className="mt-1 text-sm text-[#7a7063]">List dan kalender ringan berbasis waktu.</p>
                    </div>
                    <CalendarClock className="h-5 w-5 text-[#8b6f18]" />
                  </div>

                  <div className="mt-4 space-y-3">
                    {dashboard.today.shifts.length > 0 ? dashboard.today.shifts.map((shift) => (
                      <button
                        key={shift.id}
                        onClick={() => openEditShiftModal(shift)}
                        className="w-full rounded-2xl border border-[#efe5c8] bg-[#fffdf7] px-4 py-3 text-left transition hover:bg-white"
                      >
                        <div className="flex items-center justify-between gap-3">
                          <div>
                            <p className="font-semibold text-[#1d1914]">{shift.staff_name || 'Staff'} • {shift.title}</p>
                            <p className="text-xs text-[#7a7063]">{formatDateTime(shift.start_at)} - {new Date(shift.end_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</p>
                          </div>
                          <span className="rounded-full bg-[#f4edd9] px-2.5 py-1 text-[11px] font-semibold text-[#7b6640]">
                            {shiftStatusLabel(shift.status)}
                          </span>
                        </div>
                      </button>
                    )) : (
                      <div className="rounded-2xl border border-dashed border-[#eadfbe] bg-[#fffdf7] px-4 py-5 text-sm text-[#7a7063]">
                        Belum ada shift hari ini. Tekan tombol "Atur Shift" untuk mulai menjadwalkan.
                      </div>
                    )}
                  </div>
                </PanelCard>

                <PanelCard>
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-semibold text-[#2d261e]">Rekap cepat</p>
                      <p className="mt-1 text-sm text-[#7a7063]">Absensi terakhir dan log kas yang baru bergerak.</p>
                    </div>
                    <Bell className="h-5 w-5 text-[#8b6f18]" />
                  </div>

                  <div className="mt-4 space-y-4">
                    <div>
                      <p className="mb-2 text-xs font-semibold uppercase tracking-[0.16em] text-[#8d806c]">Absensi terbaru</p>
                      <div className="space-y-2">
                        {dashboard.today.recent_attendances.slice(0, 4).map((attendance) => (
                          <div key={attendance.id} className="rounded-2xl border border-[#efe5c8] bg-[#fffdf7] px-4 py-3 text-sm">
                            <p className="font-semibold text-[#1d1914]">{attendance.staff_name || 'Staff'} • {attendance.status}</p>
                            <p className="text-xs text-[#7a7063]">{formatDate(attendance.attendance_date)} • {attendance.check_in_at ? formatDateTime(attendance.check_in_at) : 'Belum check-in'}</p>
                          </div>
                        ))}
                        {dashboard.today.recent_attendances.length === 0 && (
                          <div className="rounded-2xl border border-dashed border-[#eadfbe] bg-[#fffdf7] px-4 py-4 text-sm text-[#7a7063]">
                            Belum ada data absensi terbaru.
                          </div>
                        )}
                      </div>
                    </div>

                    <div>
                      <p className="mb-2 text-xs font-semibold uppercase tracking-[0.16em] text-[#8d806c]">Kas terbaru</p>
                      <div className="space-y-2">
                        {dashboard.today.recent_cash_logs.slice(0, 4).map((cashLog) => (
                          <div key={cashLog.id} className="rounded-2xl border border-[#efe5c8] bg-[#fffdf7] px-4 py-3 text-sm">
                            <p className="font-semibold text-[#1d1914]">{cashLog.staff_name || 'Staff'} • {cashLog.status === 'open' ? 'Kas terbuka' : 'Kas ditutup'}</p>
                            <p className="text-xs text-[#7a7063]">
                              {formatCurrency(cashLog.opening_cash)} awal
                              {cashLog.status === 'closed' ? ` • selisih ${formatCurrency(cashLog.difference_cash || 0)}` : ''}
                            </p>
                          </div>
                        ))}
                        {dashboard.today.recent_cash_logs.length === 0 && (
                          <div className="rounded-2xl border border-dashed border-[#eadfbe] bg-[#fffdf7] px-4 py-4 text-sm text-[#7a7063]">
                            Belum ada aktivitas kas staff.
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                </PanelCard>
              </div>
            </section>
          </>
        ) : null}
      </div>

      {showStaffModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-[rgba(17,17,17,0.22)] p-4 backdrop-blur-md">
          <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-[28px] border border-[#eadfbe] bg-white p-6 shadow-[0_28px_80px_rgba(17,17,17,0.24)]">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="text-xl font-bold text-[#1d1914]">{editingStaff ? 'Edit staff' : 'Tambah staff baru'}</h2>
                <p className="mt-1 text-sm text-[#7a7063]">Role, permission, dan status kerja bisa diatur per staff.</p>
              </div>
              <button onClick={() => setShowStaffModal(false)} className="rounded-xl bg-[#f3efe5] px-3 py-2 text-sm text-[#4b4338]">
                Tutup
              </button>
            </div>

            <form onSubmit={handleSaveStaff} className="mt-6 space-y-5">
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Field label="Nama lengkap *">
                  <input
                    required
                    value={staffForm.name}
                    onChange={(event) => setStaffForm((prev) => ({ ...prev, name: event.target.value }))}
                    className={inputClass}
                    placeholder="Misal: Ahmad Prasetyo"
                  />
                </Field>
                <Field label="Email">
                  <input
                    type="email"
                    value={staffForm.email}
                    onChange={(event) => setStaffForm((prev) => ({ ...prev, email: event.target.value }))}
                    className={inputClass}
                    placeholder="ahmad@resto.com"
                  />
                </Field>
                <Field label="Nomor HP">
                  <input
                    value={staffForm.phone}
                    onChange={(event) => setStaffForm((prev) => ({ ...prev, phone: event.target.value }))}
                    className={inputClass}
                    placeholder="08xxxxxxxxxx"
                  />
                </Field>
                <Field label="Tanggal gabung">
                  <input
                    type="date"
                    value={staffForm.joined_at}
                    onChange={(event) => setStaffForm((prev) => ({ ...prev, joined_at: event.target.value }))}
                    className={inputClass}
                  />
                </Field>
              </div>

              <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <Field label="Role *">
                  <select
                    value={staffForm.role}
                    onChange={(event) => applyRolePreset(event.target.value as PosStaffRole)}
                    className={inputClass}
                  >
                    <option value="admin">Admin</option>
                    <option value="cashier">Kasir</option>
                  </select>
                </Field>
                <Field label="Status kerja">
                  <select
                    value={staffForm.employment_status}
                    onChange={(event) => setStaffForm((prev) => ({ ...prev, employment_status: event.target.value as PosStaffEmploymentStatus }))}
                    className={inputClass}
                  >
                    <option value="active">Aktif</option>
                    <option value="inactive">Nonaktif</option>
                    <option value="on_leave">Izin</option>
                  </select>
                </Field>
                <Field label="Upah per jam">
                  <input
                    type="number"
                    min={0}
                    value={staffForm.hourly_rate}
                    onChange={(event) => setStaffForm((prev) => ({ ...prev, hourly_rate: Number(event.target.value) }))}
                    className={inputClass}
                    placeholder="0"
                  />
                </Field>
              </div>

              <Field label="Hak akses POS">
                <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                  {(Object.keys(staffForm.permissions) as PosStaffPermissionKey[]).map((key) => (
                    <button
                      key={key}
                      type="button"
                      onClick={() => togglePermission(key)}
                      className={cn(
                        'rounded-2xl border px-3 py-3 text-sm font-semibold capitalize transition',
                        staffForm.permissions[key]
                          ? 'border-[#111111] bg-[#111111] text-white'
                          : 'border-[#eadfbe] bg-[#fffdf7] text-[#5a5146]'
                      )}
                    >
                      {key.replace('_', ' ')}
                    </button>
                  ))}
                </div>
              </Field>

              <Field label="Catatan">
                <textarea
                  value={staffForm.notes}
                  onChange={(event) => setStaffForm((prev) => ({ ...prev, notes: event.target.value }))}
                  className={`${inputClass} min-h-[110px] resize-y`}
                  placeholder="Contoh: bisa tutup kas, jaga shift malam, atau catatan izin tetap."
                />
              </Field>

              <div className="flex justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setShowStaffModal(false)}
                  className="rounded-2xl bg-[#f3efe5] px-4 py-3 text-sm font-semibold text-[#4b4338]"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={busyKey === 'save-staff'}
                  className="rounded-2xl bg-amber-400 px-4 py-3 text-sm font-semibold text-[#111111] disabled:opacity-60"
                >
                  {busyKey === 'save-staff' ? 'Menyimpan...' : editingStaff ? 'Update staff' : 'Simpan staff'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showShiftModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-[rgba(17,17,17,0.22)] p-4 backdrop-blur-md">
          <div className="w-full max-w-xl rounded-[28px] border border-[#eadfbe] bg-white p-6 shadow-[0_28px_80px_rgba(17,17,17,0.24)]">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="text-xl font-bold text-[#1d1914]">{editingShift ? 'Edit shift' : 'Atur shift staff'}</h2>
                <p className="mt-1 text-sm text-[#7a7063]">Tampilan list dan kalender ringan untuk jadwal kerja harian.</p>
              </div>
              <button onClick={() => setShowShiftModal(false)} className="rounded-xl bg-[#f3efe5] px-3 py-2 text-sm text-[#4b4338]">
                Tutup
              </button>
            </div>

            <form onSubmit={handleSaveShift} className="mt-6 space-y-5">
              <Field label="Staff *">
                <select
                  value={shiftForm.staff_id}
                  onChange={(event) => setShiftForm((prev) => ({ ...prev, staff_id: Number(event.target.value) }))}
                  className={inputClass}
                  required
                  disabled={Boolean(editingShift)}
                >
                  <option value={0}>Pilih staff</option>
                  {dashboard?.staff.map((member) => (
                    <option key={member.id} value={member.id}>
                      {member.name} • {roleLabel(member.role)}
                    </option>
                  ))}
                </select>
              </Field>

              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Field label="Judul shift">
                  <input
                    value={shiftForm.title}
                    onChange={(event) => setShiftForm((prev) => ({ ...prev, title: event.target.value }))}
                    className={inputClass}
                    placeholder="Shift Pagi / Shift Closing"
                  />
                </Field>
                <Field label="Reminder (menit)">
                  <input
                    type="number"
                    min={0}
                    max={180}
                    value={shiftForm.reminder_minutes}
                    onChange={(event) => setShiftForm((prev) => ({ ...prev, reminder_minutes: Number(event.target.value) }))}
                    className={inputClass}
                  />
                </Field>
                <Field label="Mulai *">
                  <input
                    type="datetime-local"
                    required
                    value={shiftForm.start_at}
                    onChange={(event) => setShiftForm((prev) => ({ ...prev, start_at: event.target.value }))}
                    className={inputClass}
                  />
                </Field>
                <Field label="Selesai *">
                  <input
                    type="datetime-local"
                    required
                    value={shiftForm.end_at}
                    onChange={(event) => setShiftForm((prev) => ({ ...prev, end_at: event.target.value }))}
                    className={inputClass}
                  />
                </Field>
              </div>

              <Field label="Catatan">
                <textarea
                  value={shiftForm.notes}
                  onChange={(event) => setShiftForm((prev) => ({ ...prev, notes: event.target.value }))}
                  className={`${inputClass} min-h-[110px] resize-y`}
                  placeholder="Contoh: briefing sebelum buka, fokus order delivery, atau penutupan kas."
                />
              </Field>

              <div className="flex justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setShowShiftModal(false)}
                  className="rounded-2xl bg-[#f3efe5] px-4 py-3 text-sm font-semibold text-[#4b4338]"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={busyKey === 'save-shift'}
                  className="rounded-2xl bg-amber-400 px-4 py-3 text-sm font-semibold text-[#111111] disabled:opacity-60"
                >
                  {busyKey === 'save-shift' ? 'Menyimpan...' : editingShift ? 'Update shift' : 'Simpan shift'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showQrModal && selectedQrStaff && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-[rgba(17,17,17,0.3)] p-4 backdrop-blur-md">
          <div className="w-full max-w-lg rounded-[28px] border border-[#eadfbe] bg-white p-6 shadow-[0_28px_80px_rgba(17,17,17,0.24)]">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="text-xl font-bold text-[#1d1914]">QR absensi staff</h2>
                <p className="mt-1 text-sm text-[#7a7063]">{selectedQrStaff.name}{selectedQr ? ` • token ${selectedQr.token_preview}` : ''}</p>
              </div>
              <button onClick={() => setShowQrModal(false)} className="rounded-xl bg-[#f3efe5] px-3 py-2 text-sm text-[#4b4338]">
                Tutup
              </button>
            </div>

            <div className="mt-6 rounded-[28px] border border-[#efe5c8] bg-[linear-gradient(180deg,#fff8e6_0%,#fffef9_100%)] p-5 text-center">
              {selectedQr?.svg_data_uri ? (
                <img src={selectedQr.svg_data_uri} alt={`QR absensi ${selectedQrStaff.name}`} className="mx-auto h-64 w-64 rounded-3xl border border-[#eadfbe] bg-white p-3" />
              ) : (
                <div className="mx-auto flex h-64 w-64 items-center justify-center rounded-3xl border border-[#eadfbe] bg-white text-sm text-[#7a7063]">
                  Memuat QR staff...
                </div>
              )}
              <p className="mt-4 text-sm font-semibold text-[#2d261e]">Tunjukkan QR ini ke device admin saat scan absensi.</p>
              <p className="mt-2 text-xs text-[#7a7063]">QR bersifat unik per staff dan bisa diganti kapan saja bila perlu.</p>
              {selectedQr?.rotated_at && (
                <p className="mt-2 text-[11px] text-[#8d806c]">Terakhir diganti {formatDateTime(selectedQr.rotated_at)}</p>
              )}
            </div>

            {scanError && (
              <div className="mt-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {scanError}
              </div>
            )}

            <div className="mt-5 flex flex-wrap justify-end gap-3">
              <button
                onClick={downloadQrCard}
                disabled={!selectedQr?.svg_data_uri}
                className="inline-flex items-center gap-2 rounded-2xl border border-[#eadfbe] bg-white px-4 py-3 text-sm font-semibold text-[#2d261e]"
              >
                <Download className="h-4 w-4" />
                Download SVG
              </button>
              <button
                onClick={() => void handleRegenerateQr()}
                disabled={busyKey === `regen-qr-${selectedQrStaff.id}`}
                className="inline-flex items-center gap-2 rounded-2xl bg-amber-400 px-4 py-3 text-sm font-semibold text-[#111111] disabled:opacity-60"
              >
                <RefreshCcw className="h-4 w-4" />
                {busyKey === `regen-qr-${selectedQrStaff.id}` ? 'Mengganti QR...' : 'Regenerasi QR'}
              </button>
            </div>
          </div>
        </div>
      )}

      {showScannerModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-[rgba(17,17,17,0.36)] p-4 backdrop-blur-md">
          <div className="w-full max-w-3xl rounded-[28px] border border-[#eadfbe] bg-white p-6 shadow-[0_28px_80px_rgba(17,17,17,0.24)]">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="text-xl font-bold text-[#1d1914]">{scanMode === 'check_in' ? 'Scanner QR check-in' : 'Scanner QR check-out'}</h2>
                <p className="mt-1 text-sm text-[#7a7063]">Gunakan kamera device admin, lalu arahkan ke QR milik staff.</p>
              </div>
              <button onClick={() => setShowScannerModal(false)} className="rounded-xl bg-[#f3efe5] px-3 py-2 text-sm text-[#4b4338]">
                Tutup
              </button>
            </div>

            <div className="mt-6 grid gap-5 lg:grid-cols-[1.4fr_0.8fr]">
              <div className="overflow-hidden rounded-[28px] border border-[#eadfbe] bg-[#1b1712]">
                <video ref={scannerVideoRef} className="aspect-video w-full object-cover" muted />
                <canvas ref={scannerCanvasRef} className="hidden" />
              </div>

              <div className="space-y-4">
                <div className="rounded-2xl border border-[#efe5c8] bg-[#fffdf7] p-4">
                  <p className="text-sm font-semibold text-[#2d261e]">Lokasi scan</p>
                  <input
                    value={scannerLocationDraft}
                    onChange={(event) => setScannerLocationDraft(event.target.value)}
                    className={`${inputClass} mt-3`}
                    placeholder="Contoh: Kasir depan, outlet lantai 1"
                  />
                  <button
                    onClick={() => void requestScannerLocation()}
                    className="mt-3 inline-flex items-center gap-2 rounded-2xl border border-[#eadfbe] bg-white px-3 py-2 text-xs font-semibold text-[#42392f]"
                  >
                    <MapPin className="h-3.5 w-3.5" />
                    Ambil GPS device admin
                  </button>
                  <p className="mt-2 text-xs text-[#7a7063]">
                    {scannerLocation.granted && formatCoordinatePair(scannerLocation.latitude, scannerLocation.longitude)
                      ? `Koordinat ${formatCoordinatePair(scannerLocation.latitude, scannerLocation.longitude)}${scannerLocation.accuracy ? ` • akurasi ±${Math.round(scannerLocation.accuracy)} m` : ''}`
                      : 'GPS opsional, tapi disarankan untuk audit lokasi scan.'}
                  </p>
                </div>

                <div className="rounded-2xl border border-[#efe5c8] bg-[#fffdf7] p-4 text-sm text-[#5b5247]">
                  <p className="font-semibold text-[#2d261e]">Status scanner</p>
                  <p className="mt-2">{scanMessage || 'Menunggu kamera aktif.'}</p>
                  {lastScannedStaff && <p className="mt-2 text-xs text-emerald-700">Scan terakhir: {lastScannedStaff}</p>}
                  {scanError && <p className="mt-2 text-xs text-red-600">{scanError}</p>}
                </div>

                <div className="rounded-2xl border border-dashed border-[#eadfbe] bg-[#fffcf3] p-4 text-xs leading-5 text-[#7a7063]">
                  QR bersifat unik per staff. Jika kartu QR hilang atau tersebar, regenerasi dari panel staff untuk mematikan QR lama.
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function PanelCard({ children }: { children: ReactNode }) {
  return (
    <section className="rounded-[28px] border border-[#eadfbe] bg-white p-5 shadow-[0_14px_35px_rgba(17,17,17,0.06)] sm:p-6">
      {children}
    </section>
  );
}

function StatCard({
  icon: Icon,
  title,
  value,
  tone,
}: {
  icon: ComponentType<{ className?: string }>;
  title: string;
  value: string;
  tone: 'amber' | 'emerald' | 'sky' | 'violet' | 'rose' | 'slate';
}) {
  const toneClass =
    tone === 'amber' ? 'bg-amber-100 text-amber-700' :
    tone === 'emerald' ? 'bg-emerald-100 text-emerald-700' :
    tone === 'sky' ? 'bg-sky-100 text-sky-700' :
    tone === 'violet' ? 'bg-violet-100 text-violet-700' :
    tone === 'rose' ? 'bg-rose-100 text-rose-700' :
    'bg-slate-100 text-slate-700';

  return (
    <div className="rounded-[24px] border border-[#eadfbe] bg-white p-4 shadow-sm">
      <div className="flex items-center gap-3">
        <div className={cn('flex h-11 w-11 items-center justify-center rounded-2xl', toneClass)}>
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-[#8d806c]">{title}</p>
          <p className="mt-1 text-2xl font-bold text-[#1d1914]">{value}</p>
        </div>
      </div>
    </div>
  );
}

function MiniMetric({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-2xl border border-[#efe5c8] bg-[#fffdf8] px-4 py-3">
      <p className="text-xs font-semibold uppercase tracking-[0.16em] text-[#8d806c]">{label}</p>
      <p className="mt-1 text-sm font-semibold text-[#1d1914]">{value}</p>
    </div>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="block space-y-2">
      <span className="text-sm font-semibold text-[#2d261e]">{label}</span>
      {children}
    </label>
  );
}

function ActionButton({
  label,
  icon: Icon,
  disabled,
  loading,
  onClick,
}: {
  label: string;
  icon: ComponentType<{ className?: string }>;
  disabled?: boolean;
  loading?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      onClick={onClick}
      disabled={disabled || loading}
      className="inline-flex items-center gap-2 rounded-2xl border border-[#eadfbe] bg-[#fffdf7] px-3 py-2.5 text-xs font-semibold text-[#42392f] transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-50"
    >
      <Icon className="h-3.5 w-3.5" />
      {loading ? 'Proses...' : label}
    </button>
  );
}

function QuickAction({
  label,
  onClick,
  disabled,
}: {
  label: string;
  onClick: () => void;
  disabled?: boolean;
}) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className="rounded-2xl border border-[#eadfbe] bg-white px-3 py-3 text-sm font-semibold text-[#2d261e] transition hover:bg-[#fff9e7] disabled:cursor-not-allowed disabled:opacity-50"
    >
      {label}
    </button>
  );
}
