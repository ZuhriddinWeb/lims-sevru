<?php

namespace App\Console\Commands;

use App\Models\CustomerDepartment;
use App\Models\Element;
use App\Models\InterlabPeer;
use App\Models\Role;
use App\Models\Sample;
use App\Models\SampleElement;
use App\Models\SvodkaPoint;
use App\Models\User;
use App\Services\Interlab\Crypto;
use App\Services\Interlab\Identity;
use App\Services\Interlab\ReferralService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * FAQAT LOKAL SINOV BAZASI UCHUN: har jadvalga 2–3 tadan namunaviy (to'qima) ma'lumot.
 *
 *   php artisan lims:demo-malumot
 *
 * Shart: yangi baza — migrate + db:seed + lims:upgrade dan keyin; probalar va buyurtmalar jadvali BO'SH bo'lishi
 * kerak (ishlab turgan bazada ishlamaydi — o'zi to'xtaydi). APP_ENV=production bo'lsa ham to'xtaydi.
 *
 * Asosiy jarayon haqiqiy API orqali yuradi (buyurtma → qabul → natija → ishchi varaq → tasdiq → jamlanma hisobot),
 * shuning uchun bog'liq jadvallar (probalar, elementlar, svodka biriktirish, bildirishnomalar, audit, xodimlar
 * ro'yxati) o'zi to'ladi. Qolgan modullar (uskunalar, sinov obyektlari, suv laboratoriyasi, laboratoriyalararo,
 * yordamchi) — to'g'ridan-to'g'ri yoziladi. Ismlar va raqamlar to'qima.
 */
class DemoData extends Command
{
    protected $signature = 'lims:demo-malumot';

    protected $description = "LOKAL sinov bazasi uchun namunaviy ma'lumot (har jadvalga 2–3 tadan). Ishlab turgan bazada ishlamaydi";

    private const PASS = 'lims12345';

    /** Haqiqiy bugungi sana (Carbon::setTestNow dan oldin olinadi) */
    private Carbon $today;

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error("APP_ENV=production — bu buyruq faqat lokal sinov bazasi uchun. Hech narsa o'zgarmadi.");

            return self::FAILURE;
        }
        foreach (['samples', 'sample_requests', 'worksheets', 'equipment'] as $t) {
            if (! Schema::hasTable($t)) {
                $this->error("«{$t}» jadvali yo'q — avval: php artisan migrate, php artisan db:seed, php artisan lims:upgrade");

                return self::FAILURE;
            }
            if (DB::table($t)->exists()) {
                $this->error("«{$t}» jadvalida ma'lumot bor — bu yangi (bo'sh) baza emas. Ishlab turgan bazani himoya qilish uchun to'xtadim. Hech narsa o'zgarmadi.");

                return self::FAILURE;
            }
        }
        if (! User::where('username', 'mirzoyeva')->exists() || ! Schema::hasTable('svodka_points')) {
            $this->error('Avval: php artisan db:seed va php artisan lims:upgrade');

            return self::FAILURE;
        }

        Carbon::setTestNow();
        $this->today = Carbon::today();
        $this->line("Database: " . DB::connection()->getDatabaseName());
        Cache::flush();
        mt_srand(20261112);

        try {
            $this->people();
            $this->workflow();
            $this->equipment();
            $this->testObjects();
            $this->water();
            $this->interlab();
            $this->help();
        } finally {
            Carbon::setTestNow();
        }

        $this->line('');
        $this->info("Tayyor. Jadvallar bo'yicha qatorlar soni:");
        $skip = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens', 'personal_access_tokens'];
        foreach ($this->tables() as $t) {
            if (! in_array($t, $skip, true)) {
                $this->line(sprintf('  %-34s %5d', $t, DB::table($t)->count()));
            }
        }
        $this->line('');
        $this->line("Kirish: admin (parolingiz) · laboratoriya: mirzoyeva, shamsiyev, qabul · buyurtmachi: otk, ttg · rahbar: rahbar");
        $this->line("Yangi foydalanuvchilar paroli: " . self::PASS);

        return self::SUCCESS;
    }

    /* =============================================================== yordamchilar */

    private function day(int $ago = 0): Carbon
    {
        return $this->today->copy()->subDays($ago);
    }

    private function at(string $date, string $time = '09:00'): void
    {
        Carbon::setTestNow(Carbon::parse("$date $time"));
    }

    /** Ilova API'sini ichkaridan chaqirish (haqiqiy validatsiya va hodisalar bilan) */
    private function api(string $username, string $method, string $uri, array $data = []): array
    {
        $app = app();
        $kernel = $app->make(HttpKernel::class);
        $app['auth']->forgetGuards();
        foreach ($app['router']->getRoutes() as $r) {
            $r->flushController();
        }
        $app->forgetScopedInstances();
        $u = User::where('username', $username)->firstOrFail();
        $app['auth']->guard('sanctum')->setUser($u);
        $app['auth']->shouldUse('sanctum');
        $req = Request::create($uri, $method, $method === 'GET' ? $data : [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1',
        ], $method === 'GET' ? null : json_encode($data));
        $res = $kernel->handle($req);
        $kernel->terminate($req, $res);
        $json = json_decode($res->getContent(), true) ?? [];
        if ($res->getStatusCode() >= 400) {
            $this->warn("  ! {$method} {$uri} → {$res->getStatusCode()} " . mb_substr($res->getContent(), 0, 300));
        }

        return [$res->getStatusCode(), $json];
    }

    private function tables(): array
    {
        $names = array_map(fn ($t) => is_array($t) ? ($t['name'] ?? '') : (string) $t, Schema::getTables());
        sort($names);

        return array_values(array_filter($names, fn ($n) => $n !== '' && ! str_starts_with($n, 'sqlite_')));
    }

    private function el(string $symbol): int
    {
        return (int) Element::where('symbol', $symbol)->value('id');
    }

    private function placeholder(string $disk, string $path, string $title): string
    {
        // kichik, ochiladigan PDF (sinov uchun)
        $text = str_replace(['(', ')'], '', $title);
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n";
        $stream = "BT /F1 18 Tf 60 780 Td ({$text}) Tj ET";
        $pdf .= '4 0 obj<</Length ' . strlen($stream) . ">>stream\n{$stream}\nendstream endobj\n5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        Storage::disk($disk)->put($path, $pdf);

        return $path;
    }

    /* =============================================================== odamlar, bo'limlar */

    private function people(): void
    {
        $this->info("1/7 Foydalanuvchilar, bo'limlar, smenalar, jamlanma hisobot spravochnigi");
        $this->at($this->day(5)->toDateString(), '08:00');
        $role = fn ($n) => Role::where('name', $n)->value('id');
        $mk = fn ($u, $n, $r, $g = null, $lab = true) => User::updateOrCreate(['username' => $u], [
            'name' => $n, 'password' => self::PASS, 'role_id' => $role($r), 'laboratory_group_id' => $g,
            'laboratory_id' => $lab ? DB::table('laboratories')->value('id') : null,
            'is_active' => true, 'locale' => 'uz', 'theme' => 'light',
        ]);
        $mk('qabul', 'Tursunov Bekzod (demo)', 'operator', 1);
        $mk('otk', 'Xolmatov Anvar (demo buyurtmachi)', 'customer', null, false);
        $mk('ttg', 'Ergasheva Nodira (demo buyurtmachi)', 'customer', null, false);
        $rahbar = $mk('rahbar', 'Abdullayev Jamshid (demo rahbar)', 'viewer', null, false);

        $tnb = CustomerDepartment::firstOrCreate(['code' => 'TNBGMZ3'], ['name' => 'TNB GMZ-3 OTK (demo)', 'contact_person' => 'Xolmatov A.', 'phone' => '+998 79 227-41-08', 'is_active' => true]);
        $ttg = CustomerDepartment::firstOrCreate(['code' => 'TTGDEMO'], ['name' => 'TTG — tadqiqot guruhi (demo)', 'contact_person' => 'Ergasheva N.', 'is_active' => true]);
        foreach ([[$tnb->id, 'otk'], [$ttg->id, 'ttg']] as [$d, $u]) {
            DB::table('customer_department_user')->updateOrInsert(
                ['customer_department_id' => $d, 'user_id' => User::where('username', $u)->value('id')],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
        foreach ([['1-smena', '08:00', '20:00'], ['2-smena', '20:00', '08:00']] as $i => [$n, $s, $e]) {
            DB::table('lab_shifts')->updateOrInsert(['name' => $n], ['start_time' => $s, 'end_time' => $e, 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        Artisan::call('svodka:spravochnik', ['bolim' => 'TNBGMZ3']);
        $this->api('mirzoyeva', 'PUT', "/api/svodka-releases/settings/{$tnb->id}", [
            'enabled' => true, 'release_mode' => 'day', 'shift1_label' => 'I-navbatchilik', 'shift2_label' => 'II-navbatchilik',
            'shift1_start' => '20:00', 'shift2_start' => '08:00', 'title' => 'GMZ-3 kunlik jamlanma hisoboti (demo)',
        ]);
        $this->api('admin', 'POST', '/api/svodka-releases/viewers', ['user_id' => $rahbar->id]);
        $this->api('admin', 'POST', '/api/svodka-releases/viewers', ['user_id' => User::where('username', 'shamsiyev')->value('id')]);
    }

    /* =============================================================== asosiy jarayon */

    private function workflow(): void
    {
        $this->info('2/7 Buyurtmalar → qabul → natijalar → ishchi varaq → tasdiq → jamlanma hisobot');
        $AU = $this->el('Au');
        $AG = $this->el('Ag');
        $S = $this->el('S') ?: $AG;
        $tnb = CustomerDepartment::where('code', 'TNBGMZ3')->first();
        $ttg = CustomerDepartment::where('code', 'TTGDEMO')->first();
        $P = SvodkaPoint::where('customer_department_id', $tnb->id)->orderBy('sort_order')->orderBy('id')->get();
        $pt = fn (string $like) => $P->first(fn ($p) => str_contains($p->name, $like)) ?? $P->first();
        $points = [
            [$pt('"K" SAF'), [$AU => [1.85, 0.06], $AG => [3.1, 0.25], $S => [2.0, 0.08]], 'solid'],
            [$pt('"K" fl.chiqindisi'), [$AU => [0.40, 0.02], $AG => [0.9, 0.08]], 'solid'],
            [$pt('"K" Fl.Boyit.'), [$AU => [25.8, 0.8], $AG => [38.0, 2.0]], 'solid'],
        ];

        // shablonlar (P33)
        $this->at($this->day(4)->toDateString(), '08:05');
        $tplItems = array_map(fn ($p) => ['customer_code' => '', 'state_of_matter' => $p[2], 'element_ids' => array_keys($p[1]), 'svodka_point_id' => $p[0]->id, 'quantity' => 150, 'quantity_unit' => 'g'], $points);
        $this->api('otk', 'POST', '/api/portal/request-templates', ['name' => 'I-navbatchilik — asosiy', 'payload' => ['laboratory_group_id' => 1, 'contact_name' => 'Rahimov Akmal', 'contact_phone' => '+998 90 111-22-33', 'items' => $tplItems]]);
        $this->api('otk', 'POST', '/api/portal/request-templates', ['name' => 'Faqat chiqindilar', 'payload' => ['laboratory_group_id' => 1, 'items' => [$tplItems[1]]]]);
        $this->api('ttg', 'POST', '/api/portal/request-templates', ['name' => 'Reagent sinovi', 'payload' => ['laboratory_group_id' => 4, 'items' => [['customer_code' => '', 'state_of_matter' => 'solid', 'element_ids' => [$AU, $AG]]]]]);

        $couriers = [['Rahimov Akmal', '90 111 22 33'], ['Sobirov Jasur', '4523'], ['Rahimov Akmal', '+998 91 555 44 33']];
        $value = fn (float $mu, float $sd) => round(max($mu + $sd * (mt_rand(-100, 100) / 100), 0.01), $mu >= 10 ? 1 : 2);
        $code = 900;
        $wsSamples = [];
        for ($k = 3; $k >= 0; $k--) {           // 3 kun oldin … bugun
            $date = $this->day($k)->toDateString();
            $this->at($date, '08:20');
            $items = [];
            foreach ($points as [$p, $els, $state]) {
                $items[] = ['customer_code' => (string) ($code += 2), 'state_of_matter' => $state, 'element_ids' => array_keys($els),
                    'svodka_point_id' => $p->id, 'quantity' => 150, 'quantity_unit' => 'g'];
            }
            [$c, $ph] = $couriers[$k % 3];
            [$st, $r] = $this->api('otk', 'POST', '/api/portal/requests', [
                'department_id' => $tnb->id, 'laboratory_group_id' => 1, 'svodka_date' => $date, 'svodka_shift' => 1,
                'contact_name' => $c, 'contact_phone' => $ph, 'items' => $items, 'submit' => $k > 0 || true,
            ]);
            if ($st >= 300) {
                continue;
            }
            if ($k === 0) {
                continue;                        // bugungisi — yuborilgan, hali qabul qilinmagan
            }
            $this->at($date, '08:55');
            $this->api('qabul', 'POST', "/api/sample-requests/{$r['id']}/receive", [
                'items' => array_map(fn ($i) => ['id' => $i['id'], 'received_quantity' => 150 - 5 * $k, 'quantity_unit' => 'g'], $r['items']),
                'handed_over_by' => $c, 'handed_over_phone' => $ph,
                'received_by' => User::where('username', $k === 2 ? 'mirzoyeva' : 'qabul')->value('id'),
            ]);
            $sids = DB::table('sample_request_items')->where('sample_request_id', $r['id'])->orderBy('position')->pluck('sample_id')->all();
            foreach ($sids as $n => $sid) {
                [, $els] = $points[$n];
                if ($k === 1 && $n < 2) {
                    $wsSamples[$sid] = $value($els[$AU][0], $els[$AU][1]);   // kechagi 2 proba — ishchi varaq orqali
                }
                $this->at($date, '15:30');
                foreach ($els as $el => [$mu, $sd]) {
                    if (isset($wsSamples[$sid]) && $el === $AU) {
                        continue;
                    }
                    SampleElement::where('sample_id', $sid)->where('element_id', $el)->update([
                        'result_value' => $value($mu, $sd), 'result_unit' => $el === $S ? '%' : 'g/t', 'status' => 'done',
                        'result_entered_by' => User::where('username', 'xolmurodova')->value('id'), 'result_entered_at' => now(),
                        'method' => $el === $S ? 'GOST 32465-2013' : "O'zDSt 2785:2013 (probir)",
                    ]);
                }
                if (! isset($wsSamples[$sid])) {
                    $s = Sample::find($sid);
                    $s->unsetRelation('sampleElements');
                    $s->refreshStatus();
                }
            }
            if ($k >= 2) {
                $this->at(Carbon::parse($date)->addDay()->toDateString(), '09:30');
                $this->api('mirzoyeva', 'POST', '/api/svodka-releases', ['department_id' => $tnb->id, 'date' => $date]);
            }
        }

        // ishchi varaq (Bo'tana): kechagi 2 proba — tasdiqlangan; bugun — qoralama
        $tpl = DB::table('worksheet_templates')->where('code', '384-2026')->value('id') ?? DB::table('worksheet_templates')->value('id');
        if ($tpl && $wsSamples) {
            $date = $this->day(1)->toDateString();
            $this->at($date, '10:15');
            [$st, $w] = $this->api('shamsiyev', 'POST', '/api/worksheets', ['template_id' => $tpl,
                'samples' => array_map(fn ($sid) => ['id' => $sid, 'parallels' => 2], array_keys($wsSamples)), 'confirm_low_balance' => true]);
            if ($st < 300) {
                $rows = [];
                foreach ($w['rows'] as $i => $row) {
                    $target = $wsSamples[$row['sample_id']] ?? 1.0;
                    $kort = round($target * 50 / 1000 * (1 + ($i % 2 ? 0.006 : -0.006)), 4);
                    $rows[] = ['id' => $row['id'], 'sample_id' => $row['sample_id'], 'excluded' => false, 'note' => null,
                        'inputs' => ['mass' => '50', 'ag' => '5', 'verkbley' => '30', 'korolek' => (string) round($kort + 5 + $target / 200, 4), 'kortochka' => (string) $kort]];
                }
                $this->at($date, '14:10');
                $who = User::whereIn('username', ['shamsiyev', 'xolmurodova', 'ishnazarova'])->pluck('id')->all();
                $signoffs = [];
                foreach (['shixtovka' => '10:30', 'eritish' => '11:40', 'kupelyatsiya' => '13:10'] as $n => $time) {
                    $signoffs[] = ['stage' => $n, 'slot' => 1, 'performer_id' => $who[count($signoffs) % max(1, count($who))] ?? null, 'performed_at' => $date, 'performed_time' => $time];
                }
                $this->api('shamsiyev', 'PUT', "/api/worksheets/{$w['id']}", ['worksheet_date' => $date, 'rows' => $rows, 'signoffs' => $signoffs]);
                $this->api('shamsiyev', 'POST', "/api/worksheets/{$w['id']}/complete");
                $this->at($date, '15:05');
                $this->api('mirzoyeva', 'POST', "/api/worksheets/{$w['id']}/approve", ['accept_warnings' => true]);
                // skaner nusxasi (fayl — sinov uchun)
                $path = $this->placeholder('local', "worksheet-scans/{$w['id']}/demo-skan.pdf", "Ishchi varaq {$w['id']} - skan (demo)");
                DB::table('worksheet_scans')->insert(['worksheet_id' => $w['id'], 'path' => $path, 'original_name' => 'skan.pdf', 'mime' => 'application/pdf',
                    'size' => Storage::disk('local')->size($path), 'sha256' => hash('sha256', Storage::disk('local')->get($path)),
                    'uploaded_by' => User::where('username', 'shamsiyev')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
            }
            // bugungi qoralama varaq
            $todayIds = DB::table('sample_request_items')->join('sample_requests', 'sample_requests.id', '=', 'sample_request_items.sample_request_id')
                ->where('sample_requests.status', 'received')->whereNotNull('sample_request_items.sample_id')
                ->whereNotIn('sample_request_items.sample_id', array_keys($wsSamples))->orderByDesc('sample_request_items.id')->limit(1)->pluck('sample_request_items.sample_id')->all();
            if ($todayIds) {
                $this->at($this->day(0)->toDateString(), '09:40');
                $this->api('xolmurodova', 'POST', '/api/worksheets', ['template_id' => $tpl, 'samples' => array_map(fn ($sid) => ['id' => $sid, 'parallels' => 2], $todayIds), 'confirm_low_balance' => true]);
            }
        }

        // TTG — svodkasiz tadqiqot buyurtmasi: biri qabul qilingan, biri qoralama
        $this->at($this->day(2)->toDateString(), '10:00');
        [$st, $r] = $this->api('ttg', 'POST', '/api/portal/requests', ['department_id' => $ttg->id, 'laboratory_group_id' => 4, 'submit' => true,
            'notes' => 'Flotatsiya reagenti sinovi (demo)', 'contact_name' => 'karimova   dilnoza', 'contact_phone' => '933334455',
            'items' => [['customer_code' => 'T-1', 'state_of_matter' => 'solid', 'element_ids' => [$AU, $AG]], ['customer_code' => 'T-1', 'state_of_matter' => 'solid', 'element_ids' => [$AU]]]]);
        if ($st < 300) {
            $this->at($this->day(2)->toDateString(), '11:00');
            $this->api('mirzoyeva', 'POST', "/api/sample-requests/{$r['id']}/receive", ['items' => array_map(fn ($i) => ['id' => $i['id'], 'received_quantity' => 80, 'quantity_unit' => 'g'], $r['items']),
                'handed_over_by' => 'Karimova Dilnoza', 'handed_over_phone' => '933334455']);
            // laboratoriyada qo'lda ro'yxatga olingan 2 proba (TNB) — buyurtmachi jamlanma hisobotga biriktiradi (svodka_assignments)
            foreach (['Sorb', 'ODP'] as $n => $like) {
                $this->at($this->day(2)->toDateString(), '12:0' . $n);
                [$st2, $smp] = $this->api('qabul', 'POST', '/api/samples', ['sample_number' => "Q-DEMO-{$n}", 'state_of_matter' => 'liquid',
                    'element_ids' => [$AU], 'assigned_group_id' => 1, 'customer_department_id' => $tnb->id, 'customer_code' => (string) (950 + $n),
                    'handed_over_by' => 'Sobirov Jasur', 'received_quantity' => 200, 'quantity_unit' => 'ml']);
                $sid = $smp['id'] ?? $smp['data']['id'] ?? null;
                if ($st2 < 300 && $sid) {
                    SampleElement::where('sample_id', $sid)->update(['result_value' => $n ? 0.21 : 8.7, 'result_unit' => 'g/t', 'status' => 'done', 'result_entered_at' => now()]);
                    $this->at($this->day(2)->toDateString(), '16:00');
                    $this->api('otk', 'PUT', '/api/portal/svodka/assign', ['sample_id' => $sid, 'point_id' => $pt($like)->id,
                        'date' => $this->day(2)->toDateString(), 'shift' => 2]);
                }
            }
        }
        $this->at($this->day(0)->toDateString(), '09:00');
        $this->api('ttg', 'POST', '/api/portal/requests', ['department_id' => $ttg->id, 'laboratory_group_id' => 4, 'submit' => false,
            'notes' => 'Qoralama (demo)', 'items' => [['customer_code' => 'T-2', 'state_of_matter' => 'liquid', 'element_ids' => [$AU]]]]);

        // proba qoldig'i harakatlari
        $sid = DB::table('samples')->whereNotNull('received_quantity')->orderBy('id')->value('id');
        if ($sid && Schema::hasTable('sample_quantity_movements')) {
            foreach ([['return', -20, "Buyurtmachiga qaytarildi (demo)"], ['dispose', -10, 'Utilizatsiya (demo)']] as [$kind, $d, $note]) {
                DB::table('sample_quantity_movements')->insert(['sample_id' => $sid, 'kind' => $kind, 'delta' => $d, 'unit' => 'g', 'note' => $note,
                    'created_by' => User::where('username', 'mirzoyeva')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /* =============================================================== uskunalar */

    private function equipment(): void
    {
        $this->info('3/7 Uskunalar, kalibrovka, xizmat, hujjatlar, ishlatish');
        $lab = DB::table('laboratories')->value('id');
        $admin = User::where('username', 'mirzoyeva')->value('id');
        $this->at($this->day(0)->toDateString(), '09:00');
        $list = [
            ['Atom-absorbsion spektrometr (demo)', 'AAS-7000', 'SN-DEMO-001', 'working', 365, -100],
            ['Analitik tarozi (demo)', 'XS205', 'SN-DEMO-002', 'working', 180, -170],
            ['Mufel pechi (demo)', 'SNOL 8.2/1100', 'SN-DEMO-003', 'awaiting_calibration', 365, -400],
        ];
        $ids = [];
        foreach ($list as $i => [$name, $model, $sn, $status, $interval, $lastAgo]) {
            $last = now()->addDays($lastAgo)->toDateString();
            $ids[] = DB::table('equipment')->insertGetId([
                'laboratory_id' => $lab, 'laboratory_group_id' => $i === 2 ? 1 : null, 'name' => $name, 'model' => $model, 'serial_number' => $sn,
                'inventory_number' => 'INV-' . (1001 + $i), 'manufacturer' => 'Demo Instruments', 'location' => "{$i}-xona",
                'commissioned_at' => now()->subYears(2)->toDateString(), 'status' => $status, 'is_blocked' => $status !== 'working',
                'block_reason' => $status !== 'working' ? "Kalibrovka muddati o'tgan (demo)" : null,
                'calibration_interval_days' => $interval, 'last_calibration_at' => $last, 'next_calibration_at' => Carbon::parse($last)->addDays($interval)->toDateString(),
                'usage_count' => 2 - min($i, 2), 'maintenance_interval_days' => 90, 'last_maintenance_at' => $this->day(30)->toDateString(),
                'next_maintenance_at' => now()->addDays(60)->toDateString(), 'notes' => 'Demo uskuna', 'created_by' => $admin,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (array_slice($ids, 0, 2) as $i => $id) {
            DB::table('equipment_calibrations')->insert(['equipment_id' => $id, 'type' => $i ? 'verification' : 'calibration', 'performed_at' => $this->day(100 + $i * 70)->toDateString(),
                'performed_by' => "O'zstandart (demo)", 'result' => 'passed', 'certificate_number' => 'KAL-' . (5001 + $i), 'valid_until' => now()->addDays(265 - $i * 70)->toDateString(),
                'notes' => 'Demo', 'created_by' => $admin, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('equipment_maintenances')->insert(['equipment_id' => $id, 'type' => $i ? 'repair' : 'scheduled', 'scheduled_at' => $this->day(30)->toDateString(),
                'performed_at' => $i ? null : $this->day(30)->toDateString(), 'performed_by' => 'Servis (demo)', 'description' => $i ? "Nasos almashtiriladi (demo)" : 'Rejali xizmat (demo)',
                'cost' => $i ? 1500000 : 300000, 'status' => $i ? 'planned' : 'done', 'created_by' => $admin, 'created_at' => now(), 'updated_at' => now()]);
            $path = $this->placeholder('public', "equipment/{$id}/demo-sertifikat.pdf", "Kalibrovka sertifikati - demo {$id}");
            DB::table('equipment_documents')->insert(['equipment_id' => $id, 'type' => 'calibration_certificate', 'title' => 'Kalibrovka sertifikati (demo)', 'file_path' => $path,
                'original_name' => 'sertifikat.pdf', 'mime_type' => 'application/pdf', 'file_size' => Storage::disk('public')->size($path), 'uploaded_by' => $admin,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $se = SampleElement::whereNotNull('result_value')->orderBy('id')->limit(2)->get();
        foreach ($se as $i => $row) {
            $row->forceFill(['equipment_id' => $ids[$i]])->save();
            DB::table('equipment_usages')->insert(['equipment_id' => $ids[$i], 'context_type' => 'SampleElement', 'context_id' => $row->id,
                'description' => 'Natija kiritildi (demo)', 'user_id' => $row->result_entered_by, 'used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /* =============================================================== sinov obyektlari */

    private function testObjects(): void
    {
        $this->info('4/7 Sinov obyektlari');
        $lab = DB::table('laboratories')->value('id');
        $by = User::where('username', 'mirzoyeva')->value('id');
        $profile = DB::table('test_profiles')->value('id');
        $els = Element::whereIn('symbol', ['Au', 'Ag', 'Cu'])->pluck('id')->all();
        foreach ([
            ['TO-DEMO-001', 'raw_material', "Ruda namunasi (demo)", 'in_testing'],
            ['TO-DEMO-002', 'product', 'Dore qotishmasi (demo)', 'tested'],
            ['TO-DEMO-003', 'water', 'Aylanma suv (demo)', 'new'],
        ] as $i => [$ident, $type, $name, $status]) {
            $id = DB::table('test_objects')->insertGetId(['identifier' => $ident, 'type' => $type, 'name' => $name, 'code' => 'D' . ($i + 1), 'batch' => 'P-' . (77 + $i),
                'description' => 'Sinov uchun namunaviy yozuv', 'source_supplier' => 'GMZ-3 (demo)', 'received_at' => $this->day(5 - $i)->toDateString(),
                'storage_conditions' => 'Quruq joyda', 'status' => $status, 'storage_location' => 'Shkaf ' . ($i + 1), 'laboratory_id' => $lab,
                'test_profile_id' => $i === 0 ? $profile : null, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_slice($els, 0, 2) as $el) {
                DB::table('test_object_element')->insert(['test_object_id' => $id, 'element_id' => $el]);
            }
            if ($i < 2) {
                $path = $this->placeholder('public', "test-objects/{$id}/demo-akt.pdf", "Qabul akti - {$ident}");
                DB::table('test_object_documents')->insert(['test_object_id' => $id, 'type' => $i ? 'protocol' : 'acceptance_act', 'title' => $i ? 'Sinov protokoli (demo)' : 'Qabul akti (demo)',
                    'file_path' => $path, 'original_name' => 'akt.pdf', 'mime_type' => 'application/pdf', 'file_size' => Storage::disk('public')->size($path),
                    'uploaded_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /* =============================================================== suv laboratoriyasi */

    private function water(): void
    {
        $this->info('5/7 Suv laboratoriyasi');
        $by = User::where('username', 'urinov')->value('id') ?? User::where('username', 'mirzoyeva')->value('id');
        $points = DB::table('monitoring_points')->orderBy('id')->limit(3)->get();
        $params = [['pH', null, 6.5, 8.5, 7.3], ['Цианиды', 'mg/l', null, 0.035, 0.012], ['Медь', 'mg/l', null, 1.0, 0.31]];
        foreach ($points as $n => $p) {
            if (! DB::table('monitoring_point_parameters')->where('monitoring_point_id', $p->id)->exists()) {
                foreach (array_slice($params, 0, 2 + ($n === 0 ? 1 : 0)) as $i => [$name, $unit, $min, $max]) {
                    DB::table('monitoring_point_parameters')->insert(['monitoring_point_id' => $p->id, 'parameter' => $name, 'unit' => $unit, 'norm_min' => $min, 'norm_max' => $max,
                        'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $done = $n < 2;
            $date = $this->day(6 - $n * 2);
            $oid = DB::table('water_orders')->insertGetId(['order_number' => 'WO-DEMO-' . ($n + 1), 'source_document' => 'Reja (demo)', 'division' => 'Ekologiya (demo)',
                'assigned_group_id' => $p->laboratory_group_id ?? null, 'monitoring_point_id' => $p->id, 'status' => $done ? 'completed' : 'registered',
                'period_month' => $date->format('Y-m'), 'registered_by' => $by, 'registered_at' => $date, 'completed_at' => $done ? $date->copy()->addDay() : null,
                'notes' => 'Demo', 'created_at' => $date, 'updated_at' => $date]);
            if ($done) {
                foreach (DB::table('monitoring_point_parameters')->where('monitoring_point_id', $p->id)->get() as $i => $pp) {
                    $v = $params[min($i, 2)][4] * ($n ? 1.4 : 1);
                    $assess = $pp->norm_max !== null && $v > $pp->norm_max ? 'critical' : 'normal';
                    DB::table('water_order_results')->insert(['water_order_id' => $oid, 'parameter' => $pp->parameter, 'value' => $v, 'unit' => $pp->unit,
                        'norm_min' => $pp->norm_min, 'norm_max' => $pp->norm_max, 'assessment' => $assess, 'measured_at' => $date->copy()->addDay(),
                        'entered_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    /* =============================================================== laboratoriyalararo */

    private function interlab(): void
    {
        $this->info('6/7 Laboratoriyalararo tekshiruv (tarmoqsiz — sinov yozuvlari)');
        try {
            $this->at($this->day(3)->toDateString(), '10:00');
            $identity = app(Identity::class);
            if (! $identity->get()) {
                $identity->create('MZL GMZ-3 (demo)', 'GMZ3', 'http://127.0.0.1:8000');
            }
            $peers = [];
            foreach ([['ЦЗЛ ГМЗ-2 (demo)', 'GMZ2'], ['ЦНИЛ (demo)', 'CNIL']] as $i => [$name, $code]) {
                $enc = Crypto::generateKeyPair();
                $sign = Crypto::generateKeyPair();
                $peers[] = InterlabPeer::create(['node_id' => (string) Str::uuid(), 'name' => $name, 'code' => $code, 'base_url' => "http://10.0.0." . (10 + $i) . ':8000',
                    'enc_public' => $enc['public'], 'sign_public' => $sign['public'], 'fingerprint' => Crypto::fingerprint($sign['public'], $enc['public']),
                    'status' => $i ? 'pending_in' : 'active', 'approved_at' => $i ? null : now(), 'last_seen_at' => now()->subHours(2), 'notes' => 'Demo — haqiqiy ulanish yo\'q']);
            }
            DB::table('interlab_invites')->insert([
                ['lookup' => Str::random(12), 'secret' => bcrypt(Str::random(20)), 'note' => 'GMZ-2 uchun (demo)', 'expires_at' => now()->addDay(), 'created_by' => User::where('username', 'admin')->value('id'),
                    'used_at' => now(), 'used_by_peer_id' => $peers[0]->id, 'used_by_name' => $peers[0]->name, 'created_at' => now(), 'updated_at' => now()],
                ['lookup' => Str::random(12), 'secret' => bcrypt(Str::random(20)), 'note' => "Muddati o'tgan (demo)", 'expires_at' => $this->day(1), 'created_by' => User::where('username', 'admin')->value('id'),
                    'used_at' => null, 'used_by_peer_id' => null, 'used_by_name' => null, 'created_at' => now(), 'updated_at' => now()],
            ]);
            // chiquvchi yo'llanma — xizmat orqali (xabar navbatga tushadi, yuborilmaydi)
            $sample = Sample::whereHas('sampleElements', fn ($q) => $q->whereNotNull('result_value'))->orderBy('id')->first();
            $mz = User::where('username', 'mirzoyeva')->first();
            if ($sample) {
                $symbol = $sample->sampleElements()->with('element')->first()?->element?->symbol;
                app(ReferralService::class)->create($mz, ['peer_id' => $peers[0]->id, 'sample_id' => $sample->id, 'elements' => [$symbol],
                    'description' => 'Nazorat tahlili (demo)', 'deadline' => now()->addDays(5)->toDateString(), 'tolerance_pct' => 5]);
            }
            // kiruvchi yo'llanma — natija qoralamasi bilan
            $this->at($this->day(2)->toDateString(), '11:00');
            $ref = DB::table('interlab_referrals')->insertGetId(['uuid' => (string) Str::uuid(), 'number' => 'GMZ2-2026-0007', 'direction' => 'in', 'peer_id' => $peers[0]->id,
                'status' => 'accepted', 'sample_code' => 'K-17', 'sample_number' => 'PR-DEMO-17', 'sample_info' => json_encode(['state' => 'solid']),
                'elements' => json_encode([['symbol' => 'Au', 'name_uz' => 'Oltin', 'name_ru' => 'Золото', 'name_en' => 'Gold', 'unit' => 'g/t']]),
                'description' => 'Hakamlik tahlili (demo)', 'deadline' => now()->addDays(3)->toDateString(), 'tolerance_pct' => 5,
                'sender_name' => 'Saidova M. (demo)', 'sender_lab' => 'ЦЗЛ ГМЗ-2 (demo)', 'group_id' => 1, 'sent_at' => now(), 'delivered_at' => now(),
                'accepted_at' => now(), 'accepted_by_name' => $mz->name, 'last_event_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ([[1, 2.31], [1, 2.29]] as $i => [$rev, $v]) {
                DB::table('interlab_results')->insert(['referral_id' => $ref, 'revision' => $rev + $i, 'is_draft' => $i === 1, 'symbol' => 'Au', 'value_text' => (string) $v, 'value' => $v,
                    'unit' => 'g/t', 'method' => "O'zDSt 2785:2013", 'entered_by' => $mz->id, 'entered_by_name' => $mz->name, 'entered_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('interlab_messages')->insert(['uuid' => (string) Str::uuid(), 'peer_id' => $peers[0]->id, 'peer_node_id' => $peers[0]->node_id, 'direction' => 'in',
                'type' => 'referral.create', 'body' => json_encode(['number' => 'GMZ2-2026-0007']), 'status' => 'processed', 'attempts' => 1, 'processed_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
        } catch (Throwable $e) {
            $this->warn("  Laboratoriyalararo qism o'tkazib yuborildi: " . $e->getMessage());
        }
    }

    /* =============================================================== yordamchi */

    private function help(): void
    {
        $this->info('7/7 Yordamchi: fikrlar va javobsiz savollar');
        if (! Schema::hasTable('help_feedback')) {
            return;
        }
        $entries = DB::table('help_entries')->orderBy('id')->limit(3)->pluck('id')->all();
        $users = User::whereIn('username', ['shamsiyev', 'otk', 'qabul'])->pluck('id')->all();
        foreach ($entries as $i => $e) {
            DB::table('help_feedback')->insert(['entry_id' => $e, 'user_id' => $users[$i] ?? null, 'helpful' => $i !== 1, 'question' => $i === 1 ? "Bu javob tushunarsiz (demo)" : null,
                'locale' => 'uz', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (["Shablonni boshqa bo'limga qanday beraman? (demo)", 'Как распечатать протокол? (demo)'] as $i => $q) {
            DB::table('help_unanswered')->insert(['question_hash' => hash('sha256', $q), 'question' => $q, 'locale' => $i ? 'ru' : 'uz', 'route' => $i ? 'samples' : 'portal-templates',
                'user_id' => $users[$i] ?? null, 'best_entry_id' => $entries[0] ?? null, 'best_score' => 0.21, 'asked_count' => $i + 1, 'status' => 'open',
                'last_asked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
