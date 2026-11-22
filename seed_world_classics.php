<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This catalogue seed can only be run from the command line.');
}

require_once __DIR__ . '/db.php';

$classics = [
    ['Pride and Prejudice', 'Jane Austen', 1813, 'A witty novel of manners following Elizabeth Bennet and her family as they navigate love, reputation, and social expectations.'],
    ['Frankenstein', 'Mary Shelley', 1818, 'A landmark Gothic novel about Victor Frankenstein, the being he creates, and the responsibilities that follow.'],
    ['Jane Eyre', 'Charlotte Bronte', 1847, 'An independent young woman seeks belonging and purpose while working as a governess at Thornfield Hall.'],
    ['Moby-Dick', 'Herman Melville', 1851, 'Sailor Ishmael joins Captain Ahab on the Pequod for a perilous pursuit of a great white whale.'],
    ['Great Expectations', 'Charles Dickens', 1861, 'Pip recounts his journey from a humble childhood toward adulthood, ambition, and self-understanding.'],
    ['Little Women', 'Louisa May Alcott', 1868, 'The March sisters grow up together, facing family changes and pursuing their own hopes and ambitions.'],
    ['The Adventures of Sherlock Holmes', 'Arthur Conan Doyle', 1892, 'A collection of cases in which Sherlock Holmes and Dr. Watson investigate baffling mysteries in Victorian London.'],
    ['The Time Machine', 'H. G. Wells', 1895, 'A Victorian inventor travels far into the future and encounters a changed human world.'],
    ['Dracula', 'Bram Stoker', 1897, 'A group of people join forces to confront Count Dracula as his influence spreads beyond Transylvania.'],
    ['The Wonderful Wizard of Oz', 'L. Frank Baum', 1900, 'Dorothy and her companions travel through Oz in search of a way to fulfill their wishes.'],
    ['The Call of the Wild', 'Jack London', 1903, 'Buck is taken from his comfortable home and must adapt to life as a sled dog in the Yukon.'],
    ['Anne of Green Gables', 'L. M. Montgomery', 1908, 'Imaginative orphan Anne Shirley finds a home and community on Prince Edward Island.'],
];

$db = db();
$insert = $db->prepare(
    "INSERT INTO books (title, author, publication_year, category, description, total_copies, available_copies)
     SELECT ?, ?, ?, 'World Classics', ?, 0, 0
     WHERE NOT EXISTS (SELECT 1 FROM books WHERE title = ? AND author = ?)"
);
$db->begin_transaction();
try {
    $added = 0;
    foreach ($classics as [$title, $author, $year, $description]) {
        $insert->bind_param('ssisss', $title, $author, $year, $description, $title, $author);
        $insert->execute();
        $added += $insert->affected_rows;
    }
    $insert->close();
    $db->commit();
    echo 'World classics added: ' . $added . PHP_EOL;
} catch (Throwable $exception) {
    $db->rollback();
    throw $exception;
}
