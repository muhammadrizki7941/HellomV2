// Member dashboard cards and polling helpers.
// Part of the Hellom API client; import from '@/lib/hellomApi' or '@/services/api'.
import { getWalletOverview } from './billing';
import { apiRequest } from './client';

// ─── Dashboard Cards ───

// MemberDashboardController::cards: one card per app with this org's access.
export type MemberDashboardCard = {
  app: { slug: string; name: string; icon_url?: string | null; [key: string]: unknown };
  entitlement: { status: string; allowed: boolean; plan: { slug: string; name: string; type: string; price: number } | null; [key: string]: unknown };
  card: { badge: string; [key: string]: unknown };
  promotion?: { type: 'one_time_product'; slug: string; price: number; target: string };
  [key: string]: unknown;
};

export function getMemberDashboardCards() {
  return apiRequest<{ cards: MemberDashboardCard[]; promoted_cards: MemberDashboardCard[] }>('/member/dashboard/cards');
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
