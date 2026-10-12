<?php

namespace Database\Seeders;

use App\Models\Element;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\WorksheetTemplate;
use App\Services\Worksheets\DefaultTemplates;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ishchi varaqlar modulini o'rnatadi (qayta ishga tushirish xavfsiz):
 *   php artisan db:seed --class=WorksheetModuleSeeder
 *
 *  - ruxsatlar: worksheets.view/create/update/delete/approve, worksheet_templates.manage
 *  - rollarga qo'shadi (mavjud ruxsatlarni O'CHIRMAYDI)
 *  - menyu: «Ishchi varaqlar» + Kataloglar ichida «Ishchi varaq shablonlari»
 *  - 5 ta boshlang'ich shablon (faqat yo'q bo'lsa — tahrirlanganlar ustidan yozilmaydi)
 *
 * Eslatma: RoleSeeder qayta ishga tushirilsa (u syncPermissions qiladi),
 * shu seederni ham qayta ishga tushiring.
 */
class WorksheetModuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->permissions();
            $this->roles();
            $this->menu();
            $this->templates();
        });
    }

    private function permissions(): void
    {
        $modules = [
            'worksheets' => [
                'group'  => 'lab',
                'labels' => ['ru' => 'Рабочие листы', 'uz' => 'Ishchi varaqlar', 'en' => 'Worksheets'],
                'abilities' => [
                    'view'    => ['ru' => 'Просмотр и печать', 'uz' => "Ko'rish va chop etish", 'en' => 'View & print'],
                    'create'  => ['ru' => 'Создание', 'uz' => 'Yaratish', 'en' => 'Create'],
                    'update'  => ['ru' => 'Ввод данных и сканы', 'uz' => "Ma'lumot kiritish va skan", 'en' => 'Enter data & scans'],
                    'delete'  => ['ru' => 'Удаление', 'uz' => "O'chirish", 'en' => 'Delete'],
                    'approve' => ['ru' => 'Утверждение', 'uz' => 'Tasdiqlash', 'en' => 'Approve'],
                ],
            ],
            'worksheet_templates' => [
                'group'  => 'admin',
                'labels' => ['ru' => 'Шаблоны рабочих листов', 'uz' => 'Ishchi varaq shablonlari', 'en' => 'Worksheet templates'],
                'abilities' => [
                    'manage' => ['ru' => 'Управление (конструктор)', 'uz' => 'Boshqarish (konstruktor)', 'en' => 'Manage (builder)'],
                ],
            ],
        ];

        $sort = (int) Permission::max('sort_order') + 1;
        foreach ($modules as $module => $cfg) {
            foreach ($cfg['abilities'] as $ability => $al) {
                $existing = Permission::where('name', "$module.$ability")->first();
                Permission::updateOrCreate(
                    ['name' => "$module.$ability"],
                    [
                        'module'     => $module,
                        'ability'    => $ability,
                        'group'      => $cfg['group'],
                        'label_ru'   => $cfg['labels']['ru'] . ' — ' . $al['ru'],
                        'label_uz'   => $cfg['labels']['uz'] . ' — ' . $al['uz'],
                        'label_en'   => $cfg['labels']['en'] . ' — ' . $al['en'],
                        'sort_order' => $existing->sort_order ?? $sort++,
                    ]
                );
            }
        }
    }

    private function roles(): void
    {
        $grants = [
            'lab_head' => [
                'worksheets.view', 'worksheets.create', 'worksheets.update',
                'worksheets.delete', 'worksheets.approve', 'worksheet_templates.manage',
            ],
            'analyst'  => ['worksheets.view', 'worksheets.create', 'worksheets.update'],
            'operator' => ['worksheets.view', 'worksheets.create'],
            'viewer'   => ['worksheets.view'],
        ];

        foreach ($grants as $roleName => $names) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            $ids = Permission::whereIn('name', $names)->pluck('id')->all();
            $role->permissions()->syncWithoutDetaching($ids);
        }
        // admin — barcha ruxsatlarga avtomatik ega
    }

    private function menu(): void
    {
        MenuItem::updateOrCreate(['key' => 'worksheets'], [
            'title_ru' => 'Рабочие листы', 'title_uz' => 'Ishchi varaqlar', 'title_en' => 'Worksheets',
            'icon' => 'file-spreadsheet', 'route' => 'worksheets', 'permission' => 'worksheets.view',
            'parent_id' => null, 'sort_order' => 2, 'is_active' => true,
        ]);

        $catalog = MenuItem::where('key', 'catalog')->first();
        MenuItem::updateOrCreate(['key' => 'worksheet_templates'], [
            'title_ru' => 'Шаблоны рабочих листов', 'title_uz' => 'Ishchi varaq shablonlari', 'title_en' => 'Worksheet templates',
            'icon' => 'layout-template', 'route' => 'worksheet-templates', 'permission' => 'worksheet_templates.manage',
            'parent_id' => $catalog?->id, 'sort_order' => 10, 'is_active' => true,
        ]);
    }

    private function templates(): void
    {
        foreach (DefaultTemplates::all() as $def) {
            if (WorksheetTemplate::withTrashed()->where('code', $def['code'])->exists()) {
                continue; // admin tahrirlagan bo'lishi mumkin — ustidan yozilmaydi
            }

            $template = WorksheetTemplate::create([
                'code'          => $def['code'],
                'version'       => $def['version'] ?? null,
                'name_uz'       => $def['name_uz'],
                'name_ru'       => $def['name_ru'] ?? null,
                'name_uz_cyrl'  => $def['name_uz_cyrl'] ?? null,
                'number_suffix' => $def['number_suffix'] ?? null,
                'number_start'  => $def['number_start'] ?? 1,
                'default_rows'  => $def['default_rows'] ?? 2,
                'print_locale'  => $def['print_locale'] ?? 'uz',
                'orientation'   => $def['orientation'] ?? 'landscape',
                'stages'        => $def['stages'],
                'params'        => $def['params'],
                'notes'         => $def['notes'] ?? null,
                'is_active'     => true,
            ]);

            foreach ($def['columns'] as $i => $c) {
                $elementId = ! empty($c['element_symbol'])
                    ? Element::where('symbol', $c['element_symbol'])->value('id')
                    : null;
                unset($c['element_symbol']);

                $template->columns()->create($c + ['element_id' => $elementId, 'sort_order' => $i]);
            }
        }
    }
}
