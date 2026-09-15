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
    'resource' => dirname(__DIR__) . '/www/assets',
    'pipeline' => [],
    'trusted_proxies' => [],
],
```

Multiple HTTP listeners are supported. Their shared Worker count is configured by `workers` in `config/config.php`.

`resource` is optional. Its files map directly to request paths: `/css/app.css` maps to `resource/css/app.css`. Puff only serves `GET` and `HEAD`; hidden files, path traversal attempts and PHP files are never served. A missing file falls through to routing. Cache policy belongs to Nginx, a CDN, or another reverse proxy; Puff only returns ETag and Last-Modified for conditional requests.
