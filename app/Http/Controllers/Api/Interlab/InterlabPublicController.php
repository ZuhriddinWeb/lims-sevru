<?php

namespace App\Http\Controllers\Api\Interlab;

use App\Http\Controllers\Controller;
use App\Services\Interlab\Identity;
use App\Services\Interlab\Inbox;
use Illuminate\Http\Request;

/**
 * Serverlar o'rtasidagi ochiq nuqtalar (Sanctum'siz).
 * Xavfsizlik: har bir xabar imzolangan va shifrlangan; faqat ulangan laboratoriyalardan qabul qilinadi.
 */
class InterlabPublicController extends Controller
{
    public function identity(Identity $identity)
    {
        $me = $identity->get();
        if (! $me) {
            return response()->json(['app' => 'lims-interlab', 'configured' => false,
                'message' => 'Bu laboratoriya hali tarmoqqa sozlanmagan'], 503);
        }

        return response()->json($identity->publicInfo($me) + ['configured' => true]);
    }

    public function inbox(Request $request, Inbox $inbox)
    {
        $max = (int) config('interlab.max_envelope_bytes', 2 * 1024 * 1024);
        if (strlen((string) $request->getContent()) > $max) {
            return response()->json(['ok' => false, 'message' => 'Xabar juda katta', 'retryable' => false], 413);
        }

        $envelope = $request->json()->all();
        if (! is_array($envelope) || ! $envelope) {
            return response()->json(['ok' => false, 'message' => 'Bo\'sh xabar', 'retryable' => false], 400);
        }

        [$status, $body] = $inbox->receive($envelope, $request->ip());

        return response()->json($body, $status);
    }
}
