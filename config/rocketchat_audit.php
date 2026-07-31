<?php

return [
    'enabled' => (bool) env('ROCKETCHAT_AUDIT_ENABLED', true),
    'root' => env(
        'ROCKETCHAT_AUDIT_PATH',
        storage_path('app/rocketchat-audit')
    ),
    'sync_batch' => (int) env('ROCKETCHAT_AUDIT_SYNC_BATCH', 100),
    'pending_timeout_seconds' => (int) env('ROCKETCHAT_AUDIT_PENDING_TIMEOUT_SECONDS', 300),
    'processing_timeout_seconds' => (int) env(
        'ROCKETCHAT_AUDIT_PROCESSING_TIMEOUT_SECONDS',
        300
    ),
    'retention_days' => (int) env('ROCKETCHAT_AUDIT_RETENTION_DAYS', 90),
    'fsync_dir_binary' => env('FRESHDESK_FSYNC_DIR_BINARY', '/usr/local/bin/fsync-dir'),
    'require_directory_fsync' => (bool) env(
        'ROCKETCHAT_AUDIT_REQUIRE_DIRECTORY_FSYNC',
        env('APP_ENV', 'production') === 'production'
    ),
];
