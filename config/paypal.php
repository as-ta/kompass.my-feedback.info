<?php

declare(strict_types=1);

$settings = [
    'client_id' => getenv('KOMPASS_PAYPAL_CLIENT_ID') ?: '',
    'secret' => getenv('KOMPASS_PAYPAL_SECRET') ?: '',
    'amount' => getenv('KOMPASS_PAYPAL_AMOUNT') ?: '',
];
$local = __DIR__ . '/paypal.local.php';

return is_file($local) ? array_replace($settings, require $local) : $settings;
