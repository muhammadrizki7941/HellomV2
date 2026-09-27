import type { PlatformFinanceSummary } from '@/services/api';

// Finance figures shown on the super-admin Dashboard and System Health pages.
export type AdminFinanceView = {
  wallet: { available_balance: number; pending_balance: number; total_in: number; total_out: number };
  period: { inflow: number; outflow: number; net: number; transaction_count: number };
  withdrawals: {
    pending_count: number;
    processing_count: number;
    paid_count: number;
    failed_count: number;
    rejected_count: number;
    cancelled_count: number;
  };
};

const num = (value: unknown): number => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

/**
 * Maps the platform-wide summary (GET /platform/finance-summary) onto the
 * page view:
 * - wallet: totals across all organization wallets (all time)
 * - period: platform revenue in the requested range; the API has no
 *   per-period outflow, so outflow is 0 and net equals the revenue
 * - withdrawals: seller withdrawal requests across all organizations
 *   (rejected/cancelled are not reported by the endpoint)
 */
export function toAdminFinanceView(summary: PlatformFinanceSummary | null | undefined): AdminFinanceView {
  const wallets = summary?.organization_wallets;
  const revenue = summary?.platform_revenue;
  const withdrawals = summary?.user_withdrawals;
  const inflow = num(revenue?.total_revenue);

  return {
    wallet: {
      available_balance: num(wallets?.total_available),
      pending_balance: num(wallets?.total_pending),
      total_in: num(wallets?.total_inflow),
      total_out: num(wallets?.total_outflow),
    },
    period: {
      inflow,
      outflow: 0,
      net: inflow,
      transaction_count: num(revenue?.revenue_count),
    },
    withdrawals: {
      pending_count: num(withdrawals?.pending_count),
      processing_count: num(withdrawals?.processing_count),
      paid_count: num(withdrawals?.paid_count),
      failed_count: num(withdrawals?.failed_count),
      rejected_count: 0,
      cancelled_count: 0,
    },
  };
}
