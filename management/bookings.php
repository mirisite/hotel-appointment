<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/booking_email.php';

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


if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$search = $_GET['q'] ?? '';
$csrfToken = $_SESSION['csrf_token'];
$message = '';
$messageType = 'error';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_room') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $bookingIdInput = $_POST['booking_id'] ?? '';
    $roomNumbersInput = $_POST['room_numbers'] ?? [];
    $bookingId = is_string($bookingIdInput) ? filter_var($bookingIdInput, FILTER_VALIDATE_INT) : false;
    $roomNumbers = [];
    if (is_array($roomNumbersInput)) {
        foreach ($roomNumbersInput as $roomNumberInput) {
            if (!is_string($roomNumberInput)) {
                $roomNumbers = [];
                break;
            }
            $roomNumbers[] = strtoupper(trim($roomNumberInput));
        }
    }

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Refresh the page and try again.';
    } elseif ($bookingId === false || $bookingId < 1) {
        $message = 'Choose a valid paid booking.';
    } elseif ($roomNumbers === [] || count(array_unique($roomNumbers)) !== count($roomNumbers)) {
        $message = 'Enter a different room number for each room in the booking.';
    } elseif (count(array_filter($roomNumbers, static fn (string $number): bool => preg_match('/^[A-Z0-9-]{1,20}$/', $number) !== 1)) > 0) {
        $message = 'Each room number can contain up to 20 letters, numbers, or hyphens.';
    } else {
        $pdo = null;
        $acquiredLocks = [];
        try {
            $pdo = getDatabaseConnection();
            $lockNames = array_map(
                static fn (string $number): string => 'room_assign_' . substr(hash('sha256', $number), 0, 48),
                $roomNumbers
            );
            sort($lockNames, SORT_STRING);
            $lockQuery = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
            foreach ($lockNames as $lockName) {
                $lockQuery->execute(['lock_name' => $lockName]);
                if ((int) $lockQuery->fetchColumn() !== 1) {
                    throw new RuntimeException('Another room assignment is in progress. Please try again.');
                }
                $acquiredLocks[] = $lockName;
            }

            $pdo->beginTransaction();
            $bookingQuery = $pdo->prepare(
                "SELECT id, booking_reference, check_in, check_out, room_count, assigned_room_number FROM bookings WHERE id = :booking_id AND payment_status = 'paid' AND booking_status = 'confirmed' FOR UPDATE"
            );
            $bookingQuery->execute(['booking_id' => $bookingId]);
            $booking = $bookingQuery->fetch();
            if (!$booking) {
                throw new RuntimeException('Room numbers can only be assigned to paid, confirmed bookings.');
            }
            if (count($roomNumbers) !== (int) $booking['room_count']) {
                throw new RuntimeException('Enter exactly ' . (int) $booking['room_count'] . ' room number' . ((int) $booking['room_count'] === 1 ? '' : 's') . ' for this booking.');
            }

            $conflictQuery = $pdo->prepare(
                "SELECT id, booking_reference, assigned_room_number FROM bookings WHERE assigned_room_number IS NOT NULL AND booking_status = 'confirmed' AND id <> :booking_id AND check_in < :check_out AND check_out > :check_in FOR UPDATE"
            );
            $conflictQuery->execute([
                'booking_id' => $bookingId,
                'check_out' => $booking['check_out'],
                'check_in' => $booking['check_in'],
            ]);
            foreach ($conflictQuery->fetchAll() as $conflictingBooking) {
                $conflictingRoomNumbers = array_map('trim', explode(',', (string) $conflictingBooking['assigned_room_number']));
                foreach ($roomNumbers as $roomNumber) {
                    if (in_array($roomNumber, $conflictingRoomNumbers, true)) {
                        throw new RuntimeException('Room ' . $roomNumber . ' is already assigned to booking ' . $conflictingBooking['booking_reference'] . ' during these dates.');
                    }
                }
            }

            $assignmentValue = implode(',', $roomNumbers);
            $roomChanged = $booking['assigned_room_number'] !== $assignmentValue;
            $saveAssignment = $pdo->prepare(
                'UPDATE bookings SET assigned_room_number = :room_number, room_assignment_email_sent_at = CASE WHEN :room_changed = 1 THEN NULL ELSE room_assignment_email_sent_at END WHERE id = :booking_id'
            );
            $saveAssignment->execute([
                'room_number' => $assignmentValue,
                'room_changed' => $roomChanged ? 1 : 0,
                'booking_id' => $bookingId,
            ]);
            $pdo->commit();

            $emailStatus = sendBookingEmailNotification($pdo, (int) $bookingId, 'room_assignment');
            $roomNumbersSummary = implode(', ', $roomNumbers);
            if (in_array($emailStatus, ['sent', 'already_sent'], true)) {
                $message = $emailStatus === 'sent'
                    ? 'Room numbers ' . $roomNumbersSummary . ' assigned. The customer has been emailed.'
                    : 'Room numbers ' . $roomNumbersSummary . ' are assigned. The customer was already notified.';
                $messageType = 'success';
            } else {
                $message = 'Room numbers ' . $roomNumbersSummary . ' are saved, but the email could not be sent. Check SMTP settings and submit these numbers again to retry.';
            }
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Room assignment failed: ' . $exception->getMessage());
            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The room number could not be assigned. Confirm the database migration and try again.';
        } finally {
            if ($acquiredLocks !== [] && $pdo instanceof PDO) {
                try {
                    $releaseLock = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                    foreach (array_reverse($acquiredLocks) as $lockName) {
                        $releaseLock->execute(['lock_name' => $lockName]);
                    }
                } catch (Throwable $exception) {
                    error_log('Room assignment lock release failed: ' . $exception->getMessage());
                }
            }
        }
    }
}
$search = is_string($search) ? trim($search) : '';
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';
$fromDate = is_string($fromDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) ? $fromDate : '';
$toDate = is_string($toDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) ? $toDate : '';
$databaseAvailable = true;
$bookings = [];
$paidCount = 0;

try {
    $pdo = getDatabaseConnection();

    $paidCount = (int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE payment_status = 'paid'")->fetchColumn();
    $conditions = ["b.payment_status = 'paid'"];
    $parameters = [];
    if ($search !== '') {
        $conditions[] = '(b.booking_reference LIKE :reference OR b.guest_name LIKE :guest_name OR b.guest_email LIKE :guest_email OR r.room_type LIKE :room_type)';
        $term = '%' . $search . '%';
        $parameters['reference'] = $term;
        $parameters['guest_name'] = $term;
        $parameters['guest_email'] = $term;
        $parameters['room_type'] = $term;
    }
    if ($fromDate !== '') {
        $conditions[] = 'b.check_in >= :from_date';
        $parameters['from_date'] = $fromDate;
    }
    if ($toDate !== '') {
        $conditions[] = 'b.check_in <= :to_date';
        $parameters['to_date'] = $toDate;
    }

    $statement = $pdo->prepare(
        'SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.check_in, b.check_out, b.adults, b.children, b.room_count, b.nights, b.total_amount, b.currency, b.payment_method, b.payment_status, b.booking_status, b.paid_at, b.assigned_room_number, b.room_assignment_email_sent_at, b.created_at, r.room_type FROM bookings b INNER JOIN rooms r ON r.id = b.room_id WHERE '
        . implode(' AND ', $conditions)
        . ' ORDER BY b.paid_at DESC, b.created_at DESC LIMIT 200'
    );
    $statement->execute($parameters);
    $bookings = $statement->fetchAll();
    foreach ($bookings as &$booking) {
        $booking['assigned_room_numbers'] = is_string($booking['assigned_room_number']) && $booking['assigned_room_number'] !== ''
            ? array_map('trim', explode(',', $booking['assigned_room_number']))
            : [];
    }
    unset($booking);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
    $message = 'Paid bookings are unavailable. Confirm that the booking database migration has been applied.';
}

$displayName = htmlspecialchars((string) ($_SESSION['user_name'] ?? 'Manager'), ENT_QUOTES, 'UTF-8');
$money = static function (float $amount, string $currency): string {
    $symbol = $currency === 'USD' ? '$' : 'â‚±';
    return $symbol . number_format($amount, 2);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>Paid Bookings | The Queens Hotel Management</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1500px); margin: auto; padding: 38px clamp(20px, 4vw, 58px) 70px; }
        .eyebrow { margin: 0 0 10px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 8px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(34px, 4vw, 46px); font-weight: 400; line-height: 1.1; }
        h2 { font-family: Georgia, "Times New Roman", serif; font-weight: 400; }
        .intro { display: flex; align-items: end; justify-content: space-between; gap: 20px; margin-bottom: 26px; }
        .intro p:last-child { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.55; }
        .manager-name { color: var(--muted); font-size: 11px; white-space: nowrap; }
        .summary { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 18px; padding: 18px 20px; border: 1px solid var(--line); background: var(--white); }
        .summary-label { margin: 0 0 5px; color: var(--muted); font-size: 11px; }
        .summary-count { margin: 0; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 30px; }
        .paid-indicator { display: inline-flex; align-items: center; gap: 7px; color: #42694b; font-size: 11px; font-weight: 650; }
        .paid-indicator::before { width: 7px; height: 7px; border-radius: 50%; background: #62996c; content: ""; }
        .filters { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(150px, .55fr) minmax(150px, .55fr) auto auto; align-items: end; gap: 10px; margin-bottom: 17px; padding: 14px; border: 1px solid var(--line); background: var(--white); }
        .field label { display: block; margin-bottom: 6px; color: #495349; font-size: 10px; font-weight: 700; }
        .control { display: block; width: 100%; min-height: 41px; padding: 8px 10px; border: 1px solid #dce1d9; border-radius: 2px; outline: none; background: #fff; color: var(--ink); font: inherit; font-size: 12px; }
        .control:focus { border-color: var(--green); box-shadow: 0 0 0 3px rgba(23,59,53,.09); }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .button { display: inline-flex; min-height: 41px; align-items: center; justify-content: center; gap: 8px; padding: 0 15px; border: 1px solid var(--green); border-radius: 2px; background: var(--green); color: white; cursor: pointer; font: inherit; font-size: 11px; font-weight: 650; text-decoration: none; }
        .button:hover { background: #102d29; }
        .button--secondary { border-color: #dce3db; background: #f5f7f3; color: var(--green); }
        .button--secondary:hover { background: #e9efe8; }
        .button:focus-visible, a:focus-visible { outline: 3px solid rgba(23,59,53,.23); outline-offset: 2px; }
        .results-meta { margin: 0 0 10px; color: var(--muted); font-size: 10px; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--line); background: var(--white); }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 13px 14px; border-bottom: 1px solid var(--line); background: #fbfcf9; color: #788078; font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; white-space: nowrap; }
        td { padding: 13px 14px; border-bottom: 1px solid #edf0eb; color: #414b42; font-size: 11px; vertical-align: middle; }
        tr:last-child td { border-bottom: 0; }
        .reference { color: var(--green); font-size: 10px; font-weight: 700; white-space: nowrap; }
        .guest { color: var(--ink); font-size: 11px; font-weight: 650; }
        .guest-email { display: block; margin-top: 3px; color: var(--muted); font-size: 9px; font-weight: 400; }
        .stay { line-height: 1.5; white-space: nowrap; }
        .stay small { display: block; color: var(--muted); font-size: 9px; }
        .payment-pill { display: inline-block; padding: 5px 7px; background: #edf4ed; color: #416748; font-size: 9px; font-weight: 700; white-space: nowrap; }
        .status-cancelled { color: #8b4d43; }
        .message { margin-bottom: 16px; padding: 12px 14px; border-left: 3px solid #a14f43; background: #f8ebe8; color: #79332d; font-size: 12px; line-height: 1.5; }
        .message--success { border-color: #557c58; background: #edf4ed; color: #345c3a; }
        .room-assignment { display: grid; min-width: 165px; grid-template-columns: minmax(76px, 1fr) auto; gap: 5px; }
        .room-assignment-fields { display: grid; grid-column: 1 / -1; grid-template-columns: repeat(2, minmax(72px, 1fr)); gap: 5px; }
        .room-assignment .control { min-height: 34px; padding: 6px 8px; font-size: 11px; }
        .room-assignment .button { min-height: 34px; padding: 0 9px; font-size: 9px; white-space: nowrap; }
        .assignment-status { max-width: 240px; margin: 4px 0 0; color: var(--muted); font-size: 9px; line-height: 1.4; overflow-wrap: anywhere; }
        .empty { padding: 38px 20px; border: 1px solid var(--line); background: var(--white); text-align: center; }
        .empty h2 { margin: 0 0 8px; color: var(--green); font-size: 23px; }
        .empty p { margin: 0; color: var(--muted); font-size: 11px; line-height: 1.55; }
        .footer { margin-top: 18px; color: #929991; font-size: 10px; }
        @media (max-width: 900px) { .filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) {
            .page { padding: 26px 14px 40px; }
            .intro { display: block; }
            .manager-name { display: inline-block; margin-top: 13px; }
            .filters { gap: 9px; padding: 11px; }
            .filters .field:first-child { grid-column: 1 / -1; }
            .filters .button { width: 100%; }
            th, td { padding: 11px 10px; }
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <header class="intro">
            <div>
                <p class="eyebrow">The Queens Hotel · Management</p>
                <h1>Paid bookings</h1>
                <p>Confirmed payments received through the booking system.</p>
            </div>
            <span class="manager-name">Signed in as <?= $displayName ?></span>
        </header>

        <section class="summary" aria-label="Paid booking summary">
            <div><p class="summary-label">All paid bookings</p><p class="summary-count"><?= number_format($paidCount) ?></p></div>
            <span class="paid-indicator">Payment verified</span>
        </section>

        <?php if (!$databaseAvailable): ?>
            <p class="message" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <?php if ($message !== ''): ?>
                <p class="message<?= $messageType === 'success' ? ' message--success' : '' ?>" role="<?= $messageType === 'success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <form class="filters" method="get" action="bookings.php" aria-label="Filter paid bookings">
                <div class="field"><label for="q">Search booking, guest, or room</label><input class="control" id="q" name="q" type="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Reference, name, email"></div>
                <div class="field"><label for="from">Check-in from</label><input class="control" id="from" name="from" type="date" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="field"><label for="to">Check-in to</label><input class="control" id="to" name="to" type="date" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>"></div>
                <button class="button" type="submit">Filter</button>
                <a class="button button--secondary" href="bookings.php">Clear</a>
            </form>

            <p class="results-meta">Showing <?= number_format(count($bookings)) ?> paid booking<?= count($bookings) === 1 ? '' : 's' ?><?= count($bookings) === 200 ? ' Â· newest 200' : '' ?></p>

            <?php if ($bookings === []): ?>
                <section class="empty"><h2>No paid bookings found</h2><p>Only bookings verified as paid by PayMongo appear here. Pending and pay-at-hotel bookings are excluded.</p></section>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th scope="col">Reference</th><th scope="col">Guest</th><th scope="col">Room type</th><th scope="col">Stay</th><th scope="col">Guests / rooms</th><th scope="col">Paid amount</th><th scope="col">Method</th><th scope="col">Paid at</th><th scope="col">Booking</th><th scope="col">Assign room number</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): ?>
                                <tr>
                                    <td class="reference"><?= htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="guest"><?= htmlspecialchars($booking['guest_name'], ENT_QUOTES, 'UTF-8') ?></span><span class="guest-email"><?= htmlspecialchars($booking['guest_email'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($booking['room_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="stay"><?= htmlspecialchars(date('M j, Y', strtotime($booking['check_in'])), ENT_QUOTES, 'UTF-8') ?><small>to <?= htmlspecialchars(date('M j, Y', strtotime($booking['check_out'])), ENT_QUOTES, 'UTF-8') ?> Â· <?= (int) $booking['nights'] ?> night<?= (int) $booking['nights'] === 1 ? '' : 's' ?></small></td>
                                    <td><?= (int) $booking['adults'] + (int) $booking['children'] ?> guests<small class="guest-email"><?= (int) $booking['room_count'] ?> room<?= (int) $booking['room_count'] === 1 ? '' : 's' ?></small></td>
                                    <td><strong><?= $money((float) $booking['total_amount'], (string) $booking['currency']) ?></strong></td>
                                    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $booking['payment_method'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= $booking['paid_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($booking['paid_at'])), ENT_QUOTES, 'UTF-8') : 'Verified' ?></td>
                                    <td class="<?= $booking['booking_status'] === 'cancelled' ? 'status-cancelled' : '' ?>"><?= htmlspecialchars(ucfirst($booking['booking_status']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($booking['booking_status'] === 'confirmed'): ?>
                                            <form class="room-assignment" method="post" action="bookings.php<?= $search !== '' || $fromDate !== '' || $toDate !== '' ? '?' . htmlspecialchars(http_build_query(array_filter(['q' => $search, 'from' => $fromDate, 'to' => $toDate], static fn ($value): bool => $value !== '')), ENT_QUOTES, 'UTF-8') : '' ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="assign_room">
                                                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                                <div class="room-assignment-fields">
                                                    <?php for ($roomIndex = 0; $roomIndex < (int) $booking['room_count']; $roomIndex++): ?>
                                                        <label class="visually-hidden" for="room-number-<?= (int) $booking['id'] ?>-<?= $roomIndex + 1 ?>">Room <?= $roomIndex + 1 ?> number for <?= htmlspecialchars($booking['booking_reference'], ENT_QUOTES, 'UTF-8') ?></label>
                                                        <input class="control" id="room-number-<?= (int) $booking['id'] ?>-<?= $roomIndex + 1 ?>" name="room_numbers[]" type="text" maxlength="20" pattern="[A-Za-z0-9-]{1,20}" placeholder="Room <?= $roomIndex + 1 ?>" value="<?= htmlspecialchars((string) ($booking['assigned_room_numbers'][$roomIndex] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                                    <?php endfor; ?>
                                                </div>
                                                <button class="button" type="submit"><?= $booking['assigned_room_numbers'] !== [] ? 'Confirm & email' : 'Assign & email' ?></button>
                                            </form>
                                            <p class="assignment-status"><?php if ($booking['assigned_room_numbers'] !== []): ?>Rooms <?= htmlspecialchars(implode(', ', $booking['assigned_room_numbers']), ENT_QUOTES, 'UTF-8') ?> · <?= $booking['room_assignment_email_sent_at'] ? 'Customer emailed' : 'Email pending' ?><?php else: ?>Not assigned<?php endif; ?></p>
                                        <?php else: ?>
                                            <span class="assignment-status">Unavailable for cancelled booking</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <p class="footer">Payment status is updated only after PayMongo verification. This register shows paid transactions, including any booking later cancelled or refunded separately.</p>
    </main>
</body>
</html>
