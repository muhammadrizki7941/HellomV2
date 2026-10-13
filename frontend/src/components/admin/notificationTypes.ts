// Super admin notification types (owner_notifications.type) — labels, icons and filter groups
// shared by the bell dropdown and /admin/notifications.
import {
  AlertTriangle,
  ArrowDownCircle,
  ArrowUpCircle,
  Bell,
  Clock,
  CreditCard,
  Megaphone,
  RotateCcw,
  ShieldCheck,
  User,
  Wallet,
  type LucideIcon,
} from 'lucide-react';

type TypeMeta = { label: string; icon: LucideIcon; tone: string };

const TYPES: Record<string, TypeMeta> = {
  landing_sale: { label: 'Dana masuk', icon: ArrowDownCircle, tone: 'bg-emerald-100 text-emerald-700' },
  new_transaction: { label: 'Transaksi', icon: CreditCard, tone: 'bg-blue-100 text-blue-700' },
  subscription: { label: 'Langganan', icon: CreditCard, tone: 'bg-blue-100 text-blue-700' },
  wallet_topup: { label: 'Top up', icon: Wallet, tone: 'bg-sky-100 text-sky-700' },
  withdrawal: { label: 'Penarikan', icon: ArrowUpCircle, tone: 'bg-orange-100 text-orange-700' },
  refund: { label: 'Refund', icon: RotateCcw, tone: 'bg-rose-100 text-rose-700' },
  sla_warning: { label: 'Lewat SLA', icon: AlertTriangle, tone: 'bg-red-100 text-red-700' },
  kyc: { label: 'Verifikasi KTP', icon: ShieldCheck, tone: 'bg-violet-100 text-violet-700' },
  new_user: { label: 'Pendaftar', icon: User, tone: 'bg-green-100 text-green-700' },
  expiry_reminder: { label: 'Tenggat', icon: Clock, tone: 'bg-amber-100 text-amber-700' },
  platform_notice: { label: 'Info', icon: Megaphone, tone: 'bg-zinc-100 text-zinc-700' },
};

export function adminNotificationMeta(type: string): TypeMeta {
  return TYPES[type] ?? { label: 'Info', icon: Bell, tone: 'bg-zinc-100 text-zinc-700' };
}

export type AdminNotificationGroup = 'all' | 'money' | 'payout' | 'account' | 'other';

export const ADMIN_NOTIFICATION_GROUPS: { key: AdminNotificationGroup; label: string; types: string[] }[] = [
  { key: 'all', label: 'Semua', types: [] },
  { key: 'money', label: 'Dana masuk', types: ['landing_sale', 'new_transaction', 'subscription', 'wallet_topup'] },
  { key: 'payout', label: 'Penarikan & refund', types: ['withdrawal', 'refund', 'sla_warning'] },
  { key: 'account', label: 'Akun & KYC', types: ['new_user', 'kyc'] },
  { key: 'other', label: 'Lainnya', types: ['expiry_reminder', 'platform_notice'] },
];

export function groupTypes(group: AdminNotificationGroup): string[] {
  return ADMIN_NOTIFICATION_GROUPS.find((item) => item.key === group)?.types ?? [];
}

const ACTION_LABELS: Record<string, string> = {
  verify_payment: 'Verifikasi',
  open_access: 'Buka akses',
  process_refund: 'Proses refund',
  view_user: 'Lihat user',
  review_withdrawal: 'Tinjau penarikan',
  open_page: 'Perlu ditinjau',
};

export function adminActionLabel(actionType: string): string {
  return ACTION_LABELS[actionType] ?? 'Perlu tindakan';
}

export function formatNotificationAge(dateString: string): string {
  const date = new Date(dateString);
  const diffMins = Math.floor((Date.now() - date.getTime()) / 60000);
  if (diffMins < 1) return 'Baru saja';
  if (diffMins < 60) return `${diffMins} menit lalu`;
  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours} jam lalu`;
  const diffDays = Math.floor(diffHours / 24);
  if (diffDays < 7) return `${diffDays} hari lalu`;
  return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}
