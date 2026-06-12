export type OrderStatusKey =
  | 'new'
  | 'accepted'
  | 'preparing'
  | 'prepared'
  | 'completed'
  | 'cancelled'
  | 'unknown';

type StatusMeta = {
  label: string;
  description: string;
  badgeClassName: string;
  panelClassName: string;
};

const STATUS_META: Record<OrderStatusKey, StatusMeta> = {
  new: {
    label: 'Pesanan masuk',
    description: 'Order sudah diterima sistem dan menunggu diproses tim dapur.',
    badgeClassName: 'bg-amber-100 text-amber-900',
    panelClassName: 'border-amber-200 bg-amber-50',
  },
  accepted: {
    label: 'Diterima',
    description: 'Tim restoran sudah menerima pesanan Anda dan akan mulai menyiapkan.',
    badgeClassName: 'bg-sky-100 text-sky-900',
    panelClassName: 'border-sky-200 bg-sky-50',
  },
  preparing: {
    label: 'Sedang disiapkan',
    description: 'Pesanan sedang diproses di dapur. Mohon tunggu sebentar.',
    badgeClassName: 'bg-orange-100 text-orange-900',
    panelClassName: 'border-orange-200 bg-orange-50',
  },
  prepared: {
    label: 'Siap diambil',
    description: 'Pesanan sudah siap. Silakan menunggu instruksi penyerahan dari staf.',
    badgeClassName: 'bg-emerald-100 text-emerald-900',
    panelClassName: 'border-emerald-200 bg-emerald-50',
  },
  completed: {
    label: 'Selesai',
    description: 'Pesanan sudah selesai. Terima kasih sudah memesan.',
    badgeClassName: 'bg-zinc-900 text-white',
    panelClassName: 'border-zinc-200 bg-zinc-50',
  },
  cancelled: {
    label: 'Dibatalkan',
    description: 'Pesanan dibatalkan. Silakan hubungi staf jika ini tidak sesuai.',
    badgeClassName: 'bg-rose-100 text-rose-900',
    panelClassName: 'border-rose-200 bg-rose-50',
  },
  unknown: {
    label: 'Status belum tersedia',
    description: 'Kami belum bisa membaca status order saat ini.',
    badgeClassName: 'bg-zinc-200 text-zinc-800',
    panelClassName: 'border-zinc-200 bg-zinc-50',
  },
};

export function normalizeOrderStatus(value: string | null | undefined): OrderStatusKey {
  switch (value) {
    case 'new':
    case 'accepted':
    case 'preparing':
    case 'prepared':
    case 'completed':
    case 'cancelled':
      return value;
    default:
      return 'unknown';
  }
}

export function getOrderStatusMeta(value: string | null | undefined): StatusMeta {
  return STATUS_META[normalizeOrderStatus(value)];
}

export function isOrderPending(value: string | null | undefined): boolean {
  const normalized = normalizeOrderStatus(value);
  return normalized !== 'completed' && normalized !== 'cancelled' && normalized !== 'unknown';
}
