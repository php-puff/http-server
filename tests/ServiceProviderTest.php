<?php

declare(strict_types=1);
/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/http-server
 * https://github.com/php-puff/http-server/issues
 * Copyright (c) Puff
 */

namespace Puff\HttpServer\Tests;

use PHPUnit\Framework\TestCase;
use Puff\Application\Application;
use Puff\Application\Exception;
use Puff\Config\Config;
use Puff\Di\Container;
use Puff\Http\Request;
use Puff\Routing\Router;
use Puff\HttpServer\ServiceProvider;

final class ServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Exception::restore();
    }

    public function testUsesSharedApplicationConfigAndContainer(): void
    {
        $config = new Config([
            'http' => ['server' => ['workers' => 3, 'routes' => []]],
        ]);
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance('config', $config);
        $app = new Application($container);
        $web = $app->container()->make(ServiceProvider::class);

        self::assertInstanceOf(ServiceProvider::class, $web);
        self::assertSame([], $web->dispatcher()->routes());
        self::assertSame(Request::class, $app->container()->getAlias('request'));
        self::assertSame(Router::class, $app->container()->getAlias('router'));
        self::assertSame(3, $web->workers());
        self::assertSame('127.0.0.1:8620', $web->info()['addr']);
    }

    public function testMissingRouteReturnsNotFoundResponse(): void
    {
        $config = new Config([
            'http' => ['server' => ['routes' => []]],
        ]);
        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance('config', $config);
        $app = new Application($container);
        $web = $app->container()->make(ServiceProvider::class);

        $response = $web->handle(new Request('GET', '/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Invalid Request Route', (string) $response->getBody());
    }
}
