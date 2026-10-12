<?php

namespace App\Services\Interlab;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Qo'ng'iroqcha (notifications) va audit jurnali — mavjud jadvallarga oddiy yozuv qo'shadi.
 */
class Notifier
{
    /** Ruxsati bor faol foydalanuvchilar (adminlar ham) */
    public function usersWith(string $permission, ?int $groupId = null): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->when($groupId, fn ($q) => $q->where('laboratory_group_id', $groupId))
            ->where(function ($q) use ($permission) {
                $q->whereHas('role', fn ($r) => $r->where('name', 'admin'))
                    ->orWhereHas('role.permissions', fn ($p) => $p->where('name', $permission));
            })
            ->pluck('id')->map(fn ($id) => (int) $id);
    }

    public function notify(iterable $userIds, string $title, ?string $body, string $level, string $route, ?int $linkId, string $dedupKey): void
    {
        foreach (collect($userIds)->filter()->unique() as $userId) {
            try {
                $exists = Notification::where('user_id', $userId)->where('dedup_key', $dedupKey)->exists();
                if ($exists) {
                    continue;
                }
                Notification::create([
                    'user_id'    => $userId,
                    'type'       => 'interlab',
                    'level'      => $level,
                    'title'      => mb_substr($title, 0, 190),
                    'body'       => $body !== null ? mb_substr($body, 0, 250) : null,
                    'link_route' => $route,
                    'link_id'    => $linkId,
                    'dedup_key'  => mb_substr($dedupKey, 0, 190),
                ]);
            } catch (Throwable) {
                // eslatma ixtiyoriy — asosiy jarayonni to'xtatmaydi
            }
        }
    }

    /** Audit jurnali. Kiruvchi xabarlarda foydalanuvchi bo'lmaydi (user_id = null). */
    public function audit(string $action, ?string $description, ?array $properties = null, ?string $modelType = null, ?int $modelId = null): void
    {
        try {
            ActivityLog::create([
                'user_id'     => auth()->id(),
                'action'      => $action,
                'model_type'  => $modelType,
                'model_id'    => $modelId,
                'description' => $description !== null ? mb_substr($description, 0, 1000) : null,
                'properties'  => $properties,
                'ip_address'  => request()?->ip(),
                'created_at'  => now(),
            ]);
        } catch (Throwable) {
            // jurnal ixtiyoriy
        }
    }
}
