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

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$roomTypes = ['Standard Room', 'Deluxe Room', 'Family Room', 'Suite', 'Presidential Suite'];
$bedTypes = ['Single', 'Double', 'Queen', 'King'];
$availabilityOptions = [
    'available' => 'Available',
    'limited' => 'Limited availability',
    'fully_booked' => 'Fully booked',
];
$amenities = [
    'Free Wi-Fi',
    'Air conditioning',
    'TV',
    'Refrigerator/minibar',
    'Electric kettle',
    'Work desk',
    'Wardrobe',
    'Hair dryer',
    'Safe',
    'Private bathroom',
    'Shower/bathtub',
    'Towels',
    'Toiletries',
    'Balcony',
    'Sea view',
    'Mountain view',
    'City view',
];
$form = [
    'room_type' => '',
    'price_per_night' => '',
    'tax_percent' => '0',
    'service_fee' => '0',
    'max_guests' => '',
    'adults_allowed' => '',
    'children_allowed' => '0',
    'bed_count' => '1',
    'bed_type' => 'Queen',
    'size_sqm' => '',
    'availability' => 'available',
    'rooms_remaining' => '',
    'description' => '',
];
$selectedAmenities = [];
$message = '';
$messageType = 'error';
$roomList = [];
$roomTypesWithInventory = [];
$dailyAvailability = [];
$availabilityForm = [
    'room_type' => '',
    'start_date' => date('Y-m-d'),
    'end_date' => date('Y-m-d'),
    'default_rooms' => '0',
];
$dailyRooms = [];
$databaseAvailable = true;

if (isset($_GET['room_action']) && in_array($_GET['room_action'], ['updated', 'deleted'], true)) {
    $message = $_GET['room_action'] === 'updated' ? 'Room details updated successfully.' : 'Room deleted successfully.';
    $messageType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_availability') {
    foreach ($availabilityForm as $field => $default) {
        $postedValue = $_POST[$field] ?? $default;
        $availabilityForm[$field] = is_string($postedValue) ? trim($postedValue) : $default;
    }

    $postedDailyRooms = $_POST['daily_rooms'] ?? [];
    if (is_array($postedDailyRooms)) {
        foreach ($postedDailyRooms as $date => $count) {
            if (is_string($date) && (is_string($count) || is_int($count))) {
                $dailyRooms[$date] = (string) $count;
            }
        }
    }

    $submittedToken = $_POST['csrf_token'] ?? '';
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $availabilityForm['start_date']);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $availabilityForm['end_date']);
    $defaultRooms = filter_var($availabilityForm['default_rooms'], FILTER_VALIDATE_INT);

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Refresh the page and try again.';
    } elseif (!in_array($availabilityForm['room_type'], $roomTypes, true)) {
        $message = 'Choose a valid room type.';
    } elseif (
        !$startDate || !$endDate
        || $startDate->format('Y-m-d') !== $availabilityForm['start_date']
        || $endDate->format('Y-m-d') !== $availabilityForm['end_date']
        || $startDate < new DateTimeImmutable('today')
        || $endDate < $startDate
        || $startDate->diff($endDate)->days > 365
    ) {
        $message = 'Choose a valid future date range of up to 366 days.';
    } elseif ($defaultRooms === false || $defaultRooms < 0 || $defaultRooms > 32767) {
        $message = 'Enter a room count between 0 and 32,767.';
    } else {
        $dateCounts = [];
        $periodEnd = $endDate->modify('+1 day');
        foreach (new DatePeriod($startDate, new DateInterval('P1D'), $periodEnd) as $date) {
            $dateKey = $date->format('Y-m-d');
            $count = isset($dailyRooms[$dateKey])
                ? filter_var($dailyRooms[$dateKey], FILTER_VALIDATE_INT)
                : false;
            if ($count === false || $count < 0 || $count > 32767) {
                $message = 'Enter a room count from 0 to 32,767 for every date.';
                break;
            }
            $dateCounts[$dateKey] = $count;
        }

        if ($message === '' && count($dailyRooms) !== count($dateCounts)) {
            $message = 'Regenerate the date list and enter a room count for each day in the selected range.';
        }

        if ($message === '') {
            try {
                $pdo = getDatabaseConnection();

                $roomTypeStatement = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE room_type = :room_type');
                $roomTypeStatement->execute(['room_type' => $availabilityForm['room_type']]);
                if ((int) $roomTypeStatement->fetchColumn() === 0) {
                    $message = 'Add a room of this type before setting its daily availability.';
                } else {
                    $pdo->beginTransaction();
                    $upsert = $pdo->prepare(
                        'INSERT INTO room_type_availability (room_type, availability_date, available_rooms, updated_by) VALUES (:room_type, :availability_date, :available_rooms, :updated_by) ON DUPLICATE KEY UPDATE available_rooms = VALUES(available_rooms), updated_by = VALUES(updated_by)'
                    );
                    foreach ($dateCounts as $date => $count) {
                        $upsert->execute([
                            'room_type' => $availabilityForm['room_type'],
                            'availability_date' => $date,
                            'available_rooms' => $count,
                            'updated_by' => (int) $_SESSION['user_id'],
                        ]);
                    }
                    $pdo->commit();
                    $message = sprintf(
                        '%s availability saved for %d day%s.',
                        $availabilityForm['room_type'],
                        count($dateCounts),
                        count($dateCounts) === 1 ? '' : 's'
                    );
                    $messageType = 'success';
                    $dailyRooms = $dateCounts;
                }
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($exception->getMessage());
                $message = 'Daily room availability could not be saved. Check the database setup and try again.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $field => $default) {
        $postedValue = $_POST[$field] ?? $default;
        $form[$field] = is_string($postedValue) ? trim($postedValue) : $default;
    }

    $postedAmenities = $_POST['amenities'] ?? [];
    if (is_array($postedAmenities)) {
        $selectedAmenities = array_values(array_filter(
            $postedAmenities,
            static fn ($amenity): bool => is_string($amenity) && in_array($amenity, $amenities, true)
        ));
    }

    $submittedToken = $_POST['csrf_token'] ?? '';
    $roomTypeIsValid = in_array($form['room_type'], $roomTypes, true);
    $bedTypeIsValid = in_array($form['bed_type'], $bedTypes, true);
    $availabilityIsValid = array_key_exists($form['availability'], $availabilityOptions);
    $maxGuests = filter_var($form['max_guests'], FILTER_VALIDATE_INT);
    $adultsAllowed = filter_var($form['adults_allowed'], FILTER_VALIDATE_INT);
    $childrenAllowed = filter_var($form['children_allowed'], FILTER_VALIDATE_INT);
    $bedCount = filter_var($form['bed_count'], FILTER_VALIDATE_INT);
    $roomsRemaining = $form['rooms_remaining'] === '' ? null : filter_var($form['rooms_remaining'], FILTER_VALIDATE_INT);
    $pricePerNight = is_numeric($form['price_per_night']) ? (float) $form['price_per_night'] : 0.0;
    $taxPercent = is_numeric($form['tax_percent']) ? (float) $form['tax_percent'] : -1.0;
    $serviceFee = is_numeric($form['service_fee']) ? (float) $form['service_fee'] : -1.0;
    $sizeSqm = is_numeric($form['size_sqm']) ? (float) $form['size_sqm'] : 0.0;
    $description = $form['description'];

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Refresh the page and try again.';
    } elseif (!$roomTypeIsValid || !$bedTypeIsValid || !$availabilityIsValid) {
        $message = 'Choose a valid room type, bed type, and availability status.';
    } elseif (
        $pricePerNight <= 0 || $pricePerNight > 99999999.99
        || $taxPercent < 0 || $taxPercent > 100
        || $serviceFee < 0 || $serviceFee > 99999999.99
    ) {
        $message = 'Enter a valid nightly price, tax percentage, and service fee.';
    } elseif (
        $maxGuests === false || $maxGuests < 1 || $maxGuests > 30
        || $adultsAllowed === false || $adultsAllowed < 1
        || $childrenAllowed === false || $childrenAllowed < 0
        || $adultsAllowed + $childrenAllowed > $maxGuests
    ) {
        $message = 'Guest limits must be valid and the adult/child limits cannot exceed maximum guests.';
    } elseif ($bedCount === false || $bedCount < 1 || $bedCount > 20 || $sizeSqm <= 0 || $sizeSqm > 10000) {
        $message = 'Enter a valid bed count and room size.';
    } elseif ($roomsRemaining === false || ($roomsRemaining !== null && ($roomsRemaining < 0 || $roomsRemaining > 32767))) {
        $message = 'Enter a valid number of rooms remaining or leave it blank.';
    } elseif (strlen($description) > 5000) {
        $message = 'Keep the room description under 5,000 characters.';
    } elseif ($form['availability'] === 'fully_booked' && $roomsRemaining !== null && $roomsRemaining !== 0) {
        $message = 'A fully booked room must have zero rooms remaining.';
    } else {
        $photoNames = $_FILES['photos']['name'] ?? [];
        $photoErrors = $_FILES['photos']['error'] ?? [];
        $photoTemps = $_FILES['photos']['tmp_name'] ?? [];
        $photoSizes = $_FILES['photos']['size'] ?? [];
        $uploads = [];

        if (is_array($photoNames) && is_array($photoErrors) && is_array($photoTemps) && is_array($photoSizes)) {
            foreach ($photoNames as $index => $originalName) {
                $uploadError = $photoErrors[$index] ?? UPLOAD_ERR_NO_FILE;
                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($uploadError !== UPLOAD_ERR_OK || !isset($photoTemps[$index], $photoSizes[$index])) {
                    $message = 'One of the selected photos could not be uploaded.';
                    break;
                }
                if (!is_string($photoTemps[$index]) || !is_string($originalName) || (int) $photoSizes[$index] > 5 * 1024 * 1024) {
                    $message = 'Each room photo must be no larger than 5 MB.';
                    break;
                }
                $uploads[] = [
                    'tmp_name' => $photoTemps[$index],
                    'original_name' => $originalName,
                ];
            }
        }

        if ($message === '' && count($uploads) === 0) {
            $message = 'Upload at least one room photo.';
        } elseif ($message === '' && count($uploads) > 8) {
            $message = 'Upload no more than 8 photos for a room.';
        }

        if ($message === '') {
            $pdo = null;
            $savedFiles = [];
            try {
                $pdo = getDatabaseConnection();

                $uploadDirectory = __DIR__ . '/../uploads/rooms';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Unable to create the room photo directory.');
                }

                $pdo->beginTransaction();
                $roomStatement = $pdo->prepare(
                    'INSERT INTO rooms (room_type, price_per_night, tax_percent, service_fee, max_guests, adults_allowed, children_allowed, bed_count, bed_type, size_sqm, availability, rooms_remaining, amenities, description, created_by) VALUES (:room_type, :price_per_night, :tax_percent, :service_fee, :max_guests, :adults_allowed, :children_allowed, :bed_count, :bed_type, :size_sqm, :availability, :rooms_remaining, :amenities, :description, :created_by)'
                );
                $roomStatement->execute([
                    'room_type' => $form['room_type'],
                    'price_per_night' => number_format($pricePerNight, 2, '.', ''),
                    'tax_percent' => number_format($taxPercent, 2, '.', ''),
                    'service_fee' => number_format($serviceFee, 2, '.', ''),
                    'max_guests' => $maxGuests,
                    'adults_allowed' => $adultsAllowed,
                    'children_allowed' => $childrenAllowed,
                    'bed_count' => $bedCount,
                    'bed_type' => $form['bed_type'],
                    'size_sqm' => number_format($sizeSqm, 2, '.', ''),
                    'availability' => $form['availability'],
                    'rooms_remaining' => $form['availability'] === 'fully_booked' ? 0 : $roomsRemaining,
                    'amenities' => json_encode($selectedAmenities, JSON_THROW_ON_ERROR),
                    'description' => $description === '' ? null : $description,
                    'created_by' => (int) $_SESSION['user_id'],
                ]);
                $roomId = (int) $pdo->lastInsertId();
                $photoStatement = $pdo->prepare('INSERT INTO room_photos (room_id, file_path, sort_order) VALUES (:room_id, :file_path, :sort_order)');
                $mimeExtensions = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);

                foreach ($uploads as $index => $upload) {
                    $mimeType = $fileInfo->file($upload['tmp_name']);
                    if (!is_string($mimeType) || !isset($mimeExtensions[$mimeType])) {
                        throw new RuntimeException('Room photos must be JPEG, PNG, or WebP images.');
                    }

                    $fileName = bin2hex(random_bytes(20)) . '.' . $mimeExtensions[$mimeType];
                    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
                    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                        throw new RuntimeException('A room photo could not be saved. Please try again.');
                    }

                    $savedFiles[] = $destination;
                    $relativePath = 'uploads/rooms/' . $fileName;
                    $photoStatement->execute([
                        'room_id' => $roomId,
                        'file_path' => $relativePath,
                        'sort_order' => $index,
                    ]);
                }

                $pdo->commit();
                $message = 'Room details and photos saved successfully.';
                $messageType = 'success';
                foreach ($form as $field => $default) {
                    $form[$field] = $field === 'tax_percent' || $field === 'service_fee' ? '0' : ($field === 'bed_count' ? '1' : ($field === 'bed_type' ? 'Queen' : ($field === 'children_allowed' ? '0' : '')));
                }
                $selectedAmenities = [];
            } catch (Throwable $exception) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                foreach ($savedFiles as $savedFile) {
                    if (is_file($savedFile)) {
                        unlink($savedFile);
                    }
                }
                error_log($exception->getMessage());
                $message = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Room details could not be saved. Check the database setup and try again.';
            }
        }
    }
}

try {
    $pdo = getDatabaseConnection();
    $roomList = $pdo->query(
        'SELECT r.*, p.file_path AS cover_photo FROM rooms r LEFT JOIN room_photos p ON p.room_id = r.id AND p.sort_order = (SELECT MIN(p2.sort_order) FROM room_photos p2 WHERE p2.room_id = r.id) ORDER BY r.created_at DESC'
    )->fetchAll();
    $roomTypesWithInventory = $pdo->query('SELECT DISTINCT room_type FROM rooms ORDER BY room_type')->fetchAll(PDO::FETCH_COLUMN);
    $dailyAvailability = $pdo->query(
        'SELECT room_type, availability_date, available_rooms FROM room_type_availability WHERE availability_date >= CURRENT_DATE AND availability_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) ORDER BY availability_date, room_type'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
}

$displayName = htmlspecialchars((string) ($_SESSION['user_name'] ?? 'Manager'), ENT_QUOTES, 'UTF-8');
$today = date('Y-m-d');
$dailyRoomsJson = json_encode($dailyRooms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>Rooms | The Queens Hotel Management</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1500px); margin: auto; padding: 34px clamp(18px, 4vw, 56px) 64px; }
        .crumb { margin-bottom: 28px; color: var(--muted); font-size: 12px; }
        .crumb strong { color: var(--green); font-weight: 650; }
        .heading { display: flex; align-items: end; justify-content: space-between; gap: 24px; margin-bottom: 26px; }
        .eyebrow { margin: 0 0 9px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 7px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(32px, 4vw, 44px); font-weight: 400; line-height: 1.1; }
        .heading p:last-child { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.55; }
        .section-label { color: #657066; font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; }
        .form-panel { padding: clamp(18px, 3vw, 32px); border: 1px solid var(--line); background: var(--white); }
        .form-section { padding: 0 0 24px; margin: 0 0 23px; border: 0; border-bottom: 1px solid #edf0eb; }
        .form-section:last-of-type { margin-bottom: 18px; }
        .form-section legend { width: 100%; padding: 0 0 17px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 21px; }
        .grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 15px; }
        .field { grid-column: span 4; min-width: 0; }
        .field.wide { grid-column: span 6; }
        .field.full { grid-column: 1 / -1; }
        .field label, .field-label { display: block; margin-bottom: 7px; color: #3e4940; font-size: 11px; font-weight: 700; }
        .hint { display: block; margin-top: 6px; color: #8a928a; font-size: 10px; line-height: 1.5; }
        .control { display: block; width: 100%; min-height: 43px; padding: 10px 12px; border: 1px solid #dce1d9; border-radius: 2px; outline: 0; background: #fff; color: var(--ink); font: inherit; font-size: 13px; }
        .control:focus { border-color: var(--green); box-shadow: 0 0 0 3px rgba(23,59,53,.09); }
        textarea.control { min-height: 92px; resize: vertical; }
        .checkin-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; }
        .photo-picker { display: flex; min-height: 116px; flex-direction: column; align-items: center; justify-content: center; gap: 7px; padding: 16px; border: 1px dashed #b8c3b8; background: #fbfcf9; text-align: center; cursor: pointer; }
        .photo-picker strong { color: var(--green); font-size: 12px; }
        .photo-picker span { color: var(--muted); font-size: 10px; }
        .photo-picker svg { width: 22px; height: 22px; color: var(--green); }
        .photo-input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
        .photo-input:focus-visible + .photo-picker { outline: 3px solid rgba(23,59,53,.25); outline-offset: 3px; }
        .photo-preview { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 9px; margin-top: 11px; }
        .photo-preview img { width: 100%; aspect-ratio: 1.35; object-fit: cover; border: 1px solid var(--line); }
        .amenities { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 9px 14px; }
        .amenity { display: flex; min-width: 0; align-items: start; gap: 8px; color: #536056; font-size: 11px; line-height: 1.35; }
        .amenity input { flex: 0 0 auto; margin: 1px 0 0; accent-color: var(--green); }
        .estimate { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 14px; margin-top: 16px; padding: 14px 15px; background: #f0f4ef; }
        .estimate-copy { color: #617064; font-size: 11px; line-height: 1.5; }
        .estimate-total { color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 24px; white-space: nowrap; }
        .estimate-breakdown { grid-column: 1 / -1; padding-top: 11px; border-top: 1px solid #dce5dc; color: #69756a; font-size: 10px; }
        .notice { margin: 0 0 18px; padding: 12px 14px; border-left: 3px solid #a14f43; background: #f8ebe8; color: #79332d; font-size: 12px; line-height: 1.5; }
        .notice.success { border-color: #547859; background: #edf4ed; color: #315c39; }
        .submit-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .submit-note { margin: 0; color: #858d85; font-size: 10px; line-height: 1.5; }
        .submit { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; gap: 9px; padding: 0 19px; border: 1px solid var(--green); border-radius: 2px; background: var(--green); color: #fff; cursor: pointer; font: inherit; font-size: 12px; font-weight: 650; transition: background 150ms ease, transform 150ms ease; }
        .submit:hover { transform: translateY(-1px); background: #102d29; }
        .submit:focus-visible { outline: 3px solid rgba(23,59,53,.25); outline-offset: 3px; }
        .inventory { margin-top: 42px; }
        .inventory-head { display: flex; align-items: end; justify-content: space-between; gap: 14px; margin-bottom: 15px; }
        .inventory-head h2 { margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: 26px; font-weight: 400; }
        .inventory-count { color: var(--muted); font-size: 11px; }
        .room-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 13px; }
        .room-card { display: grid; min-width: 0; grid-template-columns: 145px minmax(0, 1fr); overflow: hidden; border: 1px solid var(--line); background: var(--white); }
        .room-card img { width: 100%; height: 100%; min-height: 150px; object-fit: cover; background: #e7ebe5; }
        .room-card__body { min-width: 0; padding: 15px; }
        .room-card h3 { margin: 0 0 5px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 19px; font-weight: 400; }
        .room-card__meta { margin: 0 0 8px; color: var(--muted); font-size: 10px; line-height: 1.5; }
        .room-card__price { margin: 0 0 9px; color: var(--ink); font-size: 12px; font-weight: 700; }
        .room-card__amenities { margin: 0; color: #758077; font-size: 10px; line-height: 1.5; }
        .availability { display: inline-block; margin-top: 10px; padding: 4px 7px; background: #edf3ed; color: #416748; font-size: 9px; font-weight: 650; }
        .availability--limited { background: #f5f0e6; color: #886735; }
        .availability--fully_booked { background: #f5eae7; color: #8a4c41; }
        .room-card__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .room-action { display: inline-flex; min-height: 34px; align-items: center; justify-content: center; padding: 0 11px; border: 1px solid #cbd5cb; border-radius: 2px; background: #fff; color: var(--green); cursor: pointer; font: inherit; font-size: 10px; font-weight: 700; text-decoration: none; }
        .room-action:hover { background: #f0f4ef; }
        .room-action--delete { border-color: #dfc8c3; color: #8a3f35; }
        .room-action--delete:hover { background: #fbefed; }
        .empty { padding: 28px; border: 1px solid var(--line); background: var(--white); color: var(--muted); font-size: 12px; text-align: center; }
        .availability-manager { margin-top: 38px; }
        .availability-panel { padding: clamp(18px, 3vw, 28px); border: 1px solid var(--line); background: var(--white); }
        .availability-panel h2 { margin: 0 0 7px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 24px; font-weight: 400; }
        .availability-intro { margin: 0 0 20px; color: var(--muted); font-size: 12px; line-height: 1.55; }
        .availability-controls { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); align-items: end; gap: 12px; }
        .availability-controls .field { grid-column: auto; }
        .availability-controls .control { min-height: 42px; }
        .build-days { min-height: 42px; padding: 0 12px; border: 1px solid #b9c5b9; background: #f7faf6; color: var(--green); cursor: pointer; font: inherit; font-size: 11px; font-weight: 650; }
        .build-days:hover { background: #edf3ed; }
        .daily-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 9px; margin-top: 17px; }
        .daily-item { display: grid; grid-template-columns: minmax(0, 1fr) 88px; align-items: center; gap: 10px; padding: 9px 10px; border: 1px solid #e8ece6; background: #fbfcfa; }
        .daily-item label { color: #536056; font-size: 11px; font-weight: 600; }
        .daily-item input { width: 100%; min-height: 36px; padding: 6px 8px; border: 1px solid #dce1d9; border-radius: 2px; color: var(--ink); font: inherit; font-size: 12px; }
        .daily-item input:focus { border-color: var(--green); outline: 2px solid rgba(23,59,53,.1); }
        .daily-empty { margin-top: 16px; padding: 17px; background: #f7f9f5; color: var(--muted); font-size: 11px; text-align: center; }
        .availability-actions { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-top: 15px; }
        .availability-actions p { margin: 0; color: #858d85; font-size: 10px; line-height: 1.5; }
        .availability-actions .submit { flex: 0 0 auto; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--line); background: var(--white); }
        .availability-table { width: 100%; border-collapse: collapse; text-align: left; }
        .availability-table th { padding: 12px 15px; border-bottom: 1px solid var(--line); background: #fbfcf9; color: #788078; font-size: 10px; letter-spacing: .07em; text-transform: uppercase; white-space: nowrap; }
        .availability-table td { padding: 12px 15px; border-bottom: 1px solid #edf0eb; color: #414b42; font-size: 11px; white-space: nowrap; }
        .availability-table tr:last-child td { border-bottom: 0; }
        .remaining-count { color: var(--green); font-weight: 700; }
        @media (max-width: 980px) { .amenities { grid-template-columns: repeat(3, minmax(0, 1fr)); } .room-list { grid-template-columns: 1fr; } }
        @media (max-width: 700px) {
            .page { padding: 24px 15px 38px; }
            .heading { display: block; }
            .heading .section-label { display: block; margin-top: 17px; }
            .field, .field.wide { grid-column: span 6; }
            .field.full { grid-column: 1 / -1; }
            .amenities { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .room-card { grid-template-columns: 115px minmax(0, 1fr); }
            .availability-controls { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .daily-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 420px) {
            .field, .field.wide { grid-column: 1 / -1; }
            .amenities { gap: 10px 8px; }
            .room-card { grid-template-columns: 1fr; }
            .room-card img { height: 165px; min-height: 0; }
            .submit-row { align-items: stretch; flex-direction: column; }
            .submit { width: 100%; }
            .daily-grid { grid-template-columns: 1fr; }
            .availability-actions { align-items: stretch; flex-direction: column; }
        }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } .submit { transition: none; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <div class="crumb">The Queens Hotel <span aria-hidden="true">/</span> Management <span aria-hidden="true">/</span> <strong>Rooms</strong></div>
        <header class="heading">
            <div>
                <p class="eyebrow">Property inventory</p>
                <h1>Add a room</h1>
                <p>Set up room details, rates, availability, and amenities for your hotel.</p>
            </div>
            <span class="section-label">Management · <?= $displayName ?></span>
        </header>

        <?php if ($message !== ''): ?>
            <p class="notice<?= $messageType === 'success' ? ' success' : '' ?>" role="<?= $messageType === 'success' ? 'status' : 'alert' ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!$databaseAvailable): ?>
            <p class="notice" role="alert">Room inventory is unavailable. Import the updated database schema and confirm your database connection.</p>
        <?php endif; ?>

        <form class="form-panel" method="post" action="rooms.php" enctype="multipart/form-data" id="room-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <fieldset class="form-section">
                <legend>Room details</legend>
                <div class="grid">
                    <div class="field wide">
                        <label for="room-type">Room name</label>
                        <select class="control" id="room-type" name="room_type" required>
                            <option value="">Choose room type</option>
                            <?php foreach ($roomTypes as $roomType): ?>
                                <option value="<?= htmlspecialchars($roomType, ENT_QUOTES, 'UTF-8') ?>" <?= $form['room_type'] === $roomType ? 'selected' : '' ?>><?= htmlspecialchars($roomType, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field wide">
                        <label for="availability">Room availability</label>
                        <select class="control" id="availability" name="availability" required>
                            <?php foreach ($availabilityOptions as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $form['availability'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field full">
                        <label for="description">Room description</label>
                        <textarea class="control" id="description" name="description" maxlength="5000" placeholder="Describe the room and its best features."><?= htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Room photos</legend>
                <div class="field full">
                    <input class="photo-input" id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required>
                    <label class="photo-picker" for="photos">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                        <strong>Choose room photos</strong>
                        <span>JPEG, PNG, or WebP · up to 8 images · 5 MB each</span>
                    </label>
                    <div class="photo-preview" id="photo-preview" aria-live="polite"></div>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Rates and stay estimate</legend>
                <div class="grid">
                    <div class="field">
                        <label for="price-per-night">Price per night (PHP)</label>
                        <input class="control" id="price-per-night" name="price_per_night" type="number" min="0.01" max="99999999.99" step="0.01" value="<?= htmlspecialchars($form['price_per_night'], ENT_QUOTES, 'UTF-8') ?>" placeholder="0.00" required>
                    </div>
                    <div class="field">
                        <label for="tax-percent">Taxes (%)</label>
                        <input class="control" id="tax-percent" name="tax_percent" type="number" min="0" max="100" step="0.01" value="<?= htmlspecialchars($form['tax_percent'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field">
                        <label for="service-fee">Fees per stay (PHP)</label>
                        <input class="control" id="service-fee" name="service_fee" type="number" min="0" max="99999999.99" step="0.01" value="<?= htmlspecialchars($form['service_fee'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field full">
                        <span class="field-label">Estimate a selected stay</span>
                        <div class="checkin-grid">
                            <div><label for="check-in">Check-in</label><input class="control" id="check-in" type="date" min="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>"></div>
                            <div><label for="check-out">Check-out</label><input class="control" id="check-out" type="date" min="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>"></div>
                        </div>
                        <div class="estimate" aria-live="polite">
                            <div class="estimate-copy" id="estimate-copy">Choose dates to calculate length of stay and estimated price.</div>
                            <output class="estimate-total" id="estimate-total">$0.00</output>
                            <div class="estimate-breakdown" id="estimate-breakdown">Nightly rate + taxes + fees</div>
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Capacity and room size</legend>
                <div class="grid">
                    <div class="field">
                        <label for="max-guests">Maximum guests</label>
                        <input class="control" id="max-guests" name="max_guests" type="number" min="1" max="30" step="1" value="<?= htmlspecialchars($form['max_guests'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field">
                        <label for="adults-allowed">Adults allowed</label>
                        <input class="control" id="adults-allowed" name="adults_allowed" type="number" min="1" max="30" step="1" value="<?= htmlspecialchars($form['adults_allowed'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field">
                        <label for="children-allowed">Children allowed</label>
                        <input class="control" id="children-allowed" name="children_allowed" type="number" min="0" max="30" step="1" value="<?= htmlspecialchars($form['children_allowed'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field">
                        <label for="bed-count">Number of beds</label>
                        <input class="control" id="bed-count" name="bed_count" type="number" min="1" max="20" step="1" value="<?= htmlspecialchars($form['bed_count'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field">
                        <label for="bed-type">Bed type</label>
                        <select class="control" id="bed-type" name="bed_type" required>
                            <?php foreach ($bedTypes as $bedType): ?>
                                <option value="<?= htmlspecialchars($bedType, ENT_QUOTES, 'UTF-8') ?>" <?= $form['bed_type'] === $bedType ? 'selected' : '' ?>><?= htmlspecialchars($bedType, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="size-sqm">Room size (m²)</label>
                        <input class="control" id="size-sqm" name="size_sqm" type="number" min="0.01" max="10000" step="0.01" value="<?= htmlspecialchars($form['size_sqm'], ENT_QUOTES, 'UTF-8') ?>" placeholder="28" required>
                    </div>
                    <div class="field">
                        <label for="rooms-remaining">Rooms remaining</label>
                        <input class="control" id="rooms-remaining" name="rooms_remaining" type="number" min="0" max="32767" step="1" value="<?= htmlspecialchars($form['rooms_remaining'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Optional">
                        <span class="hint">Leave blank if you prefer not to show a count.</span>
                    </div>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Room amenities</legend>
                <div class="amenities">
                    <?php foreach ($amenities as $amenity): ?>
                        <label class="amenity"><input type="checkbox" name="amenities[]" value="<?= htmlspecialchars($amenity, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($amenity, $selectedAmenities, true) ? 'checked' : '' ?>><span><?= htmlspecialchars($amenity, ENT_QUOTES, 'UTF-8') ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="submit-row">
                <p class="submit-note">Price estimate uses the nightly rate, selected stay length, tax, and fees.</p>
                <button class="submit" type="submit">Save room details <span aria-hidden="true">&#8594;</span></button>
            </div>
        </form>

        <section class="availability-manager" aria-labelledby="daily-availability-heading">
            <div class="inventory-head">
                <div><p class="eyebrow">Daily room inventory</p><h2 id="daily-availability-heading">Set availability by date</h2></div>
            </div>
            <div class="availability-panel">
                <h2>Room type availability</h2>
                <p class="availability-intro">Choose a room type and date range, then set how many rooms are available on each individual day. A count of zero marks that date fully booked. Dates outside this range remain unchanged.</p>
                <?php if ($roomTypesWithInventory === []): ?>
                    <p class="daily-empty">Add at least one room before managing room-type availability.</p>
                <?php else: ?>
                    <form method="post" action="rooms.php#daily-availability-heading" id="availability-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="save_availability">
                        <div class="availability-controls">
                            <div class="field">
                                <label for="inventory-room-type">Room type</label>
                                <select class="control" id="inventory-room-type" name="room_type" required>
                                    <?php foreach ($roomTypesWithInventory as $roomType): ?>
                                        <option value="<?= htmlspecialchars($roomType, ENT_QUOTES, 'UTF-8') ?>" <?= $availabilityForm['room_type'] === $roomType ? 'selected' : '' ?>><?= htmlspecialchars($roomType, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="availability-start">First date</label>
                                <input class="control" id="availability-start" name="start_date" type="date" min="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($availabilityForm['start_date'], ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="field">
                                <label for="availability-end">Last date</label>
                                <input class="control" id="availability-end" name="end_date" type="date" min="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($availabilityForm['end_date'], ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="field">
                                <label for="default-rooms">Default count for dates</label>
                                <input class="control" id="default-rooms" type="number" min="0" max="32767" step="1" value="<?= htmlspecialchars($availabilityForm['default_rooms'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="availability-actions">
                            <p>Choose dates and generate the daily count fields. You can adjust each day independently.</p>
                            <button class="build-days" id="build-days" type="button">Build date list</button>
                        </div>
                        <div class="daily-grid" id="daily-counts" aria-live="polite"></div>
                        <div class="availability-actions">
                            <p id="daily-count-summary">No dates selected.</p>
                            <button class="submit" type="submit">Save daily availability <span aria-hidden="true">&#8594;</span></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="inventory-head" style="margin-top:24px">
                <div><p class="eyebrow">Next 30 days</p><h2>Configured availability</h2></div>
                <span class="inventory-count"><?= number_format(count($dailyAvailability)) ?> date entries</span>
            </div>
            <?php if (!$databaseAvailable): ?>
                <p class="empty">Availability records are unavailable until the database schema is installed.</p>
            <?php elseif ($dailyAvailability === []): ?>
                <p class="empty">No daily availability has been configured for the next 30 days.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="availability-table">
                        <thead><tr><th scope="col">Date</th><th scope="col">Room type</th><th scope="col">Rooms available</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($dailyAvailability as $dailyEntry): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('D, M j, Y', strtotime($dailyEntry['availability_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($dailyEntry['room_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="remaining-count"><?= (int) $dailyEntry['available_rooms'] ?></td>
                                    <td><?= (int) $dailyEntry['available_rooms'] === 0 ? 'Fully booked' : ((int) $dailyEntry['available_rooms'] <= 2 ? 'Limited availability' : 'Available') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="inventory" aria-labelledby="inventory-heading">
            <div class="inventory-head">
                <div><p class="eyebrow">Property inventory</p><h2 id="inventory-heading">Rooms on file</h2></div>
                <span class="inventory-count"><?= number_format(count($roomList)) ?> room<?= count($roomList) === 1 ? '' : 's' ?></span>
            </div>
            <?php if (!$databaseAvailable): ?>
                <p class="empty">Room data is unavailable until the database schema is installed.</p>
            <?php elseif ($roomList === []): ?>
                <p class="empty">No rooms have been added yet.</p>
            <?php else: ?>
                <div class="room-list">
                    <?php foreach ($roomList as $room): ?>
                        <?php $roomAmenities = json_decode((string) $room['amenities'], true); ?>
                        <article class="room-card">
                            <?php if (is_string($room['cover_photo'])): ?>
                                <img src="../<?= htmlspecialchars($room['cover_photo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?> room" loading="lazy">
                            <?php else: ?>
                                <div aria-hidden="true"></div>
                            <?php endif; ?>
                            <div class="room-card__body">
                                <h3><?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="room-card__meta"><?= (int) $room['max_guests'] ?> guests · <?= (int) $room['bed_count'] ?> <?= (int) $room['bed_count'] === 1 ? 'bed' : 'beds' ?> · <?= htmlspecialchars((string) $room['size_sqm'], ENT_QUOTES, 'UTF-8') ?> m²</p>
                                <p class="room-card__price">₱<?= number_format((float) $room['price_per_night'], 2) ?> / night</p>
                                <p class="room-card__amenities"><?= htmlspecialchars(is_array($roomAmenities) ? implode(' · ', array_slice($roomAmenities, 0, 4)) : '', ENT_QUOTES, 'UTF-8') ?></p>
                                <span class="availability<?= $room['availability'] !== 'available' ? ' availability--' . htmlspecialchars($room['availability'], ENT_QUOTES, 'UTF-8') : '' ?>"><?= htmlspecialchars($availabilityOptions[$room['availability']] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?><?= $room['rooms_remaining'] !== null ? ' · ' . (int) $room['rooms_remaining'] . ' left' : '' ?></span>
                                <div class="room-card__actions">
                                    <a class="room-action" href="edit-room.php?id=<?= (int) $room['id'] ?>">Edit room</a>
                                    <form method="post" action="edit-room.php" onsubmit="return confirm('Delete this room? Rooms with bookings cannot be deleted.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="room-action room-action--delete" type="submit">Delete room</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script>
        const dailyRooms = <?= $dailyRoomsJson ?: '{}' ?>;
        const availabilityStart = document.querySelector('#availability-start');
        const availabilityEnd = document.querySelector('#availability-end');
        const defaultRooms = document.querySelector('#default-rooms');
        const dailyCounts = document.querySelector('#daily-counts');
        const dailyCountSummary = document.querySelector('#daily-count-summary');
        const dateFormatter = new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' });

        function buildDailyCounts() {
            if (!availabilityStart || !availabilityEnd || !dailyCounts) return;
            dailyCounts.replaceChildren();
            if (!availabilityStart.value || !availabilityEnd.value || availabilityEnd.value < availabilityStart.value) {
                dailyCountSummary.textContent = 'Choose a valid first and last date.';
                return;
            }

            const firstDate = new Date(`${availabilityStart.value}T00:00:00Z`);
            const lastDate = new Date(`${availabilityEnd.value}T00:00:00Z`);
            const dayCount = Math.floor((lastDate - firstDate) / 86400000) + 1;
            if (dayCount > 366) {
                dailyCountSummary.textContent = 'Choose a range of no more than 366 days.';
                return;
            }

            for (let offset = 0; offset < dayCount; offset += 1) {
                const date = new Date(firstDate);
                date.setUTCDate(date.getUTCDate() + offset);
                const dateKey = date.toISOString().slice(0, 10);
                const item = document.createElement('div');
                item.className = 'daily-item';

                const label = document.createElement('label');
                label.htmlFor = `daily-count-${dateKey}`;
                label.textContent = dateFormatter.format(date);

                const countInput = document.createElement('input');
                countInput.id = `daily-count-${dateKey}`;
                countInput.name = `daily_rooms[${dateKey}]`;
                countInput.type = 'number';
                countInput.min = '0';
                countInput.max = '32767';
                countInput.step = '1';
                countInput.required = true;
                countInput.value = Object.hasOwn(dailyRooms, dateKey) ? dailyRooms[dateKey] : (defaultRooms.value || '0');
                countInput.dataset.manual = Object.hasOwn(dailyRooms, dateKey) ? 'true' : 'false';
                countInput.setAttribute('aria-label', `Available rooms on ${dateKey}`);
                item.append(label, countInput);
                dailyCounts.append(item);
            }

            dailyCountSummary.textContent = `${dayCount} date${dayCount === 1 ? '' : 's'} selected. Each day's room count can be adjusted independently.`;
        }

        document.querySelector('#build-days')?.addEventListener('click', buildDailyCounts);
        availabilityStart?.addEventListener('change', () => {
            availabilityEnd.min = availabilityStart.value;
            buildDailyCounts();
        });
        availabilityEnd?.addEventListener('change', buildDailyCounts);
        defaultRooms?.addEventListener('input', () => {
            dailyCounts?.querySelectorAll('input[data-manual="false"]').forEach((input) => {
                input.value = defaultRooms.value;
            });
        });
        dailyCounts?.addEventListener('input', (event) => {
            if (event.target instanceof HTMLInputElement) event.target.dataset.manual = 'true';
        });
        document.querySelector('#availability-form')?.addEventListener('submit', (event) => {
            if (!dailyCounts.children.length) {
                event.preventDefault();
                dailyCountSummary.textContent = 'Build the date list before saving availability.';
            }
        });
        buildDailyCounts();

        const photoInput = document.querySelector('#photos');
        const photoPreview = document.querySelector('#photo-preview');
        photoInput.addEventListener('change', () => {
            photoPreview.replaceChildren();
            [...photoInput.files].slice(0, 8).forEach((file) => {
                if (!file.type.startsWith('image/')) return;
                const image = document.createElement('img');
                image.alt = file.name;
                image.src = URL.createObjectURL(file);
                photoPreview.append(image);
            });
        });

        const checkIn = document.querySelector('#check-in');
        const checkOut = document.querySelector('#check-out');
        const nightlyInput = document.querySelector('#price-per-night');
        const taxInput = document.querySelector('#tax-percent');
        const feeInput = document.querySelector('#service-fee');
        const estimateCopy = document.querySelector('#estimate-copy');
        const estimateTotal = document.querySelector('#estimate-total');
        const estimateBreakdown = document.querySelector('#estimate-breakdown');
        const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

        function updateEstimate() {
            if (!checkIn.value || !checkOut.value) return;
            const start = new Date(`${checkIn.value}T00:00:00`);
            const end = new Date(`${checkOut.value}T00:00:00`);
            const nights = Math.round((end - start) / 86400000);
            if (nights < 1) {
                estimateCopy.textContent = 'Check-out must be after check-in.';
                estimateTotal.textContent = currency.format(0);
                estimateBreakdown.textContent = 'Select a valid length of stay.';
                return;
            }
            const nightlyRate = Number(nightlyInput.value) || 0;
            const taxRate = Number(taxInput.value) || 0;
            const serviceFee = Number(feeInput.value) || 0;
            const subtotal = nightlyRate * nights;
            const taxes = subtotal * taxRate / 100;
            estimateCopy.textContent = `${nights} night${nights === 1 ? '' : 's'} · ${checkIn.value} to ${checkOut.value}`;
            estimateTotal.textContent = currency.format(subtotal + taxes + serviceFee);
            estimateBreakdown.textContent = `${nights} × ${currency.format(nightlyRate)} = ${currency.format(subtotal)} · Tax ${currency.format(taxes)} · Fees ${currency.format(serviceFee)}`;
        }

        checkIn.addEventListener('change', () => {
            const nextDay = new Date(`${checkIn.value}T00:00:00`);
            nextDay.setDate(nextDay.getDate() + 1);
            checkOut.min = nextDay.toISOString().slice(0, 10);
            if (!checkOut.value || checkOut.value <= checkIn.value) checkOut.value = checkOut.min;
            updateEstimate();
        });
        checkOut.addEventListener('change', updateEstimate);
        [nightlyInput, taxInput, feeInput].forEach((input) => input.addEventListener('input', updateEstimate));
        checkIn.dispatchEvent(new Event('change'));
    </script>
</body>
</html>