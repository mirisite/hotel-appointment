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

$announcements = [];
$databaseAvailable = true;

try {
    $pdo = getDatabaseConnection();
    $statement = $pdo->query(
        'SELECT a.id, a.title, a.body, a.created_at, i.file_path FROM (SELECT id, title, body, created_at FROM announcements ORDER BY created_at DESC LIMIT 50) a LEFT JOIN announcement_images i ON i.announcement_id = a.id ORDER BY a.created_at DESC, i.sort_order ASC, i.id ASC'
    );
    foreach ($statement->fetchAll() as $row) {
        $announcementId = (int) $row['id'];
        if (!isset($announcements[$announcementId])) {
            $announcements[$announcementId] = [
                'id' => $announcementId,
                'title' => (string) $row['title'],
                'body' => (string) $row['body'],
                'created_at' => (string) $row['created_at'],
                'images' => [],
            ];
        }
        if (is_string($row['file_path'])) {
            $announcements[$announcementId]['images'][] = $row['file_path'];
        }
    }
    $announcements = array_values($announcements);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>Hotel Announcements | The Queens Hotel</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1120px); margin: auto; padding: 42px clamp(20px, 4vw, 58px) 72px; }
        .intro { margin-bottom: 26px; }
        .eyebrow { margin: 0 0 9px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { margin-bottom: 8px; color: var(--ink); font-family: Georgia, "Times New Roman", serif; font-size: clamp(34px, 4vw, 46px); font-weight: 400; line-height: 1.1; }
        .intro-copy { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.55; }
        .announcement-list { display: grid; gap: 15px; }
        .announcement { overflow: hidden; border: 1px solid var(--line); background: var(--white); }
        .announcement-cover { display: block; width: 100%; max-height: 440px; aspect-ratio: 2.1 / 1; object-fit: cover; background: #e8eee8; }
        .announcement-cover-link { display: block; width: 100%; overflow: hidden; cursor: zoom-in; }
        .announcement-cover-link:focus-visible { outline: 3px solid var(--green); outline-offset: -3px; }
        .announcement-content { padding: 19px 20px 20px; }
        .announcement-date { margin: 0 0 7px; color: var(--muted); font-size: 10px; }
        .announcement h2 { margin: 0 0 10px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 25px; font-weight: 400; line-height: 1.2; }
        .announcement-body { margin: 0; color: #465148; font-size: 12px; line-height: 1.7; white-space: pre-line; overflow-wrap: anywhere; }
        .gallery-trigger { display: inline-flex; min-height: 38px; align-items: center; gap: 8px; margin-top: 15px; padding: 0 12px; border: 1px solid #dce3db; background: #f5f7f3; color: var(--green); font: inherit; font-size: 10px; font-weight: 700; text-decoration: none; }
        .gallery-trigger:hover { background: #e9efe8; }
        .gallery-trigger svg { width: 16px; height: 16px; }
        .empty { padding: 42px 20px; border: 1px solid var(--line); background: #fff; text-align: center; }
        .empty h2 { margin: 0 0 8px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 24px; font-weight: 400; }
        .empty p { margin: 0; color: var(--muted); font-size: 11px; line-height: 1.55; }
        .message { padding: 15px; border-left: 3px solid var(--gold); background: #f5f0e6; color: #684f30; font-size: 12px; line-height: 1.55; }
        .button { display: inline-flex; min-height: 38px; align-items: center; justify-content: center; padding: 0 13px; border: 1px solid var(--green); background: var(--green); color: #fff; font-size: 10px; font-weight: 650; text-decoration: none; }
        @media (max-width: 640px) { .page { padding: 28px 14px 42px; } .announcement-cover { aspect-ratio: 1.5 / 1; } .announcement-content { padding: 15px; } .announcement h2 { font-size: 22px; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <header class="intro"><p class="eyebrow">The Queens Hotel · Guest updates</p><h1>Announcements</h1><p class="intro-copy">The latest news and updates from the hotel.</p></header>

        <?php if (!$databaseAvailable): ?>
            <p class="message" role="alert">Announcements are unavailable right now. Please try again later.</p>
        <?php elseif ($announcements === []): ?>
            <section class="empty"><h2>No announcements yet</h2><p>Hotel updates will appear here.</p><a class="button" href="dashboard.php">Back to dashboard</a></section>
        <?php else: ?>
            <div class="announcement-list">
                <?php foreach ($announcements as $announcement): ?>
                    <article class="announcement">
                        <a class="announcement-cover-link" href="announcement-gallery.php?id=<?= (int) $announcement['id'] ?>" aria-label="View images for <?= $escape((string) $announcement['title']) ?>">
                            <img class="announcement-cover" src="../<?= $escape((string) $announcement['images'][0]) ?>" alt="<?= $escape((string) $announcement['title']) ?>" loading="lazy">
                        </a>
                        <div class="announcement-content">
                            <p class="announcement-date"><?= $escape(date('F j, Y', strtotime((string) $announcement['created_at']))) ?></p>
                            <h2><?= $escape((string) $announcement['title']) ?></h2>
                            <p class="announcement-body"><?= $escape((string) $announcement['body']) ?></p>
                            <?php if (count($announcement['images']) > 1): ?>
                                <a class="gallery-trigger" href="announcement-gallery.php?id=<?= (int) $announcement['id'] ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                    View all <?= count($announcement['images']) ?> images
                                </a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>