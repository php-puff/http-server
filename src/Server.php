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
use Puff\Async\EventLoop;
use Puff\Async\EventLoopInterface;
use Puff\Async\FiberScheduler;
use Puff\Async\SchedulerInterface;
use Puff\Http\Client\ClientInfo;
use Puff\Http\Client\TrustedProxy;
use Puff\Http\Request;
use Puff\Http\Request\Upload;
use Puff\Http\Response;
use Puff\Http\Stream\Stream;
use Puff\Http\Uri;
use Puff\Server\Connection;
use Puff\Server\Protocol\TcpInterface;
use Puff\Server\TcpServer;
use Throwable;

final class Server implements TcpInterface
{
    private RequestHandlerInterface $handler;
    private SchedulerInterface $scheduler;
    /** @var array<string, mixed> */
    private array $config;
    private TcpServer $tcp;
    private TrustedProxy $trustedProxy;
    private Resource $resource;

    /** @param array<string, mixed> $config */
    public function __construct(
        RequestHandlerInterface $handler,
        array $config = [],
        ?EventLoopInterface $loop = null,
        ?SchedulerInterface $scheduler = null,
    ) {
        $this->config = \array_replace([
            'addr' => '127.0.0.1:8620',
            'workers' => 1,
            'backlog' => 4096,
            'accept_batch' => 256,
            'max_request_size' => 16 * 1024 * 1024,
            'idle_timeout' => 60.0,
            'trusted_proxies' => [],
        ], $config);
        $this->handler = $handler;
        $this->scheduler = $scheduler ?? new FiberScheduler();
        $this->resource = new Resource($this->config);
        $this->trustedProxy = new TrustedProxy(\array_values(\array_filter(
            (array) $this->config['trusted_proxies'],
            'is_string',
        )));
        $this->tcp = new TcpServer($this, $this->config, $loop ?? EventLoop::get());
    }

    public function start(): void
    {
        $this->tcp->start();
    }

    public function stop(): void
    {
        $this->tcp->stop();
    }

    public function address(): string
    {
        return $this->tcp->address();
    }

    /** @return array{addr: string, connections: int} */
    public function info(): array
    {
        return [
            'addr' => $this->address(),
            'connections' => $this->tcp->connectionCount(),
        ];
    }

    public function connected(Connection $connection, TcpServer $server): void
    {
    }

    public function receive(Connection $connection, TcpServer $server): void
    {
        if (\strlen($connection->readBuffer) > (int) $this->config['max_request_size']) {
            $this->queueRawResponse($connection, 413, 'Payload Too Large', true);
            return;
        }

        $this->process($connection);
    }

    public function closed(Connection $connection, TcpServer $server): void
    {
    }

    private function process(Connection $connection): void
    {
        if ($connection->busy || $connection->writeBuffer !== '') {
            return;
        }
        try {
            $parsed = $this->parseRequest($connection, $connection->readBuffer);
        } catch (Throwable $exception) {
            $this->queueRawResponse($connection, 400, 'Bad Request', true);
            return;
        }
        if ($parsed === null) {
            return;
        }

        [$request, $consumed, $keepAlive, $temporaryFiles] = $parsed;
        $connection->readBuffer = (string) \substr($connection->readBuffer, $consumed);
        $connection->busy = true;

        $this->scheduler->async(function () use ($connection, $request, $keepAlive, $temporaryFiles): void {
            try {
                $response = $this->resource->handle($request) ?? $this->handler->handle($request);
                $this->queueResponse($connection, $response, !$keepAlive);
            } catch (Throwable $exception) {
                $this->queueRawResponse($connection, 500, 'Internal Server Error', !$keepAlive);
                \error_log((string) $exception);
            } finally {
                foreach ($temporaryFiles as $file) {
                    @\unlink($file);
                }
            }
        })->ignore();
    }

    /** @return array{Request, int, bool, list<string>}|null */
    private function parseRequest(Connection $connection, string $buffer): ?array
    {
        $headerEnd = \strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }
        $head = \substr($buffer, 0, $headerEnd);
        $lines = \explode("\r\n", $head);
        $requestLine = \array_shift($lines);

        if (!\preg_match('#^([A-Z]+)\s+(\S+)\s+HTTP/(1\.[01])$#', (string) $requestLine, $match)) {
            throw new \RuntimeException('Malformed HTTP request line.');
        }

        [, $method, $target, $version] = $match;
        $headers = [];
        foreach ($lines as $line) {
            $position = \strpos($line, ':');
            if ($position === false) {
                throw new \RuntimeException('Malformed HTTP header.');
            }
            $name = \strtolower(\trim(\substr($line, 0, $position)));
            $value = \trim(\substr($line, $position + 1));
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }

        $bodyStart = $headerEnd + 4;
        $body = '';
        $bodyLength = 0;

        if (\str_contains(\strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
            $chunked = $this->decodeChunked(\substr($buffer, $bodyStart));
            if ($chunked === null) {
                return null;
            }
            [$body, $bodyLength] = $chunked;
        } else {
            $contentLength = (int) ($headers['content-length'] ?? 0);
            if (\strlen($buffer) - $bodyStart < $contentLength) {
                return null;
            }
            $body = \substr($buffer, $bodyStart, $contentLength);
            $bodyLength = $contentLength;
        }

        $parts = \parse_url($target);
        $path = $parts['path'] ?? '/';
        $queryString = $parts['query'] ?? '';
        $query = $this->parseQuery($queryString);
        [$post, $files, $temporaryFiles] = $this->parseBody($headers['content-type'] ?? '', $body);
        $cookies = $this->parseCookies($headers['cookie'] ?? '');
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $target,
            'PATH_INFO' => $path,
            'QUERY_STRING' => $queryString,
            'SERVER_PROTOCOL' => 'HTTP/' . $version,
            'SERVER_NAME' => $headers['host'] ?? $this->tcp->host(),
            'SERVER_PORT' => $this->tcp->port(),
            'CONTENT_TYPE' => $headers['content-type'] ?? '',
            'CONTENT_LENGTH' => \strlen($body),
            'REMOTE_ADDR' => $connection->remoteAddress,
            'REMOTE_PORT' => $connection->remotePort,
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . \strtoupper(\str_replace('-', '_', $name))] = $value;
        }

        $scheme = $this->trustedProxy->scheme($server);
        $host = $this->validHost($headers['host'] ?? '') ? $headers['host'] : $this->tcp->address();
        $uri = $scheme . '://' . $host . $target;
        $request = new Request(
            method: $method,
            uri: new Uri($uri),
            body: new Stream($body),
            protocolVersion: $version,
            headers: $headers,
            serverParams: $server,
            queryParams: $query,
            cookieParams: $cookies,
            uploadedFiles: $files,
            parsedBody: $post,
            clientInfo: ClientInfo::resolve($server, $this->trustedProxy, $scheme),
        );
        $connectionHeader = \strtolower($headers['connection'] ?? '');
        $keepAlive = $version === '1.1' ? $connectionHeader !== 'close' : $connectionHeader === 'keep-alive';

        return [$request, $bodyStart + $bodyLength, $keepAlive, $temporaryFiles];
    }

    private function validHost(string $host): bool
    {
        return \preg_match('/^(?:[a-z0-9.-]+|\[[0-9a-f:]+])(?::\d{1,5})?$/i', $host) === 1;
    }

    /** @return array{string, int}|null */
    private function decodeChunked(string $buffer): ?array
    {
        $offset = 0;
        $decoded = '';
        $length = \strlen($buffer);

        while (true) {
            $lineEnd = \strpos($buffer, "\r\n", $offset);
            if ($lineEnd === false) {
                return null;
            }
            $line = \substr($buffer, $offset, $lineEnd - $offset);
            $size = (int) \hexdec(\explode(';', $line, 2)[0]);
            $offset = $lineEnd + 2;

            if ($size === 0) {
                if (\substr($buffer, $offset, 2) === "\r\n") {
                    return [$decoded, $offset + 2];
                }
                $trailerEnd = \strpos($buffer, "\r\n\r\n", $offset);
                if ($trailerEnd === false) {
                    return null;
                }
                return [$decoded, $trailerEnd + 4];
            }

            if ($length < $offset + $size + 2) {
                return null;
            }
            $decoded .= \substr($buffer, $offset, $size);
            $offset += $size;
            if (\substr($buffer, $offset, 2) !== "\r\n") {
                throw new \RuntimeException('Malformed chunked body.');
            }
            $offset += 2;
        }
    }

    /** @return array{array<string, mixed>, array<string, Upload>, list<string>} */
    private function parseBody(string $contentType, string $body): array
    {
        $post = [];
        $files = [];
        $temporary = [];

        if (\str_starts_with(\strtolower($contentType), 'application/x-www-form-urlencoded')) {
            \parse_str($body, $post);
        } elseif (\str_starts_with(\strtolower($contentType), 'application/json')) {
            $decoded = \json_decode($body, true);
            $post = \is_array($decoded) ? $decoded : [];
        } elseif (\preg_match('/multipart\/form-data;\s*boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $match)) {
            $boundary = $match[1] !== '' ? $match[1] : $match[2];
            foreach (\explode('--' . $boundary, $body) as $part) {
                $part = \ltrim($part, "\r\n");
                if ($part === '' || \str_starts_with($part, '--') || !\str_contains($part, "\r\n\r\n")) {
                    continue;
                }
                if (\str_ends_with($part, "\r\n")) {
                    $part = \substr($part, 0, -2);
                }
                [$partHead, $value] = \explode("\r\n\r\n", $part, 2);
                $value = \preg_replace('/\r\n$/', '', $value) ?? $value;
                if (!\preg_match('/name="([^"]+)"/', $partHead, $nameMatch)) {
                    continue;
                }
                $name = $nameMatch[1];

                if (\preg_match('/filename="([^"]*)"/', $partHead, $fileMatch)) {
                    $tmp = \tempnam(\sys_get_temp_dir(), 'puff-upload-');
                    if ($tmp === false) {
                        throw new \RuntimeException('Unable to create upload temporary file.');
                    }
                    \file_put_contents($tmp, $value);
                    $temporary[] = $tmp;
                    \preg_match('/Content-Type:\s*([^\r\n]+)/i', $partHead, $typeMatch);
                    $files[$name] = new Upload(
                        $tmp,
                        \strlen($value),
                        UPLOAD_ERR_OK,
                        $fileMatch[1],
                        $typeMatch[1] ?? 'application/octet-stream',
                        true,
                    );
                } else {
                    $post[$name] = $value;
                }
            }
        }

        return [$post, $files, $temporary];
    }

    /** @return array<string, string> */
    private function parseCookies(string $header): array
    {
        $cookies = [];
        foreach (\explode(';', $header) as $cookie) {
            if (!\str_contains($cookie, '=')) {
                continue;
            }
            [$name, $value] = \array_map('trim', \explode('=', $cookie, 2));
            $cookies[$name] = \urldecode($value);
        }
        return $cookies;
    }

    /** @return array<string, mixed> */
    private function parseQuery(string $queryString): array
    {
        $query = [];
        \parse_str($queryString, $query);
        $result = [];
        foreach ($query as $key => $value) {
            $result[(string) $key] = $value;
        }
        return $result;
    }

    private function queueResponse(Connection $connection, ResponseInterface $response, bool $close): void
    {
        $status = $response->getStatusCode();
        $reason = $response->getReasonPhrase() ?: 'Unknown';
        $body = (string) $response->getBody();
        $headers = $response->getHeaders();
        $headers['Content-Length'] ??= [(string) \strlen($body)];
        $headers['Connection'] = [$close ? 'close' : 'keep-alive'];

        $lines = ["HTTP/1.1 {$status} {$reason}"];
        foreach ($headers as $name => $values) {
            foreach ((array) $values as $value) {
                $lines[] = "{$name}: {$value}";
            }
        }
        $this->tcp->send($connection, \implode("\r\n", $lines) . "\r\n\r\n" . $body, $close);
    }

    private function queueRawResponse(Connection $connection, int $status, string $body, bool $close): void
    {
        $reason = Response::reason($status) ?: $body;
        $raw = "HTTP/1.1 {$status} {$reason}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: " . \strlen($body)
            . "\r\nConnection: " . ($close ? 'close' : 'keep-alive') . "\r\n\r\n{$body}";
        $this->tcp->send($connection, $raw, $close);
    }
}
