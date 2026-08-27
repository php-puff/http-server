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
use Puff\Http\Exception\HttpException;
use Puff\Http\Request;
use Puff\Http\Response;
use Puff\Pipeline\Pipeline;
use Puff\Routing\Exception\MethodNotAllowedException;
use Puff\Routing\RouteCollection;
use Puff\Routing\Router;

final class Dispatcher
{
    /** @var list<string> */
    private array $routes;
    private RouteCollection $routeCollection;

    /**
     * @param string|list<string> $routes
     * @param list<string|object> $pipeline
     */
    public function __construct(
        private readonly Application $app,
        string|array $routes,
        private readonly array $pipeline = [],
    ) {
        $this->routes = \array_map(
            static fn (string $route): string => \str_ends_with($route, '.php') ? \substr($route, 0, -4) : $route,
            (array) $routes,
        );
        $container = $app->container();
        $container->alias(Router::class, 'router');
        $container->alias(Request::class, 'request');
        $container->alias(Response::class, 'response');
        $compileRequest = new Request();
        $compiler = new Router($compileRequest);
        $container->scopedInstance('request', $compileRequest);
        $container->scopedInstance('router', $compiler);
        try {
            $this->routeCollection = $compiler->load($this->routes);
        } finally {
            $container->clearScope();
        }
    }

    public function dispatch(mixed ...$context): mixed
    {
        $container = $this->app->container();
        return $container->make('router')->through($this->routes, function ($route) use ($container, $context): mixed {
            $request = $container->make('request');
            $locale = $route->locale();
            if ($request instanceof Request && $locale !== null && $locale !== '') {
                $request = $request->withAttribute('locale', $locale);
                $container->scopedInstance('request', $request);
            }

            return (new Pipeline($container))
                ->send($request)
                ->through(self::pipelines(\array_merge($this->pipeline, $route->pipeline())))
                ->then(fn (): mixed => $container->call($route->callable(), $route->args() + $context));
        });
    }

    public function handle(Request $request): ResponseInterface
    {
        $container = $this->app->container();
        $container->scopedInstance('request', $request);
        $container->scopedInstance('router', new Router($request, $this->routeCollection));
        $response = new Response();
        $container->scopedInstance('response', $response);
        try {
            $result = $this->dispatch();
            if ($result instanceof ResponseInterface) {
                return $result;
            }
            return \is_array($result) ? $response->json($result) : $response->make((string) $result);
        } catch (HttpException $exception) {
            $status = $exception->status();
            $message = $exception->getMessage() ?: Response::reason($status);
            $result = $request->accept('application/json')
                ? $response->json(['code' => $status, 'message' => $message], $status)
                : $response->html(\htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $status);
            if ($exception instanceof MethodNotAllowedException && $exception->allowed() !== []) {
                return $result->withHeader('Allow', \implode(', ', $exception->allowed()));
            }
            return $result;
        } finally {
            $container->clearScope();
        }
    }

    /** @return list<string> */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @param  array<array-key, string|object> $pipeline
     * @return list<string|object>
     */
    private static function pipelines(array $pipeline): array
    {
        $unique = [];
        $result = [];
        foreach ($pipeline as $stage) {
            $key = \is_object($stage) ? 'object:' . \spl_object_id($stage) : 'string:' . $stage;
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $result[] = $stage;
        }
        return $result;
    }
}
