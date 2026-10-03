// Super admin › Keuangan: double-entry finance journal across every gateway (Fase 4).
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { apiRequest, buildQuery } from './client';

export type FinanceProvider = 'ipaymu' | 'xendit' | 'doku' | 'manual';

export type FinanceProviderSummary = {
  provider: FinanceProvider;
  label: string;
  transactions: number;
  gross: number;
  gateway_fees: number;
  journal_balance: number;
  live_balance: { available: number; pending: number | null; checked_at: string } | null;
};

export type FinanceTrendPoint = { date: string; gross: number; revenue: number; gateway_fees: number };

export type FinanceSummary = {
  range: { days: number; from: string; to: string };
  totals: {
    gross: number;
    transactions: number;
    revenue: number;
    revenue_by_account: Record<string, number>;
    gateway_fees: number;
    adjustments: number;
    hellom_net: number;
  };
  liabilities: { seller_pending: number; seller_available: number; seller_processing: number; wallets: number; refunds: number };
  providers: FinanceProviderSummary[];
  sources: Array<{ source: string; transactions: number; gross: number }>;
  trend: FinanceTrendPoint[];
  top_sellers: Array<{ organization_id: number; name: string; orders: number; gross: number; platform_fee: number }>;
  journal: { entries: number; last_entry_at: string | null };
};

export type FinanceJournalLineRow = { account: string; account_type: string; amount: number };

export type FinanceTransaction = {
  id: number;
  event_key: string;
  event_type: string;
  source: string;
  source_type: string | null;
  source_id: number | null;
  provider: string | null;
  organization: { id: number; name: string } | null;
  amount: number;
  description: string | null;
  occurred_at: string | null;
  lines: FinanceJournalLineRow[];
};

export type FinanceTransactionFilters = {
  provider?: string;
  source?: string;
  event_type?: string;
  organization_id?: number;
  q?: string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
};

export type FinanceTransactionPage = {
  items: FinanceTransaction[];
  pagination: { page: number; per_page: number; total: number; last_page: number };
};

export function getFinanceJournalSummary(days: number, refresh = false) {
  return apiRequest<FinanceSummary>(`/admin/finance-journal/summary${buildQuery({ days, refresh: refresh ? 1 : undefined })}`);
}

export function getFinanceJournalTransactions(filters: FinanceTransactionFilters) {
  return apiRequest<FinanceTransactionPage>(`/admin/finance-journal/transactions${buildQuery(filters)}`);
}
