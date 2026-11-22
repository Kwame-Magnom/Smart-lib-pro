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
    require_permission($user, 'member.self');

    $studentId = (int) $user['id'];
    $statement = $db->prepare(
        'SELECT l.issued_at, l.due_at, l.returned_at, l.status, l.renewals,
                b.title, b.author, c.barcode
         FROM loans l
         JOIN book_copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         WHERE l.borrower_id = ?
         ORDER BY l.issued_at DESC'
    );
    $statement->bind_param('i', $studentId);
    $statement->execute();
    $history = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    exit('Your library borrowing history is temporarily unavailable.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>My borrowing history | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="smartlib-page student-page">
<main class="sl-main student-history-page">
    <header class="student-welcome">
        <div><p class="eyebrow">Your school library</p><h1>My borrowing history</h1><p>Only your own borrowing and return records are shown here.</p></div>
        <a class="student-profile-link" href="student_dashboard.php">Back to my dashboard</a>
    </header>
    <section class="sl-card">
        <div class="table-wrap"><table><thead><tr><th>Book</th><th>Author</th><th>Copy</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th><th>Renewals</th></tr></thead><tbody>
            <?php foreach ($history as $loan): ?>
                <tr class="<?= $loan['status'] === 'Overdue' ? 'overdue' : '' ?>">
                    <td><?= h($loan['title']) ?></td><td><?= h($loan['author']) ?></td><td><?= h($loan['barcode']) ?></td>
                    <td><?= h(date('M j, Y', strtotime($loan['issued_at']))) ?></td>
                    <td><?= h(date('M j, Y', strtotime($loan['due_at']))) ?></td>
                    <td><?= $loan['returned_at'] ? h(date('M j, Y', strtotime($loan['returned_at']))) : '—' ?></td>
                    <td><?= h($loan['status']) ?></td><td><?= h((string) $loan['renewals']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="8">You have no borrowing history yet.</td><?php endif; ?>
        </tbody></table></div>
    </section>
</main>
</body>
</html>
