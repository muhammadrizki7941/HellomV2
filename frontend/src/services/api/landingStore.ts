// Hellom Page selling (Fase 3): seller products, coupons, orders, buyers; public checkout
// (/beli/{id}), access page (/akses/{token}), "cek pesanan", reports; super admin
// moderation and refunds. Part of the Hellom API client; import from '@/lib/hellomApi'.
import { apiRequest, apiRequestBlob, buildQuery, publicApiRequest } from './client';
import type { Paginated } from './sellerFinance';

export type ProductType = 'drive' | 'file' | 'link' | 'physical' | 'service';
export type PaymentOption = 'qris' | 'other';

export type CheckoutField = {
  id: string;
  label: string;
  type: 'text' | 'textarea' | 'number' | 'select';
  required: boolean;
  options: string[];
};

// ─── Public ───

export type PublicProduct = {
  id: string;
  type: ProductType;
  type_label: string;
  name: string;
  description: string | null;
  image_url: string | null;
  price: number;
  compare_at_price: number | null;
  in_stock: boolean;
  stock_left: number | null;
  available: boolean;
  require_phone: boolean;
  checkout_fields: CheckoutField[];
  shipping: { mode: 'free' | 'flat' | 'manual'; fee: number } | null;
  max_quantity: number;
  file: { extension: string; size: number } | null;
};

export type PublicSeller = { name: string | null; slug: string | null; verified: boolean; suspended: boolean };

export type PublicProductPage = {
  product: PublicProduct;
  seller: PublicSeller;
  payment_options: PaymentOption[];
  min_total: number;
};

export type CheckoutQuote = {
  subtotal: number;
  discount: number;
  shipping: number;
  total: number;
  coupon: { code: string; type: string; value: number } | null;
  coupon_error: string | null;
};

export type CheckoutInput = {
  quantity?: number;
  coupon_code?: string;
  payment_method?: PaymentOption;
  buyer_name: string;
  buyer_email: string;
  buyer_phone?: string;
  fields?: Record<string, string>;
  shipping?: { recipient_name: string; phone: string; address: string; city: string; province?: string; postal_code: string; notes?: string };
};

export type CheckoutResult = {
  reference_id: string;
  mode: 'qris' | 'redirect';
  amount: number;
  product_name: string;
  status_url: string;
  expires_at: string | null;
  payment_url: string | null;
  qr_image_url?: string;
  qr_string?: string;
};

export type AccessPage = {
  reference: string;
  status: string;
  status_label: string;
  product_name: string;
  product_type: string;
  product_image_url: string | null;
  quantity: number;
  amount: number;
  buyer_name: string | null;
  paid_at: string | null;
  seller: { name: string | null; slug: string | null; phone: string | null };
  delivery_note: string | null;
  access: {
    kind: 'download' | 'link';
    expires_at: string | null;
    expired: boolean;
    opens_used: number;
    opens_max: number | null;
    downloads_used: number;
    downloads_max: number | null;
    file_name: string | null;
    file_size: number | null;
    available: boolean;
    blocked_reason: string | null;
  } | null;
  shipping: {
    address: Record<string, string> | null;
    shipped_at: string | null;
    courier: string | null;
    tracking_number: string | null;
  } | null;
  custom_fields: Array<{ label: string; value: string }>;
};

export const REPORT_REASONS: Record<string, string> = {
  scam: 'Penipuan / produk tidak dikirim',
  prohibited: 'Produk terlarang',
  copyright: 'Melanggar hak cipta',
  adult: 'Konten dewasa / kekerasan',
  other: 'Lainnya',
};

export function getPublicLandingProduct(publicId: string) {
  return publicApiRequest<PublicProductPage>(`/public/landing-products/${encodeURIComponent(publicId)}`);
}

export function quoteLandingProduct(publicId: string, body: { quantity?: number; coupon_code?: string }) {
  return publicApiRequest<CheckoutQuote>(`/public/landing-products/${encodeURIComponent(publicId)}/quote`, { method: 'POST', body });
}

export function checkoutLandingProduct(publicId: string, body: CheckoutInput) {
  return publicApiRequest<CheckoutResult>(`/public/landing-products/${encodeURIComponent(publicId)}/checkout`, { method: 'POST', body });
}

export function getLandingAccess(token: string) {
  return publicApiRequest<AccessPage>(`/public/landing-access/${encodeURIComponent(token)}`);
}

export function openLandingAccess(token: string) {
  return publicApiRequest<{ url: string }>(`/public/landing-access/${encodeURIComponent(token)}/open`, { method: 'POST' });
}

export function resendLandingAccess(token: string) {
  return publicApiRequest<{ sent: boolean }>(`/public/landing-access/${encodeURIComponent(token)}/resend`, { method: 'POST' });
}

export function lookupLandingOrder(email: string, reference: string) {
  return publicApiRequest<{ sent: boolean }>('/public/landing-orders/lookup', { method: 'POST', body: { email, reference } });
}

export function reportLandingPage(body: {
  organization_slug?: string;
  product_id?: string;
  landing_page_id?: number;
  reason: string;
  description?: string;
  reporter_email?: string;
  page_url?: string;
}) {
  return publicApiRequest<{ received: boolean }>('/public/landing-reports', { method: 'POST', body });
}

// ─── Seller: products ───

export type SellerProduct = PublicProduct & {
  db_id: number;
  is_active: boolean;
  stock: number | null;
  sold_count: number;
  delivery_url: string | null;
  delivery_note: string | null;
  drive_link: { kind: string; id: string; url: string } | null;
  file_name: string | null;
  file_size: number | null;
  access_max_opens: number | null;
  access_days: number | null;
  download_limit: number | null;
  shipping_mode: 'free' | 'flat' | 'manual' | null;
  shipping_fee: number;
  weight_grams: number | null;
  raw_checkout_fields: CheckoutField[];
  deliverable: boolean;
  admin_disabled: boolean;
  admin_disabled_reason: string | null;
  checkout_url: string;
  created_at: string | null;
  updated_at: string | null;
};

export type ProductInput = {
  type: ProductType;
  name: string;
  description?: string | null;
  price: number;
  compare_at_price?: number | null;
  stock?: number | null;
  is_active?: boolean;
  require_phone?: boolean;
  delivery_url?: string | null;
  delivery_note?: string | null;
  access_max_opens?: number | null;
  access_days?: number | null;
  download_limit?: number | null;
  shipping_mode?: 'free' | 'flat' | 'manual' | null;
  shipping_fee?: number | null;
  weight_grams?: number | null;
  checkout_fields?: Array<Pick<CheckoutField, 'label' | 'type' | 'required' | 'options'>>;
};

export type ProductLimits = { max_file_mb: number; file_extensions: string[] };

const PRODUCTS = '/apps/landing-builder/products';

export function getSellerProducts() {
  return apiRequest<{ items: SellerProduct[]; limits: ProductLimits }>(PRODUCTS);
}

export function createSellerProduct(body: ProductInput) {
  return apiRequest<SellerProduct>(PRODUCTS, { method: 'POST', body });
}

export function updateSellerProduct(id: number, body: ProductInput) {
  return apiRequest<SellerProduct>(`${PRODUCTS}/${id}`, { method: 'PUT', body });
}

export function toggleSellerProduct(id: number, isActive: boolean) {
  return apiRequest<SellerProduct>(`${PRODUCTS}/${id}/toggle`, { method: 'POST', body: { is_active: isActive } });
}

export function deleteSellerProduct(id: number) {
  return apiRequest<{ deleted: boolean }>(`${PRODUCTS}/${id}`, { method: 'DELETE' });
}

// FormData: `image`. Do not set Content-Type manually.
export function uploadSellerProductImage(id: number, form: FormData) {
  return apiRequest<SellerProduct>(`${PRODUCTS}/${id}/image`, { method: 'POST', body: form });
}

// FormData: `file` (max 10 MB).
export function uploadSellerProductFile(id: number, form: FormData) {
  return apiRequest<SellerProduct>(`${PRODUCTS}/${id}/file`, { method: 'POST', body: form });
}

export function deleteSellerProductFile(id: number) {
  return apiRequest<SellerProduct>(`${PRODUCTS}/${id}/file`, { method: 'DELETE' });
}

export function checkDriveLink(url: string) {
  return apiRequest<{ valid: boolean; kind: string | null }>(`${PRODUCTS}/check-drive-link`, { method: 'POST', body: { url } });
}

// ─── Seller: coupons ───

export type SellerCoupon = {
  id: number;
  code: string;
  type: 'percent' | 'fixed';
  value: number;
  max_discount: number | null;
  min_purchase: number;
  max_uses: number | null;
  used_count: number;
  product_ids: number[];
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  status: 'active' | 'inactive' | 'ended' | 'used_up' | 'scheduled';
};

export type CouponInput = {
  code: string;
  type: 'percent' | 'fixed';
  value: number;
  max_discount?: number | null;
  min_purchase?: number | null;
  max_uses?: number | null;
  product_ids?: number[];
  starts_at?: string | null;
  ends_at?: string | null;
  is_active?: boolean;
};

const COUPONS = '/apps/landing-builder/coupons';

export function getSellerCoupons() {
  return apiRequest<{ items: SellerCoupon[] }>(COUPONS);
}

export function createSellerCoupon(body: CouponInput) {
  return apiRequest<SellerCoupon>(COUPONS, { method: 'POST', body });
}

export function updateSellerCoupon(id: number, body: CouponInput) {
  return apiRequest<SellerCoupon>(`${COUPONS}/${id}`, { method: 'PUT', body });
}

export function deleteSellerCoupon(id: number) {
  return apiRequest<{ deleted: boolean }>(`${COUPONS}/${id}`, { method: 'DELETE' });
}

// ─── Seller: orders & buyers ───

export type SellerOrderRow = {
  id: number;
  reference: string;
  status: 'pending' | 'paid' | 'fulfilled' | 'expired' | 'failed' | 'refunded';
  status_label: string;
  needs_action: boolean;
  product_name: string;
  product_type: string;
  quantity: number;
  amount: number;
  net_amount: number;
  buyer_name: string | null;
  buyer_email: string | null;
  created_at: string | null;
  paid_at: string | null;
};

export type SellerOrderDetail = SellerOrderRow & {
  buyer_phone: string | null;
  subtotal_amount: number;
  discount_amount: number;
  coupon_code: string | null;
  shipping_amount: number;
  commission_amount: number;
  payment_method: string | null;
  payment_channel: string | null;
  shipping_address: Record<string, string> | null;
  custom_fields: Array<{ label: string; value: string }>;
  shipping_courier: string | null;
  tracking_number: string | null;
  shipped_at: string | null;
  fulfilled_at: string | null;
  expires_at: string | null;
  access: { opens_used: number; opens_max: number | null; downloads_used: number; downloads_max: number | null; expires_at: string | null; last_opened_at: string | null } | null;
  emails_sent_at: string | null;
  email_resend_count: number;
  oversold: boolean;
  refunds: Array<{ reference: string; status: string; status_label: string; amount: number; reason: string; failure_reason: string | null; created_at: string | null; paid_at: string | null }>;
  can_refund: boolean;
};

export type SellerSalesSummary = {
  today: { orders: number; revenue: number };
  month: { orders: number; revenue: number; net: number };
  balance_available: number;
  to_process: number;
  products: number;
  chart: Array<{ date: string; orders: number; revenue: number }>;
  recent: SellerOrderRow[];
};

export type OrderFilters = {
  status?: 'all' | 'paid' | 'to_process' | 'pending' | 'fulfilled' | 'refunded' | 'expired' | 'failed';
  product_id?: number;
  q?: string;
  from?: string;
  to?: string;
  page?: number;
};

export type SellerBuyer = { email: string; name: string | null; phone: string | null; orders: number; total_spent: number; last_order_at: string | null };

export type RefundInput = {
  amount: number;
  reason: string;
  destination_type: 'bank' | 'ewallet';
  bank_code: string;
  bank_name?: string;
  account_number: string;
  account_name: string;
};

export function getSellerSalesSummary() {
  return apiRequest<SellerSalesSummary>('/seller/orders/summary');
}

export function getSellerOrders(filters: OrderFilters = {}) {
  return apiRequest<Paginated<SellerOrderRow>>(`/seller/orders${buildQuery(filters)}`);
}

export function getSellerOrder(id: number) {
  return apiRequest<SellerOrderDetail>(`/seller/orders/${id}`);
}

export function resendSellerOrderEmail(id: number) {
  return apiRequest<{ sent: boolean }>(`/seller/orders/${id}/resend`, { method: 'POST' });
}

export function fulfillSellerOrder(id: number, body: { courier?: string; tracking_number?: string } = {}) {
  return apiRequest<SellerOrderDetail>(`/seller/orders/${id}/fulfill`, { method: 'POST', body });
}

export function refundSellerOrder(id: number, body: RefundInput) {
  return apiRequest<SellerOrderDetail>(`/seller/orders/${id}/refund`, { method: 'POST', body });
}

export function exportSellerOrders(filters: OrderFilters = {}) {
  return apiRequestBlob(`/seller/orders/export${buildQuery({ ...filters, page: undefined })}`);
}

export function getSellerBuyers(q?: string, page = 1) {
  return apiRequest<Paginated<SellerBuyer>>(`/seller/orders/buyers${buildQuery({ q, page })}`);
}

export function exportSellerBuyers() {
  return apiRequestBlob('/seller/orders/buyers/export');
}

export function sendEmailVerification() {
  return apiRequest<{ email_verified: boolean }>('/account/email/verification', { method: 'POST' });
}

// ─── Super admin: moderation & refunds ───

export type AdminReport = {
  id: number;
  reason: string;
  reason_label: string;
  description: string | null;
  reporter_email: string | null;
  page_url: string | null;
  status: 'open' | 'reviewing' | 'resolved' | 'dismissed';
  resolution_note: string | null;
  created_at: string | null;
  handled_at: string | null;
  organization: { id: number; name: string; slug: string; suspended: boolean } | null;
  product: { id: number; public_id: string; name: string; disabled: boolean } | null;
};

export type AdminSeller = {
  id: number;
  name: string;
  slug: string;
  verified: boolean;
  suspended: boolean;
  suspended_reason: string | null;
  balance_frozen: boolean;
  balance_available: number;
  balance_pending: number;
  open_reports: number;
  products: number;
  paid_orders: number;
};

export type AdminLandingProduct = {
  id: number;
  public_id: string;
  name: string;
  type: ProductType;
  type_label: string;
  price: number;
  is_active: boolean;
  sold_count: number;
  disabled: boolean;
  disabled_reason: string | null;
  organization: { id: number; name: string; slug: string } | null;
};

export type AdminRefund = {
  id: number;
  reference: string;
  status: 'requested' | 'paid' | 'failed';
  status_label: string;
  amount: number;
  reason: string;
  destination_type: string;
  bank_code: string;
  bank_name: string | null;
  account_number: string;
  account_name: string;
  failure_reason: string | null;
  has_proof: boolean;
  age_hours: number;
  created_at: string | null;
  paid_at: string | null;
  organization: { id: number; name: string | null; slug: string | null };
  order: { reference: string; product_name: string; amount: number; buyer_name: string | null; buyer_email: string | null } | null;
};

const MOD = '/admin/landing-moderation';

export function getAdminLandingReports(status = 'open', page = 1) {
  return apiRequest<Paginated<AdminReport>>(`${MOD}/reports${buildQuery({ status, page })}`);
}

export function updateAdminLandingReport(id: number, status: AdminReport['status'], resolution_note?: string) {
  return apiRequest<{ id: number; status: string }>(`${MOD}/reports/${id}`, { method: 'PATCH', body: { status, resolution_note } });
}

export function getAdminLandingSellers(params: { q?: string; filter?: string; page?: number } = {}) {
  return apiRequest<Paginated<AdminSeller>>(`${MOD}/sellers${buildQuery(params)}`);
}

export function suspendAdminLandingSeller(id: number, suspended: boolean, reason?: string) {
  return apiRequest<{ suspended: boolean }>(`${MOD}/sellers/${id}/suspend`, { method: 'POST', body: { suspended, reason } });
}

export function getAdminLandingProducts(params: { organization_id?: number; q?: string; page?: number } = {}) {
  return apiRequest<Paginated<AdminLandingProduct>>(`${MOD}/products${buildQuery(params)}`);
}

export function disableAdminLandingProduct(id: number, disabled: boolean, reason?: string) {
  return apiRequest<{ disabled: boolean }>(`${MOD}/products/${id}/disable`, { method: 'POST', body: { disabled, reason } });
}

export function getAdminRefunds(status = 'requested', page = 1) {
  return apiRequest<Paginated<AdminRefund>>(`/admin/seller-finance/refunds${buildQuery({ status, page })}`);
}

// FormData: optional `proof`.
export function markAdminRefundPaid(id: number, form: FormData) {
  return apiRequest<{ id: number; status: string }>(`/admin/seller-finance/refunds/${id}/mark-paid`, { method: 'POST', body: form });
}

export function markAdminRefundFailed(id: number, reason: string) {
  return apiRequest<{ id: number; status: string }>(`/admin/seller-finance/refunds/${id}/mark-failed`, { method: 'POST', body: { reason } });
}

export function downloadAdminRefundProof(id: number) {
  return apiRequestBlob(`/admin/seller-finance/refunds/${id}/proof`);
}
