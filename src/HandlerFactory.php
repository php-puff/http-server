<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/http-server
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\HttpServer;

use Psr\Http\Server\RequestHandlerInterface;
use Puff\Application\Application;

/** Creates an HTTP request handler for one configured server type. */
interface HandlerFactory
{
    public function type(): string;

    /** @param array<string, mixed> $server */
    public function create(Application $app, array $server): RequestHandlerInterface;
}
