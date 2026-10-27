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

$bookings = [];
$paidBookingCount = 0;
$totalPaid = 0.0;
$databaseAvailable = true;

try {
    $pdo = getDatabaseConnection();
    $statement = $pdo->prepare(
        "SELECT b.booking_reference, b.check_in, b.check_out, b.adults, b.children, b.room_count, b.nights, b.total_amount, b.currency, b.payment_method, b.paid_at, r.room_type FROM bookings b INNER JOIN rooms r ON r.id = b.room_id WHERE b.customer_id = :customer_id AND b.payment_status = 'paid' AND b.booking_status = 'confirmed' ORDER BY b.paid_at DESC, b.created_at DESC LIMIT 100"
    );
    $statement->execute(['customer_id' => (int) $_SESSION['user_id']]);
    $bookings = $statement->fetchAll();
    $paidBookingCount = count($bookings);
    foreach ($bookings as $booking) {
        $totalPaid += (float) $booking['total_amount'];
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
}

$displayName = htmlspecialchars((string) ($_SESSION['user_name'] ?? 'Guest'), ENT_QUOTES, 'UTF-8');
$money = static function (float $amount, string $currency): string {
    $symbol = $currency === 'USD' ? '$' : '₱';
    return $symbol . number_format($amount, 2);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>My Paid Bookings | The Queens Hotel</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1380px); margin: auto; padding: 42px clamp(20px, 4vw, 58px) 70px; }
        .intro { display: flex; align-items: end; justify-content: space-between; gap: 20px; margin-bottom: 28px; }
        .eyebrow { margin: 0 0 10px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 8px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(34px, 4vw, 46px); font-weight: 400; line-height: 1.1; }
        .intro-copy { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.55; }
        .customer-name { color: var(--muted); font-size: 11px; white-space: nowrap; }
        .summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 18px; border: 1px solid var(--line); background: var(--white); }
        .summary-item { padding: 17px 20px; }
        .summary-item + .summary-item { border-left: 1px solid var(--line); }
        .summary-label { margin: 0 0 6px; color: var(--muted); font-size: 11px; }
        .summary-value { margin: 0; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 27px; }
        .results-meta { margin: 0 0 10px; color: var(--muted); font-size: 10px; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--line); background: var(--white); }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 13px 14px; border-bottom: 1px solid var(--line); background: #fbfcf9; color: #788078; font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; white-space: nowrap; }
        td { padding: 13px 14px; border-bottom: 1px solid #edf0eb; color: #414b42; font-size: 11px; vertical-align: middle; }
        tr:last-child td { border-bottom: 0; }
        .reference { color: var(--green); font-size: 10px; font-weight: 700; white-space: nowrap; }
        .stay { line-height: 1.5; white-space: nowrap; }
        .stay small, .muted-detail { display: block; margin-top: 3px; color: var(--muted); font-size: 9px; }
        .paid-pill { display: inline-block; padding: 5px 7px; background: #edf4ed; color: #416748; font-size: 9px; font-weight: 700; white-space: nowrap; }
        .message { margin-bottom: 16px; padding: 12px 14px; border-left: 3px solid #a14f43; background: #f8ebe8; color: #79332d; font-size: 12px; line-height: 1.5; }
        .empty { padding: 42px 20px; border: 1px solid var(--line); background: var(--white); text-align: center; }
        .empty h2 { margin: 0 0 8px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 24px; font-weight: 400; }
        .empty p { margin: 0 0 17px; color: var(--muted); font-size: 11px; line-height: 1.55; }
        .button { display: inline-flex; min-height: 39px; align-items: center; justify-content: center; gap: 8px; padding: 0 14px; border: 1px solid var(--green); background: var(--green); color: white; font-size: 11px; font-weight: 650; text-decoration: none; }
        .button:hover { background: #102d29; }
        .footer { margin-top: 18px; color: #929991; font-size: 10px; line-height: 1.5; }
        @media (max-width: 640px) {
            .page { padding: 28px 14px 40px; }
            .intro { display: block; }
            .customer-name { display: inline-block; margin-top: 13px; }
            .summary-item { padding: 14px; }
            .summary-value { font-size: 23px; }
            th, td { padding: 11px 10px; }
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <header class="intro">
            <div>
                <p class="eyebrow">The Queens Hotel · Guest</p>
                <h1>My paid bookings</h1>
                <p class="intro-copy">Confirmed stays with verified payment.</p>
            </div>
            <span class="customer-name">Signed in as <?= $displayName ?></span>
        </header>

        <section class="summary" aria-label="Paid booking summary">
            <div class="summary-item"><p class="summary-label">Paid and confirmed</p><p class="summary-value"><?= number_format($paidBookingCount) ?></p></div>
            <div class="summary-item"><p class="summary-label">Total paid</p><p class="summary-value"><?= $money($totalPaid, 'PHP') ?></p></div>
        </section>

        <?php if (!$databaseAvailable): ?>
            <p class="message" role="alert">Your bookings are unavailable right now. Please try again later.</p>
        <?php elseif ($bookings === []): ?>
            <section class="empty"><h2>No paid bookings yet</h2><p>Bookings will appear here after payment is verified and the stay is confirmed.</p><a class="button" href="rooms.php">Browse rooms <span aria-hidden="true">&#8594;</span></a></section>
        <?php else: ?>
            <p class="results-meta">Showing <?= number_format($paidBookingCount) ?> paid, confirmed booking<?= $paidBookingCount === 1 ? '' : 's' ?><?= $paidBookingCount === 100 ? ' · newest 100' : '' ?></p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th scope="col">Reference</th><th scope="col">Room</th><th scope="col">Stay</th><th scope="col">Guests / rooms</th><th scope="col">Amount paid</th><th scope="col">Payment</th><th scope="col">Paid at</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td class="reference"><?= htmlspecialchars((string) $booking['booking_reference'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $booking['room_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="stay"><?= htmlspecialchars(date('M j, Y', strtotime((string) $booking['check_in'])), ENT_QUOTES, 'UTF-8') ?><small>to <?= htmlspecialchars(date('M j, Y', strtotime((string) $booking['check_out'])), ENT_QUOTES, 'UTF-8') ?> · <?= (int) $booking['nights'] ?> night<?= (int) $booking['nights'] === 1 ? '' : 's' ?></small></td>
                                <td><?= (int) $booking['adults'] + (int) $booking['children'] ?> guests<span class="muted-detail"><?= (int) $booking['room_count'] ?> room<?= (int) $booking['room_count'] === 1 ? '' : 's' ?></span></td>
                                <td><strong><?= $money((float) $booking['total_amount'], (string) $booking['currency']) ?></strong></td>
                                <td><span class="paid-pill">Paid</span><span class="muted-detail"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $booking['payment_method'])), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= $booking['paid_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime((string) $booking['paid_at'])), ENT_QUOTES, 'UTF-8') : 'Verified' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="footer">Only bookings with verified payment and confirmed status are listed here.</p>
        <?php endif; ?>
    </main>
</body>
</html>