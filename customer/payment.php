<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$token = $_GET['token'] ?? '';
$booking = null;
$paymentUrl = null;
$errorMessage = '';

if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(404);
    $errorMessage = 'This payment link is invalid or has expired.';
} else {
    try {
        $pdo = getDatabaseConnection();
        $bookingQuery = $pdo->prepare(
            "SELECT b.booking_reference, b.room_count, b.nights, b.check_in, b.check_out, b.total_amount, b.currency, b.payment_status, b.booking_status, b.paymongo_checkout_url, r.room_type FROM bookings b INNER JOIN rooms r ON r.id = b.room_id WHERE b.payment_access_token = :token AND b.payment_method = 'qrph' LIMIT 1"
        );
        $bookingQuery->execute(['token' => $token]);
        $booking = $bookingQuery->fetch() ?: null;

        if (!$booking || $booking['booking_status'] !== 'confirmed') {
            http_response_code(404);
            $booking = null;
            $errorMessage = 'This payment link is invalid or has expired.';
        } elseif ($booking['payment_status'] === 'pending') {
            $checkoutUrl = $booking['paymongo_checkout_url'] ?? null;
            $checkoutHost = is_string($checkoutUrl) ? parse_url($checkoutUrl, PHP_URL_HOST) : false;
            $checkoutHost = is_string($checkoutHost) ? strtolower($checkoutHost) : '';
            if (
                is_string($checkoutUrl)
                && str_starts_with($checkoutUrl, 'https://')
                && ($checkoutHost === 'checkout.paymongo.com' || str_ends_with($checkoutHost, '.paymongo.com'))
            ) {
                $paymentUrl = $checkoutUrl;
            }
        }
    } catch (Throwable $exception) {
        error_log('Payment landing page lookup failed: ' . $exception->getMessage());
        http_response_code(503);
        $errorMessage = 'Payment details are temporarily unavailable. Please try again shortly.';
    }
}

$money = static fn (string $currency, float $amount): string => ($currency === 'PHP' ? 'PHP ' : $currency . ' ') . number_format($amount, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#173b35">
    <title>Complete your payment | The Queens Hotel</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --deep: #102d29; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; display: grid; place-items: center; margin: 0; padding: 24px; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        main { width: min(100%, 520px); padding: clamp(24px, 6vw, 44px); border: 1px solid var(--line); background: var(--white); }
        .brand { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 34px; color: var(--green); font-size: 12px; font-weight: 750; letter-spacing: .08em; text-decoration: none; text-transform: uppercase; }
        .brand-mark { display: grid; width: 34px; aspect-ratio: 1; place-items: center; border: 1px solid var(--gold); color: var(--gold); font-family: Georgia, "Times New Roman", serif; font-size: 20px; font-weight: 400; }
        .eyebrow { margin: 0 0 9px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1 { margin: 0 0 11px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: clamp(31px, 8vw, 40px); font-weight: 400; line-height: 1.12; }
        .intro { margin: 0 0 24px; color: var(--muted); font-size: 13px; line-height: 1.6; }
        .details { display: grid; gap: 12px; margin: 0 0 24px; padding: 18px 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .detail { display: flex; justify-content: space-between; gap: 16px; color: var(--muted); font-size: 12px; }
        .detail strong { color: var(--ink); font-weight: 650; text-align: right; }
        .total { color: var(--green) !important; font-size: 17px; }
        .button { display: flex; width: 100%; min-height: 50px; align-items: center; justify-content: center; gap: 9px; border: 1px solid var(--green); background: var(--green); color: white; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; transition: background 150ms ease, transform 150ms ease; }
        .button:hover { transform: translateY(-1px); background: var(--deep); }
        .button:focus-visible { outline: 3px solid rgba(23,59,53,.25); outline-offset: 3px; }
        .note { margin: 15px 0 0; color: var(--muted); font-size: 11px; line-height: 1.6; }
        .notice { padding: 14px; border-left: 3px solid var(--gold); background: #f5f0e6; color: #654f31; font-size: 12px; line-height: 1.6; }
        @media (prefers-reduced-motion: reduce) { .button { transition: none; } }
    </style>
</head>
<body>
    <main>
        <a class="brand" href="../index.php" aria-label="The Queens Hotel home"><span class="brand-mark" aria-hidden="true">Q</span><span>The Queens Hotel</span></a>
        <?php if ($errorMessage !== ''): ?>
            <p class="eyebrow">Secure payment</p>
            <h1>Payment link unavailable.</h1>
            <p class="notice" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php elseif ($booking['payment_status'] === 'paid'): ?>
            <p class="eyebrow">Payment complete</p>
            <h1>You are all set.</h1>
            <p class="intro">Payment has been confirmed for booking <?= htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8') ?>. You can close this page.</p>
        <?php elseif ($booking['payment_status'] !== 'pending'): ?>
            <p class="eyebrow">Secure payment</p>
            <h1>Payment is unavailable.</h1>
            <p class="notice" role="status">This booking is no longer awaiting payment. Contact the hotel if you need help.</p>
        <?php elseif ($paymentUrl === null): ?>
            <p class="eyebrow">Secure payment</p>
            <h1>Payment is unavailable.</h1>
            <p class="notice" role="status">We could not find a valid PayMongo checkout for this booking. Contact the hotel for assistance.</p>
        <?php else: ?>
            <p class="eyebrow">The Queens Hotel · Secure checkout</p>
            <h1>Complete your payment.</h1>
            <p class="intro">Review your stay, then continue to PayMongo to complete payment using QR Ph.</p>
            <div class="details">
                <div class="detail"><span>Booking reference</span><strong><?= htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div class="detail"><span>Room</span><strong><?= htmlspecialchars($booking['room_type'], ENT_QUOTES, 'UTF-8') ?> × <?= (int) $booking['room_count'] ?></strong></div>
                <div class="detail"><span>Stay</span><strong><?= (int) $booking['nights'] ?> night<?= (int) $booking['nights'] === 1 ? '' : 's' ?></strong></div>
                <div class="detail"><span>Total</span><strong class="total"><?= htmlspecialchars($money((string) $booking['currency'], (float) $booking['total_amount']), ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
            <a class="button" href="<?= htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8') ?>" rel="noopener noreferrer">Continue to PayMongo <span aria-hidden="true">&#8594;</span></a>
            <p class="note">Your payment is processed securely by PayMongo. This page does not collect card, wallet, or banking details.</p>
        <?php endif; ?>
    </main>
</body>
</html>
