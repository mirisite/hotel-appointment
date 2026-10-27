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
    'Free Wi-Fi', 'Air conditioning', 'TV', 'Refrigerator/minibar', 'Electric kettle',
    'Work desk', 'Wardrobe', 'Hair dryer', 'Safe', 'Private bathroom', 'Shower/bathtub',
    'Towels', 'Toiletries', 'Balcony', 'Sea view', 'Mountain view', 'City view',
];
$message = '';
$messageType = 'error';
$room = null;
$photos = [];
$form = [];
$selectedAmenities = [];
$roomIdInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['room_id'] ?? '') : ($_GET['id'] ?? '');
$roomId = is_string($roomIdInput) ? filter_var($roomIdInput, FILTER_VALIDATE_INT) : false;

try {
    if ($roomId === false || $roomId < 1) {
        throw new RuntimeException('Choose a valid room to edit.');
    }

    $pdo = getDatabaseConnection();
    $roomQuery = $pdo->prepare('SELECT * FROM rooms WHERE id = :room_id');
    $roomQuery->execute(['room_id' => $roomId]);
    $room = $roomQuery->fetch();
    if (!$room) {
        throw new RuntimeException('That room could not be found.');
    }

    $photoQuery = $pdo->prepare('SELECT file_path FROM room_photos WHERE room_id = :room_id ORDER BY sort_order, id');
    $photoQuery->execute(['room_id' => $roomId]);
    $photos = $photoQuery->fetchAll(PDO::FETCH_COLUMN);
} catch (RuntimeException $exception) {
    $message = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Room load failed: ' . $exception->getMessage());
    $message = 'Room details are temporarily unavailable. Please try again.';
}

if (is_array($room)) {
    $form = [
        'room_type' => (string) $room['room_type'],
        'price_per_night' => (string) $room['price_per_night'],
        'tax_percent' => (string) $room['tax_percent'],
        'service_fee' => (string) $room['service_fee'],
        'max_guests' => (string) $room['max_guests'],
        'adults_allowed' => (string) $room['adults_allowed'],
        'children_allowed' => (string) $room['children_allowed'],
        'bed_count' => (string) $room['bed_count'],
        'bed_type' => (string) $room['bed_type'],
        'size_sqm' => (string) $room['size_sqm'],
        'availability' => (string) $room['availability'],
        'rooms_remaining' => $room['rooms_remaining'] === null ? '' : (string) $room['rooms_remaining'],
        'description' => (string) ($room['description'] ?? ''),
    ];
    $storedAmenities = json_decode((string) $room['amenities'], true);
    $selectedAmenities = is_array($storedAmenities) ? array_values(array_intersect($amenities, $storedAmenities)) : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($room)) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $actionInput = $_POST['action'] ?? '';
    $action = is_string($actionInput) ? $actionInput : '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'delete') {
        $pdo = null;
        try {
            $pdo = getDatabaseConnection();
            $pdo->beginTransaction();

            $lockRoom = $pdo->prepare('SELECT id FROM rooms WHERE id = :room_id FOR UPDATE');
            $lockRoom->execute(['room_id' => $roomId]);
            if (!$lockRoom->fetchColumn()) {
                throw new RuntimeException('That room could not be found.');
            }

            $bookingCount = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE room_id = :room_id');
            $bookingCount->execute(['room_id' => $roomId]);
            if ((int) $bookingCount->fetchColumn() > 0) {
                throw new RuntimeException('This room has booking records and cannot be deleted. You can edit its details instead.');
            }

            $photoPaths = $pdo->prepare('SELECT file_path FROM room_photos WHERE room_id = :room_id');
            $photoPaths->execute(['room_id' => $roomId]);
            $pathsToRemove = $photoPaths->fetchAll(PDO::FETCH_COLUMN);

            $deleteRoom = $pdo->prepare('DELETE FROM rooms WHERE id = :room_id');
            $deleteRoom->execute(['room_id' => $roomId]);
            $pdo->commit();

            foreach ($pathsToRemove as $photoPath) {
                $photoPath = (string) $photoPath;
                $uploadPrefix = 'uploads/rooms/';
                if (str_starts_with($photoPath, $uploadPrefix) && basename($photoPath) === substr($photoPath, strlen($uploadPrefix))) {
                    $savedFile = __DIR__ . '/../' . $photoPath;
                    if (is_file($savedFile)) {
                        unlink($savedFile);
                    }
                }
            }

            header('Location: rooms.php?room_action=deleted');
            exit;
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Room deletion failed: ' . $exception->getMessage());
            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The room could not be deleted. It may have booking records.';
        }
    } elseif ($action === 'update') {
        foreach ($form as $field => $value) {
            $postedValue = $_POST[$field] ?? $value;
            $form[$field] = is_string($postedValue) ? trim($postedValue) : $value;
        }

        $postedAmenities = $_POST['amenities'] ?? [];
        $selectedAmenities = is_array($postedAmenities)
            ? array_values(array_unique(array_filter($postedAmenities, static fn ($amenity): bool => is_string($amenity) && in_array($amenity, $amenities, true))))
            : [];
        $maxGuests = filter_var($form['max_guests'], FILTER_VALIDATE_INT);
        $adultsAllowed = filter_var($form['adults_allowed'], FILTER_VALIDATE_INT);
        $childrenAllowed = filter_var($form['children_allowed'], FILTER_VALIDATE_INT);
        $bedCount = filter_var($form['bed_count'], FILTER_VALIDATE_INT);
        $roomsRemaining = $form['rooms_remaining'] === '' ? null : filter_var($form['rooms_remaining'], FILTER_VALIDATE_INT);
        $pricePerNight = is_numeric($form['price_per_night']) ? (float) $form['price_per_night'] : 0.0;
        $taxPercent = is_numeric($form['tax_percent']) ? (float) $form['tax_percent'] : -1.0;
        $serviceFee = is_numeric($form['service_fee']) ? (float) $form['service_fee'] : -1.0;
        $sizeSqm = is_numeric($form['size_sqm']) ? (float) $form['size_sqm'] : 0.0;

        if (!in_array($form['room_type'], $roomTypes, true) || !in_array($form['bed_type'], $bedTypes, true) || !array_key_exists($form['availability'], $availabilityOptions)) {
            $message = 'Choose a valid room type, bed type, and availability status.';
        } elseif ($pricePerNight <= 0 || $pricePerNight > 99999999.99 || $taxPercent < 0 || $taxPercent > 100 || $serviceFee < 0 || $serviceFee > 99999999.99) {
            $message = 'Enter a valid nightly price, tax percentage, and service fee.';
        } elseif ($maxGuests === false || $maxGuests < 1 || $maxGuests > 30 || $adultsAllowed === false || $adultsAllowed < 1 || $childrenAllowed === false || $childrenAllowed < 0 || $adultsAllowed + $childrenAllowed > $maxGuests) {
            $message = 'Guest limits must be valid and the adult/child limits cannot exceed maximum guests.';
        } elseif ($bedCount === false || $bedCount < 1 || $bedCount > 20 || $sizeSqm <= 0 || $sizeSqm > 10000) {
            $message = 'Enter a valid bed count and room size.';
        } elseif ($roomsRemaining === false || ($roomsRemaining !== null && ($roomsRemaining < 0 || $roomsRemaining > 32767))) {
            $message = 'Enter a valid number of rooms remaining or leave it blank.';
        } elseif (strlen($form['description']) > 5000) {
            $message = 'Keep the room description under 5,000 characters.';
        } elseif ($form['availability'] === 'fully_booked' && $roomsRemaining !== null && $roomsRemaining !== 0) {
            $message = 'A fully booked room must have zero rooms remaining.';
        } else {
            try {
                $pdo = getDatabaseConnection();
                $updateRoom = $pdo->prepare(
                    'UPDATE rooms SET room_type = :room_type, price_per_night = :price_per_night, tax_percent = :tax_percent, service_fee = :service_fee, max_guests = :max_guests, adults_allowed = :adults_allowed, children_allowed = :children_allowed, bed_count = :bed_count, bed_type = :bed_type, size_sqm = :size_sqm, availability = :availability, rooms_remaining = :rooms_remaining, amenities = :amenities, description = :description WHERE id = :room_id'
                );
                $updateRoom->execute([
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
                    'description' => $form['description'] === '' ? null : $form['description'],
                    'room_id' => $roomId,
                ]);
                header('Location: rooms.php?room_action=updated');
                exit;
            } catch (Throwable $exception) {
                error_log('Room update failed: ' . $exception->getMessage());
                $message = 'Room details could not be saved. Please check the database and try again.';
            }
        }
    } else {
        $message = 'Choose a valid room action.';
    }
}

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$displayName = $escape($_SESSION['user_name'] ?? 'Manager');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>Edit room | The Queens Hotel Management</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1100px); margin: auto; padding: 34px clamp(18px, 4vw, 56px) 64px; }
        .crumb { margin-bottom: 28px; color: var(--muted); font-size: 12px; }
        .crumb a { color: var(--green); text-decoration-color: #aab8aa; text-underline-offset: 3px; }
        .heading { display: flex; align-items: end; justify-content: space-between; gap: 24px; margin-bottom: 24px; }
        .eyebrow { margin: 0 0 9px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 7px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 38px; font-weight: 400; line-height: 1.1; }
        .heading p:last-child { margin: 0; color: var(--muted); font-size: 13px; }
        .panel { padding: clamp(18px, 3vw, 32px); border: 1px solid var(--line); background: var(--white); }
        .notice { margin: 0 0 18px; padding: 12px 14px; border-left: 3px solid #a14f43; background: #f8ebe8; color: #79332d; font-size: 12px; line-height: 1.5; }
        .section { padding: 0 0 23px; margin: 0 0 22px; border: 0; border-bottom: 1px solid #edf0eb; }
        .section legend { width: 100%; padding: 0 0 16px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 21px; }
        .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 15px; }
        .field { min-width: 0; }
        .field.wide { grid-column: span 2; }
        .field.full { grid-column: 1 / -1; }
        .field label { display: block; margin-bottom: 7px; color: #3e4940; font-size: 11px; font-weight: 700; }
        .control { display: block; width: 100%; min-height: 43px; padding: 10px 12px; border: 1px solid #dce1d9; border-radius: 2px; outline: 0; background: #fff; color: var(--ink); font: inherit; font-size: 13px; }
        .control:focus { border-color: var(--green); box-shadow: 0 0 0 3px rgba(23,59,53,.09); }
        textarea.control { min-height: 92px; resize: vertical; }
        .photo-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
        .photo-list img { width: 100%; aspect-ratio: 1.4; object-fit: cover; border: 1px solid var(--line); background: #e7ebe5; }
        .photo-note { margin: 10px 0 0; color: var(--muted); font-size: 11px; }
        .amenities { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px 14px; }
        .amenity { display: flex; min-width: 0; align-items: start; gap: 8px; color: #536056; font-size: 11px; line-height: 1.35; }
        .amenity input { flex: 0 0 auto; margin: 1px 0 0; accent-color: var(--green); }
        .actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .actions-right { display: flex; flex-wrap: wrap; gap: 9px; }
        .button { display: inline-flex; min-height: 43px; align-items: center; justify-content: center; padding: 0 16px; border: 1px solid var(--green); border-radius: 2px; background: var(--green); color: #fff; cursor: pointer; font: inherit; font-size: 12px; font-weight: 650; text-decoration: none; }
        .button:hover { background: #102d29; }
        .button.secondary { border-color: #cbd5cb; background: #fff; color: var(--green); }
        .button.secondary:hover { background: #f0f4ef; }
        .button.danger { border-color: #8a3f35; background: #8a3f35; }
        .button.danger:hover { background: #71332c; }
        @media (max-width: 720px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .amenities { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 480px) { .page { padding: 24px 15px 38px; } .heading { display: block; } .grid { grid-template-columns: 1fr; } .field.wide { grid-column: auto; } .actions { align-items: stretch; flex-direction: column-reverse; } .actions-right, .button { width: 100%; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <div class="crumb"><a href="rooms.php">Rooms</a> <span aria-hidden="true">/</span> Edit room</div>
        <header class="heading">
            <div>
                <p class="eyebrow">Property inventory</p>
                <h1>Edit room</h1>
                <p>Update this room's rates, capacity, availability, and amenities.</p>
            </div>
            <span class="eyebrow">Management · <?= $displayName ?></span>
        </header>

        <?php if ($message !== ''): ?>
            <p class="notice" role="alert"><?= $escape($message) ?></p>
        <?php endif; ?>

        <?php if (is_array($room)): ?>
            <form class="panel" method="post" action="edit-room.php?id=<?= (int) $roomId ?>">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="room_id" value="<?= (int) $roomId ?>">
                <input type="hidden" name="action" value="update">
                <fieldset class="section">
                    <legend>Room details</legend>
                    <div class="grid">
                        <div class="field wide">
                            <label for="room-type">Room name</label>
                            <select class="control" id="room-type" name="room_type" required>
                                <?php foreach ($roomTypes as $roomType): ?>
                                    <option value="<?= $escape($roomType) ?>" <?= $form['room_type'] === $roomType ? 'selected' : '' ?>><?= $escape($roomType) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="availability">Room availability</label>
                            <select class="control" id="availability" name="availability" required>
                                <?php foreach ($availabilityOptions as $value => $label): ?>
                                    <option value="<?= $escape($value) ?>" <?= $form['availability'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field full">
                            <label for="description">Room description</label>
                            <textarea class="control" id="description" name="description" maxlength="5000"><?= $escape($form['description']) ?></textarea>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="section">
                    <legend>Rates</legend>
                    <div class="grid">
                        <div class="field"><label for="price">Price per night (PHP)</label><input class="control" id="price" name="price_per_night" type="number" min="0.01" max="99999999.99" step="0.01" value="<?= $escape($form['price_per_night']) ?>" required></div>
                        <div class="field"><label for="tax">Taxes (%)</label><input class="control" id="tax" name="tax_percent" type="number" min="0" max="100" step="0.01" value="<?= $escape($form['tax_percent']) ?>" required></div>
                        <div class="field"><label for="fee">Fees per stay (PHP)</label><input class="control" id="fee" name="service_fee" type="number" min="0" max="99999999.99" step="0.01" value="<?= $escape($form['service_fee']) ?>" required></div>
                    </div>
                </fieldset>

                <fieldset class="section">
                    <legend>Capacity and room size</legend>
                    <div class="grid">
                        <div class="field"><label for="max-guests">Maximum guests</label><input class="control" id="max-guests" name="max_guests" type="number" min="1" max="30" value="<?= $escape($form['max_guests']) ?>" required></div>
                        <div class="field"><label for="adults">Adults allowed</label><input class="control" id="adults" name="adults_allowed" type="number" min="1" max="30" value="<?= $escape($form['adults_allowed']) ?>" required></div>
                        <div class="field"><label for="children">Children allowed</label><input class="control" id="children" name="children_allowed" type="number" min="0" max="30" value="<?= $escape($form['children_allowed']) ?>" required></div>
                        <div class="field"><label for="bed-count">Number of beds</label><input class="control" id="bed-count" name="bed_count" type="number" min="1" max="20" value="<?= $escape($form['bed_count']) ?>" required></div>
                        <div class="field"><label for="bed-type">Bed type</label><select class="control" id="bed-type" name="bed_type" required><?php foreach ($bedTypes as $bedType): ?><option value="<?= $escape($bedType) ?>" <?= $form['bed_type'] === $bedType ? 'selected' : '' ?>><?= $escape($bedType) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label for="size">Room size (m²)</label><input class="control" id="size" name="size_sqm" type="number" min="0.01" max="10000" step="0.01" value="<?= $escape($form['size_sqm']) ?>" required></div>
                        <div class="field"><label for="remaining">Rooms remaining</label><input class="control" id="remaining" name="rooms_remaining" type="number" min="0" max="32767" value="<?= $escape($form['rooms_remaining']) ?>"><span class="photo-note">Leave blank if you prefer not to show a count.</span></div>
                    </div>
                </fieldset>

                <fieldset class="section">
                    <legend>Room amenities</legend>
                    <div class="amenities">
                        <?php foreach ($amenities as $amenity): ?>
                            <label class="amenity"><input type="checkbox" name="amenities[]" value="<?= $escape($amenity) ?>" <?= in_array($amenity, $selectedAmenities, true) ? 'checked' : '' ?>><span><?= $escape($amenity) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="section">
                    <legend>Current room photos</legend>
                    <div class="photo-list">
                        <?php foreach ($photos as $photo): ?>
                            <img src="../<?= $escape($photo) ?>" alt="<?= $escape($room['room_type']) ?> room photo" loading="lazy">
                        <?php endforeach; ?>
                    </div>
                    <p class="photo-note">Current photos are kept when you save room details.</p>
                </fieldset>

                <div class="actions">
                    <a class="button secondary" href="rooms.php">Cancel</a>
                    <div class="actions-right">
                        <button class="button" type="submit">Save changes</button>
                    </div>
                </div>
            </form>
            <form method="post" action="edit-room.php" style="margin-top:12px" onsubmit="return confirm('Delete this room? Rooms with bookings cannot be deleted.');">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="room_id" value="<?= (int) $roomId ?>">
                <input type="hidden" name="action" value="delete">
                <button class="button danger" type="submit">Delete room</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>