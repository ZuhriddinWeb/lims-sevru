<?php

namespace App\Http\Middleware;

use App\Services\Samples\CustomerPortal;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Buyurtmachi (portal) foydalanuvchisi uchun «devor».
 *
 * Laboratoriya API'sining ko'p qismi ruxsat bilan yopiq, lekin bir nechta umumiy marshrut
 * (bosh sahifa statistikasi, kataloglar ro'yxati va h.k.) faqat login talab qiladi.
 * Portal foydalanuvchisi ulardan ham laboratoriya ma'lumotini ko'rmasligi uchun
 * unga faqat ro'yxatdagi marshrutlar ochiq: kirish/chiqish, profil, eslatmalar, menyu va /api/portal/*.
 *
 * Qo'shimcha: /api/me va /api/login javobiga user.portal (bo'limlari) qo'shiladi —
 * interfeys shu orqali buyurtmachini portal sahifasiga yo'naltiradi;
 * /api/menu javobida portal foydalanuvchisiga faqat portal bandlari qoladi.
 *
 * SampleIntakeServiceProvider 'api' guruhiga avtomatik qo'shadi — routes/api.php ga tegilmaydi.
 */
class CustomerPortalFence
{
    /** Portal foydalanuvchisiga ochiq yo'llar ('api/' dan keyingi qism) */
    private const ALLOWED = [
        'login', 'logout', 'me', 'profile', 'preferences', 'menu',
        'notifications', 'notifications/*',
        'portal', 'portal/*',
    ];

    private const DECORATE = ['api/login', 'api/me', 'api/profile'];

    public function __construct(private CustomerPortal $portal)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        if (! str_starts_with($path, 'api/')) {
            return $next($request);
        }

        $user = $this->user();
        $isPortal = $user && $this->portal->isPortalUser($user);

        if ($isPortal && ! $this->allowed(substr($path, 4))) {
            return response()->json([
                'message' => "Buyurtmachi hisobi uchun bu bo'lim yopiq",
                'portal'  => true,
            ], 403);
        }

        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() >= 400) {
            return $response;
        }

        try {
            if (in_array($path, self::DECORATE, true)) {
                $this->decorateUser($response);
            } elseif ($path === 'api/menu' && $isPortal) {
                $this->filterMenu($response);
            } elseif ($path === 'api/menu' && $user) {
                $this->addViewerMenu($response, $user);
                $this->dedupeStaffMenu($response);
            }
        } catch (Throwable $e) {
            report($e); // javobni buzmaymiz
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

    private function allowed(string $rest): bool
    {
        foreach (self::ALLOWED as $pattern) {
            if ($rest === $pattern || (str_ends_with($pattern, '/*') && str_starts_with($rest, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    private function isSvodkaViewer($user): bool
    {
        try {
            return class_exists(\App\Services\Svodka\SvodkaService::class)
                && ! $this->portal->isPortalUser($user)
                && app(\App\Services\Svodka\SvodkaService::class)->isViewer($user);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * [P31] «Svodkani ko'ruvchilar»dagi xodim menyusiga «Bo'limlar svodkasi» (rolida svodka.view bo'lmasa ham)
     * [P32] va «Natijalar tahlili» (results.analysis)
     */
    private function addViewerMenu(JsonResponse $response, $user): void
    {
        $data = $response->getData(true);
        if (! is_array($data) || ! array_is_list($data) || ! $this->isSvodkaViewer($user)) {
            return;
        }
        $have = [];
        foreach ($data as $it) {
            if (is_array($it)) {
                $have[] = $it['permission'] ?? null;
            }
        }
        $add = array_values(array_diff(['svodka.view', 'results.analysis'], $have));
        if (! $add) {
            return;
        }
        $items = \App\Models\MenuItem::whereIn('permission', $add)->where('is_active', true)->whereNull('parent_id')->get();
        if ($items->isEmpty()) {
            return;
        }
        foreach ($items as $item) {
            $data[] = $item->setRelation('children', collect())->toArray();
        }
        usort($data, fn ($a, $b) => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)));
        $response->setData($data);
    }

    /**
     * [P31] Xodim (admin) menyusida bir sahifaga ikki band bo'lsa (masalan, «Svodka» — portal bandi va
     * «Bo'limlar svodkasi» — svodka.view), portal.* bandi olib tashlanadi.
     */
    private function dedupeStaffMenu(JsonResponse $response): void
    {
        $data = $response->getData(true);
        if (! is_array($data) || ! array_is_list($data)) {
            return;
        }
        $isPortalItem = fn ($it) => is_array($it) && str_starts_with((string) ($it['permission'] ?? ''), 'portal.');
        $staffRoutes = [];
        foreach ($data as $it) {
            if (is_array($it) && ! empty($it['route']) && ! $isPortalItem($it)) {
                $staffRoutes[$it['route']] = true;
            }
        }
        $out = array_values(array_filter($data, fn ($it) => ! $isPortalItem($it) || empty($it['route']) || ! isset($staffRoutes[$it['route']])));
        if (count($out) !== count($data)) {
            $response->setData($out);
        }
    }

    private function decorateUser(JsonResponse $response): void
    {
        $data = $response->getData(true);
        if (! is_array($data) || ! isset($data['user']) || ! is_array($data['user'])) {
            return;
        }
        $user = $this->user();
        if (! $user || (int) $user->id !== (int) ($data['user']['id'] ?? 0)) {
            return;
        }
        $data['user']['portal'] = $this->portal->profile($user);
        // [P31] admin svodkani ko'rishni shu foydalanuvchiga alohida bergan — rolga tegmasdan ruxsatlar ro'yxatiga qo'shiladi
        // [P32] ular natijalar tahlilini ham ko'radi
        if (isset($data['user']['permissions']) && is_array($data['user']['permissions']) && $this->isSvodkaViewer($user)) {
            foreach (['svodka.view', 'results.analysis'] as $perm) {
                if (! in_array($perm, $data['user']['permissions'], true)) {
                    $data['user']['permissions'][] = $perm;
                }
            }
        }
        $response->setData($data);
    }

    private function filterMenu(JsonResponse $response): void
    {
        $data = $response->getData(true);
        if (! is_array($data)) {
            return;
        }
        $keep = fn ($item) => is_array($item) && str_starts_with((string) ($item['permission'] ?? ''), 'portal.');

        $out = [];
        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! empty($item['children'])) {
                $item['children'] = array_values(array_filter($item['children'], $keep));
                if ($item['children']) {
                    $out[] = $item;
                }
            } elseif ($keep($item)) {
                $out[] = $item;
            }
        }
        $response->setData($out);
    }
}
