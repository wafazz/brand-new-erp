<?php

declare(strict_types=1);

return [
    'directory' => env('BACKUP_DIRECTORY', storage_path('backups')),

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    'minimum_bytes' => (int) env('BACKUP_MINIMUM_BYTES', 1024),

    'offsite' => [
        'enabled' => (bool) env('BACKUP_OFFSITE_ENABLED', false),
        'command' => env('BACKUP_OFFSITE_COMMAND'),
    ],

    'verify' => [
        'database' => env('BACKUP_VERIFY_DATABASE', 'sme_erp_restore_check'),
    ],
];
