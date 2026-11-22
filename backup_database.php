<?php
declare(strict_types=1);

/**
 * CLI-only database backup job. Run by Windows Task Scheduler; never expose
 * database exports through a web request.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This backup job may only be run from the command line.');
}

require_once __DIR__ . '/config.php';

function backup_fail(string $message): never
{
    fwrite(STDERR, "Backup failed: {$message}\n");
    exit(1);
}

if (!is_file(MYSQLDUMP_PATH)) {
    backup_fail('mysqldump.exe was not found at ' . MYSQLDUMP_PATH);
}
if (!is_dir(BACKUP_DIRECTORY) && !mkdir(BACKUP_DIRECTORY, 0700, true) && !is_dir(BACKUP_DIRECTORY)) {
    backup_fail('could not create backup directory ' . BACKUP_DIRECTORY);
}

$timestamp = (new DateTimeImmutable('now'))->format('Y-m-d_H-i-s');
$destination = rtrim(BACKUP_DIRECTORY, "\\/") . DIRECTORY_SEPARATOR . DB_NAME . "_{$timestamp}.sql";
$temporary = $destination . '.partial';

// MYSQL_PWD is inherited only by mysqldump. It keeps the password out of the
// command line and Task Scheduler history.
$environment = getenv();
if (!is_array($environment)) {
    $environment = [];
}
$environment['MYSQL_PWD'] = DB_PASSWORD;
$command = [
    MYSQLDUMP_PATH,
    '--host=' . DB_HOST,
    '--port=' . DB_PORT,
    '--user=' . DB_USER,
    '--single-transaction',
    '--routines',
    '--events',
    '--databases',
    DB_NAME,
];
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $temporary, 'wb'],
    2 => ['pipe', 'w'],
];
$process = proc_open($command, $descriptors, $pipes, __DIR__, $environment, ['bypass_shell' => true]);
if (!is_resource($process)) {
    backup_fail('could not start mysqldump.');
}
fclose($pipes[0]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0 || !is_file($temporary) || filesize($temporary) === 0) {
    @unlink($temporary);
    backup_fail(trim($errors) ?: 'mysqldump exited with code ' . $exitCode);
}
if (!rename($temporary, $destination)) {
    @unlink($temporary);
    backup_fail('could not finalise the backup file.');
}

$cutoff = time() - (BACKUP_RETENTION_DAYS * 86400);
foreach (glob(rtrim(BACKUP_DIRECTORY, "\\/") . DIRECTORY_SEPARATOR . DB_NAME . '_*.sql') ?: [] as $file) {
    if (is_file($file) && filemtime($file) < $cutoff) {
        @unlink($file);
    }
}

fwrite(STDOUT, 'Backup created: ' . $destination . PHP_EOL);
