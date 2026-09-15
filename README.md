# Puff HTTP Server

HTTP application orchestration for Puff. The `puff/http-server` package connects the protocol-independent `puff/application` lifecycle to HTTP requests, routing, middleware pipelines and PSR-15 servers.

```php
use Puff\HttpServer\ServiceProvider;

$provider = make(ServiceProvider::class);
```

HTTP instances are read from the shared `config/server.php` list. Each item with `type: 'http'` starts a listener:

```php
[
    'type' => 'http',
    'addr' => '127.0.0.1:8620',
    'routes' => [dirname(__DIR__) . '/app/routes.php'],
    'pipeline' => [],
    'workers' => 1,
    'trusted_proxies' => [],
],
```

Multiple HTTP listeners are supported. All HTTP items share one Application process group, so they must use the same `workers` value.
