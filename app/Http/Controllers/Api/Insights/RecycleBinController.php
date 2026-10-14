<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Services\Insights\RecycleBin;
use Illuminate\Http\Request;

/**
 * [P19] Savatcha (ruxsat: trash.view; tiklash — trash.restore; marshrutda).
 *   GET  /api/recycle-bin                       — turlar va soni
 *   GET  /api/recycle-bin/{type}                — ro'yxat (q, from, to, deleted_by), kim o'chirgani bilan
 *   GET  /api/recycle-bin/{type}/{id}           — tiklashdan oldin: ogohlantirishlar va to'siqlar
 *   POST /api/recycle-bin/{type}/{id}/restore   — tiklash (audit jurnaliga «Tiklash» yoziladi)
 * Butunlay o'chirish yo'q (ISO/IEC 17025).
 */
class RecycleBinController extends Controller
{
    private function locale(Request $request): string
    {
        return in_array($request->input('locale'), ['uz', 'ru', 'en'], true) ? $request->input('locale') : 'uz';
    }

    public function counts(RecycleBin $bin)
    {
        $types = $bin->counts();

        return response()->json(['types' => $types, 'total' => array_sum(array_column($types, 'count'))]);
    }

    public function index(Request $request, RecycleBin $bin, string $type)
    {
        $request->validate([
            'q'          => ['nullable', 'string', 'max:100'],
            'from'       => ['nullable', 'date_format:Y-m-d'],
            'to'         => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'deleted_by' => ['nullable', 'regex:/^(\d{1,18}|unknown)$/'],
            'page'       => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        return response()->json($bin->page(
            $type,
            $request->only(['q', 'from', 'to', 'deleted_by']),
            (int) $request->input('page', 1),
            (int) $request->input('per_page', 25),
            $this->locale($request),
        ));
    }

    public function show(Request $request, RecycleBin $bin, string $type, int $id)
    {
        return response()->json($bin->preview($type, $id, $this->locale($request)));
    }

    public function restore(Request $request, RecycleBin $bin, string $type, int $id)
    {
        return response()->json($bin->restore($type, $id, $request->user(), $this->locale($request)));
    }
}
