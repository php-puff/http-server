<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/puff
 * https://github.com/php-puff/puff/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

return [
    'addr' => '127.0.0.1:8620',
    'routes' => [\dirname(__DIR__, 2) . '/app/routes.php'],
    'pipeline' => [],
    'workers' => 1,
    'trusted_proxies' => [],
];
