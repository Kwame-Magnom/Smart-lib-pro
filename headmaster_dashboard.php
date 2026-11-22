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
    require_role($user, 'Headmaster');
    require_permission($user, 'dashboard');

    $count = static function (string $sql) use ($db): int {
        return (int) $db->query($sql)->fetch_assoc()['total'];
    };
    $stats = [
        'Registered students' => $count("SELECT COUNT(*) AS total FROM users WHERE role = 'Student'"),
        'Registered teachers' => $count("SELECT COUNT(*) AS total FROM users WHERE role = 'Teacher'"),
        'Library staff' => $count("SELECT COUNT(*) AS total FROM users WHERE role IN ('Pastor', 'Librarian', 'Assistant Librarian', 'Accountant', 'IT Support', 'Headmaster', 'Administrator', 'Super Administrator')"),
        'Available books' => $count("SELECT COUNT(*) AS total FROM book_copies WHERE status = 'Available'"),
        'Borrowed books' => $count("SELECT COUNT(*) AS total FROM book_copies WHERE status = 'Borrowed'"),
        'Overdue books' => $count("SELECT COUNT(*) AS total FROM loans WHERE status IN ('Active', 'Overdue') AND due_at < CURDATE()"),
        'Lost books' => $count("SELECT COUNT(*) AS total FROM book_copies WHERE status = 'Lost'"),
    ];
    $stats['Catalogue titles'] = $count("SELECT COUNT(*) AS total FROM books WHERE book_status = 'Active'");

    $financials = $db->query(
        "SELECT COALESCE((SELECT SUM(amount) FROM fines), 0) AS assessed,
                COALESCE((SELECT SUM(amount) FROM payments WHERE status = 'Completed'), 0) AS paid,
                COALESCE((SELECT SUM(balance) FROM fines WHERE status = 'Outstanding'), 0) AS outstanding"
    )->fetch_assoc();

    $trends = $db->query(
        "SELECT DATE_FORMAT(issued_at, '%Y-%m') AS period, COUNT(*) AS total
         FROM loans
         WHERE issued_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
         GROUP BY DATE_FORMAT(issued_at, '%Y-%m')
         ORDER BY period"
    )->fetch_all(MYSQLI_ASSOC);
    $popular = $db->query(
        "SELECT b.title, COUNT(*) AS total
         FROM loans l
         JOIN book_copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         GROUP BY b.id, b.title
         ORDER BY total DESC, b.title
         LIMIT 8"
    )->fetch_all(MYSQLI_ASSOC);
    $memberActivity = $db->query(
        "SELECT l.issued_at, l.due_at, l.returned_at, l.status, b.title,
                u.display_name, u.username, u.role
         FROM loans l
         JOIN book_copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         JOIN users u ON u.id = l.borrower_id
         WHERE u.role IN ('Student', 'Teacher', 'Pastor', 'User')
         ORDER BY l.issued_at DESC
         LIMIT 10"
    )->fetch_all(MYSQLI_ASSOC);
    $overdue = $db->query(
        "SELECT b.title, c.barcode, u.display_name, u.username, u.role,
                l.due_at, DATEDIFF(CURDATE(), l.due_at) AS days_overdue
         FROM loans l
         JOIN book_copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         JOIN users u ON u.id = l.borrower_id
         WHERE l.status IN ('Active', 'Overdue') AND l.due_at < CURDATE()
         ORDER BY l.due_at
         LIMIT 8"
    )->fetch_all(MYSQLI_ASSOC);
    $missing = $db->query(
        "SELECT b.title, c.barcode, c.condition_note
         FROM book_copies c
         JOIN books b ON b.id = c.book_id
         WHERE c.status IN ('Missing', 'Lost')
         ORDER BY c.status, b.title
         LIMIT 8"
    )->fetch_all(MYSQLI_ASSOC);
    $librarianPerformance = $db->query(
        "SELECT u.display_name, u.username,
                SUM(l.issued_by = u.id AND l.issued_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS issued_30_days,
                SUM(l.returned_by = u.id AND l.returned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS returned_30_days
         FROM users u
         LEFT JOIN loans l ON (l.issued_by = u.id OR l.returned_by = u.id)
         WHERE u.role IN ('Librarian', 'Assistant Librarian') AND u.account_status = 'Active'
         GROUP BY u.id, u.display_name, u.username
         ORDER BY issued_30_days DESC, returned_30_days DESC
         LIMIT 10"
    )->fetch_all(MYSQLI_ASSOC);
    $activity = $db->query(
        "SELECT a.action, a.entity_type, a.created_at, u.display_name, u.username
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.entity_type IN ('loan', 'book', 'book_copy', 'reservation', 'fine', 'payment', 'approval_request')
         ORDER BY a.created_at DESC
         LIMIT 8"
    )->fetch_all(MYSQLI_ASSOC);
    $notifications = $db->prepare(
        "SELECT type, message, created_at FROM notifications
         WHERE user_id = ? AND type IN ('overdue', 'overdue_reminder', 'reservation', 'approval_request')
         ORDER BY created_at DESC LIMIT 8"
    );
    $headmasterId = (int) $user['id'];
    $notifications->bind_param('i', $headmasterId);
    $notifications->execute();
    $alerts = $notifications->get_result()->fetch_all(MYSQLI_ASSOC);
    $notifications->close();
    $pendingApprovals = $count("SELECT COUNT(*) AS total FROM approval_requests WHERE status = 'Pending'");
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    exit('The Headmaster dashboard is temporarily unavailable.');
}

function hm_label(array $row): string
{
    return trim((string) ($row['display_name'] ?? '')) ?: (string) ($row['username'] ?? 'Unknown');
}

function hm_date(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp ? date('M j, Y', $timestamp) : '—';
}

$maxTrend = max(1, ...array_map(static function (array $row): int {
    return (int) $row['total'];
}, $trends ?: [['total' => 0]]));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Headmaster dashboard | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="smartlib-page">
<aside class="sl-sidebar">
    <a class="sl-logo" href="headmaster_dashboard.php"><span>SL</span> SmartLib <b>Pro</b></a>
    <p class="sl-role">Headmaster overview</p>
    <nav aria-label="Headmaster navigation">
        <a class="active" href="headmaster_dashboard.php" aria-current="page">Overview</a>
        <a href="course_library.php">Courses &amp; e-library</a>
        <a href="smartlib.php?section=reports">Library reports</a>
        <a href="report_export.php?report=fines">Financial and fine report</a>
        <a href="smartlib.php?section=approvals">Approval requests<?= $pendingApprovals > 0 ? ' (' . h((string) $pendingApprovals) . ')' : '' ?></a>
        <a href="smartlib.php?section=notifications">Notifications</a>
        <a href="smartlib.php?section=profile">My profile</a>
    </nav>
    <form method="post" action="logout.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <button type="submit">Sign out</button>
    </form>
</aside>

<main class="sl-main">
    <header class="sl-top">
        <div>
            <p class="eyebrow">Smart school library</p>
            <h1>Headmaster dashboard</h1>
            <p class="muted">Read-only library oversight, reports, and exceptional-request approvals.</p>
        </div>
        <div class="sl-user"><span>HM</span><div><strong><?= h(hm_label($user)) ?></strong><small>Headmaster</small></div></div>
    </header>

    <section class="sl-stats headmaster-stats" aria-label="Library overview">
        <?php foreach ($stats as $label => $value): ?>
            <article><span><?= h($label) ?></span><strong><?= h((string) $value) ?></strong></article>
        <?php endforeach; ?>
    </section>

    <section class="headmaster-reports">
        <article class="sl-card">
            <div class="headmaster-card-heading"><h2>Borrowing trend</h2><a href="smartlib.php?section=reports">Operational reports</a></div>
            <?php if (!$trends): ?>
                <p class="muted">No borrowing activity recorded yet.</p>
            <?php else: ?>
                <div class="headmaster-trend">
                    <?php foreach ($trends as $trend): ?>
                        <div title="<?= h($trend['period'] . ': ' . $trend['total'] . ' loans') ?>">
                            <span style="height: <?= h((string) max(5, (int) round(((int) $trend['total'] / $maxTrend) * 100))) ?>%"></span>
                            <small><?= h(substr($trend['period'], 5)) ?><br><?= h((string) $trend['total']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
        <article class="sl-card">
            <div class="headmaster-card-heading"><h2>Popular books</h2><a href="report_export.php?report=circulation">Export circulation</a></div>
            <?php if (!$popular): ?><p class="muted">No lending data yet.</p><?php else: ?>
                <ol class="sl-list"><?php foreach ($popular as $book): ?><li><strong><?= h($book['title']) ?></strong><span><?= h((string) $book['total']) ?> loans</span></li><?php endforeach; ?></ol>
            <?php endif; ?>
        </article>
    </section>

    <section class="sl-card">
        <div class="headmaster-card-heading">
            <div><h2>Financial and fine summary</h2><p class="muted">Read-only totals; member payment details are not shown here.</p></div>
            <a href="report_export.php?report=fines">Export fine report</a>
        </div>
        <div class="headmaster-financials">
            <article><span>Fines assessed</span><strong><?= h(number_format((float) $financials['assessed'], 2)) ?></strong></article>
            <article><span>Payments recorded</span><strong><?= h(number_format((float) $financials['paid'], 2)) ?></strong></article>
            <article><span>Outstanding balance</span><strong><?= h(number_format((float) $financials['outstanding'], 2)) ?></strong></article>
        </div>
    </section>

    <section class="headmaster-reports">
        <article class="sl-card">
            <div class="headmaster-card-heading"><h2>Overdue books</h2><a href="report_export.php?report=overdue">Export list</a></div>
            <div class="table-wrap"><table><thead><tr><th>Book</th><th>Member</th><th>Role</th><th>Due</th><th>Days overdue</th></tr></thead><tbody>
                <?php foreach ($overdue as $loan): ?><tr class="overdue"><td><?= h($loan['title']) ?></td><td><?= h(hm_label($loan)) ?></td><td><?= h($loan['role']) ?></td><td><?= h(hm_date($loan['due_at'])) ?></td><td><?= h((string) $loan['days_overdue']) ?></td></tr><?php endforeach; ?>
                <?php if (!$overdue): ?><tr><td colspan="5">No overdue books.</td></tr><?php endif; ?>
            </tbody></table></div>
        </article>
        <article class="sl-card">
            <div class="headmaster-card-heading"><h2>Missing and lost books</h2><a href="report_export.php?report=inventory">Export inventory</a></div>
            <div class="table-wrap"><table><thead><tr><th>Book</th><th>Barcode</th><th>Condition</th></tr></thead><tbody>
                <?php foreach ($missing as $copy): ?><tr><td><?= h($copy['title']) ?></td><td><?= h($copy['barcode']) ?></td><td><?= h($copy['condition_note']) ?></td></tr><?php endforeach; ?>
                <?php if (!$missing): ?><tr><td colspan="3">No missing or lost books.</td></tr><?php endif; ?>
            </tbody></table></div>
        </article>
    </section>

    <section class="sl-card">
        <div class="headmaster-card-heading"><h2>Student and staff library activity</h2><a href="report_export.php?report=activity">Export activity</a></div>
        <div class="table-wrap"><table><thead><tr><th>Member</th><th>Role</th><th>Book</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($memberActivity as $loan): ?><tr><td><?= h(hm_label($loan)) ?></td><td><?= h($loan['role']) ?></td><td><?= h($loan['title']) ?></td><td><?= h(hm_date($loan['issued_at'])) ?></td><td><?= h(hm_date($loan['due_at'])) ?></td><td><?= $loan['returned_at'] ? h(hm_date($loan['returned_at'])) : '—' ?></td><td><?= h($loan['status']) ?></td></tr><?php endforeach; ?>
            <?php if (!$memberActivity): ?><tr><td colspan="7">No member activity recorded.</td><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="headmaster-reports">
        <article class="sl-card">
            <div class="headmaster-card-heading"><h2>Librarian activity · last 30 days</h2><a href="report_export.php?report=performance">Export performance report</a></div>
            <div class="table-wrap"><table><thead><tr><th>Librarian</th><th>Loans issued</th><th>Returns recorded</th></tr></thead><tbody>
                <?php foreach ($librarianPerformance as $librarian): ?><tr><td><?= h(hm_label($librarian)) ?></td><td><?= h((string) $librarian['issued_30_days']) ?></td><td><?= h((string) $librarian['returned_30_days']) ?></td></tr><?php endforeach; ?>
                <?php if (!$librarianPerformance): ?><tr><td colspan="3">No librarian activity recorded.</td></tr><?php endif; ?>
            </tbody></table></div>
        </article>
        <article class="sl-card">
            <div class="headmaster-card-heading"><h2>Important alerts</h2><a href="smartlib.php?section=notifications">All alerts</a></div>
            <ul class="sl-list">
                <?php if ($pendingApprovals > 0): ?><li><strong><?= h((string) $pendingApprovals) ?> approval requests pending</strong><span><a href="smartlib.php?section=approvals">Review requests</a></span></li><?php endif; ?>
                <?php foreach ($alerts as $alert): ?><li><strong><?= h(ucwords(str_replace('_', ' ', $alert['type']))) ?></strong><span><?= h($alert['message']) ?></span><small><?= h(hm_date($alert['created_at'])) ?></small></li><?php endforeach; ?>
                <?php if (!$alerts && $pendingApprovals === 0): ?><li><span>No recent alerts.</span></li><?php endif; ?>
            </ul>
        </article>
    </section>

    <section class="sl-card">
        <div class="headmaster-card-heading"><h2>Library operational activity</h2><a href="report_export.php?report=activity">Export activity</a></div>
        <div class="table-wrap"><table><thead><tr><th>When</th><th>Staff member</th><th>Action</th><th>Record type</th></tr></thead><tbody>
            <?php foreach ($activity as $event): ?><tr><td><?= h(hm_date($event['created_at'])) ?></td><td><?= h(hm_label($event)) ?></td><td><?= h(ucwords(str_replace('_', ' ', $event['action']))) ?></td><td><?= h($event['entity_type']) ?></td></tr><?php endforeach; ?>
            <?php if (!$activity): ?><tr><td colspan="4">No recent library activity.</td><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="sl-card">
        <h2>Generate a report</h2>
        <p class="muted">Choose a reporting period. For a term report, enter the term start and end dates.</p>
        <form class="headmaster-export-form" method="get" action="report_export.php">
            <label>Report<select name="report"><option value="circulation">Circulation</option><option value="activity">Member activity</option><option value="fines">Fines and finance</option><option value="overdue">Overdue loans</option><option value="performance">Librarian performance</option><option value="inventory">Inventory</option></select></label>
            <label>Period<select name="period"><option value="month">Monthly</option><option value="term">Termly</option><option value="year">Annual</option></select></label>
            <label>Month<input type="month" name="month" value="<?= h(date('Y-m')) ?>"></label>
            <label>Year<input type="number" name="year" min="2000" max="<?= h((string) ((int) date('Y') + 1)) ?>" value="<?= h(date('Y')) ?>"></label>
            <label>Term starts<input type="date" name="from"></label>
            <label>Term ends<input type="date" name="to"></label>
            <label>Format<select name="format"><option value="csv">Excel-compatible CSV</option><option value="pdf">PDF</option></select></label>
            <button type="submit">Generate report</button>
        </form>
    </section>

    <p class="headmaster-readonly-note">Operational data is read-only. You can review and decide pending exceptional requests, but cannot issue or return books, change member accounts, or edit library policy from this dashboard. Authentication credentials and reset tokens are not accessible here.</p>
</main>
</body>
</html>
