import { lazy, Suspense } from 'react';
import { Routes, Route } from 'react-router-dom';
import useBrand from '@/hooks/useBrand';
import LandingPage from '@/pages/public/LandingPage';
const PublicPage = lazy(() => import('@/pages/public/PublicPage'));
const InvitationAcceptPage = lazy(() => import('@/pages/public/InvitationAcceptPage'));
const LoginPage = lazy(() => import('@/pages/auth/LoginPage'));
const RegisterPage = lazy(() => import('@/pages/auth/RegisterPage'));
const ForgotPassword = lazy(() => import('@/pages/auth/ForgotPassword'));
const ProdukPublicPage = lazy(() => import('@/pages/produk/index'));
const GuestProductCheckoutPage = lazy(() => import('@/pages/produk/checkout'));
const GuestCheckoutStatusPage = lazy(() => import('@/pages/produk/checkout-status'));
const MagicLoginPage = lazy(() => import('@/pages/auth/MagicLoginPage'));
const FaqPage = lazy(() => import('@/pages/public/FaqPage'));
const RefundPolicyPage = lazy(() => import('@/pages/public/RefundPolicyPage'));
const TermsPage = lazy(() => import('@/pages/public/TermsPage'));
const ContactPage = lazy(() => import('@/pages/public/ContactPage'));
const InsightsPage = lazy(() => import('@/pages/public/InsightsPage'));
const InsightDetailPage = lazy(() => import('@/pages/public/InsightDetailPage'));
const DashboardLayout = lazy(() => import('@/layouts/DashboardLayout'));
const DashboardHome = lazy(() => import('@/pages/member/DashboardHome'));
const MemberProfile = lazy(() => import('@/pages/member/MemberProfile'));
const LandingBuilder = lazy(() => import('@/pages/apps/LandingBuilder'));
const PosAccess = lazy(() => import('@/pages/apps/PosAccess'));
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
const FinanceManagement = lazy(() => import('@/pages/admin/FinanceManagement'));
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
const PosMenu = lazy(() => import('@/pages/pos/PosMenu'));
const PosTables = lazy(() => import('@/pages/pos/PosTables'));
const PosCustomerOrder = lazy(() => import('@/pages/pos/PosCustomerOrder'));
const PosCustomerOrderSuccess = lazy(() => import('@/pages/pos/customer/SuccessPage'));
const MemberPortalPage = lazy(() => import('@/pages/pos/customer/MemberPortalPage'));
const PosSettings = lazy(() => import('@/pages/pos/PosSettings'));
const PosMemberList = lazy(() => import('@/pages/pos/PosMemberList'));
const PosLoyaltySettings = lazy(() => import('@/pages/pos/PosLoyaltySettings'));
const PosStaff = lazy(() => import('@/pages/pos/PosStaff'));
const PosReports = lazy(() => import('@/pages/pos/PosReports'));
const PosExperienceCenter = lazy(() => import('@/pages/pos/PosExperienceCenter'));
const PosLayout = lazy(() => import('@/layouts/PosLayout'));

function RouteFallback() {
  return (
    <div className="flex min-h-screen items-center justify-center" role="status" aria-label="Memuat">
      <div className="h-8 w-8 animate-spin rounded-full border-2 border-zinc-300 border-t-zinc-900" />
    </div>
  );
}

export default function App() {
  useBrand();

  return (
    <Suspense fallback={<RouteFallback />}>
      <Routes>
        {/* Public Routes */}
        <Route path="/" element={<LandingPage />} />
        <Route path="/p/demo" element={<PublicPage />} />
        <Route path="/p/landingpage/:organizationSlug" element={<PublicPage />} />
        <Route path="/p/domain/:domain" element={<PublicPage />} />
        <Route path="/p/:organizationSlug/:pageSlug" element={<PublicPage />} />
        <Route path="/invitation/accept" element={<InvitationAcceptPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/customer/:organizationSlug" element={<PosCustomerOrder />} />
        <Route path="/customer/order/:tableToken" element={<PosCustomerOrder />} />
        <Route path="/customer/:organizationSlug/order/:tableToken" element={<PosCustomerOrder />} />
        <Route path="/customer/:organizationSlug/order/:tableToken/success/:orderNumber" element={<PosCustomerOrderSuccess />} />
        <Route path="/customer/order/:tableToken/success/:orderNumber" element={<PosCustomerOrderSuccess />} />
        <Route path="/customer/:organizationSlug/order/:tableToken/success/:orderNumber" element={<PosCustomerOrderSuccess />} />
        <Route path="/customer/:organizationSlug/member" element={<MemberPortalPage />} />
        <Route path="/customer/:organizationSlug/order/:tableToken/member" element={<MemberPortalPage />} />

        <Route path="/produk" element={<ProdukPublicPage />} />
        <Route path="/produk/checkout/:token" element={<GuestCheckoutStatusPage />} />
        <Route path="/produk/:slug/checkout" element={<GuestProductCheckoutPage />} />
        <Route path="/auth/magic" element={<MagicLoginPage />} />

        {/* Legal & Info Pages (public) */}
        <Route path="/faq" element={<FaqPage />} />
        <Route path="/refund-policy" element={<RefundPolicyPage />} />
        <Route path="/terms" element={<TermsPage />} />
        <Route path="/contact" element={<ContactPage />} />
        <Route path="/insights" element={<InsightsPage />} />
        <Route path="/insights/:slug" element={<InsightDetailPage />} />

        {/* Short public landing page URL: domain/<org-slug>. Kept as a single-segment
            dynamic route so static routes above (login, produk, dashboard, ...) still win.
            The longer /p/landingpage/:organizationSlug route below stays for old links. */}
        <Route path="/:organizationSlug" element={<PublicPage />} />

        {/* Member Routes (Protected) */}
        <Route path="/dashboard" element={<DashboardLayout />}>
          <Route index element={<DashboardHome />} />
          <Route path="profile" element={<MemberProfile />} />
          <Route path="payments" element={<Payments />} />
          <Route path="billing" element={<Payments />} />
          <Route path="billing/renew" element={<Payments />} />
          <Route path="billing/:transactionId" element={<Payments />} />
          <Route path="products" element={<ConsumerProductCatalog />} />
          <Route path="products/:slug" element={<ConsumerProductDetail />} />
          <Route path="products/:slug/checkout" element={<ConsumerProductDetail />} />
          <Route path="my-purchases" element={<MyPurchases />} />
          <Route path="apps/landing-builder" element={<LandingBuilder />} />
          <Route path="apps/pos" element={<PosAccess />} />
          <Route path="apps/pos/customer" element={<PosCustomerHub />} />
        </Route>

        {/* POS Routes */}
        <Route path="/pos" element={<PosLayout />}>
          <Route path="admin" element={<PosAdmin />} />
          <Route path="cashier" element={<PosAdmin />} />
          <Route path="admin-dashboard" element={<PosAdminDashboard />} />
          <Route path="outlets" element={<PosOutlets />} />
          <Route path="orders" element={<PosOrders />} />
          <Route path="menu" element={<PosMenu />} />
          <Route path="tables" element={<PosTables />} />
          <Route path="staff" element={<PosStaff />} />
          <Route path="members" element={<PosMemberList />} />
          <Route path="loyalty" element={<PosLoyaltySettings />} />
          <Route path="customer-hub" element={<PosExperienceCenter />} />
          <Route path="reports" element={<PosReports />} />
          <Route path="settings" element={<PosSettings />} />
        </Route>

        {/* Super Admin Routes */}
        <Route path="/admin" element={<AdminLayout />}>
          <Route index element={<AdminDashboard />} />
          <Route path="users" element={<UserManagement />} />
          <Route path="organizations" element={<OrganizationManagement />} />
          <Route path="apps" element={<AppManagement />} />
          <Route path="products" element={<AdminProducts />} />
          <Route path="products/new" element={<AdminProductEdit />} />
          <Route path="products/:id/edit" element={<AdminProductEdit />} />
          <Route path="products/purchases" element={<AdminProductPurchases />} />
          <Route path="finance" element={<FinanceManagement />} />
          <Route path="showcase" element={<ShowcaseManagement />} />
          <Route path="landing-content" element={<LandingContentManagement />} />
          <Route path="brand" element={<BrandSettings />} />
          <Route path="settings" element={<AdminSettings />} />
          <Route path="settings/email" element={<EmailSetting />} />
          <Route path="notifications" element={<Notifications />} />
          <Route path="system" element={<SystemHealth />} />
        </Route>
      </Routes>
    </Suspense>
  );
}
