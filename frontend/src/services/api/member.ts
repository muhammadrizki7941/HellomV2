// Member dashboard cards and polling helpers.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { getWalletOverview } from './billing';
import { apiRequest } from './client';

// ─── Dashboard Cards ───

export function getMemberDashboardCards() {
  return apiRequest<{ cards: Array<Record<string, unknown>> }>('/member/dashboard/cards');
}

/** Poll until the given app slug becomes allowed (entitlement activated). */
export async function pollAppEntitlement(appSlug: string): Promise<boolean> {
  const res = await getMemberDashboardCards();
  const card = (res.cards || []).find((c: any) => c.app?.slug === appSlug) as any;
  return Boolean(card?.entitlement?.allowed);
}

/** Returns current wallet available balance for polling. */
export async function pollWalletBalance(): Promise<number> {
  const res = await getWalletOverview() as any;
  return Number(res?.wallet?.available_balance ?? 0);
}
