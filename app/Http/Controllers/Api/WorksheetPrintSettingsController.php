<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Worksheets\PrintStamps;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * [Paket 45] Ishchi varaq chop etish sozlamalari (QR va «Yaratdi / Tasdiqladi» bloki).
 *   GET /api/worksheet-print-settings   — varaq ko'ruvchilar va shablon boshqaruvchilar
 *   PUT /api/worksheet-print-settings   — faqat worksheet_templates.manage (admin)
 */
class WorksheetPrintSettingsController extends Controller
{
    public function __construct(private PrintStamps $stamps)
    {
    }

    public function show()
    {
        return response()->json(['settings' => $this->stamps->settings(), 'defaults' => PrintStamps::DEFAULTS, 'modes' => PrintStamps::MODES]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'sign_block' => ['sometimes', 'boolean'],
            'qr'         => ['sometimes', 'boolean'],
            'qr_mode'    => ['sometimes', Rule::in(PrintStamps::MODES)],
        ]);

        return response()->json(['settings' => $this->stamps->update($data, $request->user())]);
    }
}
