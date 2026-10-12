<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorksheetTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'version', 'valid_from', 'name_uz', 'name_ru', 'name_uz_cyrl',
        'test_profile_id', 'number_suffix', 'number_start', 'default_rows', 'max_samples',
        'print_locale', 'orientation', 'stages', 'params', 'notes', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'stages'       => 'array',
        'params'       => 'array',
        'is_active'    => 'boolean',
        'valid_from'   => 'date:Y-m-d',
        'number_start' => 'integer',
        'test_profile_id' => 'integer',
        'default_rows' => 'integer',
        'max_samples'  => 'integer',
    ];

    public function columns(): HasMany
    {
        return $this->hasMany(WorksheetTemplateColumn::class, 'template_id')->orderBy('sort_order')->orderBy('id');
    }

    public function testProfile(): BelongsTo
    {
        return $this->belongsTo(TestProfile::class);
    }

    public function worksheets(): HasMany
    {
        return $this->hasMany(Worksheet::class, 'template_id');
    }

    /** Natijasi probaga o'tadigan elementlar (yakuniy ustunlarga biriktirilganlar) */
    public function resultElementIds(): array
    {
        return $this->columns()
            ->where('scope', 'summary')->whereNotNull('element_id')
            ->pluck('element_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Raqamlash seriyasi: qo'shimcha bo'lmasa — forma kodi */
    public function series(): string
    {
        return trim((string) $this->number_suffix) !== '' ? trim($this->number_suffix) : $this->code;
    }

    /**
     * Hisoblagich va varaq nusxasi uchun to'liq ta'rif.
     * Ishchi varaq yaratilganda shu massiv template_snapshot sifatida saqlanadi.
     */
    public function toDefinition(): array
    {
        $this->loadMissing('columns.element:id,symbol,name_ru,name_uz,name_en');

        return [
            'template_id'   => $this->id,
            'code'          => $this->code,
            'version'       => $this->version,
            'name_uz'       => $this->name_uz,
            'name_ru'       => $this->name_ru,
            'name_uz_cyrl'  => $this->name_uz_cyrl,
            'number_suffix' => $this->number_suffix,
            'print_locale'  => $this->print_locale,
            'orientation'   => $this->orientation,
            'stages'        => array_values($this->stages ?? []),
            'params'        => array_values($this->params ?? []),
            'columns'       => $this->columns->map(fn (WorksheetTemplateColumn $c) => $c->toDefinition())->values()->all(),
            'snapshot_at'   => now()->toDateTimeString(),
        ];
    }
}
