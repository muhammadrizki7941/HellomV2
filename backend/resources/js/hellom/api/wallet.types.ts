export type HellomRole = 'owner' | 'admin' | 'member';

export type WithdrawalStatus =
  | 'pending'
  | 'processing'
  | 'paid'
  | 'rejected'
  | 'cancelled'
  | 'failed';

export type WalletTransactionDirection = 'credit' | 'debit';

export interface ApiSuccessEnvelope<T> {
  success: true;
  message: string;
  data: T;
  error: null;
}

export interface ApiErrorEnvelope {
  success: false;
  message: string;
  data: null;
  error: unknown;
}

export type ApiEnvelope<T> = ApiSuccessEnvelope<T> | ApiErrorEnvelope;

export interface OrganizationSummary {
  id: number;
  name: string;
  slug: string;
}

export interface Wallet {
  id: number;
  organization_id: number;
  currency: string;
  available_balance: number;
  pending_balance: number;
  total_in: number;
  total_out: number;
  status: string;
  updated_at: string;
}

export interface WalletTransaction {
  id: number;
  type: string;
  direction: WalletTransactionDirection;
  amount: number;
  balance_after: number;
  reference_type: string | null;
  reference_id: string | null;
  external_ref: string | null;
  description: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface Withdrawal {
  id: number;
  status: WithdrawalStatus;
  amount: number;
  fee_amount: number;
  net_amount: number;
  bank_code: string | null;
  account_number_masked: string | null;
  account_name: string | null;
  provider: string;
  external_ref: string | null;
  provider_ref: string | null;
  notes: string | null;
  processed_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface WithdrawalActions {
  can_approve: boolean;
  can_reject: boolean;
  can_mark_paid: boolean;
  can_mark_failed: boolean;
  can_cancel: boolean;
}

export interface WithdrawalQueueItem extends Withdrawal {
  actions: WithdrawalActions;
}

export interface CursorPagination {
  has_more: boolean;
  next_cursor: number | null;
}

export interface ListFilters {
  status?: WithdrawalStatus | null;
  type?: string | null;
  limit: number;
  cursor: number | null;
}

export interface WalletOverviewData {
  organization: OrganizationSummary;
  requester_role: HellomRole;
  wallet: Wallet;
  recent_transactions: WalletTransaction[];
  pending_withdrawals: Withdrawal[];
}

export interface WalletTransactionsData {
  organization_id: number;
  requester_role: HellomRole;
  filters: ListFilters;
  pagination: CursorPagination;
  items: WalletTransaction[];
}

export interface WithdrawalsData {
  organization_id: number;
  requester_role: HellomRole;
  filters: ListFilters;
  pagination: CursorPagination;
  items: Withdrawal[];
}

export interface WithdrawalRequestData {
  wallet: Wallet;
  withdrawal: Withdrawal;
  next_step: {
    integration: 'xendit_disbursement' | string;
    external_ref: string;
  };
}

export interface PayoutHistoryData {
  organization_id: number;
  requester_role: HellomRole;
  filters: ListFilters;
  pagination: CursorPagination;
  summary: {
    total_requests: number;
    pending_or_processing: number;
    paid_count: number;
    rejected_or_cancelled: number;
  };
  items: Withdrawal[];
}

export interface AdminPayoutQueueData {
  organization: OrganizationSummary;
  requester_role: HellomRole;
  filters: ListFilters;
  pagination: CursorPagination;
  summary: {
    pending_count: number;
    processing_count: number;
    failed_count: number;
    paid_count: number;
  };
  items: WithdrawalQueueItem[];
}

export interface WithdrawalActionData {
  wallet?: Wallet;
  withdrawal: Withdrawal;
  next_step?: {
    integration: 'xendit_disbursement' | string;
    provider: string;
    external_ref: string;
    amount: number;
    bank_code: string;
    account_number: string;
    account_name: string;
  };
}

export interface FinanceSummaryData {
  organization: OrganizationSummary;
  requester_role: HellomRole;
  range: {
    days: number;
    start_at: string;
    end_at: string;
  };
  wallet: Wallet;
  period: {
    inflow: number;
    outflow: number;
    net: number;
    transaction_count: number;
  };
  withdrawals: {
    pending_count: number;
    processing_count: number;
    paid_count: number;
    failed_count: number;
    rejected_count: number;
    cancelled_count: number;
  };
}

export interface LoginData {
  token: string;
  user: Record<string, unknown>;
}

export interface WithdrawalRequestInput {
  amount: number;
  bank_code: string;
  account_number: string;
  account_name: string;
  notes?: string;
}

export interface CursorListQuery {
  limit?: number;
  cursor?: number;
}

export interface TransactionsQuery extends CursorListQuery {
  type?: string;
}

export interface WithdrawalsQuery extends CursorListQuery {
  status?: WithdrawalStatus;
}

export interface FinanceSummaryQuery {
  days?: number;
}

export interface LoginInput {
  email: string;
  password: string;
}

export interface ReconciliationInput {
  notes?: string;
  provider_ref?: string;
}

export interface WalletTopupInput {
  amount: number;
  source?: string;
  notes?: string;
}

export interface WalletTopupData {
  wallet: Wallet;
  topup: WalletTransaction;
}

export interface WalletCheckoutConfirmInput {
  intent_token: string;
}

export interface WalletCheckoutConfirmData {
  wallet: Wallet;
  intent_token: string;
  intent_status: string;
  subscription_status: string;
  app_slug: string;
  plan_slug: string;
}

export interface SubscriptionRenewWalletData {
  wallet: Wallet;
  subscription: {
    id: number;
    status: string;
    app_slug: string;
    plan_slug: string;
    starts_at: string;
    ends_at: string;
  };
}

export interface SubscriptionWalletAutoRenewInput {
  enabled: boolean;
}

export interface SubscriptionWalletAutoRenewData {
  subscription: {
    id: number;
    status: string;
    app_slug: string;
    plan_slug: string;
    wallet_auto_renew: boolean;
  };
}

export interface WalletAutoRenewPreviewQuery {
  days?: number;
  limit?: number;
  include_overdue?: boolean;
}

export interface WalletAutoRenewPreviewItem {
  subscription_id: number;
  status: string;
  amount: number;
  currency: string;
  billing_cycle: string;
  ends_at: string;
  due_state: 'overdue' | 'upcoming';
  wallet_auto_renew: boolean;
  can_auto_charge: boolean;
  reason: 'auto_renew_disabled' | 'invalid_amount' | 'insufficient_balance' | null;
  required_topup: number;
  app: {
    slug: string;
    name: string;
  };
  plan: {
    slug: string;
    name: string;
  };
}

export interface WalletAutoRenewPreviewData {
  organization_id: number;
  filters: {
    days: number;
    limit: number;
    include_overdue: boolean;
    horizon_at: string;
  };
  wallet: Wallet;
  summary: {
    total_due_count: number;
    overdue_count: number;
    upcoming_count: number;
    total_due_amount: number;
    payable_amount: number;
    unpayable_amount: number;
    projected_remaining_balance: number;
    minimum_topup_required: number;
  };
  items: WalletAutoRenewPreviewItem[];
}

export interface WalletPayoutPolicyQuery {
  channel?: string;
  amount?: number;
}

export interface WalletPayoutPolicyData {
  organization: OrganizationSummary;
  requester_role: HellomRole;
  query: {
    channel: string;
    amount: number | null;
  };
  policy: {
    default: Record<string, unknown>;
    channels: Record<string, unknown>;
    selected: Record<string, unknown>;
  };
  estimation: {
    fee_amount: number | null;
    net_amount: number | null;
  };
}
