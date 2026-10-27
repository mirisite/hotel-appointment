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

$today = new DateTimeImmutable('today');
$tomorrow = $today->modify('+1 day');
$checkInRaw = $_GET['check_in'] ?? $today->format('Y-m-d');
$checkOutRaw = $_GET['check_out'] ?? $tomorrow->format('Y-m-d');
$checkInDate = is_string($checkInRaw) ? DateTimeImmutable::createFromFormat('!Y-m-d', $checkInRaw) : false;
$checkOutDate = is_string($checkOutRaw) ? DateTimeImmutable::createFromFormat('!Y-m-d', $checkOutRaw) : false;
$filterMessage = '';

if (
    !$checkInDate || !$checkOutDate
    || $checkInDate->format('Y-m-d') !== $checkInRaw
    || $checkOutDate->format('Y-m-d') !== $checkOutRaw
    || $checkInDate < $today
    || $checkOutDate <= $checkInDate
    || $checkInDate->diff($checkOutDate)->days > 365
) {
    $filterMessage = 'Choose valid future dates for a stay of up to 365 nights.';
    $checkInDate = $today;
    $checkOutDate = $tomorrow;
}

$adultsRaw = $_GET['adults'] ?? '1';
$childrenRaw = $_GET['children'] ?? '0';
$adults = is_string($adultsRaw) ? filter_var($adultsRaw, FILTER_VALIDATE_INT) : false;
$children = is_string($childrenRaw) ? filter_var($childrenRaw, FILTER_VALIDATE_INT) : false;
if ($adults === false || $adults < 1 || $adults > 30) {
    $adults = 1;
}
if ($children === false || $children < 0 || $children > 30) {
    $children = 0;
}
$availableOnly = ($_GET['available_only'] ?? '') === '1';
$stayNights = (int) $checkInDate->diff($checkOutDate)->days;
$stayDates = [];
foreach (new DatePeriod($checkInDate, new DateInterval('P1D'), $checkOutDate) as $stayDate) {
    $stayDates[] = $stayDate->format('Y-m-d');
}

$rooms = [];
$roomsByType = [];
$photosByRoom = [];
$dailyCounts = [];
$databaseAvailable = true;

try {
    $pdo = getDatabaseConnection();

    $rooms = $pdo->query('SELECT * FROM rooms ORDER BY price_per_night ASC, room_type ASC, created_at DESC')->fetchAll();
    $photoRows = $pdo->query('SELECT room_id, file_path, sort_order FROM room_photos ORDER BY room_id, sort_order')->fetchAll();
    foreach ($photoRows as $photo) {
        $photosByRoom[(int) $photo['room_id']][] = $photo['file_path'];
    }

    $availabilityQuery = $pdo->prepare(
        'SELECT room_type, availability_date, available_rooms FROM room_type_availability WHERE availability_date >= :check_in AND availability_date < :check_out'
    );
    $availabilityQuery->execute([
        'check_in' => $checkInDate->format('Y-m-d'),
        'check_out' => $checkOutDate->format('Y-m-d'),
    ]);
    foreach ($availabilityQuery->fetchAll() as $availabilityRow) {
        $dailyCounts[$availabilityRow['room_type']][$availabilityRow['availability_date']] = (int) $availabilityRow['available_rooms'];
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
}

$visibleRooms = [];
foreach ($rooms as $room) {
    if ((int) $room['max_guests'] < $adults + $children
        || (int) $room['adults_allowed'] < $adults
        || (int) $room['children_allowed'] < $children) {
        continue;
    }

    $minimumAvailable = null;
    $hasUnsetDate = false;
    $isFullyBooked = $room['availability'] === 'fully_booked';
    foreach ($stayDates as $stayDate) {
        if (isset($dailyCounts[$room['room_type']]) && array_key_exists($stayDate, $dailyCounts[$room['room_type']])) {
            $dateCount = $dailyCounts[$room['room_type']][$stayDate];
        } elseif ($room['availability'] === 'fully_booked') {
            $dateCount = 0;
            $hasUnsetDate = true;
        } elseif ($room['rooms_remaining'] !== null) {
            $dateCount = (int) $room['rooms_remaining'];
            $hasUnsetDate = true;
        } else {
            $dateCount = null;
            $hasUnsetDate = true;
        }

        if ($dateCount === 0) {
            $isFullyBooked = true;
        }
        if ($dateCount !== null) {
            $minimumAvailable = $minimumAvailable === null ? $dateCount : min($minimumAvailable, $dateCount);
        }
    }

    if ($isFullyBooked) {
        $statusKey = 'fully_booked';
        $statusLabel = 'Fully booked';
    } elseif ($room['availability'] === 'limited' || ($minimumAvailable !== null && $minimumAvailable <= 2)) {
        $statusKey = 'limited';
        $statusLabel = 'Limited availability';
    } else {
        $statusKey = 'available';
        $statusLabel = 'Available';
    }

    if ($availableOnly && $isFullyBooked) {
        continue;
    }

    $room['stay_nights'] = $stayNights;
    $room['estimated_subtotal'] = (float) $room['price_per_night'] * $stayNights;
    $room['estimated_taxes'] = $room['estimated_subtotal'] * (float) $room['tax_percent'] / 100;
    $room['estimated_total'] = $room['estimated_subtotal'] + $room['estimated_taxes'] + (float) $room['service_fee'];
    $room['minimum_available'] = $minimumAvailable;
    $room['has_unset_date'] = $hasUnsetDate;
    $room['status_key'] = $statusKey;
    $room['status_label'] = $statusLabel;
    $room['photo_list'] = $photosByRoom[(int) $room['id']] ?? [];
    $visibleRooms[] = $room;
    $roomsByType[$room['room_type']] = true;
}

$money = static fn (float $amount): string => '₱' . number_format($amount, 2);
$nightLabel = $stayNights . ' night' . ($stayNights === 1 ? '' : 's');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>Explore Rooms | The Queens Hotel</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --green-soft: #eaf0ea; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1440px); margin: auto; padding: 38px clamp(18px, 4vw, 58px) 64px; }
        .eyebrow { margin: 0 0 10px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        .intro { display: flex; align-items: end; justify-content: space-between; gap: 24px; margin-bottom: 26px; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { margin-bottom: 8px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(34px, 4vw, 48px); font-weight: 400; line-height: 1.08; }
        .intro-copy { max-width: 550px; margin: 0; color: var(--muted); font-size: 14px; line-height: 1.6; }
        .guest-greeting { color: var(--muted); font-size: 12px; white-space: nowrap; }
        .search { position: relative; z-index: 5; display: flex; align-items: stretch; gap: 0; margin-bottom: 29px; padding: 8px; border: 1px solid #e2e7e0; border-radius: 13px; background: var(--white); box-shadow: 0 12px 34px rgba(30, 48, 37, .08), 0 2px 6px rgba(30, 48, 37, .04); }
        .search-segments { display: grid; min-width: 0; flex: 1 1 auto; grid-template-columns: minmax(155px, 1fr) minmax(155px, 1fr) minmax(150px, .9fr); align-items: stretch; }
        .search-segment { position: relative; display: flex; min-width: 0; min-height: 68px; align-items: center; gap: 11px; padding: 10px 17px; }
        .search-segment + .search-segment { border-left: 1px solid #e8ece7; }
        .segment-icon { display: grid; width: 20px; height: 20px; flex: 0 0 auto; place-items: center; color: #758378; }
        .segment-icon svg { display: block; width: 19px; height: 19px; }
        .segment-copy { display: grid; min-width: 0; gap: 3px; }
        .segment-label { color: #768075; font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .search-date { width: 100%; min-width: 0; min-height: 28px; padding: 0; border: 0; outline: none; background: transparent; color: var(--ink); font: inherit; font-size: 13px; font-weight: 600; }
        .search-date:focus-visible { border-radius: 3px; outline: 2px solid rgba(23,59,53,.3); outline-offset: 3px; }
        .guest-picker { position: relative; display: block; width: 100%; min-width: 0; }
        .guest-trigger { display: flex; width: 100%; min-height: 28px; align-items: center; justify-content: space-between; gap: 8px; padding: 0; border: 0; background: transparent; color: var(--ink); cursor: pointer; font: inherit; font-size: 13px; font-weight: 600; text-align: left; }
        .guest-trigger:focus-visible { border-radius: 3px; outline: 2px solid rgba(23,59,53,.3); outline-offset: 3px; }
        .guest-caret { width: 14px; height: 14px; flex: 0 0 auto; color: #768075; transition: transform 150ms ease; }
        .guest-trigger[aria-expanded="true"] .guest-caret { transform: rotate(180deg); }
        .guest-popover { position: absolute; top: calc(100% + 18px); right: -20px; z-index: 12; width: min(320px, calc(100vw - 36px)); padding: 17px; border: 1px solid var(--line); border-radius: 10px; background: #fff; box-shadow: 0 18px 45px rgba(24, 42, 31, .16); }
        .guest-popover[hidden] { display: none; }
        .guest-popover-title { margin: 0 0 12px; color: var(--ink); font-family: Georgia, "Times New Roman", serif; font-size: 18px; }
        .guest-row { display: flex; min-height: 60px; align-items: center; justify-content: space-between; gap: 12px; border-top: 1px solid #edf0eb; }
        .guest-row-copy { display: grid; gap: 3px; color: var(--ink); font-size: 12px; font-weight: 650; }
        .guest-row-copy small { color: var(--muted); font-size: 10px; font-weight: 400; }
        .stepper { display: grid; grid-template-columns: 44px 34px 44px; align-items: center; text-align: center; }
        .stepper button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #dce3db; border-radius: 50%; background: #fff; color: var(--green); cursor: pointer; }
        .stepper button:hover:not(:disabled) { background: #edf3ed; }
        .stepper button:disabled { color: #b8c0b8; cursor: not-allowed; }
        .stepper button:focus-visible { outline: 3px solid rgba(23,59,53,.2); outline-offset: 2px; }
        .stepper button svg { width: 15px; height: 15px; }
        .stepper output { color: var(--ink); font-size: 12px; font-weight: 700; }
        .search-tools { display: flex; flex: 0 0 auto; align-items: center; gap: 11px; padding: 0 10px 0 14px; border-left: 1px solid #e8ece7; }
        .available-toggle { display: inline-flex; min-height: 44px; align-items: center; gap: 9px; color: #627064; cursor: pointer; font-size: 10px; line-height: 1.35; }
        .available-toggle input { position: absolute; width: 1px; height: 1px; opacity: 0; }
        .toggle-track { position: relative; width: 34px; height: 20px; flex: 0 0 auto; border-radius: 20px; background: #dce2dc; transition: background 150ms ease; }
        .toggle-track::after { position: absolute; top: 3px; left: 3px; width: 14px; height: 14px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(20,30,20,.2); content: ""; transition: transform 150ms ease; }
        .available-toggle input:checked + .toggle-track { background: var(--green); }
        .available-toggle input:checked + .toggle-track::after { transform: translateX(14px); }
        .available-toggle input:focus-visible + .toggle-track { outline: 3px solid rgba(23,59,53,.22); outline-offset: 2px; }
        .search-button { display: inline-flex; min-width: 128px; min-height: 52px; align-items: center; justify-content: center; gap: 9px; padding: 0 17px; border: 1px solid var(--green); border-radius: 8px; background: var(--green); color: #fff; cursor: pointer; font: inherit; font-size: 11px; font-weight: 700; box-shadow: 0 4px 10px rgba(23,59,53,.14); transition: background 150ms ease, transform 150ms ease, box-shadow 150ms ease; }
        .search-button:hover { transform: translateY(-1px); background: #102d29; box-shadow: 0 7px 14px rgba(23,59,53,.19); }
        .search-button:focus-visible { outline: 3px solid rgba(23,59,53,.25); outline-offset: 3px; }
        .search-button svg { width: 15px; height: 15px; }
        .mobile-summary, .sheet-heading { display: none; }
        .results-head { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
        .results-head h2 { margin: 0; font-family: Georgia, "Times New Roman", serif; font-size: 25px; font-weight: 400; }
        .results-meta { color: var(--muted); font-size: 11px; }
        .room-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .room-card { min-width: 0; overflow: hidden; border: 1px solid var(--line); background: var(--white); animation: rise 420ms ease-out both; }
        .photo-strip { display: grid; grid-template-columns: 1fr 76px; gap: 3px; height: 230px; overflow: hidden; background: #e7ebe5; }
        .photo-main, .photo-thumbs img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .photo-main { min-width: 0; }
        .photo-strip--single { grid-template-columns: 1fr; }
        .photo-thumbs { display: grid; grid-auto-rows: 1fr; gap: 3px; min-height: 0; overflow: hidden; }
        .photo-thumbs img { min-height: 0; }
        .photo-empty { display: grid; height: 230px; place-items: center; background: linear-gradient(135deg, #e9eee8, #dce5dc); color: #728074; font-size: 11px; }
        .room-body { padding: 19px 20px 17px; }
        .room-title-row { display: flex; align-items: start; justify-content: space-between; gap: 14px; }
        .room-card h3 { margin: 0 0 7px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 25px; font-weight: 400; line-height: 1.15; }
        .room-meta { margin: 0 0 13px; color: var(--muted); font-size: 11px; line-height: 1.5; }
        .status { flex: 0 0 auto; padding: 6px 8px; background: #edf3ed; color: #416748; font-size: 9px; font-weight: 650; }
        .status--limited { background: #f5f0e6; color: #886735; }
        .status--fully_booked { background: #f5eae7; color: #8a4c41; }
        .availability-note { margin: -5px 0 14px; color: #8b7560; font-size: 10px; line-height: 1.45; }
        .feature-list { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 15px; }
        .feature { padding: 5px 7px; background: #f3f5f1; color: #647066; font-size: 9px; }
        .room-details { padding-top: 12px; border-top: 1px solid #edf0eb; }
        .room-details summary { width: fit-content; color: var(--green); cursor: pointer; font-size: 10px; font-weight: 700; text-underline-offset: 3px; }
        .room-description { margin: 11px 0 0; color: #616c63; font-size: 11px; line-height: 1.6; }
        .amenity-list { display: flex; flex-wrap: wrap; gap: 6px; margin: 11px 0 0; padding: 0; list-style: none; }
        .amenity-list li { padding: 5px 7px; border: 1px solid #e8ece6; color: #58655a; font-size: 9px; }
        .details-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(85px, 1fr)); gap: 7px; margin-top: 12px; }
        .details-photos img { width: 100%; aspect-ratio: 1.35; object-fit: cover; }
        .rate-row { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-top: 15px; padding-top: 14px; border-top: 1px solid #edf0eb; }
        .rate-caption { margin: 0; color: var(--muted); font-size: 10px; line-height: 1.5; }
        .rate-caption strong { color: var(--ink); font-size: 12px; }
        .rate-total { text-align: right; }
        .rate-total strong { display: block; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 26px; font-weight: 400; }
        .rate-total span { color: var(--muted); font-size: 9px; }
        .price-details { display: flex; justify-content: space-between; gap: 10px; margin-top: 12px; padding-top: 10px; border-top: 1px dashed #e4e8e1; color: #68736a; font-size: 10px; }
        .booking-link { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; gap: 8px; margin-top: 13px; padding: 0 14px; border: 1px solid var(--green); border-radius: 4px; background: var(--green); color: #fff; font-size: 11px; font-weight: 700; text-decoration: none; transition: background-color 150ms ease, transform 150ms ease; }
        .booking-link:hover { transform: translateY(-1px); background: #102d29; }
        .booking-link:focus-visible { outline: 3px solid rgba(23,59,53,.25); outline-offset: 3px; }
        .message { margin-bottom: 18px; padding: 12px 14px; border-left: 3px solid #ae8551; background: #f5f0e6; color: #684f30; font-size: 12px; line-height: 1.5; }
        .message--error { border-color: #a14f43; background: #f8ebe8; color: #79332d; }
        .empty { padding: 44px 20px; border: 1px solid var(--line); background: var(--white); text-align: center; }
        .empty h2 { margin-bottom: 8px; font-family: Georgia, "Times New Roman", serif; font-size: 25px; font-weight: 400; }
        .empty p { margin: 0; color: var(--muted); font-size: 12px; line-height: 1.6; }
        .footnote { margin: 22px 0 0; color: #8b938b; font-size: 10px; line-height: 1.5; }
        @keyframes rise { from { opacity: 0; transform: translateY(7px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 1100px) {
            .search { display: block; padding: 10px; }
            .search-segments { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .search-tools { justify-content: space-between; margin-top: 8px; padding: 9px 4px 2px; border-top: 1px solid #e8ece7; border-left: 0; }
            .search-button { min-width: 150px; }
        }
        @media (max-width: 1000px) { .room-list { grid-template-columns: 1fr; } }
        @media (max-width: 640px) {
            .page { padding: 27px 15px 35px; }
            .intro { display: block; margin-bottom: 20px; }
            .guest-greeting { display: block; margin-top: 12px; }
            .search { position: relative; z-index: 5; display: block; margin: 0 0 24px; padding: 0; border: 0; border-radius: 0; background: transparent; box-shadow: none; }
            .mobile-summary { display: flex; width: 100%; min-height: 58px; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 12px 8px 15px; border: 1px solid #e1e7df; border-radius: 11px; background: #fff; color: var(--ink); cursor: pointer; box-shadow: 0 8px 22px rgba(30,48,37,.07); text-align: left; }
            .mobile-summary:focus-visible { outline: 3px solid rgba(23,59,53,.24); outline-offset: 3px; }
            .mobile-summary-copy { display: flex; min-width: 0; align-items: center; gap: 10px; }
            .mobile-summary-copy svg { width: 20px; height: 20px; flex: 0 0 auto; color: var(--green); }
            .mobile-summary-text { overflow: hidden; color: #344238; font-size: 12px; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
            .mobile-edit { display: inline-flex; min-height: 42px; flex: 0 0 auto; align-items: center; gap: 6px; padding: 0 10px; border-radius: 7px; background: var(--green-soft); color: var(--green); font-size: 10px; font-weight: 700; }
            .mobile-edit svg { width: 14px; height: 14px; }
            .sheet-heading { display: none; }
            .search-segments, .search-tools { display: none; }
            .search.search--editing { position: fixed; z-index: 1001; right: 0; bottom: 0; left: 0; display: block; max-height: min(92svh, 820px); overflow-y: auto; padding: 20px 18px calc(15px + env(safe-area-inset-bottom)); border: 1px solid #e4e8e2; border-bottom: 0; border-radius: 19px 19px 0 0; background: #fff; box-shadow: 0 -18px 55px rgba(16,35,24,.19); animation: sheet-rise 200ms ease-out both; }
            body.search-sheet-open { overflow: hidden; }
            body.search-sheet-open::before { position: fixed; z-index: 1000; inset: 0; background: rgba(16,27,20,.42); content: ""; animation: backdrop-in 160ms ease-out both; }
            .search.search--editing .mobile-summary { display: none; }
            .search.search--editing .sheet-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 15px; }
            .sheet-heading span { color: var(--ink); font-family: Georgia, "Times New Roman", serif; font-size: 24px; }
            .sheet-close { display: grid; width: 48px; height: 48px; place-items: center; border: 1px solid #e3e8e2; border-radius: 50%; background: #fff; color: var(--ink); cursor: pointer; }
            .sheet-close svg { width: 19px; height: 19px; }
            .search.search--editing .search-segments { display: grid; grid-template-columns: 1fr; gap: 11px; }
            .search.search--editing .search-segment { min-height: 66px; padding: 10px 13px; border: 1px solid #e2e7e0; border-radius: 9px; }
            .search.search--editing .search-segment + .search-segment { border-left: 1px solid #e2e7e0; }
            .search.search--editing .search-date { min-height: 42px; font-size: 14px; }
            .search.search--editing .guest-trigger { min-height: 42px; font-size: 14px; }
            .search.search--editing .segment-icon { width: 22px; height: 22px; }
            .search.search--editing .segment-icon svg { width: 21px; height: 21px; }
            .search.search--editing .guest-popover { position: static; width: 100%; margin-top: 9px; padding: 10px 12px; border-radius: 8px; box-shadow: none; }
            .search.search--editing .guest-row { min-height: 64px; }
            .search.search--editing .stepper { grid-template-columns: 48px 40px 48px; }
            .search.search--editing .stepper button { width: 48px; height: 48px; }
            .search.search--editing .search-tools { display: flex; align-items: stretch; flex-direction: column; gap: 10px; margin: 11px 0 0; padding: 11px 0 0; border-top: 1px solid #edf0eb; }
            .search.search--editing .available-toggle { min-height: 48px; font-size: 12px; }
            .search.search--editing .search-button { position: sticky; bottom: 0; width: 100%; min-height: 54px; border-radius: 9px; }
            .photo-strip { height: 200px; }
            .room-body { padding: 16px; }
        }
        @media (max-width: 380px) { .photo-strip { grid-template-columns: 1fr 58px; height: 175px; } .room-title-row { display: block; } .status { display: inline-block; margin-bottom: 10px; } }
        @keyframes sheet-rise { from { opacity: .7; transform: translateY(22px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes backdrop-in { from { opacity: 0; } to { opacity: 1; } }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } .room-card { animation: none; } .search.search--editing, body.search-sheet-open::before { animation: none; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <header class="intro">
            <div>
                <p class="eyebrow">The Queens Hotel · Guest rooms</p>
                <h1>Find your kind of stay.</h1>
                <p class="intro-copy">Explore the rooms, compare amenities and check estimated pricing for your dates.</p>
            </div>
            <span class="guest-greeting">Welcome, <?= htmlspecialchars((string) ($_SESSION['user_name'] ?? 'guest'), ENT_QUOTES, 'UTF-8') ?></span>
        </header>

        <?php if ($filterMessage !== ''): ?>
            <p class="message message--error" role="alert"><?= htmlspecialchars($filterMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!$databaseAvailable): ?>
            <p class="message message--error" role="alert">Room listings are temporarily unavailable. Please try again later.</p>
        <?php endif; ?>

        <form class="search" id="room-search" method="get" action="rooms.php" aria-label="Search room availability">
            <button class="mobile-summary" id="mobile-search-open" type="button" aria-haspopup="dialog" aria-controls="room-search" aria-label="Edit stay dates and guests">
                <span class="mobile-summary-copy">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                    <span class="mobile-summary-text" id="mobile-search-summary"></span>
                </span>
                <span class="mobile-edit">Edit <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></span>
            </button>
            <div class="sheet-heading"><span id="search-sheet-title">Stay details</span><button class="sheet-close" id="mobile-search-close" type="button" aria-label="Close search settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></div>
            <div class="search-segments">
                <label class="search-segment" for="check-in">
                    <span class="segment-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
                    <span class="segment-copy"><span class="segment-label">Check-in</span><input class="search-date" id="check-in" name="check_in" type="date" min="<?= htmlspecialchars($today->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($checkInDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required></span>
                </label>
                <label class="search-segment" for="check-out">
                    <span class="segment-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h2M8 17h2"/></svg></span>
                    <span class="segment-copy"><span class="segment-label">Check-out</span><input class="search-date" id="check-out" name="check_out" type="date" min="<?= htmlspecialchars($checkInDate->modify('+1 day')->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($checkOutDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required></span>
                </label>
                <div class="search-segment">
                    <span class="segment-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                    <span class="segment-copy"><span class="segment-label">Guests</span>
                        <span class="guest-picker" id="guest-picker">
                            <button class="guest-trigger" id="guest-trigger" type="button" aria-expanded="false" aria-controls="guest-popover">
                                <span id="guest-summary"></span><svg class="guest-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <input id="adults" name="adults" type="hidden" value="<?= (int) $adults ?>">
                            <input id="children" name="children" type="hidden" value="<?= (int) $children ?>">
                            <div class="guest-popover" id="guest-popover" role="group" aria-label="Choose number of guests" hidden>
                                <p class="guest-popover-title">Guests</p>
                                <div class="guest-row"><span class="guest-row-copy">Adults<small>Ages 13 or above</small></span><span class="stepper"><button type="button" data-guest="adults" data-change="-1" aria-label="Remove one adult"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg></button><output id="adults-count"><?= (int) $adults ?></output><button type="button" data-guest="adults" data-change="1" aria-label="Add one adult"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button></span></div>
                                <div class="guest-row"><span class="guest-row-copy">Children<small>Ages 0–12</small></span><span class="stepper"><button type="button" data-guest="children" data-change="-1" aria-label="Remove one child"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg></button><output id="children-count"><?= (int) $children ?></output><button type="button" data-guest="children" data-change="1" aria-label="Add one child"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button></span></div>
                            </div>
                        </span>
                    </span>
                </div>
            </div>
            <div class="search-tools">
                <label class="available-toggle"><input type="checkbox" name="available_only" value="1" <?= $availableOnly ? 'checked' : '' ?>><span class="toggle-track" aria-hidden="true"></span><span>Hide fully<br>booked rooms</span></label>
                <button class="search-button" type="submit">Check rooms <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
            </div>
        </form>

        <div class="results-head">
            <div><p class="eyebrow">Room collection</p><h2>Stay options</h2></div>
            <span class="results-meta"><?= number_format(count($visibleRooms)) ?> option<?= count($visibleRooms) === 1 ? '' : 's' ?> · <?= $stayNights ?> night<?= $stayNights === 1 ? '' : 's' ?></span>
        </div>

        <?php if (!$databaseAvailable): ?>
            <section class="empty"><h2>Rooms are taking a moment.</h2><p>Please refresh the page shortly.</p></section>
        <?php elseif ($visibleRooms === []): ?>
            <section class="empty"><h2>No matching rooms found.</h2><p>Try changing the dates or guest count, or turn off “Hide fully booked rooms”.</p></section>
        <?php else: ?>
            <section class="room-list" aria-label="Available hotel rooms">
                <?php foreach ($visibleRooms as $room): ?>
                    <?php
                    $roomAmenities = json_decode((string) $room['amenities'], true);
                    $roomAmenities = is_array($roomAmenities) ? $roomAmenities : [];
                    $photos = $room['photo_list'];
                    ?>
                    <article class="room-card" id="room-<?= (int) $room['id'] ?>">
                        <?php if ($photos !== []): ?>
                            <div class="photo-strip<?= count($photos) === 1 ? ' photo-strip--single' : '' ?>">
                                <img class="photo-main" src="../<?= htmlspecialchars($photos[0], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?> room photo" loading="lazy">
                                <?php if (count($photos) > 1): ?>
                                    <div class="photo-thumbs">
                                        <?php foreach (array_slice($photos, 1, 3) as $photo): ?>
                                            <img src="../<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?> room detail" loading="lazy">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="photo-empty">Room photos coming soon</div>
                        <?php endif; ?>
                        <div class="room-body">
                            <div class="room-title-row">
                                <div><p class="eyebrow">The Queens Hotel</p><h3><?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?></h3></div>
                                <span class="status<?= $room['status_key'] !== 'available' ? ' status--' . htmlspecialchars($room['status_key'], ENT_QUOTES, 'UTF-8') : '' ?>"><?= htmlspecialchars($room['status_label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <p class="room-meta"><?= (int) $room['size_sqm'] ?> m² · <?= (int) $room['max_guests'] ?> guests max · <?= (int) $room['bed_count'] ?> <?= (int) $room['bed_count'] === 1 ? 'bed' : 'beds' ?> · <?= htmlspecialchars($room['bed_type'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if ($room['status_key'] === 'fully_booked'): ?>
                                <p class="availability-note">No rooms are available for at least one night of your selected stay.</p>
                            <?php elseif ($room['minimum_available'] !== null): ?>
                                <p class="availability-note">As few as <?= (int) $room['minimum_available'] ?> room<?= (int) $room['minimum_available'] === 1 ? '' : 's' ?> available on a night during your stay<?= $room['has_unset_date'] ? '; some dates use the room’s default availability.' : '.' ?></p>
                            <?php else: ?>
                                <p class="availability-note">Daily room counts have not been set for these dates. Contact the hotel to confirm.</p>
                            <?php endif; ?>
                            <div class="feature-list">
                                <?php foreach (array_slice($roomAmenities, 0, 5) as $amenity): ?>
                                    <span class="feature"><?= htmlspecialchars((string) $amenity, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                            <details class="room-details">
                                <summary>Room details and all amenities</summary>
                                <?php if (is_string($room['description']) && trim($room['description']) !== ''): ?>
                                    <p class="room-description"><?= nl2br(htmlspecialchars($room['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                                <?php endif; ?>
                                <ul class="amenity-list">
                                    <?php foreach ($roomAmenities as $amenity): ?>
                                        <li><?= htmlspecialchars((string) $amenity, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                    <li><?= (int) $room['adults_allowed'] ?> adults max</li>
                                    <li><?= (int) $room['children_allowed'] ?> children max</li>
                                </ul>
                                <?php if (count($photos) > 1): ?>
                                    <div class="details-photos">
                                        <?php foreach ($photos as $photo): ?>
                                            <img src="../<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?> photo" loading="lazy">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </details>
                            <div class="rate-row">
                                <p class="rate-caption"><strong><?= $money((float) $room['price_per_night']) ?></strong> / night<br><?= $nightLabel ?> · includes estimated taxes and fees</p>
                                <div class="rate-total"><strong><?= $money((float) $room['estimated_total']) ?></strong><span>estimated total</span></div>
                            </div>
                            <div class="price-details"><span>Taxes <?= $money((float) $room['estimated_taxes']) ?></span><span>Fees <?= $money((float) $room['service_fee']) ?></span></div>
                            <?php if ($room['status_key'] !== 'fully_booked'): ?>
                                <a class="booking-link" href="booking.php?room_id=<?= (int) $room['id'] ?>&amp;check_in=<?= rawurlencode($checkInDate->format('Y-m-d')) ?>&amp;check_out=<?= rawurlencode($checkOutDate->format('Y-m-d')) ?>&amp;adults=<?= (int) $adults ?>&amp;children=<?= (int) $children ?>&amp;room_count=1">Book this room <span aria-hidden="true">&#8594;</span></a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <p class="footnote">Prices are estimates based on the room’s listed nightly rate, tax, and service fee. Availability reflects the hotel’s configured daily room counts and may change.</p>
    </main>
    <script>
        const searchForm = document.querySelector('#room-search');
        const checkInInput = document.querySelector('#check-in');
        const checkOutInput = document.querySelector('#check-out');
        const adultsInput = document.querySelector('#adults');
        const childrenInput = document.querySelector('#children');
        const guestTrigger = document.querySelector('#guest-trigger');
        const guestPopover = document.querySelector('#guest-popover');
        const mobileSearchOpen = document.querySelector('#mobile-search-open');
        const mobileSearchClose = document.querySelector('#mobile-search-close');
        const mobileSearchSummary = document.querySelector('#mobile-search-summary');
        const guestCount = document.querySelector('#guest-summary');
        const mobileDateFormatter = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' });

        function updateGuestSummary() {
            const adults = Number(adultsInput.value);
            const children = Number(childrenInput.value);
            const totalGuests = adults + children;
            guestCount.textContent = `${totalGuests} guest${totalGuests === 1 ? '' : 's'}`;
            document.querySelector('#adults-count').textContent = adults;
            document.querySelector('#children-count').textContent = children;
            document.querySelectorAll('[data-guest="adults"][data-change="-1"]').forEach((button) => { button.disabled = adults <= 1; });
            document.querySelectorAll('[data-guest="adults"][data-change="1"]').forEach((button) => { button.disabled = totalGuests >= 30; });
            document.querySelectorAll('[data-guest="children"][data-change="-1"]').forEach((button) => { button.disabled = children <= 0; });
            document.querySelectorAll('[data-guest="children"][data-change="1"]').forEach((button) => { button.disabled = totalGuests >= 30; });
            mobileSearchSummary.textContent = `${mobileDateFormatter.format(new Date(`${checkInInput.value}T00:00:00`))} – ${mobileDateFormatter.format(new Date(`${checkOutInput.value}T00:00:00`))} · ${totalGuests} guest${totalGuests === 1 ? '' : 's'}`;
        }

        function closeGuestPopover(returnFocus = false) {
            guestPopover.hidden = true;
            guestTrigger.setAttribute('aria-expanded', 'false');
            if (returnFocus) guestTrigger.focus();
        }

        function closeMobileSearch(returnFocus = true) {
            searchForm.classList.remove('search--editing');
            searchForm.removeAttribute('role');
            searchForm.removeAttribute('aria-modal');
            searchForm.removeAttribute('aria-labelledby');
            document.body.classList.remove('search-sheet-open');
            closeGuestPopover();
            if (returnFocus) mobileSearchOpen.focus();
        }

        mobileSearchOpen.addEventListener('click', () => {
            searchForm.classList.add('search--editing');
            searchForm.setAttribute('role', 'dialog');
            searchForm.setAttribute('aria-modal', 'true');
            searchForm.setAttribute('aria-labelledby', 'search-sheet-title');
            document.body.classList.add('search-sheet-open');
            checkInInput.focus();
        });
        mobileSearchClose.addEventListener('click', () => closeMobileSearch());
        guestTrigger.addEventListener('click', () => {
            const isOpen = guestTrigger.getAttribute('aria-expanded') === 'true';
            guestPopover.hidden = isOpen;
            guestTrigger.setAttribute('aria-expanded', String(!isOpen));
            if (!isOpen) guestPopover.querySelector('button:not(:disabled)')?.focus();
        });
        guestPopover.addEventListener('click', (event) => {
            const stepButton = event.target.closest('button[data-guest]');
            if (!stepButton) return;
            const input = stepButton.dataset.guest === 'adults' ? adultsInput : childrenInput;
            const nextTotal = Number(adultsInput.value) + Number(childrenInput.value) + Number(stepButton.dataset.change);
            if (nextTotal < 1 || nextTotal > 30) return;
            input.value = String(Number(input.value) + Number(stepButton.dataset.change));
            updateGuestSummary();
        });
        document.addEventListener('click', (event) => {
            if (!event.target.closest('#guest-picker')) closeGuestPopover();
            if (searchForm.classList.contains('search--editing') && event.target === document.body) closeMobileSearch(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                if (!guestPopover.hidden) closeGuestPopover(true);
                else if (searchForm.classList.contains('search--editing')) closeMobileSearch();
            }
            if (event.key === 'Tab' && searchForm.classList.contains('search--editing')) {
                const focusable = [...searchForm.querySelectorAll('button:not(:disabled), input:not([type="hidden"]):not(:disabled)')].filter((item) => item.getClientRects().length > 0);
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        checkInInput.addEventListener('change', () => {
            const nextDate = new Date(`${checkInInput.value}T00:00:00`);
            nextDate.setDate(nextDate.getDate() + 1);
            checkOutInput.min = nextDate.toISOString().slice(0, 10);
            if (checkOutInput.value <= checkInInput.value) checkOutInput.value = checkOutInput.min;
            updateGuestSummary();
        });
        checkOutInput.addEventListener('change', updateGuestSummary);
        updateGuestSummary();
    </script>
</body>
</html>