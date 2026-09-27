# Digital Product Marketplace Feature

## DB Tables Added
- digital_products: id, slug, name, tagline, description, category, type, price, currency, thumbnail_url, preview_images, tech_stack, tags, is_published, is_featured, sort_order, total_purchases, total_downloads, timestamps, deleted_at
- digital_product_files: id, product_id, label, file_type, file_path, file_size, version, is_primary, sort_order, timestamps
- digital_product_docs: id, product_id, title, doc_type, content, file_path, video_url, external_url, sort_order, timestamps
- product_purchases: id, user_id, product_id, transaction_code, amount_paid, payment_method, payment_status, payment_gateway, gateway_ref, paid_at, download_count, last_downloaded_at, expires_at, timestamps
- onboarding_tips: id, title, body, icon, action_url, action_text, is_active, sort_order, timestamps
- user_onboarding_progress: id, user_id, dismissed, dismissed_at, timestamps

## API Endpoints
PUBLIC:
- GET /api/v1/hellom/public/products -> list published products
- GET /api/v1/hellom/public/products/{slug} -> product detail (public)
- GET /api/v1/hellom/public/products/categories -> categories with totals

CONSUMER:
- GET /api/v1/hellom/consumer/products -> products with purchase status
- GET /api/v1/hellom/consumer/products/{slug} -> product detail + purchase status
- POST /api/v1/hellom/consumer/products/{id}/purchase -> create purchase
- POST /api/v1/hellom/consumer/products/{id}/download/{fileId} -> temp download URL
- GET /api/v1/hellom/consumer/my-purchases -> list user purchases
- GET /api/v1/hellom/consumer/onboarding/tips -> active onboarding tips
- POST /api/v1/hellom/consumer/onboarding/dismiss -> dismiss tips

ADMIN:
- GET /api/v1/hellom/admin/digital-products -> list products
- POST /api/v1/hellom/admin/digital-products -> create product
- GET /api/v1/hellom/admin/digital-products/{id} -> product detail
- PUT /api/v1/hellom/admin/digital-products/{id} -> update product
- DELETE /api/v1/hellom/admin/digital-products/{id} -> delete product
- POST /api/v1/hellom/admin/digital-products/{id}/publish -> publish
- POST /api/v1/hellom/admin/digital-products/{id}/unpublish -> unpublish
- POST /api/v1/hellom/admin/digital-products/{id}/thumbnail -> upload thumbnail
- POST /api/v1/hellom/admin/digital-products/{id}/files -> upload product file
- POST /api/v1/hellom/admin/digital-products/{id}/docs -> upload documentation
- DELETE /api/v1/hellom/admin/digital-products/files/{fileId} -> delete file
- DELETE /api/v1/hellom/admin/digital-products/docs/{docId} -> delete doc
- GET /api/v1/hellom/admin/product-purchases -> list purchases
- GET /api/v1/hellom/admin/product-purchases/{id} -> purchase detail
- POST /api/v1/hellom/admin/product-purchases/{id}/approve -> approve purchase
- POST /api/v1/hellom/admin/product-purchases/{id}/refund -> refund purchase

## Files Created
backend:
- backend/app/Http/Controllers/Admin/DigitalProductController.php [created]
- backend/app/Http/Controllers/Admin/ProductPurchaseController.php [created]
- backend/app/Http/Controllers/Api/V1/Public/ProductController.php [created]
- backend/app/Http/Controllers/Api/V1/Consumer/ProductController.php [created]
- backend/app/Http/Controllers/Api/V1/Consumer/OnboardingController.php [created]
- backend/app/Models/DigitalProduct.php [created]
- backend/app/Models/DigitalProductFile.php [created]
- backend/app/Models/DigitalProductDoc.php [created]
- backend/app/Models/ProductPurchase.php [created]
- backend/app/Models/OnboardingTip.php [created]
- backend/app/Models/UserOnboardingProgress.php [created]
- backend/database/migrations/2026_05_14_120000_create_digital_products_table.php [created]
- backend/database/migrations/2026_05_14_120100_create_digital_product_files_table.php [created]
- backend/database/migrations/2026_05_14_120200_create_digital_product_docs_table.php [created]
- backend/database/migrations/2026_05_14_120300_create_product_purchases_table.php [created]
- backend/database/migrations/2026_05_14_120400_create_onboarding_tips_table.php [created]
- backend/database/migrations/2026_05_14_120500_create_user_onboarding_progress_table.php [created]
- backend/database/seeders/DigitalProductSeeder.php [created]
- backend/database/seeders/OnboardingTipSeeder.php [created]
frontend:
- plans/UI/src/components/consumer/OnboardingTips.tsx [created]
- plans/UI/src/pages/dashboard/products/index.tsx [created]
- plans/UI/src/pages/dashboard/products/[slug].tsx [created]
- plans/UI/src/pages/dashboard/my-purchases.tsx [created]
- plans/UI/src/pages/admin/products/index.tsx [created]
- plans/UI/src/pages/admin/products/[id]/edit.tsx [created]
- plans/UI/src/pages/admin/products/purchases.tsx [created]

## Files Modified
- backend/app/Services/NotificationService.php [allow nullable subscription, owner purchase notification]
- backend/routes/api.php [routes for digital products & onboarding]
- plans/UI/src/lib/hellomApi.ts [API wrappers for products/onboarding/admin]
- plans/UI/src/pages/member/DashboardHome.tsx [add onboarding tips]
- plans/UI/src/layouts/DashboardLayout.tsx [sidebar links for products]
- plans/UI/src/layouts/AdminLayout.tsx [admin menu for products]
- plans/UI/src/components/landing/SaasProductsSection.tsx [dynamic product cards]
- plans/UI/src/pages/auth/LoginPage.tsx [redirect to intended product]
- plans/UI/src/pages/auth/RegisterPage.tsx [redirect to intended product]
- plans/UI/src/App.tsx [routes for new pages]
- plans/UI/src/pages/dashboard/products/[slug].tsx [checkout page supports gateway/manual payment flow]
- plans/UI/src/pages/dashboard/my-purchases.tsx [show payment method, gateway status, WhatsApp manual confirmation]
- backend/app/Http/Controllers/Api/V1/Hellom/DokuWebhookController.php [product purchase webhook resolution + status sync]
- plans/UI/src/pages/admin/products/[id]/edit.tsx [doc delete action + real preview for pdf/video]
- backend/app/Http/Controllers/Admin/DigitalProductController.php [admin pdf preview endpoint for product docs]
- backend/app/Http/Controllers/Api/V1/Consumer/ProductController.php [consumer pdf preview endpoint for purchased docs]
- plans/UI/src/lib/hellomApi.ts [product doc delete + authorized blob preview helper]

## Flow Summary
FREE product: user click -> POST purchase -> status paid -> notif -> download available
PAID product via gateway: user open /checkout -> select gateway -> POST purchase -> redirect checkout URL -> payment -> webhook updates purchase -> notif -> download
PAID product via manual: user open /checkout -> select manual method -> POST purchase -> manual instructions shown -> confirm to owner via WhatsApp -> owner approve -> notif -> download
PRODUCT DOCS: admin upload text/pdf/video/link -> admin can preview/delete -> paid user can preview pdf inline and play YouTube embed inside dashboard

## Seeded Data
- Produk: POS / Kasir Digital, Landing Page Builder, Aplikasi Member & Loyalitas, Template Toko Online Pro, Suvarna Gaya Luwear, Kursus Digital Marketing UMKM
- Tips: Lengkapi Profil Bisnis Kamu, Aktifkan Aplikasi Pertama, Pelajari Cara Instalasi, Hubungkan Domain Kamu, Undang Tim Kamu

## What Remains
- Email notification for purchase confirmation
- Product review/rating system
- Affiliate/referral system
