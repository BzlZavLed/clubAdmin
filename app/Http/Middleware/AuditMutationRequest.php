<?php

namespace App\Http\Middleware;

use App\Support\AuditRecorder;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditMutationRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $shouldAudit = $actor && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $response = $next($request);

        if ($shouldAudit) {
            [$entityType, $entityId] = $this->routeEntity($request);
            AuditRecorder::event('mutation_request', [
                'actor_id' => $actor->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'metadata' => [
                    'response_status' => $response->getStatusCode(),
                    'successful' => $response->isSuccessful() || $response->isRedirection(),
                ],
            ], $request);
        }

        return $response;
    }

    private function routeEntity(Request $request): array
    {
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Model) {
                return [class_basename($value), $value->getKey()];
            }

            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return ['RouteResource', (int) $value];
            }
        }

        return [null, null];
    }
}
