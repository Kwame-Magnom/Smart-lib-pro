<?php
declare(strict_types=1);

/** Restore a database-only backup. Application configuration is never read from
 * nor written to by this command. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This restore command may only be run from the command line.');
}
require_once __DIR__ . '/config.php';

function restore_fail(string $message): never { fwrite(STDERR, "Restore failed: {$message}\n"); exit(1); }

$options = getopt('', ['file:', 'confirm-restore']);
if (!isset($options['file'], $options['confirm-restore'])) {
    restore_fail('use --file="C:\\xampp\\backups\\secure-portal\\backup.sql" --confirm-restore');
}
if (!is_file(MYSQL_PATH)) {
    restore_fail('mysql.exe was not found at ' . MYSQL_PATH);
}
$directory = realpath(BACKUP_DIRECTORY);
$backup = realpath((string) $options['file']);
if ($directory === false || $backup === false || pathinfo($backup, PATHINFO_EXTENSION) !== 'sql' || strpos($backup, $directory . DIRECTORY_SEPARATOR) !== 0) {
    restore_fail('the backup must be an existing .sql file inside ' . BACKUP_DIRECTORY);
}

$environment = getenv();
if (!is_array($environment)) { $environment = []; }
$environment['MYSQL_PWD'] = DB_PASSWORD;
$command = [MYSQL_PATH, '--host=' . DB_HOST, '--port=' . DB_PORT, '--user=' . DB_USER];
$descriptors = [0 => ['file', $backup, 'rb'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($command, $descriptors, $pipes, __DIR__, $environment, ['bypass_shell' => true]);
if (!is_resource($process)) { restore_fail('could not start mysql.'); }
fclose($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exitCode = proc_close($process);
if ($exitCode !== 0) { restore_fail(trim($errors) ?: 'mysql exited with code ' . $exitCode); }
fwrite(STDOUT, "Database restored. Configuration and scheduled tasks were not changed.\n");
