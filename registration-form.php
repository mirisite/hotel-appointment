<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (!isset($registrationRole) || !in_array($registrationRole, ['customer', 'management'], true)) {
    http_response_code(404);
    exit;
}

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$name = '';
$username = '';
$email = '';
$message = '';
$success = false;
$roleTitle = $registrationRole === 'management' ? 'Management' : 'Customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Please refresh the page and try again.';
    } elseif (!is_string($name) || !is_string($username) || !is_string($email) || !is_string($password)) {
        $message = 'Complete all fields with valid information.';
    } else {
        $name = trim($name);
        $username = strtolower(trim($username));
        $email = strtolower(trim($email));

        if ($name === '' || strlen($name) > 360) {
            $message = 'Enter your name (up to 120 characters).';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
            $message = 'Choose a username with 3 to 30 letters, numbers, dots, underscores, or hyphens.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $message = 'Enter a valid email address.';
        } elseif (strlen($password) < 8 || strlen($password) > 72) {
            $message = 'Choose a password between 8 and 72 characters.';
        } else {
            try {
                $pdo = getDatabaseConnection();

                $accountTable = $registrationRole === 'management' ? 'management_users' : 'customers';
                $statement = $pdo->prepare(
                    "INSERT INTO {$accountTable} (full_name, username, email, password_hash) VALUES (:name, :username, :email, :password_hash)"
                );
                $statement->execute([
                    'name' => $name,
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $success = true;
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $message = 'That username or email address is already registered.';
                } else {
                    error_log($exception->getMessage());
                    $message = 'Account creation is temporarily unavailable. Please try again shortly.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173b35">
    <title>Create <?= htmlspecialchars($roleTitle, ENT_QUOTES, 'UTF-8') ?> Account | The Queens Hotel</title>
    <style>
        :root {
            --forest: #173b35;
            --paper: #fbfaf7;
            --ink: #202923;
            --muted: #727a73;
            --line: #dfe3dc;
            --danger: #9e3e36;
        }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { display: grid; min-height: 100vh; grid-template-columns: minmax(310px, 0.85fr) minmax(480px, 1.15fr); }
        .visual { display: flex; min-height: 660px; flex-direction: column; justify-content: space-between; padding: 42px clamp(30px, 5vw, 70px); background-color: var(--forest); background-image: linear-gradient(180deg, rgba(10, 34, 30, 0.15), rgba(10, 34, 30, 0.84)), url("https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1300&q=85"); background-position: center; background-size: cover; color: #fff; }
        .brand { display: inline-flex; width: fit-content; align-items: center; gap: 11px; color: white; font-size: 14px; font-weight: 700; letter-spacing: 0.08em; text-decoration: none; text-transform: uppercase; }
        .brand-mark { display: grid; width: 34px; aspect-ratio: 1; place-items: center; border: 1px solid rgba(255,255,255,.75); color: #f3d9ae; font-family: Georgia, serif; font-size: 19px; font-weight: 400; }
        .visual-copy { max-width: 410px; }
        .eyebrow { margin: 0 0 12px; color: #dfc18f; font-size: 11px; font-weight: 700; letter-spacing: 0.17em; text-transform: uppercase; }
        .visual h1 { margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: clamp(40px, 4.7vw, 62px); font-weight: 400; line-height: 1.05; }
        .visual-copy p:last-child { max-width: 340px; margin: 18px 0 0; color: rgba(255,255,255,.78); font-size: 14px; line-height: 1.7; }
        .visual-footer { color: rgba(255,255,255,.72); font-size: 12px; }
        .main { display: grid; min-height: 100vh; place-items: center; padding: 44px 34px; }
        .form-wrap { width: min(100%, 440px); animation: arrive 550ms ease-out both; }
        .mobile-brand { display: none; }
        .heading { margin-bottom: 26px; }
        .heading .eyebrow { color: #8b7049; }
        .heading h2 { margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: 36px; font-weight: 400; line-height: 1.15; }
        .heading p:last-child { margin: 10px 0 0; color: var(--muted); font-size: 14px; line-height: 1.55; }
        .field { margin-bottom: 17px; }
        .field label { display: block; margin-bottom: 8px; color: #3c463e; font-size: 12px; font-weight: 700; }
        .input { display: block; width: 100%; height: 48px; padding: 0 13px; border: 1px solid #d9ded6; border-radius: 2px; outline: none; background: rgba(255,255,255,.8); color: var(--ink); font: inherit; font-size: 14px; transition: border-color 160ms ease, box-shadow 160ms ease; }
        .input:focus { border-color: var(--forest); box-shadow: 0 0 0 3px rgba(23,59,53,.1); }
        .input::placeholder { color: #9aa098; }
        .notice { margin: 0 0 18px; padding: 12px 14px; border-left: 3px solid var(--danger); background: #f8ebe8; color: #79332d; font-size: 13px; line-height: 1.5; }
        .submit { display: flex; width: 100%; min-height: 50px; align-items: center; justify-content: center; gap: 9px; margin-top: 8px; border: 1px solid var(--forest); border-radius: 2px; background: var(--forest); color: #fff; cursor: pointer; font: inherit; font-size: 14px; font-weight: 650; transition: background-color 160ms ease, transform 160ms ease; }
        .submit:hover:not(:disabled) { transform: translateY(-1px); background: #102d29; }
        .submit:focus-visible, a:focus-visible { outline: 3px solid rgba(23,59,53,.25); outline-offset: 3px; }
        .footnote { margin: 20px 0 0; color: #858c84; font-size: 12px; line-height: 1.6; text-align: center; }
        .footnote a, .success a { color: var(--forest); font-weight: 650; text-underline-offset: 3px; }
        .success { padding: 20px 0; }
        .success h2 { margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: 36px; font-weight: 400; }
        .success p { margin: 12px 0 20px; color: var(--muted); line-height: 1.6; }
        @keyframes arrive { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 760px) {
            .page { display: block; }
            .visual { min-height: 210px; padding: 25px 26px; background-position: center 58%; }
            .visual-copy { display: none; }
            .visual-footer { display: none; }
            .main { min-height: calc(100vh - 210px); padding: 36px 24px; }
            .form-wrap { max-width: 440px; }
            .mobile-brand { display: none; }
        }
        @media (max-width: 420px) {
            .main { padding: 30px 20px; }
            .heading h2, .success h2 { font-size: 32px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
    </style>
</head>
<body>
    <div class="page">
        <aside class="visual" aria-label="The Queens Hotel">
            <a class="brand" href="login.php"><span class="brand-mark" aria-hidden="true">Q</span><span>The Queens Hotel</span></a>
            <div class="visual-copy">
                <p class="eyebrow">Your stay, thoughtfully managed</p>
                <h1>Make room for something memorable.</h1>
                <p>Create your account to get started with The Queens Hotel.</p>
            </div>
            <div class="visual-footer">A considered kind of stay.</div>
        </aside>
        <main class="main">
            <section class="form-wrap" aria-labelledby="form-title">
                <?php if ($success): ?>
                    <div class="success" role="status">
                        <p class="eyebrow">Account created</p>
                        <h2 id="form-title">Welcome to The Queens Hotel.</h2>
                        <p>Your <?= htmlspecialchars(strtolower($roleTitle), ENT_QUOTES, 'UTF-8') ?> account is ready. Sign in with your email address or username.</p>
                        <a href="login.php">Continue to sign in</a>
                    </div>
                <?php else: ?>
                    <header class="heading">
                        <p class="eyebrow">The Queens Hotel · <?= htmlspecialchars($roleTitle, ENT_QUOTES, 'UTF-8') ?></p>
                        <h2 id="form-title">Create your account</h2>
                        <p>Enter your details to get started.</p>
                    </header>

                    <?php if ($message !== ''): ?>
                        <p class="notice" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>

                    <form method="post" action="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="field">
                            <label for="name">Full name</label>
                            <input class="input" id="name" name="name" type="text" autocomplete="name" maxlength="120" placeholder="Your full name" value="<?= is_string($name) ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : '' ?>" required>
                        </div>
                        <div class="field">
                            <label for="username">Username</label>
                            <input class="input" id="username" name="username" type="text" autocomplete="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9_.-]{3,30}" placeholder="Choose a username" value="<?= is_string($username) ? htmlspecialchars($username, ENT_QUOTES, 'UTF-8') : '' ?>" required>
                        </div>
                        <div class="field">
                            <label for="email">Email address</label>
                            <input class="input" id="email" name="email" type="email" autocomplete="email" maxlength="254" placeholder="you@example.com" value="<?= is_string($email) ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '' ?>" required>
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" placeholder="At least 8 characters" required>
                        </div>
                        <button class="submit" type="submit">Create <?= htmlspecialchars(strtolower($roleTitle), ENT_QUOTES, 'UTF-8') ?> account <span aria-hidden="true">&#8594;</span></button>
                    </form>
                    <p class="footnote">Already have an account? <a href="login.php">Sign in</a></p>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>