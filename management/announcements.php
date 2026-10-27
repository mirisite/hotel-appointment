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
$title = '';
$body = '';
$message = '';
$messageType = 'error';
$databaseAvailable = true;
$announcements = [];
$galleryData = [];
$pdo = null;
$editIdInput = $_GET['edit'] ?? '';
$editId = is_string($editIdInput) ? filter_var($editIdInput, FILTER_VALIDATE_INT) : false;
$editAnnouncementId = $editId !== false && $editId > 0 ? $editId : 0;
$editingAnnouncement = null;
$editingImageCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $deleteIdInput = $_POST['announcement_id'] ?? '';
    $deleteId = is_string($deleteIdInput) ? filter_var($deleteIdInput, FILTER_VALIDATE_INT) : false;

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Refresh the page and try again.';
    } elseif ($deleteId === false || $deleteId < 1) {
        $message = 'Choose a valid announcement to delete.';
    } else {
        try {
            $pdo = getDatabaseConnection();
            $imageQuery = $pdo->prepare('SELECT file_path FROM announcement_images WHERE announcement_id = :announcement_id');
            $imageQuery->execute(['announcement_id' => $deleteId]);
            $imagePaths = $imageQuery->fetchAll(PDO::FETCH_COLUMN);
            $pdo->beginTransaction();
            $deleteAnnouncement = $pdo->prepare('DELETE FROM announcements WHERE id = :announcement_id');
            $deleteAnnouncement->execute(['announcement_id' => $deleteId]);
            if ($deleteAnnouncement->rowCount() !== 1) {
                throw new RuntimeException('That announcement could not be found.');
            }
            $pdo->commit();

            foreach ($imagePaths as $imagePath) {
                $imagePath = (string) $imagePath;
                $uploadPrefix = 'uploads/announcements/';
                if (str_starts_with($imagePath, $uploadPrefix) && basename($imagePath) === substr($imagePath, strlen($uploadPrefix))) {
                    $savedFile = __DIR__ . '/../' . $imagePath;
                    if (is_file($savedFile)) {
                        unlink($savedFile);
                    }
                }
            }
            $message = 'Announcement and its images deleted.';
            $messageType = 'success';
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Announcement deletion failed: ' . $exception->getMessage());
            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The announcement could not be deleted. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? 'create';
    $editIdInput = $_POST['announcement_id'] ?? '';
    $editId = is_string($editIdInput) ? filter_var($editIdInput, FILTER_VALIDATE_INT) : false;
    $isUpdating = $action === 'update';
    $editAnnouncementId = $isUpdating && $editId !== false && $editId > 0 ? $editId : 0;
    $titleInput = $_POST['title'] ?? '';
    $bodyInput = $_POST['body'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Refresh the page and try again.';
    } elseif (!in_array($action, ['create', 'update'], true) || ($isUpdating && $editAnnouncementId < 1)) {
        $message = 'Choose a valid announcement action.';
    } elseif (!is_string($titleInput) || !is_string($bodyInput)) {
        $message = 'Enter a title and announcement message.';
    } else {
        $title = trim($titleInput);
        $body = trim($bodyInput);
        $fileNames = $_FILES['images']['name'] ?? [];
        $fileErrors = $_FILES['images']['error'] ?? [];
        $fileTemps = $_FILES['images']['tmp_name'] ?? [];
        $fileSizes = $_FILES['images']['size'] ?? [];
        $uploads = [];

        if (!preg_match('/^.{1,160}$/us', $title)) {
            $message = 'Enter a title using up to 160 characters.';
        } elseif ($body === '' || strlen($body) > 10000) {
            $message = 'Enter an announcement message no longer than 10,000 characters.';
        } elseif (!is_array($fileNames) || !is_array($fileErrors) || !is_array($fileTemps) || !is_array($fileSizes)) {
            $message = 'Choose at least one image to publish with the announcement.';
        } else {
            foreach ($fileNames as $index => $originalName) {
                $uploadError = $fileErrors[$index] ?? UPLOAD_ERR_NO_FILE;
                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($uploadError !== UPLOAD_ERR_OK || !isset($fileTemps[$index], $fileSizes[$index])) {
                    $message = 'One of the selected images could not be uploaded.';
                    break;
                }
                if (!is_string($fileTemps[$index]) || !is_string($originalName) || (int) $fileSizes[$index] > 5 * 1024 * 1024) {
                    $message = 'Each image must be no larger than 5 MB.';
                    break;
                }
                $uploads[] = ['tmp_name' => $fileTemps[$index]];
            }

            if ($message === '' && $isUpdating && $uploads !== []) {
                $message = 'Existing images are retained when editing an announcement.';
            } elseif ($message === '' && !$isUpdating && $uploads === []) {
                $message = 'Choose at least one image to publish with the announcement.';
            } elseif ($message === '' && !$isUpdating && count($uploads) > 8) {
                $message = 'Upload no more than 8 images per announcement.';
            }
        }

        if ($message === '') {
            $savedFiles = [];
            try {
                $pdo = getDatabaseConnection();
                $uploadDirectory = __DIR__ . '/../uploads/announcements';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('The announcement image directory could not be created.');
                }

                $mimeExtensions = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                $pdo->beginTransaction();
                if ($isUpdating) {
                    $announcementUpdate = $pdo->prepare('UPDATE announcements SET title = :title, body = :body WHERE id = :announcement_id');
                    $announcementUpdate->execute([
                        'title' => $title,
                        'body' => $body,
                        'announcement_id' => $editAnnouncementId,
                    ]);
                    $announcementExists = $pdo->prepare('SELECT COUNT(*) FROM announcements WHERE id = :announcement_id');
                    $announcementExists->execute(['announcement_id' => $editAnnouncementId]);
                    if ((int) $announcementExists->fetchColumn() !== 1) {
                        throw new RuntimeException('That announcement could not be found.');
                    }
                    $announcementId = $editAnnouncementId;
                } else {
                    $announcementInsert = $pdo->prepare(
                        'INSERT INTO announcements (title, body, created_by) VALUES (:title, :body, :created_by)'
                    );
                    $announcementInsert->execute([
                        'title' => $title,
                        'body' => $body,
                        'created_by' => (int) $_SESSION['user_id'],
                    ]);
                    $announcementId = (int) $pdo->lastInsertId();
                }
                $imageInsert = $pdo->prepare(
                    'INSERT INTO announcement_images (announcement_id, file_path, sort_order) VALUES (:announcement_id, :file_path, :sort_order)'
                );

                foreach ($uploads as $index => $upload) {
                    $mimeType = $fileInfo->file($upload['tmp_name']);
                    $imageDetails = getimagesize($upload['tmp_name']);
                    if (!is_string($mimeType) || !isset($mimeExtensions[$mimeType]) || $imageDetails === false) {
                        throw new RuntimeException('Images must be valid JPEG, PNG, or WebP files.');
                    }

                    $fileName = bin2hex(random_bytes(20)) . '.' . $mimeExtensions[$mimeType];
                    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
                    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                        throw new RuntimeException('An image could not be saved. Please try again.');
                    }
                    $savedFiles[] = $destination;
                    $imageInsert->execute([
                        'announcement_id' => $announcementId,
                        'file_path' => 'uploads/announcements/' . $fileName,
                        'sort_order' => $index,
                    ]);
                }

                $pdo->commit();
                $message = $isUpdating
                    ? 'Announcement updated.'
                    : 'Announcement published with ' . count($uploads) . ' image' . (count($uploads) === 1 ? '' : 's') . '.';
                $messageType = 'success';
                $title = '';
                $body = '';
                $editAnnouncementId = 0;
            } catch (Throwable $exception) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                foreach ($savedFiles as $savedFile) {
                    if (is_file($savedFile)) {
                        unlink($savedFile);
                    }
                }
                error_log('Announcement upload failed: ' . $exception->getMessage());
                $message = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The announcement could not be published. Apply the latest database migration and try again.';
            }
        }
    }
}

try {
    $pdo = getDatabaseConnection();
    $announcements = $pdo->query(
        'SELECT id, title, body, created_at FROM announcements ORDER BY created_at DESC LIMIT 50'
    )->fetchAll();
    if ($announcements !== []) {
        $announcementIds = array_map(static fn (array $announcement): int => (int) $announcement['id'], $announcements);
        $placeholders = implode(',', array_fill(0, count($announcementIds), '?'));
        $imageQuery = $pdo->prepare(
            'SELECT announcement_id, file_path FROM announcement_images WHERE announcement_id IN (' . $placeholders . ') ORDER BY announcement_id, sort_order, id'
        );
        $imageQuery->execute($announcementIds);
        $imagesByAnnouncement = [];
        foreach ($imageQuery->fetchAll() as $imageRow) {
            $imagesByAnnouncement[(int) $imageRow['announcement_id']][] = (string) $imageRow['file_path'];
        }
        foreach ($announcements as &$announcement) {
            $announcementId = (int) $announcement['id'];
            $announcement['images'] = $imagesByAnnouncement[$announcementId] ?? [];
            $announcement['image_count'] = count($announcement['images']);
            $announcement['cover_image'] = $announcement['images'][0] ?? '';
            $galleryData[(string) $announcementId] = [
                'title' => (string) $announcement['title'],
                'images' => $announcement['images'],
            ];
        }
        unset($announcement);
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $databaseAvailable = false;
    if ($message === '') {
        $message = 'Announcements are unavailable. Apply the latest database migration and try again.';
        $messageType = 'error';
    }
}

if ($editAnnouncementId > 0 && $databaseAvailable) {
    try {
        $editQuery = $pdo->prepare('SELECT id, title, body FROM announcements WHERE id = :announcement_id LIMIT 1');
        $editQuery->execute(['announcement_id' => $editAnnouncementId]);
        $editingAnnouncement = $editQuery->fetch();
        if ($editingAnnouncement) {
            $imageCountQuery = $pdo->prepare('SELECT COUNT(*) FROM announcement_images WHERE announcement_id = :announcement_id');
            $imageCountQuery->execute(['announcement_id' => $editAnnouncementId]);
            $editingImageCount = (int) $imageCountQuery->fetchColumn();
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $title = (string) $editingAnnouncement['title'];
                $body = (string) $editingAnnouncement['body'];
            }
        } else {
            $editAnnouncementId = 0;
            $message = 'That announcement could not be found.';
            $messageType = 'error';
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $message = 'The selected announcement could not be loaded.';
        $messageType = 'error';
    }
}

$displayName = htmlspecialchars((string) ($_SESSION['user_name'] ?? 'Manager'), ENT_QUOTES, 'UTF-8');
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f7f3">
    <title>Announcements | The Queens Hotel Management</title>
    <style>
        :root { color-scheme: light; --green: #173b35; --paper: #f6f7f3; --ink: #202923; --muted: #747d75; --line: #e2e6df; --gold: #ae8551; --white: #fff; }
        * { box-sizing: border-box; }
        body { min-width: 320px; min-height: 100vh; margin: 0; background: var(--paper); color: var(--ink); font-family: "Aptos", "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        .page { width: min(100%, 1340px); margin: auto; padding: 38px clamp(20px, 4vw, 58px) 68px; }
        .intro { display: flex; align-items: end; justify-content: space-between; gap: 18px; margin-bottom: 25px; }
        .eyebrow { margin: 0 0 9px; color: var(--gold); font-size: 10px; font-weight: 750; letter-spacing: .17em; text-transform: uppercase; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { margin-bottom: 8px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(34px, 4vw, 46px); font-weight: 400; line-height: 1.1; }
        .intro-copy { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.55; }
        .manager-name { color: var(--muted); font-size: 11px; white-space: nowrap; }
        .layout { display: grid; grid-template-columns: minmax(310px, .78fr) minmax(0, 1.22fr); align-items: start; gap: 17px; }
        .panel { padding: 20px; border: 1px solid var(--line); background: var(--white); }
        .panel-head { margin-bottom: 17px; padding-bottom: 13px; border-bottom: 1px solid #edf0eb; }
        .panel-head h2 { margin: 0 0 5px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 22px; font-weight: 400; }
        .panel-head p { margin: 0; color: var(--muted); font-size: 10px; line-height: 1.5; }
        .field { margin-bottom: 14px; }
        .field label { display: block; margin-bottom: 6px; color: #455047; font-size: 10px; font-weight: 700; }
        .control { display: block; width: 100%; min-height: 42px; padding: 9px 10px; border: 1px solid #dce1d9; border-radius: 2px; outline: none; background: #fff; color: var(--ink); font: inherit; font-size: 12px; }
        textarea.control { min-height: 150px; resize: vertical; line-height: 1.55; }
        .control:focus { border-color: var(--green); box-shadow: 0 0 0 3px rgba(23,59,53,.09); }
        input[type="file"].control { padding: 8px; }
        .hint { margin: 6px 0 0; color: #858d85; font-size: 9px; line-height: 1.5; }
        .button { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; gap: 8px; padding: 0 15px; border: 1px solid var(--green); border-radius: 2px; background: var(--green); color: white; cursor: pointer; font: inherit; font-size: 11px; font-weight: 650; }
        .button:hover { background: #102d29; }
        .message { margin-bottom: 16px; padding: 12px 14px; border-left: 3px solid #557c58; background: #edf4ed; color: #345c3a; font-size: 12px; line-height: 1.5; }
        .message--error { border-color: #a14f43; background: #f8ebe8; color: #79332d; }
        .announcement-list { display: grid; gap: 11px; }
        .announcement-item { display: grid; grid-template-columns: 116px minmax(0, 1fr); gap: 13px; padding-bottom: 12px; border-bottom: 1px solid #edf0eb; }
        .announcement-item:last-child { padding-bottom: 0; border-bottom: 0; }
        .announcement-image-trigger { display: block; width: 116px; height: 88px; padding: 0; overflow: hidden; border: 0; background: transparent; cursor: zoom-in; }
        .announcement-image { width: 116px; height: 88px; object-fit: cover; background: #e8eee8; }
        .announcement-image-trigger:focus-visible { outline: 3px solid var(--green); outline-offset: 2px; }
        .announcement-item h3 { margin: 0 0 5px; color: var(--green); font-family: Georgia, "Times New Roman", serif; font-size: 17px; font-weight: 400; }
        .announcement-date, .announcement-count { margin: 0 0 5px; color: var(--muted); font-size: 9px; }
        .announcement-preview { display: -webkit-box; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 3; line-clamp: 3; margin: 0; color: #465148; font-size: 10px; line-height: 1.5; white-space: pre-line; }
        .announcement-actions { display: flex; align-items: center; gap: 7px; margin-top: 8px; }
        .text-action, .delete-action { display: inline-flex; min-height: 30px; align-items: center; justify-content: center; padding: 0 9px; border: 1px solid #dce3db; background: #f5f7f3; color: var(--green); cursor: pointer; font: inherit; font-size: 9px; font-weight: 700; text-decoration: none; }
        .text-action:hover { background: #e9efe8; }
        .delete-action { border-color: #ead9d5; background: #fff8f6; color: #8b443b; }
        .delete-action:hover { background: #f8ebe8; }
        .announcement-gallery { width: min(940px, calc(100vw - 28px)); max-width: none; max-height: min(850px, calc(100dvh - 28px)); padding: 0; overflow: hidden; border: 1px solid var(--line); background: #fbfaf7; color: var(--ink); }
        .announcement-gallery::backdrop { background: rgba(14, 26, 21, .72); }
        .announcement-gallery__inner { display: grid; max-height: min(850px, calc(100dvh - 28px)); grid-template-rows: auto minmax(0, 1fr) auto; gap: 12px; padding: 17px; }
        .announcement-gallery__heading { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
        .announcement-gallery__heading h2 { margin: 0; color: var(--green); font: 400 22px Georgia, "Times New Roman", serif; }
        .announcement-gallery__close { display: grid; width: 38px; height: 38px; flex: 0 0 auto; place-items: center; border: 1px solid var(--line); background: #fff; color: var(--ink); cursor: pointer; }
        .announcement-gallery__close svg { width: 19px; height: 19px; }
        .announcement-gallery__stage { display: grid; min-height: 0; grid-template-columns: 42px minmax(0, 1fr) 42px; align-items: center; justify-items: center; gap: 10px; }
        .announcement-gallery__stage--single { grid-template-columns: minmax(0, 1fr); }
        .announcement-gallery__image { display: block; width: 100%; max-height: min(68vh, 650px); object-fit: contain; }
        .announcement-gallery__arrow { display: grid; width: 40px; height: 40px; place-items: center; border: 1px solid #dce3db; background: #fff; color: var(--green); cursor: pointer; }
        .announcement-gallery__arrow[hidden] { display: none; }
        .announcement-gallery__arrow:hover { background: #e9efe8; }
        .announcement-gallery__arrow svg { width: 20px; height: 20px; }
        .announcement-gallery__count { margin: 0; color: var(--muted); font-size: 10px; text-align: center; }
        .announcement-gallery__arrow:focus-visible, .announcement-gallery__close:focus-visible { outline: 3px solid rgba(23,59,53,.3); outline-offset: 2px; }
        .empty { padding: 27px 16px; border: 1px dashed var(--line); color: var(--muted); font-size: 11px; line-height: 1.5; text-align: center; }
        .notice { margin: 0 0 16px; padding: 12px 14px; border-left: 3px solid var(--gold); background: #f5f0e6; color: #684f30; font-size: 11px; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
        @media (max-width: 640px) { .page { padding: 27px 14px 42px; } .intro { display: block; } .manager-name { display: inline-block; margin-top: 12px; } .panel { padding: 16px; } .announcement-item { grid-template-columns: 90px minmax(0, 1fr); gap: 10px; } .announcement-image-trigger, .announcement-image { width: 90px; height: 78px; } .announcement-gallery { width: calc(100vw - 16px); max-height: calc(100dvh - 16px); } .announcement-gallery__inner { max-height: calc(100dvh - 16px); padding: 11px; } .announcement-gallery__heading h2 { font-size: 19px; } .announcement-gallery__stage { grid-template-columns: 34px minmax(0, 1fr) 34px; gap: 5px; } .announcement-gallery__arrow { width: 34px; height: 38px; } .announcement-gallery__image { max-height: 68vh; } }
    </style>
</head>
<body>
    <?php require __DIR__ . '/navbar.php'; ?>
    <main class="page">
        <header class="intro">
            <div><p class="eyebrow">The Queens Hotel · Management</p><h1>Announcements</h1><p class="intro-copy">Share hotel news and updates with customers.</p></div>
            <span class="manager-name">Signed in as <?= $displayName ?></span>
        </header>

        <?php if ($message !== ''): ?>
            <p class="message<?= $messageType === 'error' ? ' message--error' : '' ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>"><?= $escape($message) ?></p>
        <?php endif; ?>
        <?php if (!$databaseAvailable): ?>
            <p class="notice" role="alert">The announcements database tables are required. Run the latest database_migration.sql.</p>
        <?php else: ?>
            <div class="layout">
                <section class="panel" aria-labelledby="publish-heading">
                    <div class="panel-head"><h2 id="publish-heading"><?= $editingAnnouncement ? 'Edit announcement' : 'Publish an announcement' ?></h2><p><?= $editingAnnouncement ? 'Update its title or message. Existing images will stay attached.' : 'Announcements are visible to signed-in customers as soon as they are published.' ?></p></div>
                    <form method="post" action="announcements.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="<?= $editingAnnouncement ? 'update' : 'create' ?>">
                        <?php if ($editingAnnouncement): ?>
                            <input type="hidden" name="announcement_id" value="<?= (int) $editAnnouncementId ?>">
                        <?php endif; ?>
                        <div class="field"><label for="announcement-title">Title</label><input class="control" id="announcement-title" name="title" type="text" maxlength="160" value="<?= $escape($title) ?>" required></div>
                        <div class="field"><label for="announcement-body">Message</label><textarea class="control" id="announcement-body" name="body" maxlength="10000" required><?= $escape($body) ?></textarea></div>
                        <?php if ($editingAnnouncement): ?>
                            <p class="hint">This announcement has <?= $editingImageCount ?> image<?= $editingImageCount === 1 ? '' : 's' ?>. Its image gallery will be retained.</p>
                        <?php else: ?>
                            <div class="field"><label for="announcement-images">Images</label><input class="control" id="announcement-images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple required><p class="hint">Select 1–8 JPEG, PNG, or WebP images. Maximum 5 MB each.</p></div>
                        <?php endif; ?>
                        <button class="button" type="submit"><?= $editingAnnouncement ? 'Save changes' : 'Publish announcement' ?> <span aria-hidden="true">&#8594;</span></button>
                        <?php if ($editingAnnouncement): ?> <a class="text-action" href="announcements.php">Cancel</a><?php endif; ?>
                    </form>
                </section>

                <section class="panel" aria-labelledby="recent-heading">
                    <div class="panel-head"><h2 id="recent-heading">Recent announcements</h2><p>Latest 50 posts, newest first.</p></div>
                    <?php if ($announcements === []): ?>
                        <div class="empty">No announcements have been published yet.</div>
                    <?php else: ?>
                        <div class="announcement-list">
                            <?php foreach ($announcements as $announcement): ?>
                                <article class="announcement-item">
                                    <?php if ($announcement['images'] !== []): ?>
                                    <button class="announcement-image-trigger" type="button" data-gallery-open="<?= (int) $announcement['id'] ?>" aria-label="View announcement images">
                                        <img class="announcement-image" src="../<?= $escape((string) $announcement['cover_image']) ?>" alt="" loading="lazy">
                                    </button>
                                    <?php else: ?>
                                        <div class="announcement-image"></div>
                                    <?php endif; ?>
                                    <div>
                                        <h3><?= $escape((string) $announcement['title']) ?></h3>
                                        <p class="announcement-date"><?= $escape(date('M j, Y g:i A', strtotime((string) $announcement['created_at']))) ?></p>
                                        <p class="announcement-count"><?= (int) $announcement['image_count'] ?> image<?= (int) $announcement['image_count'] === 1 ? '' : 's' ?></p>
                                        <p class="announcement-preview"><?= $escape((string) $announcement['body']) ?></p>
                                        <div class="announcement-actions">
                                            <a class="text-action" href="announcements.php?edit=<?= (int) $announcement['id'] ?>">Edit</a>
                                            <form method="post" action="announcements.php" onsubmit="return confirm('Delete this announcement and its images?')">
                                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="announcement_id" value="<?= (int) $announcement['id'] ?>">
                                                <button class="delete-action" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </main>
    <dialog class="announcement-gallery" id="announcement-gallery" aria-labelledby="announcement-gallery-title">
        <div class="announcement-gallery__inner">
            <div class="announcement-gallery__heading">
                <h2 id="announcement-gallery-title"></h2>
                <button class="announcement-gallery__close" id="announcement-gallery-close" type="button" aria-label="Close image gallery"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
            </div>
            <div class="announcement-gallery__stage">
                <button class="announcement-gallery__arrow" id="announcement-gallery-previous" type="button" aria-label="Previous image"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
                <img class="announcement-gallery__image" id="announcement-gallery-image" src="" alt="">
                <button class="announcement-gallery__arrow" id="announcement-gallery-next" type="button" aria-label="Next image"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
            </div>
            <p class="announcement-gallery__count" id="announcement-gallery-count" aria-live="polite"></p>
        </div>
    </dialog>
    <script>
        const announcementGalleries = <?= json_encode($galleryData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
        const galleryDialog = document.querySelector('#announcement-gallery');
        const galleryImage = document.querySelector('#announcement-gallery-image');
        const galleryTitle = document.querySelector('#announcement-gallery-title');
        const galleryCount = document.querySelector('#announcement-gallery-count');
        let activeGallery = null;
        let activeGalleryIndex = 0;

        function showGalleryImage(index) {
            if (!activeGallery || activeGallery.images.length === 0) return;
            activeGalleryIndex = (index + activeGallery.images.length) % activeGallery.images.length;
            galleryImage.src = `../${activeGallery.images[activeGalleryIndex]}`;
            galleryImage.alt = `${activeGallery.title} image ${activeGalleryIndex + 1} of ${activeGallery.images.length}`;
            galleryCount.textContent = `${activeGalleryIndex + 1} / ${activeGallery.images.length}`;
            const hasMultipleImages = activeGallery.images.length > 1;
            document.querySelector('#announcement-gallery-previous').hidden = !hasMultipleImages;
            document.querySelector('#announcement-gallery-next').hidden = !hasMultipleImages;
            document.querySelector('.announcement-gallery__stage').classList.toggle('announcement-gallery__stage--single', !hasMultipleImages);
        }

        document.querySelectorAll('[data-gallery-open]').forEach((button) => {
            button.addEventListener('click', () => {
                activeGallery = announcementGalleries[button.dataset.galleryOpen];
                if (!activeGallery || activeGallery.images.length === 0) return;
                galleryTitle.textContent = activeGallery.title;
                showGalleryImage(0);
                galleryDialog.showModal();
            });
        });

        document.querySelector('#announcement-gallery-previous').addEventListener('click', () => showGalleryImage(activeGalleryIndex - 1));
        document.querySelector('#announcement-gallery-next').addEventListener('click', () => showGalleryImage(activeGalleryIndex + 1));
        document.querySelector('#announcement-gallery-close').addEventListener('click', () => galleryDialog.close());
        galleryDialog.addEventListener('click', (event) => {
            if (event.target === galleryDialog) galleryDialog.close();
        });
        galleryDialog.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') showGalleryImage(activeGalleryIndex - 1);
            if (event.key === 'ArrowRight') showGalleryImage(activeGalleryIndex + 1);
        });

        let galleryTouchStart = null;
        galleryImage.addEventListener('touchstart', (event) => {
            galleryTouchStart = event.changedTouches[0].clientX;
        }, { passive: true });
        galleryImage.addEventListener('touchend', (event) => {
            if (galleryTouchStart === null) return;
            const swipeDistance = event.changedTouches[0].clientX - galleryTouchStart;
            if (Math.abs(swipeDistance) > 45) showGalleryImage(activeGalleryIndex + (swipeDistance < 0 ? 1 : -1));
            galleryTouchStart = null;
        }, { passive: true });
    </script>
</body>
</html>