<?php
declare(strict_types=1);

// Deliberately CLI-only. It creates clearly labelled data for local testing.
if (PHP_SAPI !== 'cli' || getenv('SMARTLIB_SEED_DEMO') !== '1') {
    exit("Run only from the command line with SMARTLIB_SEED_DEMO=1.\n");
}

require_once __DIR__ . '/auth.php';

$db = db();
$password = password_hash_secure('SmartLibDemo!2026');
$accounts = [
    ['demo.superadmin', 'Demo Super Administrator', 'demo.superadmin@example.test', 'Super Administrator'],
    ['demo.librarian', 'Demo Librarian', 'demo.librarian@example.test', 'Librarian'],
    ['demo.headmaster', 'Demo Headmaster', 'demo.headmaster@example.test', 'Headmaster'],
    ['demo.teacher', 'Demo Teacher', 'demo.teacher@example.test', 'Teacher'],
    ['demo.student', 'Demo Student', 'demo.student@example.test', 'Student'],
    ['demo.accountant', 'Demo Accountant', 'demo.accountant@example.test', 'Accountant'],
];
foreach ($accounts as [$username, $displayName, $email, $role]) {
    $schoolId = 'DEMO-' . strtoupper(substr(str_replace('demo.', '', $username), 0, 8));
    $statement = $db->prepare("INSERT IGNORE INTO users (username, display_name, email, school_id, password_hash, role, account_status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
    $statement->bind_param('ssssss', $username, $displayName, $email, $schoolId, $password, $role);
    $statement->execute();
    $statement->close();
}

$books = [
    ['Demonstration: Introduction to Library Science', 'SmartLib Demo Author', '9780000000001', 'Education', 3],
    ['Demonstration: Applied Mathematics', 'SmartLib Demo Author', '9780000000002', 'Mathematics', 2],
    ['Demonstration: African Literature', 'SmartLib Demo Author', '9780000000003', 'Literature', 2],
];
foreach ($books as [$title, $author, $isbn, $category, $copies]) {
    $exists = $db->prepare('SELECT id FROM books WHERE isbn = ?');
    $exists->bind_param('s', $isbn);
    $exists->execute();
    $found = $exists->get_result()->fetch_assoc();
    $exists->close();
    if ($found) {
        continue;
    }
    $db->begin_transaction();
    try {
        $categoryInsert = $db->prepare('INSERT IGNORE INTO categories (name) VALUES (?)');
        $categoryInsert->bind_param('s', $category);
        $categoryInsert->execute();
        $categoryInsert->close();
        $authorInsert = $db->prepare('INSERT IGNORE INTO authors (name) VALUES (?)');
        $authorInsert->bind_param('s', $author);
        $authorInsert->execute();
        $authorInsert->close();
        $book = $db->prepare('INSERT INTO books (title, author, isbn, category, total_copies, available_copies) VALUES (?, ?, ?, ?, ?, ?)');
        $book->bind_param('ssssii', $title, $author, $isbn, $category, $copies, $copies);
        $book->execute();
        $bookId = $db->insert_id;
        $book->close();
        $copy = $db->prepare("INSERT INTO book_copies (book_id, barcode, status) VALUES (?, ?, 'Available')");
        for ($number = 1; $number <= $copies; $number++) {
            $barcode = 'DEMO-' . $bookId . '-' . $number;
            $copy->bind_param('is', $bookId, $barcode);
            $copy->execute();
        }
        $copy->close();
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }
}

echo "Demo accounts and catalogue items have been seeded. All demo accounts use SmartLibDemo!2026 and email OTP must be deliverable before login.\n";
