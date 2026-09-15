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
use Puff\Http\Response;

final class Resource
{
    private ?string $resource = null;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $resource = $config['resource'] ?? null;
        if ($resource === null) {
            return;
        }
        if (!\is_string($resource) || $resource === '') {
            throw new \InvalidArgumentException('Server resource must be a directory path.');
        }
        $realRoot = \realpath($resource);
        if ($realRoot === false || !\is_dir($realRoot) || !\is_readable($realRoot)) {
            throw new \InvalidArgumentException("Server resource [{$resource}] must be a readable directory.");
        }

        $this->resource = \rtrim($realRoot, DIRECTORY_SEPARATOR);
    }

    public function handle(ServerRequestInterface $request): ?ResponseInterface
    {
        if ($this->resource === null || !\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $file = $this->file($request->getUri()->getPath());
        if ($file === null) {
            return null;
        }

        $modified = \filemtime($file);
        $etag = \sha1_file($file);
        if ($modified === false || $etag === false) {
            return null;
        }
        $etag = '"' . $etag . '"';
        $headers = [
            'Content-Type' => $this->mime($file),
            'Content-Length' => (string) \filesize($file),
            'ETag' => $etag,
            'Last-Modified' => \gmdate('D, d M Y H:i:s', $modified) . ' GMT',
        ];
        if ($this->notModified($request, $etag, $modified)) {
            return new Response(304, $headers);
        }

        $content = $request->getMethod() === 'HEAD' ? '' : \file_get_contents($file);
        if ($content === false) {
            return null;
        }

        return new Response(200, $headers, $content);
    }

    private function file(string $path): ?string
    {
        if (!\str_starts_with($path, '/') || \str_contains($path, "\0")) {
            return null;
        }

        $relative = \ltrim(\rawurldecode($path), '/');
        if ($relative === '' || \str_contains($relative, '\\') || \preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $relative) === 1) {
            return null;
        }
        foreach (\explode('/', $relative) as $segment) {
            if ($segment === '' || \str_starts_with($segment, '.')) {
                return null;
            }
        }

        $file = \realpath($this->resource . DIRECTORY_SEPARATOR . $relative);
        if ($file === false || !\str_starts_with($file, $this->resource . DIRECTORY_SEPARATOR) || !\is_file($file) || !\is_readable($file)) {
            return null;
        }
        if (\str_ends_with(\strtolower($file), '.php')) {
            return null;
        }

        return $file;
    }

    private function mime(string $file): string
    {
        if (!\function_exists('finfo_open')) {
            return 'application/octet-stream';
        }
        $finfo = \finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? false : \finfo_file($finfo, $file);
        if ($finfo !== false) {
            \finfo_close($finfo);
        }
        return \is_string($mime) ? $mime : 'application/octet-stream';
    }

    private function notModified(ServerRequestInterface $request, string $etag, int $modified): bool
    {
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '') {
            return \in_array($etag, \array_map('trim', \explode(',', $ifNoneMatch)), true) || \trim($ifNoneMatch) === '*';
        }
        $ifModifiedSince = \strtotime($request->getHeaderLine('If-Modified-Since'));
        return $ifModifiedSince !== false && $modified <= $ifModifiedSince;
    }
}
