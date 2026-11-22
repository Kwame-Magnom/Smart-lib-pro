<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/redis_session_config.php';

function configure_session_storage(): void
{
    $isVercel = getenv('VERCEL') === '1';
    if (REDIS_URL === '') {
        if ($isVercel) {
            throw new RuntimeException('REDIS_URL must be configured for Vercel session storage.');
        }
        return;
    }
    if (!extension_loaded('redis')) {
        throw new RuntimeException('The PHP Redis extension is required when REDIS_URL is configured.');
    }

    $savePath = redis_session_save_path(REDIS_URL, $isVercel);
    if (ini_set('session.save_handler', 'redis') === false
        || ini_set('session.save_path', $savePath) === false
        || ini_set('redis.session.locking_enabled', '1') === false) {
        throw new RuntimeException('Unable to configure Redis-backed PHP sessions.');
    }
}

$isHttps = getenv('VERCEL') === '1'
    || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
if (session_status() !== PHP_SESSION_ACTIVE) {
    configure_session_storage();
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_is_valid(): bool
{
    return isset($_SESSION['csrf_token'], $_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return is_string($message) ? $message : null;
}

function require_pepper(): string
{
    if (strlen(APP_PEPPER) < 32) {
        throw new RuntimeException('APP_PEPPER is not configured. Set it to a random secret of at least 32 characters.');
    }
    return APP_PEPPER;
}

function password_digest(string $password): string
{
    // The pepper is applied before password_hash(). password_hash() generates
    // and stores a unique random salt in every resulting hash automatically.
    return hash_hmac('sha256', $password, require_pepper());
}

function password_hash_options(): array
{
    if (defined('PASSWORD_ARGON2ID')) {
        return [
            'algorithm' => PASSWORD_ARGON2ID,
            'options' => [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 1,
            ],
        ];
    }
    return [
        'algorithm' => PASSWORD_BCRYPT,
        'options' => ['cost' => 12],
    ];
}

function password_hash_secure(string $password): string
{
    $passwordConfig = password_hash_options();
    $hash = password_hash(password_digest($password), $passwordConfig['algorithm'], $passwordConfig['options']);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('The password hash could not be generated.');
    }
    return $hash;
}

function password_verify_secure(string $password, string $storedHash): bool
{
    return password_verify(password_digest($password), $storedHash);
}

function password_needs_secure_rehash(string $storedHash): bool
{
    $passwordConfig = password_hash_options();
    return password_needs_rehash($storedHash, $passwordConfig['algorithm'], $passwordConfig['options']);
}

function otp_digest(string $otp): string
{
    return hash_hmac('sha256', $otp, require_pepper());
}

function password_security_details(mysqli $connection, int $userId): array
{
    $statement = $connection->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $statement->bind_param('i', $userId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();

    $storedHash = is_array($row) ? (string) ($row['password_hash'] ?? '') : '';
    return password_security_details_from_hash($storedHash);
}

function password_security_details_from_hash(string $storedHash): array
{
    $salt = 'Unavailable';
    if ($storedHash !== '') {
        $parts = explode('$', $storedHash);
        if (isset($parts[1]) && str_starts_with($parts[1], 'argon')) {
            $salt = $parts[4] ?? 'Unavailable';
        } elseif (isset($parts[3]) && str_starts_with($parts[1], '2')) {
            $salt = substr($parts[3], 0, 22);
        }
    }

    $info = $storedHash !== '' ? password_get_info($storedHash) : [];
    $options = $info['options'] ?? [];
    $cost = [];
    foreach (['cost', 'memory_cost', 'time_cost', 'threads'] as $option) {
        if (isset($options[$option])) {
            $cost[] = $option . '=' . (string) $options[$option];
        }
    }
    $pepperConfigured = strlen(APP_PEPPER) >= 32;
    return [
        'algorithm' => (string) ($info['algoName'] ?? 'Unavailable'),
        'cost' => $cost ? implode(', ', $cost) : 'n/a',
        'hash_fingerprint' => $storedHash !== '' ? strtoupper(substr(hash('sha256', $storedHash), 0, 16)) : 'Unavailable',
        'salt' => $salt,
        'pepper' => $pepperConfigured
            ? 'Configured · fingerprint ' . strtoupper(substr(hash('sha256', APP_PEPPER), 0, 16))
            : 'Not configured',
    ];
}

function key_pair_status(): array
{
    if (!is_file(PUBLIC_KEY_PATH) || !is_file(PRIVATE_KEY_PATH)) {
        return ['configured' => false, 'fingerprint' => 'Unavailable'];
    }
    $publicKey = file_get_contents(PUBLIC_KEY_PATH);
    if ($publicKey === false) {
        return ['configured' => false, 'fingerprint' => 'Unavailable'];
    }
    return [
        'configured' => true,
        // This is an identifier for the public key, not the key itself.
        'fingerprint' => strtoupper(substr(hash('sha256', $publicKey), 0, 16)),
    ];
}

function send_login_otp(string $email, string $otp): bool
{
    $subject = 'Your sign-in verification code';
    $body = "Your sign-in code is: {$otp}\n\nThis code expires in 10 minutes and can be used only once. If you did not request it, you can ignore this email.";
    if (SMTP_HOST !== '') {
        $autoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            error_log('OTP SMTP delivery is configured, but Composer dependencies are missing.');
            return false;
        }
        require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log('OTP SMTP delivery requires PHPMailer. Install the project Composer dependencies.');
            return false;
        }
        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = SMTP_HOST;
            $mailer->Port = SMTP_PORT;
            $mailer->SMTPAuth = SMTP_USERNAME !== '';
            $mailer->Username = SMTP_USERNAME;
            $mailer->Password = SMTP_PASSWORD;
            if (SMTP_ENCRYPTION === 'ssl') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif (SMTP_ENCRYPTION === 'tls') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif (SMTP_ENCRYPTION !== '') {
                error_log('OTP SMTP encryption must be tls, ssl, or empty.');
                return false;
            }
            $mailer->Timeout = 15;
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom(APP_MAIL_FROM, 'SmartLib Pro');
            $mailer->addAddress($email);
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->send();
            return true;
        } catch (Throwable $exception) {
            error_log('OTP SMTP delivery failed: ' . $exception->getMessage());
            return false;
        }
    }

    if (getenv('VERCEL') === '1') {
        error_log('OTP email delivery requires SMTP on Vercel. Configure SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, and APP_MAIL_FROM.');
        return false;
    }

    $headers = "From: " . APP_MAIL_FROM . "\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
    set_error_handler(static function (int $severity, string $warning): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        error_log('OTP email transport warning: ' . $warning);
        return true;
    }, E_WARNING);
    try {
        return mail($email, $subject, $body, $headers);
    } finally {
        restore_error_handler();
    }
}

function issue_login_otp(mysqli $connection, array $user): void
{
    $otp = (string) random_int(100000, 999999);
    $hash = otp_digest($otp);
    $delete = $connection->prepare('DELETE FROM login_otps WHERE user_id = ?');
    $delete->bind_param('i', $user['id']);
    $delete->execute();
    $delete->close();

    $insert = $connection->prepare('INSERT INTO login_otps (user_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
    $insert->bind_param('is', $user['id'], $hash);
    $insert->execute();
    $challengeId = $connection->insert_id;
    $insert->close();

    if (!send_login_otp((string) $user['email'], $otp)) {
        $delete = $connection->prepare('DELETE FROM login_otps WHERE id = ?');
        $delete->bind_param('i', $challengeId);
        $delete->execute();
        $delete->close();
        throw new RuntimeException('Email delivery failed. Configure the SMTP settings and check the server error log.');
    }
    $_SESSION['pending_user_id'] = (int) $user['id'];
    $_SESSION['pending_challenge_id'] = (int) $challengeId;
}

function current_user(mysqli $connection): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $id = (int) $_SESSION['user_id'];
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 7200) {
        session_unset();
        session_destroy();
        return null;
    }
    $_SESSION['last_activity'] = time();
    $statement = $connection->prepare('SELECT id, username, display_name, email, school_id, role, account_status FROM users WHERE id = ? LIMIT 1');
    $statement->bind_param('i', $id);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc() ?: null;
    $statement->close();
    if (!$user || $user['account_status'] !== 'Active' || !in_array($user['role'], ['User', 'Pastor', 'Headmaster', 'Administrator', 'Super Administrator', 'Librarian', 'Assistant Librarian', 'Teacher', 'Student', 'Accountant', 'IT Support'], true)) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function can(array $user, string $permission): bool
{
    $role = (string) $user['role'];
    $permissions = [
        'Super Administrator' => ['*'],
        'Administrator' => ['dashboard', 'catalogue.view', 'catalogue.manage', 'copies.manage', 'circulation.manage', 'reservations.manage', 'fines.manage', 'reports.view', 'users.manage', 'settings.manage', 'audit.view', 'notifications.manage', 'approvals.manage', 'courses.manage', 'course.requests.manage', 'activity.view'],
        'Headmaster' => ['dashboard', 'catalogue.view', 'reports.view', 'fines.view', 'activity.view', 'approvals.manage'],
        'Librarian' => ['dashboard', 'catalogue.view', 'catalogue.manage', 'copies.manage', 'circulation.manage', 'reservations.manage', 'fines.manage', 'reports.view', 'notifications.manage', 'members.manage', 'approval.request'],
        'Assistant Librarian' => ['dashboard', 'catalogue.view', 'copies.manage', 'circulation.manage', 'reservations.manage'],
        'Accountant' => ['dashboard', 'fines.manage', 'reports.view'],
        'IT Support' => ['dashboard', 'audit.view'],
        'Teacher' => ['dashboard', 'catalogue.view', 'member.self'],
        'Student' => ['dashboard', 'catalogue.view', 'member.self'],
        'Pastor' => ['dashboard', 'catalogue.view', 'member.self'],
        'User' => ['dashboard', 'catalogue.view', 'member.self'],
    ];
    return in_array('*', $permissions[$role] ?? [], true) || in_array($permission, $permissions[$role] ?? [], true);
}

function require_permission(array $user, string $permission): void
{
    if (!can($user, $permission)) {
        http_response_code(403);
        exit('Unauthorized access.');
    }
}

function audit(mysqli $connection, ?int $userId, string $action, string $entityType, ?string $entityId = null, ?string $details = null): void
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $statement = $connection->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $statement->bind_param('isssss', $userId, $action, $entityType, $entityId, $details, $ip);
    $statement->execute();
    $statement->close();
}

function notify_user(mysqli $connection, int $userId, string $type, string $message, ?string $link = null): void
{
    $statement = $connection->prepare('INSERT INTO notifications (user_id, type, message, link_url) VALUES (?, ?, ?, ?)');
    $statement->bind_param('isss', $userId, $type, $message, $link);
    $statement->execute();
    $statement->close();
}

function require_role(array $user, string ...$roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Forbidden');
    }
}
