<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureProfileIs;
use Sentry\Laravel\Integration;
use App\Models\AuditLog;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Append web middleware
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'setup/superadmin',
        ]);

        // All aliases together
        $middleware->alias([
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'profile' => EnsureProfileIs::class,
            'auth.parent' => \App\Http\Middleware\EnsureParent::class,
            'redirect_if_authenticated' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
        $exceptions->reportable(function (Throwable $e) {
            if (app()->runningInConsole()) {
                return;
            }

            if (
                $e instanceof ValidationException ||
                $e instanceof HttpExceptionInterface ||
                $e instanceof AuthenticationException ||
                $e instanceof AuthorizationException ||
                $e instanceof ModelNotFoundException
            ) {
                return;
            }

            $request = request();
            if (!$request) {
                return;
            }

            $route = $request->route();
            $routeName = $route?->getName();
            $params = $route?->parameters() ?? [];
            $path = '/' . ltrim($request->path(), '/');

            $entityType = null;
            $entityId = null;
            $paramMap = [
                'user' => 'User',
                'member' => 'Member',
                'staff' => 'Staff',
                'church' => 'Church',
                'club_class' => 'ClubClass',
                'clubClass' => 'ClubClass',
                'class' => 'ClubClass',
            ];

            foreach ($paramMap as $key => $type) {
                if (array_key_exists($key, $params)) {
                    $entityType = $type;
                    $value = $params[$key];
                    $entityId = is_object($value) && method_exists($value, 'getKey')
                        ? $value->getKey()
                        : (is_scalar($value) ? (int) $value : null);
                    break;
                }
            }

            if (!$entityType) {
                if (str_contains($path, '/members')) $entityType = 'Member';
                elseif (str_contains($path, '/staff')) $entityType = 'Staff';
                elseif (str_contains($path, '/church')) $entityType = 'Church';
                elseif (str_contains($path, '/club-classes')) $entityType = 'ClubClass';
                elseif (str_contains($path, '/users')) $entityType = 'User';
            }

            if (!$entityType) {
                return;
            }

            AuditLog::create([
                'actor_id' => auth()->user()?->id,
                'action' => 'exception',
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'route' => $routeName,
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'path' => $path,
                ],
            ]);
        });
    })
    ->create();
