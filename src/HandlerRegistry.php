<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/http-server
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\HttpServer;

final class HandlerRegistry
{
    /** @var array<string, HandlerFactory> */
    private array $factories = [];

    public function add(HandlerFactory $factory): void
    {
        $type = \strtolower(\trim($factory->type()));
        if ($type === '') {
            throw new \InvalidArgumentException('HTTP server handler type must not be empty.');
        }
        if (isset($this->factories[$type])) {
            throw new \LogicException("HTTP server handler [{$type}] is already registered.");
        }
        $this->factories[$type] = $factory;
    }

    public function get(string $type): ?HandlerFactory
    {
        return $this->factories[\strtolower($type)] ?? null;
    }
}
