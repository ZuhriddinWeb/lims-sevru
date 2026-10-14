<?php

namespace App\Http\Middleware;

use App\Models\Sample;
use App\Services\Samples\SampleIntake;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Probani ro'yxatga olish / tahrirlashda «Buyurtmachi va miqdor» maydonlarini saqlaydi —
 * SampleController.php ga qo'lda hech narsa qo'shmasdan.
 *
 *   POST /api/samples            — oldin tekshiradi, proba yaratilgach yozadi
 *   PUT  /api/samples/{sample}   — oldin tekshiradi, yangilangach yozadi
 * Faqat so'rovda kelgan maydonlar yoziladi (customer_department_id, customer_code,
 * received_quantity, quantity_unit). SampleController'ga qo'lda qo'shilgan bo'lsa ham
 * zarari yo'q — qiymatlar bir xil, takroriy yozish hech narsani o'zgartirmaydi.
 *
 * SampleIntakeServiceProvider 'api' guruhiga avtomatik qo'shadi.
 */
class SampleIntakeHook
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        $isStore = $request->isMethod('POST') && $path === 'api/samples';
        $isUpdate = $request->isMethod('PUT') && preg_match('#^api/samples/\d+$#', $path) === 1;

        if (! ($isStore || $isUpdate) || ! $this->hasIntakeInput($request) || ! $this->user()) {
            return $next($request);
        }

        $current = null;
        if ($isUpdate) {
            $param = $request->route('sample');
            $current = $param instanceof Sample ? $param : Sample::find((int) basename($path));
        }

        // Noto'g'ri qiymat — proba saqlanmasdan oldin 422 (Laravel'ning odatiy xato ko'rinishida)
        Validator::make($request->all(), SampleIntake::rules($current))->validate();

        $response = $next($request);

        if (! $response instanceof JsonResponse || ! in_array($response->getStatusCode(), [200, 201], true)) {
            return $response;
        }

        try {
            $data = $response->getData(true);
            $id = (int) ($data['id'] ?? 0);
            $sample = $id ? Sample::find($id) : null;
            if ($sample) {
                SampleIntake::apply($sample, $request->only(SampleIntake::FIELDS));
                $sample->load('customerDepartment:id,code,name');

                // Javobni yangilash — forma va ro'yxat darhol to'g'ri qiymatni ko'rsin
                foreach (SampleIntake::FIELDS as $key) {
                    $data[$key] = $sample->getAttribute($key);
                }
                $dept = $sample->customerDepartment;
                $data['customer_department'] = $dept ? ['id' => (int) $dept->id, 'code' => $dept->code, 'name' => $dept->name] : null;
                $response->setData($data);
            }
        } catch (Throwable $e) {
            report($e); // proba saqlangan — javobni buzmaymiz
        }

        return $response;
    }

    private function user()
    {
        try {
            return auth('sanctum')->user() ?? auth()->user();
        } catch (Throwable) {
            return null;
        }
    }

    private function hasIntakeInput(Request $request): bool
    {
        foreach (SampleIntake::FIELDS as $key) {
            if ($request->exists($key)) {
                return true;
            }
        }

        return false;
    }
}
