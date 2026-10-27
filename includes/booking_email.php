<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

function sendBookingEmailNotification(PDO $pdo, int $bookingId, string $type): string
{
    $sentColumn = match ($type) {
        'confirmation' => 'confirmation_email_sent_at',
        'receipt' => 'payment_receipt_email_sent_at',
        'room_assignment' => 'room_assignment_email_sent_at',
        default => throw new InvalidArgumentException('Unsupported booking email type.'),
    };
    $lockName = 'hotel_email_' . $type . '_' . $bookingId;
    $lockAcquired = false;

    try {
        $lockQuery = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $lockQuery->execute(['lock_name' => $lockName]);
        $lockAcquired = (int) $lockQuery->fetchColumn() === 1;
        if (!$lockAcquired) {
            return 'busy';
        }

        $bookingQuery = $pdo->prepare(
            'SELECT b.id, b.booking_reference, b.guest_name, b.guest_email, b.check_in, b.check_out, b.adults, b.children, b.room_count, b.nights, b.total_amount, b.currency, b.payment_method, b.payment_status, b.booking_status, b.paymongo_checkout_url, b.paid_at, b.assigned_room_number, b.' . $sentColumn . ' AS email_sent_at, r.room_type FROM bookings b INNER JOIN rooms r ON r.id = b.room_id WHERE b.id = :booking_id LIMIT 1'
        );
        $bookingQuery->execute(['booking_id' => $bookingId]);
        $booking = $bookingQuery->fetch();
        if (!$booking || $booking['email_sent_at'] !== null) {
            return $booking ? 'already_sent' : 'failed';
        }
        if (
            $booking['booking_status'] !== 'confirmed'
            || (in_array($type, ['receipt', 'room_assignment'], true) && $booking['payment_status'] !== 'paid')
            || ($type === 'room_assignment' && (!is_string($booking['assigned_room_number']) || $booking['assigned_room_number'] === ''))
        ) {
            return 'not_ready';
        }

        $config = require __DIR__ . '/../config/mail.php';
        if (
            !filter_var($booking['guest_email'], FILTER_VALIDATE_EMAIL)
            || !filter_var($config['username'], FILTER_VALIDATE_EMAIL)
            || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)
            || $config['password'] === ''
        ) {
            error_log('Booking email was not sent: SMTP settings or guest email are incomplete.');
            return 'failed';
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $reference = (string) $booking['booking_reference'];
        $guestName = (string) $booking['guest_name'];
        $currency = (string) $booking['currency'];
        $amount = $currency . ' ' . number_format((float) $booking['total_amount'], 2);
        $paymentMethod = ucwords(str_replace('_', ' ', (string) $booking['payment_method']));
        $checkIn = date('F j, Y', strtotime((string) $booking['check_in']));
        $checkOut = date('F j, Y', strtotime((string) $booking['check_out']));
        $rows = [
            'Booking reference' => $reference,
            'Room' => (string) $booking['room_type'] . ' × ' . (int) $booking['room_count'],
            'Check-in' => $checkIn,
            'Check-out' => $checkOut,
            'Guests' => (int) $booking['adults'] . ' adults, ' . (int) $booking['children'] . ' children',
            'Length of stay' => (int) $booking['nights'] . ' night' . ((int) $booking['nights'] === 1 ? '' : 's'),
            'Total' => $amount,
        ];
        if ($type === 'receipt') {
            $rows['Payment method'] = $paymentMethod;
            $rows['Paid at'] = $booking['paid_at'] ? date('F j, Y g:i A', strtotime((string) $booking['paid_at'])) : date('F j, Y g:i A');
        } elseif ($type === 'room_assignment') {
            $assignedRoomNumbers = array_values(array_filter(array_map('trim', explode(',', (string) $booking['assigned_room_number']))));
            $rows['Assigned room numbers'] = implode(', ', $assignedRoomNumbers);
            $rows['Payment status'] = 'Paid';
        } else {
            $rows['Payment status'] = match ($booking['payment_status']) {
                'paid' => 'Paid',
                'unpaid' => 'Pay at hotel',
                default => 'Pending',
            };
        }

        $htmlRows = '';
        $textRows = [];
        foreach ($rows as $label => $value) {
            $htmlRows .= '<tr><th style="padding:9px 12px;border-bottom:1px solid #e5e9e3;text-align:left;color:#687169;font-size:12px">' . $escape($label) . '</th><td style="padding:9px 12px;border-bottom:1px solid #e5e9e3;color:#202923;font-size:12px">' . $escape((string) $value) . '</td></tr>';
            $textRows[] = $label . ': ' . $value;
        }

        $isReceipt = $type === 'receipt';
        $isRoomAssignment = $type === 'room_assignment';
        $subject = $isReceipt
            ? 'Payment receipt ' . $reference . ' | The Queens Hotel'
            : ($isRoomAssignment ? 'Your room is assigned | The Queens Hotel' : 'Booking confirmation ' . $reference . ' | The Queens Hotel');
        $heading = $isReceipt
            ? 'Payment received'
            : ($isRoomAssignment ? 'Your room is assigned' : 'Your booking is confirmed');
        $intro = $isReceipt
            ? 'Thank you. This email is your payment receipt for the confirmed stay below.'
            : ($isRoomAssignment
                ? 'Your room number is confirmed. Please find your assignment and stay details below.'
                : 'Your reservation details are below. Keep this email for your records.');
        $htmlBody = '<!doctype html><html><body style="margin:0;padding:24px;background:#f6f7f3;font-family:Arial,sans-serif;color:#202923">'
            . '<main style="max-width:620px;margin:0 auto;padding:28px;background:#fff;border:1px solid #e2e6df">'
            . '<p style="margin:0 0 9px;color:#ae8551;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase">The Queens Hotel</p>'
            . '<h1 style="margin:0 0 10px;color:#173b35;font-family:Georgia,serif;font-size:28px;font-weight:normal">' . $heading . '</h1>'
            . '<p style="margin:0 0 20px;color:#606a61;font-size:14px;line-height:1.6">Hello ' . $escape($guestName) . ', ' . $intro . '</p>'
            . '<table style="width:100%;border-collapse:collapse">' . $htmlRows . '</table>';

        if (!$isReceipt && !$isRoomAssignment && is_string($booking['paymongo_checkout_url']) && $booking['paymongo_checkout_url'] !== '' && $booking['payment_status'] === 'pending') {
            $htmlBody .= '<p style="margin:20px 0 0"><a href="' . $escape((string) $booking['paymongo_checkout_url']) . '" style="display:inline-block;padding:12px 16px;background:#173b35;color:#fff;text-decoration:none">Complete payment</a></p>';
        }
        $htmlBody .= '<p style="margin:22px 0 0;color:#747d75;font-size:11px;line-height:1.5">Please contact the hotel if any booking detail needs attention.</p></main></body></html>';
        $textBody = 'The Queens Hotel\n\n' . $heading . '\n\nHello ' . $guestName . ', ' . $intro . "\n\n" . implode("\n", $textRows);
        if (!$isReceipt && !$isRoomAssignment && is_string($booking['paymongo_checkout_url']) && $booking['paymongo_checkout_url'] !== '' && $booking['payment_status'] === 'pending') {
            $textBody .= "\n\nComplete payment: " . $booking['paymongo_checkout_url'];
        }

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string) $config['host'];
        $mailer->SMTPAuth = true;
        $mailer->Username = (string) $config['username'];
        $mailer->Password = (string) $config['password'];
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = (int) $config['port'];
        $mailer->Timeout = 20;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom((string) $config['from_email'], (string) $config['from_name']);
        $mailer->addAddress((string) $booking['guest_email'], $guestName);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();

        $markSent = $pdo->prepare('UPDATE bookings SET ' . $sentColumn . ' = CURRENT_TIMESTAMP WHERE id = :booking_id AND ' . $sentColumn . ' IS NULL');
        $markSent->execute(['booking_id' => $bookingId]);
        return $markSent->rowCount() === 1 ? 'sent' : 'failed';
    } catch (Throwable $exception) {
        error_log('Booking email delivery failed: ' . $exception->getMessage());
        return 'failed';
    } finally {
        if ($lockAcquired) {
            try {
                $releaseLock = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseLock->execute(['lock_name' => $lockName]);
            } catch (Throwable $exception) {
                error_log('Booking email lock release failed: ' . $exception->getMessage());
            }
        }
    }
}