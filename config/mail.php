<?php
declare(strict_types=1);

$readSetting = static function (string $name): string {
    $value = getenv($name);
    if (is_string($value) && $value !== '') {
        return $value;
    }

    $serverValue = $_SERVER[$name] ?? '';
    return is_string($serverValue) ? $serverValue : '';
};

$port = filter_var($readSetting('HOTEL_SMTP_PORT') ?: '587', FILTER_VALIDATE_INT);

return [
    'host' => $readSetting('HOTEL_SMTP_HOST') ?: 'smtp.gmail.com',
    'port' => $port !== false && $port > 0 && $port <= 65535 ? $port : 587,
    'username' => $readSetting('HOTEL_SMTP_USERNAME') ?: 'alshergenodepanon@gmail.com',
    'password' => $readSetting('HOTEL_SMTP_PASSWORD'),
    'from_email' => $readSetting('HOTEL_SMTP_FROM_EMAIL') ?: ($readSetting('HOTEL_SMTP_USERNAME') ?: 'alshergenodepanon@gmail.com'),
    'from_name' => $readSetting('HOTEL_SMTP_FROM_NAME') ?: 'The Queens Hotel',
];