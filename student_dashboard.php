<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

try {
    $db = db();
    $user = current_user($db);
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    require_role($user, 'Student', 'User', 'Teacher', 'Pastor');
    require_permission($user, 'dashboard');

    $studentId = (int) $user['id'];
    $passwordSecurity = password_security_details($db, $studentId);
    $countForStudent = static function (mysqli $db, string $sql, int $studentId): int {
        $statement = $db->prepare($sql);
        $statement->bind_param('i', $studentId);
        $statement->execute();
        $count = (int) $statement->get_result()->fetch_assoc()['total'];
        $statement->close();
        return $count;
    };
    $activeLoansCount = $countForStudent($db, "SELECT COUNT(*) AS total FROM loans WHERE borrower_id = ? AND status IN ('Active', 'Overdue')", $studentId);
    $overdueCount = $countForStudent($db, "SELECT COUNT(*) AS total FROM loans WHERE borrower_id = ? AND status IN ('Active', 'Overdue') AND due_at < CURDATE()", $studentId);
    $activeReservationsCount = $countForStudent($db, "SELECT COUNT(*) AS total FROM reservations WHERE user_id = ? AND status IN ('Pending', 'Ready')", $studentId);

    $fineQuery = $db->prepare("SELECT COALESCE(SUM(balance), 0) AS total FROM fines WHERE user_id = ? AND status = 'Outstanding'");
    $fineQuery->bind_param('i', $studentId);
    $fineQuery->execute();
    $outstandingFines = (float) $fineQuery->get_result()->fetch_assoc()['total'];
    $fineQuery->close();

    $loanQuery = $db->prepare(
        "SELECT l.id, l.due_at, l.issued_at, l.renewals, l.status, b.title, c.barcode,
                DATEDIFF(CURDATE(), l.due_at) AS days_overdue
         FROM loans l
         JOIN book_copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         WHERE l.borrower_id = ? AND l.status IN ('Active', 'Overdue')
         ORDER BY l.due_at"
    );
    $loanQuery->bind_param('i', $studentId);
    $loanQuery->execute();
    $loans = $loanQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $loanQuery->close();

    $historyQuery = $db->prepare(
        'SELECT l.issued_at, l.due_at, l.returned_at, l.status, b.title, c.barcode
         FROM loans l
         JOIN book_copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         WHERE l.borrower_id = ?
         ORDER BY l.issued_at DESC
         LIMIT 8'
    );
    $historyQuery->bind_param('i', $studentId);
    $historyQuery->execute();
    $history = $historyQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $historyQuery->close();

    $reservationQuery = $db->prepare(
        "SELECT r.id, r.reserved_at, r.status, r.expires_at, b.title
         FROM reservations r
         JOIN books b ON b.id = r.book_id
         WHERE r.user_id = ? AND r.status IN ('Pending', 'Ready')
         ORDER BY r.reserved_at"
    );
    $reservationQuery->bind_param('i', $studentId);
    $reservationQuery->execute();
    $reservations = $reservationQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $reservationQuery->close();

    $notificationQuery = $db->prepare(
        'SELECT type, message, created_at, read_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $notificationQuery->bind_param('i', $studentId);
    $notificationQuery->execute();
    $notifications = $notificationQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $notificationQuery->close();

    $categories = $db->query('SELECT name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
    $search = trim((string) ($_GET['q'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $availability = (string) ($_GET['availability'] ?? '');
    if (!in_array($availability, ['', 'available', 'borrowed'], true)) {
        $availability = '';
    }
    $bookSql =
        "SELECT b.id, b.title, b.author, b.isbn, b.category, b.description,
                b.cover_image_url, b.shelf_code, p.name AS publisher,
                COUNT(c.id) AS physical_copies,
                COALESCE(SUM(c.status = 'Available'), 0) AS available_copies,
                COALESCE(SUM(c.status = 'Borrowed'), 0) AS borrowed_copies
         FROM books b
         LEFT JOIN publishers p ON p.id = b.publisher_id
         LEFT JOIN book_copies c ON c.book_id = b.id
         WHERE b.book_status = 'Active'";
    $searchLike = '%' . $search . '%';
    $queryParts = [];
    $queryTypes = '';
    $queryParams = [];
    if ($search !== '') {
        $bookSql .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.category LIKE ? OR b.description LIKE ? OR p.name LIKE ? OR b.shelf_code LIKE ?)';
        $queryParts = [$searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike];
        $queryTypes = 'sssssss';
        $queryParams = $queryParts;
    }
    if ($category !== '') {
        $bookSql .= ' AND b.category = ?';
        $queryTypes .= 's';
        $queryParams[] = $category;
    }
    $bookSql .= ' GROUP BY b.id, p.name';
    if ($availability === 'available') {
        $bookSql .= ' HAVING available_copies > 0';
    } elseif ($availability === 'borrowed') {
        $bookSql .= ' HAVING borrowed_copies > 0';
    }
    $bookSql .= ' ORDER BY (available_copies > 0) DESC, b.title LIMIT 12';
    $bookQuery = $db->prepare($bookSql);
    if ($queryTypes !== '') {
        $bindReferences = [$queryTypes];
        foreach ($queryParams as $index => $value) {
            $bindReferences[] = &$queryParams[$index];
        }
        call_user_func_array([$bookQuery, 'bind_param'], $bindReferences);
    }
    $bookQuery->execute();
    $books = $bookQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $bookQuery->close();
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    exit('Your library dashboard is temporarily unavailable.');
}

function student_date(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp ? date('M j, Y', $timestamp) : '—';
}

function student_cover(?string $url): ?string
{
    if (!$url || strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
        return null;
    }
    return $url;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>My library | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="smartlib-page student-page">
<aside class="sl-sidebar">
    <a class="sl-logo" href="student_dashboard.php"><span>SL</span> SmartLib <b>Pro</b></a>
    <p class="sl-role">Member library</p>
    <nav aria-label="Member navigation">
        <a class="active" href="student_dashboard.php" aria-current="page">My dashboard</a>
        <a href="course_library.php">Courses &amp; e-library</a>
        <a href="#book-search">Find a book</a>
        <a href="#my-loans">My loans</a>
        <a href="#my-reservations">My reservations</a>
        <a href="#my-history">Borrowing history</a>
        <a href="#my-profile">My profile</a>
        <a href="smartlib.php?section=fines">My fines</a>
        <a href="smartlib.php?section=notifications">My notifications</a>
    </nav>
    <form method="post" action="logout.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <button type="submit">Sign out</button>
    </form>
</aside>

<main class="sl-main">
    <header class="student-welcome">
        <div>
            <p class="eyebrow">Your school library</p>
            <h1>Hi, <?= h(trim((string) $user['display_name']) ?: $user['username']) ?>!</h1>
            <p>Find your next read, keep track of due dates, and manage your reservations.</p>
        </div>
        <div class="student-header-actions">
            <a class="student-profile-link" href="course_library.php">Courses &amp; book requests</a>
            <a class="student-profile-link" href="#my-profile">View my profile</a>
        </div>
    </header>

    <section class="student-stats" aria-label="Your library summary">
        <a href="#my-loans"><span>Current loans</span><strong><?= h((string) $activeLoansCount) ?></strong></a>
        <a class="<?= $overdueCount ? 'student-stat-alert' : '' ?>" href="#my-loans"><span>Overdue</span><strong><?= h((string) $overdueCount) ?></strong></a>
        <a href="#my-reservations"><span>Reservations</span><strong><?= h((string) $activeReservationsCount) ?></strong></a>
        <a href="smartlib.php?section=fines"><span>Outstanding fines</span><strong><?= h(number_format($outstandingFines, 2)) ?></strong></a>
    </section>

    <section id="book-search" class="sl-card student-search-card">
        <div class="student-section-heading">
            <div><p class="eyebrow">Discover something new</p><h2>Find a book</h2></div>
            <span class="muted">Search by title, author, ISBN, category, or keyword.</span>
        </div>
        <form class="student-search" method="get" action="student_dashboard.php">
            <label class="student-query-label" for="book-query">Book or keyword</label>
            <input id="book-query" name="q" value="<?= h($search) ?>" placeholder="Try a title, author, ISBN, or topic">
            <label for="book-category">Category</label>
            <select id="book-category" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $item): ?>
                    <option value="<?= h($item['name']) ?>" <?= $category === $item['name'] ? 'selected' : '' ?>><?= h($item['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="book-availability">Availability</label>
            <select id="book-availability" name="availability">
                <option value="">Any availability</option>
                <option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>Available now</option>
                <option value="borrowed" <?= $availability === 'borrowed' ? 'selected' : '' ?>>Currently borrowed</option>
            </select>
            <button type="submit">Search books</button>
        </form>
        <div class="student-book-grid">
            <?php foreach ($books as $book): ?>
                <article class="student-book-card">
                    <?php $coverUrl = student_cover($book['cover_image_url']); ?>
                    <?php if ($coverUrl): ?>
                        <img class="student-book-cover" src="<?= h($coverUrl) ?>" alt="Cover of <?= h($book['title']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="student-book-cover student-cover-placeholder" aria-hidden="true"><span><?= h(strtoupper(substr($book['title'], 0, 1))) ?></span></div>
                    <?php endif; ?>
                    <div class="student-book-details">
                        <h3><?= h($book['title']) ?></h3>
                        <p class="student-book-author">By <?= h($book['author']) ?> · <?= h($book['category']) ?></p>
                        <?php if ($book['isbn']): ?><p class="student-book-meta">ISBN <?= h($book['isbn']) ?></p><?php endif; ?>
                        <?php if ($book['description']): ?><p class="student-book-description"><?= h($book['description']) ?></p><?php else: ?><p class="student-book-description muted">No description added yet.</p><?php endif; ?>
                        <p class="student-availability">
                            <?php if ((int) $book['available_copies'] > 0): ?>
                                <span class="student-available"><?= h((string) $book['available_copies']) ?> available</span>
                            <?php else: ?>
                                <span class="student-unavailable">Not available right now</span>
                            <?php endif; ?>
                            <span><?= h((string) $book['borrowed_copies']) ?> borrowed</span>
                        </p>
                        <?php if ((int) $book['available_copies'] + (int) $book['borrowed_copies'] > 0): ?>
                            <form method="post" action="smartlib.php?section=catalogue">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="reserve">
                                <input type="hidden" name="book_id" value="<?= h((string) $book['id']) ?>">
                                <button type="submit" class="student-reserve-button">Reserve this book</button>
                            </form>
                        <?php else: ?>
                            <?php if ($book['category'] === 'World Classics' && (int) $book['physical_copies'] === 0): ?>
                                <a class="student-classics-request" href="course_library.php#world-classics">Suggest this title for library acquisition</a>
                            <?php else: ?>
                                <p class="muted">Ask a librarian about this title.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$books): ?><p class="muted student-no-results">No books found. Try a different title, author, or keyword.</p><?php endif; ?>
        </div>
    </section>

    <section class="student-dashboard-columns">
        <article id="my-loans" class="sl-card">
            <div class="student-section-heading"><h2>My current loans</h2><a href="smartlib.php?section=circulation">Loan details</a></div>
            <?php if (!$loans): ?><p class="muted">No books checked out right now. Browse the catalogue to find your next read.</p><?php else: ?>
                <ul class="student-loan-list">
                    <?php foreach ($loans as $loan): ?>
                        <li class="<?= $loan['due_at'] < date('Y-m-d') ? 'student-loan-overdue' : '' ?>">
                            <div><strong><?= h($loan['title']) ?></strong><span>Due <?= h(student_date($loan['due_at'])) ?> · <?= h($loan['barcode']) ?></span></div>
                            <div class="student-loan-actions">
                                <?php if ($loan['due_at'] < date('Y-m-d')): ?><span class="student-overdue-label"><?= h((string) $loan['days_overdue']) ?> days overdue</span><?php endif; ?>
                                <form method="post" action="smartlib.php?section=circulation">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="renew">
                                    <input type="hidden" name="loan_id" value="<?= h((string) $loan['id']) ?>">
                                    <button type="submit" <?= ((int) $loan['renewals'] >= 1) ? 'disabled' : '' ?>>Request renewal</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article id="my-reservations" class="sl-card">
            <div class="student-section-heading"><h2>My reservations</h2><a href="smartlib.php?section=reservations">All reservations</a></div>
            <?php if (!$reservations): ?><p class="muted">You have no active reservations.</p><?php else: ?>
                <ul class="sl-list">
                    <?php foreach ($reservations as $reservation): ?>
                        <li>
                            <strong><?= h($reservation['title']) ?></strong>
                            <span><?= h($reservation['status']) ?> · requested <?= h(student_date($reservation['reserved_at'])) ?></span>
                            <?php if ($reservation['status'] === 'Ready' && $reservation['expires_at']): ?><small>Collect by <?= h(student_date($reservation['expires_at'])) ?></small><?php endif; ?>
                            <?php if ($reservation['status'] === 'Pending'): ?>
                                <form method="post" action="smartlib.php?section=reservations">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="cancel_reservation">
                                    <input type="hidden" name="reservation_id" value="<?= h((string) $reservation['id']) ?>">
                                    <button type="submit" class="student-cancel-button">Cancel reservation</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </section>

    <section class="student-dashboard-columns">
        <article id="my-history" class="sl-card">
            <div class="student-section-heading"><h2>Borrowing history</h2><a href="student_history.php">Full history</a></div>
            <?php if (!$history): ?><p class="muted">Your borrowing history will appear here.</p><?php else: ?>
                <ul class="sl-list">
                    <?php foreach ($history as $record): ?>
                        <li><strong><?= h($record['title']) ?></strong><span><?= h(student_date($record['issued_at'])) ?> · <?= h($record['status']) ?><?= $record['returned_at'] ? ' · returned ' . h(student_date($record['returned_at'])) : '' ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
        <article class="sl-card">
            <div class="student-section-heading"><h2>Library reminders</h2><a href="smartlib.php?section=notifications">All notifications</a></div>
            <?php if (!$notifications): ?><p class="muted">No reminders right now.</p><?php else: ?>
                <ul class="sl-list">
                    <?php foreach ($notifications as $notification): ?>
                        <li class="<?= $notification['read_at'] ? '' : 'student-alert-unread' ?>"><strong><?= h(ucwords(str_replace('_', ' ', $notification['type']))) ?></strong><span><?= h($notification['message']) ?></span><small><?= h(student_date($notification['created_at'])) ?></small></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </section>

    <section id="my-profile" class="sl-card student-profile-card">
        <div class="student-section-heading"><h2>My profile</h2><span class="muted">Your account, your information.</span></div>
        <form method="post" action="smartlib.php?section=profile" class="student-profile-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">
            <label>Full name<input name="display_name" maxlength="100" value="<?= h((string) $user['display_name']) ?>" required></label>
            <label>Email address<input name="email" type="email" maxlength="254" value="<?= h($user['email']) ?>" required></label>
            <label>School ID<input name="school_id" maxlength="50" value="<?= h((string) $user['school_id']) ?>"></label>
            <label>Role<input value="<?= h($user['role']) ?>" readonly></label>
            <button type="submit">Save my details</button>
        </form>
        <p class="muted">You can update your name, email, and school ID. Your username and library role cannot be changed here.</p>
        <div class="password-security-grid" aria-label="Password protection details">
            <div><span>Hash</span><strong><?= h($passwordSecurity['algorithm']) ?> · <?= h($passwordSecurity['cost']) ?></strong><code><?= h($passwordSecurity['hash_fingerprint']) ?></code></div>
            <div><span>Salt</span><strong><?= h($passwordSecurity['salt']) ?></strong></div>
            <div><span>Pepper</span><strong><?= h($passwordSecurity['pepper']) ?></strong><small>The secret is never displayed.</small></div>
        </div>
    </section>
</main>
</body>
</html>
