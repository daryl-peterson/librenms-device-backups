<?php

return [
    'archive_password' => env('DEVICE_BACKUPS_ARCHIVE_PASSWORD'),
    'scheduled' => env('DEVICE_BACKUPS_SCHEDULED', false),
    'reserve_bytes' => (int) env('DEVICE_BACKUPS_RESERVE_BYTES', 5 * 1024 ** 3),
];
