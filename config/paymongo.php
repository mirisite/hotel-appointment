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

return [
    'secret_key' => $readSetting('PAYMONGO_SECRET_KEY'),
    'webhook_secret' => $readSetting('PAYMONGO_WEBHOOK_SECRET'),
    'app_url' => rtrim($readSetting('HOTEL_APP_URL'), '/'),
    'api_url' => 'https://api.paymongo.com/v1',
];