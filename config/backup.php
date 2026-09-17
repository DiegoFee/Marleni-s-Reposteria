<?php

return [
    'path' => env('BACKUP_PATH', 'storage/app/private/backups'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
    'dump_binary' => env('BACKUP_DUMP_BINARY', 'mariadb-dump'),
];
