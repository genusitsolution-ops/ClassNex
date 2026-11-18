<?php
declare(strict_types=1);

/**
 * Minimal router. Options per route:
 *  auth      => bool (default true)
 *  roles     => array of allowed roles
 *  perm      => permission key (academy roles)
 *  feature   => package feature key the academy must have
 *  write     => bool: requires a writable subscription (default: true for non-GET academy routes)
 */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        // {id}-style parameters only match digits, so fixed paths like /students/bulk-branch never collide with /students/{id}
        $regex = '#^' . preg_replace_callback('#\{(\w+)\}#', static fn($m) => '(?P<' . $m[1] . '>' . ($m[1] === 'id' || str_ends_with($m[1], 'id') ? '\d+' : '[^/]+') . ')', rtrim($pattern, '/')) . '/?$#';
        $this->routes[] = [$method, $regex, $handler, $opts, rtrim($pattern, '/')];
    }

    /** Route table (used to generate docs/API.md). */
    public function definitions(): array
    {
        return array_map(static fn($r) => ['method' => $r[0], 'path' => $r[4], 'opts' => $r[3]], $this->routes);
    }

    public function get(string $p, callable $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, callable $h, array $o = []): void { $this->add('POST', $p, $h, $o); }
    public function put(string $p, callable $h, array $o = []): void { $this->add('PUT', $p, $h, $o); }
    public function delete(string $p, callable $h, array $o = []): void { $this->add('DELETE', $p, $h, $o); }

    public function dispatch(string $method, string $path): array
    {
        $path = '/' . trim($path, '/');
        $allowed = false;
        foreach ($this->routes as [$m, $regex, $handler, $opts]) {
            if (!preg_match($regex, $path, $mt)) {
                continue;
            }
            $allowed = true;
            if ($m !== $method) {
                continue;
            }
            $params = array_filter($mt, 'is_string', ARRAY_FILTER_USE_KEY);
            $ctx = $this->guard($method, $opts);
            $result = $handler($ctx, $params);
            return is_array($result) ? $result : Http::ok($result);
        }
        throw new ApiException($allowed ? 'Method not allowed.' : 'Endpoint not found.', $allowed ? 405 : 404);
    }

    private function guard(string $method, array $opts): ?Ctx
    {
        if (($opts['auth'] ?? true) === false) {
            return Auth::optional();
        }
        $ctx = Auth::require();
        if (!empty($opts['roles']) && !in_array($ctx->role, $opts['roles'], true)) {
            throw ApiException::forbidden();
        }
        if (!empty($opts['perm']) && !$ctx->can($opts['perm'])) {
            throw ApiException::forbidden();
        }
        if ($ctx->academyId) {
            $sub = Subscription::state($ctx->academyId);
            if ($sub['status'] === 'suspended' && $ctx->role !== 'owner') {
                throw new ApiException('This academy account is suspended. Please contact the academy.', 403, [], 'suspended');
            }
            if (!empty($opts['feature']) && !Feature::has($ctx->academyId, $opts['feature'])) {
                throw new ApiException('This feature is not included in your current plan. Upgrade to use it.', 402, [], 'feature_locked');
            }
            $isWrite = $opts['write'] ?? ($method !== 'GET');
            if ($isWrite && empty($opts['allow_readonly']) && !$sub['writable'] && in_array($ctx->role, ['owner', 'teacher'], true)) {
                throw new ApiException('Your subscription has expired. Renew your plan to make changes — all your data is safe.', 402, [], 'subscription_expired');
            }
        }
        return $ctx;
    }
}
