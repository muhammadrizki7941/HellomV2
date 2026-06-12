# Hellom Billing Handoff

## Scope implemented

- Added third payment gateway provider: `doku`
- Extended payment runtime to support `xendit`, `ipaymu`, and `doku`
- Renamed automatic checkout mode logically to `gateway_automatic`
  - backend still accepts legacy value `xendit_automatic` for compatibility
- Added manual payment fallback config for owner dashboard:
  - `bank_transfer`
  - `gopay`
  - `dana`
  - `qris`
- Published manual payment methods to member checkout modal
- Added email notifications for:
  - checkout created
  - manual approval / rejection
  - successful gateway payment webhook
- Fixed POS plan eligibility to follow current plan structure:
  - `pos_starter_monthly`
  - `pos_starter_yearly`
  - `pos_pro_monthly`
  - `pos_pro_yearly`
  - `pos_lifetime`

## Main backend files

- `backend/app/Http/Controllers/Api/V1/Hellom/BillingController.php`
- `backend/app/Http/Controllers/Api/V1/Hellom/DokuWebhookController.php`
- `backend/app/Http/Controllers/Api/V1/Hellom/XenditWebhookController.php`
- `backend/app/Http/Controllers/Api/V1/Hellom/IpaymuWebhookController.php`
- `backend/app/Services/Hellom/DokuSettingsService.php`
- `backend/app/Services/Hellom/DokuService.php`
- `backend/app/Services/Hellom/ManualPaymentSettingsService.php`
- `backend/app/Services/Hellom/PaymentGatewaySettingsService.php`
- `backend/app/Mail/HellomCheckoutStatusMail.php`
- `backend/resources/views/emails/hellom-checkout-status.blade.php`
- `backend/routes/api.php`
- `backend/config/payments.php`

## Main frontend files

- `plans/UI/src/lib/hellomApi.ts`
- `plans/UI/src/pages/admin/AdminSettings.tsx`
- `plans/UI/src/components/SubscriptionModal.tsx`

## Runtime config

Stored in `system_settings`:

- `hellom_active_payment_gateway`
- `hellom_checkout_mode`
- `hellom_member_wallet_enabled`

Supported values:

- provider: `xendit`, `ipaymu`, `doku`
- checkout mode: `manual_confirmation`, `gateway_automatic`

## DOKU config

Stored in `system_settings`:

- `hellom_doku_client_id`
- `hellom_doku_secret_key`
- `hellom_doku_callback_token`
- `hellom_doku_is_production`
- `hellom_doku_payment_methods`

Environment fallback:

- `DOKU_CLIENT_ID`
- `DOKU_SECRET_KEY`
- `DOKU_CALLBACK_TOKEN`
- `DOKU_IS_PRODUCTION`
- `DOKU_PAYMENT_METHOD_TYPES`

Webhook endpoint:

- `POST /api/v1/hellom/webhooks/doku`

Current webhook auth:

- query param `token` or header `X-DOKU-TOKEN`
- compared against `hellom_doku_callback_token`

Current DOKU implementation uses Checkout API and stores:

- payment URL
- DOKU session id
- invoice number
- selected payment method types

## Manual payment config

Admin endpoints:

- `GET /api/v1/hellom/admin/billing/manual-payment-config`
- `POST /api/v1/hellom/admin/billing/manual-payment-config`

Uses `FormData` because image upload is supported for:

- bank transfer proof image / banner
- GoPay image
- DANA image
- QRIS image

Storage:

- `storage/app/public/hellom/manual-payments`

Published to member checkout via:

- `GET /api/v1/hellom/billing/gateway-status`
- payload field: `manual_payment`

## Subscription rules

Billing cycle is now resolved from:

- explicit request `billing_cycle`
- or fallback from plan definition

End date is resolved from:

- `duration_days`
- yearly billing
- lifetime plan -> `ends_at = null`

## Important gaps / next steps

- Add automated feature tests specifically for:
  - DOKU checkout creation
  - manual payment config save/publish
  - manual approval email flow
  - DOKU webhook activation flow
- Current Laravel feature tests are blocked by an older SQLite migration issue unrelated to this work:
  - query against `information_schema.TABLE_CONSTRAINTS`
- Frontend `vite build` in this repo is currently blocked by an existing project config issue with absolute HTML output path
- Consider moving checkout notification logic into a dedicated service to remove duplication across Xendit/iPaymu/DOKU webhook controllers
- Consider adding buyer upload proof flow if owner needs stronger manual verification evidence
- Consider verifying DOKU webhook signature headers in addition to callback token

## Quick smoke checklist

1. Configure SMTP in admin mail settings
2. Configure DOKU in admin payment settings
3. Set runtime provider to `doku`
4. Set checkout mode to `gateway_automatic`
5. Buy locked POS app from `/hellom/dashboard`
6. Ensure payment URL opens
7. Trigger DOKU webhook and verify:
   - subscription active
   - entitlement active
   - POS provisioned
   - owner email sent
   - buyer email sent
8. Switch checkout mode to `manual_confirmation`
9. Configure manual bank / gopay / dana / qris
10. Repeat checkout and verify:
   - methods shown in dashboard modal
   - pending checkout visible in admin
   - owner email sent on checkout creation
   - buyer email sent with instructions
   - approval sends final emails and unlocks POS
