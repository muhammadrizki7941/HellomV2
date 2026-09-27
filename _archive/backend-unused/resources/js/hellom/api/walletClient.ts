import type {
  AdminPayoutQueueData,
  ApiErrorEnvelope,
  ApiSuccessEnvelope,
  FinanceSummaryData,
  FinanceSummaryQuery,
  LoginData,
  LoginInput,
  PayoutHistoryData,
  ReconciliationInput,
  SubscriptionRenewWalletData,
  SubscriptionWalletAutoRenewData,
  SubscriptionWalletAutoRenewInput,
  TransactionsQuery,
  WalletCheckoutConfirmData,
  WalletCheckoutConfirmInput,
  WalletAutoRenewPreviewData,
  WalletAutoRenewPreviewQuery,
  WalletPayoutPolicyData,
  WalletPayoutPolicyQuery,
  WalletOverviewData,
  WalletTopupData,
  WalletTopupInput,
  WalletTransactionsData,
  WithdrawalActionData,
  WithdrawalRequestData,
  WithdrawalRequestInput,
  WithdrawalsData,
  WithdrawalsQuery,
} from './wallet.types';

export interface HellomWalletClientConfig {
  baseUrl: string;
  getToken?: () => string | null | undefined;
  defaultHeaders?: Record<string, string>;
}

export class HellomApiError extends Error {
  readonly status: number;
  readonly payload: ApiErrorEnvelope | null;

  constructor(message: string, status: number, payload: ApiErrorEnvelope | null = null) {
    super(message);
    this.name = 'HellomApiError';
    this.status = status;
    this.payload = payload;
  }
}

function toQueryString(query?: Record<string, string | number | undefined | null>): string {
  if (!query) {
    return '';
  }

  const params = new URLSearchParams();

  Object.entries(query).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') {
      return;
    }
    params.set(key, String(value));
  });

  const qs = params.toString();
  return qs ? `?${qs}` : '';
}

export class HellomWalletClient {
  private readonly baseUrl: string;
  private readonly getToken?: () => string | null | undefined;
  private readonly defaultHeaders: Record<string, string>;

  constructor(config: HellomWalletClientConfig) {
    this.baseUrl = config.baseUrl.replace(/\/$/, '');
    this.getToken = config.getToken;
    this.defaultHeaders = config.defaultHeaders ?? {};
  }

  private async request<T>(
    method: 'GET' | 'POST',
    path: string,
    options?: {
      query?: Record<string, string | number | undefined | null>;
      body?: unknown;
      tokenOverride?: string | null;
      authRequired?: boolean;
    }
  ): Promise<ApiSuccessEnvelope<T>> {
    const authRequired = options?.authRequired ?? true;
    const token = options?.tokenOverride ?? this.getToken?.();

    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...this.defaultHeaders,
    };

    if (options?.body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    if (authRequired && token) {
      headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(`${this.baseUrl}${path}${toQueryString(options?.query)}`, {
      method,
      headers,
      body: options?.body !== undefined ? JSON.stringify(options.body) : undefined,
    });

    let parsed: unknown = null;
    try {
      parsed = await response.json();
    } catch {
      parsed = null;
    }

    if (!response.ok) {
      const errorPayload = (parsed as ApiErrorEnvelope | null) ?? null;
      const message = errorPayload?.message || `HTTP ${response.status}`;
      throw new HellomApiError(message, response.status, errorPayload);
    }

    const successPayload = parsed as ApiSuccessEnvelope<T>;
    if (!successPayload || successPayload.success !== true) {
      throw new HellomApiError('Invalid success envelope from API', response.status, null);
    }

    return successPayload;
  }

  login(payload: LoginInput) {
    return this.request<LoginData>('POST', '/auth/login', {
      authRequired: false,
      body: payload,
    });
  }

  getOverview() {
    return this.request<WalletOverviewData>('GET', '/wallet/overview');
  }

  getFinanceSummary(query?: FinanceSummaryQuery) {
    return this.request<FinanceSummaryData>('GET', '/wallet/finance-summary', {
      query: {
        days: query?.days,
      },
    });
  }

  getTransactions(query?: TransactionsQuery) {
    return this.request<WalletTransactionsData>('GET', '/wallet/transactions', {
      query: {
        type: query?.type,
        limit: query?.limit,
        cursor: query?.cursor,
      },
    });
  }

  getWithdrawals(query?: WithdrawalsQuery) {
    return this.request<WithdrawalsData>('GET', '/wallet/withdrawals', {
      query: {
        status: query?.status,
        limit: query?.limit,
        cursor: query?.cursor,
      },
    });
  }

  requestWithdrawal(payload: WithdrawalRequestInput) {
    return this.request<WithdrawalRequestData>('POST', '/wallet/withdrawals', {
      body: payload,
    });
  }

  getPayoutHistory(query?: WithdrawalsQuery) {
    return this.request<PayoutHistoryData>('GET', '/wallet/payout-history', {
      query: {
        status: query?.status,
        limit: query?.limit,
        cursor: query?.cursor,
      },
    });
  }

  getAdminPayoutQueue(query?: WithdrawalsQuery) {
    return this.request<AdminPayoutQueueData>('GET', '/wallet/admin/payout-queue', {
      query: {
        status: query?.status,
        limit: query?.limit,
        cursor: query?.cursor,
      },
    });
  }

  approveWithdrawal(withdrawalId: number) {
    return this.request<WithdrawalActionData>('POST', `/wallet/withdrawals/${withdrawalId}/approve`, {
      body: {},
    });
  }

  rejectWithdrawal(withdrawalId: number, payload?: ReconciliationInput) {
    return this.request<WithdrawalActionData>('POST', `/wallet/withdrawals/${withdrawalId}/reject`, {
      body: payload ?? {},
    });
  }

  markWithdrawalPaid(withdrawalId: number, payload?: ReconciliationInput) {
    return this.request<WithdrawalActionData>('POST', `/wallet/withdrawals/${withdrawalId}/mark-paid`, {
      body: payload ?? {},
    });
  }

  markWithdrawalFailed(withdrawalId: number, payload?: ReconciliationInput) {
    return this.request<WithdrawalActionData>('POST', `/wallet/withdrawals/${withdrawalId}/mark-failed`, {
      body: payload ?? {},
    });
  }

  cancelWithdrawal(withdrawalId: number) {
    return this.request<WithdrawalActionData>('POST', `/wallet/withdrawals/${withdrawalId}/cancel`, {
      body: {},
    });
  }

  walletTopupMock(payload: WalletTopupInput) {
    return this.request<WalletTopupData>('POST', '/billing/wallet/topup-mock', {
      body: payload,
    });
  }

  checkoutConfirmWallet(payload: WalletCheckoutConfirmInput) {
    return this.request<WalletCheckoutConfirmData>('POST', '/billing/checkout-confirm-wallet', {
      body: payload,
    });
  }

  renewSubscriptionWallet(subscriptionId: number) {
    return this.request<SubscriptionRenewWalletData>('POST', `/billing/subscriptions/${subscriptionId}/renew-wallet`, {
      body: {},
    });
  }

  setSubscriptionWalletAutoRenew(subscriptionId: number, payload: SubscriptionWalletAutoRenewInput) {
    return this.request<SubscriptionWalletAutoRenewData>('POST', `/billing/subscriptions/${subscriptionId}/auto-renew-wallet`, {
      body: payload,
    });
  }

  getWalletAutoRenewPreview(query?: WalletAutoRenewPreviewQuery) {
    return this.request<WalletAutoRenewPreviewData>('GET', '/billing/wallet/auto-renew-preview', {
      query: {
        days: query?.days,
        limit: query?.limit,
        include_overdue: query?.include_overdue === undefined ? undefined : (query.include_overdue ? 1 : 0),
      },
    });
  }

  getPayoutPolicy(query?: WalletPayoutPolicyQuery) {
    return this.request<WalletPayoutPolicyData>('GET', '/wallet/payout-policy', {
      query: {
        channel: query?.channel,
        amount: query?.amount,
      },
    });
  }
}

export function createHellomWalletClient(config: HellomWalletClientConfig): HellomWalletClient {
  return new HellomWalletClient(config);
}
