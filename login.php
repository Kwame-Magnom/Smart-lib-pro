<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (isset($_GET['restart'])) {
    unset($_SESSION['pending_user_id'], $_SESSION['pending_challenge_id']);
    header('Location: login.php');
    exit;
}

$connection = null;
$message = flash('notice') ?? '';
$keyStatus = key_pair_status();
$isOtpStage = !empty($_SESSION['pending_user_id']) && !empty($_SESSION['pending_challenge_id']);
try {
    $connection = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_is_valid()) {
            $message = 'Your session expired. Refresh the page and try again.';
        } else {
            $action = (string) ($_POST['action'] ?? 'credentials');
            if ($action === 'credentials') {
                $identifier = trim((string) ($_POST['identifier'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $statement = $connection->prepare('SELECT id, username, email, password_hash, role, account_status FROM users WHERE username = ? OR email = ? LIMIT 1');
                $statement->bind_param('ss', $identifier, $identifier);
                $statement->execute();
                $user = $statement->get_result()->fetch_assoc() ?: null;
                $statement->close();

                $validPassword = false;
                if ($user) {
                    $validPassword = password_verify_secure($password, $user['password_hash']);
                    if ($validPassword && password_needs_secure_rehash($user['password_hash'])) {
                        $replacementHash = password_hash_secure($password);
                        $rehash = $connection->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                        $rehash->bind_param('si', $replacementHash, $user['id']);
                        $rehash->execute();
                        $rehash->close();
                    }
                } else {
                    // Perform a password hash operation for unknown accounts too.
                    password_hash_secure($password);
                }
                if (!$user || !$validPassword || $user['account_status'] !== 'Active' || !in_array($user['role'], ['User', 'Pastor', 'Headmaster', 'Administrator', 'Super Administrator', 'Librarian', 'Assistant Librarian', 'Teacher', 'Student', 'Accountant', 'IT Support'], true)) {
                    audit($connection, $user ? (int) $user['id'] : null, 'login_failed', 'user', $user ? (string) $user['id'] : null, 'Invalid credentials or inactive account');
                    $message = 'The username/email or password is incorrect.';
                } else {
                    session_regenerate_id(true);
                    issue_login_otp($connection, $user);
                    $isOtpStage = true;
                    $message = 'A fresh 6-digit sign-in code was sent to your registered email address.';
                }
            } elseif ($action === 'verify_otp' && $isOtpStage) {
                $otp = trim((string) ($_POST['otp'] ?? ''));
                $challengeId = (int) $_SESSION['pending_challenge_id'];
                $userId = (int) $_SESSION['pending_user_id'];
                $statement = $connection->prepare('SELECT id, code_hash, attempts FROM login_otps WHERE id = ? AND user_id = ? AND consumed_at IS NULL AND expires_at > NOW() LIMIT 1');
                $statement->bind_param('ii', $challengeId, $userId);
                $statement->execute();
                $challenge = $statement->get_result()->fetch_assoc() ?: null;
                $statement->close();

                if (!$challenge || (int) $challenge['attempts'] >= 5) {
                    unset($_SESSION['pending_user_id'], $_SESSION['pending_challenge_id']);
                    $isOtpStage = false;
                    $message = 'That code has expired or too many attempts were made. Sign in again to request a new code.';
                } else {
                    $attempt = $connection->prepare('UPDATE login_otps SET attempts = attempts + 1 WHERE id = ?');
                    $attempt->bind_param('i', $challengeId);
                    $attempt->execute();
                    $attempt->close();
                    if (!preg_match('/^\d{6}$/', $otp) || !hash_equals($challenge['code_hash'], otp_digest($otp))) {
                        $message = 'The verification code is incorrect. Check your email and try again.';
                    } else {
                        $consume = $connection->prepare('UPDATE login_otps SET consumed_at = NOW() WHERE id = ? AND consumed_at IS NULL');
                        $consume->bind_param('i', $challengeId);
                        $consume->execute();
                        $consume->close();
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['authenticated_at'] = time();
                        $_SESSION['last_activity'] = time();
                        $loginUpdate = $connection->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
                        $loginUpdate->bind_param('i', $userId);
                        $loginUpdate->execute();
                        $loginUpdate->close();
                        audit($connection, $userId, 'login_success', 'user', (string) $userId, 'OTP verified');
                        unset($_SESSION['pending_user_id'], $_SESSION['pending_challenge_id']);
                        header('Location: dashboard.php');
                        exit;
                    }
                }
            } elseif ($action === 'resend_otp' && $isOtpStage) {
                $challengeId = (int) $_SESSION['pending_challenge_id'];
                $check = $connection->prepare('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM login_otps WHERE id = ?');
                $check->bind_param('i', $challengeId);
                $check->execute();
                $challengeAge = $check->get_result()->fetch_assoc();
                $check->close();
                if ($challengeAge && (int) $challengeAge['age'] < 60) {
                    $message = 'Please wait 60 seconds before requesting another code.';
                } else {
                    $userId = (int) $_SESSION['pending_user_id'];
                    $lookup = $connection->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
                    $lookup->bind_param('i', $userId);
                    $lookup->execute();
                    $user = $lookup->get_result()->fetch_assoc();
                    $lookup->close();
                    if (!$user) {
                        unset($_SESSION['pending_user_id'], $_SESSION['pending_challenge_id']);
                        $isOtpStage = false;
                        $message = 'Sign in again to continue.';
                    } else {
                        issue_login_otp($connection, $user);
                        $message = 'A new sign-in code was sent to your registered email address.';
                    }
                }
            } else {
                $message = 'That sign-in step is no longer valid. Start again.';
            }
        }
    }
} catch (Throwable $exception) {
    error_log((string) $exception);
    if ($exception instanceof RuntimeException && strpos($exception->getMessage(), 'APP_PEPPER') !== false) {
        $message = $exception->getMessage();
    } elseif ($exception instanceof RuntimeException && strpos($exception->getMessage(), 'Email delivery') !== false) {
        $message = $exception->getMessage();
    } elseif ($exception instanceof mysqli_sql_exception) {
        $message = 'The library database could not complete sign-in. Verify the private database settings and database permissions.';
    } else {
        $message = 'Sign-in could not be completed. Check the server error log or contact the administrator.';
    }
}
$isOtpStage = !empty($_SESSION['pending_user_id']) && !empty($_SESSION['pending_challenge_id']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="auth-layout">
    <section class="intro-panel">
        <a class="brand" href="index.php"><span class="brand-mark">SL</span> SmartLib Pro</a>
        <div class="intro-copy">
            <p class="eyebrow">Welcome back</p>
            <h1>Your library,<br>securely connected.</h1>
            <p>Sign in to manage your school library with password and email verification.</p>
        </div>
        <div class="security-note"><span aria-hidden="true">✦</span> Codes expire after 10 minutes and work once.</div>
        <div class="security-note key-status"><span aria-hidden="true">&#128273;</span><?php if ($keyStatus['configured']): ?> Server key pair configured. Public key ID: <?= h($keyStatus['fingerprint']) ?>. <a href="public_key.php" target="_blank" rel="noopener">View public key</a>. Private key protected.<?php else: ?> Server key pair is unavailable.<?php endif; ?></div>
    </section>
    <section class="form-panel">
        <div class="form-wrap">
            <p class="eyebrow"><?= $isOtpStage ? 'One more step' : 'Secure sign in' ?></p>
            <h2><?= $isOtpStage ? 'Check your email' : 'Sign in to your account' ?></h2>
            <p class="form-subtitle"><?= $isOtpStage ? 'Enter the 6-digit code we just sent you.' : 'Enter your username or email and password to receive a sign-in code.' ?></p>
            <?php if ($message !== ''): ?><div class="alert <?= strpos($message, 'incorrect') !== false || strpos($message, 'expired') !== false || strpos($message, 'unavailable') !== false || strpos($message, 'wait') !== false ? 'error' : 'info' ?>" role="status"><?= h($message) ?></div><?php endif; ?>
            <?php if ($isOtpStage): ?>
                <form method="post" action="login.php" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="verify_otp">
                    <label for="otp">Email verification code</label>
                    <input id="otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus>
                    <p class="field-hint">For your security, the code expires in 10 minutes and can only be used once.</p>
                    <button class="button" type="submit">Verify and continue <span aria-hidden="true">→</span></button>
                </form>
                <form class="resend-form" method="post" action="login.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="resend_otp">
                    <button class="text-button" type="submit">Send a new code</button>
                </form>
            <?php else: ?>
                <form method="post" action="login.php" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="credentials">
                    <label for="identifier">Username or email</label>
                    <input id="identifier" name="identifier" type="text" autocomplete="username" required>
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <button class="button" type="submit">Continue with email code <span aria-hidden="true">→</span></button>
                </form>
            <?php endif; ?>
            <p class="switch-link"><?= $isOtpStage ? 'Wrong account?' : 'New here?' ?> <a href="<?= $isOtpStage ? 'login.php?restart=1' : 'register.php' ?>"><?= $isOtpStage ? 'Start over' : 'Create an account' ?></a></p>
        </div>
    </section>
</main>
</body>
</html>
