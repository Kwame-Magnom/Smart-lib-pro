<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): mysqli
{
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = mysqli_init();
    if (!($connection instanceof mysqli)) {
        throw new RuntimeException('Unable to initialize the MySQL connection.');
    }

    if (DB_SSL_CA !== '') {
        if (!is_file(DB_SSL_CA) || !is_readable(DB_SSL_CA)) {
            throw new RuntimeException('DB_SSL_CA must point to a readable CA certificate file.');
        }
        if (!defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT') || !defined('MYSQLI_CLIENT_SSL')) {
            throw new RuntimeException('The PHP MySQL driver does not support verified TLS connections.');
        }
        if (!$connection->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true)) {
            throw new RuntimeException('This PHP MySQL driver cannot verify the database certificate. Upgrade to a PHP build with verified TLS support.');
        }
        $connection->ssl_set(null, null, DB_SSL_CA, null, null);
        try {
            $connection->real_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT, null, MYSQLI_CLIENT_SSL);
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1049) {
                $connection->real_connect(DB_HOST, DB_USER, DB_PASSWORD, null, DB_PORT, null, MYSQLI_CLIENT_SSL);
                $escapedDb = str_replace('`', '``', DB_NAME);
                $connection->query("CREATE DATABASE IF NOT EXISTS `{$escapedDb}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $connection->select_db(DB_NAME);
            } else {
                throw $exception;
            }
        }
    } else {
        try {
            $connection->real_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1049) {
                $connection->real_connect(DB_HOST, DB_USER, DB_PASSWORD, null, DB_PORT);
                $escapedDb = str_replace('`', '``', DB_NAME);
                $connection->query("CREATE DATABASE IF NOT EXISTS `{$escapedDb}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $connection->select_db(DB_NAME);
            } else {
                throw $exception;
            }
        }
    }
    $connection->set_charset('utf8mb4');

    $connection->query("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        display_name VARCHAR(100) NULL,
        email VARCHAR(254) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'User',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Upgrade the earlier TOTP demo schema without discarding existing records.
    $columns = [];
    $result = $connection->query('SHOW COLUMNS FROM users');
    while ($column = $result->fetch_assoc()) {
        $columns[$column['Field']] = strtolower((string) $column['Type']);
    }
    if (!isset($columns['email'])) {
        $connection->query('ALTER TABLE users ADD COLUMN email VARCHAR(254) NULL AFTER username');
    }
    if (!isset($columns['display_name'])) {
        $connection->query('ALTER TABLE users ADD COLUMN display_name VARCHAR(100) NULL AFTER username');
    }
    if (($columns['password_hash'] ?? '') !== 'varchar(255)') {
        $connection->query('ALTER TABLE users MODIFY password_hash VARCHAR(255) NOT NULL');
    }
    if (!isset($columns['role'])) {
        $connection->query("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'User'");
    }
    // Preserve accounts created by the earlier portal while using the library roles.
    $connection->query("UPDATE users SET role = 'Administrator' WHERE role = 'Admin'");
    if (!isset($columns['created_at'])) {
        $connection->query('ALTER TABLE users ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
    if (!isset($columns['school_id'])) {
        $connection->query('ALTER TABLE users ADD COLUMN school_id VARCHAR(50) NULL UNIQUE AFTER email');
    }
    if (!isset($columns['account_status'])) {
        $connection->query("ALTER TABLE users ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER role");
    }
    if (!isset($columns['last_login_at'])) {
        $connection->query('ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER account_status');
    }
    if (isset($columns['salt'])) {
        $connection->query('ALTER TABLE users MODIFY salt CHAR(32) NULL');
    }
    if (isset($columns['two_factor_secret'])) {
        $connection->query('ALTER TABLE users MODIFY two_factor_secret VARCHAR(64) NULL');
    }

    $indexResult = $connection->query("SHOW INDEX FROM users WHERE Column_name = 'email' AND Non_unique = 0");
    if ($indexResult->num_rows === 0) {
        $connection->query('ALTER TABLE users ADD UNIQUE INDEX uq_users_email (email)');
    }

    $connection->query("CREATE TABLE IF NOT EXISTS login_otps (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        code_hash CHAR(64) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_otps_user (user_id),
        CONSTRAINT fk_login_otps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $connection->query("CREATE TABLE IF NOT EXISTS mobile_login_challenges (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        otp_id BIGINT UNSIGNED NOT NULL,
        challenge_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mobile_login_challenges_user (user_id, created_at),
        CONSTRAINT fk_mobile_login_challenges_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_mobile_login_challenges_otp FOREIGN KEY (otp_id) REFERENCES login_otps(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $connection->query("CREATE TABLE IF NOT EXISTS mobile_api_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mobile_api_tokens_user (user_id, expires_at),
        CONSTRAINT fk_mobile_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $connection->query("CREATE TABLE IF NOT EXISTS books (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        author VARCHAR(140) NOT NULL,
        isbn VARCHAR(32) NULL UNIQUE,
        category VARCHAR(80) NOT NULL DEFAULT 'General',
        total_copies SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        available_copies SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CHECK (available_copies <= total_copies)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $connection->query("CREATE TABLE IF NOT EXISTS book_loans (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        book_id INT UNSIGNED NOT NULL,
        borrower_id INT UNSIGNED NOT NULL,
        issued_by INT UNSIGNED NOT NULL,
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        due_at DATE NOT NULL,
        returned_at DATETIME NULL,
        returned_by INT UNSIGNED NULL,
        INDEX idx_book_loans_borrower (borrower_id),
        INDEX idx_book_loans_book_active (book_id, returned_at),
        CONSTRAINT fk_book_loans_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
        CONSTRAINT fk_book_loans_borrower FOREIGN KEY (borrower_id) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_book_loans_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_book_loans_returner FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // SmartLib Pro normalized library entities. Existing portal records remain intact.
    $connection->query("CREATE TABLE IF NOT EXISTS roles (
        id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(40) NOT NULL UNIQUE,
        description VARCHAR(180) NOT NULL,
        is_privileged TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS book_acquisition_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        book_id INT UNSIGNED NOT NULL,
        requested_by INT UNSIGNED NOT NULL,
        request_note VARCHAR(300) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        reviewed_by INT UNSIGNED NULL,
        review_note VARCHAR(300) NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL,
        INDEX idx_acquisition_requests_status (status, requested_at),
        INDEX idx_acquisition_requests_user (requested_by, requested_at),
        CONSTRAINT fk_acquisition_requests_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
        CONSTRAINT fk_acquisition_requests_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_acquisition_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("INSERT IGNORE INTO roles (name, description, is_privileged) VALUES
        ('Super Administrator', 'Full system access and security administration', 1),
        ('Administrator', 'Library and account administration', 1),
        ('Headmaster', 'Read-only leadership and authorized reporting', 0),
        ('Librarian', 'Catalogue, circulation, and member operations', 0),
        ('Assistant Librarian', 'Assigned circulation assistance', 0),
        ('Teacher', 'Teacher library member', 0),
        ('Student', 'Student library member', 0),
        ('Accountant', 'Fine and payment operations', 0),
        ('IT Support', 'Technical support and audit visibility', 0),
        ('Pastor', 'Staff library member', 0),
        ('User', 'Standard library member', 0)");

    $connection->query("CREATE TABLE IF NOT EXISTS categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS authors (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(140) NOT NULL UNIQUE,
        biography TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS publishers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(140) NOT NULL UNIQUE,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS courses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_code VARCHAR(40) NOT NULL UNIQUE,
        name VARCHAR(140) NOT NULL,
        description VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_courses_status_name (status, name),
        CONSTRAINT fk_courses_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS course_books (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        book_id INT UNSIGNED NOT NULL,
        added_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_course_books (course_id, book_id),
        INDEX idx_course_books_book (book_id),
        CONSTRAINT fk_course_books_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        CONSTRAINT fk_course_books_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
        CONSTRAINT fk_course_books_adder FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS course_book_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_book_id BIGINT UNSIGNED NOT NULL,
        requested_by INT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        request_note VARCHAR(300) NULL,
        reviewed_by INT UNSIGNED NULL,
        review_note VARCHAR(300) NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL,
        INDEX idx_course_book_requests_status (status, requested_at),
        INDEX idx_course_book_requests_user (requested_by, requested_at),
        CONSTRAINT fk_course_book_requests_item FOREIGN KEY (course_book_id) REFERENCES course_books(id) ON DELETE RESTRICT,
        CONSTRAINT fk_course_book_requests_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_course_book_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $bookColumns = [];
    $bookResult = $connection->query('SHOW COLUMNS FROM books');
    while ($column = $bookResult->fetch_assoc()) {
        $bookColumns[$column['Field']] = true;
    }
    $bookUpgrades = [
        'publisher_id' => 'ALTER TABLE books ADD COLUMN publisher_id INT UNSIGNED NULL AFTER author',
        'publication_year' => 'ALTER TABLE books ADD COLUMN publication_year SMALLINT UNSIGNED NULL AFTER publisher_id',
        'edition' => 'ALTER TABLE books ADD COLUMN edition VARCHAR(50) NULL AFTER publication_year',
        'language' => "ALTER TABLE books ADD COLUMN language VARCHAR(50) NOT NULL DEFAULT 'English' AFTER category",
        'description' => 'ALTER TABLE books ADD COLUMN description TEXT NULL AFTER language',
        'cover_image_url' => 'ALTER TABLE books ADD COLUMN cover_image_url VARCHAR(500) NULL AFTER description',
        'shelf_code' => 'ALTER TABLE books ADD COLUMN shelf_code VARCHAR(50) NULL AFTER description',
        'acquisition_cost' => 'ALTER TABLE books ADD COLUMN acquisition_cost DECIMAL(10,2) NULL AFTER shelf_code',
        'book_status' => "ALTER TABLE books ADD COLUMN book_status VARCHAR(20) NOT NULL DEFAULT 'Active' AFTER acquisition_cost",
        'updated_at' => 'ALTER TABLE books ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    ];
    foreach ($bookUpgrades as $name => $sql) {
        if (!isset($bookColumns[$name])) {
            $connection->query($sql);
        }
    }
    $bookChecks = $connection->query(
        "SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
         FROM information_schema.TABLE_CONSTRAINTS tc
         JOIN information_schema.CHECK_CONSTRAINTS cc
           ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
          AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
         WHERE tc.TABLE_SCHEMA = DATABASE()
           AND tc.TABLE_NAME = 'books'
           AND tc.CONSTRAINT_TYPE = 'CHECK'"
    );
    while ($check = $bookChecks->fetch_assoc()) {
        $checkClause = strtolower(str_replace('`', '', (string) $check['CHECK_CLAUSE']));
        if (strpos($checkClause, 'total_copies > 0') !== false) {
            $constraint = str_replace('`', '``', (string) $check['CONSTRAINT_NAME']);
            $connection->query('ALTER TABLE books DROP CONSTRAINT `' . $constraint . '`');
        }
    }
    $connection->query("CREATE TABLE IF NOT EXISTS book_copies (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        book_id INT UNSIGNED NOT NULL,
        barcode VARCHAR(80) NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'Available',
        condition_note VARCHAR(100) NOT NULL DEFAULT 'Good',
        acquired_at DATE NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_book_copies_available (book_id, status),
        CONSTRAINT fk_book_copies_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Upgrade catalogue entries created before individual-copy tracking existed.
    $legacyBooks = $connection->query('SELECT id, total_copies FROM books');
    while ($legacyBook = $legacyBooks->fetch_assoc()) {
        $bookId = (int) $legacyBook['id'];
        $existingCopies = $connection->prepare('SELECT COUNT(*) AS total FROM book_copies WHERE book_id = ?');
        $existingCopies->bind_param('i', $bookId);
        $existingCopies->execute();
        $count = (int) $existingCopies->get_result()->fetch_assoc()['total'];
        $existingCopies->close();
        $target = (int) $legacyBook['total_copies'];
        if ($count < $target) {
            $createCopy = $connection->prepare("INSERT INTO book_copies (book_id, barcode, status) VALUES (?, ?, 'Available')");
            for ($number = $count + 1; $number <= $target; $number++) {
                $barcode = 'MIG-' . $bookId . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
                $createCopy->bind_param('is', $bookId, $barcode);
                $createCopy->execute();
            }
            $createCopy->close();
        }
    }
    $connection->query("CREATE TABLE IF NOT EXISTS loans (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        copy_id BIGINT UNSIGNED NOT NULL,
        borrower_id INT UNSIGNED NOT NULL,
        issued_by INT UNSIGNED NOT NULL,
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        due_at DATE NOT NULL,
        returned_at DATETIME NULL,
        returned_by INT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active',
        renewals TINYINT UNSIGNED NOT NULL DEFAULT 0,
        notes VARCHAR(255) NULL,
        INDEX idx_loans_borrower_active (borrower_id, status),
        INDEX idx_loans_due (due_at, status),
        CONSTRAINT fk_loans_copy FOREIGN KEY (copy_id) REFERENCES book_copies(id) ON DELETE RESTRICT,
        CONSTRAINT fk_loans_borrower FOREIGN KEY (borrower_id) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_loans_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_loans_returner FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS reservations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        book_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        status VARCHAR(25) NOT NULL DEFAULT 'Pending',
        reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ready_at DATETIME NULL,
        expires_at DATETIME NULL,
        fulfilled_at DATETIME NULL,
        INDEX idx_reservations_book_status (book_id, status, reserved_at),
        CONSTRAINT fk_reservations_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
        CONSTRAINT fk_reservations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS fines (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        loan_id BIGINT UNSIGNED NULL,
        amount DECIMAL(10,2) NOT NULL,
        balance DECIMAL(10,2) NOT NULL,
        reason VARCHAR(180) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Outstanding',
        assessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        waived_by INT UNSIGNED NULL,
        INDEX idx_fines_user_status (user_id, status),
        CONSTRAINT fk_fines_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_fines_loan FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE RESTRICT,
        CONSTRAINT fk_fines_waiver FOREIGN KEY (waived_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS payments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        fine_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        method VARCHAR(30) NOT NULL,
        reference_code VARCHAR(80) NOT NULL UNIQUE,
        receipt_number VARCHAR(80) NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'Completed',
        paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        recorded_by INT UNSIGNED NOT NULL,
        INDEX idx_payments_fine (fine_id),
        CONSTRAINT fk_payments_fine FOREIGN KEY (fine_id) REFERENCES fines(id) ON DELETE RESTRICT,
        CONSTRAINT fk_payments_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        type VARCHAR(40) NOT NULL,
        message VARCHAR(255) NOT NULL,
        link_url VARCHAR(255) NULL,
        read_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notifications_user_read (user_id, read_at),
        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS approval_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        requested_by INT UNSIGNED NOT NULL,
        request_type VARCHAR(60) NOT NULL,
        reason VARCHAR(450) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        reviewed_by INT UNSIGNED NULL,
        review_note VARCHAR(450) NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL,
        INDEX idx_approval_requests_status_date (status, requested_at),
        CONSTRAINT fk_approval_requests_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
        CONSTRAINT fk_approval_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(50) NOT NULL,
        entity_id VARCHAR(50) NULL,
        details VARCHAR(500) NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_created (created_at),
        INDEX idx_audit_user (user_id),
        CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("CREATE TABLE IF NOT EXISTS library_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $connection->query("INSERT IGNORE INTO library_settings (setting_key, setting_value) VALUES
        ('student_loan_days', '14'), ('teacher_loan_days', '28'), ('staff_loan_days', '21'),
        ('student_loan_limit', '3'), ('teacher_loan_limit', '8'), ('staff_loan_limit', '5'),
        ('daily_fine_rate', '1.00'), ('fine_grace_days', '0'), ('maximum_fine', '100.00'), ('reservation_hold_days', '3')");

    return $connection;
}
