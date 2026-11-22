<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function report_rows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $statement = $db->prepare($sql);
    if ($types !== '') {
        $references = [$types];
        foreach ($params as $index => $value) {
            $references[] = &$params[$index];
        }
        call_user_func_array([$statement, 'bind_param'], $references);
    }
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();
    return $rows;
}

function report_window(string $period, string $month, string $year, string $from, string $to): array
{
    if ($period === 'all') {
        return ['', [], ''];
    }
    if ($period === 'month') {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('Choose a valid reporting month.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if (!$start || $start->format('Y-m') !== $month) {
            throw new RuntimeException('Choose a valid reporting month.');
        }
        $end = $start->modify('first day of next month');
    } elseif ($period === 'year') {
        if (!preg_match('/^\d{4}$/', $year) || (int) $year < 2000 || (int) $year > (int) date('Y') + 1) {
            throw new RuntimeException('Choose a valid reporting year.');
        }
        $start = new DateTimeImmutable($year . '-01-01');
        $end = $start->modify('+1 year');
    } elseif ($period === 'term') {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $finish = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if (!$start || !$finish || $start->format('Y-m-d') !== $from || $finish->format('Y-m-d') !== $to || $finish < $start) {
            throw new RuntimeException('Enter a valid term start date and end date.');
        }
        if ($finish->diff($start)->days > 370) {
            throw new RuntimeException('A term report cannot cover more than 371 days.');
        }
        $end = $finish->modify('+1 day');
    } else {
        throw new RuntimeException('Choose a monthly, termly, annual, or all-time reporting period.');
    }
    return [$start->format('Y-m-d'), [$start->format('Y-m-d'), $end->format('Y-m-d')], $end->modify('-1 day')->format('Y-m-d')];
}

function report_pdf(array $lines): string
{
    $wrapped = [];
    foreach ($lines as $line) {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $line);
        if ($converted === false) {
            throw new RuntimeException('Report text could not be converted for PDF output.');
        }
        $converted = preg_replace('/[^\x20-\x7E\x80-\xFF]/', ' ', $converted) ?? '';
        foreach (explode("\n", wordwrap($converted, 105, "\n", true)) as $part) {
            $wrapped[] = $part;
        }
    }
    $pages = array_chunk($wrapped ?: ['No report data found.'], 52);
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];
    $pageReferences = [];
    foreach ($pages as $index => $pageLines) {
        $pageId = 4 + $index * 2;
        $streamId = $pageId + 1;
        $pageReferences[] = $pageId . ' 0 R';
        $stream = "BT\n/F1 9 Tf\n40 752 Td\n13 TL\n";
        foreach ($pageLines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= '(' . $escaped . ") Tj\nT*\n";
        }
        $stream .= "ET\n";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $streamId . ' 0 R >>';
        $objects[$streamId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . count($pages) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $size = max(array_keys($objects)) + 1;
    $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
    for ($id = 1; $id < $size; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
    }
    return $pdf . "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
}

function csv_safe_value($value): string
{
    $text = (string) ($value ?? '');
    return preg_match('/^\s*[=+\-@]/', $text) ? "'" . $text : $text;
}

try {
    $db = db();
    $user = current_user($db);
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    require_permission($user, 'reports.view');

    $report = (string) ($_GET['report'] ?? 'overdue');
    $allowedReports = ['overdue', 'inventory', 'circulation', 'fines', 'activity', 'performance'];
    if (!in_array($report, $allowedReports, true)) {
        http_response_code(400);
        exit('Unknown report.');
    }
    if ($report === 'fines') {
        require_permission($user, 'fines.view');
    }
    if (in_array($report, ['activity', 'performance'], true)) {
        require_permission($user, 'activity.view');
    }

    $format = (string) ($_GET['format'] ?? 'csv');
    if (!in_array($format, ['csv', 'pdf'], true)) {
        http_response_code(400);
        exit('Choose PDF or Excel-compatible CSV format.');
    }
    $period = (string) ($_GET['period'] ?? 'all');
    $month = trim((string) ($_GET['month'] ?? date('Y-m')));
    $year = trim((string) ($_GET['year'] ?? date('Y')));
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    [$startDate, $windowParams, $endDate] = report_window($period, $month, $year, $from, $to);
    $dateFilter = static function (string $column) use ($period): string {
        return $period === 'all' ? '' : ' WHERE ' . $column . ' >= ? AND ' . $column . ' < ?';
    };
    $types = '';
    $params = [];
    $periodLabel = $period === 'all' ? 'All time' : ($startDate . ' to ' . $endDate);

    if ($report === 'inventory') {
        $headers = ['Book', 'Author', 'ISBN', 'Category', 'Physical copies', 'Available copies', 'Shelf location', 'Copy status'];
        $rows = report_rows($db, "SELECT b.title, b.author, b.isbn, b.category, COUNT(c.id) AS physical_copies, SUM(c.status = 'Available') AS available_copies, b.shelf_code, c.status AS copy_status FROM books b LEFT JOIN book_copies c ON c.book_id = b.id GROUP BY b.id, c.status ORDER BY b.title, c.status");
    } elseif ($report === 'overdue') {
        $headers = ['Book', 'Barcode', 'Member', 'Role', 'Due date', 'Days overdue'];
        $sql = "SELECT b.title, c.barcode, COALESCE(u.display_name, u.username) AS member, u.role, l.due_at, GREATEST(DATEDIFF(CURDATE(), l.due_at), 0) AS days_overdue FROM loans l JOIN book_copies c ON c.id = l.copy_id JOIN books b ON b.id = c.book_id JOIN users u ON u.id = l.borrower_id WHERE l.status IN ('Active', 'Overdue') AND l.due_at < CURDATE()";
        if ($period !== 'all') {
            $sql .= ' AND l.due_at >= ? AND l.due_at < ?';
            $types = 'ss';
            $params = $windowParams;
        }
        $rows = report_rows($db, $sql . ' ORDER BY l.due_at', $types, $params);
    } elseif ($report === 'circulation') {
        $headers = ['Book', 'Barcode', 'Member', 'Role', 'Borrowed', 'Due date', 'Returned', 'Status', 'Renewals'];
        $where = $dateFilter('l.issued_at');
        $sql = "SELECT b.title, c.barcode, COALESCE(u.display_name, u.username) AS member, u.role, l.issued_at, l.due_at, l.returned_at, l.status, l.renewals FROM loans l JOIN book_copies c ON c.id = l.copy_id JOIN books b ON b.id = c.book_id JOIN users u ON u.id = l.borrower_id" . $where . ' ORDER BY l.issued_at DESC';
        if ($period !== 'all') {
            $types = 'ss';
            $params = $windowParams;
        }
        $rows = report_rows($db, $sql, $types, $params);
    } elseif ($report === 'activity') {
        $headers = ['Member', 'Role', 'Book', 'Borrowed', 'Due date', 'Returned', 'Status'];
        $where = $dateFilter('l.issued_at');
        $sql = "SELECT COALESCE(u.display_name, u.username) AS member, u.role, b.title, l.issued_at, l.due_at, l.returned_at, l.status FROM loans l JOIN book_copies c ON c.id = l.copy_id JOIN books b ON b.id = c.book_id JOIN users u ON u.id = l.borrower_id WHERE u.role IN ('Student', 'Teacher', 'Pastor', 'User')";
        if ($period !== 'all') {
            $sql .= ' AND l.issued_at >= ? AND l.issued_at < ?';
            $types = 'ss';
            $params = $windowParams;
        }
        $rows = report_rows($db, $sql . ' ORDER BY l.issued_at DESC', $types, $params);
    } elseif ($report === 'performance') {
        $headers = ['Librarian', 'Loans issued', 'Returns recorded'];
        $dateStart = $period === 'all' ? '2000-01-01' : $startDate;
        $dateEnd = $period === 'all' ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d', strtotime($endDate . ' +1 day'));
        $rows = report_rows(
            $db,
            "SELECT COALESCE(u.display_name, u.username) AS librarian,
                    (SELECT COUNT(*) FROM loans issued WHERE issued.issued_by = u.id AND issued.issued_at >= ? AND issued.issued_at < ?) AS loans_issued,
                    (SELECT COUNT(*) FROM loans returned WHERE returned.returned_by = u.id AND returned.returned_at >= ? AND returned.returned_at < ?) AS returns_recorded
             FROM users u
             WHERE u.role IN ('Librarian', 'Assistant Librarian')
             ORDER BY loans_issued DESC, returns_recorded DESC, librarian",
            'ssss',
            [$dateStart, $dateEnd, $dateStart, $dateEnd]
        );
    } else {
        $headers = ['Member', 'Reason', 'Assessed amount', 'Outstanding balance', 'Status', 'Assessed date'];
        $where = $dateFilter('f.assessed_at');
        $sql = 'SELECT COALESCE(u.display_name, u.username) AS member, f.reason, f.amount, f.balance, f.status, f.assessed_at FROM fines f JOIN users u ON u.id = f.user_id' . $where . ' ORDER BY f.assessed_at DESC';
        if ($period !== 'all') {
            $types = 'ss';
            $params = $windowParams;
        }
        $rows = report_rows($db, $sql, $types, $params);
    }

    $title = 'SmartLib ' . ucfirst($report) . ' report (' . $periodLabel . ')';
    audit($db, (int) $user['id'], 'report_exported', 'report', $report, $format . ' / ' . $periodLabel);
    $filename = 'smartlib-' . $report . '-' . $period . '-' . date('Y-m-d');
    if ($format === 'pdf') {
        $lines = [$title, 'Generated: ' . date('Y-m-d H:i'), str_repeat('-', 100), implode(' | ', $headers)];
        foreach ($rows as $row) {
            $values = [];
            foreach (array_values($row) as $value) {
                $values[] = str_replace(["\r", "\n"], ' ', (string) ($value ?? ''));
            }
            $lines[] = implode(' | ', $values);
        }
        $pdf = report_pdf($lines);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Unable to open report output.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [$title]);
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, array_map('csv_safe_value', array_values($row)));
        }
        fclose($output);
    }
} catch (Throwable $exception) {
    error_log((string) $exception);
    if (!headers_sent()) {
        http_response_code($exception instanceof RuntimeException ? 400 : 503);
        header('Content-Type: text/plain; charset=utf-8');
    }
    exit($exception instanceof RuntimeException ? $exception->getMessage() : 'The requested report is temporarily unavailable.');
}
