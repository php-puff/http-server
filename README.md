# Puff HTTP Server

HTTP application orchestration for Puff. The `puff/http-server` package connects the protocol-independent `puff/application` lifecycle to HTTP requests, routing, middleware pipelines and PSR-15 servers.

```php
use Puff\HttpServer\ServiceProvider;

$provider = make(ServiceProvider::class);
```

The package publishes `config/http.server.php`; its values are available under `http.server`:

```php
'http' => [
    'server' => [
        'addr' => '127.0.0.1:8620',
        'routes' => [dirname(__DIR__) . '/app/routes.php'],
        'pipeline' => [],
        'workers' => 1,
        'trusted_proxies' => [],
    ],
],
```
