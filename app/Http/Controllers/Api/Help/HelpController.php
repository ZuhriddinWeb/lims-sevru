<?php

namespace App\Http\Controllers\Api\Help;

use App\Http\Controllers\Controller;
use App\Services\Help\HelpAssistant;
use Illuminate\Http\Request;

/**
 * Yordamchi (lokal AI). Barcha tizimga kirgan foydalanuvchilar uchun; javoblar ruxsatga qarab saralanadi.
 *   GET  /api/help/suggestions?locale=&route=   — boshlang'ich takliflar
 *   POST /api/help/ask                           — savol berish
 *   GET  /api/help/entries/{id}?locale=          — tanlangan taklifning javobi
 *   POST /api/help/feedback                      — «foydali / foydasiz»
 */
class HelpController extends Controller
{
    private function common(): array
    {
        return [
            'locale' => ['nullable', 'in:uz,ru,en'],
            'route' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ];
    }

    public function suggestions(Request $request, HelpAssistant $help)
    {
        $request->validate($this->common());

        return response()->json([
            'suggestions' => $help->suggestions($request->user(), (string) $request->input('locale', 'uz'), $request->input('route')),
        ]);
    }

    public function ask(Request $request, HelpAssistant $help)
    {
        $request->validate($this->common() + ['question' => ['required', 'string', 'min:2', 'max:300']]);

        return response()->json($help->ask(
            $request->user(),
            (string) $request->input('question'),
            (string) $request->input('locale', 'uz'),
            $request->input('route'),
        ));
    }

    public function entry(Request $request, HelpAssistant $help, int $id)
    {
        $request->validate($this->common());
        $entry = $help->entry($request->user(), $id, (string) $request->input('locale', 'uz'));
        abort_if($entry === null, 404);

        return response()->json(['answer' => $entry]);
    }

    public function feedback(Request $request, HelpAssistant $help)
    {
        $data = $request->validate($this->common() + [
            'entry_id' => ['required', 'integer', 'min:1'],
            'helpful' => ['required', 'boolean'],
            'question' => ['nullable', 'string', 'max:400'],
        ]);
        $help->feedback($request->user(), (int) $data['entry_id'], (bool) $data['helpful'], $data['question'] ?? null, $data['locale'] ?? 'uz');

        return response()->json(['ok' => true]);
    }
}
