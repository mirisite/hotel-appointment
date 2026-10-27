<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

if (($_SESSION['user_role'] ?? '') !== 'management' || !isset($_SESSION['user_id'])) {
    header('Location: ../management-login.php');
    exit;
}

$counts = ['customers' => null, 'staff' => null, 'new_customers' => null];
$customers = [];
$staff = [];
$databaseAvailable = true;

try {
    $pdo = getDatabaseConnection();

    $counts['customers'] = (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE is_active = 1')->fetchColumn();
    $counts['staff'] = (int) $pdo->query('SELECT COUNT(*) FROM management_users WHERE is_active = 1')->fetchColumn();
    $counts['new_customers'] = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')")->fetchColumn();
    $customers = $pdo->query('SELECT full_name, username, email, created_at FROM customers ORDER BY created_at DESC LIMIT 8')->fetchAll();
    $staff = $pdo->query('SELECT full_name, username, email, is_active FROM management_users ORDER BY full_name ASC LIMIT 8')->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
}

$displayName = htmlspecialchars((string) ($_SESSION['user_name'] ?? 'Manager'), ENT_QUOTES, 'UTF-8');
$today = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>Management Dashboard | The Queens Hotel</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --green-2: #315c4d; --paper: #f6f7f3; --white: #fff; --ink: #202923; --muted: #747d75; --line: #e4e7e0; --gold: #ae8551; --red: #8c5148; }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; scroll-padding-top: 28px; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .dashboard { width: min(100%, 1500px); margin: 0 auto; padding: 38px clamp(22px, 4vw, 58px) 64px; }
        .topline { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 34px; }
        .crumb { display: flex; align-items: center; gap: 9px; color: var(--muted); font-size: 12px; }
        .crumb-mark { color: var(--gold); font-family: Georgia, serif; font-size: 18px; }
        .today { color: var(--muted); font-size: 12px; }
        .intro { display: flex; align-items: end; justify-content: space-between; gap: 24px; margin-bottom: 30px; }
        .eyebrow { margin: 0 0 11px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 8px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(32px, 4vw, 46px); font-weight: 400; line-height: 1.12; }
        .intro-copy { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.6; }
        .today-chip { padding: 10px 13px; border: 1px solid var(--line); background: rgba(255,255,255,.55); color: #58635a; font-size: 11px; white-space: nowrap; }
        .alert { margin: 0 0 22px; padding: 13px 15px; border-left: 3px solid #bc8f55; background: #f3eddf; color: #614f32; font-size: 13px; line-height: 1.5; }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 38px; }
        .stat { min-height: 140px; padding: 20px 21px; border: 1px solid var(--line); background: var(--white); }
        .stat-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; color: var(--muted); font-size: 12px; }
        .stat-icon { display: grid; width: 34px; height: 34px; place-items: center; background: #edf2ec; color: var(--green); }
        .stat-icon svg { width: 18px; height: 18px; }
        .stat-value { margin: 19px 0 5px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 34px; line-height: 1; }
        .stat-caption { margin: 0; color: #8a918a; font-size: 11px; }
        .section { margin: 0 0 34px; scroll-margin-top: 22px; }
        .section-heading { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
        .section-heading h2 { margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: 25px; font-weight: 400; }
        .section-heading p { margin: 0; color: var(--muted); font-size: 12px; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--line); background: var(--white); }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 13px 17px; border-bottom: 1px solid var(--line); background: #fbfcf9; color: #788078; font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; white-space: nowrap; }
        td { padding: 14px 17px; border-bottom: 1px solid #edf0eb; color: #414b42; font-size: 12px; white-space: nowrap; }
        tr:last-child td { border-bottom: 0; }
        .person { display: flex; align-items: center; gap: 10px; }
        .avatar { display: grid; width: 32px; height: 32px; flex: 0 0 auto; place-items: center; border-radius: 50%; background: #e9eee7; color: var(--green); font-size: 11px; font-weight: 700; }
        .person-name { color: var(--ink); font-size: 12px; font-weight: 650; }
        .person-handle { margin-top: 3px; color: #969d96; font-size: 10px; }
        .status { display: inline-flex; align-items: center; gap: 6px; color: #49684f; font-size: 11px; }
        .status::before { width: 6px; height: 6px; border-radius: 50%; background: #6c9a6e; content: ""; }
        .status--inactive { color: #8b6a54; }
        .status--inactive::before { background: #bc8f55; }
        .empty { padding: 30px 18px; color: var(--muted); font-size: 13px; text-align: center; }
        .footer { padding-top: 12px; color: #929991; font-size: 11px; }
        @media (max-width: 820px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) {
            html { scroll-padding-top: 12px; }
            .dashboard { padding: 24px 16px 34px; }
            .topline { margin-bottom: 28px; }
            .intro { display: block; margin-bottom: 24px; }
            .today-chip { display: inline-block; margin-top: 14px; }
            .stats { gap: 10px; margin-bottom: 30px; }
            .stat { min-height: 128px; padding: 15px; }
            .stat-value { font-size: 30px; }
            .section-heading { align-items: start; flex-direction: column; }
            th, td { padding: 12px 13px; }
            .footer { padding-bottom: 8px; }
        }
        @media (max-width: 390px) { .stats { grid-template-columns: 1fr; } .stat { min-height: 112px; } .stat-value { margin-top: 12px; } }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="dashboard">
        <div class="topline" id="overview">
            <div class="crumb"><span class="crumb-mark" aria-hidden="true">Q</span><span>THE QUEENS HOTEL</span><span aria-hidden="true">/</span><span>Management</span></div>
            <time class="today" datetime="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?></time>
        </div>

        <header class="intro">
            <div>
                <p class="eyebrow">Management overview</p>
                <h1>Good day, <?= $displayName ?>.</h1>
                <p class="intro-copy">Here is your account overview for The Queens Hotel.</p>
            </div>
            <div class="today-chip">PROPERTY OPERATIONS</div>
        </header>

        <?php if (!$databaseAvailable): ?>
            <p class="alert" role="alert">The dashboard could not connect to the database. Check your database settings and try again.</p>
        <?php endif; ?>

        <section class="stats" aria-label="Account overview">
            <article class="stat">
                <div class="stat-head"><span>Active customers</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span></div>
                <p class="stat-value"><?= $counts['customers'] === null ? '&mdash;' : number_format($counts['customers']) ?></p>
                <p class="stat-caption">Registered customer accounts</p>
            </article>
            <article class="stat">
                <div class="stat-head"><span>Management team</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg></span></div>
                <p class="stat-value"><?= $counts['staff'] === null ? '&mdash;' : number_format($counts['staff']) ?></p>
                <p class="stat-caption">Active management accounts</p>
            </article>
            <article class="stat">
                <div class="stat-head"><span>New this month</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M12 14v4M10 16h4"/></svg></span></div>
                <p class="stat-value"><?= $counts['new_customers'] === null ? '&mdash;' : number_format($counts['new_customers']) ?></p>
                <p class="stat-caption">Customer accounts created this month</p>
            </article>
        </section>

        <section class="section" id="customers" aria-labelledby="customers-heading">
            <div class="section-heading"><div><p class="eyebrow">Guest directory</p><h2 id="customers-heading">Recent customers</h2></div><p>Latest registered accounts</p></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Customer</th><th scope="col">Email</th><th scope="col">Joined</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                    <?php if (!$databaseAvailable): ?>
                        <tr><td colspan="4" class="empty">Customer data is unavailable until the database connection is restored.</td></tr>
                    <?php elseif ($customers === []): ?>
                        <tr><td colspan="4" class="empty">No customer accounts yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                            <?php $initials = strtoupper(substr((string) $customer['full_name'], 0, 1)); ?>
                            <tr>
                                <td><div class="person"><span class="avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span><span><span class="person-name"><?= htmlspecialchars($customer['full_name'], ENT_QUOTES, 'UTF-8') ?></span><span class="person-handle" style="display:block">@<?= htmlspecialchars($customer['username'], ENT_QUOTES, 'UTF-8') ?></span></span></div></td>
                                <td><?= htmlspecialchars($customer['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($customer['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="status">Active</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section" id="team" aria-labelledby="team-heading">
            <div class="section-heading"><div><p class="eyebrow">People and access</p><h2 id="team-heading">Management team</h2></div><p>Accounts with management access</p></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Team member</th><th scope="col">Email</th><th scope="col">Access</th></tr></thead>
                    <tbody>
                    <?php if (!$databaseAvailable): ?>
                        <tr><td colspan="3" class="empty">Team data is unavailable until the database connection is restored.</td></tr>
                    <?php elseif ($staff === []): ?>
                        <tr><td colspan="3" class="empty">No management accounts found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($staff as $member): ?>
                            <?php $initials = strtoupper(substr((string) $member['full_name'], 0, 1)); ?>
                            <tr>
                                <td><div class="person"><span class="avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span><span><span class="person-name"><?= htmlspecialchars($member['full_name'], ENT_QUOTES, 'UTF-8') ?></span><span class="person-handle" style="display:block">@<?= htmlspecialchars($member['username'], ENT_QUOTES, 'UTF-8') ?></span></span></div></td>
                                <td><?= htmlspecialchars($member['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="status<?= (int) $member['is_active'] === 1 ? '' : ' status--inactive' ?>"><?= (int) $member['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <p class="footer">The Queens Hotel Management · Account directory</p>
    </main>
</body>
</html>