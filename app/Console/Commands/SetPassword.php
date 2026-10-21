<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Yangi serverda parolni almashtirish yoki administrator yaratish (tinker shart emas).
 *
 *   php artisan lims:set-password admin                 — parolni so'raydi (ekranda ko'rinmaydi)
 *   php artisan lims:set-password admin --password=...  — parol buyruqda (tarixda qoladi, ehtiyot bo'ling)
 *   php artisan lims:set-password boss --create         — foydalanuvchi yo'q bo'lsa «admin» roli bilan yaratadi
 *   php artisan lims:set-password --list                — foydalanuvchilar va ularning rollari
 */
class SetPassword extends Command
{
    protected $signature = 'lims:set-password
        {username? : Login}
        {--password= : Yangi parol (bo\'sh qoldirilsa so\'raladi)}
        {--create : Foydalanuvchi yo\'q bo\'lsa administrator sifatida yaratish}
        {--list : Foydalanuvchilar ro\'yxatini ko\'rsatish}';

    protected $description = 'Foydalanuvchi parolini almashtiradi yoki administrator yaratadi (yangi server uchun)';

    public function handle(): int
    {
        if ($this->option('list')) {
            $rows = User::with('role')->orderBy('id')->get()->map(fn ($u) => [
                $u->id, $u->username, $u->name, $u->role?->name ?? '-', $u->is_active ? 'faol' : 'nofaol',
            ])->all();
            $this->table(['id', 'login', 'FIO', 'rol', 'holat'], $rows);

            return self::SUCCESS;
        }

        $username = trim((string) $this->argument('username'));
        if ($username === '') {
            $this->error('Login ko\'rsatilmagan. Misol: php artisan lims:set-password admin');

            return self::FAILURE;
        }

        $user = User::where('username', $username)->first();
        if (! $user && ! $this->option('create')) {
            $this->error("«{$username}» topilmadi. Yaratish uchun --create qo'shing, ro'yxat uchun --list.");

            return self::FAILURE;
        }

        $password = (string) $this->option('password');
        if ($password === '') {
            $password = (string) $this->secret('Yangi parol (kamida 8 belgi)');
            if ($password !== (string) $this->secret('Parolni takrorlang')) {
                $this->error('Parollar mos kelmadi.');

                return self::FAILURE;
            }
        }
        if (mb_strlen($password) < 8) {
            $this->error('Parol kamida 8 belgidan iborat bo\'lsin.');

            return self::FAILURE;
        }

        if (! $user) {
            $role = Role::where('name', 'admin')->first();
            if (! $role) {
                $this->error('«admin» roli yo\'q — avval PermissionSeeder va RoleSeeder bajarilishi kerak.');

                return self::FAILURE;
            }
            $user = new User();
            $user->fill([
                'name'      => 'Администратор системы',
                'username'  => $username,
                'role_id'   => $role->id,
                'locale'    => 'ru',
                'theme'     => 'light',
                'is_active' => true,
            ]);
        }

        $user->password = $password;   // 'hashed' cast o'zi shifrlaydi
        $user->save();
        $user->tokens()->delete();     // eski sessiyalar yopiladi

        $this->info("«{$username}» uchun parol o'rnatildi" . ($user->wasRecentlyCreated ? ' (yangi administrator yaratildi).' : '.'));

        return self::SUCCESS;
    }
}
