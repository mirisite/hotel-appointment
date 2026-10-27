<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

if (($_SESSION['user_role'] ?? '') !== 'customer' || !isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$customerId = (int) $_SESSION['user_id'];
$csrfToken = $_SESSION['csrf_token'];
$message = '';
$messageType = 'error';
$databaseAvailable = true;
$pdo = null;
$profileQuery = null;
$profile = [
    'full_name' => (string) ($_SESSION['user_name'] ?? ''),
    'username' => '',
    'email' => (string) ($_SESSION['user_email'] ?? ''),
    'phone' => '',
    'created_at' => null,
    'password_hash' => '',
];

try {
    $pdo = getDatabaseConnection();
    $profileQuery = $pdo->prepare(
        'SELECT full_name, username, email, phone, password_hash, created_at FROM customers WHERE id = :customer_id AND is_active = 1 LIMIT 1'
    );
    $profileQuery->execute(['customer_id' => $customerId]);
    $customerProfile = $profileQuery->fetch();
    if (!$customerProfile) {
        $_SESSION = [];
        session_destroy();
        header('Location: ../login.php');
        exit;
    }
    $profile = $customerProfile;
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
    $message = 'Your profile is unavailable right now. Please try again later.';
    $messageType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseAvailable && $pdo instanceof PDO && $profileQuery instanceof PDOStatement) {
    $nameInput = $_POST['full_name'] ?? '';
    $usernameInput = $_POST['username'] ?? '';
    $emailInput = $_POST['email'] ?? '';
    $phoneInput = $_POST['phone'] ?? '';
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Refresh the page and try again.';
        $messageType = 'error';
    } elseif (!is_string($nameInput) || !is_string($usernameInput) || !is_string($emailInput) || !is_string($phoneInput) || !is_string($currentPassword) || !is_string($newPassword) || !is_string($confirmPassword)) {
        $message = 'Enter valid information in the profile fields.';
        $messageType = 'error';
    } else {
        $nameInput = trim($nameInput);
        $usernameInput = strtolower(trim($usernameInput));
        $emailInput = strtolower(trim($emailInput));
        $phoneInput = trim($phoneInput);
        $phoneDigits = preg_replace('/\D+/', '', $phoneInput) ?? '';
        $phoneIsValid = $phoneInput === '' || (
            preg_match('/^\+?[0-9().\-\s]{7,32}$/', $phoneInput) === 1
            && strlen($phoneDigits) >= 7
            && strlen($phoneDigits) <= 15
        );
        $profile['full_name'] = $nameInput;
        $profile['username'] = $usernameInput;
        $profile['email'] = $emailInput;
        $profile['phone'] = $phoneInput;
        $passwordChangeRequested = $currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '';

        if (!preg_match('/^.{1,120}$/us', $nameInput)) {
            $message = 'Enter your name using up to 120 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $usernameInput)) {
            $message = 'Choose a username with 3 to 30 letters, numbers, dots, underscores, or hyphens.';
        } elseif (!filter_var($emailInput, FILTER_VALIDATE_EMAIL) || strlen($emailInput) > 254) {
            $message = 'Enter a valid email address.';
        } elseif (!$phoneIsValid) {
            $message = 'Enter a valid phone number with 7 to 15 digits, or leave it blank.';
        } elseif ($passwordChangeRequested && ($currentPassword === '' || $newPassword === '' || $confirmPassword === '')) {
            $message = 'Enter your current password and complete both new password fields.';
        } elseif ($passwordChangeRequested && !password_verify($currentPassword, (string) $profile['password_hash'])) {
            $message = 'Your current password is incorrect.';
        } elseif ($passwordChangeRequested && (strlen($newPassword) < 8 || strlen($newPassword) > 72)) {
            $message = 'Choose a new password between 8 and 72 characters.';
        } elseif ($passwordChangeRequested && $newPassword !== $confirmPassword) {
            $message = 'The new password fields do not match.';
        } else {
            try {
                if ($passwordChangeRequested) {
                    $updateProfile = $pdo->prepare(
                        'UPDATE customers SET full_name = :full_name, username = :username, email = :email, phone = :phone, password_hash = :password_hash WHERE id = :customer_id AND is_active = 1'
                    );
                    $parameters = [
                        'full_name' => $nameInput,
                        'username' => $usernameInput,
                        'email' => $emailInput,
                        'phone' => $phoneInput !== '' ? $phoneInput : null,
                        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'customer_id' => $customerId,
                    ];
                } else {
                    $updateProfile = $pdo->prepare(
                        'UPDATE customers SET full_name = :full_name, username = :username, email = :email, phone = :phone WHERE id = :customer_id AND is_active = 1'
                    );
                    $parameters = [
                        'full_name' => $nameInput,
                        'username' => $usernameInput,
                        'email' => $emailInput,
                        'phone' => $phoneInput !== '' ? $phoneInput : null,
                        'customer_id' => $customerId,
                    ];
                }
                $updateProfile->execute($parameters);

                $profileQuery->execute(['customer_id' => $customerId]);
                $profile = $profileQuery->fetch();
                $_SESSION['user_name'] = $profile['full_name'];
                $_SESSION['user_email'] = $profile['email'];
                $message = $passwordChangeRequested ? 'Your profile and password have been updated.' : 'Your profile has been updated.';
                $messageType = 'success';
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $message = 'That username or email address is already in use by another account.';
                } else {
                    error_log($exception->getMessage());
                    $message = 'Your profile could not be saved right now. Please try again.';
                }
                $messageType = 'error';
            }
        }

    }
}

$displayName = htmlspecialchars((string) $profile['full_name'], ENT_QUOTES, 'UTF-8');
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>My Profile | The Queens Hotel</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1120px); margin: auto; padding: 42px clamp(20px, 4vw, 58px) 72px; }
        .eyebrow { margin: 0 0 10px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 8px; color: var(--ink); font-family: Georgia, "Times New Roman", serif; font-size: clamp(34px, 4vw, 46px); font-weight: 400; line-height: 1.1; }
        h2 { margin-bottom: 5px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 23px; font-weight: 400; }
        .intro { display: flex; align-items: end; justify-content: space-between; gap: 18px; margin-bottom: 25px; }
        .intro-copy { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.55; }
        .welcome { color: var(--muted); font-size: 11px; white-space: nowrap; }
        .profile-grid { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(240px, .75fr); align-items: start; gap: 16px; }
        .profile-panel { padding: 22px; border: 1px solid var(--line); background: var(--white); }
        .panel-heading { margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid #edf0eb; }
        .panel-heading p { margin: 0; color: var(--muted); font-size: 11px; line-height: 1.5; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px 12px; }
        .field--wide { grid-column: 1 / -1; }
        .field label { display: block; margin-bottom: 6px; color: #455047; font-size: 10px; font-weight: 700; }
        .control { display: block; width: 100%; min-height: 43px; padding: 10px 11px; border: 1px solid #dce1d9; border-radius: 2px; outline: none; background: #fff; color: var(--ink); font: inherit; font-size: 12px; }
        .control:focus { border-color: var(--green); box-shadow: 0 0 0 3px rgba(23,59,53,.09); }
        .field-hint { margin: 5px 0 0; color: #858d85; font-size: 9px; line-height: 1.45; }
        .section-divider { grid-column: 1 / -1; margin: 5px 0 0; padding-top: 17px; border-top: 1px solid #edf0eb; }
        .section-divider h3 { margin: 0 0 5px; color: var(--green); font-size: 12px; font-weight: 700; }
        .section-divider p { margin: 0; color: var(--muted); font-size: 10px; line-height: 1.5; }
        .actions { display: flex; justify-content: end; margin-top: 19px; }
        .button { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; gap: 8px; padding: 0 16px; border: 1px solid var(--green); border-radius: 2px; background: var(--green); color: #fff; cursor: pointer; font: inherit; font-size: 11px; font-weight: 650; }
        .button:hover { background: #102d29; }
        .button:focus-visible, a:focus-visible { outline: 3px solid rgba(23,59,53,.25); outline-offset: 2px; }
        .message { margin-bottom: 16px; padding: 12px 14px; border-left: 3px solid #557c58; background: #edf4ed; color: #345c3a; font-size: 12px; line-height: 1.5; }
        .message--error { border-color: #a14f43; background: #f8ebe8; color: #79332d; }
        .account-meta { margin: 16px 0 0; color: #929991; font-size: 10px; line-height: 1.6; }
        .security-note { margin: 0; color: var(--muted); font-size: 11px; line-height: 1.65; }
        .security-mark { display: grid; width: 34px; height: 34px; margin-bottom: 14px; place-items: center; border: 1px solid #dfe5dd; color: var(--green); }
        .security-mark svg { width: 19px; height: 19px; }
        @media (max-width: 760px) {
            .page { padding: 28px 15px 42px; }
            .intro { display: block; }
            .welcome { display: inline-block; margin-top: 12px; }
            .profile-grid { grid-template-columns: 1fr; }
            .profile-panel { padding: 17px; }
        }
        @media (max-width: 460px) { .fields { grid-template-columns: 1fr; } .field--wide, .section-divider { grid-column: auto; } .actions .button { width: 100%; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <header class="intro">
            <div>
                <p class="eyebrow">The Queens Hotel · Account</p>
                <h1>My profile</h1>
                <p class="intro-copy">Keep your account information up to date.</p>
            </div>
            <span class="welcome">Signed in as <?= $displayName ?></span>
        </header>

        <?php if ($message !== ''): ?>
            <p class="message<?= $messageType === 'error' ? ' message--error' : '' ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>"><?= $escape($message) ?></p>
        <?php endif; ?>

        <div class="profile-grid">
            <section class="profile-panel" aria-labelledby="profile-details-heading">
                <div class="panel-heading">
                    <h2 id="profile-details-heading">Account details</h2>
                    <p>Edit the name and contact information associated with your bookings.</p>
                </div>
                <form method="post" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <div class="fields">
                        <div class="field field--wide">
                            <label for="full-name">Full name</label>
                            <input class="control" id="full-name" name="full_name" type="text" maxlength="120" autocomplete="name" value="<?= $escape((string) $profile['full_name']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="username">Username</label>
                            <input class="control" id="username" name="username" type="text" minlength="3" maxlength="30" pattern="[A-Za-z0-9_.-]{3,30}" autocomplete="username" value="<?= $escape((string) $profile['username']) ?>" required>
                            <p class="field-hint">3–30 letters, numbers, dots, underscores, or hyphens.</p>
                        </div>
                        <div class="field">
                            <label for="email">Email address</label>
                            <input class="control" id="email" name="email" type="email" maxlength="254" autocomplete="email" value="<?= $escape((string) $profile['email']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="phone">Phone number</label>
                            <input class="control" id="phone" name="phone" type="tel" maxlength="32" autocomplete="tel" value="<?= $escape((string) ($profile['phone'] ?? '')) ?>" placeholder="+63 917 123 4567" aria-describedby="phone-hint">
                            <p class="field-hint" id="phone-hint">Include your country code. This field is optional.</p>
                        </div>

                        <div class="section-divider">
                            <h3>Change password</h3>
                            <p>Leave these fields blank to keep your current password.</p>
                        </div>
                        <div class="field field--wide">
                            <label for="current-password">Current password</label>
                            <input class="control" id="current-password" name="current_password" type="password" autocomplete="current-password">
                        </div>
                        <div class="field">
                            <label for="new-password">New password</label>
                            <input class="control" id="new-password" name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password">
                            <p class="field-hint">Use 8–72 characters.</p>
                        </div>
                        <div class="field">
                            <label for="confirm-password">Confirm new password</label>
                            <input class="control" id="confirm-password" name="confirm_password" type="password" minlength="8" maxlength="72" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="actions"><button class="button" type="submit">Save changes <span aria-hidden="true">&#8594;</span></button></div>
                </form>
                <?php if (!empty($profile['created_at'])): ?>
                    <p class="account-meta">Member since <?= $escape(date('F j, Y', strtotime((string) $profile['created_at']))) ?></p>
                <?php endif; ?>
            </section>

            <aside class="profile-panel" aria-labelledby="security-heading">
                <div class="security-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg></div>
                <h2 id="security-heading">Your account</h2>
                <p class="security-note">Your profile details are used for reservations and booking updates. Password changes require your current password.</p>
            </aside>
        </div>
    </main>
</body>
</html>