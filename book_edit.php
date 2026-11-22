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
    require_permission($user, 'catalogue.manage');
    $bookId = (int) ($_GET['id'] ?? $_POST['book_id'] ?? 0);
    if ($bookId < 1) {
        throw new RuntimeException('Book not found.');
    }
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_is_valid()) {
            throw new RuntimeException('Your session expired. Refresh and try again.');
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $author = trim((string) ($_POST['author'] ?? ''));
        $isbn = trim((string) ($_POST['isbn'] ?? ''));
        $publisher = trim((string) ($_POST['publisher'] ?? ''));
        $year = (int) ($_POST['publication_year'] ?? 0);
        $category = trim((string) ($_POST['category'] ?? ''));
        $shelf = trim((string) ($_POST['shelf_code'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $coverImageUrl = trim((string) ($_POST['cover_image_url'] ?? ''));
        if ($title === '' || $author === '' || $category === ''
            || strlen($title) > 180 || strlen($author) > 140 || strlen($isbn) > 32
            || strlen($publisher) > 140 || strlen($category) > 80 || strlen($shelf) > 50 || strlen($coverImageUrl) > 500
            || ($coverImageUrl !== '' && (!filter_var($coverImageUrl, FILTER_VALIDATE_URL) || parse_url($coverImageUrl, PHP_URL_SCHEME) !== 'https'))
            || ($year !== 0 && ($year < 1000 || $year > (int) date('Y')))) {
            throw new RuntimeException('Enter valid book details, a supported publication year, and an HTTPS cover image URL if supplied.');
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

            $publisherId = null;
            if ($publisher !== '') {
                $publisherInsert = $db->prepare('INSERT IGNORE INTO publishers (name) VALUES (?)');
                $publisherInsert->bind_param('s', $publisher);
                $publisherInsert->execute();
                $publisherInsert->close();
                $publisherLookup = $db->prepare('SELECT id FROM publishers WHERE name = ?');
                $publisherLookup->bind_param('s', $publisher);
                $publisherLookup->execute();
                $publisherId = (int) $publisherLookup->get_result()->fetch_assoc()['id'];
                $publisherLookup->close();
            }

            $update = $db->prepare(
                'UPDATE books
                 SET title = ?, author = ?, isbn = NULLIF(?, \'\'), publisher_id = ?,
                     publication_year = NULLIF(?, 0), category = ?,
                     shelf_code = NULLIF(?, \'\'), description = NULLIF(?, \'\'),
                     cover_image_url = NULLIF(?, \'\')
                 WHERE id = ?'
            );
            $update->bind_param('sssiissssi', $title, $author, $isbn, $publisherId, $year, $category, $shelf, $description, $coverImageUrl, $bookId);
            $update->execute();
            $update->close();
            audit($db, (int) $user['id'], 'book_updated', 'book', (string) $bookId, $title);
            $db->commit();
            $message = 'Book details updated.';
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    $query = $db->prepare('SELECT b.*, p.name AS publisher_name FROM books b LEFT JOIN publishers p ON p.id = b.publisher_id WHERE b.id = ?');
    $query->bind_param('i', $bookId);
    $query->execute();
    $book = $query->get_result()->fetch_assoc();
    $query->close();
    if (!$book) {
        throw new RuntimeException('Book not found.');
    }
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    exit('Book editing is temporarily unavailable.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit book | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-page">
<header class="topbar">
    <a class="brand" href="librarian_dashboard.php"><span class="brand-mark">SL</span> SmartLib Pro</a>
    <div class="topbar-actions"><a class="topbar-link" href="smartlib.php?section=catalogue">Catalogue</a></div>
</header>
<main class="dashboard-shell">
    <section class="dashboard-card">
        <h1>Edit book</h1>
        <?php if ($message !== ''): ?><div class="alert success"><?= h($message) ?></div><?php endif; ?>
        <form method="post" class="sl-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="book_id" value="<?= h((string) $book['id']) ?>">
            <input name="title" maxlength="180" value="<?= h($book['title']) ?>" placeholder="Title" required>
            <input name="author" maxlength="140" value="<?= h($book['author']) ?>" placeholder="Author" required>
            <input name="isbn" maxlength="32" value="<?= h((string) $book['isbn']) ?>" placeholder="ISBN">
            <input name="publisher" maxlength="140" value="<?= h((string) ($book['publisher_name'] ?? '')) ?>" placeholder="Publisher">
            <input name="publication_year" type="number" min="1000" max="<?= h(date('Y')) ?>" value="<?= h((string) $book['publication_year']) ?>" placeholder="Publication year">
            <input name="category" maxlength="80" value="<?= h($book['category']) ?>" placeholder="Category" required>
            <input name="shelf_code" maxlength="50" value="<?= h((string) $book['shelf_code']) ?>" placeholder="Shelf location">
            <input name="description" value="<?= h((string) $book['description']) ?>" placeholder="Description">
            <input name="cover_image_url" type="url" maxlength="500" value="<?= h((string) $book['cover_image_url']) ?>" placeholder="HTTPS cover image URL">
            <button>Save book</button>
        </form>
    </section>
</main>
</body>
</html>
