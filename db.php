<?php
declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('HOTEL_DB_HOST') ?: '127.0.0.1';
    $database = getenv('HOTEL_DB_NAME') ?: 'hotel_management';
    $username = getenv('HOTEL_DB_USER') ?: 'root';
    $password = getenv('HOTEL_DB_PASSWORD') ?: '';

    $connection = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $connection;
}