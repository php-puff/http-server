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
use Psr\Http\Server\RequestHandlerInterface;
use Puff\Application\Application;
use Puff\Application\Contract;
use Puff\Http\Request;

final class ServiceProvider implements Contract
{
    /** @var list<Dispatcher> */
    private array $dispatchers = [];

    /** @var list<Server> */
    private array $servers = [];

    /** @var list<Resource> */
    private array $resources = [];

    /** @var array<string, array{dispatcher: Dispatcher, resource: Resource}> */
    private array $httpHandlers = [];

    private ?int $workers = null;

    public function name(): string
    {
        return 'HTTP';
    }

    public function boot(Application $app): void
    {
        $this->dispatchers = [];
        $this->servers = [];
        $this->resources = [];
        $this->httpHandlers = [];
        $this->workers = null;
        $app->container()->singletonIf(HandlerRegistry::class);
        $config = $app->container()->make('config');
        $workers = $config->get('workers', 1);
        if (!\is_int($workers) || $workers < 1) {
            throw new \InvalidArgumentException('Config workers must be a positive integer.');
        }
        foreach (\Puff\Server\ServerConfig::all($config->get('server', []), $workers) as $server) {
            $type = \strtolower((string) $server['type']);
            if ($type === 'http') {
                $routes = \array_values(\array_filter((array) ($server['routes'] ?? []), \is_string(...)));
                $pipeline = \array_values(\array_filter(
                    (array) ($server['pipeline'] ?? []),
                    static fn (mixed $item): bool => \is_string($item) || \is_object($item),
                ));
                $dispatcher = new Dispatcher($app, $routes, $pipeline);
                $resource = new Resource($server);
                $handler = new Handler($dispatcher);
                $this->dispatchers[] = $dispatcher;
                $this->resources[] = $resource;
                $this->httpHandlers[$server['addr']] = ['dispatcher' => $dispatcher, 'resource' => $resource];
            } else {
                $factory = $app->container()->make(HandlerRegistry::class)->get($type);
                if ($factory === null) {
                    continue;
                }
                $handler = $factory->create($app, $server);
            }
            if (!$handler instanceof RequestHandlerInterface) {
                throw new \LogicException("HTTP server handler [{$type}] is invalid.");
            }
            $this->servers[] = new Server($handler, $server);
            $this->setWorkers($server['workers']);
        }
        if ($this->servers === []) {
            throw new \LogicException('No HTTP server is configured.');
        }
    }

    public function start(): void
    {
        foreach ($this->servers as $server) {
            $server->start();
        }
    }

    public function stop(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
    }

    public function workers(): int
    {
        return $this->workers ?? 1;
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        $info = \array_map(static fn (Server $server): array => $server->info(), $this->servers);
        return [
            'name' => $this->name(),
            'addr' => \implode(', ', \array_column($info, 'addr')),
            'url' => \implode(', ', \array_column($info, 'url')),
            'workers' => $this->workers(),
        ];
    }

    public function server(): Server
    {
        return $this->servers[0] ?? throw new \LogicException('HTTP worker has not been booted.');
    }

    public function handle(Request $request): ResponseInterface
    {
        $address = \getenv('PUFF_SERVER_ADDR');
        if ($address === false || $address === '') {
            if (\count($this->dispatchers) !== 1) {
                throw new \LogicException('PHP-FPM requires PUFF_SERVER_ADDR when multiple HTTP servers are configured.');
            }
            return $this->resources[0]->handle($request) ?? $this->dispatcher()->handle($request);
        }
        $address = (string) \Puff\Server\Endpoint::parse($address);
        $handler = $this->httpHandlers[$address] ?? null;
        if ($handler !== null) {
            return $handler['resource']->handle($request) ?? $handler['dispatcher']->handle($request);
        }
        throw new \LogicException("HTTP server [{$address}] is not configured for PHP-FPM.");
    }

    public function dispatcher(): Dispatcher
    {
        return $this->dispatchers[0] ?? throw new \LogicException('HTTP worker has not been booted.');
    }

    private function setWorkers(int $workers): void
    {
        if ($this->workers !== null && $this->workers !== $workers) {
            throw new \InvalidArgumentException('HTTP servers must use the same workers value.');
        }
        $this->workers = $workers;
    }

}
