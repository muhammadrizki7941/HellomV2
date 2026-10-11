import type { HppRounding } from '@/lib/hellomApi';

// Mirror of backend App\Services\Hpp\PriceAdvisor (live preview while typing; the saved numbers
// always come from the server):  price = (HPP + fee flat) ÷ (1 − margin − fee %), rounded UP.

export function roundPrice(price: number, rounding: HppRounding): number {
  const p = Math.ceil(price - 0.0001);
  switch (rounding) {
    case '100': return Math.ceil(p / 100) * 100;
    case '500': return Math.ceil(p / 500) * 500;
    case '1000': return Math.ceil(p / 1000) * 1000;
    case 'psych': {
      const candidate = Math.ceil((p + 100) / 1000) * 1000 - 100;
      return candidate >= p ? candidate : candidate + 1000;
    }
    default: return p;
  }
}

export function suggestPrice(cost: number, marginPct: number, rounding: HppRounding, feePct = 0, feeFlat = 0): number | null {
  const denominator = 1 - marginPct / 100 - feePct / 100;
  if (cost <= 0 || denominator <= 0.05) return null;
  return roundPrice((cost + feeFlat) / denominator, rounding);
}

export function marginPct(price: number, cost: number, feePct = 0, feeFlat = 0): number | null {
  if (price <= 0) return null;
  return Math.round(((price - cost - (price * feePct) / 100 - feeFlat) / price) * 1000) / 10;
}

export function foodCostStatus(pctValue: number | null): 'green' | 'yellow' | 'red' | null {
  if (pctValue === null) return null;
  return pctValue < 35 ? 'green' : pctValue <= 45 ? 'yellow' : 'red';
}

export const ROUNDING_LABELS: Record<HppRounding, string> = {
  none: 'Tanpa pembulatan', '100': 'Ke Rp100', '500': 'Ke Rp500', '1000': 'Ke ribuan', psych: 'Harga psikologis (Rp19.900)',
};
