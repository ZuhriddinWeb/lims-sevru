<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\User;
use App\Services\Samples\CustomerPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «Buyurtmachi bo'limlar» katalogi: zavoddagi proba topshiradigan bo'limlar
 * va ularning portalga kiradigan foydalanuvchilari.
 */
class CustomerDepartmentController extends Controller
{
    use LogsActivity;

    public function __construct(private CustomerPortal $portal)
    {
    }

    public function index(Request $request)
    {
        $query = CustomerDepartment::query()
            ->with(['users' => fn ($q) => $q->select('users.id', 'users.name', 'users.username', 'users.is_active')->orderBy('users.name')])
            ->withCount('samples');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('code', 'like', "%$search%")
                  ->orWhere('contact_person', 'like', "%$search%");
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $query->orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn (CustomerDepartment $d) => $this->row($d));
    }

    /** Proba ro'yxatga olish formasi uchun — faol bo'limlar */
    public function options()
    {
        return CustomerDepartment::query()->where('is_active', true)->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'code' => $d->code, 'name' => $d->name]);
    }

    /** Bo'limga biriktirish mumkin bo'lgan foydalanuvchilar («Buyurtmachi» roli) */
    public function portalUsers()
    {
        $ids = $this->portal->eligibleUserIds();
        if (! $ids) {
            return [];
        }
        $links = DB::table('customer_department_user')->whereIn('user_id', $ids)->get(['user_id', 'customer_department_id'])
            ->groupBy('user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')
            ->get(['id', 'name', 'username', 'position', 'is_active'])
            ->map(fn (User $u) => [
                'id'          => (int) $u->id,
                'name'        => $u->name,
                'username'    => $u->username,
                'position'    => $u->position,
                'is_active'   => (bool) $u->is_active,
                'departments' => $links->get($u->id, collect())->pluck('customer_department_id')->map(fn ($v) => (int) $v)->values(),
            ]);
    }

    public function store(Request $request)
    {
        [$data, $userIds] = $this->validated($request);

        $department = DB::transaction(function () use ($data, $userIds, $request) {
            $department = CustomerDepartment::create($data + ['created_by' => $request->user()->id]);
            $department->users()->sync($userIds);

            return $department;
        });

        $this->logActivity('created', $department, $data + ['user_ids' => $userIds], "Buyurtmachi bo'lim qo'shildi: {$department->name}");

        return response()->json($this->row($department->fresh()->load('users')->loadCount('samples')), 201);
    }

    public function update(Request $request, CustomerDepartment $customerDepartment)
    {
        [$data, $userIds] = $this->validated($request, $customerDepartment);

        DB::transaction(function () use ($customerDepartment, $data, $userIds) {
            $customerDepartment->update($data);
            if ($userIds !== null) {
                $customerDepartment->users()->sync($userIds);
            }
        });

        $this->logActivity('updated', $customerDepartment, $data + ['user_ids' => $userIds], "Buyurtmachi bo'lim yangilandi: {$customerDepartment->name}");

        return response()->json($this->row($customerDepartment->fresh()->load('users')->loadCount('samples')));
    }

    public function destroy(CustomerDepartment $customerDepartment)
    {
        $name = $customerDepartment->name;
        $samples = $customerDepartment->samples()->count();

        DB::transaction(function () use ($customerDepartment) {
            $customerDepartment->users()->detach();
            $customerDepartment->delete(); // probalardagi bog'lanish saqlanadi (soft delete)
        });

        $this->logActivity('deleted', $customerDepartment, ['samples' => $samples], "Buyurtmachi bo'lim o'chirildi: {$name}");

        return response()->json(['message' => "Bo'lim o'chirildi"]);
    }

    // ---------------------------------------------------------------- yordamchi

    /** @return array{0: array, 1: ?array} */
    private function validated(Request $request, ?CustomerDepartment $current = null): array
    {
        $data = $request->validate([
            'code'           => ['nullable', 'string', 'max:32',
                Rule::unique('customer_departments', 'code')->ignore($current?->id)],
            'name'           => ['required', 'string', 'max:255',
                Rule::unique('customer_departments', 'name')->ignore($current?->id)->whereNull('deleted_at')],
            'contact_person' => ['nullable', 'string', 'max:191'],
            'phone'          => ['nullable', 'string', 'max:64'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'is_active'      => ['nullable', 'boolean'],
            'user_ids'       => ['nullable', 'array', 'max:200'],
            'user_ids.*'     => ['integer'],
        ], [
            'code.unique' => "Bu belgi boshqa bo'limda (yoki o'chirilgan bo'limda) ishlatilgan",
            'name.unique' => "Bunday nomli bo'lim bor",
        ]);

        $userIds = null;
        if ($request->has('user_ids')) {
            $userIds = array_values(array_unique(array_map('intval', $data['user_ids'] ?? [])));
            $eligible = $this->portal->eligibleUserIds();
            $bad = array_diff($userIds, $eligible);
            if ($bad) {
                throw ValidationException::withMessages(['user_ids' => [
                    "Faqat «Buyurtmachi» roli (portal.view ruxsati) berilgan foydalanuvchini biriktirish mumkin",
                ]]);
            }
        }
        unset($data['user_ids']);

        $data['code'] = isset($data['code']) ? (trim($data['code']) ?: null) : null;
        $data['name'] = trim($data['name']);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return [$data, $userIds];
    }

    private function row(CustomerDepartment $d): array
    {
        return [
            'id'             => (int) $d->id,
            'code'           => $d->code,
            'name'           => $d->name,
            'contact_person' => $d->contact_person,
            'phone'          => $d->phone,
            'notes'          => $d->notes,
            'is_active'      => (bool) $d->is_active,
            'samples_count'  => (int) ($d->samples_count ?? 0),
            'users'          => $d->relationLoaded('users') ? $d->users->map(fn ($u) => [
                'id' => (int) $u->id, 'name' => $u->name, 'username' => $u->username, 'is_active' => (bool) $u->is_active,
            ])->values() : [],
            'user_ids'       => $d->relationLoaded('users') ? $d->users->pluck('id')->map(fn ($v) => (int) $v)->values() : [],
            'created_at'     => $d->created_at?->toDateTimeString(),
        ];
    }
}
