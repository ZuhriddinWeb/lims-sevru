<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Rahbariyat paneli 2.0, smenalar, xodimlar faoliyati (qayta ishga tushirish xavfsiz):
 *   php artisan db:seed --class=InsightsModuleSeeder --force
 *
 *  - ruxsat «shifts.manage» (Smenalar — sozlash) — faqat yo'q bo'lsa yaratiladi;
 *    yangi yaratilganda «Laboratoriya boshlig'i» (lab_head) roliga beriladi. Admin — avtomatik.
 *  - [P17] ruxsat «staff_activity.view» (Xodimlar faoliyati — barcha xodimlarni ko'rish) — faqat yo'q bo'lsa;
 *    yangi yaratilganda «Rahbariyat paneli» ruxsati bor rollarga beriladi (Kuzatuvchi va Buyurtmachidan tashqari).
 *    Bu ruxsati yo'q xodim sahifada faqat o'z faoliyatini ko'radi.
 *  - menyu: Administratsiya → «Smenalar»; [P17] «Xodimlar faoliyati» (faqat yo'q bo'lsa — admin o'zgartirgan nom/tartib saqlanadi)
 *  - smenalar o'zi YARATILMAYDI: admin «Smenalar» sahifasida kiritadi (tayyor namunalar bor: 2 / 3 smena)
 */
class InsightsModuleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $permission = Permission::firstOrCreate(['name' => 'shifts.manage'], [
                'module'     => 'shifts',
                'ability'    => 'manage',
                'group'      => 'admin',
                'label_ru'   => 'Смены — настройка',
                'label_uz'   => 'Smenalar — sozlash',
                'label_en'   => 'Shifts — configure',
                'sort_order' => (int) Permission::max('sort_order') + 1,
            ]);

            if ($permission->wasRecentlyCreated) {
                Role::where('name', 'lab_head')->first()?->permissions()->syncWithoutDetaching([$permission->id]);
            }

            // [P17] Xodimlar faoliyati
            $staff = Permission::firstOrCreate(['name' => 'staff_activity.view'], [
                'module'     => 'staff_activity',
                'ability'    => 'view',
                'group'      => 'lab',
                'label_ru'   => 'Деятельность сотрудников — все сотрудники',
                'label_uz'   => "Xodimlar faoliyati — barcha xodimlar",
                'label_en'   => 'Staff activity — all staff',
                'sort_order' => (int) Permission::max('sort_order') + 1,
            ]);
            if ($staff->wasRecentlyCreated) {
                $executive = Permission::where('name', 'executive.view')->value('id');
                $roles = $executive
                    ? Role::whereNotIn('name', ['admin', 'viewer', 'customer'])
                        ->whereHas('permissions', fn ($q) => $q->where('permissions.id', $executive))->get()
                    : collect();
                if ($lab = Role::where('name', 'lab_head')->first()) {
                    $roles->push($lab);
                }
                foreach ($roles->unique('id') as $role) {
                    $role->permissions()->syncWithoutDetaching([$staff->id]);
                }
            }
            MenuItem::firstOrCreate(['key' => 'staff_activity'], [
                'title_ru' => 'Деятельность сотрудников', 'title_uz' => 'Xodimlar faoliyati', 'title_en' => 'Staff activity',
                'icon' => 'user-check', 'route' => 'staff-activity', 'permission' => null,
                'parent_id' => null, 'sort_order' => 2, 'is_active' => true,
            ]);

            $admin = MenuItem::where('key', 'administration')->first();
            MenuItem::firstOrCreate(['key' => 'lab_shifts'], [
                'title_ru' => 'Смены', 'title_uz' => 'Smenalar', 'title_en' => 'Shifts',
                'icon' => 'clock', 'route' => 'lab-shifts', 'permission' => 'shifts.manage',
                'parent_id' => $admin?->id, 'sort_order' => 5, 'is_active' => true,
            ]);
        });
    }
}
