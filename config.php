<?php
declare(strict_types=1);

// System environment values take priority. A local env file is supported for
// XAMPP development and is deliberately stored outside htdocs.
function config_env_file_values(): array
{
    static $values = null;
    if (is_array($values)) {
        return $values;
    }
    $values = [];
    $path = getenv('APP_ENV_FILE') ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'secure-portal.env';
    if (!is_readable($path)) {
        return $values;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            $values[$key] = trim(trim($value), "\"'");
        }
    }
    return $values;
}

function config_local_values(): array
{
    static $values = null;
    if (is_array($values)) {
        return $values;
    }
    $values = [];
    $path = getenv('APP_LOCAL_CONFIG') ?: __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
    if (!is_file($path) || !is_readable($path)) {
        return $values;
    }
    if (!defined('SMARTLIB_CONFIG_LOADING')) {
        define('SMARTLIB_CONFIG_LOADING', true);
    }
    $localValues = require $path;
    if (is_array($localValues)) {
        $values = $localValues;
    }
    return $values;
}

function config_env(string $key, string $default = ''): string
{
    $systemValue = getenv($key);
    if ($systemValue !== false && $systemValue !== '') {
        return $systemValue;
    }
    $fileValues = config_env_file_values();
    if (isset($fileValues[$key]) && $fileValues[$key] !== '') {
        return $fileValues[$key];
    }
    $localValues = config_local_values();
    if (isset($localValues[$key]) && is_scalar($localValues[$key])) {
        return (string) $localValues[$key];
    }
    return $default;
}

define('DB_HOST', config_env('DB_HOST', '127.0.0.1'));
define('DB_USER', config_env('DB_USER', 'root'));
define('DB_PASSWORD', config_env('DB_PASSWORD'));
define('DB_PORT', (int) config_env('DB_PORT', '3307'));
define('DB_NAME', config_env('DB_NAME', 'infosec_db'));
define('DB_SSL_CA', config_env('DB_SSL_CA'));
define('REDIS_URL', config_env('REDIS_URL'));
define('APP_PEPPER', config_env('APP_PEPPER'));
define('MOBILE_CORS_ORIGINS', config_env('MOBILE_CORS_ORIGINS'));
define('APP_MAIL_FROM', config_env('APP_MAIL_FROM', 'library@example.com'));
define('SMTP_HOST', config_env('SMTP_HOST'));
define('SMTP_PORT', (int) config_env('SMTP_PORT', '587'));
define('SMTP_USERNAME', config_env('SMTP_USERNAME'));
define('SMTP_PASSWORD', config_env('SMTP_PASSWORD'));
define('SMTP_ENCRYPTION', strtolower(config_env('SMTP_ENCRYPTION', 'tls')));
define('PRIVATE_KEY_PATH', config_env('PRIVATE_KEY_PATH'));
define('PUBLIC_KEY_PATH', config_env('PUBLIC_KEY_PATH'));

// Keep backups outside the public web root. These values may also be supplied
// through Apache/Task Scheduler environment variables.
define('BACKUP_DIRECTORY', config_env('BACKUP_DIRECTORY', 'C:\\xampp\\backups\\secure-portal'));
define('MYSQLDUMP_PATH', config_env('MYSQLDUMP_PATH', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'));
define('MYSQL_PATH', config_env('MYSQL_PATH', 'C:\\xampp\\mysql\\bin\\mysql.exe'));
define('BACKUP_RETENTION_DAYS', max(1, (int) config_env('BACKUP_RETENTION_DAYS', '14')));
