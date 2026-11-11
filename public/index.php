<?php

declare(strict_types=1);

use Laminas\Mvc\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

Application::init(require dirname(__DIR__) . '/config/application.php')->run();
