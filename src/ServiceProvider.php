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
use Puff\Application\Application;
use Puff\Application\Contract;
use Puff\Http\Request;

final class ServiceProvider implements Contract
{
    private ?Dispatcher $dispatcher = null;
    private ?Server $server = null;
    /** @var array<string, mixed> */
    private array $config = [];

    public function name(): string
    {
        return 'HTTP';
    }

    public function boot(Application $app): void
    {
        $config = $app->container()->make('config');
        $this->config = (array) $config->get('http.server', []);
        $routes = \array_values(\array_filter((array) ($this->config['routes'] ?? []), \is_string(...)));
        $pipeline = \array_values(\array_filter(
            (array) ($this->config['pipeline'] ?? []),
            static fn (mixed $item): bool => \is_string($item) || \is_object($item),
        ));
        $this->dispatcher = new Dispatcher(
            $app,
            $routes,
            $pipeline,
        );
        $this->server = new Server(
            new Handler($this->dispatcher),
            $this->config,
        );
    }

    public function start(): void
    {
        $this->server()->start();
    }

    public function stop(): void
    {
        $this->server?->stop();
    }

    public function workers(): int
    {
        return \max(1, (int) ($this->config['workers'] ?? 1));
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        return [
            ...$this->server()->info(),
            'name' => $this->name(),
            'workers' => $this->workers(),
        ];
    }

    public function server(): Server
    {
        return $this->server ?? throw new \LogicException('HTTP worker has not been booted.');
    }

    public function handle(Request $request): ResponseInterface
    {
        return $this->dispatcher()->handle($request);
    }

    public function dispatcher(): Dispatcher
    {
        return $this->dispatcher ?? throw new \LogicException('HTTP worker has not been booted.');
    }

}
