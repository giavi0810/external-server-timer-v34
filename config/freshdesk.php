<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Freshdesk API Configuration
    |--------------------------------------------------------------------------
    */

    'domain'  => env('FRESHDESK_DOMAIN'),
    'api_key' => env('FRESHDESK_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Basic Auth for Webhook Endpoint
    |--------------------------------------------------------------------------
    */

    'basic_auth' => [
        'username' => env('FRESHDESK_WEBHOOK_USER', 'admin'),
        'password' => env('FRESHDESK_WEBHOOK_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Group Mapping (Freshdesk Group ID → Internal Name)
    |--------------------------------------------------------------------------
    */

    'group_mapping' => [
        // Automatically populated from Freshdesk API (php artisan freshdesk:sync-groups)
    ],

    /*
    |--------------------------------------------------------------------------
    | Group Layers (Internal Name → Layer)
    |--------------------------------------------------------------------------
    */

    'group_layers' => [
        // Auto-detected dynamically (L1, L2, L3, L4)
    ],


    /*
    |--------------------------------------------------------------------------
    | CX Groups
    |--------------------------------------------------------------------------
    */

    'cx_groups' => [
        'CX',
        'Group CX',
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Map (Freshdesk numeric → string)
    |--------------------------------------------------------------------------
    */

    'status_map' => [
        2 => 'Open',
        3 => 'Pending',
        4 => 'Resolved',
        5 => 'Closed',
        6 => 'Waiting For Customer',
        7 => 'Processing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Priority Map (Freshdesk numeric → string)
    |--------------------------------------------------------------------------
    */

    'priority_map' => [
        1 => 'Low',
        2 => 'Medium',
        3 => 'High',
        4 => 'Urgent',
    ],

    /*
    |--------------------------------------------------------------------------
    | Priority Weight (for comparison)
    |--------------------------------------------------------------------------
    */

    'priority_weight' => [
        'Low'    => 1,
        'Medium' => 2,
        'High'   => 3,
        'Urgent' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Categories for SLA Timer Logic
    |--------------------------------------------------------------------------
    */

    // Timer runs (counting SLA time)
    'run_statuses' => [
        'Open',
        'Processing',
    ],

    // Timer pauses (not counting SLA time)
    'pause_statuses' => [
        'Waiting For Customer',
        'Pending',
    ],

    // Timer ends (ticket finished)
    'end_statuses' => [
        'Resolved',
        'Closed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reopen Threshold (minutes)
    |--------------------------------------------------------------------------
    | Ngưỡng thời gian (phút) kể từ khi đóng ticket.
    | <= ngưỡng: Reopen ticket cũ. > ngưỡng: Tạo ticket mới.
    | Cấu hình thử nghiệm: 1440 phút (1 ngày).
    */

    'reopen_threshold_minutes' => (int) env('FRESHDESK_REOPEN_THRESHOLD', 1440),

    /*
    |--------------------------------------------------------------------------
    | Legacy Ticket Filter (Phase 1)
    |--------------------------------------------------------------------------
    */

    'enable_legacy_ticket_filter' => (bool) env('ENABLE_LEGACY_TICKET_FILTER', false),
    'go_live_timestamp' => env('GO_LIVE_TIMESTAMP'),

];

