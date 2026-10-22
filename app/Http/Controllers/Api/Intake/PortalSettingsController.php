<?php

namespace App\Http\Controllers\Api\Intake;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Samples\PortalVisibility;
use Illuminate\Http\Request;

/**
 * «Portal sozlamalari» — buyurtmachi portalida nima ko'rinishini admin belgilaydi. [Paket 27]
 *   GET /api/portal-settings   PUT /api/portal-settings      (ruxsat: portal.settings — admin'da doim bor)
 */
class PortalSettingsController extends Controller
{
    public function __construct(private PortalVisibility $visibility)
    {
    }

    public function show()
    {
        return response()->json([
            'visibility' => $this->visibility->get(),
            'defaults'   => PortalVisibility::DEFAULTS,
        ] + $this->visibility->meta());
    }

    public function update(Request $request)
    {
        $rules = [];
        foreach (array_keys(PortalVisibility::DEFAULTS) as $k) {
            $rules["visibility.$k"] = ['sometimes', 'boolean'];
        }
        $data = $request->validate(['visibility' => ['required', 'array']] + $rules);

        $before = $this->visibility->get();
        $after = $this->visibility->set($data['visibility'], $request->user());

        $changed = array_keys(array_diff_assoc(array_map('intval', $after), array_map('intval', $before)));
        if ($changed) {
            try {
                ActivityLog::create([
                    'user_id'     => $request->user()->id,
                    'action'      => 'updated',
                    'model_type'  => 'PortalSettings',
                    'model_id'    => null,
                    'description' => "Portal sozlamalari o'zgartirildi: " . implode(', ', array_map(fn ($k) => $k . '=' . ($after[$k] ? 'ha' : "yo'q"), $changed)),
                    'properties'  => ['before' => $before, 'after' => $after],
                    'ip_address'  => $request->ip(),
                    'created_at'  => now(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['visibility' => $after, 'defaults' => PortalVisibility::DEFAULTS] + $this->visibility->meta());
    }
}
