<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

final class Audit
{
    public static function record(
        string $event,
        ?Model $model = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $reason = null,
        ?string $description = null,
    ): void {
        $oldValues = self::redact($oldValues);
        $newValues = self::redact($newValues);

        AuditLog::create([
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'description' => $description,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'reason' => $reason,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }

    private static function redact(array $values): array
    {
        foreach (['password', 'password_confirmation', 'remember_token'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
