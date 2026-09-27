import { CheckCircle2, ChefHat, ClipboardList, RefreshCw, Soup, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { getOrderStatusMeta, normalizeOrderStatus } from '@/lib/pos/orderStatus';

type Props = {
  status: string | null | undefined;
  lastUpdated?: string | null;
  isRefreshing?: boolean;
  onRefresh?: () => void;
};

function StatusIcon({ status }: { status: string | null | undefined }) {
  switch (normalizeOrderStatus(status)) {
    case 'new':
      return <ClipboardList className="w-6 h-6" />;
    case 'accepted':
      return <ChefHat className="w-6 h-6" />;
    case 'preparing':
      return <Soup className="w-6 h-6" />;
    case 'prepared':
    case 'completed':
      return <CheckCircle2 className="w-6 h-6" />;
    case 'cancelled':
      return <XCircle className="w-6 h-6" />;
    default:
      return <RefreshCw className="w-6 h-6" />;
  }
}

export default function StatusTracker({ status, lastUpdated, isRefreshing, onRefresh }: Props) {
  const meta = getOrderStatusMeta(status);
  const panelClassName = 'border-[#E6A800] bg-[#F9F9F9]';
  const badgeClassName = 'bg-[#1A1A1A] text-[#F5C518]';

  return (
    <div className={cn('rounded-3xl border p-5', panelClassName)}>
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          <div className="rounded-2xl bg-white/80 p-3 text-[#1A1A1A] shadow-sm">
            <StatusIcon status={status} />
          </div>
          <div>
            <p className="text-sm font-semibold uppercase tracking-[0.18em] text-[#888888]">
              Status pesanan
            </p>
            <div className="mt-2 flex items-center gap-3">
              <span className={cn('rounded-full px-3 py-1 text-sm font-semibold', badgeClassName)}>
                {meta.label}
              </span>
              {isRefreshing && <span className="text-xs text-[#888888]">Memperbarui...</span>}
            </div>
            <p className="mt-3 max-w-xl text-sm text-[#888888]">{meta.description}</p>
          </div>
        </div>

        {onRefresh && (
          <button
            type="button"
            onClick={onRefresh}
            className="rounded-full border border-[#E6A800] bg-white px-3 py-2 text-sm font-medium text-[#1A1A1A] hover:bg-[#F9F9F9]"
          >
            Refresh
          </button>
        )}
      </div>

      {lastUpdated && (
        <p className="mt-4 text-xs text-[#888888]">
          Terakhir diperbarui {new Date(lastUpdated).toLocaleTimeString('id-ID')}
        </p>
      )}
    </div>
  );
}
