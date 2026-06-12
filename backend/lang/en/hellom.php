<?php

return [
    // ─── Auth ───
    'unauthorized' => 'Unauthorized',
    'invalid_credentials' => 'Invalid credentials',
    'registered' => 'Registered',
    'logged_in' => 'Logged in',
    'logged_out' => 'Logged out',
    'forgot_password_sent' => 'If the account exists, reset instructions have been sent',
    'password_reset_success' => 'Password has been reset',
    'password_reset_failed' => 'Password reset failed',
    'profile_updated' => 'Profile updated',
    'password_changed' => 'Password changed',
    'invalid_current_password' => 'Current password is incorrect',

    // ─── Organization ───
    'no_active_organization' => 'No active organization',
    'organization_created' => 'Organization created',
    'organization_switched' => 'Organization switched',
    'organization_not_found' => 'Organization not found',

    // ─── Team ───
    'member_invited' => 'Member invited',
    'member_role_updated' => 'Member role updated',
    'member_removed' => 'Member removed',
    'already_member' => 'User is already a member of this organization',
    'user_not_found' => 'User not found. User must register first.',
    'insufficient_role' => 'You do not have sufficient role for this action',
    'owner_role_locked' => 'Owner role cannot be changed with this endpoint',
    'cannot_remove_self' => 'You cannot remove yourself from current organization via this endpoint',

    // ─── Billing ───
    'wallet_topup_success' => 'Wallet top-up successful',
    'checkout_confirmed' => 'Checkout confirmed',
    'intent_not_found' => 'Checkout intent not found',
    'insufficient_wallet_balance' => 'Insufficient wallet balance for checkout',
    'subscription_renewed' => 'Subscription renewed',
    'subscription_cancelled' => 'Subscription cancelled',

    // ─── Landing Builder ───
    'page_created' => 'Page created',
    'page_updated' => 'Page updated',
    'page_deleted' => 'Page deleted',
    'page_published' => 'Page published',
    'page_unpublished' => 'Page unpublished',
    'page_duplicated' => 'Page duplicated',
    'template_applied' => 'Template applied',

    // ─── File Assets ───
    'file_uploaded' => 'File asset uploaded',
    'file_duplicate_reused' => 'Duplicate file — reusing existing asset',
    'storage_quota_exceeded' => 'Organization storage quota exceeded (100 MB)',

    // ─── Super Admin ───
    'admin_only' => 'This action requires super admin privileges',
    'org_suspended' => 'Organization suspended',
    'org_reactivated' => 'Organization reactivated',
    'user_suspended' => 'User suspended',
    'user_reactivated' => 'User reactivated',
    'app_updated' => 'App updated',
    'plan_updated' => 'Plan updated',
    'plan_created' => 'Plan created',
    'plan_deleted' => 'Plan deleted',
    'entitlement_overridden' => 'Entitlement overridden',

    // ─── Purchase Settings ───
    'purchase_setting_created' => 'Purchase setting created',
    'purchase_setting_updated' => 'Purchase setting updated',
    'purchase_setting_deleted' => 'Purchase setting deleted',
    'service_type_exists' => 'Service type already exists',
    'setting_not_found' => 'Setting not found',

    // ─── Promo ───
    'promo_created' => 'Promo campaign created',
    'promo_updated' => 'Promo campaign updated',
    'promo_deleted' => 'Promo campaign deleted',
    'promo_invalid' => 'Invalid or expired promo code',
    'promo_max_slots_reached' => 'This promo has reached maximum redemptions',

    // ─── Locale ───
    'locale_switched' => 'Locale switched',
];
