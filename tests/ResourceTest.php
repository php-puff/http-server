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
use Puff\Http\Request;
use Puff\HttpServer\Resource;

final class ResourceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/puff-resource-' . \bin2hex(\random_bytes(8));
        \mkdir($this->root . '/css', 0700, true);
        \file_put_contents($this->root . '/css/app.css', 'body{}');
        \file_put_contents($this->root . '/script.php', '<?php');
        \file_put_contents($this->root . '/.secret', 'hidden');
    }

    protected function tearDown(): void
    {
        \unlink($this->root . '/css/app.css');
        \unlink($this->root . '/script.php');
        \unlink($this->root . '/.secret');
        \rmdir($this->root . '/css');
        \rmdir($this->root);
    }

    public function testServesFilesFromResource(): void
    {
        $resource = new Resource(['resource' => $this->root]);

        $response = $resource->handle(new Request('GET', '/css/app.css'));

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('body{}', (string) $response->getBody());
        self::assertSame('', $response->getHeaderLine('Cache-Control'));
        self::assertNull($resource->handle(new Request('GET', '/missing.css')));
    }

    public function testRejectsUnsafeFilesAndFallsBackForOtherMethods(): void
    {
        $resource = new Resource(['resource' => $this->root]);

        self::assertNull($resource->handle(new Request('GET', '/../.secret')));
        self::assertNull($resource->handle(new Request('GET', '/.secret')));
        self::assertNull($resource->handle(new Request('GET', '/script.php')));
        self::assertNull($resource->handle(new Request('POST', '/css/app.css')));
    }

    public function testSupportsHeadAndConditionalRequests(): void
    {
        $resource = new Resource(['resource' => $this->root]);
        $response = $resource->handle(new Request('HEAD', '/css/app.css'));

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('6', $response->getHeaderLine('Content-Length'));

        $notModified = $resource->handle(new Request('GET', '/css/app.css', [
            'If-None-Match' => $response->getHeaderLine('ETag'),
        ]));
        self::assertNotNull($notModified);
        self::assertSame(304, $notModified->getStatusCode());
    }
}
