<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

try {
    $db = db(); $user = current_user($db);
    if (!$user) { header('Location: login.php'); exit; } 
    require_permission($user, 'members.manage');
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_is_valid()) { throw new RuntimeException('Your session expired. Refresh and try again.'); }
        $username = trim((string) ($_POST['username'] ?? ''));
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $schoolId = trim((string) ($_POST['school_id'] ?? ''));
        $role = (string) ($_POST['role'] ?? 'Student');
        $temporaryPassword = (string) ($_POST['temporary_password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username) || $displayName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($schoolId) > 50 || !in_array($role, ['Student', 'Teacher', 'User', 'Pastor'], true) || strlen($temporaryPassword) < 12) {
            throw new RuntimeException('Enter valid member details and a temporary password of at least 12 characters.');
        }
        $hash = password_hash_secure($temporaryPassword);
        $insert = $db->prepare("INSERT INTO users (username, display_name, email, school_id, password_hash, role, account_status) VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, 'Active')");
        $insert->bind_param('ssssss', $username, $displayName, $email, $schoolId, $hash, $role);
        $insert->execute(); $newId = $db->insert_id; $insert->close();
        audit($db, (int) $user['id'], 'member_registered', 'user', (string) $newId, $role . ': ' . $username);
        $message = 'Member account created. Give the temporary password to the member through a secure channel.';
    }
} catch (mysqli_sql_exception $exception) {
    $message = $exception->getCode() === 1062 ? 'That username, email, or school ID is already in use.' : 'The member could not be created.';
} catch (Throwable $exception) { error_log((string) $exception); $message = $exception->getMessage(); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Register member | SmartLib Pro</title><link rel="stylesheet" href="style.css"></head><body class="dashboard-page"><header class="topbar"><a class="brand" href="librarian_dashboard.php"><span class="brand-mark">SL</span> SmartLib Pro</a><div class="topbar-actions"><a class="topbar-link" href="librarian_dashboard.php">Librarian dashboard</a></div></header><main class="dashboard-shell"><section class="dashboard-hero compact-hero"><div><p class="eyebrow">Members</p><h1>Register a member</h1><p>Create active student, teacher, member, or pastor accounts without granting staff or administrator privileges.</p></div></section><section class="dashboard-card library-form-card"><?php if($message !== ''):?><div class="alert info"><?=h($message)?></div><?php endif;?><form method="post" class="sl-form"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input name="display_name" maxlength="100" required placeholder="Full name"><input name="username" maxlength="50" required placeholder="Username"><input name="email" type="email" maxlength="254" required placeholder="Email address"><input name="school_id" maxlength="50" placeholder="School ID"><select name="role"><option>Student</option><option>Teacher</option><option>User</option><option>Pastor</option></select><input name="temporary_password" type="password" minlength="12" required placeholder="Temporary password (12+ characters)"><button>Create member</button></form></section></main></body></html>
