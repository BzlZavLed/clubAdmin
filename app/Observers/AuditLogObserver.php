<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogObserver
{
    public function created(Model $model): void
    {
        $this->log('created', $model, [
            'after' => $this->attributesForLog($model),
        ]);
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);
        if (empty($changes)) {
            return;
        }

        $before = [];
        foreach ($changes as $key => $value) {
            $before[$key] = $model->getOriginal($key);
        }

        $this->log('updated', $model, [
            'before' => $before,
            'after' => $changes,
        ]);
    }

    public function deleted(Model $model): void
    {
        $this->log('deleted', $model, [
            'before' => $this->attributesForLog($model),
        ]);
    }

    public function restored(Model $model): void
    {
        $this->log('restored', $model, [
            'after' => $this->attributesForLog($model),
        ]);
    }

    public function forceDeleted(Model $model): void
    {
        $this->log('force_deleted', $model, [
            'before' => $this->attributesForLog($model),
        ]);
    }

    protected function log(string $action, Model $model, array $changes = []): void
    {
        if ($model instanceof AuditLog) {
            return;
        }

        $request = request();
        $user = auth()->user();

        AuditLog::create([
            'actor_id' => $user?->id,
            'action' => $action,
            'entity_type' => class_basename($model),
            'entity_id' => $model->getKey(),
            'entity_label' => $this->labelFor($model),
            'changes' => empty($changes) ? null : $changes,
            'metadata' => [
                'club_id' => $model->club_id ?? null,
                'church_id' => $model->church_id ?? null,
            ],
            'route' => $request?->route()?->getName(),
            'method' => $request?->method(),
            'url' => $request?->fullUrl(),
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    protected function labelFor(Model $model): ?string
    {
        foreach (['name', 'title', 'class_name', 'applicant_name', 'church_name', 'club_name', 'email'] as $field) {
            if (!empty($model->{$field})) {
                return (string) $model->{$field};
            }
        }
        return null;
    }

    protected function attributesForLog(Model $model): array
    {
        $attributes = $model->getAttributes();
        unset($attributes['password'], $attributes['remember_token']);
        return $attributes;
    }
}
