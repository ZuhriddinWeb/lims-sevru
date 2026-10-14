<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Insights\InsightPeriod;
use App\Services\Insights\ShiftCalendar;
use App\Services\Insights\SimpleXlsx;
use App\Services\Insights\StaffActivity;
use Illuminate\Http\Request;

/**
 * Xodimlar faoliyati.
 *   GET /api/insights/staff            — jadval (boshliq/rahbariyat — hamma; boshqalar — faqat o'zi)
 *   GET /api/insights/staff/{user}     — bitta xodim: ko'rsatkichlar, kun × smena, vaqt chizig'i
 *   GET /api/insights/staff/export     — Excel (.xlsx; zip kengaytmasi bo'lmasa — CSV)
 * Davr parametrlari Rahbariyat paneli bilan bir xil (?period=shift|today|week|…&date_from&date_to).
 */
class StaffActivityController extends Controller
{
    public const PERMISSION = 'staff_activity.view';

    private function rules(): array
    {
        return [
            'period'        => ['nullable', 'string', 'in:' . implode(',', InsightPeriod::PRESETS)],
            'date_from'     => ['nullable', 'date'],
            'date_to'       => ['nullable', 'date'],
            'laboratory_id' => ['nullable', 'integer'],
            'group_id'      => ['nullable', 'integer'],
            'idle'          => ['nullable', 'boolean'],
            'with_log'      => ['nullable', 'boolean'],
            'locale'        => ['nullable', 'string', 'in:uz,ru,en'],
        ];
    }

    private function canAll(Request $request): bool
    {
        return $request->user()->hasPermission(self::PERMISSION);
    }

    /** Jadval uchun filtrlar: ruxsati yo'q xodim — faqat o'zi */
    private function filters(Request $request): array
    {
        if (! $this->canAll($request)) {
            return ['user_id' => (int) $request->user()->id];
        }

        return array_filter([
            'laboratory_id' => $request->input('laboratory_id'),
            'group_id'      => $request->input('group_id'),
        ]);
    }

    public function index(Request $request, StaffActivity $staff, ShiftCalendar $calendar)
    {
        $request->validate($this->rules());
        $period = InsightPeriod::fromRequest($request, $calendar);
        $all = $this->canAll($request);
        $data = $staff->summary($period, $this->filters($request), $all && $request->boolean('idle'));

        return response()->json([
            'scope'   => $all ? 'all' : 'own',
            'me'      => (int) $request->user()->id,
            'period'  => $period->toArray(),
            'shifts'  => $calendar->list(),
            'groups'  => $all ? $staff->groups() : [],
        ] + $data);
    }

    public function show(Request $request, User $user, StaffActivity $staff, ShiftCalendar $calendar)
    {
        $request->validate($this->rules());
        $me = $request->user();
        if ((int) $user->id !== (int) $me->id && ! $this->canAll($request)) {
            abort(403, 'Faqat o\'z faoliyatingizni ko\'rishingiz mumkin');
        }
        $period = InsightPeriod::fromRequest($request, $calendar);
        $summary = $staff->summary($period, ['user_id' => (int) $user->id], false, true);
        $row = $summary['rows'][0] ?? null;
        $info = $staff->userInfo([(int) $user->id])[(int) $user->id] ?? null;

        return response()->json([
            'scope'    => $this->canAll($request) ? 'all' : 'own',
            'me'       => (int) $me->id,
            'user'     => $info,
            'period'   => $period->toArray(),
            'shifts'   => $calendar->list(),
            'grain'    => $summary['grain'],
            'metrics'  => $summary['metrics'],
            'summary'  => $row ? [
                'metrics'     => $row['metrics'],
                'shifts'      => $row['shifts'],
                'days'        => $row['days'],
                'day_shifts'  => $row['day_shifts'],
                'active_days' => $row['active_days'],
                'first_at'    => $row['first_at'],
                'last_at'     => $row['last_at'],
            ] : null,
        ] + $staff->timeline((int) $user->id, $period, $request->boolean('with_log')));
    }

    public function export(Request $request, StaffActivity $staff, ShiftCalendar $calendar)
    {
        $request->validate($this->rules());
        $period = InsightPeriod::fromRequest($request, $calendar);
        $all = $this->canAll($request);
        $data = $staff->summary($period, $this->filters($request), $all && $request->boolean('idle'), true);
        $L = self::labels($request->input('locale', 'uz'));
        $shifts = $calendar->list();
        $metrics = $data['metrics'];

        $title = $L['title'] . ' — ' . $period->from->format('d.m.Y H:i') . ' — ' . $period->to->format('d.m.Y H:i');
        $groupName = fn ($g) => $g ? ($g['name_' . $request->input('locale', 'uz')] ?? $g['name_uz'] ?? '') : '';

        // 1-varaq: xodimlar
        $head = array_merge([$L['name'], $L['position'], $L['group']], array_map(fn ($m) => $L['m'][$m] ?? $m, $metrics));
        foreach ($shifts as $s) {
            $head[] = $s['name'] . ' (' . $s['start_time'] . '–' . $s['end_time'] . ')';
        }
        if ($shifts) {
            $head[] = $L['outside'];
        }
        $head = array_merge($head, [$L['no_time'], $L['active_days'], $L['first'], $L['last']]);
        $rows = [$head];
        foreach ($data['rows'] as $r) {
            $m = (array) $r['metrics'];
            $sh = (array) $r['shifts'];
            $line = [$r['user']['name'], $r['user']['position'], $groupName($r['user']['group'])];
            foreach ($metrics as $k) {
                $line[] = (int) ($m[$k] ?? 0);
            }
            foreach ($shifts as $s) {
                $line[] = (int) ($sh[(string) $s['id']] ?? 0);
            }
            if ($shifts) {
                $line[] = (int) ($sh['out'] ?? 0);
            }
            $line[] = (int) ($sh['nt'] ?? 0);
            $line[] = $r['active_days'];
            $line[] = $r['first_at'] ? date('d.m.Y H:i', strtotime($r['first_at'])) : null;
            $line[] = $r['last_at'] ? date('d.m.Y H:i', strtotime($r['last_at'])) : null;
            $rows[] = $line;
        }
        $tm = (array) $data['totals']['metrics'];
        $ts = (array) $data['totals']['shifts'];
        $total = [$L['total'], null, null];
        foreach ($metrics as $k) {
            $total[] = (int) ($tm[$k] ?? 0);
        }
        foreach ($shifts as $s) {
            $total[] = (int) ($ts[(string) $s['id']] ?? 0);
        }
        if ($shifts) {
            $total[] = (int) ($ts['out'] ?? 0);
        }
        $total[] = (int) ($ts['nt'] ?? 0);
        $rows[] = $total;
        $widths = array_merge([30, 20, 22], array_fill(0, count($head) - 3, 13));

        // 2-varaq: kunlar bo'yicha
        $daily = [array_merge([$data['grain'] === 'month' ? $L['month'] : $L['date'], $L['name'], $L['group']], array_map(fn ($m) => $L['m'][$m] ?? $m, $metrics))];
        $byDay = [];
        foreach ($data['rows'] as $i => $r) {
            foreach ((array) ($r['days'] ?? []) as $d => $vals) {
                $byDay[] = [$d, $i, $r, (array) $vals];
            }
        }
        usort($byDay, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($byDay as [$d, , $r, $vals]) {
            $label = strlen($d) === 10 ? date('d.m.Y', strtotime($d)) : $d;
            $line = [$label, $r['user']['name'], $groupName($r['user']['group'])];
            foreach ($metrics as $k) {
                $line[] = (int) ($vals[$k] ?? 0);
            }
            $daily[] = $line;
        }

        $info = [
            [$L['info'], ''],
            [$L['title'], $period->from->format('d.m.Y H:i') . ' — ' . $period->to->format('d.m.Y H:i')],
            [$L['shift_label'], $period->shift ? $period->shift['name'] : '—'],
            [$L['created'], now()->format('d.m.Y H:i') . ' · ' . $request->user()->name],
        ];

        $name = 'xodimlar_faoliyati_' . $period->from->format('Y-m-d') . '_' . $period->to->format('Y-m-d');

        if (SimpleXlsx::available()) {
            $path = (new SimpleXlsx())
                ->sheet($L['sheet_staff'], $rows, $widths, true)
                ->sheet($L['sheet_days'], $daily, array_merge([12, 30, 22], array_fill(0, count($metrics), 13)))
                ->sheet($L['info'], $info, [24, 60])
                ->save();

            return response()->download($path, $name . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        // Zaxira: CSV (UTF-8 BOM, «;» bilan — Excel to'g'ri ochadi)
        return response()->streamDownload(function () use ($rows, $title) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$title], ';');
            foreach ($rows as $r) {
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, $name . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function labels(string $locale): array
    {
        $all = [
            'uz' => [
                'title' => 'Xodimlar faoliyati', 'name' => 'F.I.Sh.', 'position' => 'Lavozim', 'group' => 'Guruh',
                'outside' => 'Smenadan tashqari', 'no_time' => 'Soatsiz imzolar', 'active_days' => 'Faol kunlar',
                'first' => 'Birinchi amal', 'last' => 'Oxirgi amal', 'total' => 'Jami', 'date' => 'Sana', 'month' => 'Oy',
                'sheet_staff' => 'Xodimlar', 'sheet_days' => "Kunlar bo'yicha", 'info' => "Ma'lumot",
                'shift_label' => 'Smena', 'created' => 'Yaratildi',
                'm' => [
                    'received' => 'Qabul qilingan namunalar', 'started' => 'Ishga olingan namunalar', 'results' => 'Kiritilgan natijalar',
                    'worksheets' => 'Ochilgan varaqlar', 'signoffs' => 'Imzolangan bosqichlar', 'approved' => 'Tasdiqlangan varaqlar',
                    'extra' => "Qo'shimcha parallellar", 'retests' => 'Takroriy tahlilga qaytarilgan', 'movements' => 'Namuna miqdori yozuvlari',
                    'water' => 'Suv natijalari', 'equipment' => 'Uskuna ishlatish',
                ],
            ],
            'ru' => [
                'title' => 'Деятельность сотрудников', 'name' => 'Ф.И.О.', 'position' => 'Должность', 'group' => 'Группа',
                'outside' => 'Вне смен', 'no_time' => 'Подписи без времени', 'active_days' => 'Активных дней',
                'first' => 'Первое действие', 'last' => 'Последнее действие', 'total' => 'Итого', 'date' => 'Дата', 'month' => 'Месяц',
                'sheet_staff' => 'Сотрудники', 'sheet_days' => 'По дням', 'info' => 'Сведения',
                'shift_label' => 'Смена', 'created' => 'Сформировано',
                'm' => [
                    'received' => 'Принято проб', 'started' => 'Взято в работу', 'results' => 'Внесено результатов',
                    'worksheets' => 'Открыто листов', 'signoffs' => 'Подписано этапов', 'approved' => 'Утверждено листов',
                    'extra' => 'Доп. параллели', 'retests' => 'Возвращено на повтор', 'movements' => 'Записи о количестве пробы',
                    'water' => 'Результаты (вода)', 'equipment' => 'Использование оборудования',
                ],
            ],
            'en' => [
                'title' => 'Staff activity', 'name' => 'Name', 'position' => 'Position', 'group' => 'Group',
                'outside' => 'Outside shifts', 'no_time' => 'Sign-offs without time', 'active_days' => 'Active days',
                'first' => 'First action', 'last' => 'Last action', 'total' => 'Total', 'date' => 'Date', 'month' => 'Month',
                'sheet_staff' => 'Staff', 'sheet_days' => 'By day', 'info' => 'Info',
                'shift_label' => 'Shift', 'created' => 'Created',
                'm' => [
                    'received' => 'Samples received', 'started' => 'Samples started', 'results' => 'Results entered',
                    'worksheets' => 'Worksheets opened', 'signoffs' => 'Stages signed', 'approved' => 'Worksheets approved',
                    'extra' => 'Extra parallels', 'retests' => 'Sent to retest', 'movements' => 'Sample quantity records',
                    'water' => 'Water results', 'equipment' => 'Equipment use',
                ],
            ],
        ];

        return $all[$locale] ?? $all['uz'];
    }
}
