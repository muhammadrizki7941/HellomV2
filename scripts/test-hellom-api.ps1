param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$Email = 'testuser_hellom@example.com',
    [string]$Password = 'Secret1234',
    [string]$MockWebhookSecret = 'dev_mock_webhook_secret'
)

$ErrorActionPreference = 'Stop'

function Out-Step($title) {
    Write-Host "`n=== $title ===" -ForegroundColor Cyan
}

Out-Step '1) Login'
$loginBody = @{ email = $Email; password = $Password } | ConvertTo-Json
$login = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/v1/hellom/auth/login" -ContentType 'application/json' -Body $loginBody
$token = $login.data.token
$headers = @{ Authorization = "Bearer $token" }
@{ login_success = $login.success; user = $login.data.user.email } | ConvertTo-Json -Depth 6

Out-Step '2) Billing Overview + History'
$overview = Invoke-RestMethod -Method Get -Uri "$BaseUrl/api/v1/hellom/billing/overview" -Headers $headers
$history = Invoke-RestMethod -Method Get -Uri "$BaseUrl/api/v1/hellom/billing/history?limit=10" -Headers $headers
@{
    overview_success = $overview.success
    active_subscriptions = $overview.data.summary.active_subscriptions_count
    pending_intents = $overview.data.summary.pending_checkout_intents_count
    history_success = $history.success
    history_subscriptions_count = $history.data.subscriptions.Count
    history_intents_count = $history.data.checkout_intents.Count
} | ConvertTo-Json -Depth 8

Out-Step '3) Create Checkout Intent (POS)'
$intentBody = @{ app_slug = 'pos'; plan_slug = 'pos_starter' } | ConvertTo-Json
$intent = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/v1/hellom/billing/checkout-intent-mock" -Headers $headers -ContentType 'application/json' -Body $intentBody
@{ intent_success = $intent.success; intent_token = $intent.data.checkout_intent.intent_token } | ConvertTo-Json -Depth 8

Out-Step '4) Webhook Mock Success'
$webhookHeaders = @{ 'X-Mock-Signature' = $MockWebhookSecret }
$webhookBody = @{
    intent_token = $intent.data.checkout_intent.intent_token
    payment_status = 'success'
    provider_ref = 'script_mock_success_001'
} | ConvertTo-Json
$webhook = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/v1/hellom/billing/webhook/mock-payment" -Headers $webhookHeaders -ContentType 'application/json' -Body $webhookBody
@{
    webhook_success = $webhook.success
    intent_status = $webhook.data.intent_status
    subscription_status = $webhook.data.subscription_status
} | ConvertTo-Json -Depth 8

Out-Step '5) Check POS Entitlement'
$checkPos = Invoke-RestMethod -Method Get -Uri "$BaseUrl/api/v1/hellom/entitlements/check/pos" -Headers $headers
@{ check_success = $checkPos.success; pos_allowed = $checkPos.data.allowed; pos_status = $checkPos.data.status } | ConvertTo-Json -Depth 8

Out-Step '6) Cancel Active POS Subscription'
$historyLatest = Invoke-RestMethod -Method Get -Uri "$BaseUrl/api/v1/hellom/billing/history?limit=20" -Headers $headers
$activeSub = $historyLatest.data.subscriptions | Where-Object { $_.status -eq 'active' -and $_.app_slug -eq 'pos' } | Select-Object -First 1
if (-not $activeSub) {
    throw 'No active POS subscription found to cancel.'
}
$cancel = Invoke-RestMethod -Method Post -Uri ("$BaseUrl/api/v1/hellom/billing/subscriptions/{0}/cancel-mock" -f $activeSub.id) -Headers $headers
$checkAfterCancel = Invoke-RestMethod -Method Get -Uri "$BaseUrl/api/v1/hellom/entitlements/check/pos" -Headers $headers
@{
    cancel_success = $cancel.success
    canceled_subscription_id = $cancel.data.subscription.id
    canceled_subscription_status = $cancel.data.subscription.status
    entitlement_after_cancel = $cancel.data.entitlement.status
    pos_allowed_after_cancel = $checkAfterCancel.data.allowed
} | ConvertTo-Json -Depth 8

Write-Host "`nAll Hellom API checks completed." -ForegroundColor Green
