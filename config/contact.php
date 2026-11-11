<?php

declare(strict_types=1);

return [
    'to' => getenv('STUDIO_CONTACT_TO') ?: 'info@my-feedback.info',
    'from' => getenv('STUDIO_CONTACT_FROM') ?: 'info@my-feedback.info',
];
