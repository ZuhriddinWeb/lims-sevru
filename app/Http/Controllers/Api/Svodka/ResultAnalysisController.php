<?php

namespace App\Http\Controllers\Api\Svodka;

use App\Http\Controllers\Controller;
use App\Models\CustomerDepartment;
use App\Models\SvodkaSection;
use App\Services\Samples\CustomerPortal;
use App\Services\Svodka\ResultAnalysis;
use App\Services\Svodka\SvodkaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * [Paket 32] Natijalar tahlili (menyuda «AI tahlil») — laboratoriya rahbariyati uchun.
 * Kim ochadi: admin; «Natijalar tahlili — Ko'rish» ruxsati bor rol (standart: laboratoriya boshlig'i);
 * admin «Svodkani ko'ruvchilar» ga qo'shgan xodim. Buyurtmachi (portal) — yo'q.
 */
class ResultAnalysisController extends Controller
{
    public const PERMISSION = 'results.analysis';

    public function __construct(private SvodkaService $svc, private ResultAnalysis $analysis, private CustomerPortal $portal)
    {
    }

    private function guard(Request $request): void
    {
        $u = $request->user();
        abort_unless($this->svc->available(), 404, "Jamlanma hisobot moduli o'rnatilmagan");
        abort_if(! $u || $this->portal->isPortalUser($u), 403, 'Natijalar tahlili faqat laboratoriya rahbariyatiga ochiq');
        abort_unless($u->isAdmin() || $u->hasPermission(self::PERMISSION) || $this->svc->isViewer($u), 403,
            "Natijalar tahlili faqat laboratoriya rahbariyatiga ochiq — ruxsatni admin beradi");
    }

    /** Filtrlar: bo'limlar, ularning bloklari/nuqtalari, ko'rsatkichlar (natijasi borlar) */
    public function options(Request $request)
    {
        $this->guard($request);
        $depts = CustomerDepartment::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
        $sections = SvodkaSection::with(['points' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->whereIn('customer_department_id', $depts->pluck('id'))->orderBy('sort_order')->orderBy('id')->get();
        $symbols = DB::table('sample_elements')
            ->join('elements', 'elements.id', '=', 'sample_elements.element_id')
            ->join('samples', 'samples.id', '=', 'sample_elements.sample_id')
            ->where('sample_elements.status', 'done')->whereNotNull('sample_elements.result_value')
            ->whereNotNull('samples.customer_department_id')
            ->distinct()->orderBy('elements.symbol')->pluck('elements.symbol')
            ->map(fn ($s) => trim((string) $s))->filter()->unique(fn ($s) => mb_strtolower($s))->values();

        return response()->json([
            'departments' => $depts->map(fn ($d) => [
                'id' => (int) $d->id, 'code' => $d->code, 'name' => $d->name,
                'sections' => $sections->where('customer_department_id', $d->id)->map(fn ($s) => [
                    'id' => (int) $s->id, 'title' => $s->title,
                    'points' => $s->points->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name])->values(),
                ])->values(),
            ])->values(),
            'symbols'  => $symbols,
            'max_days' => ResultAnalysis::MAX_DAYS,
        ]);
    }

    public function analyze(Request $request)
    {
        $this->guard($request);
        $data = $request->validate([
            'department_id' => ['required', 'integer'],
            'from'          => ['required', 'date'],
            'to'            => ['required', 'date', 'after_or_equal:from'],
            'section_id'    => ['nullable', 'integer'],
            'point_id'      => ['nullable', 'integer'],
            'symbol'        => ['nullable', 'string', 'max:40'],
            'shift'         => ['nullable', 'integer', 'in:1,2'],
        ]);
        abort_unless(CustomerDepartment::whereKey($data['department_id'])->exists(), 404, "Bo'lim topilmadi");
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > ResultAnalysis::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => ['Davr ' . ResultAnalysis::MAX_DAYS . " kundan oshmasin"]]);
        }

        return response()->json($this->analysis->analyze($data));
    }
}
