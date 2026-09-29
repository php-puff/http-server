<?php

declare(strict_types=1);
/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/http-server
 * https://github.com/php-puff/http-server/issues
 * Copyright (c) Puff
 */

namespace Puff\HttpServer\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Puff\Async\EventLoop;
use Puff\Http\Response;
use Puff\HttpServer\Server;

final class ServerTest extends TestCase
{
    public function testDefaultAddressUsesPort8620(): void
    {
        $server = new Server($this->handler());

        self::assertSame('127.0.0.1:8620', $server->address());
        self::assertSame(['addr', 'connections'], \array_keys($server->info()));
        self::assertSame('127.0.0.1:8620', $server->info()['addr']);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testServesHttpRequestWithInternalServer(): void
    {
        EventLoop::reset();
        $server = new Server($this->handler(), ['addr' => '127.0.0.1:0', 'workers' => 1]);
        $server->start();
        $address = $server->address();
        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            EventLoop::get()->run();
            exit(0);
        }

        \usleep(20_000);
        $client = \stream_socket_client('tcp://' . $address, $errno, $error, 2);
        self::assertNotFalse($client, $error ?? 'Unable to connect to HTTP server.');
        \fwrite($client, "GET /health?check=1 HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $response = \stream_get_contents($client);
        \fclose($client);
        @\posix_kill($pid, SIGTERM);
        \pcntl_waitpid($pid, $status);
        $server->stop();

        self::assertStringContainsString('HTTP/1.1 200 OK', $response);
        self::assertStringEndsWith('pure-fiber-server', $response);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'text/plain'], 'pure-fiber-server');
            }
        };
    }
}
