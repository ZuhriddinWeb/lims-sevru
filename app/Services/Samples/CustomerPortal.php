<?php

namespace App\Services\Samples;

use App\Models\CustomerDepartment;
use App\Models\Sample;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyurtmachi portali: bo'lim foydalanuvchisi faqat o'z bo'limi topshirgan probalarni KO'RADI.
 *
 * Portal foydalanuvchisi = admin emas va roli «portal.view» ruxsatiga ega (odatda «Buyurtmachi» roli).
 * Bunday foydalanuvchiga laboratoriyaning qolgan API'si yopiq (CustomerPortalFence).
 * Laboratoriya xodimlari (customer_departments.view ruxsati bilan) portalni istalgan bo'lim nomidan
 * «ko'rib chiqish» rejimida ochishi mumkin.
 */
class CustomerPortal
{
    public const PERMISSION = 'portal.view';
    public const PREVIEW_PERMISSION = 'customer_departments.view';

    /** @var array<int, bool> */
    private array $portalCache = [];

    public function isPortalUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $id = (int) $user->id;
        if (! array_key_exists($id, $this->portalCache)) {
            $this->portalCache[$id] = ! $user->isAdmin() && $user->hasPermission(self::PERMISSION);
        }

        return $this->portalCache[$id];
    }

    public function canPreview(User $user): bool
    {
        return ! $this->isPortalUser($user)
            && ($user->isAdmin() || $user->hasPermission(self::PREVIEW_PERMISSION));
    }

    /** Foydalanuvchi biriktirilgan faol bo'limlar */
    public function linkedDepartments(User $user)
    {
        if (! Schema::hasTable('customer_department_user')) {
            return collect();
        }

        return CustomerDepartment::query()
            ->where('is_active', true)
            ->whereIn('id', fn ($q) => $q->select('customer_department_id')->from('customer_department_user')->where('user_id', $user->id))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    /**
     * So'rov uchun ko'rinadigan bo'limlar.
     *   portal foydalanuvchisi — biriktirilgan bo'limlari (department_id bilan bittasini tanlashi mumkin)
     *   ko'rib chiquvchi xodim — department_id majburiy (istalgan bo'lim)
     * @return array{ids: int[], preview: bool}
     */
    public function scope(Request $request): array
    {
        $user = $request->user();
        $wanted = $request->filled('department_id') ? (int) $request->input('department_id') : null;

        if ($this->isPortalUser($user)) {
            $ids = $this->linkedDepartments($user)->pluck('id')->map(fn ($v) => (int) $v)->all();
            if ($wanted) {
                abort_unless(in_array($wanted, $ids, true), 403, "Bu bo'lim sizga biriktirilmagan");
                $ids = [$wanted];
            }

            return ['ids' => $ids, 'preview' => false];
        }

        abort_unless($user && $this->canPreview($user), 403, "Buyurtmachi portalini ko'rish uchun ruxsat yo'q");

        return ['ids' => $wanted ? [$wanted] : [], 'preview' => true];
    }

    public function assertCanSee(Request $request, Sample $sample): void
    {
        $scope = $this->scope($request);
        if ($scope['preview'] && ! $request->filled('department_id')) {
            // xodim — istalgan bo'lim probasini ko'rib chiqishi mumkin (faqat bo'limdan kelgan probalar)
            abort_unless($sample->customer_department_id, 404, 'Proba topilmadi');

            return;
        }
        abort_unless(
            $sample->customer_department_id && in_array((int) $sample->customer_department_id, $scope['ids'], true),
            404,
            'Proba topilmadi'
        );
    }

    /** Portal foydalanuvchisi uchun /me javobiga qo'shiladigan ma'lumot */
    public function profile(User $user): ?array
    {
        if (! $this->isPortalUser($user)) {
            return null;
        }

        return [
            'departments' => $this->linkedDepartments($user)->map(fn ($d) => [
                'id' => (int) $d->id, 'code' => $d->code, 'name' => $d->name,
            ])->values()->all(),
        ];
    }

    /** Bo'limga biriktirish mumkin bo'lgan foydalanuvchilar: roli portal.view ruxsatiga ega (admin emas) */
    public function eligibleUserIds(): array
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.name', self::PERMISSION)
            ->where('roles.name', '!=', 'admin')
            ->whereNull('users.deleted_at')
            ->pluck('users.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}
