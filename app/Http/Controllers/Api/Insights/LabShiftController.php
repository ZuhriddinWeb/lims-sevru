<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Concerns\LogsActivity;
use App\Http\Controllers\Controller;
use App\Models\LabShift;
use App\Services\Insights\ShiftCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Smenalar sozlamasi (Administratsiya → Smenalar).
 * Faol smenalar bir-birining ustiga tushmasligi kerak; tugash < boshlanish — tungi smena (yarim tundan o'tadi).
 */
class LabShiftController extends Controller
{
    use LogsActivity;

    public const MAX = 8;

    /** Migratsiya bajarilmagan bo'lsa — tushunarli xabar (500 o'rniga) */
    private function ensureTable(): void
    {
        if (! Schema::hasTable('lab_shifts')) {
            abort(response()->json([
                'message' => "Smenalar jadvali hali yaratilmagan. Serverda bajaring: php artisan migrate --force",
            ], 409));
        }
    }

    /** Har chaqiruvda yangi taqvim — smenalar o'zgargan bo'lishi mumkin */
    private function calendar(): ShiftCalendar
    {
        return new ShiftCalendar();
    }

    public function index()
    {
        $this->ensureTable();
        return response()->json([
            'data'     => LabShift::query()->orderBy('sort_order')->orderBy('start_time')->orderBy('id')->get()->map(fn ($s) => $this->row($s)),
            'current'  => ShiftCalendar::present($this->calendar()->current()),
            'previous' => ShiftCalendar::present($this->calendar()->previous()),
            'now'      => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /** Hamma foydalanuvchi uchun: faol smenalar va joriy smena (davr tanlagich, imzo soati) */
    public function active()
    {
        return response()->json([
            'data'     => $this->calendar()->list(),
            'current'  => ShiftCalendar::present($this->calendar()->current()),
            'previous' => ShiftCalendar::present($this->calendar()->previous()),
            'now'      => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureTable();
        if (LabShift::query()->count() >= self::MAX) {
            throw ValidationException::withMessages(['name' => ['Smenalar soni ' . self::MAX . ' tadan oshmasin']]);
        }
        $data = $this->validated($request);
        $this->assertNoOverlap($data);

        $shift = LabShift::create($data + ['sort_order' => $data['sort_order'] ?? ((int) LabShift::max('sort_order') + 1)]);
        $this->logActivity('created', $shift, $data, "Smena qo'shildi: {$shift->name} ({$shift->start_time}–{$shift->end_time})");

        return response()->json($this->row($shift), 201);
    }

    public function update(Request $request, LabShift $labShift)
    {
        $this->ensureTable();
        $data = $this->validated($request);
        $this->assertNoOverlap($data, $labShift->id);

        $before = $labShift->only(['name', 'start_time', 'end_time', 'is_active', 'sort_order']);
        $labShift->update($data);
        $this->logActivity('updated', $labShift, ['before' => $before, 'after' => $data],
            "Smena o'zgartirildi: {$labShift->name} ({$labShift->start_time}–{$labShift->end_time})");

        return response()->json($this->row($labShift->fresh()));
    }

    public function destroy(LabShift $labShift)
    {
        $this->ensureTable();
        $name = "{$labShift->name} ({$labShift->start_time}–{$labShift->end_time})";
        $labShift->delete();
        $this->logActivity('deleted', $labShift, null, "Smena o'chirildi: {$name}");

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request): array
    {
        $time = ['required', 'string', 'regex:' . ShiftCalendar::TIME_RE];
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:64'],
            'start_time' => $time,
            'end_time'   => $time,
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active'  => ['nullable', 'boolean'],
        ], [
            'start_time.regex' => 'Vaqt «SS:DD» ko\'rinishida bo\'lsin (masalan 08:00)',
            'end_time.regex'   => 'Vaqt «SS:DD» ko\'rinishida bo\'lsin (masalan 20:00)',
        ]);
        $data['name'] = trim($data['name']);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        if (! array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        return $data;
    }

    /** Faol smenalar ustma-ust tushmasin */
    private function assertNoOverlap(array $data, ?int $ignoreId = null): void
    {
        if (! $data['is_active']) {
            return;
        }
        $others = LabShift::query()->where('is_active', true)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->get();
        foreach ($others as $o) {
            if (ShiftCalendar::overlaps($data['start_time'], $data['end_time'], $o->start_time, $o->end_time)) {
                throw ValidationException::withMessages([
                    'start_time' => ["«{$o->name}» smenasi ({$o->start_time}–{$o->end_time}) bilan vaqti ustma-ust tushadi"],
                ]);
            }
        }
    }

    private function row(LabShift $s): array
    {
        return [
            'id'               => (int) $s->id,
            'name'             => $s->name,
            'start_time'       => $s->start_time,
            'end_time'         => $s->end_time,
            'sort_order'       => (int) $s->sort_order,
            'is_active'        => (bool) $s->is_active,
            'crosses_midnight' => $s->crossesMidnight(),
            'minutes'          => $s->minutes(),
        ];
    }
}
