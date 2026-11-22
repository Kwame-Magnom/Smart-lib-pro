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
    require_permission($user, 'circulation.manage');

    $search = trim((string) ($_GET['q'] ?? ''));
    $sql = "SELECT l.id, l.issued_at, l.due_at, l.returned_at, l.status, l.renewals,
                   b.title, c.barcode, u.display_name, u.username
            FROM loans l
            JOIN book_copies c ON c.id = l.copy_id
            JOIN books b ON b.id = c.book_id
            JOIN users u ON u.id = l.borrower_id";
    if ($search !== '') {
        $like = '%' . $search . '%';
        $query = $db->prepare($sql . ' WHERE b.title LIKE ? OR c.barcode LIKE ? OR u.display_name LIKE ? OR u.username LIKE ? ORDER BY l.issued_at DESC LIMIT 200');
        $query->bind_param('ssss', $like, $like, $like, $like);
        $query->execute();
        $history = $query->get_result()->fetch_all(MYSQLI_ASSOC);
        $query->close();
    } else {
        $history = $db->query($sql . ' ORDER BY l.issued_at DESC LIMIT 200')->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    exit('Borrowing history is temporarily unavailable.');
}

function loan_history_member(array $row): string
{
    return trim((string) $row['display_name']) ?: (string) $row['username'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Borrowing history | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="smartlib-page">
<main class="sl-main librarian-history">
    <header class="sl-top">
        <div><p class="eyebrow">Librarian workspace</p><h1>Borrowing and return history</h1></div>
        <a href="librarian_dashboard.php">Back to dashboard</a>
    </header>
    <section class="sl-card">
        <form class="sl-filter" method="get">
            <input name="q" value="<?= h($search) ?>" placeholder="Search book, barcode, or member">
            <button>Search history</button>
        </form>
        <p class="muted">Showing up to 200 most recent loan records.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Book</th><th>Copy</th><th>Member</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th><th>Renewals</th><th>Receipt</th></tr></thead>
                <tbody>
                <?php foreach ($history as $loan): ?>
                    <tr>
                        <td><?= h($loan['title']) ?></td>
                        <td><?= h($loan['barcode']) ?></td>
                        <td><?= h(loan_history_member($loan)) ?></td>
                        <td><?= h(date('M j, Y', strtotime($loan['issued_at']))) ?></td>
                        <td><?= h(date('M j, Y', strtotime($loan['due_at']))) ?></td>
                        <td><?= $loan['returned_at'] ? h(date('M j, Y', strtotime($loan['returned_at']))) : '—' ?></td>
                        <td><?= h($loan['status']) ?></td>
                        <td><?= h((string) $loan['renewals']) ?></td>
                        <td><a href="borrowing_receipt.php?id=<?= h((string) $loan['id']) ?>">Print receipt</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?><tr><td colspan="9">No matching loan records.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
