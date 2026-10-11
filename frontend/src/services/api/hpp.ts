import { apiRequest, apiRequestBlob, buildQuery } from './client';

// Kalkulator HPP (dashboard app "hpp"): backend App\Http\Controllers\Api\V1\Hellom\Hpp\*.
// One engine for Mode Cepat / Resep / Pro; numbers are per unit of the product.

export type HppMode = 'quick' | 'recipe' | 'pro';
export type HppRounding = 'none' | '100' | '500' | '1000' | 'psych';

export type HppSettings = {
  mode: HppMode;
  business_type: string;
  target_margin_pct: number;
  rounding: HppRounding;
  price_method: 'last' | 'average' | 'fifo';
  labor_rate_per_hour: number;
  overhead_method: 'per_unit' | 'revenue_pct' | 'labor_hour';
  monthly_units: number;
  monthly_revenue: number;
  monthly_labor_hours: number;
  main_channel_id: number | null;
  backflush_enabled: boolean;
  per_outlet_prices: boolean;
  onboarded: boolean;
};

export type HppOptions = {
  business_types: Array<{ key: string; label: string }>;
  use_units: Array<{ key: string; label: string }>;
  buy_units: Array<{ key: string; use_unit: string | null; factor: number | null }>;
};

export type HppCost = { material: number; packaging: number; labor: number; other: number; direct: number; overhead: number; full: number };

export type HppProductSummary = {
  id: number;
  name: string;
  mode: 'quick' | 'recipe';
  is_subrecipe: boolean;
  pos_product_id: number | null;
  yield_qty: number;
  yield_unit: string;
  selling_price: number;
  target_margin_pct: number;
  cost: HppCost;
  /** No recipe / batch cost yet: margin, food cost and BEP are left empty. */
  incomplete: boolean;
  margin_pct: number | null;
  food_cost_pct: number | null;
  food_cost_status: 'green' | 'yellow' | 'red' | null;
  suggested_price: number | null;
  main_channel: { id: number; name: string; fee_pct: number } | null;
  channels: HppChannelPrice[];
  variants: HppVariantSummary[];
  bep: { units_per_month: number; units_per_day: number; contribution: number } | null;
  is_archived: boolean;
};

export type HppVariantSummary = {
  id: number;
  name: string;
  kind: 'size' | 'modifier';
  price_delta: number;
  pos_option_value_id: number | null;
  extra_cost: number;
  full: number;
  price: number;
  margin_pct: number | null;
  suggested_price: number | null;
};

export type HppAlert = {
  product_id: number;
  name: string;
  severity: 'warning' | 'critical';
  margin_pct: number;
  target_margin_pct: number;
  food_cost_pct: number | null;
  selling_price: number;
  suggested_price: number | null;
  pos_product_id: number | null;
  cause: { ingredient_id: number; name: string; change_pct: number } | null;
  message: string;
};

export type HppChannelPrice = {
  id: number;
  name: string;
  kind: string;
  fee_pct: number;
  fee_flat: number;
  price: number | null;
  own_price: boolean;
  margin_pct: number | null;
  suggested_price: number | null;
  is_main: boolean;
};

export type HppLine = {
  id: number;
  component_type: 'ingredient' | 'product';
  component_id: number;
  name: string;
  unit: string;
  kind: string;
  qty: number;
  unit_cost: number;
  cost: number;
  note: string | null;
};

export type HppProductDetail = HppProductSummary & {
  quick_batch_cost: number;
  labor_minutes: number;
  other_cost: number;
  own_target_margin_pct: number | null;
  lines: HppLine[];
  channel_prices: Record<string, number>;
  variant_lines: Record<string, HppLine[]>;
  trend: Array<{ date: string; cost: number; price: number; margin_pct: number | null }>;
};

export type HppIngredient = {
  id: number;
  name: string;
  kind: 'ingredient' | 'packaging';
  category: string | null;
  use_unit: string;
  buy_unit: string;
  buy_factor: number;
  buy_price: number;
  cost_per_unit: number;
  yield_pct: number;
  effective_cost: number;
  is_archived: boolean;
  used_in?: number;
  stock?: number;
};

export type HppOverview = {
  settings: HppSettings;
  counts: { products: number; ingredients: number; without_price: number; incomplete: number; red: number; below_target: number };
  overhead_total: number;
  alerts: HppAlert[];
  pro: HppPro;
  outlets: Array<{ id: number; name: string }>;
  products: HppProductSummary[];
  options: HppOptions;
};

export type HppProductInput = {
  name: string;
  mode: 'quick' | 'recipe';
  is_subrecipe?: boolean;
  pos_product_id?: number | null;
  selling_price?: number;
  target_margin_pct?: number | null;
  yield_qty: number;
  yield_unit: string;
  quick_batch_cost?: number;
  labor_minutes?: number;
  other_cost?: number;
  items?: Array<{ component_type: 'ingredient' | 'product'; component_id: number; qty: number; note?: string | null }>;
  channel_prices?: Record<string, number>;
  variants?: Array<{ name: string; kind: 'size' | 'modifier'; price_delta: number; pos_option_value_id: number | null; items: Array<{ component_type: 'ingredient' | 'product'; component_id: number; qty: number }> }>;
};

export type HppIngredientInput = {
  name: string;
  kind: 'ingredient' | 'packaging';
  category?: string | null;
  use_unit: string;
  buy_unit: string;
  buy_factor?: number | null;
  buy_price: number;
  yield_pct?: number;
};

export function getHppOverview() {
  return apiRequest<HppOverview>('/hpp/overview');
}

export function updateHppSettings(body: Partial<Omit<HppSettings, 'onboarded'>>) {
  return apiRequest<{ settings: HppSettings }>('/hpp/settings', { method: 'PUT', body });
}

export function onboardHpp(body: { business_type: string; mode: 'quick' | 'recipe'; use_template: boolean }) {
  return apiRequest<{ settings: HppSettings }>('/hpp/onboarding', { method: 'POST', body });
}

export function getHppIngredients() {
  return apiRequest<{ ingredients: HppIngredient[] }>('/hpp/ingredients');
}

export function saveHppIngredient(body: HppIngredientInput, id?: number) {
  return apiRequest<{ ingredient: HppIngredient }>(id ? `/hpp/ingredients/${id}` : '/hpp/ingredients', { method: id ? 'PUT' : 'POST', body });
}

export function deleteHppIngredient(id: number) {
  return apiRequest<{ archived: boolean }>(`/hpp/ingredients/${id}`, { method: 'DELETE' });
}

export function getHppProducts(outletId?: number | null) {
  return apiRequest<{ products: HppProductSummary[] }>(`/hpp/products${buildQuery({ outlet_id: outletId ?? undefined })}`);
}

export function getHppProduct(id: number) {
  return apiRequest<{ product: HppProductDetail }>(`/hpp/products/${id}`);
}

export function saveHppProduct(body: HppProductInput, id?: number) {
  return apiRequest<{ product: HppProductDetail }>(id ? `/hpp/products/${id}` : '/hpp/products', { method: id ? 'PUT' : 'POST', body });
}

export function deleteHppProduct(id: number) {
  return apiRequest<{ archived: boolean }>(`/hpp/products/${id}`, { method: 'DELETE' });
}

export function applyHppPrice(id: number, price: number) {
  return apiRequest<{ product: HppProductDetail }>(`/hpp/products/${id}/apply-price`, { method: 'POST', body: { price } });
}

export function getHppPosProducts() {
  return apiRequest<{ products: Array<{ id: number; name: string; price: number; hpp_product_id: number | null }> }>('/hpp/pos-products');
}

export function importHppPosProducts() {
  return apiRequest<{ created: number }>('/hpp/products/import-pos', { method: 'POST' });
}

// ── Tahap 2: biaya tetap, channel, pembelian, laporan ──
export type HppOverhead = { id: number; name: string; category: string; amount: number; outlet_id: number | null; is_active: boolean };
export type HppChannel = { id: number; name: string; kind: string; fee_pct: number; fee_flat: number; is_active: boolean };
export type HppCosts = { overheads: HppOverhead[]; overhead_total: number; channels: HppChannel[]; outlets: Array<{ id: number; name: string }> };
export type HppPurchase = {
  id: number;
  purchased_at: string;
  supplier: string | null;
  total: number;
  outlet: { id: number; name: string | null } | null;
  notes: string | null;
  items: Array<{ ingredient_id: number; name: string; unit: string; qty: number; total_price: number; unit_cost: number; change_pct: number | null }>;
};
export type HppPriceChange = { ingredient_id: number; name: string; unit: string; previous_cost: number; new_cost: number; change_pct: number | null };
export type HppProductReportRow = HppProductSummary & { profit_per_unit: number | null };
export type HppProductReport = {
  products: HppProductReportRow[];
  most_profitable: HppProductReportRow[];
  most_costly: HppProductReportRow[];
  overhead_total: number;
  settings: { overhead_method: string; monthly_units: number };
};

export function getHppCosts() {
  return apiRequest<HppCosts>('/hpp/costs');
}

export function saveHppOverhead(body: { name: string; category: string; amount: number; outlet_id?: number | null; is_active?: boolean }, id?: number) {
  return apiRequest<HppCosts>(id ? `/hpp/overheads/${id}` : '/hpp/overheads', { method: id ? 'PUT' : 'POST', body });
}

export function deleteHppOverhead(id: number) {
  return apiRequest<HppCosts>(`/hpp/overheads/${id}`, { method: 'DELETE' });
}

export function loadHppOverheadTemplate() {
  return apiRequest<HppCosts>('/hpp/overheads/template', { method: 'POST' });
}

export function saveHppChannel(body: { name: string; kind: string; fee_pct: number; fee_flat?: number; is_active?: boolean }, id?: number) {
  return apiRequest<HppCosts>(id ? `/hpp/channels/${id}` : '/hpp/channels', { method: id ? 'PUT' : 'POST', body });
}

export function deleteHppChannel(id: number) {
  return apiRequest<HppCosts>(`/hpp/channels/${id}`, { method: 'DELETE' });
}

export function getHppPurchases() {
  return apiRequest<{ purchases: HppPurchase[]; outlets: Array<{ id: number; name: string }> }>('/hpp/purchases');
}

export function createHppPurchase(body: { purchased_at: string; supplier?: string; notes?: string; outlet_id?: number | null; items: Array<{ ingredient_id: number; qty: number; total_price: number }> }) {
  return apiRequest<{ purchase_id: number; changes: HppPriceChange[] }>('/hpp/purchases', { method: 'POST', body });
}

export function getHppProductReport() {
  return apiRequest<HppProductReport>('/hpp/reports/products');
}

// ── Tahap 3: varian/modifier dari POS, peringatan margin ──
export type HppPosOption = { id: number; name: string; values: Array<{ id: number; name: string; price_delta: number }> };

export function getHppPosOptions(posProductId: number) {
  return apiRequest<{ options: HppPosOption[] }>(`/hpp/pos-products/${posProductId}/options`);
}

export function getHppAlerts() {
  return apiRequest<{ alerts: HppAlert[] }>('/hpp/alerts');
}

// ── Tahap 4: Pro (gratis untuk pelanggan POS Kasir, atau langganan hpp_pro_*) ──
export type HppPro = { active: boolean; source: 'pos' | 'hpp' | null; plans: Array<{ slug: string; name: string; price: number; billing_cycles: string[] }> };
export type HppStockCountSummary = { id: number; counted_at: string; outlet_id: number | null; notes: string | null; items: number };
export type HppVarianceRow = {
  ingredient_id: number; name: string; unit: string; opening: number; purchased: number; closing: number;
  theoretical: number; actual: number; variance: number; variance_pct: number | null; value: number; flag: boolean;
};
export type HppVariance = { from: HppStockCountSummary | null; to: HppStockCountSummary | null; rows: HppVarianceRow[]; total_value: number };
export type HppSimulationRow = {
  id: number; name: string; selling_price: number; incomplete: boolean; cost_now: number; cost_sim: number;
  margin_now: number | null; margin_sim: number | null; suggested_now: number | null; suggested_sim: number | null; target_margin_pct: number;
};
export type HppOutletPrices = {
  outlet_id: number | null;
  outlets: Array<{ id: number; name: string }>;
  ingredients: Array<{ id: number; name: string; use_unit: string; cost_per_unit: number; outlet_cost: number | null }>;
};

export function getHppStockCounts(outletId?: number | null) {
  return apiRequest<{ counts: HppStockCountSummary[]; outlets: Array<{ id: number; name: string }> }>(`/hpp/pro/stock-counts${buildQuery({ outlet_id: outletId ?? undefined })}`);
}

export function createHppStockCount(body: { outlet_id?: number | null; notes?: string; items: Array<{ ingredient_id: number; counted_qty: number }> }) {
  return apiRequest<{ count: HppStockCountSummary }>('/hpp/pro/stock-counts', { method: 'POST', body });
}

export function getHppVariance(outletId?: number | null) {
  return apiRequest<HppVariance>(`/hpp/pro/variance${buildQuery({ outlet_id: outletId ?? undefined })}`);
}

export function simulateHpp(body: { ingredients: Array<{ id: number; pct: number }>; overhead_pct?: number; outlet_id?: number | null }) {
  return apiRequest<{ products: HppSimulationRow[] }>('/hpp/pro/simulate', { method: 'POST', body });
}

export function getHppOutletPrices(outletId?: number | null) {
  return apiRequest<HppOutletPrices>(`/hpp/pro/outlet-prices${buildQuery({ outlet_id: outletId ?? undefined })}`);
}

export function saveHppOutletPrice(body: { outlet_id: number; ingredient_id: number; cost_per_unit: number | null }) {
  return apiRequest<{ saved: boolean }>('/hpp/pro/outlet-prices', { method: 'PUT', body });
}

/** CSV (Excel) download of the products report or the stock variance. */
export async function downloadHppExport(type: 'products' | 'variance', outletId?: number | null) {
  const blob = await apiRequestBlob(`/hpp/pro/export${buildQuery({ type, outlet_id: outletId ?? undefined })}`);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `hpp-${type}-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
