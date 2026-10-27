<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/paymongo.php';
require_once __DIR__ . '/includes/booking_email.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function findBookingReference(mixed $value): ?string
{
    if (!is_array($value)) {
        return null;
    }

    foreach ($value as $key => $nestedValue) {
        if ($key === 'booking_reference' && is_string($nestedValue) && preg_match('/^MRD-[A-Z0-9]{12}$/', $nestedValue)) {
            return $nestedValue;
        }
        if (is_array($nestedValue)) {
            $reference = findBookingReference($nestedValue);
            if ($reference !== null) {
                return $reference;
            }
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'method_not_allowed']);
}

$config = require __DIR__ . '/config/paymongo.php';
$webhookSecret = $config['webhook_secret'];
$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
if (!is_string($webhookSecret) || $webhookSecret === '' || !is_string($rawBody) || strlen($rawBody) > 1048576 || !is_string($signatureHeader)) {
    respond(400, ['error' => 'invalid_webhook_request']);
}

$signatureParts = [];
foreach (explode(',', $signatureHeader) as $part) {
    $segments = explode('=', trim($part), 2);
    if (count($segments) === 2) {
        $signatureParts[$segments[0]] = $segments[1];
    }
}

$timestamp = $signatureParts['t'] ?? '';
$testSignature = $signatureParts['te'] ?? '';
if ($timestamp === '' || $testSignature === '') {
    respond(401, ['error' => 'invalid_webhook_signature']);
}

$expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
if (!hash_equals($expectedSignature, $testSignature)) {
    respond(401, ['error' => 'invalid_webhook_signature']);
}

try {
    $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respond(400, ['error' => 'invalid_json']);
}

$eventData = $event['data'] ?? [];
$eventType = is_array($eventData)
    ? ($eventData['type'] ?? $eventData['attributes']['type'] ?? '')
    : '';
if (!is_string($eventType) || !str_contains($eventType, 'paid')) {
    respond(200, ['received' => true, 'ignored' => true]);
}

$bookingReference = findBookingReference($event);
if ($bookingReference === null) {
    respond(200, ['received' => true, 'ignored' => true]);
}

try {
    $pdo = getDatabaseConnection();
    $bookingQuery = $pdo->prepare(
        "SELECT id, booking_reference, payment_status, booking_status, paymongo_checkout_session_id FROM bookings WHERE booking_reference = :reference AND currency = 'PHP' AND payment_method <> 'pay_at_hotel' LIMIT 1"
    );
    $bookingQuery->execute(['reference' => $bookingReference]);
    $booking = $bookingQuery->fetch();

    if (!$booking || $booking['booking_status'] !== 'confirmed' || !is_string($booking['paymongo_checkout_session_id'])) {
        respond(200, ['received' => true, 'ignored' => true]);
    }

    if ($booking['payment_status'] === 'paid') {
        $receiptStatus = sendBookingEmailNotification($pdo, (int) $booking['id'], 'receipt');
        if (in_array($receiptStatus, ['failed', 'busy'], true)) {
            respond(500, ['error' => 'receipt_email_pending_retry']);
        }
        respond(200, ['received' => true, 'already_paid' => true]);
    }
    if ($booking['payment_status'] !== 'pending') {
        respond(200, ['received' => true, 'ignored' => true]);
    }

    $checkoutSession = paymongoRetrieveCheckoutSession($booking['paymongo_checkout_session_id']);
    $bookingTotalQuery = $pdo->prepare('SELECT total_amount FROM bookings WHERE id = :booking_id LIMIT 1');
    $bookingTotalQuery->execute(['booking_id' => $booking['id']]);
    $expectedAmountInCentavos = (int) round((float) $bookingTotalQuery->fetchColumn() * 100);
    if (!paymongoSessionConfirmsBookingPayment($checkoutSession, $bookingReference, $expectedAmountInCentavos)) {
        respond(200, ['received' => true, 'ignored' => true]);
    }

    $markPaid = $pdo->prepare(
        "UPDATE bookings SET payment_status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE id = :booking_id AND payment_status = 'pending' AND booking_status = 'confirmed'"
    );
    $markPaid->execute(['booking_id' => $booking['id']]);
    $receiptStatus = sendBookingEmailNotification($pdo, (int) $booking['id'], 'receipt');
    if (in_array($receiptStatus, ['failed', 'busy'], true)) {
        respond(500, ['error' => 'receipt_email_pending_retry']);
    }
    respond(200, ['received' => true]);
} catch (Throwable $exception) {
    error_log('PayMongo webhook processing failed: ' . $exception->getMessage());
    respond(500, ['error' => 'webhook_processing_failed']);
}