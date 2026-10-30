<?php
return [
    'mysqldump' => env('MYSQLDUMP_PATH', PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin/mysqldump.exe' : 'mysqldump'),
    'backup_password' => env('BACKUP_PASSWORD'),
    'scheduled_backups' => (bool) env('SCHOOL_SCHEDULED_BACKUPS', false),
];
