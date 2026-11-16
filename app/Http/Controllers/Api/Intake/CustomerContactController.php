<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\CustomerContact;
use App\Models\CustomerDepartment;
use App\Services\Samples\CustomerContacts;
use App\Services\Samples\CustomerPortal;
use App\Services\Samples\SampleRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * [Paket 34] Bo'linma xodimlari ma'lumotnomasi («Kim olib keladi» + telefon).
 *
 *   GET    /api/portal/contacts?department_id=     ro'yxat (kombobox uchun)
 *   POST   /api/portal/contacts/check               {department_id, name, phone} → tozalangan yozuv + o'xshashlar
 *   PUT    /api/portal/contacts/{id}                F.I.Sh. / telefonni tuzatish
 *   DELETE /api/portal/contacts/{id}                noto'g'ri yozuvni o'chirish
 *
 * Ro'yxat bo'linmaga tegishli: buyurtmachi faqat o'z bo'linmalarinikini ko'radi va o'zgartiradi.
 * Laboratoriya xodimi (buyurtma tuzish / qabul) — istalgan bo'linmaniki, faqat o'qiydi (yozuv qabulda o'zi qo'shiladi).
 * Yo'l /api/portal/* — CustomerPortalFence buyurtmachiga ochiq qoldiradi.
 */
class CustomerContactController extends Controller
{
    public function __construct(private CustomerPortal $portal, private CustomerContacts $contacts)
    {
    }

    /** @return array{write: bool, ids: int[]|null} */
    private function ctx(Request $request): array
    {
        abort_unless(CustomerContacts::enabled(), 503, "Xodimlar ma'lumotnomasi jadvali yo'q — php artisan migrate");
        $user = $request->user();
        if ($this->portal->isPortalUser($user)) {
            return [
                'write' => $user->hasPermission(SampleRequests::PORTAL_PERMISSION),
                'ids'   => $this->portal->linkedDepartments($user)->pluck('id')->map(fn ($v) => (int) $v)->all(),
            ];
        }
        $staff = $user && ($this->portal->canPreview($user)
            || $user->hasPermission('sample_requests.view')
            || $user->hasPermission('sample_requests.create')
            || $user->hasPermission('sample_requests.receive'));
        abort_unless($staff, 403, "Ma'lumotnomani ko'rish uchun ruxsat yo'q");

        return ['write' => $user->isAdmin(), 'ids' => null];
    }

    private function department(Request $request, array $ctx): int
    {
        $id = (int) $request->input('department_id');
        if ($ctx['ids'] !== null) {
            if (! $id && count($ctx['ids']) === 1) {
                $id = $ctx['ids'][0];
            }
            if (! in_array($id, $ctx['ids'], true)) {
                throw ValidationException::withMessages(['department_id' => ["Bo'lim tanlanmagan"]]);
            }

            return $id;
        }
        if (! $id || ! CustomerDepartment::whereKey($id)->exists()) {
            throw ValidationException::withMessages(['department_id' => ["Bo'lim tanlanmagan"]]);
        }

        return $id;
    }

    private function find(int $id, array $ctx): CustomerContact
    {
        $c = CustomerContact::query()
            ->when($ctx['ids'] !== null, fn ($q) => $q->whereIn('customer_department_id', $ctx['ids'] ?: [0]))
            ->find($id);
        abort_unless($c, 404, 'Xodim topilmadi');

        return $c;
    }

    public function index(Request $request)
    {
        $ctx = $this->ctx($request);
        $dept = $this->department($request, $ctx);

        return response()->json([
            'data'      => $this->contacts->list($dept)->map(fn ($c) => CustomerContacts::present($c))->values(),
            'can_write' => $ctx['write'],
        ]);
    }

    /** Yozilgan F.I.Sh./telefonni tekshirish: tozalangan ko'rinish, aniq mos yozuv, o'xshashlar, telefon turi */
    public function check(Request $request)
    {
        $ctx = $this->ctx($request);
        $request->validate(['name' => ['nullable', 'string', 'max:191'], 'phone' => ['nullable', 'string', 'max:64']]);
        $dept = $this->department($request, $ctx);
        $m = $this->contacts->match($dept, $request->input('name'));
        $phone = CustomerContacts::phone($request->input('phone'));

        return response()->json([
            'name'        => $m['name'],
            'exact'       => $m['exact'] ? CustomerContacts::present($m['exact']) : null,
            'same_format' => $m['same_format'],
            'similar'     => array_map(fn ($s) => CustomerContacts::present($s['contact']) + ['reason' => $s['reason']], $m['similar']),
            'phone'       => ['value' => $phone['display'], 'kind' => $phone['kind']],
        ]);
    }

    public function update(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Ma'lumotnomani o'zgartirish uchun ruxsat yo'q");
        $c = $this->find($id, $ctx);
        $data = $request->validate(['name' => ['required', 'string', 'max:191'], 'phone' => ['nullable', 'string', 'max:64']]);

        $name = CustomerContacts::cleanName($data['name']);
        $key = CustomerContacts::nameKey($name);
        if ($key === '') {
            throw ValidationException::withMessages(['name' => ['F.I.Sh. kiriting']]);
        }
        $dup = CustomerContact::where('customer_department_id', $c->customer_department_id)
            ->where('name_key', $key)->whereKeyNot($c->id)->first();
        if ($dup) {
            throw ValidationException::withMessages(['name' => ["«{$dup->full_name}» ro'yxatda allaqachon bor — ortiqchasini o'chiring"]]);
        }
        $old = "{$c->full_name} (" . ($c->phone ?: '—') . ')';
        $c->forceFill([
            'full_name'  => $name,
            'name_key'   => $key,
            'phone'      => CustomerContacts::phoneValue($data['phone'] ?? null),
            'updated_by' => $request->user()->id,
        ])->save();
        $this->contacts->log($request->user(), $c, "Bo'linma xodimi tahrirlandi: {$old} → {$c->full_name} (" . ($c->phone ?: '—') . ')');

        return response()->json(CustomerContacts::present($c));
    }

    public function destroy(Request $request, int $id)
    {
        $ctx = $this->ctx($request);
        abort_unless($ctx['write'], 403, "Ma'lumotnomani o'zgartirish uchun ruxsat yo'q");
        $c = $this->find($id, $ctx);
        $this->contacts->log($request->user(), $c, "Bo'linma xodimi ro'yxatdan o'chirildi: {$c->full_name} (" . ($c->phone ?: '—') . ')');
        $c->delete();

        return response()->json(['ok' => true]);
    }
}
