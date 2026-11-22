<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function sl_value(mysqli $db, string $key, string $fallback): string
{
    $statement = $db->prepare('SELECT setting_value FROM library_settings WHERE setting_key = ?');
    $statement->bind_param('s', $key);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $row ? (string) $row['setting_value'] : $fallback;
}
function sl_member_group(string $role): string { return $role === 'Teacher' ? 'teacher' : (in_array($role, ['Student', 'User'], true) ? 'student' : 'staff'); }
function sl_user_label(array $row): string { return trim((string) ($row['display_name'] ?? '')) ?: (string) $row['username']; }
function sl_date(string $date): string { $t = strtotime($date); return $t ? date('M j, Y', $t) : '—'; }
function sl_role_home(string $role): string { return in_array($role, ['Student', 'Teacher', 'Pastor', 'User'], true) ? 'My library' : $role . ' dashboard'; }

try {
    $db = db();
    $user = current_user($db);
    if (!$user) { header('Location: login.php'); exit; }
    $passwordSecurity = password_security_details($db, (int) $user['id']);
    $section = (string) ($_GET['section'] ?? 'dashboard');
    if ($user['role'] === 'Librarian' && $section === 'dashboard') { header('Location: librarian_dashboard.php'); exit; }
    if ($user['role'] === 'Headmaster' && $section === 'dashboard') { header('Location: headmaster_dashboard.php'); exit; }
    if (in_array($user['role'], ['Student', 'User', 'Teacher', 'Pastor'], true) && $section === 'dashboard') { header('Location: student_dashboard.php'); exit; }
    if (in_array($user['role'], ['Administrator', 'Super Administrator'], true) && $section === 'dashboard') { header('Location: admin_dashboard.php'); exit; }
    $allowed = ['dashboard', 'catalogue', 'circulation', 'reservations', 'fines', 'members', 'reports', 'settings', 'notifications', 'audit', 'approvals', 'profile'];
    if (!in_array($section, $allowed, true)) { $section = 'dashboard'; }
    $flash = '';
    $flashType = 'success';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_is_valid()) { throw new RuntimeException('Your session expired. Refresh the page and try again.'); }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add_book') {
            require_permission($user, 'catalogue.manage');
            $title = trim((string) ($_POST['title'] ?? ''));
            $author = trim((string) ($_POST['author'] ?? ''));
            $isbn = trim((string) ($_POST['isbn'] ?? ''));
            $coverImageUrl = trim((string) ($_POST['cover_image_url'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? '')) ?: 'General';
            $publisher = trim((string) ($_POST['publisher'] ?? ''));
            $year = (int) ($_POST['publication_year'] ?? 0);
            $shelf = trim((string) ($_POST['shelf_code'] ?? ''));
            $copies = (int) ($_POST['copies'] ?? 0);
            if ($title === '' || $author === '' || $copies < 1 || strlen($title) > 180 || strlen($author) > 140 || strlen($coverImageUrl) > 500 || ($coverImageUrl !== '' && (!filter_var($coverImageUrl, FILTER_VALIDATE_URL) || parse_url($coverImageUrl, PHP_URL_SCHEME) !== 'https'))) { throw new RuntimeException('Provide a title, author, at least one physical copy, and a valid HTTPS cover image URL if supplied.'); }
            $db->begin_transaction();
            try {
                $cat = $db->prepare('INSERT IGNORE INTO categories (name) VALUES (?)'); $cat->bind_param('s', $category); $cat->execute(); $cat->close();
                $auth = $db->prepare('INSERT IGNORE INTO authors (name) VALUES (?)'); $auth->bind_param('s', $author); $auth->execute(); $auth->close();
                $publisherId = null;
                if ($publisher !== '') { $p = $db->prepare('INSERT IGNORE INTO publishers (name) VALUES (?)'); $p->bind_param('s', $publisher); $p->execute(); $p->close(); $p = $db->prepare('SELECT id FROM publishers WHERE name = ?'); $p->bind_param('s', $publisher); $p->execute(); $publisherId = (int) $p->get_result()->fetch_assoc()['id']; $p->close(); }
                $isbnValue = $isbn !== '' ? $isbn : null;
                $coverImageValue = $coverImageUrl !== '' ? $coverImageUrl : null;
                $book = $db->prepare('INSERT INTO books (title, author, isbn, publisher_id, publication_year, category, cover_image_url, shelf_code, total_copies, available_copies) VALUES (?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?)');
                $book->bind_param('sssiisssii', $title, $author, $isbnValue, $publisherId, $year, $category, $coverImageValue, $shelf, $copies, $copies);
                $book->execute(); $bookId = $db->insert_id; $book->close();
                $copy = $db->prepare("INSERT INTO book_copies (book_id, barcode, status) VALUES (?, ?, 'Available')");
                for ($n = 1; $n <= $copies; $n++) { $barcode = 'SLP-' . $bookId . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT); $copy->bind_param('is', $bookId, $barcode); $copy->execute(); }
                $copy->close(); audit($db, (int) $user['id'], 'book_created', 'book', (string) $bookId, $title); $db->commit();
                $flash = 'Book and its physical copies were added to the catalogue.';
            } catch (Throwable $e) { $db->rollback(); throw $e; }
        } elseif ($action === 'issue') {
            require_permission($user, 'circulation.manage');
            $copyId = (int) ($_POST['copy_id'] ?? 0); $borrowerId = (int) ($_POST['borrower_id'] ?? 0);
            $db->begin_transaction();
            try {
                $member = $db->prepare('SELECT id, role, account_status FROM users WHERE id = ? FOR UPDATE'); $member->bind_param('i', $borrowerId); $member->execute(); $borrower = $member->get_result()->fetch_assoc(); $member->close();
                $copy = $db->prepare("SELECT c.id, c.book_id, b.title FROM book_copies c JOIN books b ON b.id=c.book_id WHERE c.id = ? AND c.status = 'Available' FOR UPDATE"); $copy->bind_param('i', $copyId); $copy->execute(); $copyRow = $copy->get_result()->fetch_assoc(); $copy->close();
                if (!$borrower || $borrower['account_status'] !== 'Active' || !$copyRow) { throw new RuntimeException('The member is inactive or this copy is no longer available.'); }
                $group = sl_member_group((string) $borrower['role']); $limit = (int) sl_value($db, $group . '_loan_limit', '3'); $days = (int) sl_value($db, $group . '_loan_days', '14');
                $count = $db->prepare("SELECT COUNT(*) AS total FROM loans WHERE borrower_id = ? AND status IN ('Active', 'Overdue')"); $count->bind_param('i', $borrowerId); $count->execute(); $active = (int) $count->get_result()->fetch_assoc()['total']; $count->close();
                if ($active >= $limit) { throw new RuntimeException('This member has reached their borrowing limit (' . $limit . ').'); }
                $issuer = (int) $user['id']; $dueAt = date('Y-m-d', strtotime('+' . $days . ' days'));
                $loan = $db->prepare("INSERT INTO loans (copy_id, borrower_id, issued_by, due_at) VALUES (?, ?, ?, ?)"); $loan->bind_param('iiis', $copyId, $borrowerId, $issuer, $dueAt); $loan->execute(); $loanId = $db->insert_id; $loan->close();
                $status = $db->prepare("UPDATE book_copies SET status = 'Borrowed' WHERE id = ?"); $status->bind_param('i', $copyId); $status->execute(); $status->close();
                $available = $db->prepare('UPDATE books SET available_copies = GREATEST(available_copies - 1, 0) WHERE id = ?'); $available->bind_param('i', $copyRow['book_id']); $available->execute(); $available->close();
                notify_user($db, $borrowerId, 'loan', 'Borrowed: ' . $copyRow['title'] . '. Due ' . sl_date($dueAt) . '.', 'smartlib.php?section=circulation'); audit($db, $issuer, 'loan_issued', 'loan', (string) $loanId, 'Copy ' . $copyId); $db->commit(); $flash = 'Loan issued. Due date: ' . sl_date($dueAt) . '.';
            } catch (Throwable $e) { $db->rollback(); throw $e; }
        } elseif ($action === 'send_reminder') {
            require_permission($user, 'notifications.manage');
            $loanId = (int) ($_POST['loan_id'] ?? 0);
            $q = $db->prepare("SELECT l.borrower_id, b.title FROM loans l JOIN book_copies c ON c.id = l.copy_id JOIN books b ON b.id = c.book_id WHERE l.id = ? AND l.status IN ('Active', 'Overdue') AND l.due_at < CURDATE()");
            $q->bind_param('i', $loanId);
            $q->execute();
            $loan = $q->get_result()->fetch_assoc();
            $q->close();
            if (!$loan) {
                throw new RuntimeException('That loan is no longer overdue.');
            }
            $borrowerId = (int) $loan['borrower_id'];
            notify_user($db, $borrowerId, 'overdue_reminder', 'Reminder: "' . $loan['title'] . '" is overdue. Please return it or contact the library.', 'smartlib.php?section=circulation');
            audit($db, (int) $user['id'], 'overdue_reminder_sent', 'loan', (string) $loanId, $loan['title']);
            $flash = 'Overdue reminder sent to the member.';
        } elseif ($action === 'request_approval') {
            require_permission($user, 'approval.request');
            $transactionType = trim((string) ($_POST['transaction_type'] ?? ''));
            $reason = trim((string) ($_POST['approval_reason'] ?? ''));
            if (!in_array($transactionType, ['Fine adjustment', 'Loan limit exception', 'Other exceptional transaction'], true)
                || $reason === '' || strlen($reason) > 450) {
                throw new RuntimeException('Choose an exception type and provide a reason of 450 characters or fewer.');
            }
            $requesterId = (int) $user['id'];
            $db->begin_transaction();
            try {
                $insert = $db->prepare("INSERT INTO approval_requests (requested_by, request_type, reason) VALUES (?, ?, ?)");
                $insert->bind_param('iss', $requesterId, $transactionType, $reason);
                $insert->execute();
                $requestId = $db->insert_id;
                $insert->close();
                $requester = sl_user_label($user);
                $message = 'Approval request #' . $requestId . ' from ' . $requester . ' (' . $transactionType . '): ' . $reason;
                $approvers = $db->query("SELECT id FROM users WHERE account_status = 'Active' AND role IN ('Headmaster', 'Administrator', 'Super Administrator')")->fetch_all(MYSQLI_ASSOC);
                foreach ($approvers as $approver) {
                    notify_user($db, (int) $approver['id'], 'approval_request', $message, 'smartlib.php?section=approvals');
                }
                audit($db, $requesterId, 'approval_requested', 'approval_request', (string) $requestId, $transactionType . ': ' . $reason);
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
            $flash = 'Approval request submitted. Do not process the exception until it is authorized.';
        } elseif ($action === 'review_approval') {
            require_permission($user, 'approvals.manage');
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $decision = (string) ($_POST['decision'] ?? '');
            $reviewNote = trim((string) ($_POST['review_note'] ?? ''));
            if ($requestId < 1 || !in_array($decision, ['Approved', 'Rejected'], true) || strlen($reviewNote) > 450) {
                throw new RuntimeException('Choose an approval decision and a review note of 450 characters or fewer.');
            }
            $reviewerId = (int) $user['id'];
            $db->begin_transaction();
            try {
                $requestQuery = $db->prepare("SELECT id, requested_by, request_type FROM approval_requests WHERE id = ? AND status = 'Pending' FOR UPDATE");
                $requestQuery->bind_param('i', $requestId);
                $requestQuery->execute();
                $approvalRequest = $requestQuery->get_result()->fetch_assoc();
                $requestQuery->close();
                if (!$approvalRequest) {
                    throw new RuntimeException('That request has already been reviewed or does not exist.');
                }
                if ((int) $approvalRequest['requested_by'] === $reviewerId) {
                    throw new RuntimeException('You cannot review your own approval request.');
                }
                $review = $db->prepare("UPDATE approval_requests SET status = ?, reviewed_by = ?, review_note = NULLIF(?, ''), reviewed_at = NOW() WHERE id = ? AND status = 'Pending'");
                $review->bind_param('sisi', $decision, $reviewerId, $reviewNote, $requestId);
                $review->execute();
                if ($review->affected_rows !== 1) {
                    $review->close();
                    throw new RuntimeException('The request was reviewed by another approver. Refresh the queue.');
                }
                $review->close();
                $reviewerName = sl_user_label($user);
                $notice = 'Your ' . $approvalRequest['request_type'] . ' request #' . $requestId . ' was ' . strtolower($decision) . ' by ' . $reviewerName . '.';
                if ($reviewNote !== '') {
                    $notice .= ' Note: ' . $reviewNote;
                }
                notify_user($db, (int) $approvalRequest['requested_by'], 'approval_' . strtolower($decision), $notice, 'smartlib.php?section=approvals');
                audit($db, $reviewerId, 'approval_' . strtolower($decision), 'approval_request', (string) $requestId, $reviewNote);
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
            $flash = 'Approval request ' . strtolower($decision) . '.';
        } elseif ($action === 'fulfill_reservation') {
            require_permission($user, 'reservations.manage');
            $reservationId = (int) ($_POST['reservation_id'] ?? 0);
            $db->begin_transaction();
            try {
                $reservationQuery = $db->prepare("SELECT r.id, r.book_id, r.user_id, b.title, u.role, u.account_status FROM reservations r JOIN books b ON b.id = r.book_id JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.status = 'Ready' AND (r.expires_at IS NULL OR r.expires_at >= NOW()) FOR UPDATE");
                $reservationQuery->bind_param('i', $reservationId);
                $reservationQuery->execute();
                $reservation = $reservationQuery->get_result()->fetch_assoc();
                $reservationQuery->close();
                if (!$reservation || $reservation['account_status'] !== 'Active') {
                    throw new RuntimeException('The reservation expired or its member is inactive.');
                }
                $bookId = (int) $reservation['book_id'];
                $copyQuery = $db->prepare("SELECT id FROM book_copies WHERE book_id = ? AND status = 'Reserved' ORDER BY id LIMIT 1 FOR UPDATE");
                $copyQuery->bind_param('i', $bookId);
                $copyQuery->execute();
                $reservedCopy = $copyQuery->get_result()->fetch_assoc();
                $copyQuery->close();
                if (!$reservedCopy) {
                    throw new RuntimeException('No reserved physical copy is available for this hold.');
                }
                $borrowerId = (int) $reservation['user_id'];
                $group = sl_member_group((string) $reservation['role']);
                $limit = (int) sl_value($db, $group . '_loan_limit', '3');
                $days = (int) sl_value($db, $group . '_loan_days', '14');
                $loanCount = $db->prepare("SELECT COUNT(*) AS total FROM loans WHERE borrower_id = ? AND status IN ('Active', 'Overdue')");
                $loanCount->bind_param('i', $borrowerId);
                $loanCount->execute();
                $activeLoans = (int) $loanCount->get_result()->fetch_assoc()['total'];
                $loanCount->close();
                if ($activeLoans >= $limit) {
                    throw new RuntimeException('This member has reached their borrowing limit (' . $limit . ').');
                }
                $copyId = (int) $reservedCopy['id'];
                $issuer = (int) $user['id'];
                $dueAt = date('Y-m-d', strtotime('+' . $days . ' days'));
                $loanInsert = $db->prepare('INSERT INTO loans (copy_id, borrower_id, issued_by, due_at) VALUES (?, ?, ?, ?)');
                $loanInsert->bind_param('iiis', $copyId, $borrowerId, $issuer, $dueAt);
                $loanInsert->execute();
                $loanId = $db->insert_id;
                $loanInsert->close();
                $copyUpdate = $db->prepare("UPDATE book_copies SET status = 'Borrowed' WHERE id = ?");
                $copyUpdate->bind_param('i', $copyId);
                $copyUpdate->execute();
                $copyUpdate->close();
                $reservationUpdate = $db->prepare("UPDATE reservations SET status = 'Fulfilled', fulfilled_at = NOW() WHERE id = ?");
                $reservationUpdate->bind_param('i', $reservationId);
                $reservationUpdate->execute();
                $reservationUpdate->close();
                notify_user($db, $borrowerId, 'loan', 'Collected: ' . $reservation['title'] . '. Due ' . sl_date($dueAt) . '.', 'smartlib.php?section=circulation');
                audit($db, $issuer, 'reservation_fulfilled', 'loan', (string) $loanId, 'Reservation ' . $reservationId);
                $db->commit();
                $flash = 'Reserved book issued. Due date: ' . sl_date($dueAt) . '.';
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        } elseif ($action === 'cancel_managed_reservation') {
            require_permission($user, 'reservations.manage');
            $reservationId = (int) ($_POST['reservation_id'] ?? 0);
            $db->begin_transaction();
            try {
                $reservationQuery = $db->prepare("SELECT id, book_id, status FROM reservations WHERE id = ? AND status IN ('Pending', 'Ready') FOR UPDATE");
                $reservationQuery->bind_param('i', $reservationId);
                $reservationQuery->execute();
                $reservation = $reservationQuery->get_result()->fetch_assoc();
                $reservationQuery->close();
                if (!$reservation) {
                    throw new RuntimeException('That reservation is no longer active.');
                }
                $reservationUpdate = $db->prepare("UPDATE reservations SET status = 'Cancelled' WHERE id = ?");
                $reservationUpdate->bind_param('i', $reservationId);
                $reservationUpdate->execute();
                $reservationUpdate->close();
                if ($reservation['status'] === 'Ready') {
                    $bookId = (int) $reservation['book_id'];
                    $copyQuery = $db->prepare("SELECT id FROM book_copies WHERE book_id = ? AND status = 'Reserved' ORDER BY id LIMIT 1 FOR UPDATE");
                    $copyQuery->bind_param('i', $bookId);
                    $copyQuery->execute();
                    $copy = $copyQuery->get_result()->fetch_assoc();
                    $copyQuery->close();
                    if ($copy) {
                        $nextQuery = $db->prepare("SELECT r.id, r.user_id, b.title FROM reservations r JOIN books b ON b.id = r.book_id WHERE r.book_id = ? AND r.status = 'Pending' ORDER BY r.reserved_at LIMIT 1 FOR UPDATE");
                        $nextQuery->bind_param('i', $bookId);
                        $nextQuery->execute();
                        $nextReservation = $nextQuery->get_result()->fetch_assoc();
                        $nextQuery->close();
                        if ($nextReservation) {
                            $holdDays = (int) sl_value($db, 'reservation_hold_days', '3');
                            $nextReservationId = (int) $nextReservation['id'];
                            $readyUpdate = $db->prepare("UPDATE reservations SET status = 'Ready', ready_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?");
                            $readyUpdate->bind_param('ii', $holdDays, $nextReservationId);
                            $readyUpdate->execute();
                            $readyUpdate->close();
                            notify_user($db, (int) $nextReservation['user_id'], 'reservation', 'Your reserved book is ready for collection: ' . $nextReservation['title'] . '.', 'smartlib.php?section=reservations');
                        } else {
                            $copyId = (int) $copy['id'];
                            $copyUpdate = $db->prepare("UPDATE book_copies SET status = 'Available' WHERE id = ?");
                            $copyUpdate->bind_param('i', $copyId);
                            $copyUpdate->execute();
                            $copyUpdate->close();
                            $stockUpdate = $db->prepare('UPDATE books SET available_copies = available_copies + 1 WHERE id = ?');
                            $stockUpdate->bind_param('i', $bookId);
                            $stockUpdate->execute();
                            $stockUpdate->close();
                        }
                    }
                }
                audit($db, (int) $user['id'], 'reservation_cancelled', 'reservation', (string) $reservationId, null);
                $db->commit();
                $flash = 'Reservation cancelled.';
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        } elseif ($action === 'return') {
            require_permission($user, 'circulation.manage'); $loanId = (int) ($_POST['loan_id'] ?? 0); $db->begin_transaction();
            try {
                $q = $db->prepare("SELECT l.*, c.book_id, c.id AS copy_id, b.title FROM loans l JOIN book_copies c ON c.id=l.copy_id JOIN books b ON b.id=c.book_id WHERE l.id = ? AND l.status IN ('Active','Overdue') FOR UPDATE"); $q->bind_param('i', $loanId); $q->execute(); $loan = $q->get_result()->fetch_assoc(); $q->close(); if (!$loan) { throw new RuntimeException('That active loan was not found.'); }
                $returner = (int) $user['id']; $update = $db->prepare("UPDATE loans SET returned_at=NOW(), returned_by=?, status='Returned' WHERE id=?"); $update->bind_param('ii', $returner, $loanId); $update->execute(); $update->close();
                $next = $db->prepare("SELECT id, user_id FROM reservations WHERE book_id=? AND status='Pending' ORDER BY reserved_at LIMIT 1 FOR UPDATE"); $next->bind_param('i', $loan['book_id']); $next->execute(); $reservation = $next->get_result()->fetch_assoc(); $next->close();
                if ($reservation) { $holdDays = (int) sl_value($db, 'reservation_hold_days', '3'); $rid=(int)$reservation['id']; $r = $db->prepare("UPDATE reservations SET status='Ready', ready_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?"); $r->bind_param('ii', $holdDays, $rid); $r->execute(); $r->close(); $copyStatus = 'Reserved'; notify_user($db, (int)$reservation['user_id'], 'reservation', 'Your reserved book is ready for collection: ' . $loan['title'] . '.', 'smartlib.php?section=reservations'); } else { $copyStatus = 'Available'; }
                $copy = $db->prepare('UPDATE book_copies SET status=? WHERE id=?'); $copy->bind_param('si', $copyStatus, $loan['copy_id']); $copy->execute(); $copy->close(); if (!$reservation) { $stock=$db->prepare('UPDATE books SET available_copies=available_copies+1 WHERE id=?'); $stock->bind_param('i',$loan['book_id']); $stock->execute(); $stock->close(); }
                $lateDays = max(0, (int) floor((time() - strtotime((string)$loan['due_at'])) / 86400)); $grace = (int) sl_value($db, 'fine_grace_days', '0'); $fineAmount = min((float) sl_value($db, 'maximum_fine', '100.00'), max(0, $lateDays - $grace) * (float) sl_value($db, 'daily_fine_rate', '1.00'));
                if ($fineAmount > 0) { $borrower=(int)$loan['borrower_id']; $reason='Overdue fine: ' . $lateDays . ' day(s)'; $fine=$db->prepare("INSERT INTO fines (user_id, loan_id, amount, balance, reason) VALUES (?, ?, ?, ?, ?)"); $fine->bind_param('iidds',$borrower,$loanId,$fineAmount,$fineAmount,$reason); $fine->execute(); $fine->close(); notify_user($db,$borrower,'fine','A fine of ' . number_format($fineAmount,2) . ' was assessed for ' . $loan['title'] . '.','smartlib.php?section=fines'); }
                audit($db,$returner,'loan_returned','loan',(string)$loanId,$loan['title']); $db->commit(); $flash='Return recorded' . ($fineAmount > 0 ? '; fine assessed: ' . number_format($fineAmount, 2) : '.') ;
            } catch (Throwable $e) { $db->rollback(); throw $e; }
        } elseif ($action === 'renew') {
            $loanId=(int)($_POST['loan_id']??0); $isOwn=can($user,'member.self'); if (!$isOwn) require_permission($user,'circulation.manage');
            $q=$db->prepare("SELECT l.*, u.role, c.book_id FROM loans l JOIN users u ON u.id=l.borrower_id JOIN book_copies c ON c.id=l.copy_id WHERE l.id=? AND l.status IN ('Active','Overdue')"); $q->bind_param('i',$loanId); $q->execute(); $loan=$q->get_result()->fetch_assoc(); $q->close(); if (!$loan || ($isOwn && (int)$loan['borrower_id'] !== (int)$user['id'])) throw new RuntimeException('Loan not found.');
            $r=$db->prepare("SELECT COUNT(*) AS total FROM reservations WHERE book_id=? AND status IN ('Pending','Ready')"); $r->bind_param('i',$loan['book_id']); $r->execute(); $waiting=(int)$r->get_result()->fetch_assoc()['total']; $r->close(); if ($waiting || (int)$loan['renewals'] >= 1) throw new RuntimeException('This loan cannot be renewed because it has reached its renewal limit or has a reservation queue.');
            $days=(int)sl_value($db,sl_member_group($loan['role']).'_loan_days','14'); $newDue=date('Y-m-d',strtotime('+' . $days . ' days')); $u=$db->prepare("UPDATE loans SET due_at=?, renewals=renewals+1, status='Active' WHERE id=?"); $u->bind_param('si',$newDue,$loanId); $u->execute(); $u->close(); notify_user($db,(int)$loan['borrower_id'],'renewal','Your loan was renewed until ' . sl_date($newDue) . '.','smartlib.php?section=circulation'); audit($db,(int)$user['id'],'loan_renewed','loan',(string)$loanId,null); $flash='Loan renewed until ' . sl_date($newDue) . '.';
        } elseif ($action === 'reserve') {
            require_permission($user,'member.self'); $bookId=(int)($_POST['book_id']??0); $db->begin_transaction(); try { $dup=$db->prepare("SELECT id FROM reservations WHERE book_id=? AND user_id=? AND status IN ('Pending','Ready')"); $uid=(int)$user['id']; $dup->bind_param('ii',$bookId,$uid); $dup->execute(); $exists=$dup->get_result()->fetch_assoc(); $dup->close(); if($exists) throw new RuntimeException('You already have an active reservation for this book.'); $book=$db->prepare("SELECT b.title, COUNT(c.id) AS reservable_copies FROM books b LEFT JOIN book_copies c ON c.book_id=b.id AND c.status IN ('Available','Borrowed') WHERE b.id=? AND b.book_status='Active' GROUP BY b.id");$book->bind_param('i',$bookId);$book->execute();$title=$book->get_result()->fetch_assoc();$book->close();if(!$title||!(int)$title['reservable_copies'])throw new RuntimeException('This book is not currently available to reserve.');$insert=$db->prepare("INSERT INTO reservations (book_id,user_id,status) VALUES (?,?,'Pending')");$insert->bind_param('ii',$bookId,$uid);$insert->execute();$insert->close();notify_user($db,$uid,'reservation','Reservation request received for ' . $title['title'] . '.','smartlib.php?section=reservations');audit($db,$uid,'reservation_created','book',(string)$bookId,$title['title']);$db->commit();$flash='Reservation added to the waiting list.'; } catch(Throwable $e){$db->rollback();throw $e;}
        } elseif ($action === 'cancel_reservation') {
            $reservationId=(int)($_POST['reservation_id']??0); $q=$db->prepare("UPDATE reservations SET status='Cancelled' WHERE id=? AND user_id=? AND status='Pending'");$uid=(int)$user['id'];$q->bind_param('ii',$reservationId,$uid);$q->execute();if($q->affected_rows!==1)throw new RuntimeException('Only your pending reservations can be cancelled.');$q->close();$flash='Reservation cancelled.';
        } elseif ($action === 'record_payment') {
            require_permission($user,'fines.manage');$fineId=(int)($_POST['fine_id']??0);$amount=(float)($_POST['amount']??0);$method=trim((string)($_POST['method']??''));if($amount<=0||$method==='')throw new RuntimeException('Enter a payment amount and method.');$db->begin_transaction();try{$q=$db->prepare("SELECT id,balance FROM fines WHERE id=? AND status='Outstanding' FOR UPDATE");$q->bind_param('i',$fineId);$q->execute();$fine=$q->get_result()->fetch_assoc();$q->close();if(!$fine||$amount>(float)$fine['balance'])throw new RuntimeException('Payment must not exceed the outstanding balance.');$ref='PAY-'.date('YmdHis').'-'.bin2hex(random_bytes(3));$receipt='RCP-'.date('Ymd').'-'.bin2hex(random_bytes(3));$rec=(int)$user['id'];$p=$db->prepare("INSERT INTO payments (fine_id,amount,method,reference_code,receipt_number,recorded_by) VALUES (?,?,?,?,?,?)");$p->bind_param('idsssi',$fineId,$amount,$method,$ref,$receipt,$rec);$p->execute();$p->close();$u=$db->prepare("UPDATE fines SET balance=balance-?,status=IF(balance-?<=0,'Paid','Outstanding') WHERE id=?");$u->bind_param('ddi',$amount,$amount,$fineId);$u->execute();$u->close();audit($db,$rec,'payment_recorded','fine',(string)$fineId,$receipt);$db->commit();$flash='Payment recorded. Receipt: '.$receipt;}catch(Throwable $e){$db->rollback();throw $e;}
        } elseif ($action === 'update_member') {
            require_permission($user, 'members.manage');
            $target = (int) ($_POST['user_id'] ?? 0);
            $role = (string) ($_POST['role'] ?? '');
            $status = (string) ($_POST['account_status'] ?? '');
            if ($target < 1 || !in_array($role, ['User', 'Student', 'Teacher', 'Pastor'], true) || !in_array($status, ['Active', 'Suspended', 'Inactive'], true)) {
                throw new RuntimeException('Choose a valid member role and account status.');
            }
            $memberCheck = $db->prepare("SELECT id FROM users WHERE id = ? AND role IN ('User', 'Student', 'Teacher', 'Pastor')");
            $memberCheck->bind_param('i', $target);
            $memberCheck->execute();
            $isMember = $memberCheck->get_result()->fetch_assoc();
            $memberCheck->close();
            if (!$isMember) {
                throw new RuntimeException('That member was not found.');
            }
            $update = $db->prepare("UPDATE users SET role = ?, account_status = ? WHERE id = ? AND role IN ('User', 'Student', 'Teacher', 'Pastor')");
            $update->bind_param('ssi', $role, $status, $target);
            $update->execute();
            $updated = $update->affected_rows;
            $update->close();
            if ($updated === 1) {
                audit($db, (int) $user['id'], 'member_access_updated', 'user', (string) $target, $role . ' / ' . $status);
            }
            $flash = 'Member account updated.';
        } elseif ($action === 'update_profile') {
            require_permission($user, 'member.self');
            $displayName = trim((string) ($_POST['display_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $schoolId = trim((string) ($_POST['school_id'] ?? ''));
            if ($displayName === '' || strlen($displayName) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || strlen($schoolId) > 50) {
                throw new RuntimeException('Enter a valid name, email address, and school ID.');
            }
            $memberId = (int) $user['id'];
            $profileUpdate = $db->prepare('UPDATE users SET display_name = ?, email = ?, school_id = NULLIF(?, \'\') WHERE id = ?');
            $profileUpdate->bind_param('sssi', $displayName, $email, $schoolId, $memberId);
            $profileUpdate->execute();
            $profileUpdate->close();
            audit($db, $memberId, 'profile_updated', 'user', (string) $memberId, 'Member profile details updated');
            $user['display_name'] = $displayName;
            $user['email'] = $email;
            $user['school_id'] = $schoolId;
            $flash = 'Your profile was updated.';
        } elseif ($action === 'update_user') {
            require_permission($user, 'users.manage');
            $target = (int) ($_POST['user_id'] ?? 0);
            $role = (string) ($_POST['role'] ?? '');
            $status = (string) ($_POST['account_status'] ?? '');
            $roles = ['User', 'Pastor', 'Headmaster', 'Librarian', 'Assistant Librarian', 'Teacher', 'Student', 'Accountant', 'IT Support', 'Administrator', 'Super Administrator'];
            if ($target < 1 || !in_array($role, $roles, true) || !in_array($status, ['Active', 'Suspended', 'Inactive'], true)) {
                throw new RuntimeException('Invalid user settings.');
            }
            $db->begin_transaction();
            try {
                $targetQuery = $db->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
                $targetQuery->bind_param('i', $target);
                $targetQuery->execute();
                $targetUser = $targetQuery->get_result()->fetch_assoc();
                $targetQuery->close();
                if (!$targetUser) {
                    throw new RuntimeException('That user account was not found.');
                }
                $isSuperAdministrator = can($user, '*');
                if (!$isSuperAdministrator && in_array($targetUser['role'], ['Administrator', 'Super Administrator'], true)) {
                    throw new RuntimeException('Only a Super Administrator can change administrator accounts.');
                }
                if (!$isSuperAdministrator && in_array($role, ['Administrator', 'Super Administrator'], true)) {
                    throw new RuntimeException('Only a Super Administrator can assign privileged roles.');
                }
                if ($target === (int) $user['id'] && ($status !== 'Active' || $role !== $targetUser['role'])) {
                    throw new RuntimeException('You cannot deactivate or change the role of your own account.');
                }
                if ($targetUser['role'] === 'Super Administrator'
                    && ($role !== 'Super Administrator' || $status !== 'Active')
                    && (int) $db->query("SELECT COUNT(*) AS total FROM users WHERE role = 'Super Administrator' AND account_status = 'Active'")->fetch_assoc()['total'] <= 1) {
                    throw new RuntimeException('The last active Super Administrator cannot be demoted or deactivated.');
                }
                $update = $db->prepare('UPDATE users SET role = ?, account_status = ? WHERE id = ?');
                $update->bind_param('ssi', $role, $status, $target);
                $update->execute();
                $update->close();
                audit($db, (int) $user['id'], 'user_access_updated', 'user', (string) $target, $role . ' / ' . $status);
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
            $flash = 'User account updated.';
        } elseif ($action === 'save_settings') {
            require_permission($user,'settings.manage');foreach(['student_loan_days','teacher_loan_days','staff_loan_days','student_loan_limit','teacher_loan_limit','staff_loan_limit','daily_fine_rate','fine_grace_days','maximum_fine','reservation_hold_days'] as $key){$val=trim((string)($_POST[$key]??''));if($val===''||!is_numeric($val)|| (float)$val<0)throw new RuntimeException('All policy values must be valid positive numbers.');$s=$db->prepare('UPDATE library_settings SET setting_value=? WHERE setting_key=?');$s->bind_param('ss',$val,$key);$s->execute();$s->close();}audit($db,(int)$user['id'],'settings_updated','library_settings',null,null);$flash='Library policies saved.';
        } elseif ($action === 'read_notifications') { $uid=(int)$user['id'];$q=$db->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL');$q->bind_param('i',$uid);$q->execute();$q->close();$flash='Notifications marked as read.'; }
    }

    $search=trim((string)($_GET['q']??''));$category=trim((string)($_GET['category']??''));$availability=(string)($_GET['availability']??'');
    $categories=$db->query('SELECT name FROM categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
    $bookSql="SELECT b.*, p.name AS publisher, COUNT(c.id) AS physical_copies, SUM(c.status='Available') AS live_available FROM books b LEFT JOIN publishers p ON p.id=b.publisher_id LEFT JOIN book_copies c ON c.book_id=b.id WHERE b.book_status='Active'";
    $types='';$params=[];if($search!==''){$bookSql.=' AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.category LIKE ? OR p.name LIKE ? OR b.shelf_code LIKE ? OR b.description LIKE ?)';$like='%'.$search.'%';$types.='sssssss';$params=[$like,$like,$like,$like,$like,$like,$like];}if($category!==''){$bookSql.=' AND b.category=?';$types.='s';$params[]=$category;}if($availability==='available')$bookSql.=" HAVING live_available > 0";$bookSql.=' GROUP BY b.id ORDER BY b.title LIMIT 100';$booksQuery=$db->prepare($bookSql);if($types!==''){$refs=[];$refs[]=$types;foreach($params as $i=>$v){$refs[]=&$params[$i];}call_user_func_array([$booksQuery,'bind_param'],$refs);}$booksQuery->execute();$books=$booksQuery->get_result()->fetch_all(MYSQLI_ASSOC);$booksQuery->close();
    $activeLoansSql="SELECT l.*, b.title, c.barcode, u.username, u.display_name, u.role FROM loans l JOIN book_copies c ON c.id=l.copy_id JOIN books b ON b.id=c.book_id JOIN users u ON u.id=l.borrower_id WHERE l.status IN ('Active','Overdue')";if(can($user,'circulation.manage')){$activeLoansSql.=' ORDER BY l.due_at';$loans=$db->query($activeLoansSql)->fetch_all(MYSQLI_ASSOC);}else{$activeLoansSql.=' AND l.borrower_id=? ORDER BY l.due_at';$q=$db->prepare($activeLoansSql);$uid=(int)$user['id'];$q->bind_param('i',$uid);$q->execute();$loans=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();}
    $notificationsQ=$db->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20');$uid=(int)$user['id'];$notificationsQ->bind_param('i',$uid);$notificationsQ->execute();$notifications=$notificationsQ->get_result()->fetch_all(MYSQLI_ASSOC);$notificationsQ->close();
} catch (Throwable $exception) { error_log((string)$exception); $fatal = $exception->getMessage(); }
if (isset($fatal)) { http_response_code(503); ?><!doctype html><title>SmartLib Pro</title><link rel="stylesheet" href="style.css"><main class="error-page"><h1>SmartLib Pro is unavailable</h1><p><?= h($fatal === '' ? 'Please try again shortly.' : $fatal) ?></p><a href="login.php">Return to sign in</a></main><?php exit; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h(ucfirst($section)) ?> | SmartLib Pro</title><link rel="stylesheet" href="style.css"></head><body class="smartlib-page">
<aside class="sl-sidebar"><a class="sl-logo" href="smartlib.php"><span>SL</span> SmartLib <b>Pro</b></a><p class="sl-role"><?= h((string)$user['role']) ?></p><nav><?php $nav=['dashboard'=>'Overview','catalogue'=>'Catalogue','circulation'=>'Loans & returns','reservations'=>'Reservations','fines'=>'Fines & payments','members'=>can($user,'users.manage')?'User accounts':'Members','reports'=>'Reports','approvals'=>'Approvals','settings'=>'Library settings','notifications'=>'Notifications','audit'=>'Audit trail','profile'=>'My profile'];foreach($nav as $key=>$label): $needed=['catalogue'=>'catalogue.view','circulation'=>'member.self','reservations'=>'member.self','fines'=>'member.self','members'=>'members.manage','reports'=>'reports.view','approvals'=>'approvals.manage','settings'=>'settings.manage','audit'=>'audit.view','notifications'=>'dashboard','profile'=>'dashboard'][$key]??'dashboard'; if(($key==='catalogue'&&can($user,'catalogue.manage'))||($key==='circulation'&&can($user,'circulation.manage'))||($key==='reservations'&&can($user,'reservations.manage'))||($key==='fines'&&(can($user,'fines.manage')||can($user,'fines.view')))||($key==='members'&&can($user,'users.manage'))||can($user,$needed)): ?><a class="<?= $section===$key?'active':'' ?>" href="smartlib.php?section=<?= h($key) ?>"><?= h($label) ?></a><?php endif;endforeach;?><?php if(can($user,'catalogue.view')): ?><a href="course_library.php">Courses &amp; e-library</a><?php endif;?></nav><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button type="submit">Sign out</button></form></aside>
<main class="sl-main"><header class="sl-top"><div><p class="eyebrow">Smart school library</p><h1><?= h($section==='dashboard'?sl_role_home((string)$user['role']):ucwords(str_replace('_',' ',$section))) ?></h1></div><div class="sl-user"><span><?= h(strtoupper(substr(sl_user_label($user),0,2))) ?></span><div><strong><?= h(sl_user_label($user)) ?></strong><small><?= h((string)$user['role']) ?></small></div></div></header><?php if($flash!==''):?><div class="alert <?=h($flashType)?>"><?=h($flash)?></div><?php endif; ?>
<?php if($section==='dashboard'): $stats=['Titles'=>$db->query('SELECT COUNT(*) c FROM books')->fetch_assoc()['c'],'Physical copies'=>$db->query('SELECT COUNT(*) c FROM book_copies')->fetch_assoc()['c'],'Active loans'=>$db->query("SELECT COUNT(*) c FROM loans WHERE status IN ('Active','Overdue')")->fetch_assoc()['c'],'Overdue'=>$db->query("SELECT COUNT(*) c FROM loans WHERE due_at<CURDATE() AND status IN ('Active','Overdue')")->fetch_assoc()['c']]; if(can($user,'member.self')&&!can($user,'circulation.manage')){$stats=['My loans'=>count($loans),'My overdue'=>count(array_filter($loans,static function($l){return $l['due_at']<date('Y-m-d');})),'Unread alerts'=>count(array_filter($notifications,static function($n){return !$n['read_at'];})),'Available books'=>$db->query("SELECT COUNT(*) c FROM book_copies WHERE status='Available'")->fetch_assoc()['c']];} ?><section class="sl-stats"><?php foreach($stats as $label=>$value):?><article><span><?=h($label)?></span><strong><?=h((string)$value)?></strong></article><?php endforeach;?></section><section class="sl-grid"><article class="sl-card"><h2><?=can($user,'circulation.manage')?'Loans requiring attention':'My current loans'?></h2><?php if(!$loans):?><p class="muted">No active loans.</p><?php else:?><ul class="sl-list"><?php foreach(array_slice($loans,0,5) as $loan):?><li><strong><?=h($loan['title'])?></strong><span>Due <?=h(sl_date($loan['due_at']))?></span></li><?php endforeach;?></ul><?php endif;?></article><article class="sl-card"><h2>Recent notifications</h2><?php if(!$notifications):?><p class="muted">You are all caught up.</p><?php else:?><ul class="sl-list"><?php foreach(array_slice($notifications,0,5) as $note):?><li><strong><?=h($note['type'])?></strong><span><?=h($note['message'])?></span></li><?php endforeach;?></ul><?php endif;?></article></section>
<?php elseif($section==='catalogue'): ?><section class="sl-card"><form class="sl-filter" method="get"><input type="hidden" name="section" value="catalogue"><input name="q" value="<?=h($search)?>" placeholder="Search title, author, ISBN, category, or keyword"><select name="category"><option value="">All categories</option><?php foreach($categories as $cat):?><option <?=($category===$cat['name']?'selected':'')?> value="<?=h($cat['name'])?>"><?=h($cat['name'])?></option><?php endforeach;?></select><select name="availability"><option value="">Any availability</option><option value="available" <?=$availability==='available'?'selected':''?>>Available now</option></select><button>Search</button></form></section><?php if(can($user,'catalogue.manage')): ?><section class="sl-card"><h2>Add book and copies</h2><form method="post" class="sl-form"><input type="hidden" name="action" value="add_book"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input name="title" placeholder="Title" required><input name="author" placeholder="Author" required><input name="isbn" placeholder="ISBN"><input name="publisher" placeholder="Publisher"><input name="publication_year" type="number" min="1000" max="<?=date('Y')?>" placeholder="Year"><input name="category" placeholder="Category" required><input name="shelf_code" placeholder="Shelf location"><input name="cover_image_url" type="url" maxlength="500" placeholder="HTTPS cover image URL"><input name="copies" type="number" min="1" value="1" required><button>Add to catalogue</button></form></section><?php endif; ?><section class="sl-card"><div class="table-wrap"><table><thead><tr><th>Title</th><th>Author</th><th>Category</th><th>ISBN</th><th>Availability</th><th>Action</th></tr></thead><tbody><?php foreach($books as $book):?><tr><td><strong><?=h($book['title'])?></strong><small><?=h((string)($book['publisher']??''))?></small></td><td><?=h($book['author'])?></td><td><?=h($book['category'])?></td><td><?=h((string)($book['isbn']?:'—'))?></td><td><?=h((string)$book['live_available'])?> / <?=h((string)$book['physical_copies'])?></td><td><?php if(can($user,'catalogue.manage')):?><a href="book_edit.php?id=<?=h((string)$book['id'])?>">Edit</a><?php elseif(can($user,'member.self')):?><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="reserve"><input type="hidden" name="book_id" value="<?=h((string)$book['id'])?>"><button class="link-button">Reserve</button></form><?php endif;?></td></tr><?php endforeach;if(!$books):?><tr><td colspan="6">No books match your search.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($section==='circulation'): require_permission($user,can($user,'circulation.manage')?'circulation.manage':'member.self'); if(can($user,'circulation.manage')): $members=$db->query("SELECT id,username,display_name,role FROM users WHERE account_status='Active' ORDER BY COALESCE(display_name,username)")->fetch_all(MYSQLI_ASSOC);$copies=$db->query("SELECT c.id,c.barcode,b.title FROM book_copies c JOIN books b ON b.id=c.book_id WHERE c.status='Available' ORDER BY b.title")->fetch_all(MYSQLI_ASSOC);?><section class="sl-card"><h2>Issue a book</h2><form method="post" class="sl-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="issue"><select name="copy_id" required><option value="">Select available copy</option><?php foreach($copies as $copy):?><option value="<?=$copy['id']?>"><?=h($copy['title'])?> · <?=h($copy['barcode'])?></option><?php endforeach;?></select><select name="borrower_id" required><option value="">Select member</option><?php foreach($members as $member):?><option value="<?=$member['id']?>"><?=h(sl_user_label($member))?> · <?=h($member['role'])?></option><?php endforeach;?></select><button>Issue book</button></form></section><?php endif;?><section class="sl-card"><h2><?=can($user,'circulation.manage')?'Active loans':'My loans'?></h2><div class="table-wrap"><table><thead><tr><th>Book</th><th>Copy</th><?php if(can($user,'circulation.manage')):?><th>Member</th><?php endif;?><th>Due date</th><th>Action</th></tr></thead><tbody><?php foreach($loans as $loan):?><tr class="<?=($loan['due_at']<date('Y-m-d')?'overdue':'')?>"><td><?=h($loan['title'])?></td><td><?=h($loan['barcode'])?></td><?php if(can($user,'circulation.manage')):?><td><?=h(sl_user_label($loan))?></td><?php endif;?><td><?=h(sl_date($loan['due_at']))?></td><td><?php if(can($user,'circulation.manage')):?><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="return"><input type="hidden" name="loan_id" value="<?=$loan['id']?>"><button>Return</button></form><?php endif;?> <form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="renew"><input type="hidden" name="loan_id" value="<?=$loan['id']?>"><button class="link-button">Renew</button></form></td></tr><?php endforeach;if(!$loans):?><tr><td colspan="5">No active loans.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($section==='reservations'): $sql="SELECT r.*,b.title,u.username,u.display_name FROM reservations r JOIN books b ON b.id=r.book_id JOIN users u ON u.id=r.user_id";if(can($user,'reservations.manage')){$reservations=$db->query($sql.' WHERE r.status IN (\'Pending\',\'Ready\') ORDER BY r.reserved_at')->fetch_all(MYSQLI_ASSOC);}else{$sql.=' WHERE r.user_id=? ORDER BY r.reserved_at DESC';$q=$db->prepare($sql);$uid=(int)$user['id'];$q->bind_param('i',$uid);$q->execute();$reservations=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();}?><section class="sl-card"><h2><?=can($user,'reservations.manage')?'Reservation queue':'My reservations'?></h2><div class="table-wrap"><table><thead><tr><th>Book</th><?php if(can($user,'reservations.manage')):?><th>Member</th><?php endif;?><th>Requested</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($reservations as $r):?><tr><td><?=h($r['title'])?></td><?php if(can($user,'reservations.manage')):?><td><?=h(sl_user_label($r))?></td><?php endif;?><td><?=h(sl_date($r['reserved_at']))?></td><td><span class="sl-badge"><?=h($r['status'])?></span></td><td><?php if(can($user,'reservations.manage')):?><?php if($r['status']==='Ready'):?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="fulfill_reservation"><input type="hidden" name="reservation_id" value="<?=h((string)$r['id'])?>"><button>Issue reserved copy</button></form><?php endif;?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="cancel_managed_reservation"><input type="hidden" name="reservation_id" value="<?=h((string)$r['id'])?>"><button>Cancel hold</button></form><?php elseif($r['status']==='Pending'&&(int)$r['user_id']===(int)$user['id']):?><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="reservation_id" value="<?=$r['id']?>"><button>Cancel</button></form><?php endif;?></td></tr><?php endforeach;if(!$reservations):?><tr><td colspan="5">No reservations found.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($section==='fines'): $sql="SELECT f.*,u.username,u.display_name,b.title FROM fines f JOIN users u ON u.id=f.user_id LEFT JOIN loans l ON l.id=f.loan_id LEFT JOIN book_copies c ON c.id=l.copy_id LEFT JOIN books b ON b.id=c.book_id";if(can($user,'fines.manage')||can($user,'fines.view')){$fines=$db->query($sql.' ORDER BY f.assessed_at DESC')->fetch_all(MYSQLI_ASSOC);}else{$sql.=' WHERE f.user_id=? ORDER BY f.assessed_at DESC';$q=$db->prepare($sql);$uid=(int)$user['id'];$q->bind_param('i',$uid);$q->execute();$fines=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();}?><section class="sl-card"><h2>Fine ledger</h2><div class="table-wrap"><table><thead><tr><th>Member</th><th>Reason</th><th>Assessed</th><th>Balance</th><th>Status</th><th>Payment</th></tr></thead><tbody><?php foreach($fines as $fine):?><tr><td><?=h(sl_user_label($fine))?></td><td><?=h($fine['reason'])?></td><td><?=h(sl_date($fine['assessed_at']))?></td><td><?=number_format((float)$fine['balance'],2)?></td><td><?=h($fine['status'])?></td><td><?php if(can($user,'fines.manage')&&$fine['status']==='Outstanding'):?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="record_payment"><input type="hidden" name="fine_id" value="<?=$fine['id']?>"><input name="amount" type="number" step="0.01" min="0.01" max="<?=h($fine['balance'])?>" placeholder="Amount" required><select name="method"><option>Cash</option><option>Bank transfer</option><option>Mobile money</option></select><button>Record</button></form><?php endif;?></td></tr><?php endforeach;if(!$fines):?><tr><td colspan="6">No fine records.</td></tr><?php endif;?></tbody></table></div></section>
<?php elseif($section==='members'): require_permission($user,can($user,'users.manage')?'users.manage':'members.manage');$memberSearch=trim((string)($_GET['q']??''));$memberSql='SELECT id,username,display_name,email,school_id,role,account_status,last_login_at FROM users';if(!can($user,'users.manage')){$memberSql.=" WHERE role IN ('User','Student','Teacher','Pastor')";}if($memberSearch!==''){$memberSql.=(strpos($memberSql,' WHERE ')===false?' WHERE':' AND').' (username LIKE ? OR display_name LIKE ? OR email LIKE ? OR school_id LIKE ?)';$memberLike='%'.$memberSearch.'%';$memberQuery=$db->prepare($memberSql.' ORDER BY created_at DESC LIMIT 100');$memberQuery->bind_param('ssss',$memberLike,$memberLike,$memberLike,$memberLike);$memberQuery->execute();$members=$memberQuery->get_result()->fetch_all(MYSQLI_ASSOC);$memberQuery->close();}else{$members=$db->query($memberSql.' ORDER BY created_at DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);}?><section class="sl-card"><div class="librarian-card-heading"><h2><?=can($user,'users.manage')?'Member and staff accounts':'Library members'?></h2><?php if(can($user,'members.manage')):?><a href="member_register.php">Register member</a><?php endif;?></div><form class="sl-filter" method="get"><input type="hidden" name="section" value="members"><input name="q" value="<?=h($memberSearch)?>" placeholder="Search name, username, email, or school ID"><button>Search members</button></form><div class="table-wrap"><table><thead><tr><th>User</th><th>School ID</th><th>Role</th><th>Status</th><th>Last sign in</th><th>Update</th></tr></thead><tbody><?php foreach($members as $member):?><tr><td><strong><?=h(sl_user_label($member))?></strong><small><?=h($member['email'])?></small></td><td><?=h((string)($member['school_id']?:'—'))?></td><td><?=h($member['role'])?></td><td><?=h($member['account_status'])?></td><td><?=h((string)($member['last_login_at']?:'Never'))?></td><td><?php if(can($user,'users.manage')):?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?=$member['id']?>"><select name="role"><?php $assignableRoles=['User','Pastor','Headmaster','Librarian','Assistant Librarian','Teacher','Student','Accountant','IT Support','Administrator'];if(can($user,'*'))$assignableRoles[]='Super Administrator';foreach($assignableRoles as $role):?><option <?=$member['role']===$role?'selected':''?>><?=h($role)?></option><?php endforeach;?></select><select name="account_status"><option <?=$member['account_status']==='Active'?'selected':''?>>Active</option><option <?=$member['account_status']==='Suspended'?'selected':''?>>Suspended</option><option <?=$member['account_status']==='Inactive'?'selected':''?>>Inactive</option></select><button>Save</button></form><?php elseif(in_array($member['role'],['User','Student','Teacher','Pastor'],true)):?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_member"><input type="hidden" name="user_id" value="<?=$member['id']?>"><select name="role"><?php foreach(['User','Student','Teacher','Pastor'] as $role):?><option <?=$member['role']===$role?'selected':''?>><?=h($role)?></option><?php endforeach;?></select><select name="account_status"><?php foreach(['Active','Suspended','Inactive'] as $status):?><option <?=$member['account_status']===$status?'selected':''?>><?=h($status)?></option><?php endforeach;?></select><button>Save</button></form><?php else:?>Restricted account<?php endif;?></td></tr><?php endforeach;if(!$members):?><tr><td colspan="6">No matching users found.</td></tr><?php endif;?></tbody></table></div><p class="muted">Use Inactive to remove an account from sign-in while retaining borrowing and audit history. User records with library history are not permanently deleted.</p></section>
<?php elseif($section==='reports'): require_permission($user,'reports.view');$trend=$db->query("SELECT DATE_FORMAT(issued_at,'%Y-%m') label,COUNT(*) total FROM loans GROUP BY DATE_FORMAT(issued_at,'%Y-%m') ORDER BY label DESC LIMIT 12")->fetch_all(MYSQLI_ASSOC);$popular=$db->query("SELECT b.title,COUNT(*) total FROM loans l JOIN book_copies c ON c.id=l.copy_id JOIN books b ON b.id=c.book_id GROUP BY b.id ORDER BY total DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);?><section class="sl-grid"><article class="sl-card"><h2>Borrowing trend</h2><div class="sl-bars"><?php foreach(array_reverse($trend) as $row):?><div><span style="height:<?=min(100,10+(int)$row['total']*12)?>%"></span><small><?=h($row['label'])?> · <?=h($row['total'])?></small></div><?php endforeach;if(!$trend):?><p class="muted">No borrowing activity recorded yet.</p><?php endif;?></div></article><article class="sl-card"><h2>Popular books</h2><ol class="sl-list"><?php foreach($popular as $row):?><li><strong><?=h($row['title'])?></strong><span><?=h($row['total'])?> loans</span></li><?php endforeach;if(!$popular):?><p class="muted">No lending data yet.</p><?php endif;?></ol></article></section><section class="sl-card"><div class="headmaster-card-heading"><h2>Authorized report exports</h2><span class="muted">Excel-compatible CSV or PDF</span></div><div class="headmaster-report-links"><?php foreach(['circulation'=>'Circulation','activity'=>'Member activity','fines'=>'Financial and fine','overdue'=>'Overdue loans','performance'=>'Librarian performance','inventory'=>'Inventory'] as $reportKey=>$reportLabel):?><div><strong><?=h($reportLabel)?></strong><a href="report_export.php?report=<?=h($reportKey)?>&amp;format=csv">Excel CSV</a><a href="report_export.php?report=<?=h($reportKey)?>&amp;format=pdf">PDF</a></div><?php endforeach;?></div></section>
<?php elseif($section==='approvals'): require_permission($user,'approvals.manage');$approvalRows=$db->query("SELECT ar.*, requester.display_name AS requester_name, requester.username AS requester_username, reviewer.display_name AS reviewer_name, reviewer.username AS reviewer_username FROM approval_requests ar JOIN users requester ON requester.id=ar.requested_by LEFT JOIN users reviewer ON reviewer.id=ar.reviewed_by ORDER BY (ar.status='Pending') DESC, ar.requested_at DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);?><section class="sl-card"><h2>Exceptional transaction approvals</h2><p class="muted">Review requests before the related exceptional transaction is processed. Approval records the decision only; it does not perform the transaction.</p><div class="table-wrap"><table><thead><tr><th>Requested</th><th>Requester</th><th>Type</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead><tbody><?php foreach($approvalRows as $approval):?><tr><td><?=h(sl_date($approval['requested_at']))?></td><td><?=h(trim((string)$approval['requester_name'])?:$approval['requester_username'])?></td><td><?=h($approval['request_type'])?></td><td><?=h($approval['reason'])?></td><td><?=h($approval['status'])?><?php if($approval['review_note']):?><small><?=h($approval['review_note'])?></small><?php endif;?></td><td><?php if($approval['status']==='Pending'&&(int)$approval['requested_by']!==(int)$user['id']):?><form method="post" class="headmaster-approval-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="review_approval"><input type="hidden" name="request_id" value="<?=h((string)$approval['id'])?>"><input name="review_note" maxlength="450" placeholder="Decision note"><button name="decision" value="Approved">Approve</button><button name="decision" value="Rejected" class="secondary-button">Reject</button></form><?php elseif($approval['status']!=='Pending'):?><span class="muted">By <?=h(trim((string)$approval['reviewer_name'])?:($approval['reviewer_username']??'approver'))?> · <?=h(sl_date((string)$approval['reviewed_at']))?></span><?php else:?>Requester cannot self-approve<?php endif;?></td></tr><?php endforeach;if(!$approvalRows):?><tr><td colspan="6">No approval requests yet.</td><?php endif;?></tbody></table></div></section>
<?php elseif($section==='settings'): require_permission($user,'settings.manage');$settingKeys=['student_loan_days'=>'Student loan days','teacher_loan_days'=>'Teacher loan days','staff_loan_days'=>'Staff loan days','student_loan_limit'=>'Student loan limit','teacher_loan_limit'=>'Teacher loan limit','staff_loan_limit'=>'Staff loan limit','daily_fine_rate'=>'Daily fine rate','fine_grace_days'=>'Fine grace days','maximum_fine'=>'Maximum fine','reservation_hold_days'=>'Reservation hold days'];?><section class="sl-card"><h2>Library policy</h2><form method="post" class="sl-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="save_settings"><?php foreach($settingKeys as $key=>$label):?><label><?=h($label)?><input name="<?=h($key)?>" type="number" step="0.01" min="0" value="<?=h(sl_value($db,$key,'0'))?>"></label><?php endforeach;?><button>Save policies</button></form></section>
<?php elseif($section==='notifications'):?><section class="sl-card"><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="read_notifications"><button>Mark all as read</button></form><ul class="sl-list notifications"><?php foreach($notifications as $note):?><li class="<?=$note['read_at']?'':'unread'?>"><strong><?=h($note['type'])?></strong><span><?=h($note['message'])?></span><small><?=h(sl_date($note['created_at']))?></small></li><?php endforeach;if(!$notifications):?><p class="muted">No notifications yet.</p><?php endif;?></ul></section>
<?php elseif($section==='audit'): require_permission($user,'audit.view');$logs=$db->query('SELECT a.*,u.username FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);?><section class="sl-card"><div class="table-wrap"><table><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody><?php foreach($logs as $log):?><tr><td><?=h(sl_date($log['created_at']))?></td><td><?=h((string)($log['username']?:'System'))?></td><td><?=h($log['action'])?></td><td><?=h($log['entity_type'].' '.$log['entity_id'])?></td><td><?=h((string)$log['details'])?></td></tr><?php endforeach;if(!$logs):?><tr><td colspan="5">No audit events.</td></tr><?php endif;?></tbody></table></div></section>
<?php else:?><section class="sl-card"><h2>My profile</h2><dl class="sl-profile"><div><dt>Name</dt><dd><?=h(sl_user_label($user))?></dd></div><div><dt>Email</dt><dd><?=h($user['email'])?></dd></div><div><dt>School ID</dt><dd><?=h((string)($user['school_id']?:'Not assigned'))?></dd></div><div><dt>Role</dt><dd><?=h($user['role'])?></dd></div><div><dt>Session protection</dt><dd>Email one-time verification enabled</dd></div></dl><h2>Password protection</h2><div class="password-security-grid" aria-label="Password protection details"><div><span>Hash</span><strong><?=h($passwordSecurity['algorithm'])?> · <?=h($passwordSecurity['cost'])?></strong><code><?=h($passwordSecurity['hash_fingerprint'])?></code></div><div><span>Salt</span><strong><?=h($passwordSecurity['salt'])?></strong></div><div><span>Pepper</span><strong><?=h($passwordSecurity['pepper'])?></strong><small>The secret is never displayed.</small></div></div><?php if(can($user,'member.self')):?><h2 class="profile-edit-heading">Update permitted details</h2><form method="post" class="sl-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_profile"><input name="display_name" maxlength="100" value="<?=h((string)$user['display_name'])?>" placeholder="Your name" required><input name="email" type="email" maxlength="254" value="<?=h($user['email'])?>" placeholder="Email address" required><input name="school_id" maxlength="50" value="<?=h((string)$user['school_id'])?>" placeholder="School ID"><button>Save profile</button></form><p class="muted">Your role and username can only be changed by authorized staff.</p><?php endif;?></section><?php endif;?></main></body></html>
