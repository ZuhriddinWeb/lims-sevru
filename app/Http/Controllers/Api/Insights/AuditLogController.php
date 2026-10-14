<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Services\Insights\AuditLog;
use App\Services\Insights\SimpleXlsx;
use Illuminate\Http\Request;

/**
 * [P19] Audit jurnali (ruxsat: logs.view — marshrutda).
 *   GET /api/audit/logs     — sahifa (yangisi birinchi), har yozuvning hozirgi holati bilan
 *   GET /api/audit/summary  — davr bo'yicha sonlar: amallar, bo'limlar, xodimlar
 *   GET /api/audit/export   — Excel (filtr bo'yicha, eng ko'pi 20 000 qator)
 */
class AuditLogController extends Controller
{
    private function rules(): array
    {
        return [
            'from'       => ['nullable', 'date_format:Y-m-d'],
            'to'         => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id'    => ['nullable', 'regex:/^(\d{1,18}|system)$/'],
            'model_type' => ['nullable', 'regex:/^[A-Za-z][A-Za-z0-9_\\\\]{0,190}$/'],
            'model_id'   => ['nullable', 'integer', 'min:1'],
            'action'     => ['nullable', 'string', 'max:64'],
            'q'          => ['nullable', 'string', 'max:100'],
            'page'       => ['nullable', 'integer', 'min:1'],
            'per_page'   => ['nullable', 'integer', 'min:10', 'max:200'],
            'locale'     => ['nullable', 'in:uz,ru,en'],
        ];
    }

    private function filters(Request $request): array
    {
        return $request->only(['from', 'to', 'user_id', 'model_type', 'model_id', 'action', 'q']);
    }

    public function index(Request $request, AuditLog $audit)
    {
        $request->validate($this->rules());

        return response()->json($audit->page(
            $this->filters($request),
            (int) $request->input('page', 1),
            (int) $request->input('per_page', 50),
        ));
    }

    public function summary(Request $request, AuditLog $audit)
    {
        $request->validate($this->rules());

        return response()->json($audit->summary($this->filters($request)));
    }

    public function export(Request $request, AuditLog $audit)
    {
        $request->validate($this->rules());
        $f = $this->filters($request);
        $L = self::labels($request->input('locale', 'uz'));
        $rows = $audit->exportRows($f);

        $head = [$L['date'], $L['time'], $L['action'], $L['module'], $L['record'], $L['description'], $L['user'], $L['ip'], $L['details']];
        $sheet = [$head];
        foreach ($rows as $r) {
            $sheet[] = [
                $r['at']?->format('d.m.Y'),
                $r['at']?->format('H:i:s'),
                $L['actions'][AuditLog::bucket($r['action'])] ?? $r['action'],
                $r['model_type'] ? ($L['models'][$r['model_type']] ?? $r['model_type']) : null,
                $r['model_id'],
                $r['description'],
                $r['user'] ?? $L['system'],
                $r['ip'],
                $r['properties'],
            ];
        }

        $period = ($f['from'] ?? null) || ($f['to'] ?? null)
            ? (isset($f['from']) ? date('d.m.Y', strtotime($f['from'])) : '…') . ' — ' . (isset($f['to']) ? date('d.m.Y', strtotime($f['to'])) : '…')
            : $L['all_time'];
        $info = [
            [$L['info'], ''],
            [$L['period'], $period],
            [$L['rows'], count($rows) . (count($rows) >= AuditLog::EXPORT_MAX ? ' ' . $L['truncated'] : '')],
            [$L['created'], now()->format('d.m.Y H:i') . ' · ' . $request->user()->name],
        ];
        foreach (['action' => $L['action'], 'model_type' => $L['module'], 'user_id' => $L['user'], 'q' => $L['search']] as $k => $label) {
            if (! empty($f[$k])) {
                $v = $f[$k];
                if ($k === 'action') {
                    $v = $L['actions'][$v] ?? $v;
                } elseif ($k === 'model_type') {
                    $v = ($L['models'][$v] ?? $v) . (! empty($f['model_id']) ? ' #' . $f['model_id'] : '');
                } elseif ($k === 'user_id') {
                    $v = $v === 'system' ? $L['system'] : (\App\Models\User::withTrashed()->find((int) $v)?->name ?? $v);
                }
                $info[] = [$label, $v];
            }
        }

        $name = 'audit_jurnali_' . now()->format('Y-m-d_H-i');
        if (SimpleXlsx::available()) {
            $path = (new SimpleXlsx())
                ->sheet($L['sheet'], $sheet, [11, 9, 16, 22, 9, 70, 26, 15, 60])
                ->sheet($L['info'], $info, [22, 60])
                ->save();

            return response()->download($path, $name . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        return response()->streamDownload(function () use ($sheet) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($sheet as $r) {
                fputcsv($out, $r, ';');
            }
            fclose($out);
        }, $name . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Excel uchun nomlar (interfeysdagi bilan bir xil) */
    public static function labels(string $locale): array
    {
        $models = [
            'uz' => [
                'Sample' => 'Namuna', 'SampleElement' => 'Namuna natijasi', 'SampleQuantityMovement' => 'Namuna miqdori',
                'Worksheet' => 'Ishchi varaq', 'WorksheetTemplate' => 'Ishchi varaq shabloni', 'WorksheetScan' => 'Varaq skani',
                'TestObject' => 'Sinov obyekti', 'TestObjectDocument' => 'Obyekt hujjati',
                'Equipment' => 'Uskuna', 'EquipmentDocument' => 'Uskuna hujjati', 'EquipmentCalibration' => 'Kalibrovka',
                'EquipmentMaintenance' => 'Texnik xizmat', 'WaterOrder' => 'Suv buyurtmasi', 'MonitoringPoint' => 'Monitoring nuqtasi',
                'User' => 'Foydalanuvchi', 'Role' => 'Rol', 'MenuItem' => 'Menyu', 'Laboratory' => 'Laboratoriya',
                'LaboratoryGroup' => 'Guruh', 'Element' => 'Element', 'TestProfile' => 'Sinov profili',
                'CustomerDepartment' => "Buyurtmachi bo'lim", 'LabShift' => 'Smena',
                'InterlabReferral' => 'Laboratoriyalararo yo\'llanma', 'InterlabPeer' => 'Tarmoqdagi laboratoriya', 'InterlabInvite' => 'Ulanish kodi',
            ],
            'ru' => [
                'Sample' => 'Проба', 'SampleElement' => 'Результат пробы', 'SampleQuantityMovement' => 'Количество пробы',
                'Worksheet' => 'Рабочий лист', 'WorksheetTemplate' => 'Шаблон рабочего листа', 'WorksheetScan' => 'Скан листа',
                'TestObject' => 'Объект испытания', 'TestObjectDocument' => 'Документ объекта',
                'Equipment' => 'Оборудование', 'EquipmentDocument' => 'Документ оборудования', 'EquipmentCalibration' => 'Калибровка',
                'EquipmentMaintenance' => 'Техобслуживание', 'WaterOrder' => 'Заявка водной лаб.', 'MonitoringPoint' => 'Точка мониторинга',
                'User' => 'Пользователь', 'Role' => 'Роль', 'MenuItem' => 'Меню', 'Laboratory' => 'Лаборатория',
                'LaboratoryGroup' => 'Группа', 'Element' => 'Элемент', 'TestProfile' => 'Профиль испытаний',
                'CustomerDepartment' => 'Подразделение-заказчик', 'LabShift' => 'Смена',
                'InterlabReferral' => 'Межлаб. направление', 'InterlabPeer' => 'Лаборатория сети', 'InterlabInvite' => 'Код подключения',
            ],
            'en' => [
                'Sample' => 'Sample', 'SampleElement' => 'Sample result', 'SampleQuantityMovement' => 'Sample quantity',
                'Worksheet' => 'Worksheet', 'WorksheetTemplate' => 'Worksheet template', 'WorksheetScan' => 'Worksheet scan',
                'TestObject' => 'Test object', 'TestObjectDocument' => 'Object document',
                'Equipment' => 'Equipment', 'EquipmentDocument' => 'Equipment document', 'EquipmentCalibration' => 'Calibration',
                'EquipmentMaintenance' => 'Maintenance', 'WaterOrder' => 'Water order', 'MonitoringPoint' => 'Monitoring point',
                'User' => 'User', 'Role' => 'Role', 'MenuItem' => 'Menu', 'Laboratory' => 'Laboratory',
                'LaboratoryGroup' => 'Group', 'Element' => 'Element', 'TestProfile' => 'Test profile',
                'CustomerDepartment' => 'Customer department', 'LabShift' => 'Shift',
                'InterlabReferral' => 'Inter-lab referral', 'InterlabPeer' => 'Network laboratory', 'InterlabInvite' => 'Pairing code',
            ],
        ];
        $all = [
            'uz' => [
                'sheet' => 'Audit jurnali', 'info' => "Ma'lumot", 'date' => 'Sana', 'time' => 'Vaqt', 'action' => 'Amal', 'module' => "Bo'lim",
                'record' => 'Yozuv №', 'description' => 'Tavsif', 'user' => 'Xodim', 'ip' => 'IP manzil', 'details' => 'Tafsilotlar',
                'system' => 'Tizim', 'period' => 'Davr', 'all_time' => 'Butun davr', 'rows' => 'Qatorlar', 'truncated' => '(cheklangan — davrni qisqartiring)',
                'created' => 'Yaratildi', 'search' => 'Qidiruv',
                'actions' => ['created' => 'Yaratish', 'updated' => "O'zgartirish", 'deleted' => "O'chirish", 'restored' => 'Tiklash',
                    'login' => 'Kirish', 'logout' => 'Chiqish', 'interlab' => 'Laboratoriyalararo'],
            ],
            'ru' => [
                'sheet' => 'Журнал аудита', 'info' => 'Сведения', 'date' => 'Дата', 'time' => 'Время', 'action' => 'Действие', 'module' => 'Раздел',
                'record' => 'Запись №', 'description' => 'Описание', 'user' => 'Сотрудник', 'ip' => 'IP-адрес', 'details' => 'Подробности',
                'system' => 'Система', 'period' => 'Период', 'all_time' => 'Весь период', 'rows' => 'Строк', 'truncated' => '(ограничено — сократите период)',
                'created' => 'Сформировано', 'search' => 'Поиск',
                'actions' => ['created' => 'Создание', 'updated' => 'Изменение', 'deleted' => 'Удаление', 'restored' => 'Восстановление',
                    'login' => 'Вход', 'logout' => 'Выход', 'interlab' => 'Межлабораторные'],
            ],
            'en' => [
                'sheet' => 'Audit log', 'info' => 'Info', 'date' => 'Date', 'time' => 'Time', 'action' => 'Action', 'module' => 'Section',
                'record' => 'Record #', 'description' => 'Description', 'user' => 'User', 'ip' => 'IP address', 'details' => 'Details',
                'system' => 'System', 'period' => 'Period', 'all_time' => 'All time', 'rows' => 'Rows', 'truncated' => '(truncated — narrow the period)',
                'created' => 'Generated', 'search' => 'Search',
                'actions' => ['created' => 'Create', 'updated' => 'Update', 'deleted' => 'Delete', 'restored' => 'Restore',
                    'login' => 'Sign in', 'logout' => 'Sign out', 'interlab' => 'Inter-lab'],
            ],
        ];
        $locale = isset($all[$locale]) ? $locale : 'uz';

        return $all[$locale] + ['models' => $models[$locale]];
    }
}
