<?php
declare(strict_types=1);

// Schedule once daily with Task Scheduler. It never sends external email/SMS.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/auth.php';

$db = db();
$db->begin_transaction();
try {
    $expired = $db->query("SELECT id, user_id, book_id FROM reservations WHERE status='Ready' AND expires_at < NOW() FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    foreach ($expired as $reservation) {
        $id = (int) $reservation['id']; $bookId = (int) $reservation['book_id'];
        $db->query("UPDATE reservations SET status='Expired' WHERE id={$id}");
        $next = $db->prepare("SELECT r.id, r.user_id, b.title FROM reservations r JOIN books b ON b.id=r.book_id WHERE r.book_id=? AND r.status='Pending' ORDER BY r.reserved_at LIMIT 1 FOR UPDATE");
        $next->bind_param('i', $bookId);
        $next->execute();
        $nextReservation = $next->get_result()->fetch_assoc();
        $next->close();
        if ($nextReservation) {
            $holdDaysQuery = $db->prepare("SELECT setting_value FROM library_settings WHERE setting_key='reservation_hold_days'");
            $holdDaysQuery->execute();
            $holdDays = max(1, (int) ($holdDaysQuery->get_result()->fetch_assoc()['setting_value'] ?? 3));
            $holdDaysQuery->close();
            $nextId = (int) $nextReservation['id'];
            $ready = $db->prepare("UPDATE reservations SET status='Ready', ready_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?");
            $ready->bind_param('ii', $holdDays, $nextId);
            $ready->execute();
            $ready->close();
            notify_user($db, (int) $nextReservation['user_id'], 'reservation', 'Your reserved book is ready for collection: ' . $nextReservation['title'] . '.', 'smartlib.php?section=reservations');
        } else {
            $db->query("UPDATE book_copies SET status='Available' WHERE book_id={$bookId} AND status='Reserved' LIMIT 1");
            $db->query("UPDATE books SET available_copies=(SELECT COUNT(*) FROM book_copies WHERE book_id={$bookId} AND status='Available') WHERE id={$bookId}");
        }
        audit($db, null, 'reservation_expired', 'reservation', (string) $id, null);
    }
    $overdue = $db->query("SELECT l.id,l.borrower_id,b.title FROM loans l JOIN book_copies c ON c.id=l.copy_id JOIN books b ON b.id=c.book_id WHERE l.status='Active' AND l.due_at < CURDATE() FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    foreach ($overdue as $loan) {
        $loanId = (int) $loan['id']; $borrower = (int) $loan['borrower_id'];
        $db->query("UPDATE loans SET status='Overdue' WHERE id={$loanId}");
        $existing = $db->prepare("SELECT id FROM notifications WHERE user_id=? AND type='overdue' AND link_url=? AND created_at >= CURDATE() LIMIT 1");
        $link = 'smartlib.php?section=circulation'; $existing->bind_param('is', $borrower, $link); $existing->execute(); $found = $existing->get_result()->fetch_assoc(); $existing->close();
        if (!$found) { notify_user($db, $borrower, 'overdue', 'Overdue: ' . $loan['title'] . '. Please return or renew it.', $link); }
    }
    $db->commit();
    echo 'Expired reservations: ' . count($expired) . '; overdue loans marked: ' . count($overdue) . PHP_EOL;
} catch (Throwable $exception) { $db->rollback(); throw $exception; }
