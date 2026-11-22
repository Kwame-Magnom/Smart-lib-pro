<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

try {
    $connection = db();
    $admin = current_user($connection);
    if (!$admin) {
        header('Location: login.php');
        exit;
    }
    require_role($admin, 'Administrator', 'Super Administrator');
    $accounts = $connection->query('SELECT id, username, role, password_hash, created_at FROM users ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    exit('The security report is temporarily unavailable.');
}

function hash_report_details(string $storedHash): array
{
    $details = password_get_info($storedHash);
    $algorithm = (string) ($details['algoName'] ?? 'unknown');
    $cost = $details['options']['cost'] ?? null;
    return [
        'algorithm' => $algorithm,
        'cost' => is_int($cost) ? (string) $cost : 'n/a',
        // A short fingerprint identifies the stored hash without disclosing it.
        'fingerprint' => strtoupper(substr(hash('sha256', $storedHash), 0, 16)),
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security report | Secure Portal</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="dashboard-page">
<header class="topbar">
    <a class="brand" href="dashboard.php"><span class="brand-mark">S</span> Secure Portal</a>
    <div class="topbar-actions"><a class="topbar-link" href="manage_users.php">User roles</a><a class="topbar-link" href="dashboard.php">Dashboard</a></div>
</header>
<main class="dashboard-shell">
    <section class="dashboard-hero compact-hero">
        <div><p class="eyebrow">Administrator</p><h1>Password-security report</h1><p>This report proves password protection without exposing credential hashes or the application pepper.</p></div>
    </section>
    <section class="dashboard-grid report-summary" aria-label="Security configuration summary">
        <article class="dashboard-card"><p class="eyebrow">Salt</p><h2>Unique per password</h2><p class="report-copy">PHP stores a cryptographic random salt inside each password hash.</p></article>
        <article class="dashboard-card"><p class="eyebrow">Pepper</p><h2><?= strlen(APP_PEPPER) >= 32 ? 'Configured' : 'Not configured' ?></h2><p class="report-copy">The secret is kept outside the database and is never displayed.</p></article>
        <article class="dashboard-card"><p class="eyebrow">2FA</p><h2>Email OTP enabled</h2><p class="report-copy">Each sign-in needs a fresh six-digit, one-time verification code.</p></article>
    </section>
    <section class="dashboard-card role-manager">
        <div class="card-heading"><p class="eyebrow">Stored user-hash report</p><h2>Protected password-hash evidence</h2></div>
        <p class="field-hint">The fingerprint is derived from the stored hash and is safe to show. Full hashes are deliberately withheld.</p>
        <div class="role-table-wrap"><table class="role-table"><thead><tr><th>User</th><th>Role</th><th>Hash algorithm</th><th>Cost</th><th>Hash fingerprint</th></tr></thead><tbody>
        <?php foreach ($accounts as $account): $details = hash_report_details((string) $account['password_hash']); ?>
            <tr><td><?= h((string) $account['username']) ?></td><td><span class="role-pill"><?= h((string) $account['role']) ?></span></td><td><?= h($details['algorithm']) ?></td><td><?= h($details['cost']) ?></td><td><code><?= h($details['fingerprint']) ?></code></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
</body>
</html>
