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
    require_role($user, 'Librarian');
    require_permission($user, 'dashboard');

    $count = static function (string $sql) use ($db): int {
        return (int) $db->query($sql)->fetch_assoc()['total'];
    };
    $stats = [
        'Catalogue titles' => $count("SELECT COUNT(*) AS total FROM books WHERE book_status = 'Active'"),
        'Available copies' => $count("SELECT COUNT(*) AS total FROM book_copies WHERE status = 'Available'"),
        'Active loans' => $count("SELECT COUNT(*) AS total FROM loans WHERE status IN ('Active', 'Overdue')"),
        'Overdue loans' => $count("SELECT COUNT(*) AS total FROM loans WHERE due_at < CURDATE() AND status IN ('Active', 'Overdue')"),
        'Active members' => $count("SELECT COUNT(*) AS total FROM users WHERE account_status = 'Active' AND role IN ('User', 'Student', 'Teacher', 'Pastor')"),
        'Waiting reservations' => $count("SELECT COUNT(*) AS total FROM reservations WHERE status = 'Pending'"),
        'Damaged, missing, or lost' => $count("SELECT COUNT(*) AS total FROM book_copies WHERE status IN ('Damaged', 'Missing', 'Lost')"),
    ];
    $fineBalance = (float) $db->query("SELECT COALESCE(SUM(balance), 0) AS total FROM fines WHERE status = 'Outstanding'")->fetch_assoc()['total'];

    $overdue = $db->query(
        "SELECT l.id, l.due_at, b.title, c.barcode, u.display_name, u.username,
                DATEDIFF(CURDATE(), l.due_at) AS days_overdue
         FROM loans l
         JOIN book_copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         JOIN users u ON u.id = l.borrower_id
         WHERE l.due_at < CURDATE() AND l.status IN ('Active', 'Overdue')
         ORDER BY l.due_at ASC
         LIMIT 8"
    )->fetch_all(MYSQLI_ASSOC);
    $recentLoans = $db->query(
        "SELECT l.id, l.issued_at, l.due_at, l.status, b.title, c.barcode,
                u.display_name, u.username
         FROM loans l
         JOIN book_copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         JOIN users u ON u.id = l.borrower_id
         ORDER BY l.issued_at DESC
         LIMIT 6"
    )->fetch_all(MYSQLI_ASSOC);
    $reservations = $db->query(
        "SELECT r.reserved_at, r.status, b.title, u.display_name, u.username
         FROM reservations r
         JOIN books b ON b.id = r.book_id
         JOIN users u ON u.id = r.user_id
         WHERE r.status IN ('Pending', 'Ready')
         ORDER BY r.reserved_at ASC
         LIMIT 6"
    )->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    exit('The librarian dashboard is temporarily unavailable.');
}

function librarian_label(array $row): string
{
    return trim((string) ($row['display_name'] ?? '')) ?: (string) $row['username'];
}

function librarian_date(string $date): string
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
    <title>Librarian dashboard | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="smartlib-page">
<aside class="sl-sidebar">
    <a class="sl-logo" href="librarian_dashboard.php"><span>SL</span> SmartLib <b>Pro</b></a>
    <p class="sl-role">Librarian workspace</p>
    <nav aria-label="Librarian navigation">
        <a class="active" href="librarian_dashboard.php" aria-current="page">Overview</a>
        <a href="smartlib.php?section=catalogue">Catalogue</a>
        <a href="course_library.php">Courses &amp; e-library</a>
        <a href="copies.php">Physical copies</a>
        <a href="smartlib.php?section=circulation">Loans &amp; returns</a>
        <a href="smartlib.php?section=reservations">Reservations</a>
        <a href="smartlib.php?section=fines">Fines &amp; payments</a>
        <a href="smartlib.php?section=members">Members</a>
        <a href="member_register.php">Register member</a>
        <a href="smartlib.php?section=reports">Circulation reports</a>
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
            <h1>Librarian dashboard</h1>
            <p class="muted">Welcome, <?= h(librarian_label($user)) ?>. Your library work, all in one place.</p>
        </div>
        <div class="sl-user">
            <span><?= h(strtoupper(substr(librarian_label($user), 0, 2))) ?></span>
            <div><strong><?= h(librarian_label($user)) ?></strong><small>Librarian</small></div>
        </div>
    </header>

    <section class="sl-stats librarian-stats" aria-label="Library summary">
        <?php foreach (['Catalogue titles', 'Available copies', 'Active loans', 'Overdue loans', 'Active members', 'Waiting reservations', 'Damaged, missing, or lost'] as $label): ?>
            <article>
                <span><?= h($label) ?></span>
                <strong><?= h((string) $stats[$label]) ?></strong>
            </article>
        <?php endforeach; ?>
        <article><span>Outstanding fines</span><strong><?= h(number_format($fineBalance, 2)) ?></strong></article>
    </section>

    <section class="librarian-actions" aria-label="Library actions">
        <a href="smartlib.php?section=catalogue"><strong>Find or add a book</strong><span>Search the catalogue, edit a title, or add books and copies.</span></a>
        <a href="copies.php"><strong>Manage copy condition</strong><span>Update availability and record damaged, missing, or lost copies.</span></a>
        <a href="smartlib.php?section=circulation"><strong>Issue, return, or renew</strong><span>Manage active loans and eligible renewals.</span></a>
        <a href="loan_history.php"><strong>Borrowing and return history</strong><span>Search prior loans and print borrowing receipts.</span></a>
        <a href="smartlib.php?section=reservations"><strong>Manage reservations</strong><span>Review the waiting list and ready-to-collect holds.</span></a>
        <a href="smartlib.php?section=fines"><strong>Fines and payments</strong><span>Review balances and record member payments.</span></a>
        <a href="member_register.php"><strong>Register a member</strong><span>Create a member account without granting staff privileges.</span></a>
        <a href="smartlib.php?section=members"><strong>Search and manage members</strong><span>Find member records and update member account details.</span></a>
        <a href="smartlib.php?section=reports"><strong>Circulation reports</strong><span>Review borrowing trends and export circulation data.</span></a>
    </section>

    <section class="sl-card">
        <h2>Request administrative approval</h2>
        <p class="muted">For exceptions to normal library policies, send the active administrators a reasoned request. Do not complete the transaction until it is authorized.</p>
        <form method="post" action="smartlib.php?section=notifications" class="sl-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="request_approval">
            <select name="transaction_type" required>
                <option value="">Choose exception type</option>
                <option>Fine adjustment</option>
                <option>Loan limit exception</option>
                <option>Other exceptional transaction</option>
            </select>
            <input name="approval_reason" maxlength="450" placeholder="Reason and related record details" required>
            <button type="submit">Send approval request</button>
        </form>
    </section>

    <section class="sl-grid librarian-panels">
        <article class="sl-card">
            <div class="librarian-card-heading">
                <h2>Overdue loans</h2>
                <a href="report_export.php?report=overdue">Export overdue list</a>
            </div>
            <?php if (!$overdue): ?>
                <p class="muted">There are no overdue loans to follow up.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Book</th><th>Member</th><th>Due</th><th>Late</th><th>Follow up</th></tr></thead>
                        <tbody>
                        <?php foreach ($overdue as $loan): ?>
                            <tr class="overdue">
                                <td><strong><?= h($loan['title']) ?></strong><small><?= h($loan['barcode']) ?></small></td>
                                <td><?= h(librarian_label($loan)) ?></td>
                                <td><?= h(librarian_date($loan['due_at'])) ?></td>
                                <td><?= h((string) $loan['days_overdue']) ?> days</td>
                                <td>
                                    <form method="post" action="smartlib.php?section=circulation" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="send_reminder">
                                        <input type="hidden" name="loan_id" value="<?= h((string) $loan['id']) ?>">
                                        <button type="submit">Send reminder</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <a class="librarian-more" href="smartlib.php?section=circulation">View active loans</a>
            <?php endif; ?>
        </article>

        <article class="sl-card">
            <div class="librarian-card-heading">
                <h2>Reservation queue</h2>
                <a href="smartlib.php?section=reservations">Manage queue</a>
            </div>
            <?php if (!$reservations): ?>
                <p class="muted">No active reservations.</p>
            <?php else: ?>
                <ul class="sl-list">
                    <?php foreach ($reservations as $reservation): ?>
                        <li>
                            <strong><?= h($reservation['title']) ?></strong>
                            <span><?= h(librarian_label($reservation)) ?> · <?= h($reservation['status']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </section>

    <section class="sl-card">
        <div class="librarian-card-heading">
            <h2>Recent borrowing activity</h2>
            <a href="loan_history.php">View loan history</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Book</th><th>Member</th><th>Issued</th><th>Due</th><th>Status</th><th>Receipt</th></tr></thead>
                <tbody>
                <?php foreach ($recentLoans as $loan): ?>
                    <tr>
                        <td><strong><?= h($loan['title']) ?></strong><small><?= h($loan['barcode']) ?></small></td>
                        <td><?= h(librarian_label($loan)) ?></td>
                        <td><?= h(librarian_date($loan['issued_at'])) ?></td>
                        <td><?= h(librarian_date($loan['due_at'])) ?></td>
                        <td><span class="sl-badge"><?= h($loan['status']) ?></span></td>
                        <td><a href="borrowing_receipt.php?id=<?= h((string) $loan['id']) ?>">Print receipt</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentLoans): ?><tr><td colspan="6">No borrowing activity recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="librarian-security-note">Administrative roles and critical library security settings are restricted to authorized administrators.</p>
</main>
</body>
</html>
