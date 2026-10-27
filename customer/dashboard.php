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
$roomCount = 0;
$roomTypeCount = 0;
$lowestNightlyPrice = null;
$featuredRooms = [];
$announcements = [];
$databaseAvailable = true;
$announcementsAvailable = true;

try {
    $pdo = getDatabaseConnection();

    $roomStats = $pdo->query('SELECT COUNT(*) AS room_count, COUNT(DISTINCT room_type) AS type_count, MIN(price_per_night) AS lowest_price FROM rooms')->fetch();
    $roomCount = (int) ($roomStats['room_count'] ?? 0);
    $roomTypeCount = (int) ($roomStats['type_count'] ?? 0);
    $lowestNightlyPrice = $roomStats['lowest_price'] !== null ? (float) $roomStats['lowest_price'] : null;
    $featuredRooms = $pdo->query(
        'SELECT r.id, r.room_type, r.price_per_night, r.max_guests, r.bed_count, r.bed_type, r.amenities, p.file_path AS cover_photo FROM rooms r LEFT JOIN room_photos p ON p.room_id = r.id AND p.sort_order = (SELECT MIN(p2.sort_order) FROM room_photos p2 WHERE p2.room_id = r.id) ORDER BY r.price_per_night ASC, r.created_at DESC LIMIT 3'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
}

try {
    $announcementQuery = getDatabaseConnection()->query(
        'SELECT a.id, a.title, a.body, a.created_at, (SELECT i.file_path FROM announcement_images i WHERE i.announcement_id = a.id ORDER BY i.sort_order, i.id LIMIT 1) AS cover_image, (SELECT COUNT(*) FROM announcement_images i WHERE i.announcement_id = a.id) AS image_count FROM announcements a ORDER BY a.created_at DESC LIMIT 3'
    );
    $announcements = $announcementQuery->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $announcementsAvailable = false;
}

$displayName = htmlspecialchars((string) ($_SESSION['user_name'] ?? 'Guest'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>Guest Dashboard | The Queens Hotel</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .dashboard { width: min(100%, 1480px); margin: 0 auto; padding: 38px clamp(20px, 4vw, 58px) 64px; }
        .topline { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 35px; }
        .brandline { display: flex; align-items: center; gap: 9px; color: var(--muted); font-size: 11px; letter-spacing: .08em; }
        .brandmark { color: var(--gold); font-family: Georgia, "Times New Roman", serif; font-size: 19px; }
        .date { color: var(--muted); font-size: 11px; }
        .welcome { margin-bottom: 27px; }
        .eyebrow { margin: 0 0 10px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { margin-bottom: 9px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(34px, 4vw, 48px); font-weight: 400; line-height: 1.08; }
        .welcome-copy { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.6; }
        .button { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; gap: 9px; padding: 0 16px; border: 1px solid var(--green); background: var(--green); color: #fff; cursor: pointer; font: inherit; font-size: 11px; font-weight: 650; text-decoration: none; transition: background 150ms ease, transform 150ms ease; }
        .button:hover { transform: translateY(-1px); background: #102d29; }
        .button:focus-visible, a:focus-visible { outline: 3px solid rgba(23,59,53,.25); outline-offset: 3px; }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 13px; margin-bottom: 38px; }
        .stat { min-height: 112px; padding: 17px 19px; border: 1px solid var(--line); background: #fff; }
        .stat-label { color: var(--muted); font-size: 11px; }
        .stat-value { margin: 14px 0 4px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 28px; line-height: 1; }
        .stat-note { margin: 0; color: #909890; font-size: 10px; }
        .section-head { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 15px; }
        .section-head h2 { margin: 0; color: var(--ink); font-family: Georgia, "Times New Roman", serif; font-size: 27px; font-weight: 400; }
        .text-link { color: var(--green); font-size: 11px; font-weight: 700; text-underline-offset: 3px; }
        .announcement-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 38px; }
        .announcement-card { min-width: 0; overflow: hidden; border: 1px solid var(--line); background: var(--white); }
        .announcement-cover-link { display: block; overflow: hidden; }
        .announcement-cover { display: block; width: 100%; height: 150px; object-fit: cover; background: #e9eee8; transition: transform 180ms ease; }
        .announcement-cover-link:hover .announcement-cover { transform: scale(1.025); }
        .announcement-body { padding: 14px 15px 15px; }
        .announcement-date { margin: 0 0 6px; color: var(--muted); font-size: 9px; }
        .announcement-card h3 { margin: 0 0 7px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 19px; font-weight: 400; line-height: 1.25; }
        .announcement-copy { display: -webkit-box; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 3; line-clamp: 3; min-height: 45px; margin: 0 0 10px; color: #606a61; font-size: 10px; line-height: 1.5; white-space: pre-line; }
        .announcement-link { color: var(--green); font-size: 10px; font-weight: 700; text-underline-offset: 3px; }
        .room-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .room-card { min-width: 0; overflow: hidden; border: 1px solid var(--line); background: var(--white); }
        .room-image { display: block; width: 100%; height: 182px; object-fit: cover; background: linear-gradient(135deg, #e9eee8, #dce5dc); }
        .room-image-placeholder { display: grid; height: 182px; place-items: center; background: linear-gradient(135deg, #e9eee8, #dce5dc); color: #728074; font-size: 11px; }
        .room-body { padding: 15px 16px; }
        .room-card h3 { margin: 0 0 6px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 21px; font-weight: 400; }
        .room-meta { margin: 0 0 12px; color: var(--muted); font-size: 10px; line-height: 1.5; }
        .room-bottom { display: flex; align-items: end; justify-content: space-between; gap: 10px; padding-top: 11px; border-top: 1px solid #edf0eb; }
        .price strong { display: block; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 20px; font-weight: 400; }
        .price span { color: var(--muted); font-size: 9px; }
        .room-bottom .button { min-height: 35px; padding: 0 11px; font-size: 10px; }
        .notice { margin: 0 0 19px; padding: 12px 14px; border-left: 3px solid #ae8551; background: #f5f0e6; color: #684f30; font-size: 12px; line-height: 1.5; }
        .empty { padding: 30px 20px; border: 1px solid var(--line); background: #fff; text-align: center; }
        .empty h3 { margin-bottom: 7px; font-family: Georgia, "Times New Roman", serif; font-size: 23px; font-weight: 400; }
        .empty p { margin: 0; color: var(--muted); font-size: 11px; line-height: 1.55; }
        .footer { margin-top: 34px; padding-top: 15px; border-top: 1px solid var(--line); color: #8d958d; font-size: 10px; }
        @media (max-width: 920px) { .room-grid, .announcement-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) {
            .dashboard { padding: 26px 15px 38px; }
            .topline { margin-bottom: 29px; }
            .stats { gap: 9px; margin-bottom: 30px; }
            .stat { min-height: 102px; padding: 14px; }
            .stat-value { font-size: 24px; }
            .room-grid { grid-template-columns: 1fr; }
            .announcement-grid { grid-template-columns: 1fr; gap: 11px; margin-bottom: 30px; }
            .announcement-card { display: grid; grid-template-columns: 112px minmax(0, 1fr); }
            .announcement-cover-link, .announcement-cover { width: 112px; height: 100%; min-height: 142px; }
            .announcement-cover { object-fit: cover; }
            .announcement-body { padding: 12px; }
            .room-image, .room-image-placeholder { height: 200px; }
        }
        @media (max-width: 400px) { .stats { grid-template-columns: 1fr; } .stat { min-height: 84px; } .stat-value { margin-top: 10px; } }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } .button { transition: none; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="dashboard">
        <div class="topline">
            <div class="brandline"><span class="brandmark" aria-hidden="true">Q</span><span>THE QUEENS HOTEL · GUEST</span></div>
            <time class="date" datetime="<?= htmlspecialchars($today->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($today->format('l, F j'), ENT_QUOTES, 'UTF-8') ?></time>
        </div>

        <header class="welcome">
            <p class="eyebrow">Your stay starts here</p>
            <h1>Welcome back, <?= $displayName ?>.</h1>
            <p class="welcome-copy">Plan your next stay, explore the rooms, and check rates for your dates.</p>
        </header>

        <?php if (!$databaseAvailable): ?>
            <p class="notice" role="alert">We could not load the room catalog right now. Please try again later.</p>
        <?php endif; ?>

        <section class="stats" aria-label="Room catalog summary">
            <article class="stat"><span class="stat-label">Rooms listed</span><p class="stat-value"><?= $databaseAvailable ? number_format($roomCount) : '&mdash;' ?></p><p class="stat-note">Hotel room options in the catalog</p></article>
            <article class="stat"><span class="stat-label">Room styles</span><p class="stat-value"><?= $databaseAvailable ? number_format($roomTypeCount) : '&mdash;' ?></p><p class="stat-note">Different room categories</p></article>
            <article class="stat"><span class="stat-label">Rates from</span><p class="stat-value"><?= !$databaseAvailable || $lowestNightlyPrice === null ? '&mdash;' : '₱' . number_format($lowestNightlyPrice, 2) ?></p><p class="stat-note">Per room, per night before stay estimate</p></article>
        </section>

        <section aria-labelledby="announcements-heading">
            <div class="section-head"><div><p class="eyebrow">From the hotel</p><h2 id="announcements-heading">Latest announcements</h2></div><a class="text-link" href="announcements.php">All announcements <span aria-hidden="true">&#8594;</span></a></div>
            <?php if (!$announcementsAvailable): ?>
                <div class="empty"><h3>Announcements unavailable</h3><p>Hotel updates could not be loaded right now.</p></div>
            <?php elseif ($announcements === []): ?>
                <div class="empty"><h3>No announcements yet</h3><p>Hotel updates will appear here.</p></div>
            <?php else: ?>
                <div class="announcement-grid">
                    <?php foreach ($announcements as $announcement): ?>
                        <article class="announcement-card">
                            <?php if (is_string($announcement['cover_image'])): ?>
                                <a class="announcement-cover-link" href="announcement-gallery.php?id=<?= (int) $announcement['id'] ?>" aria-label="View images for <?= htmlspecialchars((string) $announcement['title'], ENT_QUOTES, 'UTF-8') ?>">
                                    <img class="announcement-cover" src="../<?= htmlspecialchars($announcement['cover_image'], ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                                </a>
                            <?php endif; ?>
                            <div class="announcement-body">
                                <p class="announcement-date"><?= htmlspecialchars(date('M j, Y', strtotime((string) $announcement['created_at'])), ENT_QUOTES, 'UTF-8') ?><?php if ((int) $announcement['image_count'] > 1): ?> · <?= (int) $announcement['image_count'] ?> images<?php endif; ?></p>
                                <h3><?= htmlspecialchars((string) $announcement['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="announcement-copy"><?= htmlspecialchars((string) $announcement['body'], ENT_QUOTES, 'UTF-8') ?></p>
                                <a class="announcement-link" href="announcement-gallery.php?id=<?= (int) $announcement['id'] ?>">Read announcement <span aria-hidden="true">&#8594;</span></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section aria-labelledby="featured-heading">
            <div class="section-head"><div><p class="eyebrow">A place to unwind</p><h2 id="featured-heading">Explore our rooms</h2></div><a class="text-link" href="rooms.php">View all rooms <span aria-hidden="true">&#8594;</span></a></div>
            <?php if (!$databaseAvailable): ?>
                <div class="empty"><h3>Room information unavailable</h3><p>Please try loading the catalog again later.</p></div>
            <?php elseif ($featuredRooms === []): ?>
                <div class="empty"><h3>Rooms are being prepared</h3><p>There are no room listings yet. Check back soon.</p></div>
            <?php else: ?>
                <div class="room-grid">
                    <?php foreach ($featuredRooms as $room): ?>
                        <?php
                        $roomAmenities = json_decode((string) $room['amenities'], true);
                        $roomAmenities = is_array($roomAmenities) ? $roomAmenities : [];
                        ?>
                        <article class="room-card">
                            <?php if (is_string($room['cover_photo'])): ?>
                                <img class="room-image" src="../<?= htmlspecialchars($room['cover_photo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?> room" loading="lazy">
                            <?php else: ?>
                                <div class="room-image-placeholder">Room photos coming soon</div>
                            <?php endif; ?>
                            <div class="room-body">
                                <p class="eyebrow">The Queens Hotel</p>
                                <h3><?= htmlspecialchars($room['room_type'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="room-meta"><?= (int) $room['max_guests'] ?> guests max · <?= (int) $room['bed_count'] ?> <?= (int) $room['bed_count'] === 1 ? 'bed' : 'beds' ?> · <?= htmlspecialchars($room['bed_type'], ENT_QUOTES, 'UTF-8') ?><?php if ($roomAmenities !== []): ?> · <?= htmlspecialchars((string) $roomAmenities[0], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
                                <div class="room-bottom">
                                    <div class="price"><strong>₱<?= number_format((float) $room['price_per_night'], 2) ?></strong><span>per night</span></div>
                                    <a class="button" href="rooms.php?check_in=<?= rawurlencode($today->format('Y-m-d')) ?>&amp;check_out=<?= rawurlencode($tomorrow->format('Y-m-d')) ?>#room-<?= (int) $room['id'] ?>">View details</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="footer" aria-label="Booking information">Booking activity will appear here when reservations are enabled. For now, browse rooms and check availability for your dates.</section>
    </main>
</body>
</html>