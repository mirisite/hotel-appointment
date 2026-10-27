<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = $_POST['identifier'] ?? '';
    $password = $_POST['password'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Please refresh the page and try again.';
    } elseif (
        !is_string($identifier)
        || trim($identifier) === ''
        || !is_string($password)
    ) {
        $message = 'Enter a valid email address or username and password.';
    } else {
        try {
            $pdo = getDatabaseConnection();
            $identifier = strtolower(trim($identifier));
            $statement = $pdo->prepare(
                'SELECT id, full_name, email, password_hash FROM management_users WHERE (email = :email OR username = :username) AND is_active = 1 LIMIT 1'
            );
            $statement->execute([
                'email' => $identifier,
                'username' => $identifier,
            ]);
            $user = $statement->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = 'management';

                header('Location: management/dashboard.php');
                exit;
            }

            $message = 'We could not sign you in with those details. Check your management account, username, and password.';
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $message = 'Sign-in is temporarily unavailable. Please try again shortly.';
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
    <title>Management sign in | The Queens Hotel</title>
    <style>
        :root {
            color-scheme: light;
            --forest: #173b35;
            --forest-deep: #102d29;
            --paper: #fbfaf7;
            --ink: #202923;
            --muted: #727a73;
            --line: #dfe3dc;
            --gold: #c49b60;
            --danger: #9e3e36;
        }

        * { box-sizing: border-box; }

        body {
            min-width: 320px;
            min-height: 100vh;
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font-family: "Aptos", "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(360px, 0.92fr) minmax(480px, 1.08fr);
            min-height: 100vh;
        }

        .visual {
            display: flex;
            min-height: 680px;
            flex-direction: column;
            justify-content: space-between;
            padding: 44px clamp(32px, 5vw, 76px) 40px;
            background-color: var(--forest);
            background-image: linear-gradient(180deg, rgba(10, 34, 30, 0.12) 20%, rgba(10, 34, 30, 0.84) 100%), url("https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1500&q=85");
            background-position: center;
            background-size: cover;
            color: white;
        }

        .brand, .mobile-brand {
            display: inline-flex;
            width: fit-content;
            align-items: center;
            gap: 12px;
            color: inherit;
            font-size: 15px;
            font-weight: 650;
            letter-spacing: 0.08em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .brand-mark {
            display: grid;
            width: 34px;
            aspect-ratio: 1;
            place-items: center;
            border: 1px solid currentColor;
            color: #f3d9ae;
            font-family: Georgia, serif;
            font-size: 19px;
            font-weight: 400;
        }

        .visual-copy { max-width: 460px; padding-bottom: 10px; }
        .eyebrow { margin: 0 0 18px; color: #e0c291; font-size: 11px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase; }
        .visual h1 { max-width: 440px; margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: clamp(42px, 5vw, 68px); font-weight: 400; line-height: 1.04; }
        .visual-copy > p:last-child { max-width: 350px; margin: 20px 0 0; color: rgba(255, 255, 255, 0.78); font-size: 15px; line-height: 1.7; }
        .visual-footer { display: flex; align-items: center; justify-content: space-between; color: rgba(255, 255, 255, 0.75); font-size: 12px; }
        .visual-footer span:last-child { color: #e0c291; }

        .main { display: grid; min-height: 100vh; place-items: center; padding: 52px 40px; }
        .form-wrap { width: min(100%, 430px); }
        .mobile-brand { display: none; color: var(--forest); }
        .welcome { margin-bottom: 34px; }
        .welcome .eyebrow { margin-bottom: 11px; color: #8b7049; }
        .welcome h2 { margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: 38px; font-weight: 400; line-height: 1.15; }
        .welcome p:last-child { margin: 11px 0 0; color: var(--muted); font-size: 14px; line-height: 1.6; }
        .field { margin-bottom: 19px; }
        .field-label { display: block; margin-bottom: 10px; color: #3c463e; font-size: 12px; font-weight: 700; }
        .input { display: block; width: 100%; height: 50px; padding: 0 14px; border: 1px solid #d9ded6; border-radius: 2px; outline: none; background: rgba(255, 255, 255, 0.76); color: var(--ink); font: inherit; font-size: 14px; }
        .input::placeholder { color: #9aa098; }
        .input:focus { border-color: var(--forest); box-shadow: 0 0 0 3px rgba(23, 59, 53, 0.1); }
        .password-wrap { position: relative; }
        .password-wrap .input { padding-right: 72px; }
        .show-password { position: absolute; top: 50%; right: 12px; padding: 6px; transform: translateY(-50%); border: 0; background: transparent; color: var(--forest); cursor: pointer; font: inherit; font-size: 12px; font-weight: 700; }
        .submit { display: flex; width: 100%; min-height: 51px; align-items: center; justify-content: center; gap: 10px; border: 1px solid var(--forest); border-radius: 2px; background: var(--forest); color: white; cursor: pointer; font: inherit; font-size: 14px; font-weight: 650; transition: background-color 160ms ease, transform 160ms ease; }
        .submit:hover { transform: translateY(-1px); background: var(--forest-deep); }
        .submit:focus-visible { outline: 3px solid rgba(23, 59, 53, 0.25); outline-offset: 3px; }
        .notice { margin: 0 0 20px; padding: 12px 14px; border-left: 3px solid var(--danger); background: #f8ebe8; color: #79332d; font-size: 13px; line-height: 1.5; }
        .switch-link { margin: 23px 0 0; color: var(--muted); font-size: 12px; text-align: center; }
        .switch-link a { color: var(--forest); font-weight: 650; text-decoration-color: #9eae9f; text-underline-offset: 3px; }

        @media (max-width: 820px) {
            .layout { grid-template-columns: minmax(280px, 0.8fr) minmax(420px, 1.2fr); }
            .visual { padding: 34px 28px 30px; }
            .main { padding: 42px 30px; }
        }

        @media (max-width: 680px) {
            .layout { display: block; }
            .visual { display: none; }
            .main { min-height: 100svh; padding: 34px 24px; }
            .mobile-brand { display: inline-flex; margin-bottom: 52px; font-size: 13px; }
            .welcome h2 { font-size: 35px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="visual" aria-label="The Queens Hotel">
            <a class="brand" href="index.php" aria-label="The Queens Hotel home">
                <span class="brand-mark" aria-hidden="true">Q</span>
                <span>The Queens Hotel</span>
            </a>
            <div class="visual-copy">
                <p class="eyebrow">Management portal</p>
                <h1>Service starts behind the scenes.</h1>
                <p>Access the tools that help keep every guest experience running smoothly.</p>
            </div>
            <div class="visual-footer">
                <span>Management access</span>
                <span>EST. 1987</span>
            </div>
        </aside>

        <main class="main">
            <section class="form-wrap" aria-labelledby="welcome-heading">
                <a class="mobile-brand" href="index.php" aria-label="The Queens Hotel home">
                    <span class="brand-mark" aria-hidden="true">Q</span>
                    <span>The Queens Hotel</span>
                </a>
                <header class="welcome">
                    <p class="eyebrow">Team access</p>
                    <h2 id="welcome-heading">Management sign in</h2>
                    <p>Sign in with your management account to continue.</p>
                </header>

                <?php if ($message !== ''): ?>
                    <p class="notice" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <form method="post" action="management-login.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="field">
                        <label class="field-label" for="identifier">Email address or username</label>
                        <input class="input" id="identifier" name="identifier" type="text" autocomplete="username" placeholder="name@example.com or username" value="<?= isset($identifier) && is_string($identifier) ? htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8') : '' ?>" required>
                    </div>

                    <div class="field">
                        <label class="field-label" for="password">Password</label>
                        <div class="password-wrap">
                            <input class="input" id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
                            <button class="show-password" type="button" aria-controls="password" aria-pressed="false">Show</button>
                        </div>
                    </div>

                    <button class="submit" type="submit">Sign in <span aria-hidden="true">&#8594;</span></button>
                    <p class="switch-link">Customer? <a href="login.php">Go to customer sign in</a></p>
                </form>
            </section>
        </main>
    </div>
    <script>
        const passwordInput = document.querySelector('#password');
        const passwordToggle = document.querySelector('.show-password');

        passwordToggle.addEventListener('click', () => {
            const shouldShow = passwordInput.type === 'password';
            passwordInput.type = shouldShow ? 'text' : 'password';
            passwordToggle.textContent = shouldShow ? 'Hide' : 'Show';
            passwordToggle.setAttribute('aria-pressed', String(shouldShow));
        });
    </script>
</body>
</html>
