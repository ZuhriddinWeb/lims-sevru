<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'username', 'email', 'phone', 'position', 'password',
        'role_id', 'laboratory_id', 'laboratory_group_id',
        'locale', 'theme', 'avatar', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // --- Munosabatlar ---

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class);
    }

    public function laboratoryGroup(): BelongsTo
    {
        return $this->belongsTo(LaboratoryGroup::class);
    }

    // --- Ruxsat yordamchilari ---

    public function isAdmin(): bool
    {
        return optional($this->role)->name === 'admin';
    }

    /**
     * Ushbu foydalanuvchida berilgan ruxsat bormi?
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true; // admin hamma narsaga ruxsatli
        }

        if (! $this->role) {
            return false;
        }

        return $this->role->permissions
            ->pluck('name')
            ->contains($permission);
    }

    /**
     * Foydalanuvchining barcha ruxsatlari ro'yxati (name bo'yicha).
     */
    public function permissionNames(): array
    {
        if ($this->isAdmin()) {
            return Permission::pluck('name')->all();
        }

        return $this->role
            ? $this->role->permissions->pluck('name')->all()
            : [];
    }
}
