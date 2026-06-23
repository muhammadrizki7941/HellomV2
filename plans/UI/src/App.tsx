import { Routes, Route } from 'react-router-dom';
import LandingPage from '@/pages/public/LandingPage';
import PublicPage from '@/pages/public/PublicPage';
import InvitationAcceptPage from '@/pages/public/InvitationAcceptPage';
import LoginPage from '@/pages/auth/LoginPage';
import RegisterPage from '@/pages/auth/RegisterPage';
import ForgotPassword from '@/pages/auth/ForgotPassword';
import ProdukPublicPage from '@/pages/produk/index';
import FaqPage from '@/pages/public/FaqPage';
import RefundPolicyPage from '@/pages/public/RefundPolicyPage';
import TermsPage from '@/pages/public/TermsPage';
import ContactPage from '@/pages/public/ContactPage';
import InsightsPage from '@/pages/public/InsightsPage';
import InsightDetailPage from '@/pages/public/InsightDetailPage';
import DashboardLayout from '@/layouts/DashboardLayout';
import DashboardHome from '@/pages/member/DashboardHome';
import MemberProfile from '@/pages/member/MemberProfile';
import LandingBuilder from '@/pages/apps/LandingBuilder';
import PosAccess from '@/pages/apps/PosAccess';
import PosCustomerHub from '@/pages/apps/PosCustomerHub';
import Payments from '@/pages/dashboard/Payments';
import ConsumerProductCatalog from '@/pages/dashboard/products';
import ConsumerProductDetail from '@/pages/dashboard/products/[slug]';
import MyPurchases from '@/pages/dashboard/my-purchases';
import AdminLayout from '@/layouts/AdminLayout';
import AdminDashboard from '@/pages/admin/AdminDashboard';
import UserManagement from '@/pages/admin/UserManagement';
import OrganizationManagement from '@/pages/admin/OrganizationManagement';
import AppManagement from '@/pages/admin/AppManagement';
import AdminSettings from '@/pages/admin/AdminSettings';
import EmailSetting from '@/pages/admin/settings/EmailSetting';
import Notifications from '@/pages/admin/Notifications';
import SystemHealth from '@/pages/admin/SystemHealth';
import FinanceManagement from '@/pages/admin/FinanceManagement';
import ShowcaseManagement from '@/pages/admin/ShowcaseManagement';
import LandingContentManagement from '@/pages/admin/LandingContentManagement';
import BrandSettings from '@/pages/admin/BrandSettings';
import AdminProducts from '@/pages/admin/products';
import AdminProductEdit from '@/pages/admin/products/[id]/edit';
import AdminProductPurchases from '@/pages/admin/products/purchases';
import PosAdmin from '@/pages/pos/PosAdmin';
import PosAdminDashboard from '@/pages/pos/PosAdminDashboard';
import PosOutlets from '@/pages/pos/PosOutlets';
import PosOrders from '@/pages/pos/PosOrders';
import PosMenu from '@/pages/pos/PosMenu';
import PosTables from '@/pages/pos/PosTables';
import PosCustomerOrder from '@/pages/pos/PosCustomerOrder';
import PosCustomerOrderSuccess from '@/pages/pos/customer/SuccessPage';
import MemberPortalPage from '@/pages/pos/customer/MemberPortalPage';
import PosSettings from '@/pages/pos/PosSettings';
import PosMemberList from '@/pages/pos/PosMemberList';
import PosLoyaltySettings from '@/pages/pos/PosLoyaltySettings';
import PosStaff from '@/pages/pos/PosStaff';
import PosReports from '@/pages/pos/PosReports';
import PosExperienceCenter from '@/pages/pos/PosExperienceCenter';
import PosLayout from '@/layouts/PosLayout';
import useBrand from '@/hooks/useBrand';

export default function App() {
  useBrand();

  return (
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

      {/* Legal & Info Pages (public) */}
      <Route path="/faq" element={<FaqPage />} />
      <Route path="/refund-policy" element={<RefundPolicyPage />} />
      <Route path="/terms" element={<TermsPage />} />
      <Route path="/contact" element={<ContactPage />} />
      <Route path="/insights" element={<InsightsPage />} />
      <Route path="/insights/:slug" element={<InsightDetailPage />} />

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
  );
}
