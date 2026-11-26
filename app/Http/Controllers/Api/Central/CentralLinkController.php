<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Controller;
use App\Services\Central\CentralLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * [Paket 44] Administratsiya → «Markaziy portal»: nima yuborilishini admin hal qiladi.
 */
class CentralLinkController extends Controller
{
    public function __construct(private CentralLink $link)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->link->publicSettings());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'    => ['sometimes', 'boolean'],
            'url'        => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^https?://[^\s/$.?\#].[^\s]*$#i'],
            'datasets'   => ['sometimes', 'array'],
            'datasets.*' => ['string'],
            'interval'   => ['sometimes', 'integer'],
            'key'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'clear_key'  => ['sometimes', 'boolean'],
        ], [
            'url.regex' => "Manzil http:// yoki https:// bilan boshlanishi kerak (masalan http://192.168.14.23:5000)",
        ]);
        if (! empty($data['key']) && ! CentralLink::validKey($data['key'])) {
            throw ValidationException::withMessages(['key' => ["Kalit noto'g'ri: markaziy portal admin panelidagi «Ulanish kaliti» ni to'liq nusxalang (ngmk1.… bilan boshlanadi)"]]);
        }
        $s = $this->link->settings();
        $willEnable = empty($data['clear_key']) && ($data['enabled'] ?? $s['enabled']); // kalit o'chirilsa — yuborish ham o'chadi
        $url = array_key_exists('url', $data) ? (string) $data['url'] : $s['url'];
        $hasKey = ! empty($data['key']) || ($this->link->key() !== null && empty($data['clear_key']));
        if ($willEnable && (! $url || ! $hasKey)) {
            throw ValidationException::withMessages(['enabled' => ['Yoqish uchun markaz manzili va ulanish kaliti kerak']]);
        }

        return response()->json($this->link->update($data, $request->user()));
    }

    /** Yuboriladigan ma'lumot — admin ko'rib chiqishi uchun (yubormaydi) */
    public function preview(Request $request): JsonResponse
    {
        $raw = $request->input('datasets');
        $datasets = $raw === null ? null
            : array_values(array_intersect(array_keys(CentralLink::DATASETS), is_array($raw) ? $raw : explode(',', (string) $raw)));
        $snap = $this->link->snapshot($datasets);

        return response()->json(['payload' => $snap, 'bytes' => strlen(json_encode($snap, JSON_UNESCAPED_UNICODE))]);
    }

    /** Hozir yuborish (sinash) */
    public function push(): JsonResponse
    {
        $s = $this->link->settings();
        if (! $s['url'] || ! $this->link->key()) {
            throw ValidationException::withMessages(['url' => ['Avval markaz manzili va ulanish kalitini saqlang']]);
        }
        $st = $this->link->push();

        return response()->json(['status' => $st] + $this->link->publicSettings(), $st['ok'] ? 200 : 502);
    }
}
