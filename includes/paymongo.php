<?php
declare(strict_types=1);

function paymongoConfiguration(): array
{
    $config = require __DIR__ . '/../config/paymongo.php';
    $secretKey = $config['secret_key'];

    if (!is_string($secretKey) || !str_starts_with($secretKey, 'sk_test_')) {
        throw new RuntimeException('PayMongo test mode is not configured. Set PAYMONGO_SECRET_KEY to a rotated test secret key.');
    }

    $appUrl = $config['app_url'];
    $urlParts = is_string($appUrl) ? parse_url($appUrl) : false;
    if (
        !is_string($appUrl)
        || filter_var($appUrl, FILTER_VALIDATE_URL) === false
        || !is_array($urlParts)
        || !isset($urlParts['scheme'], $urlParts['host'])
    ) {
        throw new RuntimeException('Set HOTEL_APP_URL to the absolute base URL of this hotel site.');
    }

    $isLocalHttp = $urlParts['scheme'] === 'http'
        && in_array(strtolower($urlParts['host']), ['localhost', '127.0.0.1'], true);
    if ($urlParts['scheme'] !== 'https' && !$isLocalHttp) {
        throw new RuntimeException('HOTEL_APP_URL must use HTTPS outside localhost.');
    }

    return $config;
}

function paymongoRequest(string $method, string $path, ?array $payload = null): array
{
    $config = paymongoConfiguration();
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for PayMongo Checkout.');
    }

    $curl = curl_init($config['api_url'] . $path);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the PayMongo connection.');
    }

    $headers = ['Accept: application/json'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $config['secret_key'] . ':',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ];

    if ($payload !== null) {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $options[CURLOPT_POSTFIELDS] = $jsonPayload;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }

    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($responseBody)) {
        error_log('PayMongo request failed: ' . $curlError);
        throw new RuntimeException('PayMongo could not be reached. No payment was processed.');
    }

    try {
        $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('PayMongo returned an invalid response. HTTP status: ' . $httpStatus);
        throw new RuntimeException('PayMongo returned an invalid response. No payment was processed.');
    }

    if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($response)) {
        $apiError = $response['errors'][0] ?? [];
        $apiCode = is_array($apiError) && is_string($apiError['code'] ?? null)
            ? preg_replace('/[^a-zA-Z0-9_.-]/', '', $apiError['code'])
            : 'unknown';
        error_log('PayMongo API rejected a request. HTTP status: ' . $httpStatus . '; code: ' . $apiCode);
        throw new RuntimeException('PayMongo rejected checkout (' . $apiCode . '). No payment was processed.');
    }

    return $response;
}

function paymongoCreateCheckoutSession(array $booking, string $paymentMethod): array
{
    $methodMap = [
        'card' => 'card',
        'gcash' => 'gcash',
        'maya' => 'paymaya',
        'qrph' => 'qrph',
        'bank_transfer' => 'dob',
    ];
    if (!isset($methodMap[$paymentMethod])) {
        throw new RuntimeException('This payment method is not available through PayMongo Checkout.');
    }

    $amountInCentavos = (int) round((float) $booking['total_amount'] * 100);
    if ($amountInCentavos < 100) {
        throw new RuntimeException('PayMongo requires a booking total of at least PHP 1.00.');
    }

    $config = paymongoConfiguration();
    $reference = (string) $booking['booking_reference'];
    $payload = [
        'data' => [
            'attributes' => [
                'billing' => [
                    'name' => (string) $booking['guest_name'],
                    'email' => (string) $booking['guest_email'],
                ],
                'cancel_url' => $config['app_url'] . '/customer/booking.php?confirmation=' . rawurlencode($reference) . '&payment_cancelled=1',
                'description' => 'Hotel booking ' . $reference,
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => $amountInCentavos,
                    'description' => (string) $booking['room_type'] . ' · ' . (int) $booking['nights'] . ' nights',
                    'name' => 'The Queens Hotel booking ' . $reference,
                    'quantity' => 1,
                ]],
                'metadata' => ['booking_reference' => $reference],
                'payment_method_types' => [$methodMap[$paymentMethod]],
                'reference_number' => $reference,
                'send_email_receipt' => false,
                'show_description' => true,
                'show_line_items' => true,
                'success_url' => $config['app_url'] . '/customer/booking.php?confirmation=' . rawurlencode($reference) . '&payment_return=1',
            ],
        ],
    ];

    $response = paymongoRequest('POST', '/checkout_sessions', $payload);
    $session = $response['data'] ?? null;
    $sessionId = is_array($session) ? ($session['id'] ?? null) : null;
    $attributes = is_array($session) ? ($session['attributes'] ?? null) : null;
    $checkoutUrl = is_array($attributes) ? ($attributes['checkout_url'] ?? null) : null;
    $checkoutHost = is_string($checkoutUrl) ? parse_url($checkoutUrl, PHP_URL_HOST) : false;
    $checkoutHost = is_string($checkoutHost) ? strtolower($checkoutHost) : false;
    $isPayMongoHost = $checkoutHost === 'checkout.paymongo.com'
        || (is_string($checkoutHost) && str_ends_with($checkoutHost, '.paymongo.com'));

    if (
        !is_string($sessionId)
        || !is_string($checkoutUrl)
        || !str_starts_with($checkoutUrl, 'https://')
        || !$isPayMongoHost
    ) {
        throw new RuntimeException('PayMongo returned an invalid Checkout URL. No payment was processed.');
    }

    return ['id' => $sessionId, 'url' => $checkoutUrl];
}

function paymongoRetrieveCheckoutSession(string $sessionId): array
{
    if (!preg_match('/^cs_[A-Za-z0-9_-]+$/', $sessionId)) {
        throw new RuntimeException('The saved PayMongo Checkout Session ID is invalid.');
    }

    $response = paymongoRequest('GET', '/checkout_sessions/' . rawurlencode($sessionId));
    $session = $response['data'] ?? null;
    if (!is_array($session) || ($session['id'] ?? null) !== $sessionId || !is_array($session['attributes'] ?? null)) {
        throw new RuntimeException('PayMongo could not verify this Checkout Session.');
    }

    return $session;
}

function paymongoSessionConfirmsBookingPayment(array $session, string $bookingReference, int $expectedAmountInCentavos): bool
{
    $sessionId = $session['id'] ?? null;
    $attributes = $session['attributes'] ?? null;
    if (!is_string($sessionId) || !is_array($attributes)) {
        return false;
    }

    $metadata = $attributes['metadata'] ?? [];
    if (
        ($attributes['livemode'] ?? true) !== false
        || ($attributes['reference_number'] ?? '') !== $bookingReference
        || !is_array($metadata)
        || ($metadata['booking_reference'] ?? '') !== $bookingReference
    ) {
        return false;
    }

    foreach (($attributes['payments'] ?? []) as $payment) {
        $paymentAttributes = is_array($payment) ? ($payment['attributes'] ?? null) : null;
        if (!is_array($paymentAttributes)) {
            continue;
        }

        $paymentSessionId = $paymentAttributes['checkout_session_id'] ?? $sessionId;
        if (
            ($paymentAttributes['status'] ?? '') === 'paid'
            && (int) ($paymentAttributes['amount'] ?? 0) === $expectedAmountInCentavos
            && ($paymentAttributes['currency'] ?? '') === 'PHP'
            && ($paymentAttributes['livemode'] ?? true) === false
            && $paymentSessionId === $sessionId
        ) {
            return true;
        }
    }

    $paymentIntent = $attributes['payment_intent']['attributes'] ?? null;
    return is_array($paymentIntent)
        && ($paymentIntent['status'] ?? '') === 'succeeded'
        && (int) ($paymentIntent['amount'] ?? 0) === $expectedAmountInCentavos
        && ($paymentIntent['currency'] ?? '') === 'PHP'
        && ($paymentIntent['livemode'] ?? true) === false;
}