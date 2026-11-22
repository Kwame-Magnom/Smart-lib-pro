<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

try {
    $connection = db();
    $user = current_user($connection);
    if (!$user) { header('Location: login.php'); exit; }
    if ($user['role'] === 'Librarian') { header('Location: librarian_dashboard.php'); exit; }
    if ($user['role'] === 'Headmaster') { header('Location: headmaster_dashboard.php'); exit; }
    if (in_array($user['role'], ['Student', 'User', 'Teacher', 'Pastor'], true)) { header('Location: student_dashboard.php'); exit; }
    if (in_array($user['role'], ['Administrator', 'Super Administrator'], true)) { header('Location: admin_dashboard.php'); exit; }
} catch (Throwable $exception) {
    error_log((string) $exception); http_response_code(503); exit('The dashboard is temporarily unavailable.');
}

require __DIR__ . '/smartlib.php';
