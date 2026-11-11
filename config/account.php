<?php

declare(strict_types=1);

$settings = [
    'dsn' => getenv('KOMPASS_DB_DSN') ?: '',
    'user' => getenv('KOMPASS_DB_USER') ?: '',
    'password' => getenv('KOMPASS_DB_PASSWORD') ?: '',
    'url' => getenv('KOMPASS_PUBLIC_URL') ?: '',
    'from' => getenv('KOMPASS_ACCOUNT_FROM') ?: 'info@my-feedback.info',
];
$local = __DIR__ . '/account.local.php';

return is_file($local) ? array_replace($settings, require $local) : $settings;
