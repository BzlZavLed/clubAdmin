<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AuditRecorder
{
    public static function event(string $action, array $attributes = [], ?Request $request = null): AuditLog
    {
        return AuditLog::create([
            ...$attributes,
            'action' => $action,
            ...self::requestContext($request ?: request()),
        ]);
    }

    public static function requestContext(?Request $request): array
    {
        if (! $request) {
            return [];
        }

        $requestId = $request->attributes->get('audit_request_id');
        if (! $requestId) {
            $requestId = (string) Str::uuid();
            $request->attributes->set('audit_request_id', $requestId);
        }

        $route = $request->route();
        $routeTemplate = $route?->uri();

        return [
            'request_id' => $requestId,
            'route' => $route?->getName(),
            'method' => $request->method(),
            // Store the route template, never query strings or secret route parameters.
            'url' => $routeTemplate ? '/'.ltrim($routeTemplate, '/') : '/'.ltrim($request->path(), '/'),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ];
    }

    public static function identifierHash(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return hash_hmac('sha256', Str::lower(trim($value)), (string) config('audit.integrity_key'));
    }

    public static function maskedEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $email, 2);

        return Str::substr($name, 0, 1).'***@'.$domain;
    }
}
