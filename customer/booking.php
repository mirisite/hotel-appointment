<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/booking_email.php';

if (($_SESSION['user_role'] ?? '') !== 'customer' || !isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$today = new DateTimeImmutable('today');
$tomorrow = $today->modify('+1 day');
$guestName = (string) ($_SESSION['user_name'] ?? 'Guest');
$guestEmail = (string) ($_SESSION['user_email'] ?? '');
$paymentMethods = [
    'card' => 'Credit / debit card',
    'gcash' => 'GCash',
    'maya' => 'Maya',
    'qrph' => 'QR Ph',
    'bank_transfer' => 'Bank transfer',
    'pay_at_hotel' => 'Pay at hotel',
];
$extraOptions = [
    'breakfast' => ['label' => 'Breakfast', 'price' => 650.00, 'basis' => 'per guest, per night'],
    'airport_transfer' => ['label' => 'Airport transfer', 'price' => 1800.00, 'basis' => 'per stay'],
    'late_checkout' => ['label' => 'Late checkout', 'price' => 900.00, 'basis' => 'per room'],
];
$methodLabels = [
    'pending' => 'Payment pending',
    'unpaid' => 'Pay at hotel',
    'paid' => 'Paid',
    'failed' => 'Payment failed',
    'refunded' => 'Refunded',
];
$contactEmail = getenv('HOTEL_CONTACT_EMAIL') ?: '';
$contactPhone = getenv('HOTEL_CONTACT_PHONE') ?: '';
$contactAddress = getenv('HOTEL_CONTACT_ADDRESS') ?: '';
$paymongoConfig = require __DIR__ . '/../config/paymongo.php';
$confirmation = null;
$emailConfirmationSent = false;
$paymentReceiptSent = false;
$paymentVerificationMessage = '';
$message = '';
$messageType = 'error';
$databaseAvailable = true;
$rooms = [];
$roomAvailability = [];
$selectedRoom = null;
$searchInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$checkInRaw = $searchInput['check_in'] ?? $today->format('Y-m-d');
$checkOutRaw = $searchInput['check_out'] ?? $tomorrow->format('Y-m-d');
$checkInDate = is_string($checkInRaw) ? DateTimeImmutable::createFromFormat('!Y-m-d', $checkInRaw) : false;
$checkOutDate = is_string($checkOutRaw) ? DateTimeImmutable::createFromFormat('!Y-m-d', $checkOutRaw) : false;
$datesAreValid = !(
    !$checkInDate || !$checkOutDate
    || $checkInDate->format('Y-m-d') !== $checkInRaw
    || $checkOutDate->format('Y-m-d') !== $checkOutRaw
    || $checkInDate < $today
    || $checkOutDate <= $checkInDate
    || $checkInDate->diff($checkOutDate)->days > 365
);
if (!$datesAreValid) {
    $checkInDate = $today;
    $checkOutDate = $tomorrow;
    $message = 'Choose valid future dates for a stay of up to 365 nights.';
}

$adultsRaw = $searchInput['adults'] ?? '1';
$childrenRaw = $searchInput['children'] ?? '0';
$roomCountRaw = $searchInput['room_count'] ?? '1';
$adults = is_string($adultsRaw) ? filter_var($adultsRaw, FILTER_VALIDATE_INT) : false;
$children = is_string($childrenRaw) ? filter_var($childrenRaw, FILTER_VALIDATE_INT) : false;
$roomCount = is_string($roomCountRaw) ? filter_var($roomCountRaw, FILTER_VALIDATE_INT) : false;
$guestCountsAreValid = $adults !== false && $adults >= 1 && $adults <= 30
    && $children !== false && $children >= 0 && $children <= 30;
$roomCountIsValid = $roomCount !== false && $roomCount >= 1 && $roomCount <= 20;
$adults = $adults !== false && $adults >= 1 && $adults <= 30 ? $adults : 1;
$children = $children !== false && $children >= 0 && $children <= 30 ? $children : 0;
$roomCount = $roomCount !== false && $roomCount >= 1 && $roomCount <= 20 ? $roomCount : 1;
$nights = (int) $checkInDate->diff($checkOutDate)->days;
$stayDates = [];
foreach (new DatePeriod($checkInDate, new DateInterval('P1D'), $checkOutDate) as $stayDate) {
    $stayDates[] = $stayDate->format('Y-m-d');
}

$requestedRoomId = $searchInput['room_id'] ?? '';
$requestedRoomId = is_string($requestedRoomId) || is_int($requestedRoomId) ? filter_var($requestedRoomId, FILTER_VALIDATE_INT) : false;
$requestedRoomId = $requestedRoomId !== false && $requestedRoomId > 0 ? $requestedRoomId : null;
$selectedExtras = $searchInput['extras'] ?? [];
if (!is_array($selectedExtras)) {
    $selectedExtras = [];
}
$selectedExtras = array_values(array_unique(array_filter(
    $selectedExtras,
    static fn ($extra): bool => is_string($extra) && array_key_exists($extra, $extraOptions)
)));
$paymentMethodInput = $searchInput['payment_method'] ?? 'pay_at_hotel';
$paymentMethodIsValid = is_string($paymentMethodInput) && array_key_exists($paymentMethodInput, $paymentMethods);
$paymentMethod = $paymentMethodIsValid ? $paymentMethodInput : 'pay_at_hotel';
if (!$paymentMethodIsValid) {
    $paymentMethod = 'pay_at_hotel';
}

$confirmationReference = $_GET['confirmation'] ?? '';
if (is_string($confirmationReference) && preg_match('/^MRD-[A-Z0-9-]{12,28}$/', $confirmationReference)) {
    try {
        $pdo = getDatabaseConnection();
        $confirmationQuery = $pdo->prepare(
            'SELECT b.*, r.room_type, r.price_per_night, r.max_guests, r.bed_count, r.bed_type FROM bookings b INNER JOIN rooms r ON r.id = b.room_id WHERE b.booking_reference = :reference AND b.customer_id = :customer_id LIMIT 1'
        );
        $confirmationQuery->execute([
            'reference' => $confirmationReference,
            'customer_id' => (int) $_SESSION['user_id'],
        ]);
        $confirmation = $confirmationQuery->fetch() ?: null;
        if (
            $confirmation
            && ($_GET['payment_return'] ?? '') === '1'
            && $confirmation['payment_status'] === 'pending'
            && is_string($confirmation['paymongo_checkout_session_id'])
        ) {
            try {
                $paymongoSession = paymongoRetrieveCheckoutSession($confirmation['paymongo_checkout_session_id']);
                $expectedAmountInCentavos = (int) round((float) $confirmation['total_amount'] * 100);
                $isValidPaidSession = paymongoSessionConfirmsBookingPayment(
                    $paymongoSession,
                    $confirmationReference,
                    $expectedAmountInCentavos
                );

                if ($isValidPaidSession) {
                    $markPaid = $pdo->prepare(
                        "UPDATE bookings SET payment_status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE id = :booking_id AND payment_status = 'pending' AND booking_status = 'confirmed'"
                    );
                    $markPaid->execute(['booking_id' => $confirmation['id']]);
                    $confirmationQuery->execute([
                        'reference' => $confirmationReference,
                        'customer_id' => (int) $_SESSION['user_id'],
                    ]);
                    $confirmation = $confirmationQuery->fetch() ?: $confirmation;
                } else {
                    $paymentVerificationMessage = 'PayMongo has not confirmed this payment yet. Your booking still shows pending; refresh this page shortly if you completed checkout.';
                }
            } catch (Throwable $exception) {
                error_log('PayMongo return verification failed: ' . $exception->getMessage());
                $paymentVerificationMessage = 'We could not verify the payment yet. The booking remains pending until PayMongo confirms it.';
            }
        }
        if ($confirmation && $confirmation['booking_status'] === 'confirmed') {
            $confirmationEmailStatus = sendBookingEmailNotification($pdo, (int) $confirmation['id'], 'confirmation');
            $emailConfirmationSent = in_array($confirmationEmailStatus, ['sent', 'already_sent'], true)
                || $confirmation['confirmation_email_sent_at'] !== null;
            if ($confirmation['payment_status'] === 'paid') {
                $receiptEmailStatus = sendBookingEmailNotification($pdo, (int) $confirmation['id'], 'receipt');
                $paymentReceiptSent = in_array($receiptEmailStatus, ['sent', 'already_sent'], true)
                    || $confirmation['payment_receipt_email_sent_at'] !== null;
            }
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $databaseAvailable = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($searchInput['action'] ?? '') === 'place_booking') {
    $submittedToken = $searchInput['csrf_token'] ?? '';
    if ($message !== '') {
        $messageType = 'error';
    } elseif (!$guestCountsAreValid || !$roomCountIsValid) {
        $message = 'Enter valid guest and room counts before confirming.';
    } elseif (!$paymentMethodIsValid) {
        $message = 'Choose a valid payment method.';
    } elseif (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Refresh the page and review your booking again.';
    } elseif ($requestedRoomId === null || $nights < 1 || $roomCount < 1) {
        $message = 'Check your room, dates, and room count before confirming.';
    } elseif (!array_key_exists($paymentMethod, $paymentMethods)) {
        $message = 'Choose a valid payment method.';
    } else {
        $pdo = null;
        try {
            $pdo = getDatabaseConnection();
            $bookingColumns = $pdo->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $requiredBookingColumns = ['currency', 'paymongo_checkout_session_id', 'paymongo_checkout_url', 'payment_access_token', 'paid_at', 'confirmation_email_sent_at', 'payment_receipt_email_sent_at'];
            $missingBookingColumns = array_diff($requiredBookingColumns, $bookingColumns);
            if ($missingBookingColumns !== []) {
                throw new DomainException('The booking database is missing PayMongo fields. Run the latest database_migration.sql in phpMyAdmin before creating bookings.');
            }
            $pdo->beginTransaction();

            $roomQuery = $pdo->prepare('SELECT * FROM rooms WHERE id = :room_id FOR UPDATE');
            $roomQuery->execute(['room_id' => $requestedRoomId]);
            $roomForBooking = $roomQuery->fetch();
            if (!$roomForBooking) {
                throw new DomainException('That room is no longer available. Please choose another room.');
            }

            $totalGuests = $adults + $children;
            if (
                $adults > (int) $roomForBooking['adults_allowed'] * $roomCount
                || $children > (int) $roomForBooking['children_allowed'] * $roomCount
                || $totalGuests > (int) $roomForBooking['max_guests'] * $roomCount
            ) {
                throw new DomainException('This room type cannot accommodate the selected guests and room count.');
            }

            $availabilityQuery = $pdo->prepare(
                'SELECT availability_date, available_rooms FROM room_type_availability WHERE room_type = :room_type AND availability_date >= :check_in AND availability_date < :check_out ORDER BY availability_date FOR UPDATE'
            );
            $availabilityQuery->execute([
                'room_type' => $roomForBooking['room_type'],
                'check_in' => $checkInDate->format('Y-m-d'),
                'check_out' => $checkOutDate->format('Y-m-d'),
            ]);
            $lockedAvailability = $availabilityQuery->fetchAll();
            if (count($lockedAvailability) !== $nights) {
                throw new DomainException('The hotel has not set availability for every night of this stay. Choose another date range or contact the hotel.');
            }
            foreach ($lockedAvailability as $inventoryDay) {
                if ((int) $inventoryDay['available_rooms'] < $roomCount) {
                    throw new DomainException('There are not enough rooms available on ' . date('M j, Y', strtotime($inventoryDay['availability_date'])) . '. Search again to see current availability.');
                }
            }

            $extraDetails = [];
            $extraServicesTotal = 0.0;
            foreach ($selectedExtras as $extraKey) {
                $extra = $extraOptions[$extraKey];
                $quantity = match ($extraKey) {
                    'breakfast' => $totalGuests * $nights,
                    'late_checkout' => $roomCount,
                    default => 1,
                };
                $lineTotal = $extra['price'] * $quantity;
                $extraDetails[] = [
                    'key' => $extraKey,
                    'name' => $extra['label'],
                    'quantity' => $quantity,
                    'unit_price' => number_format($extra['price'], 2, '.', ''),
                    'total' => number_format($lineTotal, 2, '.', ''),
                ];
                $extraServicesTotal += $lineTotal;
            }

            $nightlyRate = (float) $roomForBooking['price_per_night'];
            $roomSubtotal = $nightlyRate * $nights * $roomCount;
            $serviceFeeTotal = (float) $roomForBooking['service_fee'] * $roomCount;
            $taxTotal = ($roomSubtotal + $extraServicesTotal) * (float) $roomForBooking['tax_percent'] / 100;
            $discountTotal = 0.0;
            $totalAmount = $roomSubtotal + $extraServicesTotal + $taxTotal + $serviceFeeTotal - $discountTotal;
            $paymentStatus = $paymentMethod === 'pay_at_hotel' ? 'unpaid' : 'pending';

            $decrementAvailability = $pdo->prepare(
                'UPDATE room_type_availability SET available_rooms = available_rooms - :room_count WHERE room_type = :room_type AND availability_date = :availability_date AND available_rooms >= :minimum_rooms'
            );
            foreach ($lockedAvailability as $inventoryDay) {
                $decrementAvailability->execute([
                    'room_count' => $roomCount,
                    'room_type' => $roomForBooking['room_type'],
                    'availability_date' => $inventoryDay['availability_date'],
                    'minimum_rooms' => $roomCount,
                ]);
                if ($decrementAvailability->rowCount() !== 1) {
                    throw new DomainException('Availability changed while confirming. Please search again.');
                }
            }

            $bookingReference = 'MRD-' . strtoupper(bin2hex(random_bytes(6)));
            $paymentAccessToken = $paymentMethod === 'qrph' ? bin2hex(random_bytes(32)) : null;
            $bookingInsert = $pdo->prepare(
                'INSERT INTO bookings (booking_reference, customer_id, room_id, guest_name, guest_email, check_in, check_out, adults, children, room_count, nights, room_subtotal, extra_services, extra_services_total, tax_total, discount_total, service_fee_total, total_amount, currency, payment_method, payment_status) VALUES (:reference, :customer_id, :room_id, :guest_name, :guest_email, :check_in, :check_out, :adults, :children, :room_count, :nights, :room_subtotal, :extra_services, :extra_services_total, :tax_total, :discount_total, :service_fee_total, :total_amount, :currency, :payment_method, :payment_status)'
            );
            $bookingInsert->execute([
                'reference' => $bookingReference,
                'customer_id' => (int) $_SESSION['user_id'],
                'room_id' => $requestedRoomId,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'check_in' => $checkInDate->format('Y-m-d'),
                'check_out' => $checkOutDate->format('Y-m-d'),
                'adults' => $adults,
                'children' => $children,
                'room_count' => $roomCount,
                'nights' => $nights,
                'room_subtotal' => number_format($roomSubtotal, 2, '.', ''),
                'extra_services' => json_encode($extraDetails, JSON_THROW_ON_ERROR),
                'extra_services_total' => number_format($extraServicesTotal, 2, '.', ''),
                'tax_total' => number_format($taxTotal, 2, '.', ''),
                'discount_total' => number_format($discountTotal, 2, '.', ''),
                'service_fee_total' => number_format($serviceFeeTotal, 2, '.', ''),
                'total_amount' => number_format($totalAmount, 2, '.', ''),
                'currency' => 'PHP',
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
            ]);
            $bookingId = (int) $pdo->lastInsertId();
            $pdo->commit();

            if ($paymentMethod !== 'pay_at_hotel') {
                try {
                    $checkoutSession = paymongoCreateCheckoutSession([
                        'booking_reference' => $bookingReference,
                        'guest_name' => $guestName,
                        'guest_email' => $guestEmail,
                        'room_type' => $roomForBooking['room_type'],
                        'nights' => $nights,
                        'total_amount' => $totalAmount,
                    ], $paymentMethod);

                    $saveCheckout = $pdo->prepare(
                        'UPDATE bookings SET paymongo_checkout_session_id = :session_id, paymongo_checkout_url = :checkout_url, payment_access_token = :access_token WHERE booking_reference = :reference AND customer_id = :customer_id AND payment_status = :payment_status AND booking_status = :booking_status'
                    );
                    $saveCheckout->execute([
                        'session_id' => $checkoutSession['id'],
                        'checkout_url' => $checkoutSession['url'],
                        'access_token' => $paymentAccessToken,
                        'reference' => $bookingReference,
                        'customer_id' => (int) $_SESSION['user_id'],
                        'payment_status' => 'pending',
                        'booking_status' => 'confirmed',
                    ]);
                    if ($saveCheckout->rowCount() !== 1) {
                        throw new RuntimeException('Could not attach the PayMongo Checkout Session to the booking.');
                    }

                    if ($paymentMethod === 'qrph') {
                        header('Location: booking.php?confirmation=' . rawurlencode($bookingReference) . '&show_payment_qr=1', true, 303);
                    } else {
                        header('Location: ' . $checkoutSession['url'], true, 303);
                    }
                    exit;
                } catch (Throwable $paymentException) {
                    error_log('PayMongo checkout creation failed: ' . $paymentException->getMessage());
                        $paymentError = $paymentException->getMessage();
                        if (str_contains($paymentError, 'PAYMONGO_SECRET_KEY')) {
                            $customerPaymentError = 'PayMongo test mode is not configured on the server. Set PAYMONGO_SECRET_KEY in the Apache/PHP environment, then restart Apache.';
                        } elseif (str_contains($paymentError, 'HOTEL_APP_URL')) {
                            $customerPaymentError = 'Set HOTEL_APP_URL to this site’s full HTTPS address (HTTP localhost is permitted for local testing), then retry.';
                        } elseif (str_contains($paymentError, 'cURL extension')) {
                            $customerPaymentError = 'Enable the PHP cURL extension in XAMPP, restart Apache, and retry checkout.';
                        } elseif (preg_match('/PayMongo rejected checkout \(([a-zA-Z0-9_.-]+)\)/', $paymentError, $errorCodeMatch)) {
                            $customerPaymentError = 'PayMongo rejected this checkout (' . $errorCodeMatch[1] . '). Check the PHP/Apache error log, test key mode, currency, and enabled payment methods.';
                        } else {
                            $customerPaymentError = 'PayMongo checkout failed. Check the PHP/Apache error log for a safe error code, then retry.';
                        }
                    try {
                        $pdo->beginTransaction();
                        $cancelUnpaidBooking = $pdo->prepare(
                            "UPDATE bookings SET booking_status = 'cancelled', payment_status = 'failed' WHERE booking_reference = :reference AND customer_id = :customer_id AND payment_status = 'pending' AND paymongo_checkout_session_id IS NULL"
                        );
                        $cancelUnpaidBooking->execute([
                            'reference' => $bookingReference,
                            'customer_id' => (int) $_SESSION['user_id'],
                        ]);
                        if ($cancelUnpaidBooking->rowCount() === 1) {
                            $restoreInventory = $pdo->prepare(
                                'UPDATE room_type_availability SET available_rooms = available_rooms + :room_count WHERE room_type = :room_type AND availability_date = :availability_date'
                            );
                            foreach ($stayDates as $stayDate) {
                                $restoreInventory->execute([
                                    'room_count' => $roomCount,
                                    'room_type' => $roomForBooking['room_type'],
                                    'availability_date' => $stayDate,
                                ]);
                            }
                        }
                        $pdo->commit();
                    } catch (Throwable $compensationException) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('PayMongo booking compensation failed: ' . $compensationException->getMessage());
                    }

                    throw new DomainException(($customerPaymentError ?? 'PayMongo checkout could not be started.') . ' No payment was taken, and the booking was cancelled. Please try again or choose pay at hotel.');
                }
            }

            sendBookingEmailNotification($pdo, $bookingId, 'confirmation');
            header('Location: booking.php?confirmation=' . rawurlencode($bookingReference));
            exit;
        } catch (DomainException $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $message = 'We could not complete this booking. Check the database setup and try again.';
        }
    }
}

if (!$confirmation) {
    try {
        $pdo = getDatabaseConnection();
        $rooms = $pdo->query(
            'SELECT r.*, p.file_path AS cover_photo FROM rooms r LEFT JOIN room_photos p ON p.room_id = r.id AND p.sort_order = (SELECT MIN(p2.sort_order) FROM room_photos p2 WHERE p2.room_id = r.id) ORDER BY r.price_per_night, r.room_type'
        )->fetchAll();
        $availabilityQuery = $pdo->prepare(
            'SELECT room_type, availability_date, available_rooms FROM room_type_availability WHERE availability_date >= :check_in AND availability_date < :check_out'
        );
        $availabilityQuery->execute([
            'check_in' => $checkInDate->format('Y-m-d'),
            'check_out' => $checkOutDate->format('Y-m-d'),
        ]);
        foreach ($availabilityQuery->fetchAll() as $inventoryDay) {
            $roomAvailability[$inventoryDay['room_type']][$inventoryDay['availability_date']] = (int) $inventoryDay['available_rooms'];
        }

        if ($requestedRoomId !== null) {
            $roomQuery = $pdo->prepare(
                'SELECT r.*, p.file_path AS cover_photo FROM rooms r LEFT JOIN room_photos p ON p.room_id = r.id AND p.sort_order = (SELECT MIN(p2.sort_order) FROM room_photos p2 WHERE p2.room_id = r.id) WHERE r.id = :room_id LIMIT 1'
            );
            $roomQuery->execute(['room_id' => $requestedRoomId]);
            $selectedRoom = $roomQuery->fetch() ?: null;
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $databaseAvailable = false;
    }
}

$roomOptions = [];
foreach ($rooms as $room) {
    if (
        $adults > (int) $room['adults_allowed'] * $roomCount
        || $children > (int) $room['children_allowed'] * $roomCount
        || $adults + $children > (int) $room['max_guests'] * $roomCount
    ) {
        continue;
    }
    $minimumAvailable = null;
    $hasAllDates = true;
    foreach ($stayDates as $stayDate) {
        if (!isset($roomAvailability[$room['room_type']]) || !array_key_exists($stayDate, $roomAvailability[$room['room_type']])) {
            $hasAllDates = false;
            break;
        }
        $countForDate = $roomAvailability[$room['room_type']][$stayDate];
        $minimumAvailable = $minimumAvailable === null ? $countForDate : min($minimumAvailable, $countForDate);
    }
    $room['minimum_available'] = $minimumAvailable;
    $room['can_book'] = $hasAllDates && $minimumAvailable >= $roomCount;
    $roomOptions[] = $room;
}

$selectedRoomCanBook = false;
if ($selectedRoom) {
    foreach ($roomOptions as $roomOption) {
        if ((int) $roomOption['id'] === (int) $selectedRoom['id']) {
            $selectedRoomCanBook = (bool) $roomOption['can_book'];
            $selectedRoom['minimum_available'] = $roomOption['minimum_available'];
            break;
        }
    }
}

$roomSubtotal = $selectedRoom ? (float) $selectedRoom['price_per_night'] * $nights * $roomCount : 0.0;
$serviceFeeTotal = $selectedRoom ? (float) $selectedRoom['service_fee'] * $roomCount : 0.0;
$extraDetails = [];
$extraServicesTotal = 0.0;
$totalGuests = $adults + $children;
foreach ($selectedExtras as $extraKey) {
    $extra = $extraOptions[$extraKey];
    $quantity = match ($extraKey) {
        'breakfast' => $totalGuests * $nights,
        'late_checkout' => $roomCount,
        default => 1,
    };
    $lineTotal = $extra['price'] * $quantity;
    $extraDetails[] = ['name' => $extra['label'], 'quantity' => $quantity, 'unit_price' => $extra['price'], 'total' => $lineTotal];
    $extraServicesTotal += $lineTotal;
}
$taxTotal = $selectedRoom ? ($roomSubtotal + $extraServicesTotal) * (float) $selectedRoom['tax_percent'] / 100 : 0.0;
$discountTotal = 0.0;
$serviceFeeTotal = round($serviceFeeTotal, 2);
$taxTotal = round($taxTotal, 2);
$extraServicesTotal = round($extraServicesTotal, 2);
$roomSubtotal = round($roomSubtotal, 2);
$totalAmount = round($roomSubtotal + $extraServicesTotal + $taxTotal + $serviceFeeTotal - $discountTotal, 2);
$money = static fn (float $amount): string => '₱' . number_format($amount, 2);
$currencySymbol = $confirmation && ($confirmation['currency'] ?? '') === 'USD' ? '$' : '₱';
$confirmationStatusLabel = $confirmation ? ($methodLabels[$confirmation['payment_status']] ?? 'Pending') : '';
$paymentLandingUrl = '';
if (
    $confirmation
    && ($confirmation['payment_method'] ?? '') === 'qrph'
    && is_string($confirmation['payment_access_token'] ?? null)
    && preg_match('/^[a-f0-9]{64}$/', $confirmation['payment_access_token'])
    && is_string($paymongoConfig['app_url'] ?? null)
    && $paymongoConfig['app_url'] !== ''
) {
    $paymentLandingUrl = rtrim($paymongoConfig['app_url'], '/') . '/customer/payment.php?token=' . rawurlencode($confirmation['payment_access_token']);
}
$appUrlHost = is_string($paymongoConfig['app_url'] ?? null) ? parse_url($paymongoConfig['app_url'], PHP_URL_HOST) : false;
$paymentUrlIsLocal = is_string($appUrlHost) && in_array(strtolower($appUrlHost), ['localhost', '127.0.0.1'], true);
$cancellationInformation = 'Contact the hotel as soon as possible to request a cancellation. Refund eligibility depends on the property cancellation terms and payment status.';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title><?= $confirmation ? 'Booking Confirmation' : 'Book a Room' ?> | The Queens Hotel</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; --red: #8a4c41; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1280px); margin: 0 auto; padding: 34px clamp(18px, 4vw, 56px) 70px; }
        .eyebrow { margin: 0 0 9px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { margin-bottom: 8px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(33px, 4vw, 45px); font-weight: 400; line-height: 1.1; }
        h2 { font-family: Georgia, "Times New Roman", serif; font-weight: 400; }
        .intro { margin-bottom: 24px; }
        .intro p:last-child { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.55; }
        .message { margin-bottom: 18px; padding: 12px 14px; border-left: 3px solid #9f594d; background: #f8ebe8; color: #743a32; font-size: 12px; line-height: 1.55; }
        .search-panel, .panel { margin-bottom: 20px; padding: clamp(17px, 3vw, 26px); border: 1px solid var(--line); background: var(--white); }
        .search-panel h2, .panel h2 { margin: 0 0 16px; color: var(--green); font-size: 22px; }
        .search-fields { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); align-items: end; gap: 12px; }
        .field { min-width: 0; }
        .field label { display: block; margin-bottom: 7px; color: #455047; font-size: 10px; font-weight: 700; }
        .control { display: block; width: 100%; min-height: 43px; padding: 9px 11px; border: 1px solid #dce1d9; border-radius: 3px; outline: none; background: #fff; color: var(--ink); font: inherit; font-size: 12px; }
        .control:focus { border-color: var(--green); box-shadow: 0 0 0 3px rgba(23,59,53,.09); }
        .button { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; gap: 9px; padding: 0 17px; border: 1px solid var(--green); border-radius: 3px; background: var(--green); color: #fff; cursor: pointer; font: inherit; font-size: 11px; font-weight: 700; text-decoration: none; transition: background 150ms ease, transform 150ms ease; }
        .button:hover { transform: translateY(-1px); background: #102d29; }
        .button:focus-visible, a:focus-visible { outline: 3px solid rgba(23,59,53,.25); outline-offset: 3px; }
        .button--secondary { border-color: #d9e2d8; background: #f2f5f0; color: var(--green); }
        .button--secondary:hover { background: #e7eee6; }
        .search-button { width: 100%; }
        .room-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 13px; margin-bottom: 20px; }
        .room-option { display: grid; min-width: 0; grid-template-columns: 150px minmax(0, 1fr); overflow: hidden; border: 1px solid var(--line); background: #fff; }
        .room-option--selected { border-color: var(--green); box-shadow: 0 0 0 1px var(--green); }
        .room-option img, .room-placeholder { display: grid; width: 100%; height: 100%; min-height: 146px; place-items: center; object-fit: cover; background: #e8eee7; color: #728074; font-size: 10px; }
        .room-option-body { min-width: 0; padding: 15px; }
        .room-option h3 { margin: 0 0 6px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 19px; font-weight: 400; }
        .room-meta { margin: 0 0 7px; color: var(--muted); font-size: 10px; line-height: 1.5; }
        .room-rate { margin: 0 0 10px; color: var(--ink); font-size: 11px; font-weight: 700; }
        .room-status { margin: 0 0 10px; color: #49684f; font-size: 10px; }
        .room-status--no { color: var(--red); }
        .price-layout { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(280px, .75fr); align-items: start; gap: 18px; }
        .selection { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; padding-bottom: 17px; border-bottom: 1px solid #edf0eb; }
        .selection img, .selection-placeholder { width: 104px; height: 78px; flex: 0 0 auto; object-fit: cover; background: #e8eee7; }
        .selection h3 { margin: 0 0 5px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 19px; font-weight: 400; }
        .selection p { margin: 0; color: var(--muted); font-size: 10px; line-height: 1.5; }
        .extras { display: grid; gap: 9px; }
        .booking-extras { margin-top: 23px; padding-top: 17px; border-top: 1px solid var(--line); }
        .booking-extras h2 { margin: 0 0 12px; color: var(--green); font-size: 20px; }
        .extra-option { display: flex; min-height: 57px; align-items: center; justify-content: space-between; gap: 12px; padding: 9px 11px; border: 1px solid #e6eae4; background: #fff; }
        .extra-choice { display: flex; align-items: center; gap: 9px; color: #3e4940; font-size: 11px; font-weight: 650; }
        .extra-choice input { width: 16px; height: 16px; accent-color: var(--green); }
        .extra-rate { color: var(--muted); font-size: 10px; text-align: right; }
        .payment-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        .payment-option { display: flex; min-height: 48px; align-items: center; gap: 9px; padding: 9px 10px; border: 1px solid #e5e9e3; color: #455047; cursor: pointer; font-size: 10px; }
        .payment-option:has(input:checked) { border-color: var(--green); background: #f3f7f2; color: var(--green); }
        .payment-option input { accent-color: var(--green); }
        .secure-note { margin: 12px 0 0; color: #778177; font-size: 10px; line-height: 1.55; }
        .summary { position: sticky; top: 18px; }
        .summary h2 { margin-bottom: 5px; }
        .summary-caption { margin-bottom: 15px; color: var(--muted); font-size: 10px; line-height: 1.5; }
        .summary-lines { display: grid; gap: 10px; }
        .summary-line { display: flex; justify-content: space-between; gap: 14px; color: #647066; font-size: 11px; line-height: 1.4; }
        .summary-line strong { color: #354137; font-weight: 650; text-align: right; }
        .summary-line--total { margin-top: 3px; padding-top: 12px; border-top: 1px solid var(--line); color: var(--ink); font-weight: 700; }
        .summary-line--total strong { color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 24px; font-weight: 400; }
        .summary-disclaimer { margin: 14px 0 0; color: #858d85; font-size: 9px; line-height: 1.55; }
        .payment-section { margin-top: 17px; padding-top: 16px; border-top: 1px solid var(--line); }
        .payment-section h3 { margin: 0 0 10px; color: var(--green); font-size: 13px; font-weight: 700; }
        .confirm-button { width: 100%; margin-top: 16px; }
        .fine-print { margin: 13px 0 0; color: #858d85; font-size: 9px; line-height: 1.55; }
        .empty { padding: 30px 18px; border: 1px solid var(--line); background: #fff; color: var(--muted); font-size: 12px; line-height: 1.6; text-align: center; }
        .confirmation { max-width: 860px; margin: 14px auto; padding: clamp(20px, 4vw, 42px); border: 1px solid var(--line); background: #fff; }
        .confirmation-top { display: flex; align-items: start; justify-content: space-between; gap: 20px; padding-bottom: 23px; border-bottom: 1px solid var(--line); }
        .confirmation-top h1 { font-size: clamp(30px, 4vw, 42px); }
        .confirmation-top p:last-child { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.55; }
        .reference { padding: 11px 13px; background: #eef3ed; color: var(--green); font-size: 12px; font-weight: 750; white-space: nowrap; }
        .confirm-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; padding: 22px 0; }
        .confirm-block { padding: 14px; border: 1px solid #ebeee9; }
        .confirm-block h2 { margin: 0 0 11px; color: var(--green); font-size: 18px; }
        .confirm-data { display: grid; gap: 8px; }
        .confirm-line { display: flex; justify-content: space-between; gap: 12px; color: var(--muted); font-size: 10px; line-height: 1.45; }
        .confirm-line strong { color: var(--ink); font-weight: 650; text-align: right; }
        .confirm-total { display: flex; justify-content: space-between; gap: 12px; margin-top: 10px; padding-top: 11px; border-top: 1px solid var(--line); color: var(--ink); font-size: 11px; font-weight: 700; }
        .confirm-total strong { color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 23px; font-weight: 400; }
        .status-note { margin: 0; padding: 12px 14px; border-left: 3px solid #a9874f; background: #f5f0e6; color: #654f31; font-size: 11px; line-height: 1.55; }
        .confirmation-actions { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 20px; }
        .confirmation-actions .button { min-width: 145px; }
        .not-configured { color: #858d85; font-style: italic; }
        .qr-payment { display: grid; grid-template-columns: 190px minmax(0, 1fr); align-items: center; gap: 18px; margin-top: 18px; padding: 17px; border: 1px solid var(--line); background: #f8faf7; }
        .qr-code { display: grid; width: 190px; height: 190px; place-items: center; padding: 8px; background: #fff; }
        .qr-code img, .qr-code canvas { max-width: 100%; height: auto; }
        .qr-copy h2 { margin: 0 0 7px; color: var(--green); font-size: 18px; }
        .qr-copy p { margin: 0 0 12px; color: var(--muted); font-size: 11px; line-height: 1.55; }
        .qr-warning { margin-top: 12px !important; color: #743a32 !important; }
        @media (max-width: 900px) { .search-fields { grid-template-columns: repeat(3, minmax(0, 1fr)); } .search-fields .field:last-child { grid-column: 1 / -1; } .price-layout { grid-template-columns: 1fr; } .summary { position: static; } }
        @media (max-width: 700px) {
            .page { padding: 25px 14px 38px; }
            .search-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .room-grid { grid-template-columns: 1fr; }
            .room-option { grid-template-columns: 118px minmax(0, 1fr); }
            .search-button { grid-column: 1 / -1; }
            .confirm-grid { grid-template-columns: 1fr; }
            .confirmation-top { flex-direction: column; }
            .qr-payment { grid-template-columns: 1fr; }
        }
        @media (max-width: 420px) { .room-option { grid-template-columns: 1fr; } .room-option img, .room-placeholder { height: 160px; min-height: 0; } .payment-options { grid-template-columns: 1fr; } }
        @media print {
            body { background: #fff; }
            .customer-nav, .confirmation-actions, .print-hidden { display: none !important; }
            body { padding: 0 !important; }
            .page { width: 100%; padding: 0; }
            .confirmation { max-width: none; margin: 0; border: 0; }
        }
        @media (prefers-reduced-motion: reduce) { .button { transition: none; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <?php if ($confirmation): ?>
            <?php
            $confirmedExtras = json_decode((string) $confirmation['extra_services'], true);
            $confirmedExtras = is_array($confirmedExtras) ? $confirmedExtras : [];
            ?>
            <section class="confirmation" id="booking-confirmation" aria-labelledby="confirmation-title">
                <div class="confirmation-top">
                    <div>
                        <p class="eyebrow">The Queens Hotel · Booking receipt</p>
                        <h1 id="confirmation-title">Your booking is recorded.</h1>
                        <p>Keep this confirmation for your records. Present the reference when you contact the hotel.</p>
                    </div>
                    <div class="reference">REF <?= htmlspecialchars($confirmation['booking_reference'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <?php if ($emailConfirmationSent): ?>
                    <p class="status-note" style="margin-top:16px">A confirmation email was sent to <?= htmlspecialchars($confirmation['guest_email'], ENT_QUOTES, 'UTF-8') ?>.</p>
                <?php else: ?>
                    <p class="status-note" style="margin-top:16px">We could not send the confirmation email yet. Check the email settings and refresh this page to retry.</p>
                <?php endif; ?>
                <?php if ($confirmation['payment_status'] === 'paid'): ?>
                    <p class="status-note"><?= $paymentReceiptSent ? 'Your payment receipt has been sent to ' . htmlspecialchars($confirmation['guest_email'], ENT_QUOTES, 'UTF-8') . '.' : 'Your payment is confirmed. The receipt email could not be sent yet; refresh this page to retry.' ?></p>
                <?php endif; ?>

                <div class="confirm-grid">
                    <section class="confirm-block">
                        <h2>Stay details</h2>
                        <div class="confirm-data">
                            <div class="confirm-line"><span>Guest</span><strong><?= htmlspecialchars($confirmation['guest_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                            <div class="confirm-line"><span>Email</span><strong><?= htmlspecialchars($confirmation['guest_email'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                            <div class="confirm-line"><span>Room</span><strong><?= htmlspecialchars($confirmation['room_type'], ENT_QUOTES, 'UTF-8') ?> × <?= (int) $confirmation['room_count'] ?></strong></div>
                            <div class="confirm-line"><span>Check-in</span><strong><?= htmlspecialchars(date('D, M j, Y', strtotime($confirmation['check_in'])), ENT_QUOTES, 'UTF-8') ?> · 3:00 PM</strong></div>
                            <div class="confirm-line"><span>Check-out</span><strong><?= htmlspecialchars(date('D, M j, Y', strtotime($confirmation['check_out'])), ENT_QUOTES, 'UTF-8') ?> · 11:00 AM</strong></div>
                            <div class="confirm-line"><span>Length of stay</span><strong><?= (int) $confirmation['nights'] ?> night<?= (int) $confirmation['nights'] === 1 ? '' : 's' ?></strong></div>
                            <div class="confirm-line"><span>Guests</span><strong><?= (int) $confirmation['adults'] ?> adults, <?= (int) $confirmation['children'] ?> children</strong></div>
                        </div>
                    </section>
                    <section class="confirm-block">
                        <h2>Payment summary</h2>
                        <div class="confirm-data">
                            <div class="confirm-line"><span>Room price</span><strong><?= $currencySymbol ?><?= number_format((float) $confirmation['room_subtotal'], 2) ?></strong></div>
                            <?php foreach ($confirmedExtras as $extra): ?>
                                <div class="confirm-line"><span><?= htmlspecialchars($extra['name'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= $currencySymbol ?><?= number_format((float) $extra['total'], 2) ?></strong></div>
                            <?php endforeach; ?>
                            <div class="confirm-line"><span>Taxes</span><strong><?= $currencySymbol ?><?= number_format((float) $confirmation['tax_total'], 2) ?></strong></div>
                            <div class="confirm-line"><span>Discount</span><strong>−<?= $currencySymbol ?><?= number_format((float) $confirmation['discount_total'], 2) ?></strong></div>
                            <div class="confirm-line"><span>Service fees</span><strong><?= $currencySymbol ?><?= number_format((float) $confirmation['service_fee_total'], 2) ?></strong></div>
                            <div class="confirm-total"><span>Total amount</span><strong><?= $currencySymbol ?><?= number_format((float) $confirmation['total_amount'], 2) ?></strong></div>
                            <div class="confirm-line"><span>Payment method</span><strong><?= htmlspecialchars($paymentMethods[$confirmation['payment_method']] ?? $confirmation['payment_method'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                            <div class="confirm-line"><span>Payment status</span><strong><?= htmlspecialchars($confirmationStatusLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
                        </div>
                    </section>
                    <section class="confirm-block">
                        <h2>Hotel contact</h2>
                        <div class="confirm-data">
                            <div class="confirm-line"><span>Email</span><strong><?= $contactEmail !== '' ? htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8') : '<span class="not-configured">Not configured</span>' ?></strong></div>
                            <div class="confirm-line"><span>Phone</span><strong><?= $contactPhone !== '' ? htmlspecialchars($contactPhone, ENT_QUOTES, 'UTF-8') : '<span class="not-configured">Not configured</span>' ?></strong></div>
                            <div class="confirm-line"><span>Address</span><strong><?= $contactAddress !== '' ? htmlspecialchars($contactAddress, ENT_QUOTES, 'UTF-8') : '<span class="not-configured">Not configured</span>' ?></strong></div>
                        </div>
                    </section>
                    <section class="confirm-block">
                        <h2>Cancellation information</h2>
                        <p class="secure-note"><?= htmlspecialchars($cancellationInformation, ENT_QUOTES, 'UTF-8') ?></p>
                    </section>
                </div>

                <?php if ($paymentVerificationMessage !== ''): ?>
                    <p class="status-note"><?= htmlspecialchars($paymentVerificationMessage, ENT_QUOTES, 'UTF-8') ?></p>
                <?php elseif ($confirmation['payment_status'] === 'pending' && is_string($confirmation['paymongo_checkout_url']) && $confirmation['paymongo_checkout_url'] !== ''): ?>
                    <p class="status-note"><?= ($_GET['payment_cancelled'] ?? '') === '1' ? 'Checkout was cancelled. Your booking is still pending.' : 'Payment has not been confirmed yet. Complete checkout to finish your payment.' ?></p>
                    <?php if ($confirmation['payment_method'] === 'qrph' && $paymentLandingUrl !== ''): ?>
                        <section class="qr-payment" aria-labelledby="qr-payment-heading">
                            <div class="qr-code" id="payment-link-qr" data-url="<?= htmlspecialchars($paymentLandingUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="QR code to open the secure payment page"></div>
                            <div class="qr-copy">
                                <h2 id="qr-payment-heading">Continue payment on your phone</h2>
                                <p>Scan this code with your phone camera to open the hotel payment page, then continue securely to PayMongo.</p>
                                <a class="button button--secondary" href="<?= htmlspecialchars($paymentLandingUrl, ENT_QUOTES, 'UTF-8') ?>">Open payment page on this device</a>
                                <?php if ($paymentUrlIsLocal): ?>
                                    <p class="qr-warning">This site is configured as localhost, which phones cannot reach. Set HOTEL_APP_URL to a public HTTPS address or phone-reachable HTTPS tunnel before scanning.</p>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                    <div class="confirmation-actions print-hidden">
                        <a class="button" href="booking.php?confirmation=<?= rawurlencode($confirmation['booking_reference']) ?>&amp;payment_return=1">Verify payment status</a>
                        <a class="button button--secondary" href="<?= htmlspecialchars($confirmation['paymongo_checkout_url'], ENT_QUOTES, 'UTF-8') ?>" rel="noopener">Continue to PayMongo test checkout</a>
                    </div>
                <?php elseif ($confirmation['payment_status'] === 'pending'): ?>
                    <p class="status-note">Payment is pending. Refresh after checkout; payment is marked paid only after server-side verification.</p>
                <?php elseif ($confirmation['payment_status'] === 'unpaid'): ?>
                    <p class="status-note">Payment is due at the hotel. No payment was taken online.</p>
                <?php endif; ?>
                <div class="confirmation-actions print-hidden">
                    <button class="button" id="print-confirmation" type="button">Print confirmation</button>
                    <button class="button button--secondary" id="download-confirmation" type="button">Download receipt</button>
                    <a class="button button--secondary" href="dashboard.php">Return to dashboard</a>
                </div>
            </section>
        <?php else: ?>
            <header class="intro">
                <p class="eyebrow">The Queens Hotel · Reservations</p>
                <h1>Book your stay.</h1>
                <p>Search dates and guests, choose a room, then review the full total before confirming.</p>
            </header>

            <?php if ($message !== ''): ?>
                <p class="message" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if (!$databaseAvailable): ?>
                <p class="message" role="alert">Room booking is unavailable. Confirm the database connection and booking tables are installed.</p>
            <?php endif; ?>

            <?php if (!$selectedRoom): ?>
                <section class="search-panel print-hidden" aria-labelledby="search-heading">
                    <h2 id="search-heading">Search your stay</h2>
                    <form method="get" action="booking.php">
                        <div class="search-fields">
                            <div class="field"><label for="search-check-in">Check-in</label><input class="control" id="search-check-in" name="check_in" type="date" min="<?= htmlspecialchars($today->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($checkInDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required></div>
                            <div class="field"><label for="search-check-out">Check-out</label><input class="control" id="search-check-out" name="check_out" type="date" min="<?= htmlspecialchars($checkInDate->modify('+1 day')->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($checkOutDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required></div>
                            <div class="field"><label for="search-adults">Adults</label><input class="control" id="search-adults" name="adults" type="number" min="1" max="30" value="<?= (int) $adults ?>" required></div>
                            <div class="field"><label for="search-children">Children</label><input class="control" id="search-children" name="children" type="number" min="0" max="30" value="<?= (int) $children ?>" required></div>
                            <div class="field"><label for="search-rooms">Rooms</label><input class="control" id="search-rooms" name="room_count" type="number" min="1" max="20" value="<?= (int) $roomCount ?>" required></div>
                            <div class="field"><button class="button search-button" type="submit">Search rooms <span aria-hidden="true">&#8594;</span></button></div>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($databaseAvailable && $rooms === []): ?>
                <p class="empty">No rooms have been added yet. Please check back later.</p>
            <?php elseif ($databaseAvailable && $roomOptions === []): ?>
                <p class="empty">No rooms match this guest count. Adjust the adults, children, or room quantity and search again.</p>
            <?php elseif ($databaseAvailable): ?>
                <section aria-labelledby="rooms-heading" class="print-hidden">
                    <p class="eyebrow" id="rooms-heading">Choose a room · <?= $nights ?> night<?= $nights === 1 ? '' : 's' ?></p>
                    <div class="room-grid">
                        <?php foreach ($roomOptions as $room): ?>
                            <?php $isSelected = $requestedRoomId !== null && (int) $room['id'] === $requestedRoomId; ?>
                            <article class="room-option<?= $isSelected ? ' room-option--selected' : '' ?>">
                                <?php if (is_string($room['cover_photo'])): ?>
                                    <img src="../<?= htmlspecialchars($room['cover_photo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?> room" loading="lazy">
                                <?php else: ?>
                                    <div class="room-placeholder">Room photo</div>
                                <?php endif; ?>
                                <div class="room-option-body">
                                    <h3><?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <p class="room-meta"><?= (int) $room['max_guests'] ?> guests max per room · <?= (int) $room['bed_count'] ?> <?= (int) $room['bed_count'] === 1 ? 'bed' : 'beds' ?> · <?= htmlspecialchars($room['bed_type'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="room-rate">$<?= number_format((float) $room['price_per_night'], 2) ?> / room / night</p>
                                    <?php if ($room['can_book']): ?>
                                        <p class="room-status">Available · <?= (int) $room['minimum_available'] ?> room<?= (int) $room['minimum_available'] === 1 ? '' : 's' ?> left each night</p>
                                        <a class="button<?= $isSelected ? ' button--secondary' : '' ?>" href="booking.php?room_id=<?= (int) $room['id'] ?>&amp;check_in=<?= rawurlencode($checkInDate->format('Y-m-d')) ?>&amp;check_out=<?= rawurlencode($checkOutDate->format('Y-m-d')) ?>&amp;adults=<?= (int) $adults ?>&amp;children=<?= (int) $children ?>&amp;room_count=<?= (int) $roomCount ?><?= $isSelected ? '#booking-summary' : '' ?>"><?= $isSelected ? 'Selected' : 'Select room' ?></a>
                                    <?php else: ?>
                                        <p class="room-status room-status--no"><?= $room['minimum_available'] === null ? 'Availability not set for every date' : 'Not enough rooms for selected dates' ?></p>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($selectedRoom): ?>
                <section class="panel" id="booking-summary" aria-labelledby="booking-heading">
                    <?php if ($selectedRoomCanBook): ?>
                        <form method="post" action="booking.php" id="booking-form">
                            <input type="hidden" name="action" value="place_booking">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="room_id" value="<?= (int) $selectedRoom['id'] ?>">
                            <input type="hidden" name="check_in" value="<?= htmlspecialchars($checkInDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="check_out" value="<?= htmlspecialchars($checkOutDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="adults" value="<?= (int) $adults ?>">
                            <input type="hidden" name="children" value="<?= (int) $children ?>">
                            <input type="hidden" name="room_count" value="<?= (int) $roomCount ?>">
                    <?php endif; ?>
                    <div class="price-layout">
                        <div>
                            <p class="eyebrow">Review before confirmation</p>
                            <h2 id="booking-heading">Your booking details</h2>
                            <div class="selection">
                                <?php if (is_string($selectedRoom['cover_photo'])): ?>
                                    <img src="../<?= htmlspecialchars($selectedRoom['cover_photo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($selectedRoom['room_type'], ENT_QUOTES, 'UTF-8') ?> room">
                                <?php else: ?>
                                    <div class="selection-placeholder" aria-hidden="true"></div>
                                <?php endif; ?>
                                <div><h3><?= htmlspecialchars($selectedRoom['room_type'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= $nights ?> night<?= $nights === 1 ? '' : 's' ?> · <?= (int) $roomCount ?> room<?= $roomCount === 1 ? '' : 's' ?> · <?= (int) $adults ?> adults, <?= (int) $children ?> children</p></div>
                            </div>
                            <?php if (!$selectedRoomCanBook): ?>
                                <p class="message">This room type does not have enough inventory for every night in your stay. Search other dates or ask the hotel to update daily availability.</p>
                            <?php else: ?>
                                <section class="booking-extras" aria-labelledby="extras-heading">
                                    <h2 id="extras-heading">Extra services</h2>
                                    <div class="extras">
                                        <?php foreach ($extraOptions as $extraKey => $extra): ?>
                                            <label class="extra-option">
                                                <span class="extra-choice"><input type="checkbox" name="extras[]" value="<?= htmlspecialchars($extraKey, ENT_QUOTES, 'UTF-8') ?>" data-extra="<?= htmlspecialchars($extraKey, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($extraKey, $selectedExtras, true) ? 'checked' : '' ?>><span><?= htmlspecialchars($extra['label'], ENT_QUOTES, 'UTF-8') ?></span></span>
                                                <span class="extra-rate">$<?= number_format($extra['price'], 2) ?> <?= htmlspecialchars($extra['basis'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endif; ?>
                        </div>

                        <aside class="panel summary" aria-labelledby="summary-heading">
                            <p class="eyebrow">Price breakdown</p>
                            <h2 id="summary-heading">Booking summary</h2>
                            <p class="summary-caption"><?= htmlspecialchars($guestName, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($checkInDate->format('M j, Y'), ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($checkOutDate->format('M j, Y'), ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="summary-lines" id="price-summary" data-price="<?= htmlspecialchars((string) $selectedRoom['price_per_night'], ENT_QUOTES, 'UTF-8') ?>" data-nights="<?= (int) $nights ?>" data-rooms="<?= (int) $roomCount ?>" data-guests="<?= (int) $totalGuests ?>" data-tax="<?= htmlspecialchars((string) $selectedRoom['tax_percent'], ENT_QUOTES, 'UTF-8') ?>" data-service-fee="<?= htmlspecialchars((string) $selectedRoom['service_fee'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="summary-line"><span>Room price · <?= (int) $roomCount ?> × <?= $nights ?> nights</span><strong id="summary-room-price"><?= $money($roomSubtotal) ?></strong></div>
                                <div id="summary-extras">
                                    <?php foreach ($extraDetails as $extra): ?>
                                        <div class="summary-line"><span><?= htmlspecialchars($extra['name'], ENT_QUOTES, 'UTF-8') ?></span><strong>$<?= number_format($extra['total'], 2) ?></strong></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="summary-line"><span>Extra services</span><strong id="summary-extra-total"><?= $money($extraServicesTotal) ?></strong></div>
                                <div class="summary-line"><span>Taxes</span><strong id="summary-tax"><?= $money($taxTotal) ?></strong></div>
                                <div class="summary-line"><span>Discount</span><strong><?= $money($discountTotal) ?></strong></div>
                                <div class="summary-line"><span>Service fees</span><strong id="summary-fees"><?= $money($serviceFeeTotal) ?></strong></div>
                                <div class="summary-line summary-line--total"><span>Total amount</span><strong id="summary-total"><?= $money($totalAmount) ?></strong></div>
                            </div>
                            <p class="summary-disclaimer">Estimated in PHP. Payment is handled securely by PayMongo; this site never collects your card or banking credentials.</p>
                            <?php if ($selectedRoomCanBook): ?>
                                <section class="payment-section" aria-labelledby="payment-heading">
                                    <h3 id="payment-heading">Payment method</h3>
                                    <div class="payment-options">
                                        <?php foreach ($paymentMethods as $methodKey => $methodLabel): ?>
                                            <label class="payment-option"><input type="radio" name="payment_method" value="<?= htmlspecialchars($methodKey, ENT_QUOTES, 'UTF-8') ?>" <?= $paymentMethod === $methodKey ? 'checked' : '' ?>><span><?= htmlspecialchars($methodLabel, ENT_QUOTES, 'UTF-8') ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="secure-note">Online payments are securely processed by PayMongo. Choose QR Ph to scan a payment QR code at checkout. Pay at hotel remains unpaid until settled at the property.</p>
                                </section>
                                <p class="fine-print">By confirming, you agree to contact the hotel directly for cancellation requests. Refund terms are determined by the property.</p>
                                <button class="button confirm-button" type="submit">Confirm booking <span aria-hidden="true">&#8594;</span></button>
                            <?php endif; ?>
                        </aside>
                    </div>
                    <?php if ($selectedRoomCanBook): ?>
                        </form>
                    <?php endif; ?>
                </section>
            <?php elseif ($requestedRoomId !== null && !$selectedRoom): ?>
                <p class="message">That room could not be found. Choose another room from the list.</p>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?php if ($confirmation): ?>
        <?php if ($paymentLandingUrl !== '' && $confirmation['payment_status'] === 'pending'): ?>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" referrerpolicy="no-referrer"></script>
            <script>
                const paymentQr = document.querySelector('#payment-link-qr');
                if (paymentQr && window.QRCode) {
                    new QRCode(paymentQr, {
                        text: paymentQr.dataset.url,
                        width: 174,
                        height: 174,
                        correctLevel: QRCode.CorrectLevel.M,
                    });
                }
            </script>
        <?php endif; ?>
        <script>
            document.querySelector('#print-confirmation').addEventListener('click', () => window.print());
            document.querySelector('#download-confirmation').addEventListener('click', () => {
                const receipt = document.querySelector('#booking-confirmation').innerText;
                const file = new Blob([receipt], { type: 'text/plain;charset=utf-8' });
                const url = URL.createObjectURL(file);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'booking-<?= htmlspecialchars($confirmation['booking_reference'], ENT_QUOTES, 'UTF-8') ?>.txt';
                link.click();
                URL.revokeObjectURL(url);
            });
        </script>
    <?php elseif ($selectedRoom): ?>
        <script>
            const summary = document.querySelector('#price-summary');
            const nights = Number(summary.dataset.nights);
            const roomCount = Number(summary.dataset.rooms);
            const guestCount = Number(summary.dataset.guests);
            const nightlyPrice = Number(summary.dataset.price);
            const taxPercent = Number(summary.dataset.tax);
            const perRoomFee = Number(summary.dataset.serviceFee);
            const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

            function updateBookingSummary() {
                let extras = 0;
                const extraLines = [];
                document.querySelectorAll('[data-extra]:checked').forEach((checkbox) => {
                    let quantity = 1;
                    let unitPrice = 0;
                    if (checkbox.dataset.extra === 'breakfast') {
                        quantity = guestCount * nights;
                        unitPrice = 650;
                    } else if (checkbox.dataset.extra === 'airport_transfer') {
                        unitPrice = 1800;
                    } else {
                        quantity = roomCount;
                        unitPrice = 900;
                    }
                    const lineTotal = quantity * unitPrice;
                    extras += lineTotal;
                    const line = document.createElement('div');
                    line.className = 'summary-line';
                    const label = document.createElement('span');
                    label.textContent = checkbox.closest('.extra-option').querySelector('.extra-choice span').textContent;
                    const amount = document.createElement('strong');
                    amount.textContent = money.format(lineTotal);
                    line.append(label, amount);
                    extraLines.push(line);
                });

                const roomSubtotal = nightlyPrice * nights * roomCount;
                const tax = (roomSubtotal + extras) * taxPercent / 100;
                const fees = perRoomFee * roomCount;
                document.querySelector('#summary-room-price').textContent = money.format(roomSubtotal);
                document.querySelector('#summary-extras').replaceChildren(...extraLines);
                document.querySelector('#summary-extra-total').textContent = money.format(extras);
                document.querySelector('#summary-tax').textContent = money.format(tax);
                document.querySelector('#summary-fees').textContent = money.format(fees);
                document.querySelector('#summary-total').textContent = money.format(roomSubtotal + extras + tax + fees);
            }

            document.querySelectorAll('[data-extra]').forEach((checkbox) => checkbox.addEventListener('change', updateBookingSummary));
        </script>
    <?php endif; ?>
</body>
</html>