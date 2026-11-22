<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$message = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $message = 'Your session expired. Refresh the page and try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $message = 'Use 3–50 letters, numbers, dots, dashes, or underscores for your username.';
        } elseif (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Enter a valid email address.';
        } elseif (strlen($password) < 12) {
            $message = 'Your password must contain at least 12 characters.';
        } else {
            try {
                $connection = db();
                $hash = password_hash_secure($password);
                $role = 'User';
                $displayName = $username;
                $statement = $connection->prepare("INSERT INTO users (username, display_name, email, password_hash, role, account_status) VALUES (?, ?, ?, ?, ?, 'Active')");
                $statement->bind_param('sssss', $username, $displayName, $email, $hash, $role);
                $statement->execute();
                $statement->close();
                flash('notice', 'Your account is ready. Sign in with your username or email and password; we will email a fresh one-time code.');
                header('Location: login.php');
                exit;
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() === 1062) {
                    $message = 'That username or email address is already registered.';
                } else {
                    error_log((string) $exception);
                    $message = in_array((int) $exception->getCode(), [1044, 1045, 1049, 2002, 2003, 2005], true)
                        ? 'Registration cannot connect to the library database. Check the database settings in config.local.php.'
                        : 'Registration could not be saved. Check the hosting PHP error log or contact the site administrator.';
                }
            } catch (Throwable $exception) {
                error_log((string) $exception);
                $message = $exception instanceof RuntimeException ? $exception->getMessage() : 'Registration could not be completed.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account | SmartLib Pro</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="auth-layout">
    <section class="intro-panel">
        <a class="brand" href="index.php"><span class="brand-mark">SL</span> SmartLib Pro</a>
        <div class="intro-copy">
            <p class="eyebrow">SmartLib Pro account</p>
            <h1>Your library account,<br>protected at every step.</h1>
            <p>Create a member account. Each sign-in is confirmed with a new code sent to your email.</p>
        </div>
        <div class="security-note"><span aria-hidden="true">✦</span> Passwords are stored with a one-way password hash.</div>
    </section>
    <section class="form-panel">
        <div class="form-wrap">
            <p class="eyebrow">Get started</p>
            <h2>Create your account</h2>
            <p class="form-subtitle">Use your details to register. New accounts receive standard member access.</p>
            <?php if ($message !== ''): ?><div class="alert <?= $success ? 'success' : 'error' ?>" role="alert"><?= h($message) ?></div><?php endif; ?>
            <form method="post" action="register.php" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" minlength="3" maxlength="50" autocomplete="username" required value="<?= h((string) ($_POST['username'] ?? '')) ?>">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" maxlength="254" autocomplete="email" required value="<?= h((string) ($_POST['email'] ?? '')) ?>">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>
                <p class="field-hint">Use at least 12 characters.</p>
                <button class="button" type="submit">Create account <span aria-hidden="true">→</span></button>
            </form>
            <p class="switch-link">Already registered? <a href="login.php">Sign in</a></p>
        </div>
    </section>
</main>
</body>
</html>
