<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

try {
    $db = db();
    $user = current_user($db);
    if (!$user) { header('Location: login.php'); exit; }
    require_permission($user, 'copies.manage');
    $message = '';
    $messageType = 'success';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_is_valid()) { throw new RuntimeException('Your session expired. Refresh and try again.'); }
        $action = (string) ($_POST['action'] ?? 'update_copy');
        if ($action === 'add_copy') {
            $bookId = (int) ($_POST['book_id'] ?? 0);
            if ($bookId < 1) {
                throw new RuntimeException('Choose a catalogue title for the new copy.');
            }
            $db->begin_transaction();
            try {
                $bookQuery = $db->prepare("SELECT id, title FROM books WHERE id = ? AND book_status = 'Active' FOR UPDATE");
                $bookQuery->bind_param('i', $bookId);
                $bookQuery->execute();
                $book = $bookQuery->get_result()->fetch_assoc();
                $bookQuery->close();
                if (!$book) {
                    throw new RuntimeException('The selected book is not active in the catalogue.');
                }
                $barcode = 'SLP-' . $bookId . '-' . strtoupper(bin2hex(random_bytes(6)));
                $insert = $db->prepare("INSERT INTO book_copies (book_id, barcode, status) VALUES (?, ?, 'Available')");
                $insert->bind_param('is', $bookId, $barcode);
                $insert->execute();
                $copyId = $db->insert_id;
                $insert->close();
                $stock = $db->prepare('UPDATE books SET total_copies = total_copies + 1, available_copies = available_copies + 1 WHERE id = ?');
                $stock->bind_param('i', $bookId);
                $stock->execute();
                $stock->close();
                audit($db, (int) $user['id'], 'book_copy_added', 'book_copy', (string) $copyId, $book['title'] . ' / ' . $barcode);
                $db->commit();
                $message = 'New available copy added. Barcode: ' . $barcode;
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        } elseif ($action === 'update_copy') {
            $copyId = (int) ($_POST['copy_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');
            $condition = trim((string) ($_POST['condition_note'] ?? 'Good'));
            if (!in_array($status, ['Available', 'Maintenance', 'Damaged', 'Missing', 'Lost'], true) || $copyId < 1 || strlen($condition) > 100) {
                throw new RuntimeException('Choose a valid copy status and condition.');
            }
            $db->begin_transaction();
            try {
                $copy = $db->prepare('SELECT book_id, barcode FROM book_copies WHERE id = ? FOR UPDATE');
                $copy->bind_param('i', $copyId); $copy->execute(); $row = $copy->get_result()->fetch_assoc(); $copy->close();
                if (!$row) { throw new RuntimeException('Physical copy not found.'); }
                $loan = $db->prepare("SELECT id FROM loans WHERE copy_id = ? AND status IN ('Active', 'Overdue') LIMIT 1");
                $loan->bind_param('i', $copyId); $loan->execute(); $active = $loan->get_result()->fetch_assoc(); $loan->close();
                if ($active) { throw new RuntimeException('Return this copy before changing its inventory status.'); }
                $update = $db->prepare('UPDATE book_copies SET status = ?, condition_note = ? WHERE id = ?');
                $update->bind_param('ssi', $status, $condition, $copyId); $update->execute(); $update->close();
                $bookId = (int) $row['book_id'];
                $stock = $db->prepare("UPDATE books SET available_copies = (SELECT COUNT(*) FROM book_copies WHERE book_id = ? AND status = 'Available') WHERE id = ?");
                $stock->bind_param('ii', $bookId, $bookId); $stock->execute(); $stock->close();
                audit($db, (int) $user['id'], 'copy_status_updated', 'book_copy', (string) $copyId, $row['barcode'] . ' set to ' . $status);
                $db->commit(); $message = 'Copy inventory status updated.';
            } catch (Throwable $exception) { $db->rollback(); throw $exception; }
        } else {
            throw new RuntimeException('Choose a valid copy management action.');
        }
    }
    $books = $db->query("SELECT id, title, author FROM books WHERE book_status = 'Active' ORDER BY title")->fetch_all(MYSQLI_ASSOC);
    $filter = (string) ($_GET['status'] ?? '');
    $sql = 'SELECT c.*, b.title FROM book_copies c JOIN books b ON b.id = c.book_id';
    if (in_array($filter, ['Available', 'Borrowed', 'Reserved', 'Maintenance', 'Damaged', 'Missing', 'Lost'], true)) {
        $sql .= ' WHERE c.status = ? ORDER BY b.title, c.barcode';
        $list = $db->prepare($sql); $list->bind_param('s', $filter); $list->execute(); $copies = $list->get_result()->fetch_all(MYSQLI_ASSOC); $list->close();
    } else {
        $copies = $db->query($sql . ' ORDER BY b.title, c.barcode')->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $exception) {
    error_log((string) $exception); http_response_code(503); exit('Copy management is temporarily unavailable.');
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Physical copies | SmartLib Pro</title><link rel="stylesheet" href="style.css"></head>
<body class="dashboard-page"><header class="topbar"><a class="brand" href="smartlib.php"><span class="brand-mark">SL</span> SmartLib Pro</a><div class="topbar-actions"><a class="topbar-link" href="smartlib.php?section=catalogue">Catalogue</a><a class="topbar-link" href="smartlib.php?section=dashboard">Dashboard</a></div></header>
<main class="dashboard-shell"><section class="dashboard-hero compact-hero"><div><p class="eyebrow">Inventory</p><h1>Physical copies</h1><p>Track each barcode, its condition, and whether it is available, damaged, missing, lost, or under maintenance.</p></div></section><?php if($message !== ''):?><div class="alert <?=h($messageType)?> library-alert"><?=h($message)?></div><?php endif; ?>
<section class="dashboard-card library-form-card"><h2>Add a physical copy</h2><?php if(!$books):?><p>No active catalogue titles are available. Add a book to the catalogue first.</p><?php else:?><form method="post" class="sl-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_copy"><select name="book_id" required><option value="">Choose a book</option><?php foreach($books as $book):?><option value="<?=h((string)$book['id'])?>"><?=h($book['title'])?> — <?=h($book['author'])?></option><?php endforeach;?></select><button>Add copy</button></form><?php endif;?></section>
<section class="dashboard-card role-manager"><form class="sl-filter" method="get"><select name="status"><option value="">All statuses</option><?php foreach(['Available','Borrowed','Reserved','Maintenance','Damaged','Missing','Lost'] as $choice):?><option value="<?=h($choice)?>" <?=$filter===$choice?'selected':''?>><?=h($choice)?></option><?php endforeach;?></select><button>Filter copies</button></form><div class="role-table-wrap"><table class="role-table"><thead><tr><th>Book</th><th>Barcode</th><th>Status</th><th>Condition</th><th>Update</th></tr></thead><tbody><?php foreach($copies as $copy):?><tr><td><?=h($copy['title'])?></td><td><?=h($copy['barcode'])?></td><td><span class="role-pill"><?=h($copy['status'])?></span></td><td><?=h($copy['condition_note'])?></td><td><?php if(!in_array($copy['status'],['Borrowed','Reserved'],true)):?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update_copy"><input type="hidden" name="copy_id" value="<?=h((string)$copy['id'])?>"><select name="status"><?php foreach(['Available','Maintenance','Damaged','Missing','Lost'] as $choice):?><option <?=$copy['status']===$choice?'selected':''?>><?=h($choice)?></option><?php endforeach;?></select><input name="condition_note" maxlength="100" value="<?=h($copy['condition_note'])?>"><button>Save</button></form><?php else:?>Managed by circulation<?php endif;?></td></tr><?php endforeach;if(!$copies):?><tr><td colspan="5">No physical copies found.</td></tr><?php endif;?></tbody></table></div></section></main></body></html>
