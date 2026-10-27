<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (($_SESSION['user_role'] ?? '') !== 'customer' || !isset($_SESSION['user_id'])) {
    $respond(401, ['error' => 'Customer sign-in is required.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    $respond(405, ['error' => 'This method is not supported.']);
}

try {
    $statement = getDatabaseConnection()->query('SELECT address, latitude, longitude FROM hotel_settings WHERE id = 1');
    $location = $statement->fetch();
    if (!$location || trim((string) $location['address']) === '' || $location['latitude'] === null || $location['longitude'] === null) {
        $respond(404, ['error' => 'The hotel location has not been set yet. Please check back later.']);
    }

    $respond(200, $location);
} catch (PDOException $exception) {
    error_log('Customer hotel location request failed: ' . $exception->getMessage());
    $respond(503, ['error' => 'The hotel location is unavailable right now. Please try again later.']);
}