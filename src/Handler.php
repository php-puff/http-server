<?php

declare(strict_types=1);
/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/http-server
 * https://github.com/php-puff/http-server/issues
 * Copyright (c) Puff
 */

namespace Puff\HttpServer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Puff\Http\Request;

final readonly class Handler implements RequestHandlerInterface
{
    public function __construct(private Dispatcher $app)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$request instanceof Request) {
            throw new \InvalidArgumentException('Puff HTTP server requires a Puff\\Http\\Request.');
        }
        return $this->app->handle($request);
    }
}
