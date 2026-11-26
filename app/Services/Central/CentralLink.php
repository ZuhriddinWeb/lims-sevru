<?php

namespace App\Services\Central;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Insights\ExecutiveInsights;
use App\Services\Insights\InsightPeriod;
use App\Services\Insights\ShiftCalendar;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * [Paket 44] Markaziy portalga (НГМК LIMS portali) ma'lumot uzatish.
 *
 * Zavod serveri o'zi yuboradi (push): markaz zavod serveriga kira olmaydi, faqat qabul qiladi.
 * Faqat admin ruxsat bergan bloklar yuboriladi; namuna shifrlari, buyurtmachi nuqtalari va natija
 * qiymatlari hech qachon yuborilmaydi — faqat jamlangan sonlar (rahbariyat paneli bilan bir xil hisob).
 *
 * Himoya: markaz bergan kalit (ngmk1.<lab id>.<maxfiy>) — bazada shifrlangan holda (APP_KEY bilan);
 * har yuborish HMAC-SHA256 imzosi va vaqt belgisi bilan (o'zgartirish va qayta yuborishdan himoya).
 */
class CentralLink
{
    public const KEY = 'central.link';
    public const STATUS_KEY = 'central.link.status';
    public const VERSION = 1;
    public const INTERVALS = [5, 10, 15, 30, 60];

    /** Bloklar: kalit => sukut bo'yicha yoqilganmi (nomi va izohi — interfeysda, uch tilda) */
    public const DATASETS = [
        'summary'    => true,   // bugun va 30 kun: qabul, yakunlangan, tahlillar, ishda, kechikkan, bajarilish vaqti
        'trend'      => true,   // 30 kunlik kunlik dinamika
        'flow'       => true,   // hozirgi ish oqimi (bosqichlar bo'yicha soni)
        'shifts'     => true,   // joriy smena va bugungi smenalar
        'equipment'  => true,   // uskunalar tayyorligi, kalibrovkasi o'tganlar
        'worksheets' => false,  // ishchi varaqlar holati (sonlar)
        'requests'   => false,  // buyurtmalar holati (sonlar)
        'groups'     => false,  // bajaruvchi guruhlar yuklamasi (guruh nomlari bilan)
        'customers'  => false,  // buyurtmachi bo'limlar kesimida (bo'lim nomlari bilan)
    ];

    public function __construct(private ShiftCalendar $calendar)
    {
    }

    // ------------------------------------------------------------------ sozlamalar

    public function settings(): array
    {
        $s = $this->read(self::KEY);

        return [
            'enabled'  => (bool) ($s['enabled'] ?? false),
            'url'      => (string) ($s['url'] ?? ''),
            'key_enc'  => $s['key_enc'] ?? null,
            'datasets' => self::cleanDatasets($s['datasets'] ?? array_keys(array_filter(self::DATASETS))),
            'interval' => in_array((int) ($s['interval'] ?? 5), self::INTERVALS, true) ? (int) ($s['interval'] ?? 5) : 5,
        ];
    }

    /** Interfeysga: kalit o'zi hech qachon qaytarilmaydi — faqat boshi va oxiri */
    public function publicSettings(): array
    {
        $s = $this->settings();
        $key = $this->key();

        return [
            'enabled'   => $s['enabled'],
            'url'       => $s['url'],
            'datasets'  => $s['datasets'],
            'interval'  => $s['interval'],
            'key_set'   => $key !== null,
            'key_hint'  => $key ? self::hint($key) : null,
            'key_lab'   => $key ? self::labIdOf($key) : null,
            'all'       => array_keys(self::DATASETS),
            'defaults'  => array_keys(array_filter(self::DATASETS)),
            'intervals' => self::INTERVALS,
            'status'    => $this->status(),
        ];
    }

    public function update(array $v, User $user): array
    {
        $s = $this->settings();
        if (array_key_exists('enabled', $v)) {
            $s['enabled'] = (bool) $v['enabled'];
        }
        if (array_key_exists('url', $v)) {
            $s['url'] = rtrim(trim((string) $v['url']), '/');
        }
        if (array_key_exists('datasets', $v)) {
            $s['datasets'] = self::cleanDatasets((array) $v['datasets']);
        }
        if (array_key_exists('interval', $v) && in_array((int) $v['interval'], self::INTERVALS, true)) {
            $s['interval'] = (int) $v['interval'];
        }
        if (! empty($v['key'])) {
            $s['key_enc'] = Crypt::encryptString(trim((string) $v['key']));
        }
        if (! empty($v['clear_key'])) {
            $s['key_enc'] = null;
            $s['enabled'] = false;
        }
        $this->write(self::KEY, $s, $user->id);

        return $this->publicSettings();
    }

    public function key(): ?string
    {
        $enc = $this->settings()['key_enc'];
        if (! $enc) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (Throwable) {
            return null; // APP_KEY o'zgargan — kalitni qayta kiritish kerak
        }
    }

    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^ngmk1\.\d{1,9}\.[A-Za-z0-9]{32,128}$/', trim($key));
    }

    public static function labIdOf(string $key): ?int
    {
        return preg_match('/^ngmk1\.(\d+)\./', $key, $m) ? (int) $m[1] : null;
    }

    public static function hint(string $key): string
    {
        $parts = explode('.', $key);

        return ($parts[0] ?? '') . '.' . ($parts[1] ?? '') . '.' . substr($parts[2] ?? '', 0, 4) . '…' . substr($key, -4);
    }

    private static function cleanDatasets(array $list): array
    {
        return array_values(array_filter(array_keys(self::DATASETS), fn ($k) => in_array($k, $list, true)));
    }

    // ------------------------------------------------------------------ holat

    public function status(): array
    {
        return $this->read(self::STATUS_KEY) + [
            'last_at' => null, 'last_ok_at' => null, 'ok' => null, 'http' => null,
            'error' => null, 'bytes' => null, 'ms' => null, 'fails' => 0,
        ];
    }

    private function saveStatus(array $st): void
    {
        $this->write(self::STATUS_KEY, $st, null);
    }

    /** Jadval bo'yicha yuborish vaqti keldimi (interval o'tdimi) */
    public function due(): bool
    {
        $s = $this->settings();
        if (! $s['enabled'] || ! $s['url'] || ! $this->key()) {
            return false;
        }
        $last = $this->status()['last_at'];
        if (! $last) {
            return true;
        }
        try {
            // 30 soniya zaxira: scheduler daqiqasi biroz siljisa ham navbat o'tkazib yuborilmaydi
            return now()->diffInSeconds(\Carbon\Carbon::parse($last), true) >= $s['interval'] * 60 - 30;
        } catch (Throwable) {
            return true;
        }
    }

    // ------------------------------------------------------------------ yig'ish

    /**
     * Markazga ketadigan ma'lumot — faqat ruxsat berilgan bloklar. «heartbeat» (onlayn belgisi) har doim.
     */
    public function snapshot(?array $datasets = null): array
    {
        $datasets ??= $this->settings()['datasets'];
        $now = now();
        $out = [
            'v'            => self::VERSION,
            'generated_at' => $now->toIso8601String(),
            'timezone'     => config('app.timezone'),
            'app'          => ['name' => config('app.name'), 'package' => 44],
            'interval'     => $this->settings()['interval'], // markaz «onlayn» chegarasini shundan hisoblaydi
            'datasets'     => $datasets,
        ];
        if (! $datasets) {
            return $out;
        }

        $ins = app(ExecutiveInsights::class);
        $today = InsightPeriod::make('today', $this->calendar, $now);
        $month = InsightPeriod::make('custom', $this->calendar, $now,
            $now->copy()->subDays(29)->toDateString(), $now->toDateString());

        $need = fn (string ...$k) => (bool) array_intersect($k, $datasets);
        $todayBlocks = array_values(array_filter([
            $need('summary') ? 'kpi' : null, $need('shifts') ? 'by_shift' : null,
        ]));
        $monthBlocks = array_values(array_filter([
            $need('summary') ? 'kpi' : null, $need('trend') ? 'trend' : null, $need('flow') ? 'flow' : null,
            $need('equipment') ? 'equipment' : null, $need('worksheets') ? 'worksheets' : null,
            $need('groups') ? 'groups' : null, $need('customers') ? 'customers' : null,
        ]));
        $t = $todayBlocks ? $ins->build($today, [], $todayBlocks) : [];
        $m = $monthBlocks ? $ins->build($month, [], $monthBlocks) : [];

        if ($need('summary')) {
            $out['summary'] = ['today' => $t['kpi'] ?? null, 'month' => $m['kpi'] ?? null,
                'month_from' => $month->from->toDateString(), 'month_to' => $month->to->toDateString()];
        }
        if ($need('trend')) {
            $out['trend'] = $m['trend'] ?? null;
        }
        if ($need('flow') && is_array($m['flow'] ?? null)) {
            // namuna shifrlari zavoddan chiqmaydi — faqat sonlar
            $f = $m['flow'];
            $out['flow'] = ['stages' => $f['stages'] ?? [], 'open' => $f['open'] ?? 0, 'overdue_count' => $f['overdue_count'] ?? 0];
        }
        if ($need('shifts') && is_array($t['by_shift'] ?? null)) {
            $b = $t['by_shift'];
            $out['shifts'] = ['configured' => $b['configured'] ?? false, 'live' => $b['live'] ?? null, 'rows' => $b['rows'] ?? []];
        }
        if ($need('equipment')) {
            $out['equipment'] = $m['equipment'] ?? null;
        }
        if ($need('worksheets') && is_array($m['worksheets'] ?? null)) {
            $w = $m['worksheets'];
            unset($w['awaiting']['items']);
            $out['worksheets'] = $w;
        }
        if ($need('groups')) {
            $out['groups'] = $m['groups'] ?? null;
        }
        if ($need('customers')) {
            $out['customers'] = $m['customers'] ?? null;
        }
        if ($need('requests')) {
            $out['requests'] = $this->safely(fn () => $this->requests($month->from));
        }

        return $out;
    }

    /** Buyurtmalar holati — faqat sonlar */
    private function requests(\Carbon\Carbon $from): ?array
    {
        if (! Schema::hasTable('sample_requests')) {
            return null;
        }
        $q = fn () => DB::table('sample_requests');
        $hasSchedule = Schema::hasColumn('sample_requests', 'origin');
        $today = now()->startOfDay();

        return [
            'waiting'        => $q()->whereIn('status', ['submitted', 'partial'])->count(),
            'expired'        => $q()->where('status', 'expired')->count(),
            'submitted_today'=> $q()->whereNotNull('submitted_at')->where('submitted_at', '>=', $today)->count(),
            'received_today' => $q()->whereNotNull('received_at')->where('received_at', '>=', $today)->count(),
            'month'          => [
                'submitted' => $q()->whereNotNull('submitted_at')->where('submitted_at', '>=', $from)->count(),
                'received'  => $q()->whereIn('status', ['received', 'partial'])->where('submitted_at', '>=', $from)->count(),
                'rejected'  => $q()->where('status', 'rejected')->where('submitted_at', '>=', $from)->count(),
                'cancelled' => $q()->where('status', 'cancelled')->where('submitted_at', '>=', $from)->count(),
                'schedule'  => $hasSchedule ? $q()->where('origin', 'schedule')->where('submitted_at', '>=', $from)->count() : 0,
            ],
        ];
    }

    // ------------------------------------------------------------------ yuborish

    /**
     * Markazga yuborish. Natija holatga yoziladi; xato bo'lsa ham istisno chiqarmaydi (jadval to'xtamasin).
     */
    public function push(): array
    {
        $s = $this->settings();
        $key = $this->key();
        $st = $this->status();
        $started = microtime(true);
        $st['last_at'] = now()->toDateTimeString();

        if (! $s['url'] || ! $key) {
            $st = ['ok' => false, 'http' => null, 'error' => 'Markaz manzili yoki kalit kiritilmagan'] + $st;
            $this->saveStatus($st);

            return $st;
        }

        try {
            $body = json_encode($this->snapshot($s['datasets']), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $ts = (string) time();
            $res = Http::timeout(20)->connectTimeout(8)->acceptJson()
                ->withHeaders([
                    'X-Ngmk-Version'   => (string) self::VERSION,
                    'X-Ngmk-Lab'       => (string) self::labIdOf($key),
                    'X-Ngmk-Timestamp' => $ts,
                    'X-Ngmk-Signature' => hash_hmac('sha256', $ts . '.' . $body, $key),
                ])
                ->withBody($body, 'application/json')
                ->post($s['url'] . '/api/ingest');

            $ok = $res->successful() && ($res->json('ok') === true);
            $st['ok'] = $ok;
            $st['http'] = $res->status();
            $st['bytes'] = strlen($body);
            $st['error'] = $ok ? null : mb_substr((string) ($res->json('message') ?? $res->body()), 0, 300);
        } catch (Throwable $e) {
            $st['ok'] = false;
            $st['http'] = null;
            $st['error'] = mb_substr($e->getMessage(), 0, 300);
        }
        $st['ms'] = (int) round((microtime(true) - $started) * 1000);
        if ($st['ok']) {
            $st['last_ok_at'] = $st['last_at'];
            $st['fails'] = 0;
        } else {
            $st['fails'] = (int) ($st['fails'] ?? 0) + 1;
        }
        $this->saveStatus($st);

        return $st;
    }

    // ------------------------------------------------------------------ yordamchi

    private function safely(\Closure $fn): ?array
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function read(string $key): array
    {
        try {
            if (! Schema::hasTable('app_settings')) {
                return [];
            }
            $raw = AppSetting::where('key', $key)->value('value');

            return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function write(string $key, array $value, ?int $userId): void
    {
        AppSetting::updateOrCreate(['key' => $key], ['value' => json_encode($value, JSON_UNESCAPED_UNICODE)] + ($userId ? ['updated_by' => $userId] : []));
    }
}
