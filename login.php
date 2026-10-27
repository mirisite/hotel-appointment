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
        $message = 'Enter a valid email address and password.';
    } else {
        try {
            $pdo = getDatabaseConnection();

            $statement = $pdo->prepare(
                'SELECT id, full_name, email, password_hash FROM customers WHERE (email = :email OR username = :username) AND is_active = 1 LIMIT 1'
            );
            $identifier = strtolower(trim($identifier));
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
                $_SESSION['user_role'] = 'customer';

                header('Location: customer/dashboard.php');
                exit;
            }

            $message = 'We could not sign you in with those details. Check your account type, email, and password.';
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
    <title>Sign in | The Queens Hotel</title>
    <style>
        :root {
            color-scheme: light;
            --forest: #173b35;
            --forest-deep: #102d29;
            --mint: #d9e8dc;
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
            position: relative;
            display: flex;
            min-height: 680px;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            padding: 44px clamp(32px, 5vw, 76px) 40px;
            background-color: var(--forest);
            background-image: linear-gradient(180deg, rgba(10, 34, 30, 0.12) 20%, rgba(10, 34, 30, 0.84) 100%), url("https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1500&q=85");
            background-position: center;
            margin: 20px 0 0;
            color: rgba(255, 255, 255, 0.78);
            font-size: 15px;
            line-height: 1.7;
        }

        .visual-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: rgba(255, 255, 255, 0.75);
            font-size: 12px;
        }

        .visual-footer span:last-child { color: #e0c291; }

        .main {
            display: grid;
            min-height: 100vh;
            place-items: center;
            padding: 52px 40px;
        }

        .form-wrap {
            width: min(100%, 430px);
            animation: arrive 650ms 100ms ease-out both;
        }

        .mobile-brand { display: none; }

        .welcome { margin-bottom: 34px; }

        .welcome .eyebrow { margin-bottom: 11px; color: #8b7049; }

        .welcome h2 {
            margin: 0;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 38px;
            font-weight: 400;
            line-height: 1.15;
        }

        .welcome p:last-child {
            margin: 11px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .field-label, .role-label {
            display: block;
            margin-bottom: 10px;
            color: #3c463e;
            font-size: 12px;
            font-weight: 700;
        }

        .role-picker {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
            margin-bottom: 26px;
            padding: 4px;
            border: 1px solid var(--line);
            background: #f1f2ed;
        }

        .role-option { position: relative; }

        .role-option input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
        }

        .role-option label {
            display: flex;
            min-height: 43px;
            align-items: center;
            justify-content: center;
            border: 1px solid transparent;
            color: #737a73;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: background-color 160ms ease, color 160ms ease, border-color 160ms ease;
        }

        .role-option input:checked + label {
            border-color: #e5e6e0;
            background: white;
            color: var(--forest);
            box-shadow: 0 1px 3px rgba(22, 35, 26, 0.08);
        }

        .role-option input:focus-visible + label {
            outline: 2px solid var(--forest);
            outline-offset: 2px;
        }

        .field { margin-bottom: 19px; }

        .input {
            display: block;
            width: 100%;
            height: 50px;
            padding: 0 14px;
            border: 1px solid #d9ded6;
            border-radius: 2px;
            outline: none;
            background: rgba(255, 255, 255, 0.76);
            color: var(--ink);
            font: inherit;
            font-size: 14px;
            transition: border-color 160ms ease, box-shadow 160ms ease;
        }

        .input::placeholder { color: #9aa098; }
        .input:focus { border-color: var(--forest); box-shadow: 0 0 0 3px rgba(23, 59, 53, 0.1); }

        .password-wrap { position: relative; }
        .password-wrap .input { padding-right: 72px; }

        .show-password {
            position: absolute;
            top: 50%;
            right: 12px;
            padding: 6px;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: var(--forest);
            cursor: pointer;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
        }

        .form-meta {
            display: flex;
            min-height: 20px;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 2px 0 23px;
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 12px;
        }

        .remember input { accent-color: var(--forest); }

        .help-link {
            color: var(--forest);
            font-size: 12px;
            font-weight: 650;
            text-decoration-color: #9eae9f;
            text-underline-offset: 3px;
        }

        .submit {
            display: flex;
            width: 100%;
            min-height: 51px;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: 1px solid var(--forest);
            border-radius: 2px;
            background: var(--forest);
            color: white;
            cursor: pointer;
            font: inherit;
            font-size: 14px;
            font-weight: 650;
            transition: background-color 160ms ease, transform 160ms ease;
        }

        .submit:hover { transform: translateY(-1px); background: var(--forest-deep); }
        .submit:focus-visible { outline: 3px solid rgba(23, 59, 53, 0.25); outline-offset: 3px; }

        .notice {
            margin: 0 0 20px;
            padding: 12px 14px;
            border-left: 3px solid var(--danger);
            background: #f8ebe8;
            color: #79332d;
            font-size: 13px;
            line-height: 1.5;
        }

        .privacy-note {
            margin: 25px 0 0;
            color: #858c84;
            font-size: 11px;
            line-height: 1.6;
            text-align: center;
        }

        @keyframes arrive {
            from { opacity: 0; transform: translateY(9px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 820px) {
            .layout { grid-template-columns: minmax(280px, 0.8fr) minmax(420px, 1.2fr); }
            .visual { padding: 34px 28px 30px; }
            .main { padding: 42px 30px; }
        }

        @media (max-width: 680px) {
            .layout { display: block; }
            .visual { display: none; }
            .main { min-height: 100svh; padding: 34px 24px; }
            .form-wrap { max-width: 430px; }
            .mobile-brand {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 52px;
                color: var(--forest);
                font-size: 13px;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-decoration: none;
                text-transform: uppercase;
            }
            .mobile-brand .brand-mark { border-color: var(--forest); color: #9a774a; }
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
            <a class="brand" href="login.php" aria-label="The Queens Hotel home">
                <span class="brand-mark" aria-hidden="true">Q</span>
                <span>The Queens Hotel</span>
            </a>
            <div class="visual-copy">
                <p class="eyebrow">A considered kind of stay</p>
                <h1>Hospitality, made personal.</h1>
                <p>Thoughtful stays and seamless service, from the first hello to the journey home.</p>
            </div>
            <div class="visual-footer">
                <span>Make yourself at home.</span>
                <span>EST. 1987</span>
            </div>
        </aside>

        <main class="main">
            <section class="form-wrap" aria-labelledby="welcome-heading">
                <a class="mobile-brand" href="login.php" aria-label="The Queens Hotel home">
                    <span class="brand-mark" aria-hidden="true">Q</span>
                    <span>The Queens Hotel</span>
                </a>
                <header class="welcome">
                    <p class="eyebrow">Welcome back</p>
                    <h2 id="welcome-heading">Sign in to continue</h2>
                    <p>Access your stay details and account securely.</p>
                </header>

                <?php if ($message !== ''): ?>
                    <p class="notice" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <form method="post" action="login.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="field">
                        <label class="field-label" for="identifier">Email address or username</label>
                        <input class="input" id="identifier" name="identifier" type="text" autocomplete="username" placeholder="you@example.com or your username" value="<?= isset($identifier) && is_string($identifier) ? htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8') : '' ?>" required>
                    </div>

                    <div class="field">
                        <label class="field-label" for="password">Password</label>
                        <div class="password-wrap">
                            <input class="input" id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
                            <button class="show-password" type="button" aria-controls="password" aria-pressed="false">Show</button>
                        </div>
                    </div>

                    <div class="form-meta">
                        <label class="remember"><input type="checkbox" name="remember" value="1"> Remember me</label>
                        <a class="help-link" href="customer-register.php">Create an account</a>
                    </div>

                    <button class="submit" type="submit">Sign in <span aria-hidden="true">&#8594;</span></button>
                    <p class="privacy-note">Your account information is protected and only used to manage your hotel experience.</p>
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