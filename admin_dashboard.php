<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function admin_dashboard_courses(mysqli $db): array
{
    $courses = $db->query(
        "SELECT c.id, c.course_code, c.name, c.description,
                (SELECT COUNT(*)
                 FROM course_books cb
                 JOIN books b ON b.id = cb.book_id AND b.book_status = 'Active'
                 WHERE cb.course_id = c.id) AS reading_list_count
         FROM courses c
         WHERE c.status = 'Active'
         ORDER BY c.name
         LIMIT 6"
    )->fetch_all(MYSQLI_ASSOC);
    $bookQuery = $db->prepare(
        "SELECT b.title, b.author
         FROM course_books cb
         JOIN books b ON b.id = cb.book_id AND b.book_status = 'Active'
         WHERE cb.course_id = ?
         ORDER BY b.title
         LIMIT 3"
    );
    foreach ($courses as &$course) {
        $courseId = (int) $course['id'];
        $bookQuery->bind_param('i', $courseId);
        $bookQuery->execute();
        $course['reading_list_preview'] = $bookQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    unset($course);
    $bookQuery->close();
    return $courses;
}

$message = flash('admin_dashboard_notice') ?? '';
$messageType = 'success';
$latestBackup = null;
$courses = [];
$isVercelDeployment = getenv('VERCEL') === '1';

try {
    $db = db();
    $user = current_user($db);
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    require_role($user, 'Administrator', 'Super Administrator');
    require_permission($user, 'activity.view');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_is_valid()) {
            throw new RuntimeException('Your session expired. Refresh and try again.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'run_database_backup') {
            require_permission($user, 'settings.manage');
            if ($isVercelDeployment) {
                throw new RuntimeException('Windows backup tasks are unavailable on Vercel. Configure and verify automated backups with your database provider.');
            }
            $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
            $taskScheduler = rtrim($systemRoot, "\\/") . '\\System32\\schtasks.exe';
            if (!function_exists('proc_open') || !is_file($taskScheduler)) {
                throw new RuntimeException('Windows Task Scheduler is unavailable. Confirm the scheduled backup task is installed on this server.');
            }
            $process = proc_open(
                [$taskScheduler, '/Run', '/TN', 'SecurePortalDatabaseBackup'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                __DIR__,
                null,
                ['bypass_shell' => true]
            );
            if (!is_resource($process)) {
                throw new RuntimeException('The backup task could not be started.');
            }
            fclose($pipes[0]);
            $taskOutput = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $taskError = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $taskExitCode = proc_close($process);
            if ($taskExitCode !== 0) {
                throw new RuntimeException(trim($taskError ?: $taskOutput) ?: 'Windows Task Scheduler did not accept the backup request.');
            }
            audit($db, (int) $user['id'], 'database_backup_requested', 'database', null, 'Manual dashboard backup requested');
            flash('admin_dashboard_notice', 'Backup task started. Refresh this page in a minute to confirm the latest backup time.');
            header('Location: admin_dashboard.php#database-backup');
            exit;
        } else {
            require_permission($user, 'course.requests.manage');
            $reviewAction = $action !== '' ? $action : 'review_course_request';
            if (!in_array($reviewAction, ['review_course_request', 'review_acquisition_request'], true)) {
                throw new RuntimeException('Unsupported approval request.');
            } else {
                $requestId = (int) ($_POST['request_id'] ?? 0);
                $decision = (string) ($_POST['decision'] ?? '');
                $reviewNote = trim((string) ($_POST['review_note'] ?? ''));
                if ($requestId < 1 || !in_array($decision, ['Approved', 'Rejected'], true) || strlen($reviewNote) > 300) {
                    throw new RuntimeException('Choose an approval decision and enter a note of 300 characters or fewer.');
                }
                $reviewerId = (int) $user['id'];
                $db->begin_transaction();
                try {
                    if ($reviewAction === 'review_course_request') {
                        $find = $db->prepare(
                            "SELECT r.requested_by, b.title, c.name AS context_name
                             FROM course_book_requests r
                             JOIN course_books cb ON cb.id = r.course_book_id
                             JOIN books b ON b.id = cb.book_id
                             JOIN courses c ON c.id = cb.course_id
                             WHERE r.id = ? AND r.status = 'Pending'
                             FOR UPDATE"
                        );
                        $table = 'course_book_requests';
                        $entity = 'course_book_request';
                        $prefix = 'course_book';
                    } else {
                        $find = $db->prepare(
                            "SELECT r.requested_by, b.title, 'World Classics' AS context_name,
                                    (SELECT COUNT(*) FROM book_copies cp WHERE cp.book_id = b.id) AS physical_copies
                             FROM book_acquisition_requests r
                             JOIN books b ON b.id = r.book_id
                             WHERE r.id = ? AND r.status = 'Pending'
                             FOR UPDATE"
                        );
                        $table = 'book_acquisition_requests';
                        $entity = 'book_acquisition_request';
                        $prefix = 'book_acquisition';
                    }
                    $find->bind_param('i', $requestId);
                    $find->execute();
                    $request = $find->get_result()->fetch_assoc();
                    $find->close();
                    if (!$request) {
                        throw new RuntimeException('This request has already been reviewed or no longer exists.');
                    }
                    if ((int) $request['requested_by'] === $reviewerId) {
                        throw new RuntimeException('You cannot review your own request.');
                    }
                    if ($reviewAction === 'review_acquisition_request' && (int) $request['physical_copies'] > 0) {
                        throw new RuntimeException('This title is now in the library inventory; its acquisition suggestion no longer needs review.');
                    }
                    $update = $db->prepare("UPDATE {$table} SET status = ?, reviewed_by = ?, review_note = NULLIF(?, ''), reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                    $update->bind_param('sisi', $decision, $reviewerId, $reviewNote, $requestId);
                    $update->execute();
                    if ($update->affected_rows !== 1) {
                        $update->close();
                        throw new RuntimeException('Another administrator has already reviewed this request. Refresh the page.');
                    }
                    $update->close();
                    $notice = $prefix === 'book_acquisition'
                        ? 'Your suggestion to acquire "' . $request['title'] . '" was ' . strtolower($decision) . ' by an administrator. Approval is not a purchase or a checkout.'
                        : 'Your request for "' . $request['title'] . '" was ' . strtolower($decision) . ' by an administrator.';
                    if ($reviewNote !== '') {
                        $notice .= ' An administrator left a review note; see your request details.';
                    }
                    notify_user($db, (int) $request['requested_by'], $prefix . '_' . strtolower($decision), $notice, 'course_library.php');
                    audit($db, $reviewerId, $prefix . '_request_' . strtolower($decision), $entity, (string) $requestId, substr($request['context_name'] . ': ' . $request['title'] . ($reviewNote !== '' ? ' — ' . $reviewNote : ''), 0, 500));
                    $db->commit();
                } catch (Throwable $exception) {
                    $db->rollback();
                    throw $exception;
                }
                $message = ($reviewAction === 'review_course_request' ? 'Course book request ' : 'Acquisition suggestion ') . strtolower($decision) . '.';
            }
        }
    }

    $count = static function (mysqli $db, string $query): int {
        return (int) $db->query($query)->fetch_assoc()['total'];
    };
    $stats = [
        'Active accounts' => $count($db, "SELECT COUNT(*) AS total FROM users WHERE account_status = 'Active'"),
        'New accounts · 30 days' => $count($db, 'SELECT COUNT(*) AS total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
        'Actions · 24 hours' => $count($db, 'SELECT COUNT(*) AS total FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'),
        'Pending course requests' => $count($db, "SELECT COUNT(*) AS total FROM course_book_requests WHERE status = 'Pending'"),
        'Pending acquisition suggestions' => $count($db, "SELECT COUNT(*) AS total FROM book_acquisition_requests WHERE status = 'Pending'"),
        'Courses' => $count($db, "SELECT COUNT(*) AS total FROM courses WHERE status = 'Active'"),
    ];
    $activity = $db->query(
        "SELECT a.action, a.entity_type, a.entity_id, a.details, a.created_at,
                u.display_name, u.username, u.role
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.created_at DESC
         LIMIT 50"
    )->fetch_all(MYSQLI_ASSOC);
    $requests = $db->query(
        "SELECT r.id, r.request_note, r.requested_at, b.title, c.name AS course_name,
                u.display_name, u.username, u.role
         FROM course_book_requests r
         JOIN course_books cb ON cb.id = r.course_book_id
         JOIN books b ON b.id = cb.book_id
         JOIN courses c ON c.id = cb.course_id
         JOIN users u ON u.id = r.requested_by
         WHERE r.status = 'Pending'
         ORDER BY r.requested_at ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $courses = admin_dashboard_courses($db);
    $acquisitionRequests = $db->query(
        "SELECT r.id, r.request_note, r.requested_at, b.title,
                u.display_name, u.username, u.role
         FROM book_acquisition_requests r
         JOIN books b ON b.id = r.book_id
         JOIN users u ON u.id = r.requested_by
         WHERE r.status = 'Pending'
         ORDER BY r.requested_at ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $userSearch = trim((string) ($_GET['user_q'] ?? ''));
    $accountsSql = 'SELECT id, username, display_name, email, school_id, role, account_status, created_at, last_login_at, password_hash FROM users';
    if ($userSearch !== '') {
        $like = '%' . $userSearch . '%';
        $accountsQuery = $db->prepare(
            $accountsSql . ' WHERE username LIKE ? OR display_name LIKE ? OR email LIKE ? OR school_id LIKE ? OR role LIKE ? ORDER BY created_at DESC LIMIT 100'
        );
        $accountsQuery->bind_param('sssss', $like, $like, $like, $like, $like);
        $accountsQuery->execute();
        $accounts = $accountsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $accountsQuery->close();
    } else {
        $accounts = $db->query($accountsSql . ' ORDER BY created_at DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);
    }
    $latestBackup = null;
    $backupFiles = glob(rtrim(BACKUP_DIRECTORY, "\\/") . DIRECTORY_SEPARATOR . DB_NAME . '_*.sql') ?: [];
    foreach ($backupFiles as $backupFile) {
        if (is_file($backupFile) && ($latestBackup === null || filemtime($backupFile) > filemtime($latestBackup))) {
            $latestBackup = $backupFile;
        }
    }
} catch (RuntimeException $exception) {
    if (isset($user) && isset($db)) {
        $message = $exception->getMessage();
        $messageType = 'error';
        $count = static function (mysqli $db, string $query): int {
            return (int) $db->query($query)->fetch_assoc()['total'];
        };
        try {
            $stats = [
                'Active accounts' => $count($db, "SELECT COUNT(*) AS total FROM users WHERE account_status = 'Active'"),
                'New accounts · 30 days' => $count($db, 'SELECT COUNT(*) AS total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
                'Actions · 24 hours' => $count($db, 'SELECT COUNT(*) AS total FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'),
                'Pending course requests' => $count($db, "SELECT COUNT(*) AS total FROM course_book_requests WHERE status = 'Pending'"),
                'Pending acquisition suggestions' => $count($db, "SELECT COUNT(*) AS total FROM book_acquisition_requests WHERE status = 'Pending'"),
                'Courses' => $count($db, "SELECT COUNT(*) AS total FROM courses WHERE status = 'Active'"),
            ];
            $activity = $db->query("SELECT a.action, a.entity_type, a.entity_id, a.details, a.created_at, u.display_name, u.username, u.role FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
            $requests = $db->query("SELECT r.id, r.request_note, r.requested_at, b.title, c.name AS course_name, u.display_name, u.username, u.role FROM course_book_requests r JOIN course_books cb ON cb.id = r.course_book_id JOIN books b ON b.id = cb.book_id JOIN courses c ON c.id = cb.course_id JOIN users u ON u.id = r.requested_by WHERE r.status = 'Pending' ORDER BY r.requested_at ASC")->fetch_all(MYSQLI_ASSOC);
            $courses = admin_dashboard_courses($db);
            $acquisitionRequests = $db->query("SELECT r.id, r.request_note, r.requested_at, b.title, u.display_name, u.username, u.role FROM book_acquisition_requests r JOIN books b ON b.id = r.book_id JOIN users u ON u.id = r.requested_by WHERE r.status = 'Pending' ORDER BY r.requested_at ASC")->fetch_all(MYSQLI_ASSOC);
            $userSearch = trim((string) ($_GET['user_q'] ?? ''));
            $accountsSql = 'SELECT id, username, display_name, email, school_id, role, account_status, created_at, last_login_at, password_hash FROM users';
            if ($userSearch !== '') {
                $like = '%' . $userSearch . '%';
                $accountsQuery = $db->prepare($accountsSql . ' WHERE username LIKE ? OR display_name LIKE ? OR email LIKE ? OR school_id LIKE ? OR role LIKE ? ORDER BY created_at DESC LIMIT 100');
                $accountsQuery->bind_param('sssss', $like, $like, $like, $like, $like);
                $accountsQuery->execute();
                $accounts = $accountsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                $accountsQuery->close();
            } else {
                $accounts = $db->query($accountsSql . ' ORDER BY created_at DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);
            }
        } catch (Throwable $queryException) {
            error_log((string) $queryException);
            http_response_code(503);
            exit('The administrator dashboard is temporarily unavailable.');
        }
    } else {
        http_response_code(503);
        exit('The administrator dashboard is temporarily unavailable.');
    }
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    exit('The administrator dashboard is temporarily unavailable.');
}

function admin_dashboard_date(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp ? date('M j, Y g:i a', $timestamp) : '—';
}

function admin_dashboard_label(array $row): string
{
    return trim((string) ($row['display_name'] ?? '')) ?: (string) ($row['username'] ?? 'Unknown user');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Administrator dashboard | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="smartlib-page">
<aside class="sl-sidebar">
    <a class="sl-logo" href="admin_dashboard.php"><span>SL</span> SmartLib <b>Pro</b></a>
    <p class="sl-role">Administrator</p>
    <nav aria-label="Administrator navigation">
        <a class="active" href="admin_dashboard.php" aria-current="page">Activity overview</a>
        <a href="#database-backup">Database backup</a>
        <a href="#courses">Course overview</a>
        <a href="#security-demo">Password security demo</a>
        <a href="#user-accounts">User accounts</a>
        <a href="smartlib.php?section=catalogue">Book catalogue</a>
        <a href="smartlib.php?section=approvals">Exceptional approvals</a>
        <a href="smartlib.php?section=audit">Audit trail</a>
        <a href="smartlib.php?section=settings">Library settings</a>
    </nav>
    <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button type="submit">Sign out</button></form>
</aside>
<main class="sl-main">
    <header class="sl-top"><div><p class="eyebrow">Library administration</p><h1>Administrator dashboard</h1><p class="muted">Monitor member activity and review course book requests.</p></div><div class="sl-user"><span><?= h(strtoupper(substr(admin_dashboard_label($user), 0, 2))) ?></span><div><strong><?= h(admin_dashboard_label($user)) ?></strong><small><?= h($user['role']) ?></small></div></div></header>
    <?php if ($message !== ''): ?><div class="alert <?= h($messageType) ?>"><?= h($message) ?></div><?php endif; ?>
    <section class="sl-stats admin-stats" aria-label="Library administration summary">
        <?php foreach ($stats as $label => $value): ?><article><span><?= h($label) ?></span><strong><?= h((string) $value) ?></strong></article><?php endforeach; ?>
    </section>
    <section id="courses" class="sl-card admin-courses">
        <div class="admin-card-heading">
            <div><h2>Course catalogue</h2><p class="muted">Review active courses and their reading lists at a glance.</p></div>
            <a class="admin-course-action" href="course_library.php">Manage courses &amp; reading lists</a>
        </div>
        <?php if ($courses): ?>
            <div class="admin-course-grid">
                <?php foreach ($courses as $course): ?>
                    <article class="admin-course-item">
                        <div class="admin-course-item-heading">
                            <span class="admin-course-code"><?= h($course['course_code']) ?></span>
                            <span class="admin-course-book-count"><?= h((string) $course['reading_list_count']) ?> <?= (int) $course['reading_list_count'] === 1 ? 'title' : 'titles' ?></span>
                        </div>
                        <h3><?= h($course['name']) ?></h3>
                        <p><?= h((string) ($course['description'] ?: 'No course description added yet.')) ?></p>
                        <?php if ($course['reading_list_preview']): ?>
                            <ul class="admin-course-books">
                                <?php foreach ($course['reading_list_preview'] as $book): ?>
                                    <li><strong><?= h($book['title']) ?></strong><span><?= h((string) ($book['author'] ?: 'Author not listed')) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php $moreBooks = (int) $course['reading_list_count'] - count($course['reading_list_preview']); ?>
                            <?php if ($moreBooks > 0): ?><p class="admin-course-more">+<?= h((string) $moreBooks) ?> more <?= $moreBooks === 1 ? 'title' : 'titles' ?></p><?php endif; ?>
                        <?php else: ?>
                            <p class="admin-course-no-books">No active books assigned yet.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ((int) $stats['Courses'] > count($courses)): ?>
                <p class="admin-course-more">Showing 6 of <?= h((string) $stats['Courses']) ?> active courses.</p>
            <?php endif; ?>
        <?php else: ?>
            <div class="admin-course-empty">
                <strong>No active courses yet</strong>
                <p>Create a course and build its reading list to make titles easier for members to find.</p>
                <a class="admin-course-action" href="course_library.php">Create the first course</a>
            </div>
        <?php endif; ?>
    </section>
    <section id="database-backup" class="sl-card">
        <div class="admin-card-heading"><div><h2>Database backup</h2><p class="muted"><?= $isVercelDeployment ? 'Use your database provider’s backup and restore controls for this deployment.' : 'Create a backup now using the server’s protected Windows Task Scheduler job.' ?></p></div></div>
        <div class="admin-backup-status">
            <?php if ($isVercelDeployment): ?>
                <p>Server-local Windows backups are unavailable on Vercel. Enable automated database backups and verify a restore with your database provider.</p>
            <?php elseif ($latestBackup && is_file($latestBackup)): ?>
                <p><strong>Latest backup:</strong> <?= h(admin_dashboard_date(date('Y-m-d H:i:s', filemtime($latestBackup)))) ?></p>
                <p class="muted"><?= h(basename($latestBackup)) ?> · <?= h(number_format(filesize($latestBackup) / 1048576, 2)) ?> MB</p>
            <?php else: ?>
                <p>No database backup file was found in the configured backup folder.</p>
            <?php endif; ?>
            <?php if (!$isVercelDeployment): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="run_database_backup">
                    <button type="submit">Back up now</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$isVercelDeployment): ?>
            <p class="muted">Backups are stored outside the website folder. After starting a backup, refresh this page in a minute to verify its timestamp. If the task is not installed, an administrator must run <code>install_backup_task.ps1</code> on the server first.</p>
        <?php endif; ?>
    </section>
    <section class="sl-card">
        <div class="admin-card-heading"><div><h2>Course book requests</h2><p class="muted">Approval authorizes the member to visit the library for checkout; it does not issue a book automatically.</p></div><a href="course_library.php">Manage courses &amp; reading lists</a></div>
        <div class="table-wrap"><table><thead><tr><th>Member</th><th>Course</th><th>Book</th><th>Request note</th><th>Requested</th><th>Review</th></tr></thead><tbody>
            <?php foreach ($requests as $request): ?>
                <tr><td><?= h(admin_dashboard_label($request)) ?><small><?= h($request['role']) ?></small></td><td><?= h($request['course_name']) ?></td><td><?= h($request['title']) ?></td><td><?= h((string) ($request['request_note'] ?? '—')) ?></td><td><?= h(admin_dashboard_date($request['requested_at'])) ?></td>
                    <td><form method="post" class="admin-review-form"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="review_course_request"><input type="hidden" name="request_id" value="<?= h((string) $request['id']) ?>"><input name="review_note" maxlength="300" placeholder="Optional review note"><button name="decision" value="Approved" type="submit">Approve</button><button name="decision" value="Rejected" class="admin-reject-button" type="submit">Reject</button></form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$requests): ?><tr><td colspan="6">There are no pending course book requests.</td><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="sl-card">
        <div class="admin-card-heading"><div><h2>World classics acquisition suggestions</h2><p class="muted">Approval records a recommendation for consideration only; it does not purchase a book or create stock.</p></div><a href="course_library.php#world-classics">View the classics collection</a></div>
        <div class="table-wrap"><table><thead><tr><th>Member</th><th>Title</th><th>Member note</th><th>Requested</th><th>Review</th></tr></thead><tbody>
            <?php foreach ($acquisitionRequests as $request): ?>
                <tr><td><?= h(admin_dashboard_label($request)) ?><small><?= h($request['role']) ?></small></td><td><?= h($request['title']) ?></td><td><?= h((string) ($request['request_note'] ?? '—')) ?></td><td><?= h(admin_dashboard_date($request['requested_at'])) ?></td>
                    <td><form method="post" class="admin-review-form"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="review_acquisition_request"><input type="hidden" name="request_id" value="<?= h((string) $request['id']) ?>"><input name="review_note" maxlength="300" placeholder="Optional review note"><button name="decision" value="Approved" type="submit">Approve</button><button name="decision" value="Rejected" class="admin-reject-button" type="submit">Reject</button></form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$acquisitionRequests): ?><tr><td colspan="5">There are no pending acquisition suggestions.</td><?php endif; ?>
        </tbody></table></div>
    </section>
    <section id="security-demo" class="sl-card admin-security-demo">
        <div class="admin-card-heading"><div><h2>Password security demo</h2><p class="muted">A safe, illustrative view of how password hashing uses a salt and an application pepper.</p></div></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Component</th><th>Demo / status</th><th>How it works</th></tr></thead>
            <tbody>
                <tr><td><strong>Example password</strong></td><td><code>example-password</code><small>Illustrative only; not an account credential.</small></td><td>The app never stores or displays a user's plain-text password.</td></tr>
                <tr><td><strong>Pepper</strong></td><td><?= strlen(APP_PEPPER) >= 32 ? 'Configured · value hidden' : 'Not configured' ?></td><td>A server-side secret is used by HMAC-SHA-256 before password hashing. Its actual value is never shown in the dashboard.</td></tr>
                <tr><td><strong>Salt</strong></td><td>Unique and generated automatically</td><td>PHP's <code>password_hash()</code> creates a random salt for each hash and encodes it in the stored hash. See an account's details below.</td></tr>
                <tr><td><strong>Stored result</strong></td><td>Argon2id when available; otherwise bcrypt</td><td>The database stores the one-way password hash, including its algorithm parameters and salt—not the password or pepper.</td></tr>
            </tbody>
        </table></div>
        <p class="admin-security-note">The pepper is intentionally not exposed, even to administrators. This demo shows its configured status only; revealing the secret would weaken the protection it provides.</p>
    </section>
    <section id="user-accounts" class="sl-card">
        <div class="admin-card-heading"><div><h2>User accounts</h2><p class="muted">Review accounts, update roles or sign-in status, and inspect password-protection metadata.</p></div><a href="smartlib.php?section=members">Open full account list</a></div>
        <form class="sl-filter admin-user-search" method="get" action="admin_dashboard.php">
            <input name="user_q" value="<?= h($userSearch) ?>" placeholder="Search name, username, email, school ID, or role">
            <button type="submit">Search users</button>
            <?php if ($userSearch !== ''): ?><a href="admin_dashboard.php#user-accounts">Clear search</a><?php endif; ?>
        </form>
        <div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Created</th><th>Last sign-in</th><th>Password security</th><th>Manage</th></tr></thead><tbody>
            <?php foreach ($accounts as $account): ?>
                <?php $lockedAccount = !can($user, '*') && in_array($account['role'], ['Administrator', 'Super Administrator'], true); ?>
                <?php $securityDetails = password_security_details_from_hash((string) $account['password_hash']); ?>
                <tr>
                    <td><strong><?= h(admin_dashboard_label($account)) ?></strong><small><?= h($account['email']) ?><?= $account['school_id'] ? ' · ' . h($account['school_id']) : '' ?></small></td>
                    <td><?= h($account['role']) ?></td><td><?= h($account['account_status']) ?></td>
                    <td><?= h(admin_dashboard_date($account['created_at'])) ?></td>
                    <td><?= $account['last_login_at'] ? h(admin_dashboard_date($account['last_login_at'])) : 'Never' ?></td>
                    <td><details class="admin-password-details"><summary>View details</summary><dl><div><dt>Hash</dt><dd><?= h($securityDetails['algorithm']) ?> · <?= h($securityDetails['cost']) ?><code><?= h($securityDetails['hash_fingerprint']) ?></code></dd></div><div><dt>Salt</dt><dd><code><?= h($securityDetails['salt']) ?></code></dd></div><div><dt>Pepper</dt><dd><?= h($securityDetails['pepper']) ?><small>The secret is never displayed.</small></dd></div></dl></details></td>
                    <td>
                        <?php if ($lockedAccount): ?>
                            <span class="muted">Super Administrator only</span>
                        <?php else: ?>
                            <form method="post" action="smartlib.php?section=members" class="admin-account-form">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="user_id" value="<?= h((string) $account['id']) ?>">
                                <select name="role" aria-label="Role for <?= h(admin_dashboard_label($account)) ?>">
                                    <?php $accountRoles = ['User', 'Student', 'Teacher', 'Pastor', 'Assistant Librarian', 'Librarian', 'Headmaster', 'Accountant', 'IT Support']; if (can($user, '*')): $accountRoles[] = 'Administrator'; $accountRoles[] = 'Super Administrator'; endif; foreach ($accountRoles as $role): ?>
                                        <option value="<?= h($role) ?>" <?= $account['role'] === $role ? 'selected' : '' ?>><?= h($role) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="account_status" aria-label="Status for <?= h(admin_dashboard_label($account)) ?>">
                                    <?php foreach (['Active', 'Suspended', 'Inactive'] as $status): ?><option value="<?= h($status) ?>" <?= $account['account_status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?>
                                </select>
                                <button type="submit">Save</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$accounts): ?><tr><td colspan="7">No user accounts match this search.</td><?php endif; ?>
        </tbody></table></div>
        <p class="muted">Use Inactive or Suspended to stop sign-in while retaining borrowing and audit history. Accounts are not permanently deleted, preserving linked library records. Passwords and the actual pepper are never displayed; the hash fingerprint is for identification only.</p>
    </section>
    <section class="sl-card">
        <div class="admin-card-heading"><div><h2>Recent user and library activity</h2><p class="muted">Activity is recorded by the application’s audit log. Authentication secrets are not displayed.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody>
            <?php foreach ($activity as $event): ?>
                <tr><td><?= h(admin_dashboard_date($event['created_at'])) ?></td><td><?= h(admin_dashboard_label($event)) ?></td><td><?= h((string) ($event['role'] ?? 'Account removed')) ?></td><td><?= h(str_replace('_', ' ', $event['action'])) ?></td><td><?= h($event['entity_type'] . ($event['entity_id'] !== null ? ' #' . $event['entity_id'] : '')) ?></td><td><?= h((string) ($event['details'] ?? '—')) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$activity): ?><tr><td colspan="6">No activity has been recorded yet.</td><?php endif; ?>
        </tbody></table></div>
    </section>
</main>
</body>
</html>
