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

$announcementIdRaw = $_GET['id'] ?? '';
$announcementId = is_string($announcementIdRaw) ? filter_var($announcementIdRaw, FILTER_VALIDATE_INT) : false;
if ($announcementId === false || $announcementId < 1) {
    http_response_code(404);
    exit('Announcement not found.');
}

$announcement = null;
$images = [];
$databaseAvailable = true;

try {
    $pdo = getDatabaseConnection();
    $announcementQuery = $pdo->prepare('SELECT id, title, body, created_at FROM announcements WHERE id = :announcement_id LIMIT 1');
    $announcementQuery->execute(['announcement_id' => $announcementId]);
    $announcement = $announcementQuery->fetch() ?: null;
    if ($announcement) {
        $imageQuery = $pdo->prepare('SELECT file_path FROM announcement_images WHERE announcement_id = :announcement_id ORDER BY sort_order, id');
        $imageQuery->execute(['announcement_id' => $announcementId]);
        $images = $imageQuery->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
}

if ($databaseAvailable && (!$announcement || $images === [])) {
    http_response_code(404);
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title><?= $announcement ? $escape((string) $announcement['title']) . ' | The Queens Hotel' : 'Announcement images | The Queens Hotel' ?></title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1180px); margin: auto; padding: 38px clamp(18px, 4vw, 56px) 68px; }
        .back { display: inline-flex; min-height: 38px; align-items: center; gap: 8px; margin-bottom: 23px; color: var(--green); font-size: 11px; font-weight: 700; text-decoration: none; }
        .back:hover { text-decoration: underline; text-underline-offset: 3px; }
        .eyebrow { margin: 0 0 8px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, p { margin-top: 0; }
        h1 { margin-bottom: 12px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: clamp(30px, 4vw, 42px); font-weight: 400; line-height: 1.15; }
        .body { margin-bottom: 25px; color: #465148; font-size: 13px; line-height: 1.7; white-space: pre-line; overflow-wrap: anywhere; }
        .gallery { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .gallery img { display: block; width: 100%; max-height: 72vh; aspect-ratio: 4 / 3; object-fit: contain; background: #e9ede7; }
        .empty { padding: 32px 20px; border: 1px solid var(--line); background: #fff; color: var(--muted); font-size: 12px; line-height: 1.5; }
        @media (max-width: 640px) { .page { padding: 28px 14px 42px; } .gallery { grid-template-columns: 1fr; } .gallery img { max-height: 60vh; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <a class="back" href="announcements.php"><span aria-hidden="true">&#8592;</span> All announcements</a>
        <?php if (!$databaseAvailable): ?>
            <p class="empty" role="alert">Announcement images are unavailable right now. Please try again later.</p>
        <?php elseif (!$announcement || $images === []): ?>
            <p class="empty" role="alert">This announcement or its images could not be found.</p>
        <?php else: ?>
            <header>
                <p class="eyebrow"><?= $escape(date('F j, Y', strtotime((string) $announcement['created_at']))) ?> · <?= count($images) ?> image<?= count($images) === 1 ? '' : 's' ?></p>
                <h1><?= $escape((string) $announcement['title']) ?></h1>
                <p class="body"><?= $escape((string) $announcement['body']) ?></p>
            </header>
            <div class="gallery" aria-label="Announcement images">
                <?php foreach ($images as $imageIndex => $imagePath): ?>
                    <img src="../<?= $escape((string) $imagePath) ?>" alt="<?= $escape((string) $announcement['title']) ?> image <?= $imageIndex + 1 ?>">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>