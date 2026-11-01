import { lazy, Suspense, useEffect, useState, type ReactNode } from 'react';
import { Navigate, Routes, Route, useParams, useLocation, useNavigate } from 'react-router-dom';
import useBrand from '@/hooks/useBrand';
import PlatformTracking from '@/components/PlatformTracking';
import CheckoutQueryLauncher from '@/components/CheckoutQueryLauncher';
import HellomLoading from '@/components/HellomLoading';
import { clearSession, getSessionUser } from '@/lib/hellomApi';
import { usePermissions } from '@/hooks/usePermissions';
import { RouteJourney, ConnectionNotice, PageErrorBoundary, ServiceNotice } from '@/components/MascotFeedback';
const MarketingAnalytics = lazy(() => import('@/pages/admin/MarketingAnalytics'));
import {
  AplikasiDetailPage,
  AplikasiPage,
  BerandaPage,
  KontakPage,
  LayananPage,
  PortofolioPage,
  ProdukPage,
  TentangPage,
  WawasanDetailPage,
  WawasanPage,
} from '@/pages/site';
const PublicLayout = lazy(() => import('@/layouts/PublicLayout'));
const PublicPage = lazy(() => import('@/pages/public/PublicPage'));
const OrderStatusPage = lazy(() => import('@/pages/public/OrderStatusPage'));
const CheckoutPage = lazy(() => import('@/pages/public/CheckoutPage'));
const AccessPage = lazy(() => import('@/pages/public/AccessPage'));
const OrderLookupPage = lazy(() => import('@/pages/public/OrderLookupPage'));
const SellerPolicyPage = lazy(() => import('@/pages/public/SellerPolicyPage'));
const InvitationAcceptPage = lazy(() => import('@/pages/public/InvitationAcceptPage'));
const AdminInvitationPage = lazy(() => import('@/pages/public/AdminInvitationPage'));
const UnsubscribePage = lazy(() => import('@/pages/public/UnsubscribePage'));
const PosPinResetPage = lazy(() => import('@/pages/public/PosPinResetPage'));
const PosPaymentStatusPage = lazy(() => import('@/pages/public/PosPaymentStatusPage'));
const PosCheckoutRoute = lazy(() => import('@/pages/public/PosCheckoutRoute'));
const LoginPage = lazy(() => import('@/pages/auth/LoginPage'));
const StaffLoginPage = lazy(() => import('@/pages/auth/StaffLoginPage'));
const ForcePasswordChangePage = lazy(() => import('@/pages/auth/ForcePasswordChangePage'));
const RegisterPage = lazy(() => import('@/pages/auth/RegisterPage'));
const ForgotPassword = lazy(() => import('@/pages/auth/ForgotPassword'));
const ResetPasswordPage = lazy(() => import('@/pages/auth/ResetPasswordPage'));
const GuestProductCheckoutPage = lazy(() => import('@/pages/produk/checkout'));
const GuestCheckoutStatusPage = lazy(() => import('@/pages/produk/checkout-status'));
const MagicLoginPage = lazy(() => import('@/pages/auth/MagicLoginPage'));
const FaqPage = lazy(() => import('@/pages/public/FaqPage'));
const RefundPolicyPage = lazy(() => import('@/pages/public/RefundPolicyPage'));
const TermsPage = lazy(() => import('@/pages/public/TermsPage'));
const DashboardLayout = lazy(() => import('@/layouts/DashboardLayout'));
const DashboardHome = lazy(() => import('@/pages/member/DashboardHome'));
const MemberProfile = lazy(() => import('@/pages/member/MemberProfile'));
const UserSupportPage = lazy(() => import('@/pages/support/UserSupportPage'));
const SupportAgentConsole = lazy(() => import('@/pages/support/SupportAgentConsole'));
const LandingBuilder = lazy(() => import('@/pages/apps/LandingBuilder'));
const PosAccess = lazy(() => import('@/pages/apps/PosAccess'));
const HppApp = lazy(() => import('@/pages/apps/hpp/HppApp'));
const PosCustomerHub = lazy(() => import('@/pages/apps/PosCustomerHub'));
const Payments = lazy(() => import('@/pages/dashboard/Payments'));
const ConsumerProductCatalog = lazy(() => import('@/pages/dashboard/products'));
const ConsumerProductDetail = lazy(() => import('@/pages/dashboard/products/[slug]'));
const MyPurchases = lazy(() => import('@/pages/dashboard/my-purchases'));
const AdminLayout = lazy(() => import('@/layouts/AdminLayout'));
const AdminDashboard = lazy(() => import('@/pages/admin/AdminDashboard'));
const UserManagement = lazy(() => import('@/pages/admin/UserManagement'));
const OrganizationManagement = lazy(() => import('@/pages/admin/OrganizationManagement'));
const AppManagement = lazy(() => import('@/pages/admin/AppManagement'));
const AdminSettings = lazy(() => import('@/pages/admin/AdminSettings'));
const EmailSetting = lazy(() => import('@/pages/admin/settings/EmailSetting'));
const Notifications = lazy(() => import('@/pages/admin/Notifications'));
const SystemHealth = lazy(() => import('@/pages/admin/SystemHealth'));
const AdminAuditLog = lazy(() => import('@/pages/admin/AuditLog'));
const AdminInvoices = lazy(() => import('@/pages/admin/Invoices'));
const FinanceManagement = lazy(() => import('@/pages/admin/FinanceManagement'));
const AdminSellerFinance = lazy(() => import('@/pages/admin/SellerFinance'));
const AdminLandingModeration = lazy(() => import('@/pages/admin/LandingModeration'));
const ShowcaseManagement = lazy(() => import('@/pages/admin/ShowcaseManagement'));
const LandingContentManagement = lazy(() => import('@/pages/admin/LandingContentManagement'));
const BrandSettings = lazy(() => import('@/pages/admin/BrandSettings'));
const AdminProducts = lazy(() => import('@/pages/admin/products'));
const AdminProductEdit = lazy(() => import('@/pages/admin/products/[id]/edit'));
const AdminProductPurchases = lazy(() => import('@/pages/admin/products/purchases'));
const PosAdmin = lazy(() => import('@/pages/pos/PosAdmin'));
const PosAdminDashboard = lazy(() => import('@/pages/pos/PosAdminDashboard'));
const PosOutlets = lazy(() => import('@/pages/pos/PosOutlets'));
const PosOrders = lazy(() => import('@/pages/pos/PosOrders'));
const PosSyncQueue = lazy(() => import('@/pages/pos/PosSyncQueue'));
const PosMenu = lazy(() => import('@/pages/pos/PosMenu'));
const PosInventory = lazy(() => import('@/pages/pos/PosInventory'));
const PosTables = lazy(() => import('@/pages/pos/PosTables'));
const PosCustomerOrder = lazy(() => import('@/pages/pos/PosCustomerOrder'));
const PosCustomerOrderSuccess = lazy(() => import('@/pages/pos/customer/SuccessPage'));
const MemberPortalPage = lazy(() => import('@/pages/pos/customer/MemberPortalPage'));
const PosSettings = lazy(() => import('@/pages/pos/PosSettings'));
const PosMemberList = lazy(() => import('@/pages/pos/PosMemberList'));
const PosLoyaltySettings = lazy(() => import('@/pages/pos/PosLoyaltySettings'));
const PosStaff = lazy(() => import('@/pages/pos/PosStaff'));
const PosProfile = lazy(() => import('@/pages/pos/PosProfile'));
const PosReports = lazy(() => import('@/pages/pos/PosReports'));
const PosExperienceCenter = lazy(() => import('@/pages/pos/PosExperienceCenter'));
const PosAudit = lazy(() => import('@/pages/pos/PosAudit'));
const PosLayout = lazy(() => import('@/layouts/PosLayout'));

// Old article URLs (/insights/:slug) keep working.
function InsightRedirect() {
  const { slug = '' } = useParams();
  return <Navigate to={`/wawasan/${slug}`} replace />;
}

function RouteFallback() {
  const { pathname } = useLocation();
  const [showLoader, setShowLoader] = useState(false);

  useEffect(() => {
    const timer = window.setTimeout(() => setShowLoader(true), 500);
    return () => window.clearTimeout(timer);
  }, []);

  if (!showLoader) return null;
  return <HellomLoading label={pathname.startsWith('/pos') ? 'Memuat POS...' : 'Menyiapkan halaman...'} />;
}

function FirstLoginPasswordGate({ children }: { children: ReactNode }) {
  const user = getSessionUser<{ must_change_password?: boolean }>();

  return user?.must_change_password
    ? <Navigate to="/auth/change-password" replace />
    : children;
}

function PosPermissionGate({ permission, children }: { permission: string; children: ReactNode }) {
  const { can } = usePermissions();

  return can(permission) ? children : <Navigate to="/pos/orders" replace />;
}

function OrganizationAdminGate({ children }: { children: ReactNode }) {
  const { isOrganizationAdmin } = usePermissions();

  return isOrganizationAdmin ? children : <Navigate to="/dashboard" replace />;
}

function AuthRevocationHandler() {
  const navigate = useNavigate();

  useEffect(() => {
    const handleRevocation = () => {
      clearSession();
      navigate('/login/kasir', { replace: true });
    };
    window.addEventListener('hellom-auth-revoked', handleRevocation);

    return () => window.removeEventListener('hellom-auth-revoked', handleRevocation);
  }, [navigate]);

  return null;
}

export default function App() {
  useBrand();

  return (
    <>
    <RouteJourney />
    <ConnectionNotice />
    <ServiceNotice />
    <PageErrorBoundary>
    <Suspense fallback={<RouteFallback />}>
      <PlatformTracking />
      <CheckoutQueryLauncher />
      <AuthRevocationHandler />
      <Routes>
        {/* Public site: persistent navbar/footer, curtain transitions between pages */}
        <Route element={<PublicLayout />}>
          <Route path="/" element={<BerandaPage />} />
          <Route path="/tentang" element={<TentangPage />} />
          <Route path="/layanan" element={<LayananPage />} />
          <Route path="/aplikasi" element={<AplikasiPage />} />
          <Route path="/aplikasi/:slug" element={<AplikasiDetailPage />} />
          <Route path="/produk" element={<ProdukPage />} />
          <Route path="/portofolio" element={<PortofolioPage />} />
          <Route path="/wawasan" element={<WawasanPage />} />
          <Route path="/wawasan/:slug" element={<WawasanDetailPage />} />
          <Route path="/kontak" element={<KontakPage />} />
        </Route>

        {/* Other public routes */}
        {/* Buyer thank-you / order status (payment return URL). */}
        <Route path="/pesanan/:reference" element={<OrderStatusPage />} />
        <Route path="/checkout-pos" element={<PosCheckoutRoute />} />
        <Route path="/pembayaran/:token" element={<PosPaymentStatusPage />} />
        {/* Hellom Page selling: checkout, buyer access page, order lookup, policies. */}
        <Route path="/beli/:productId" element={<CheckoutPage />} />
        <Route path="/akses/:token" element={<AccessPage />} />
        <Route path="/cek-pesanan" element={<OrderLookupPage />} />
        <Route path="/kebijakan/:slug" element={<SellerPolicyPage />} />
        <Route path="/p/demo" element={<PublicPage />} />
        <Route path="/p/landingpage/:organizationSlug" element={<PublicPage />} />
        <Route path="/p/domain/:domain" element={<PublicPage />} />
        <Route path="/p/:organizationSlug/:pageSlug" element={<PublicPage />} />
        <Route path="/invitation/accept" element={<InvitationAcceptPage />} />
        <Route path="/undangan-admin" element={<AdminInvitationPage />} />
        <Route path="/berhenti-langganan" element={<UnsubscribePage />} />
        <Route path="/reset-pin-pos" element={<PosPinResetPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/login/kasir" element={<StaffLoginPage />} />
        <Route path="/auth/change-password" element={<ForcePasswordChangePage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/customer/:organizationSlug" element={<PosCustomerOrder />} />
        <Route path="/customer/order/:tableToken" element={<PosCustomerOrder />} />
        <Route path="/customer/:organizationSlug/order/:tableToken" element={<PosCustomerOrder />} />
        <Route path="/customer/:organizationSlug/order/:tableToken/success/:orderNumber" element={<PosCustomerOrderSuccess />} />
        <Route path="/customer/order/:tableToken/success/:orderNumber" element={<PosCustomerOrderSuccess />} />
        <Route path="/customer/:organizationSlug/order/:tableToken/success/:orderNumber" element={<PosCustomerOrderSuccess />} />
        <Route path="/customer/:organizationSlug/member" element={<MemberPortalPage />} />
        <Route path="/customer/:organizationSlug/order/:tableToken/member" element={<MemberPortalPage />} />

        <Route path="/produk/checkout/:token" element={<GuestCheckoutStatusPage />} />
        <Route path="/produk/:slug/checkout" element={<GuestProductCheckoutPage />} />
        <Route path="/auth/magic" element={<MagicLoginPage />} />

        {/* Legal & Info Pages (public) */}
        <Route path="/faq" element={<FaqPage />} />
        <Route path="/refund-policy" element={<RefundPolicyPage />} />
        <Route path="/terms" element={<TermsPage />} />
        {/* Old URLs from the single-page site */}
        <Route path="/contact" element={<Navigate to="/kontak" replace />} />
        <Route path="/insights" element={<Navigate to="/wawasan" replace />} />
        <Route path="/insights/:slug" element={<InsightRedirect />} />

        {/* Short public landing page URL: domain/<org-slug>. Kept as a single-segment
            dynamic route so static routes above (login, produk, dashboard, ...) still win.
            The longer /p/landingpage/:organizationSlug route below stays for old links. */}
        <Route path="/:organizationSlug" element={<PublicPage />} />

        {/* Member Routes (Protected) */}
        <Route path="/dashboard" element={<FirstLoginPasswordGate><DashboardLayout /></FirstLoginPasswordGate>}>
          <Route index element={<DashboardHome />} />
          <Route path="profile" element={<MemberProfile />} />
          <Route path="support" element={<UserSupportPage />} />
          <Route path="payments" element={<OrganizationAdminGate><Payments /></OrganizationAdminGate>} />
          <Route path="billing" element={<OrganizationAdminGate><Payments /></OrganizationAdminGate>} />
          <Route path="billing/renew" element={<OrganizationAdminGate><Payments /></OrganizationAdminGate>} />
          <Route path="billing/:transactionId" element={<OrganizationAdminGate><Payments /></OrganizationAdminGate>} />
          <Route path="products" element={<ConsumerProductCatalog />} />
          <Route path="products/:slug" element={<ConsumerProductDetail />} />
          <Route path="products/:slug/checkout" element={<ConsumerProductDetail />} />
          <Route path="my-purchases" element={<MyPurchases />} />
          <Route path="apps/landing-builder" element={<LandingBuilder />} />
          <Route path="apps/hpp" element={<HppApp />} />
          <Route path="apps/pos" element={<PosAccess />} />
          <Route path="apps/pos/customer" element={<PosCustomerHub />} />
        </Route>
        <Route path="/support/agent" element={<SupportAgentConsole />} />

        {/* POS Routes */}
        <Route path="/pos" element={<FirstLoginPasswordGate><PosLayout /></FirstLoginPasswordGate>}>
          <Route path="profile" element={<PosProfile />} />
          <Route path="admin" element={<PosAdmin />} />
          <Route path="cashier" element={<PosAdmin />} />
          <Route path="admin-dashboard" element={<PosPermissionGate permission="organization_admin"><PosAdminDashboard /></PosPermissionGate>} />
          <Route path="outlets" element={<PosPermissionGate permission="organization_admin"><PosOutlets /></PosPermissionGate>} />
          <Route path="orders" element={<PosPermissionGate permission="orders"><PosOrders /></PosPermissionGate>} />
          <Route path="sync-queue" element={<PosPermissionGate permission="orders"><PosSyncQueue /></PosPermissionGate>} />
          <Route path="menu" element={<PosPermissionGate permission="products"><PosMenu /></PosPermissionGate>} />
          <Route path="inventory" element={<PosPermissionGate permission="inventory_management"><PosInventory /></PosPermissionGate>} />
          <Route path="tables" element={<PosPermissionGate permission="tables"><PosTables /></PosPermissionGate>} />
          <Route path="staff" element={<PosPermissionGate permission="staff_management"><PosStaff /></PosPermissionGate>} />
          <Route path="members" element={<PosPermissionGate permission="members"><PosMemberList /></PosPermissionGate>} />
          <Route path="loyalty" element={<PosPermissionGate permission="loyalty"><PosLoyaltySettings /></PosPermissionGate>} />
          <Route path="customer-hub" element={<PosPermissionGate permission="customer_hub"><PosExperienceCenter /></PosPermissionGate>} />
          <Route path="reports" element={<PosPermissionGate permission="reports"><PosReports /></PosPermissionGate>} />
          <Route path="settings" element={<PosPermissionGate permission="outlet_settings"><PosSettings /></PosPermissionGate>} />
          <Route path="audit" element={<PosPermissionGate permission="organization_admin"><PosAudit /></PosPermissionGate>} />
        </Route>

        {/* Super Admin Routes */}
        <Route path="/admin" element={<AdminLayout />}>
          <Route index element={<AdminDashboard />} />
          <Route path="users" element={<UserManagement />} />
          <Route path="organizations" element={<OrganizationManagement />} />
          <Route path="apps" element={<AppManagement />} />
          <Route path="marketing" element={<MarketingAnalytics />} />
          <Route path="products" element={<AdminProducts />} />
          <Route path="products/new" element={<AdminProductEdit />} />
          <Route path="products/:id/edit" element={<AdminProductEdit />} />
          <Route path="products/purchases" element={<AdminProductPurchases />} />
          <Route path="finance" element={<FinanceManagement />} />
          <Route path="keuangan-penjual" element={<AdminSellerFinance />} />
          <Route path="moderasi-toko" element={<AdminLandingModeration />} />
          <Route path="showcase" element={<ShowcaseManagement />} />
          <Route path="landing-content" element={<LandingContentManagement />} />
          <Route path="brand" element={<BrandSettings />} />
          <Route path="settings" element={<AdminSettings />} />
          <Route path="settings/email" element={<EmailSetting />} />
          <Route path="notifications" element={<Notifications />} />
          <Route path="support" element={<SupportAgentConsole />} />
          <Route path="system" element={<SystemHealth />} />
          <Route path="audit-log" element={<AdminAuditLog />} />
          <Route path="invoices" element={<AdminInvoices />} />
        </Route>
      </Routes>
    </Suspense>
    </PageErrorBoundary>
    </>
  );
}
