<?php

return [
    'sla_hours' => (int) env('SUPPORT_SLA_HOURS', 24),
    'reopen_days' => (int) env('SUPPORT_REOPEN_DAYS', 7),
    'max_attachment_bytes' => 5 * 1024 * 1024,
    'max_attachments_per_message' => 3,
    'ai_daily_limit' => (int) env('SUPPORT_AI_DAILY_LIMIT', 20),
];
