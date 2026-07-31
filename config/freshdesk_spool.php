<?php

return [
    'enabled' => env('FRESHDESK_SPOOL_ENABLED', true),
    'root' => env('FRESHDESK_SPOOL_PATH', storage_path('app/freshdesk-spool')),
    'queue' => env('FRESHDESK_INGEST_QUEUE', 'freshdesk-ingest'),
    'dispatch_batch' => (int) env('FRESHDESK_SPOOL_DISPATCH_BATCH', 250),
    'enqueued_visibility_seconds' => (int) env('FRESHDESK_SPOOL_ENQUEUED_VISIBILITY_SECONDS', 3600),
    'processing_lease_seconds' => (int) env('FRESHDESK_SPOOL_PROCESSING_LEASE_SECONDS', 180),
    'gc_after_seconds' => (int) env('FRESHDESK_SPOOL_GC_AFTER_SECONDS', 3600),
    'max_payload_bytes' => (int) env('FRESHDESK_SPOOL_MAX_PAYLOAD_BYTES', 1048576),
    'max_attempts' => (int) env('FRESHDESK_SPOOL_MAX_ATTEMPTS', 1000),
    'backoff' => [5, 15, 30, 60, 120, 300, 600],
    'fsync_dir_binary' => env('FRESHDESK_FSYNC_DIR_BINARY', '/usr/local/bin/fsync-dir'),
    'require_directory_fsync' => (bool) env(
        'FRESHDESK_REQUIRE_DIRECTORY_FSYNC',
        env('APP_ENV', 'production') === 'production'
    ),
];
