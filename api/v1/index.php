<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$originHost = $origin !== '' ? parse_url($origin, PHP_URL_HOST) : null;
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$requestHostName = parse_url('http://' . $requestHost, PHP_URL_HOST);
$configuredOrigins = array_filter(array_map('trim', explode(',', MOBILE_CORS_ORIGINS)));
$sameHost = is_string($originHost) && is_string($requestHostName)
    && strtolower($originHost) === strtolower($requestHostName);
$localDevelopment = preg_match('~^https?://(localhost|127\.0\.0\.1)(:\d+)?$~i', $origin) === 1;
if ($origin !== '' && ($sameHost || $localDevelopment || in_array($origin, $configuredOrigins, true))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function api_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function api_error(string $message, int $status = 400): never
{
    api_response(['error' => $message], $status);
}

function api_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        api_error('Request body must be a JSON object.');
    }
    return $body;
}

function api_authorization_header(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header !== '') {
        return $header;
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value)) {
                return $value;
            }
        }
    }
    return '';
}

function api_user(mysqli $db): array
{
    $header = api_authorization_header();
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{40,100})$/', $header, $matches)) {
        api_error('Sign in to continue.', 401);
    }
    $tokenHash = hash('sha256', $matches[1]);
    $query = $db->prepare(
        "SELECT u.id, u.username, u.display_name, u.email, u.school_id, u.role, u.account_status, t.id AS token_id
         FROM mobile_api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()
         LIMIT 1"
    );
    $query->bind_param('s', $tokenHash);
    $query->execute();
    $user = $query->get_result()->fetch_assoc();
    $query->close();
    if (!$user || $user['account_status'] !== 'Active') {
        api_error('Your session has expired. Sign in again.', 401);
    }
    $tokenId = (int) $user['token_id'];
    $touch = $db->prepare('UPDATE mobile_api_tokens SET last_used_at = NOW() WHERE id = ?');
    $touch->bind_param('i', $tokenId);
    $touch->execute();
    $touch->close();
    unset($user['token_id'], $user['account_status']);
    return $user;
}

function api_member_access(array $user): void
{
    if (!can($user, 'member.self')) {
        api_error('This action is only available to library members.', 403);
    }
}

function api_user_data(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'display_name' => (string) ($user['display_name'] ?? ''),
        'email' => (string) $user['email'],
        'school_id' => (string) ($user['school_id'] ?? ''),
        'role' => (string) $user['role'],
    ];
}

function api_login(mysqli $db, array $body): never
{
    $identifier = trim((string) ($body['identifier'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($identifier === '' || $password === '' || strlen($identifier) > 254 || strlen($password) > 4096) {
        api_error('Enter your username/email and password.');
    }

    $query = $db->prepare('SELECT id, username, email, password_hash, role, account_status FROM users WHERE username = ? OR email = ? LIMIT 1');
    $query->bind_param('ss', $identifier, $identifier);
    $query->execute();
    $user = $query->get_result()->fetch_assoc() ?: null;
    $query->close();
    $validPassword = $user
        ? password_verify_secure($password, (string) $user['password_hash'])
        : (password_hash_secure($password) !== '');
    if ($user && $validPassword && password_needs_secure_rehash((string) $user['password_hash'])) {
        $replacementHash = password_hash_secure($password);
        $rehash = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $rehash->bind_param('si', $replacementHash, $user['id']);
        $rehash->execute();
        $rehash->close();
    }

    $validRoles = ['User', 'Pastor', 'Headmaster', 'Administrator', 'Super Administrator', 'Librarian', 'Assistant Librarian', 'Teacher', 'Student', 'Accountant', 'IT Support'];
    if (!$user || !$validPassword || $user['account_status'] !== 'Active' || !in_array($user['role'], $validRoles, true)) {
        audit($db, $user ? (int) $user['id'] : null, 'login_failed', 'user', $user ? (string) $user['id'] : null, 'Mobile credentials rejected');
        api_error('The username/email or password is incorrect.', 401);
    }

    $userId = (int) $user['id'];
    $db->begin_transaction();
    try {
        $invalidate = $db->prepare('UPDATE mobile_login_challenges SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL');
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();
        $invalidate->close();
        $delete = $db->prepare('DELETE FROM login_otps WHERE user_id = ?');
        $delete->bind_param('i', $userId);
        $delete->execute();
        $delete->close();

        $otp = (string) random_int(100000, 999999);
        $otpHash = otp_digest($otp);
        $insert = $db->prepare('INSERT INTO login_otps (user_id, code_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
        $insert->bind_param('is', $userId, $otpHash);
        $insert->execute();
        $otpId = $db->insert_id;
        $insert->close();

        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challengeHash = hash('sha256', $challenge);
        $challengeInsert = $db->prepare(
            'INSERT INTO mobile_login_challenges (user_id, otp_id, challenge_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
        );
        $challengeInsert->bind_param('iis', $userId, $otpId, $challengeHash);
        $challengeInsert->execute();
        $challengeInsert->close();
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    if (!send_login_otp((string) $user['email'], $otp)) {
        $remove = $db->prepare('DELETE FROM login_otps WHERE id = ?');
        $remove->bind_param('i', $otpId);
        $remove->execute();
        $remove->close();
        $invalidate = $db->prepare('UPDATE mobile_login_challenges SET consumed_at = NOW() WHERE challenge_hash = ?');
        $invalidate->bind_param('s', $challengeHash);
        $invalidate->execute();
        $invalidate->close();
        api_error('Email verification could not be sent. Check the server email configuration.', 503);
    }

    api_response(['challenge' => $challenge, 'expires_in' => 600]);
}

function api_verify_otp(mysqli $db, array $body): never
{
    $challenge = (string) ($body['challenge'] ?? '');
    $code = trim((string) ($body['code'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{40,100}$/', $challenge) || !preg_match('/^\d{6}$/', $code)) {
        api_error('Enter the six-digit code from your email.');
    }
    $challengeHash = hash('sha256', $challenge);
    $db->begin_transaction();
    try {
        $query = $db->prepare(
            'SELECT c.id AS challenge_id, c.user_id, c.otp_id, o.code_hash, o.attempts
             FROM mobile_login_challenges c
             JOIN login_otps o ON o.id = c.otp_id AND o.user_id = c.user_id
             JOIN users u ON u.id = c.user_id
             WHERE c.challenge_hash = ? AND c.consumed_at IS NULL AND c.expires_at > NOW()
               AND o.consumed_at IS NULL AND o.expires_at > NOW() AND u.account_status = \'Active\'
             LIMIT 1 FOR UPDATE'
        );
        $query->bind_param('s', $challengeHash);
        $query->execute();
        $record = $query->get_result()->fetch_assoc() ?: null;
        $query->close();
        if (!$record || (int) $record['attempts'] >= 5) {
            $db->commit();
            api_error('That code has expired or too many attempts were made. Sign in again.', 401);
        }

        $otpId = (int) $record['otp_id'];
        $attempt = $db->prepare('UPDATE login_otps SET attempts = attempts + 1 WHERE id = ? AND attempts < 5');
        $attempt->bind_param('i', $otpId);
        $attempt->execute();
        $attempt->close();
        if (!hash_equals((string) $record['code_hash'], otp_digest($code))) {
            if ((int) $record['attempts'] + 1 >= 5) {
                $challengeId = (int) $record['challenge_id'];
                $expire = $db->prepare('UPDATE mobile_login_challenges SET consumed_at = NOW() WHERE id = ?');
                $expire->bind_param('i', $challengeId);
                $expire->execute();
                $expire->close();
            }
            $db->commit();
            api_error('The verification code is incorrect. Check your email and try again.', 401);
        }

        $challengeId = (int) $record['challenge_id'];
        $userId = (int) $record['user_id'];
        $consumeChallenge = $db->prepare('UPDATE mobile_login_challenges SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL');
        $consumeChallenge->bind_param('i', $challengeId);
        $consumeChallenge->execute();
        if ($consumeChallenge->affected_rows !== 1) {
            $consumeChallenge->close();
            $db->rollback();
            api_error('That verification code has already been used. Sign in again.', 401);
        }
        $consumeChallenge->close();
        $consumeOtp = $db->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL');
        $consumeOtp->bind_param('i', $otpId);
        $consumeOtp->execute();
        $consumeOtp->close();

        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $rawToken);
        $tokenInsert = $db->prepare('INSERT INTO mobile_api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))');
        $tokenInsert->bind_param('is', $userId, $tokenHash);
        $tokenInsert->execute();
        $tokenInsert->close();
        $loginUpdate = $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $loginUpdate->bind_param('i', $userId);
        $loginUpdate->execute();
        $loginUpdate->close();
        audit($db, $userId, 'login_success', 'user', (string) $userId, 'Mobile OTP verified');
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    $userQuery = $db->prepare('SELECT id, username, display_name, email, school_id, role FROM users WHERE id = ? LIMIT 1');
    $userQuery->bind_param('i', $userId);
    $userQuery->execute();
    $user = $userQuery->get_result()->fetch_assoc();
    $userQuery->close();
    api_response(['token' => $rawToken, 'token_type' => 'Bearer', 'expires_in' => 2592000, 'user' => api_user_data($user)]);
}

try {
    $db = db();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $route = trim((string) ($_GET['route'] ?? ''), '/');
    $body = in_array($method, ['POST', 'PATCH'], true) ? api_body() : [];

    if ($method === 'POST' && $route === 'auth/login') {
        api_login($db, $body);
    }
    if ($method === 'POST' && $route === 'auth/verify') {
        api_verify_otp($db, $body);
    }

    $user = api_user($db);
    $userId = (int) $user['id'];

    if ($method === 'POST' && $route === 'auth/logout') {
        $authorization = api_authorization_header();
        preg_match('/^Bearer ([A-Za-z0-9_-]{40,100})$/', $authorization, $matches);
        $tokenHash = hash('sha256', $matches[1]);
        $revoke = $db->prepare('UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL');
        $revoke->bind_param('s', $tokenHash);
        $revoke->execute();
        $revoke->close();
        api_response(['message' => 'Signed out.']);
    }
    if ($method === 'GET' && $route === 'me') {
        api_response(['user' => api_user_data($user)]);
    }
    if ($method === 'PATCH' && $route === 'profile') {
        $displayName = trim((string) ($body['display_name'] ?? ''));
        $schoolId = trim((string) ($body['school_id'] ?? ''));
        if (strlen($displayName) > 100 || strlen($schoolId) > 50) {
            api_error('Display name must be 100 characters or fewer and school ID must be 50 characters or fewer.');
        }
        $update = $db->prepare('UPDATE users SET display_name = NULLIF(?, \'\'), school_id = NULLIF(?, \'\') WHERE id = ?');
        $update->bind_param('ssi', $displayName, $schoolId, $userId);
        $update->execute();
        $update->close();
        $user['display_name'] = $displayName;
        $user['school_id'] = $schoolId;
        api_response(['user' => api_user_data($user)]);
    }
    if ($method === 'GET' && $route === 'books') {
        $search = trim((string) ($_GET['q'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $limit = min(50, max(1, (int) ($_GET['limit'] ?? 25)));
        if (strlen($search) > 120 || strlen($category) > 80) {
            api_error('Search text is too long.');
        }
        $sql =
            "SELECT b.id, b.title, b.author, b.isbn, b.category, b.description, b.cover_image_url, b.shelf_code,
                    p.name AS publisher, COUNT(c.id) AS physical_copies,
                    COALESCE(SUM(c.status = 'Available'), 0) AS available_copies,
                    COALESCE(SUM(c.status = 'Borrowed'), 0) AS borrowed_copies
             FROM books b
             LEFT JOIN publishers p ON p.id = b.publisher_id
             LEFT JOIN book_copies c ON c.book_id = b.id
             WHERE b.book_status = 'Active'";
        $types = '';
        $params = [];
        if ($search !== '') {
            $sql .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.category LIKE ? OR b.description LIKE ? OR p.name LIKE ? OR b.shelf_code LIKE ?)';
            $wildcard = '%' . $search . '%';
            $types .= 'sssssss';
            array_push($params, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard, $wildcard);
        }
        if ($category !== '') {
            $sql .= ' AND b.category = ?';
            $types .= 's';
            $params[] = $category;
        }
        $sql .= ' GROUP BY b.id, p.name ORDER BY (available_copies > 0) DESC, b.title LIMIT ?';
        $types .= 'i';
        $params[] = $limit;
        $query = $db->prepare($sql);
        $bindings = [$types];
        foreach ($params as $index => $value) {
            $bindings[] = &$params[$index];
        }
        call_user_func_array([$query, 'bind_param'], $bindings);
        $query->execute();
        $books = $query->get_result()->fetch_all(MYSQLI_ASSOC);
        $query->close();
        foreach ($books as &$book) {
            $book['id'] = (int) $book['id'];
            $book['physical_copies'] = (int) $book['physical_copies'];
            $book['available_copies'] = (int) $book['available_copies'];
            $book['borrowed_copies'] = (int) $book['borrowed_copies'];
        }
        unset($book);
        api_response(['books' => $books]);
    }
    if ($method === 'GET' && $route === 'loans') {
        $query = $db->prepare(
            'SELECT l.id, l.issued_at, l.due_at, l.returned_at, l.status, l.renewals, b.title, b.author, c.barcode,
                    DATEDIFF(CURDATE(), l.due_at) AS days_overdue
             FROM loans l JOIN book_copies c ON c.id = l.copy_id JOIN books b ON b.id = c.book_id
             WHERE l.borrower_id = ? ORDER BY l.issued_at DESC LIMIT 100'
        );
        $query->bind_param('i', $userId);
        $query->execute();
        $rows = $query->get_result()->fetch_all(MYSQLI_ASSOC);
        $query->close();
        api_response(['loans' => $rows]);
    }
    if ($method === 'GET' && $route === 'reservations') {
        $query = $db->prepare(
            'SELECT r.id, r.book_id, r.reserved_at, r.status, r.expires_at, b.title, b.author
             FROM reservations r JOIN books b ON b.id = r.book_id
             WHERE r.user_id = ? ORDER BY r.reserved_at DESC LIMIT 100'
        );
        $query->bind_param('i', $userId);
        $query->execute();
        api_response(['reservations' => $query->get_result()->fetch_all(MYSQLI_ASSOC)]);
    }
    if ($method === 'POST' && $route === 'reservations') {
        api_member_access($user);
        $bookId = filter_var($body['book_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$bookId || $bookId < 1) {
            api_error('Choose a valid book to reserve.');
        }
        $db->begin_transaction();
        try {
            $bookQuery = $db->prepare(
                "SELECT b.id, b.title, b.available_copies,
                        (SELECT COUNT(*) FROM book_copies c WHERE c.book_id = b.id AND c.status IN ('Available', 'Borrowed', 'Reserved')) AS copies
                 FROM books b WHERE b.id = ? AND b.book_status = 'Active' FOR UPDATE"
            );
            $bookQuery->bind_param('i', $bookId);
            $bookQuery->execute();
            $book = $bookQuery->get_result()->fetch_assoc();
            $bookQuery->close();
            if (!$book || (int) $book['copies'] === 0) {
                throw new RuntimeException('This title does not have physical copies available for reservation.');
            }
            if ((int) $book['available_copies'] > 0) {
                throw new RuntimeException('This title currently has available copies and cannot be reserved.');
            }
            $duplicate = $db->prepare("SELECT id FROM reservations WHERE book_id = ? AND user_id = ? AND status IN ('Pending', 'Ready') LIMIT 1");
            $duplicate->bind_param('ii', $bookId, $userId);
            $duplicate->execute();
            $alreadyReserved = $duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($alreadyReserved) {
                throw new RuntimeException('You already have an active reservation for this title.');
            }
            $insert = $db->prepare("INSERT INTO reservations (book_id, user_id, status) VALUES (?, ?, 'Pending')");
            $insert->bind_param('ii', $bookId, $userId);
            $insert->execute();
            $reservationId = $db->insert_id;
            $insert->close();
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            if ($exception instanceof RuntimeException) {
                api_error($exception->getMessage(), 409);
            }
            throw $exception;
        }
        api_response(['message' => 'Book reserved.', 'reservation_id' => $reservationId], 201);
    }
    if ($method === 'GET' && $route === 'courses') {
        $query = $db->prepare(
            "SELECT cb.id AS course_book_id, c.course_code, c.name AS course_name, c.description AS course_description,
                    b.id AS book_id, b.title, b.author, b.available_copies
             FROM course_books cb
             JOIN courses c ON c.id = cb.course_id AND c.status = 'Active'
             JOIN books b ON b.id = cb.book_id AND b.book_status = 'Active'
             ORDER BY c.name, b.title LIMIT 200"
        );
        $query->execute();
        api_response(['courses' => $query->get_result()->fetch_all(MYSQLI_ASSOC)]);
    }
    if ($method === 'GET' && $route === 'course-requests') {
        $query = $db->prepare(
            'SELECT r.id, r.course_book_id, r.status, r.request_note, r.review_note, r.requested_at, r.reviewed_at,
                    c.course_code, c.name AS course_name, b.title, b.author
             FROM course_book_requests r
             JOIN course_books cb ON cb.id = r.course_book_id
             JOIN courses c ON c.id = cb.course_id
             JOIN books b ON b.id = cb.book_id
             WHERE r.requested_by = ? ORDER BY r.requested_at DESC LIMIT 100'
        );
        $query->bind_param('i', $userId);
        $query->execute();
        api_response(['requests' => $query->get_result()->fetch_all(MYSQLI_ASSOC)]);
    }
    if ($method === 'POST' && $route === 'course-requests') {
        api_member_access($user);
        $courseBookId = filter_var($body['course_book_id'] ?? null, FILTER_VALIDATE_INT);
        $note = trim((string) ($body['note'] ?? ''));
        if (!$courseBookId || $courseBookId < 1 || strlen($note) > 300) {
            api_error('Choose a valid course book and keep your note to 300 characters or fewer.');
        }
        $db->begin_transaction();
        try {
            $check = $db->prepare(
                "SELECT cb.id, b.title, c.name AS course_name
                 FROM course_books cb
                 JOIN courses c ON c.id = cb.course_id AND c.status = 'Active'
                 JOIN books b ON b.id = cb.book_id AND b.book_status = 'Active'
                 WHERE cb.id = ? FOR UPDATE"
            );
            $check->bind_param('i', $courseBookId);
            $check->execute();
            $courseBook = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$courseBook) {
                throw new RuntimeException('That active course book was not found.');
            }
            $stock = $db->prepare(
                "SELECT COUNT(*) AS available FROM book_copies
                 WHERE book_id = (SELECT book_id FROM course_books WHERE id = ?) AND status = 'Available'"
            );
            $stock->bind_param('i', $courseBookId);
            $stock->execute();
            $available = (int) $stock->get_result()->fetch_assoc()['available'];
            $stock->close();
            if ($available < 1) {
                throw new RuntimeException('This title is not currently available to request.');
            }
            $duplicate = $db->prepare("SELECT id FROM course_book_requests WHERE course_book_id = ? AND requested_by = ? AND status = 'Pending' LIMIT 1");
            $duplicate->bind_param('ii', $courseBookId, $userId);
            $duplicate->execute();
            $pending = $duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($pending) {
                throw new RuntimeException('You already have a pending request for this course book.');
            }
            $insert = $db->prepare("INSERT INTO course_book_requests (course_book_id, requested_by, request_note) VALUES (?, ?, NULLIF(?, ''))");
            $insert->bind_param('iis', $courseBookId, $userId, $note);
            $insert->execute();
            $requestId = $db->insert_id;
            $insert->close();
            $admins = $db->query("SELECT id FROM users WHERE account_status = 'Active' AND role IN ('Administrator', 'Super Administrator')")->fetch_all(MYSQLI_ASSOC);
            foreach ($admins as $admin) {
                notify_user($db, (int) $admin['id'], 'course_book_request', 'New course book request #' . $requestId . ' for "' . $courseBook['title'] . '".', 'admin_dashboard.php');
            }
            audit($db, $userId, 'course_book_requested', 'course_book_request', (string) $requestId, $courseBook['course_name'] . ': ' . $courseBook['title']);
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            if ($exception instanceof RuntimeException) {
                api_error($exception->getMessage(), 409);
            }
            throw $exception;
        }
        api_response(['message' => 'Course book request submitted.', 'request_id' => $requestId], 201);
    }
    if ($method === 'GET' && $route === 'notifications') {
        $query = $db->prepare(
            'SELECT id, type, message, link_url, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100'
        );
        $query->bind_param('i', $userId);
        $query->execute();
        api_response(['notifications' => $query->get_result()->fetch_all(MYSQLI_ASSOC)]);
    }
    if ($method === 'POST' && preg_match('~^notifications/(\d+)/read$~', $route, $matches)) {
        $notificationId = (int) $matches[1];
        $update = $db->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?');
        $update->bind_param('ii', $notificationId, $userId);
        $update->execute();
        $updated = $update->affected_rows;
        $update->close();
        if ($updated === 0) {
            $check = $db->prepare('SELECT id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
            $check->bind_param('ii', $notificationId, $userId);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$exists) {
                api_error('Notification not found.', 404);
            }
        }
        api_response(['message' => 'Notification marked as read.']);
    }

    api_error('API route not found.', 404);
} catch (Throwable $exception) {
    error_log('Mobile API error: ' . (string) $exception);
    api_error('The library service could not complete the request. Try again later.', 500);
}
