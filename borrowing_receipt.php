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

    $loanId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$loanId || $loanId < 1) {
        http_response_code(400);
        exit('A valid loan is required.');
    }
    $statement = $db->prepare(
        'SELECT l.id, l.issued_at, l.due_at, l.returned_at, l.status,
                c.barcode, b.title, b.author, u.display_name, u.username, u.school_id,
                issuer.display_name AS issuer_name, issuer.username AS issuer_username
         FROM loans l
         JOIN book_copies c ON c.id = l.copy_id
         JOIN books b ON b.id = c.book_id
         JOIN users u ON u.id = l.borrower_id
         JOIN users issuer ON issuer.id = l.issued_by
         WHERE l.id = ?'
    );
    $statement->bind_param('i', $loanId);
    $statement->execute();
    $loan = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$loan) {
        http_response_code(404);
        exit('Loan not found.');
    }
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    exit('The borrowing receipt is temporarily unavailable.');
}

$memberName = trim((string) $loan['display_name']) ?: (string) $loan['username'];
$issuerName = trim((string) $loan['issuer_name']) ?: (string) $loan['issuer_username'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Borrowing receipt | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .receipt { max-width: 680px; margin: 48px auto; padding: 36px; border: 1px solid #dce4f0; border-radius: 12px; background: #fff; }
        .receipt h1 { margin: 0; color: #172e59; }
        .receipt-meta { margin: 8px 0 28px; color: #76839a; }
        .receipt dl { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 0; }
        .receipt dl div { padding: 14px; border-radius: 8px; background: #f7f9fc; }
        .receipt dt { color: #76839a; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        .receipt dd { margin: 7px 0 0; color: #253854; font-weight: 650; overflow-wrap: anywhere; }
        .receipt-actions { display: flex; gap: 12px; margin-top: 24px; }
        .receipt-actions a,.receipt-actions button { padding: 11px 16px; border: 0; border-radius: 7px; color: #fff; background: #2458aa; font: inherit; font-weight: 700; cursor: pointer; }
        .receipt-actions a { color: #2458aa; background: #eaf1ff; }
        @media print { .receipt { max-width: none; margin: 0; border: 0; } .receipt-actions { display: none; } }
        @media (max-width: 520px) { .receipt { margin: 16px; padding: 22px; } .receipt dl { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="smartlib-page">
<main class="receipt">
    <p class="eyebrow">SmartLib Pro · Borrowing record</p>
    <h1>Borrowing receipt</h1>
    <p class="receipt-meta">Receipt for loan #<?= h((string) $loan['id']) ?></p>
    <dl>
        <div><dt>Book</dt><dd><?= h($loan['title']) ?></dd></div>
        <div><dt>Author</dt><dd><?= h($loan['author']) ?></dd></div>
        <div><dt>Barcode</dt><dd><?= h($loan['barcode']) ?></dd></div>
        <div><dt>Member</dt><dd><?= h($memberName) ?></dd></div>
        <div><dt>Member ID</dt><dd><?= h((string) ($loan['school_id'] ?: $loan['username'])) ?></dd></div>
        <div><dt>Issued by</dt><dd><?= h($issuerName) ?></dd></div>
        <div><dt>Borrowed</dt><dd><?= h(date('M j, Y g:i A', strtotime($loan['issued_at']))) ?></dd></div>
        <div><dt>Due date</dt><dd><?= h(date('M j, Y', strtotime($loan['due_at']))) ?></dd></div>
        <div><dt>Loan status</dt><dd><?= h($loan['status']) ?></dd></div>
        <?php if ($loan['returned_at']): ?><div><dt>Returned</dt><dd><?= h(date('M j, Y g:i A', strtotime($loan['returned_at']))) ?></dd></div><?php endif; ?>
    </dl>
    <div class="receipt-actions">
        <button type="button" onclick="window.print()">Print receipt</button>
        <a href="librarian_dashboard.php">Back to dashboard</a>
    </div>
</main>
</body>
</html>
