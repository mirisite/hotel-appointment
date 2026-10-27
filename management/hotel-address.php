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

if (($_SESSION['user_role'] ?? '') !== 'management' || !isset($_SESSION['user_id'])) {
    $respond(401, ['error' => 'Management sign-in is required.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    $respond(405, ['error' => 'This method is not supported.']);
}

try {
    $pdo = getDatabaseConnection();

    if ($method === 'GET') {
        $location = $pdo->query('SELECT address, latitude, longitude FROM hotel_settings WHERE id = 1')->fetch();
        $respond(200, $location ?: ['address' => '', 'latitude' => null, 'longitude' => null]);
    }

    $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expectedToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !is_string($expectedToken) || $expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
        $respond(403, ['error' => 'Your session expired. Refresh the page and try again.']);
    }

    $input = json_decode((string) file_get_contents('php://input'), true);
    $address = is_array($input) && is_string($input['address'] ?? null) ? trim($input['address']) : '';
    $latitude = is_array($input) && is_numeric($input['latitude'] ?? null) ? (float) $input['latitude'] : NAN;
    $longitude = is_array($input) && is_numeric($input['longitude'] ?? null) ? (float) $input['longitude'] : NAN;
    $addressIsValid = preg_match('/^.{1,255}$/us', $address) === 1;

    if (!$addressIsValid || !is_finite($latitude) || $latitude < -90 || $latitude > 90 || !is_finite($longitude) || $longitude < -180 || $longitude > 180) {
        $respond(422, ['error' => 'Enter a valid address and choose a valid map location.']);
    }

    $statement = $pdo->prepare(
        'INSERT INTO hotel_settings (id, address, latitude, longitude, updated_by) VALUES (1, :address, :latitude, :longitude, :updated_by) ON DUPLICATE KEY UPDATE address = VALUES(address), latitude = VALUES(latitude), longitude = VALUES(longitude), updated_by = VALUES(updated_by)'
    );
    $statement->execute([
        'address' => $address,
        'latitude' => number_format($latitude, 7, '.', ''),
        'longitude' => number_format($longitude, 7, '.', ''),
        'updated_by' => (int) $_SESSION['user_id'],
    ]);

    $respond(200, ['address' => $address, 'latitude' => $latitude, 'longitude' => $longitude]);
} catch (PDOException $exception) {
    error_log('Hotel location request failed: ' . $exception->getMessage());
    $respond(503, ['error' => 'Hotel location is unavailable. Apply the latest database_migration.sql and try again.']);
} catch (JsonException $exception) {
    $respond(400, ['error' => 'The submitted location data is invalid.']);
}