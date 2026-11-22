<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$message = '';
$messageType = 'success';
$courses = [];
$catalogueBooks = [];
$courseOptions = [];
$myRequests = [];
$worldClassics = [];
$myAcquisitionRequests = [];
$courseDataLoaded = false;

try {
    $db = db();
    $user = current_user($db);
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    require_permission($user, 'catalogue.view');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_is_valid()) {
            throw new RuntimeException('Your session expired. Refresh and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_course') {
            require_permission($user, 'courses.manage');
            $code = strtoupper(trim((string) ($_POST['course_code'] ?? '')));
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,39}$/', $code)
                || $name === '' || strlen($name) > 140 || strlen($description) > 500) {
                throw new RuntimeException('Enter a valid course code, name, and description.');
            }
            $creatorId = (int) $user['id'];
            $insert = $db->prepare('INSERT INTO courses (course_code, name, description, created_by) VALUES (?, ?, NULLIF(?, \'\'), ?)');
            $insert->bind_param('sssi', $code, $name, $description, $creatorId);
            $insert->execute();
            $insert->close();
            audit($db, $creatorId, 'course_created', 'course', (string) $db->insert_id, $code . ': ' . $name);
            $message = 'Course created.';
        } elseif ($action === 'add_course_book') {
            require_permission($user, 'courses.manage');
            $courseId = (int) ($_POST['course_id'] ?? 0);
            $bookId = (int) ($_POST['book_id'] ?? 0);
            $adderId = (int) $user['id'];
            $courseBook = $db->prepare(
                "INSERT INTO course_books (course_id, book_id, added_by)
                 SELECT c.id, b.id, ? FROM courses c JOIN books b ON b.id = ?
                 WHERE c.id = ? AND c.status = 'Active' AND b.book_status = 'Active'"
            );
            $courseBook->bind_param('iii', $adderId, $bookId, $courseId);
            $courseBook->execute();
            if ($courseBook->affected_rows !== 1) {
                $courseBook->close();
                throw new RuntimeException('Choose an active course and active catalogue book.');
            }
            $associationId = $db->insert_id;
            $courseBook->close();
            audit($db, $adderId, 'course_book_added', 'course_book', (string) $associationId, 'Course ' . $courseId . ', book ' . $bookId);
            $message = 'Book added to the course reading list.';
        } elseif ($action === 'request_book') {
            require_permission($user, 'member.self');
            $courseBookId = (int) ($_POST['course_book_id'] ?? 0);
            $requesterId = (int) $user['id'];
            $db->begin_transaction();
            try {
                $eligible = $db->prepare(
                    "SELECT cb.id, b.title, c.name AS course_name
                     FROM course_books cb
                     JOIN courses c ON c.id = cb.course_id AND c.status = 'Active'
                     JOIN books b ON b.id = cb.book_id AND b.book_status = 'Active'
                     WHERE cb.id = ?
                     FOR UPDATE"
                );
                $eligible->bind_param('i', $courseBookId);
                $eligible->execute();
                $item = $eligible->get_result()->fetch_assoc();
                $eligible->close();
                if (!$item) {
                    throw new RuntimeException('This title is not currently available to request.');
                }
                $stock = $db->prepare("SELECT COUNT(*) AS available FROM book_copies WHERE book_id = (SELECT book_id FROM course_books WHERE id = ?) AND status = 'Available'");
                $stock->bind_param('i', $courseBookId);
                $stock->execute();
                $availableCopies = (int) $stock->get_result()->fetch_assoc()['available'];
                $stock->close();
                if ($availableCopies < 1) {
                    throw new RuntimeException('This title is not currently available to request.');
                }
                $duplicate = $db->prepare("SELECT id FROM course_book_requests WHERE course_book_id = ? AND requested_by = ? AND status = 'Pending' LIMIT 1");
                $duplicate->bind_param('ii', $courseBookId, $requesterId);
                $duplicate->execute();
                $existing = $duplicate->get_result()->fetch_assoc();
                $duplicate->close();
                if ($existing) {
                    throw new RuntimeException('You already have a pending request for this course book.');
                }
                $note = trim((string) ($_POST['request_note'] ?? ''));
                if (strlen($note) > 300) {
                    throw new RuntimeException('Request notes must be 300 characters or fewer.');
                }
                $insert = $db->prepare("INSERT INTO course_book_requests (course_book_id, requested_by, request_note) VALUES (?, ?, NULLIF(?, ''))");
                $insert->bind_param('iis', $courseBookId, $requesterId, $note);
                $insert->execute();
                $requestId = $db->insert_id;
                $insert->close();
                $admins = $db->query("SELECT id FROM users WHERE account_status = 'Active' AND role IN ('Administrator', 'Super Administrator')")->fetch_all(MYSQLI_ASSOC);
                foreach ($admins as $admin) {
                    notify_user($db, (int) $admin['id'], 'course_book_request', 'New course book request #' . $requestId . ' for "' . $item['title'] . '".', 'admin_dashboard.php');
                }
                audit($db, $requesterId, 'course_book_requested', 'course_book_request', (string) $requestId, $item['course_name'] . ': ' . $item['title']);
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
            $message = 'Request submitted to the administrator for review.';
        } elseif ($action === 'request_acquisition') {
            require_permission($user, 'member.self');
            $bookId = (int) ($_POST['book_id'] ?? 0);
            $requesterId = (int) $user['id'];
            $note = trim((string) ($_POST['request_note'] ?? ''));
            if (strlen($note) > 300) {
                throw new RuntimeException('Request notes must be 300 characters or fewer.');
            }
            $db->begin_transaction();
            try {
                $bookQuery = $db->prepare(
                    "SELECT b.title, COUNT(cp.id) AS copies
                     FROM books b
                     LEFT JOIN book_copies cp ON cp.book_id = b.id
                     WHERE b.id = ? AND b.book_status = 'Active' AND b.category = 'World Classics'
                     GROUP BY b.id
                     FOR UPDATE"
                );
                $bookQuery->bind_param('i', $bookId);
                $bookQuery->execute();
                $book = $bookQuery->get_result()->fetch_assoc();
                $bookQuery->close();
                if (!$book || (int) $book['copies'] > 0) {
                    throw new RuntimeException('This title is not currently eligible for an acquisition request.');
                }
                $duplicate = $db->prepare("SELECT id FROM book_acquisition_requests WHERE book_id = ? AND requested_by = ? AND status = 'Pending' LIMIT 1");
                $duplicate->bind_param('ii', $bookId, $requesterId);
                $duplicate->execute();
                $pending = $duplicate->get_result()->fetch_assoc();
                $duplicate->close();
                if ($pending) {
                    throw new RuntimeException('You already have a pending acquisition request for this title.');
                }
                $insert = $db->prepare("INSERT INTO book_acquisition_requests (book_id, requested_by, request_note) VALUES (?, ?, NULLIF(?, ''))");
                $insert->bind_param('iis', $bookId, $requesterId, $note);
                $insert->execute();
                $requestId = $db->insert_id;
                $insert->close();
                $admins = $db->query("SELECT id FROM users WHERE account_status = 'Active' AND role IN ('Administrator', 'Super Administrator')")->fetch_all(MYSQLI_ASSOC);
                foreach ($admins as $admin) {
                    notify_user($db, (int) $admin['id'], 'book_acquisition_request', 'New acquisition suggestion #' . $requestId . ' for "' . $book['title'] . '".', 'admin_dashboard.php');
                }
                audit($db, $requesterId, 'book_acquisition_requested', 'book_acquisition_request', (string) $requestId, $book['title']);
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
            $message = 'Your book acquisition suggestion was sent to the administrator.';
        } elseif ($action === 'cancel_acquisition_request') {
            require_permission($user, 'member.self');
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $requesterId = (int) $user['id'];
            $cancel = $db->prepare("UPDATE book_acquisition_requests SET status = 'Cancelled' WHERE id = ? AND requested_by = ? AND status = 'Pending'");
            $cancel->bind_param('ii', $requestId, $requesterId);
            $cancel->execute();
            $cancelled = $cancel->affected_rows === 1;
            $cancel->close();
            if (!$cancelled) {
                throw new RuntimeException('Only your own pending acquisition requests can be cancelled.');
            }
            audit($db, $requesterId, 'book_acquisition_request_cancelled', 'book_acquisition_request', (string) $requestId, null);
            $message = 'Acquisition request cancelled.';
        } elseif ($action === 'cancel_request') {
            require_permission($user, 'member.self');
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $requesterId = (int) $user['id'];
            $cancel = $db->prepare("UPDATE course_book_requests SET status = 'Cancelled' WHERE id = ? AND requested_by = ? AND status = 'Pending'");
            $cancel->bind_param('ii', $requestId, $requesterId);
            $cancel->execute();
            $cancelled = $cancel->affected_rows === 1;
            $cancel->close();
            if (!$cancelled) {
                throw new RuntimeException('Only your own pending requests can be cancelled.');
            }
            audit($db, $requesterId, 'course_book_request_cancelled', 'course_book_request', (string) $requestId, null);
            $message = 'Request cancelled.';
        } else {
            throw new RuntimeException('Unsupported course library action.');
        }
    }

    $courses = $db->query(
        "SELECT c.id, c.course_code, c.name, c.description,
                cb.id AS course_book_id, b.id AS book_id, b.title, b.author, b.isbn,
                b.description AS book_description,
                (SELECT COUNT(*) FROM book_copies cp WHERE cp.book_id = b.id AND cp.status = 'Available') AS available_copies
         FROM courses c
         LEFT JOIN course_books cb ON cb.course_id = c.id
         LEFT JOIN books b ON b.id = cb.book_id AND b.book_status = 'Active'
         WHERE c.status = 'Active'
         ORDER BY c.name, b.title"
    )->fetch_all(MYSQLI_ASSOC);
    $catalogueBooks = $db->query("SELECT id, title, author FROM books WHERE book_status = 'Active' ORDER BY title")->fetch_all(MYSQLI_ASSOC);
    $courseOptions = $db->query("SELECT id, course_code, name FROM courses WHERE status = 'Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    $requestQuery = $db->prepare(
        'SELECT r.id, r.status, r.requested_at, r.review_note, b.title, c.name AS course_name
         FROM course_book_requests r
         JOIN course_books cb ON cb.id = r.course_book_id
         JOIN books b ON b.id = cb.book_id
         JOIN courses c ON c.id = cb.course_id
         WHERE r.requested_by = ?
         ORDER BY r.requested_at DESC
         LIMIT 30'
    );
    $studentId = (int) $user['id'];
    $requestQuery->bind_param('i', $studentId);
    $requestQuery->execute();
    $myRequests = $requestQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $requestQuery->close();
    $worldClassics = $db->query(
        "SELECT b.id, b.title, b.author, b.publication_year, b.description,
                (SELECT COUNT(*) FROM book_copies cp WHERE cp.book_id = b.id) AS physical_copies,
                (SELECT COUNT(*) FROM book_copies cp WHERE cp.book_id = b.id AND cp.status = 'Available') AS available_copies
         FROM books b
         WHERE b.book_status = 'Active' AND b.category = 'World Classics'
         ORDER BY b.title"
    )->fetch_all(MYSQLI_ASSOC);
    $acquisitionQuery = $db->prepare(
        'SELECT r.id, r.status, r.requested_at, r.review_note, b.title
         FROM book_acquisition_requests r
         JOIN books b ON b.id = r.book_id
         WHERE r.requested_by = ?
         ORDER BY r.requested_at DESC
         LIMIT 30'
    );
    $acquisitionQuery->bind_param('i', $studentId);
    $acquisitionQuery->execute();
    $myAcquisitionRequests = $acquisitionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $acquisitionQuery->close();
    $courseDataLoaded = true;
} catch (Throwable $exception) {
    if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
        $message = 'That course or course-book assignment already exists.';
        $messageType = 'error';
    } elseif ($exception instanceof RuntimeException && isset($user)) {
        $message = $exception->getMessage();
        $messageType = 'error';
    } else {
        error_log((string) $exception);
        http_response_code(503);
        exit('The course library is temporarily unavailable.');
    }
}

if (!$courseDataLoaded && isset($db, $user)) {
    try {
        $courses = $db->query(
            "SELECT c.id, c.course_code, c.name, c.description,
                    cb.id AS course_book_id, b.id AS book_id, b.title, b.author, b.isbn,
                    b.description AS book_description,
                    (SELECT COUNT(*) FROM book_copies cp WHERE cp.book_id = b.id AND cp.status = 'Available') AS available_copies
             FROM courses c
             LEFT JOIN course_books cb ON cb.course_id = c.id
             LEFT JOIN books b ON b.id = cb.book_id AND b.book_status = 'Active'
             WHERE c.status = 'Active'
             ORDER BY c.name, b.title"
        )->fetch_all(MYSQLI_ASSOC);
        $catalogueBooks = $db->query("SELECT id, title, author FROM books WHERE book_status = 'Active' ORDER BY title")->fetch_all(MYSQLI_ASSOC);
        $courseOptions = $db->query("SELECT id, course_code, name FROM courses WHERE status = 'Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        $requestQuery = $db->prepare(
            'SELECT r.id, r.status, r.requested_at, r.review_note, b.title, c.name AS course_name
             FROM course_book_requests r
             JOIN course_books cb ON cb.id = r.course_book_id
             JOIN books b ON b.id = cb.book_id
             JOIN courses c ON c.id = cb.course_id
             WHERE r.requested_by = ?
             ORDER BY r.requested_at DESC
             LIMIT 30'
        );
        $studentId = (int) $user['id'];
        $requestQuery->bind_param('i', $studentId);
        $requestQuery->execute();
        $myRequests = $requestQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $requestQuery->close();
        $worldClassics = $db->query(
            "SELECT b.id, b.title, b.author, b.publication_year, b.description,
                    (SELECT COUNT(*) FROM book_copies cp WHERE cp.book_id = b.id) AS physical_copies,
                    (SELECT COUNT(*) FROM book_copies cp WHERE cp.book_id = b.id AND cp.status = 'Available') AS available_copies
             FROM books b
             WHERE b.book_status = 'Active' AND b.category = 'World Classics'
             ORDER BY b.title"
        )->fetch_all(MYSQLI_ASSOC);
        $acquisitionQuery = $db->prepare(
            'SELECT r.id, r.status, r.requested_at, r.review_note, b.title
             FROM book_acquisition_requests r
             JOIN books b ON b.id = r.book_id
             WHERE r.requested_by = ?
             ORDER BY r.requested_at DESC
             LIMIT 30'
        );
        $acquisitionQuery->bind_param('i', $studentId);
        $acquisitionQuery->execute();
        $myAcquisitionRequests = $acquisitionQuery->get_result()->fetch_all(MYSQLI_ASSOC);
        $acquisitionQuery->close();
    } catch (Throwable $queryException) {
        error_log((string) $queryException);
        http_response_code(503);
        exit('The course library is temporarily unavailable.');
    }
}

function course_library_date(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp ? date('M j, Y', $timestamp) : '—';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Courses and e-library | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="smartlib-page">
<aside class="sl-sidebar">
    <a class="sl-logo" href="course_library.php"><span>SL</span> SmartLib <b>Pro</b></a>
    <p class="sl-role">Course e-library</p>
    <nav aria-label="Course library navigation">
        <a href="<?= $user['role'] === 'Student' ? 'student_dashboard.php' : 'dashboard.php' ?>">My dashboard</a>
        <a class="active" href="course_library.php" aria-current="page">Courses &amp; books</a>
        <?php if (can($user, 'activity.view')): ?><a href="admin_dashboard.php">Admin activity</a><?php endif; ?>
    </nav>
    <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button type="submit">Sign out</button></form>
</aside>
<main class="sl-main">
    <header class="sl-top"><div><p class="eyebrow">Course reading lists</p><h1>Browse courses and books</h1><p class="muted">Request an in-stock course book for approval or suggest a classic the library does not yet hold.</p></div></header>
    <?php if ($message !== ''): ?><div class="alert <?= h($messageType) ?>"><?= h($message) ?></div><?php endif; ?>

    <?php if (can($user, 'courses.manage')): ?>
        <section class="course-admin-tools">
            <article class="sl-card">
                <h2>Create a course</h2>
                <form method="post" class="sl-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create_course">
                    <input name="course_code" maxlength="40" placeholder="Course code (e.g. BIO-101)" required>
                    <input name="name" maxlength="140" placeholder="Course name" required>
                    <input name="description" maxlength="500" placeholder="Short course description">
                    <button type="submit">Add course</button>
                </form>
            </article>
            <article class="sl-card">
                <h2>Add a book to a course</h2>
                <?php if (!$courseOptions || !$catalogueBooks): ?><p class="muted">Create a course and add active catalogue books before assigning reading lists.</p>
                <?php else: ?>
                    <form method="post" class="sl-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_course_book">
                        <select name="course_id" required><option value="">Choose a course</option><?php foreach ($courseOptions as $course): ?><option value="<?= h((string) $course['id']) ?>"><?= h($course['course_code'] . ' · ' . $course['name']) ?></option><?php endforeach; ?></select>
                        <select name="book_id" required><option value="">Choose a book</option><?php foreach ($catalogueBooks as $book): ?><option value="<?= h((string) $book['id']) ?>"><?= h($book['title'] . ' · ' . $book['author']) ?></option><?php endforeach; ?></select>
                        <button type="submit">Add to reading list</button>
                    </form>
                <?php endif; ?>
            </article>
        </section>
    <?php endif; ?>

    <section id="world-classics" class="sl-card world-classics">
        <div class="student-section-heading">
            <div><p class="eyebrow">Reader recommendations</p><h2>World classics</h2></div>
            <span class="muted">Explore the titles and suggest any that are not yet in the library collection.</span>
        </div>
        <?php if (!$worldClassics): ?>
            <p class="muted">The classics collection is being prepared.</p>
        <?php else: ?>
            <div class="course-grid">
                <?php foreach ($worldClassics as $classic): ?>
                    <article class="sl-card course-card">
                        <p class="course-code"><?= h((string) $classic['publication_year']) ?> · World Classic</p>
                        <h3><?= h($classic['title']) ?></h3>
                        <p class="muted">By <?= h($classic['author']) ?></p>
                        <?php if ((int) $classic['physical_copies'] > 0): ?>
                            <p class="course-available"><?= h((string) $classic['available_copies']) ?> available of <?= h((string) $classic['physical_copies']) ?> library copies</p>
                        <?php else: ?>
                            <p class="course-unavailable">Not currently held by the library</p>
                        <?php endif; ?>
                        <details>
                            <summary>Look inside</summary>
                            <p><?= h($classic['description']) ?></p>
                            <?php if (can($user, 'member.self') && (int) $classic['physical_copies'] === 0): ?>
                                <form method="post" class="course-request-form">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="request_acquisition">
                                    <input type="hidden" name="book_id" value="<?= h((string) $classic['id']) ?>">
                                    <input name="request_note" maxlength="300" placeholder="Optional note for the administrator">
                                    <button type="submit">Request library acquisition</button>
                                </form>
                            <?php elseif (can($user, 'member.self') && (int) $classic['available_copies'] > 0): ?>
                                <p class="muted">This title is in stock. Use the catalogue to reserve a copy.</p>
                            <?php endif; ?>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php
    $groupedCourses = [];
    foreach ($courses as $course) {
        $courseKey = (int) $course['id'];
        if (!isset($groupedCourses[$courseKey])) {
            $groupedCourses[$courseKey] = ['course' => $course, 'books' => []];
        }
        if ($course['course_book_id'] !== null && $course['book_id'] !== null) {
            $groupedCourses[$courseKey]['books'][] = $course;
        }
    }
    ?>
    <section class="course-grid" aria-label="Available courses">
        <?php foreach ($groupedCourses as $group): $course = $group['course']; ?>
            <article class="sl-card course-card">
                <p class="course-code"><?= h($course['course_code']) ?></p>
                <h2><?= h($course['name']) ?></h2>
                <?php if ($course['description']): ?><p class="muted"><?= h($course['description']) ?></p><?php endif; ?>
                <?php if (!$group['books']): ?><p class="muted">No books have been added to this reading list yet.</p>
                <?php else: ?>
                    <ul class="course-book-list">
                        <?php foreach ($group['books'] as $book): ?>
                            <li>
                                <div><strong><?= h($book['title']) ?></strong><span><?= h($book['author']) ?><?= $book['isbn'] ? ' · ISBN ' . h($book['isbn']) : '' ?></span>
                                    <?php if ($book['book_description']): ?><small><?= h($book['book_description']) ?></small><?php endif; ?>
                                    <span class="<?= (int) $book['available_copies'] > 0 ? 'course-available' : 'course-unavailable' ?>"><?= (int) $book['available_copies'] > 0 ? h((string) $book['available_copies']) . ' available' : 'Currently unavailable' ?></span>
                                </div>
                                <?php if (can($user, 'member.self') && (int) $book['available_copies'] > 0): ?>
                                    <form method="post" class="course-request-form">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="request_book"><input type="hidden" name="course_book_id" value="<?= h((string) $book['course_book_id']) ?>">
                                        <input name="request_note" maxlength="300" placeholder="Optional note">
                                        <button type="submit">Request approval</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$groupedCourses): ?><article class="sl-card"><p class="muted">No courses are available yet.</p></article><?php endif; ?>
    </section>

    <?php if (can($user, 'member.self')): ?>
        <section class="sl-card">
            <h2>My acquisition suggestions</h2>
            <p class="muted">Administrator approval means the library will consider acquiring a title; it does not mean the book is in stock or checked out to you.</p>
            <div class="table-wrap"><table><thead><tr><th>Book</th><th>Requested</th><th>Status</th><th>Administrator note</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($myAcquisitionRequests as $request): ?>
                <tr><td><?= h($request['title']) ?></td><td><?= h(course_library_date($request['requested_at'])) ?></td><td><?= h($request['status']) ?></td><td><?= h((string) ($request['review_note'] ?? '—')) ?></td><td><?php if ($request['status'] === 'Pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_acquisition_request"><input type="hidden" name="request_id" value="<?= h((string) $request['id']) ?>"><button class="link-button" type="submit">Cancel</button></form><?php else: ?>—<?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$myAcquisitionRequests): ?><tr><td colspan="5">You have not suggested a title for acquisition.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
        <section class="sl-card">
            <h2>My course book requests</h2>
            <div class="table-wrap"><table><thead><tr><th>Course</th><th>Book</th><th>Requested</th><th>Status</th><th>Administrator note</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($myRequests as $request): ?>
                <tr><td><?= h($request['course_name']) ?></td><td><?= h($request['title']) ?></td><td><?= h(course_library_date($request['requested_at'])) ?></td><td><?= h($request['status']) ?></td><td><?= h((string) ($request['review_note'] ?? '—')) ?></td><td><?php if ($request['status'] === 'Pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_request"><input type="hidden" name="request_id" value="<?= h((string) $request['id']) ?>"><button class="link-button" type="submit">Cancel</button></form><?php elseif ($request['status'] === 'Approved'): ?>Visit the library desk to borrow this book.<?php else: ?>—<?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$myRequests): ?><tr><td colspan="6">You have not requested any course books.</td><?php endif; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
