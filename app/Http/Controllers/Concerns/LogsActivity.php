<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

trait LogsActivity
{
    protected function logActivity(string $action, ?Model $model = null, ?array $properties = null, ?string $description = null): void
    {
        ActivityLog::create([
            'user_id'     => auth()->id(),
            'action'      => $action,
            'model_type'  => $model ? class_basename($model) : null,
            'model_id'    => $model?->getKey(),
            'description' => $description,
            'properties'  => $properties,
            'ip_address'  => request()->ip(),
            'created_at'  => now(),
        ]);
    }
}
