<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Support\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
            'before' => $this->sanitize($before),
            'after' => $this->sanitize($changes),
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

        $user = auth()->user();

        AuditRecorder::event($action, [
            'actor_id' => $user?->id,
            'entity_type' => class_basename($model),
            'entity_id' => $model->getKey(),
            'entity_label' => $this->labelFor($model),
            'changes' => empty($changes) ? null : $changes,
            'metadata' => [
                'club_id' => $model->club_id ?? null,
                'church_id' => $model->church_id ?? null,
            ],
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
        return $this->sanitize($model->getAttributes());
    }

    protected function sanitize(array $attributes): array
    {
        $sensitivePatterns = [
            'password', 'remember_token', 'token', 'secret', 'api_key',
            'signature', 'receipt_path', 'proof_path', 'check_image',
            'insurance_card', 'identity_snapshot', 'medical', 'health',
        ];

        foreach ($attributes as $key => $value) {
            $normalizedKey = Str::lower((string) $key);
            if (collect($sensitivePatterns)->contains(fn (string $pattern) => str_contains($normalizedKey, $pattern))) {
                $attributes[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $attributes[$key] = $this->sanitize($value);
                continue;
            }

            if (is_string($value) && strlen($value) > 500) {
                $attributes[$key] = [
                    'redacted_large_value' => true,
                    'length' => strlen($value),
                    'sha256' => hash('sha256', $value),
                ];
            }
        }

        return $attributes;
    }
}
