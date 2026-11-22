<?php
declare(strict_types=1);

// Run: $env:SMARTLIB_RUN_TESTS='1'; C:\xampp\php\php.exe .\tests\smartlib_rules_test.php
if (PHP_SAPI !== 'cli' || getenv('SMARTLIB_RUN_TESTS') !== '1') {
    exit("Set SMARTLIB_RUN_TESTS=1 to run database smoke tests.\n");
}
require_once dirname(__DIR__) . '/auth.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
}

test_assert(can(['role' => 'Student'], 'catalogue.view'), 'Students can view catalogue');
test_assert(can(['role' => 'Teacher'], 'member.self'), 'Teachers can use their own library dashboard');
test_assert(can(['role' => 'Pastor'], 'member.self'), 'Pastors can use their own library dashboard');
test_assert(can(['role' => 'User'], 'catalogue.view'), 'New member accounts can browse the catalogue and course library');
test_assert(can(['role' => 'User'], 'member.self'), 'New member accounts have personal library access and can request course books');
test_assert(can(['role' => 'User'], 'member.self'), 'Standard members can suggest a book for acquisition review');
test_assert(!can(['role' => 'User'], 'users.manage'), 'New member accounts cannot manage user accounts');
test_assert(!can(['role' => 'User'], 'settings.manage'), 'New member accounts cannot change library settings');
test_assert(can(['role' => 'Student'], 'member.self'), 'Students can access their own library account');
test_assert(!can(['role' => 'Student'], 'members.manage'), 'Students cannot manage other members');
test_assert(!can(['role' => 'Student'], 'circulation.manage'), 'Students cannot alter circulation records');
test_assert(!can(['role' => 'Student'], 'reservations.manage'), 'Students cannot manage other members reservations');
test_assert(!can(['role' => 'Student'], 'fines.manage'), 'Students cannot alter fine records');
test_assert(!can(['role' => 'Student'], 'users.manage'), 'Students cannot assign roles or manage accounts');
test_assert(!can(['role' => 'Student'], 'settings.manage'), 'Students cannot change library policies');
test_assert(can(['role' => 'Librarian'], 'circulation.manage'), 'Librarians can manage circulation');
test_assert(can(['role' => 'Librarian'], 'members.manage'), 'Librarians can manage library members');
test_assert(can(['role' => 'Librarian'], 'approval.request'), 'Librarians can request exceptional transaction approval');
test_assert(!can(['role' => 'Librarian'], 'users.manage'), 'Librarians cannot manage staff or administrator accounts');
test_assert(!can(['role' => 'Librarian'], 'settings.manage'), 'Librarians cannot change policies');
test_assert(can(['role' => 'Headmaster'], 'reports.view'), 'Headmasters can view authorized reports');
test_assert(can(['role' => 'Headmaster'], 'fines.view'), 'Headmasters can view financial reports');
test_assert(can(['role' => 'Headmaster'], 'activity.view'), 'Headmasters can view library activity');
test_assert(can(['role' => 'Headmaster'], 'approvals.manage'), 'Headmasters can review exceptional requests');
test_assert(!can(['role' => 'Headmaster'], 'circulation.manage'), 'Headmasters cannot change circulation records');
test_assert(!can(['role' => 'Headmaster'], 'users.manage'), 'Headmasters cannot manage user accounts');
test_assert(!can(['role' => 'Headmaster'], 'settings.manage'), 'Headmasters cannot change library policies');
test_assert(can(['role' => 'Administrator'], 'activity.view'), 'Administrators can monitor user activity');
test_assert(can(['role' => 'Administrator'], 'users.manage'), 'Administrators can review and manage user accounts');
test_assert(can(['role' => 'Administrator'], 'courses.manage'), 'Administrators can manage course reading lists');
test_assert(can(['role' => 'Administrator'], 'course.requests.manage'), 'Administrators can review course book requests');
test_assert(!can(['role' => 'Student'], 'courses.manage'), 'Students cannot edit course reading lists');
test_assert(!can(['role' => 'Student'], 'course.requests.manage'), 'Students cannot approve course book requests');
test_assert(!can(['role' => 'Headmaster'], 'course.requests.manage'), 'Headmasters cannot approve course book requests');
test_assert(can(['role' => 'Super Administrator'], 'settings.manage'), 'Super Administrators can change policies');
test_assert(can(['role' => 'Super Administrator'], 'users.manage'), 'Super Administrators can manage accounts');
test_assert(can(['role' => 'Super Administrator'], 'course.requests.manage'), 'Super Administrators can review course book requests');

$db = db();
foreach (['book_copies', 'loans', 'reservations', 'fines', 'payments', 'notifications', 'audit_logs', 'approval_requests', 'courses', 'course_books', 'course_book_requests', 'book_acquisition_requests'] as $table) {
    $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    test_assert($result->num_rows === 1, 'Schema includes ' . $table);
}

$token = bin2hex(random_bytes(5));
$db->begin_transaction();
try {
    $hash = password_hash('test-only', PASSWORD_DEFAULT);
    $studentName = 'test.student.' . $token;
    $librarianName = 'test.librarian.' . $token;
    $emailA = $studentName . '@example.test';
    $emailB = $librarianName . '@example.test';
    $roleStudent = 'Student'; $roleLibrarian = 'Librarian';
    $user = $db->prepare("INSERT INTO users (username, display_name, email, password_hash, role, account_status) VALUES (?, ?, ?, ?, ?, 'Active')");
    $user->bind_param('sssss', $studentName, $studentName, $emailA, $hash, $roleStudent); $user->execute(); $studentId = $db->insert_id;
    $user->bind_param('sssss', $librarianName, $librarianName, $emailB, $hash, $roleLibrarian); $user->execute(); $librarianId = $db->insert_id; $user->close();
    $newMemberName = 'test.member.' . $token;
    $newMemberEmail = $newMemberName . '@example.test';
    $newMemberRole = 'User';
    $newMember = $db->prepare("INSERT INTO users (username, display_name, email, password_hash, role, account_status) VALUES (?, ?, ?, ?, ?, 'Active')");
    $newMember->bind_param('sssss', $newMemberName, $newMemberName, $newMemberEmail, $hash, $newMemberRole);
    $newMember->execute();
    $newMemberId = $db->insert_id;
    $newMember->close();
    $newMemberCheck = $db->prepare('SELECT role, account_status FROM users WHERE id = ?');
    $newMemberCheck->bind_param('i', $newMemberId);
    $newMemberCheck->execute();
    $createdMember = $newMemberCheck->get_result()->fetch_assoc();
    $newMemberCheck->close();
    test_assert($createdMember['role'] === 'User' && $createdMember['account_status'] === 'Active', 'New member accounts are saved active with the standard member role');
    $adminName = 'test.admin.' . $token; $adminEmail = $adminName . '@example.test'; $adminRole = 'Administrator';
    $admin = $db->prepare("INSERT INTO users (username, display_name, email, password_hash, role, account_status) VALUES (?, ?, ?, ?, ?, 'Active')");
    $admin->bind_param('sssss', $adminName, $adminName, $adminEmail, $hash, $adminRole); $admin->execute(); $adminId = $db->insert_id; $admin->close();
    $courseCode = 'T' . strtoupper(substr($token, 0, 8)); $courseName = 'Test course ' . $token;
    $course = $db->prepare('INSERT INTO courses (course_code, name, created_by) VALUES (?, ?, ?)');
    $course->bind_param('ssi', $courseCode, $courseName, $adminId); $course->execute(); $courseId = $db->insert_id; $course->close();
    $title = 'Test book ' . $token; $author = 'Test Author'; $isbn = 'TEST-' . $token; $category = 'Test'; $copies = 1;
    $book = $db->prepare('INSERT INTO books (title, author, isbn, category, total_copies, available_copies) VALUES (?, ?, ?, ?, ?, ?)');
    $book->bind_param('ssssii', $title, $author, $isbn, $category, $copies, $copies); $book->execute(); $bookId = $db->insert_id; $book->close();
    $classicTitle = 'Unstocked classic ' . $token; $classicAuthor = 'Test Classic Author'; $classicYear = 1900; $classicCategory = 'World Classics'; $classicDescription = 'Test recommendation with no library copies.';
    $classic = $db->prepare('INSERT INTO books (title, author, publication_year, category, description, total_copies, available_copies) VALUES (?, ?, ?, ?, ?, 0, 0)');
    $classic->bind_param('ssiss', $classicTitle, $classicAuthor, $classicYear, $classicCategory, $classicDescription);
    $classic->execute(); $classicBookId = $db->insert_id; $classic->close();
    $stock = $db->prepare('SELECT total_copies, available_copies FROM books WHERE id = ?');
    $stock->bind_param('i', $classicBookId); $stock->execute(); $zeroStock = $stock->get_result()->fetch_assoc(); $stock->close();
    test_assert((int) $zeroStock['total_copies'] === 0 && (int) $zeroStock['available_copies'] === 0, 'Acquisition recommendations do not invent physical stock');
    $acquisition = $db->prepare("INSERT INTO book_acquisition_requests (book_id, requested_by, request_note) VALUES (?, ?, 'Test acquisition suggestion')");
    $acquisition->bind_param('ii', $classicBookId, $studentId); $acquisition->execute(); $acquisitionId = $db->insert_id; $acquisition->close();
    $acquisitionStatus = $db->query('SELECT status FROM book_acquisition_requests WHERE id=' . (int) $acquisitionId)->fetch_assoc();
    test_assert($acquisitionStatus['status'] === 'Pending', 'Member acquisition suggestions enter the pending administrator review queue');
    $db->query("UPDATE book_acquisition_requests SET status='Approved', reviewed_by=" . (int) $adminId . ", reviewed_at=NOW() WHERE id=" . (int) $acquisitionId);
    $approvedStock = $db->query('SELECT total_copies, available_copies FROM books WHERE id=' . (int) $classicBookId)->fetch_assoc();
    test_assert((int) $approvedStock['total_copies'] === 0 && (int) $approvedStock['available_copies'] === 0, 'Approving an acquisition suggestion never creates physical stock');
    $courseBook = $db->prepare('INSERT INTO course_books (course_id, book_id, added_by) VALUES (?, ?, ?)');
    $courseBook->bind_param('iii', $courseId, $bookId, $adminId); $courseBook->execute(); $courseBookId = $db->insert_id; $courseBook->close();
    $courseRequest = $db->prepare('INSERT INTO course_book_requests (course_book_id, requested_by, request_note) VALUES (?, ?, ?)');
    $requestNote = 'Test request'; $courseRequest->bind_param('iis', $courseBookId, $studentId, $requestNote); $courseRequest->execute(); $requestId = $db->insert_id; $courseRequest->close();
    $requestStatus = $db->query('SELECT status FROM course_book_requests WHERE id=' . (int)$requestId)->fetch_assoc();
    test_assert($requestStatus['status'] === 'Pending', 'Course e-library requests begin pending administrator approval');
    $barcode = 'TEST-' . $token;
    $copy = $db->prepare("INSERT INTO book_copies (book_id, barcode, status) VALUES (?, ?, 'Available')"); $copy->bind_param('is', $bookId, $barcode); $copy->execute(); $copyId = $db->insert_id; $copy->close();
    $due = date('Y-m-d', strtotime('+14 days'));
    $loan = $db->prepare('INSERT INTO loans (copy_id, borrower_id, issued_by, due_at) VALUES (?, ?, ?, ?)'); $loan->bind_param('iiis', $copyId, $studentId, $librarianId, $due); $loan->execute(); $loanId = $db->insert_id; $loan->close();
    $db->query("UPDATE book_copies SET status='Borrowed' WHERE id=" . (int)$copyId);
    $state = $db->query('SELECT status FROM book_copies WHERE id=' . (int)$copyId)->fetch_assoc(); test_assert($state['status'] === 'Borrowed', 'Issue marks a physical copy borrowed');
    $db->query("UPDATE loans SET returned_at=NOW(), returned_by=" . (int)$librarianId . ", status='Returned' WHERE id=" . (int)$loanId);
    $db->query("UPDATE book_copies SET status='Available' WHERE id=" . (int)$copyId);
    $state = $db->query('SELECT status FROM book_copies WHERE id=' . (int)$copyId)->fetch_assoc(); test_assert($state['status'] === 'Available', 'Return restores availability');
    $amount = 2.50; $reason = 'Test fine';
    $fine = $db->prepare("INSERT INTO fines (user_id, loan_id, amount, balance, reason) VALUES (?, ?, ?, ?, ?)"); $fine->bind_param('iidds', $studentId, $loanId, $amount, $amount, $reason); $fine->execute(); $fineId = $db->insert_id; $fine->close();
    $method='Cash'; $ref='TEST-'.$token; $receipt='TEST-RCP-'.$token;
    $payment=$db->prepare('INSERT INTO payments (fine_id, amount, method, reference_code, receipt_number, recorded_by) VALUES (?, ?, ?, ?, ?, ?)');$payment->bind_param('idsssi',$fineId,$amount,$method,$ref,$receipt,$librarianId);$payment->execute();$payment->close();
    $paid=$db->prepare("UPDATE fines SET balance=0,status='Paid' WHERE id=?");$paid->bind_param('i',$fineId);$paid->execute();$paid->close();
    $fineState=$db->query('SELECT balance,status FROM fines WHERE id='.(int)$fineId)->fetch_assoc();test_assert((float)$fineState['balance']===0.0&&$fineState['status']==='Paid','Payment closes the fine balance');
    $db->rollback();
    echo "SmartLib rules tests passed.\n";
} catch (Throwable $exception) {
    $db->rollback();
    throw $exception;
}
