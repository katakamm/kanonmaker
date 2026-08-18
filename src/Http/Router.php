<?php

declare(strict_types=1);

namespace Kanon\Http;

final class Router
{
    /** @var list<array{method: string, regex: string, names: list<string>, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) use (&$names): string {
                $names[] = $m[1];

                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#u',
            'names'   => $names,
            'handler' => $handler,
        ];
    }

    /** @return array{handler: callable, params: array<string, string>}|null */
    public function match(string $method, string $path): ?array
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }

            $params = [];
            foreach ($route['names'] as $i => $name) {
                $params[$name] = $m[$i + 1];
            }

            return ['handler' => $route['handler'], 'params' => $params];
        }

        return null;
    }
}
