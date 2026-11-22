<?php
declare(strict_types=1);

if (!defined('SMARTLIB_CONFIG_LOADING')) {
    http_response_code(404);
    exit;
}

return [
    'DB_HOST' => 'your-database-host',
    'DB_USER' => 'your-database-user',
    'DB_PASSWORD' => '',
    'DB_PORT' => '3306',
    'DB_NAME' => 'your-database-name',
    'DB_SSL_CA' => '',
    'REDIS_URL' => '',
    'APP_PEPPER' => '',
    'MOBILE_CORS_ORIGINS' => '',
    'APP_MAIL_FROM' => '',
    'SMTP_HOST' => '',
    'SMTP_PORT' => '587',
    'SMTP_USERNAME' => '',
    'SMTP_PASSWORD' => '',
    'SMTP_ENCRYPTION' => 'tls',
];
